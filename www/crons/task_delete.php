<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config_example.php';
} else {
    require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/../class/fzcoBuildQueue.class.php';

fzcoEnsureBuildQueueSchema($bdd_connexion);

$retentionDays = max(1, (int) ($fzoc_retention_days ?? 30));
$cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $retentionDays . ' days')
    ->format('Y-m-d H:i:s');

function fzcoSafeRelativePath(string $relative): ?string
{
    $relative = str_replace('\\', '/', trim($relative));

    if (
        $relative === ''
        || str_contains($relative, "\0")
        || preg_match('~(^|/)\.\.(/|$)~', $relative)
    ) {
        return null;
    }

    return ltrim($relative, '/');
}

function fzcoDeleteTree(string $path): bool
{
    if (!file_exists($path) && !is_link($path)) {
        return true;
    }

    if (is_file($path) || is_link($path)) {
        return @unlink($path);
    }

    $entries = @scandir($path);
    if ($entries === false) {
        return false;
    }

    $ok = true;

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (!fzcoDeleteTree($path . DIRECTORY_SEPARATOR . $entry)) {
            $ok = false;
        }
    }

    return @rmdir($path) && $ok;
}

function fzcoDeleteFileIfPresent(string $path): bool
{
    return !file_exists($path) || @unlink($path);
}

$newJobs = $bdd_connexion->prepare(
    'SELECT
        build_job_id,
        public_job_id,
        build_path
     FROM fzco_build_job
     WHERE build_status IN ("success", "error", "impossible", "deleted")
       AND last_requested_at < :cutoff
     ORDER BY build_job_id'
);
$newJobs->execute(['cutoff' => $cutoff]);

$deleteNewJob = $bdd_connexion->prepare(
    'DELETE FROM fzco_build_job WHERE build_job_id = :id'
);

foreach ($newJobs->fetchAll(PDO::FETCH_ASSOC) as $job) {
    $relativeBuildPath = fzcoSafeRelativePath((string) $job['build_path']);
    $publicId = preg_match('/^[a-f0-9]{32}$/', (string) $job['public_job_id'])
        ? (string) $job['public_job_id']
        : null;

    $filesystemOk = true;

    if ($relativeBuildPath !== null) {
        $filesystemOk = fzcoDeleteTree(
            rtrim($fap_path, '/\\') . DIRECTORY_SEPARATOR . $relativeBuildPath
        ) && $filesystemOk;
    }

    if ($publicId !== null) {
        $taskBase = rtrim($task_list, '/\\');

        foreach ([
            $taskBase . DIRECTORY_SEPARATOR . $publicId . '.sh',
            $taskBase . DIRECTORY_SEPARATOR . 'running' . DIRECTORY_SEPARATOR . $publicId . '.sh',
            $taskBase . DIRECTORY_SEPARATOR . 'result' . DIRECTORY_SEPARATOR . $publicId . '.result',
        ] as $taskFile) {
            $filesystemOk = fzcoDeleteFileIfPresent($taskFile) && $filesystemOk;
        }

        $filesystemOk = fzcoDeleteTree(
            __DIR__ . '/../gits/' . $publicId
        ) && $filesystemOk;
    }

    if (!$filesystemOk) {
        error_log(sprintf(
            '[FZOC retention] Some files could not be deleted for build job %d',
            (int) $job['build_job_id']
        ));
    }

    // fzco_build_request rows are removed by ON DELETE CASCADE.
    $deleteNewJob->execute(['id' => (int) $job['build_job_id']]);
}

// A recently reused cached build may still have request records older than the
// retention window. Purge those individual requests as well.
$deleteOldRequests = $bdd_connexion->prepare(
    'DELETE FROM fzco_build_request WHERE requested_at < :cutoff'
);
$deleteOldRequests->execute(['cutoff' => $cutoff]);

$bdd_connexion->exec(
    'UPDATE fzco_build_job j
     SET request_count = GREATEST(
         1,
         (
             SELECT COUNT(*)
             FROM fzco_build_request r
             WHERE r.build_job_id = j.build_job_id
         )
     )'
);

// Legacy FZOC rows are purged too, rather than being kept forever as "deleted".
$legacyRows = $bdd_connexion->prepare(
    'SELECT
        c.compiled_firmware_version_id,
        c.compiled_application_id,
        c.compiled_date,
        c.compiled_path_fap,
        a.application_appid
     FROM fzco_compiled c
     INNER JOIN fzco_application a
        ON a.application_id = c.compiled_application_id
     WHERE c.compiled_date < :cutoff'
);
$legacyRows->execute(['cutoff' => $cutoff]);

$deleteLegacy = $bdd_connexion->prepare(
    'DELETE FROM fzco_compiled
     WHERE compiled_firmware_version_id = :firmware_version_id
       AND compiled_application_id = :application_id
       AND compiled_date = :compiled_date'
);

foreach ($legacyRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $relativeBuildPath = fzcoSafeRelativePath((string) $row['compiled_path_fap']);

    if ($relativeBuildPath !== null) {
        $legacyDir = rtrim($fap_path, '/\\')
            . DIRECTORY_SEPARATOR
            . $relativeBuildPath;

        if (!fzcoDeleteTree($legacyDir)) {
            error_log(sprintf(
                '[FZOC retention] Legacy build files could not be fully deleted: %s',
                $relativeBuildPath
            ));
        }
    }

    $deleteLegacy->execute([
        'firmware_version_id' => (int) $row['compiled_firmware_version_id'],
        'application_id' => (int) $row['compiled_application_id'],
        'compiled_date' => (string) $row['compiled_date'],
    ]);
}

// Legacy application records contain repository URLs. Remove them once no
// retained compilation references them anymore.
$bdd_connexion->exec(
    'DELETE a
     FROM fzco_application a
     LEFT JOIN fzco_compiled c
        ON c.compiled_application_id = a.application_id
     WHERE c.compiled_application_id IS NULL'
);

echo sprintf(
    "[fzoc-retention] purged compilations older than %d days (cutoff UTC %s)%s",
    $retentionDays,
    $cutoff,
    PHP_EOL
);
