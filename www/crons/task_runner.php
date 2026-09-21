<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config_example.php';
} else {
    require_once __DIR__ . '/../config.php';
}
require_once __DIR__ . '/../class/fzcoBuildQueue.class.php';

fzcoEnsureBuildQueueSchema($bdd_connexion);

$runningDir = rtrim($task_list, '/') . '/running/';
$resultDir = rtrim($task_list, '/') . '/result/';

foreach ([$task_list, $runningDir, $resultDir, $fap_path, __DIR__ . '/../gits/'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function claim_next_build(PDO $db): array|false
{
    try {
        $db->beginTransaction();

        $query = $db->query(
            "SELECT *
             FROM fzco_build_job
             WHERE build_status = 'queued'
             ORDER BY priority DESC, request_count DESC, queued_at ASC, build_job_id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );

        $job = $query->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $db->commit();
            return false;
        }

        $update = $db->prepare(
            "UPDATE fzco_build_job
             SET build_status = 'running',
                 started_at = NOW(),
                 error_code = NULL
             WHERE build_job_id = :id
               AND build_status = 'queued'"
        );
        $update->execute(['id' => (int) $job['build_job_id']]);

        if ($update->rowCount() !== 1) {
            $db->rollBack();
            return false;
        }

        $db->commit();
        $job['build_status'] = 'running';

        return $job;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }
}

function finish_build(PDO $db, int $id, string $status, ?string $errorCode = null): void
{
    $update = $db->prepare(
        "UPDATE fzco_build_job
         SET build_status = :status,
             finished_at = NOW(),
             error_code = :error_code
         WHERE build_job_id = :id"
    );
    $update->execute([
        'status' => $status,
        'error_code' => $errorCode,
        'id' => $id,
    ]);
}

function run_new_queue_job(
    PDO $db,
    array $job,
    string $taskList,
    string $runningDir,
    string $resultDir,
    string $fapPath
): void {
    $publicId = (string) $job['public_job_id'];
    $sourceTask = rtrim($taskList, '/') . '/' . $publicId . '.sh';
    $runningTask = $runningDir . $publicId . '.sh';
    $resultFile = $resultDir . $publicId . '.result';

    if (!is_file($sourceTask) || !@rename($sourceTask, $runningTask)) {
        finish_build($db, (int) $job['build_job_id'], 'error', 'missing_task');
        return;
    }

    $exitCode = 0;
    exec(
        'sh ' . escapeshellarg($runningTask)
        . ' > ' . escapeshellarg($resultFile)
        . ' 2>&1',
        $ignoredOutput,
        $exitCode
    );

    $log = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';

    file_put_contents(
        $resultFile,
        sprintf(
            "%s[FZOC] finished_at=%s exit_code=%d%s",
            PHP_EOL,
            date(DATE_ATOM),
            $exitCode,
            PHP_EOL
        ),
        FILE_APPEND | LOCK_EX
    );

    $status = 'success';
    $errorCode = null;

    if (stripos($log, 'Found nothing to build') !== false) {
        $status = 'impossible';
        $errorCode = 'nothing_to_build';
    } elseif (
        $exitCode !== 0
        || stripos($log, 'Failed parsing manifest') !== false
        || preg_match('/(^|\s)(error|fatal)(:|\s)/i', $log)
    ) {
        $status = 'error';
        $errorCode = 'build_failed';
    }

    $expectedFap = rtrim($fapPath, '/') . '/'
        . $job['build_path'] . '/'
        . $job['application_appid'] . '.fap';

    if ($status === 'success' && !is_file($expectedFap)) {
        $status = 'error';
        $errorCode = 'missing_fap';

        file_put_contents(
            $resultFile,
            "[FZOC] Build command finished without producing the expected .fap file." . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    finish_build($db, (int) $job['build_job_id'], $status, $errorCode);

    $gitPath = __DIR__ . '/../gits/' . $publicId;
    if (is_dir($gitPath)) {
        exec('rm -rf -- ' . escapeshellarg($gitPath));
    }

    @unlink($runningTask);
}

function run_legacy_task(
    PDO $db,
    string $taskList,
    string $runningDir,
    string $resultDir,
    string $fapPath
): bool {
    $pendingTasks = scandir($taskList);
    if ($pendingTasks === false) {
        return false;
    }

    $updateStatus = $db->prepare('
        UPDATE fzco_compiled
        SET compiled_status = :new_status
        WHERE compiled_path_fap = :compiled_path
    ');

    $appForBuild = $db->prepare('
        SELECT a.application_appid
        FROM fzco_compiled c
        INNER JOIN fzco_application a
            ON a.application_id = c.compiled_application_id
        WHERE c.compiled_path_fap = :compiled_path
        LIMIT 1
    ');

    foreach ($pendingTasks as $taskWaiting) {
        if ($taskWaiting === '.' || $taskWaiting === '..' || str_starts_with($taskWaiting, '.')) {
            continue;
        }

        $sourceTask = rtrim($taskList, '/') . '/' . $taskWaiting;
        if (!is_file($sourceTask) || !str_ends_with($taskWaiting, '.sh')) {
            continue;
        }

        $runningTask = $runningDir . $taskWaiting;

        if (!@rename($sourceTask, $runningTask)) {
            continue;
        }

        $jobName = pathinfo($taskWaiting, PATHINFO_FILENAME);
        $separator = strrpos($jobName, '_');

        if ($separator === false) {
            @unlink($runningTask);
            continue;
        }

        $compiledPath = substr($jobName, 0, $separator) . '/'
            . substr($jobName, $separator + 1);
        $resultFile = $resultDir . $jobName . '.result';

        $exitCode = 0;

        exec(
            'sh ' . escapeshellarg($runningTask)
            . ' > ' . escapeshellarg($resultFile)
            . ' 2>&1',
            $ignoredOutput,
            $exitCode
        );

        $log = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';

        $status = 'success';

        if (stripos($log, 'Found nothing to build') !== false) {
            $status = 'impossible';
        } elseif (
            $exitCode !== 0
            || stripos($log, 'Failed parsing manifest') !== false
            || preg_match('/(^|\s)(error|fatal)(:|\s)/i', $log)
        ) {
            $status = 'error';
        }

        if ($status === 'success') {
            $appForBuild->execute(['compiled_path' => $compiledPath]);
            $applicationAppId = $appForBuild->fetchColumn();

            $expectedFap = $applicationAppId
                ? rtrim($fapPath, '/') . '/' . $compiledPath . '/'
                    . $applicationAppId . '.fap'
                : null;

            if ($expectedFap === null || !is_file($expectedFap)) {
                $status = 'error';
            }
        }

        $updateStatus->execute([
            'new_status' => $status,
            'compiled_path' => $compiledPath,
        ]);

        $gitPath = __DIR__ . '/../gits/' . $compiledPath;
        if (is_dir($gitPath)) {
            exec('rm -rf -- ' . escapeshellarg($gitPath));
        }

        @unlink($runningTask);
        return true;
    }

    return false;
}

$newJob = claim_next_build($bdd_connexion);

if ($newJob !== false) {
    run_new_queue_job(
        $bdd_connexion,
        $newJob,
        $task_list,
        $runningDir,
        $resultDir,
        $fap_path
    );
    exit(0);
}

run_legacy_task(
    $bdd_connexion,
    $task_list,
    $runningDir,
    $resultDir,
    $fap_path
);
