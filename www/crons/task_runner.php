<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config_example.php';
} else {
    require_once __DIR__ . '/../config.php';
}

$runningDir = rtrim($task_list, '/') . '/running/';
$resultDir  = rtrim($task_list, '/') . '/result/';

foreach ([$task_list, $runningDir, $resultDir, $fap_path, __DIR__ . '/../gits/'] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

$pendingTasks = scandir($task_list);
if ($pendingTasks === false) {
    exit(0);
}

$updateStatus = $bdd_connexion->prepare('
    UPDATE fzco_compiled
    SET compiled_status = :new_status
    WHERE compiled_path_fap = :compiled_path
');

$appForBuild = $bdd_connexion->prepare('
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

    $sourceTask = rtrim($task_list, '/') . '/' . $taskWaiting;
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

    $compiledPath = substr($jobName, 0, $separator) . '/' . substr($jobName, $separator + 1);
    $resultFile = $resultDir . $jobName . '.result';

    $exitCode = 0;

    // Redirect stdout/stderr directly to the result file so the XHR status
    // endpoint can expose build logs while uFBT is still running.
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
            ? rtrim($fap_path, '/') . '/' . $compiledPath . '/' . $applicationAppId . '.fap'
            : null;

        if ($expectedFap === null || !is_file($expectedFap)) {
            $status = 'error';
            file_put_contents(
                $resultFile,
                "[FZOC] Build command finished without producing the expected .fap file." . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
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
}
