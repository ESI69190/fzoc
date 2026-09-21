<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config_example.php';
} else {
    require_once __DIR__ . '/../../config.php';
}
require_once __DIR__ . '/../../class/fzcoBuildQueue.class.php';

fzcoEnsureBuildQueueSchema($bdd_connexion);

$queuePositions = [];
$queueQuery = $bdd_connexion->query(
    "SELECT build_job_id
     FROM fzco_build_job
     WHERE build_status = 'queued'
     ORDER BY priority DESC, request_count DESC, queued_at ASC, build_job_id ASC"
);

$position = 0;
foreach ($queueQuery->fetchAll(PDO::FETCH_COLUMN) as $queuedId) {
    $queuePositions[(int) $queuedId] = ++$position;
}

$newQuery = $bdd_connexion->query('
    SELECT *
    FROM fzco_build_job
    ORDER BY last_requested_at DESC
    LIMIT 50
');

$items = [];

foreach ($newQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $download = null;

    if ($row['build_status'] === 'success') {
        $file = rtrim($fap_path, '/') . '/'
            . $row['build_path'] . '/'
            . $row['application_appid'] . '.fap';

        if (is_file($file)) {
            $download = '/faps/' . $row['build_path'] . '/'
                . rawurlencode($row['application_appid']) . '.fap';
        }
    }

    $items[] = [
        'job' => $row['public_job_id'],
        'date' => $row['last_requested_at'],
        'status' => $row['build_status'],
        'queue_position' => $queuePositions[(int) $row['build_job_id']] ?? null,
        'request_count' => (int) $row['request_count'],
        'application' => [
            'name' => $row['application_name'],
            'appid' => $row['application_appid'],
            'repository' => $row['application_url_git'],
            'commit' => $row['repository_commit'],
        ],
        'firmware' => [
            'name' => $row['firmware_name'],
            'version' => $row['firmware_version_name'],
            'channel' => $row['sdk_channel'],
        ],
        'download' => $download,
    ];
}

$legacyQuery = $bdd_connexion->query('
    SELECT
        c.compiled_date,
        c.compiled_status,
        c.compiled_path_fap,
        a.application_name,
        a.application_appid,
        a.application_url_git,
        fv.firmware_version_name,
        fv.firmware_version_type,
        f.firmware_name
    FROM fzco_compiled c
    INNER JOIN fzco_application a
        ON a.application_id = c.compiled_application_id
    INNER JOIN fzco_firmware_version fv
        ON fv.firmware_version_id = c.compiled_firmware_version_id
    INNER JOIN fzco_depend d
        ON d.depend_firmware_version_id = fv.firmware_version_id
    INNER JOIN fzco_firmware f
        ON f.firmware_id = d.depend_firmware_id
    ORDER BY c.compiled_date DESC
    LIMIT 50
');

foreach ($legacyQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $job = str_replace('/', '_', $row['compiled_path_fap']);
    $status = $row['compiled_status'];

    if ($status === 'pending') {
        if (is_file(rtrim($task_list, '/') . '/running/' . $job . '.sh')) {
            $status = 'running';
        } elseif (is_file(rtrim($task_list, '/') . '/' . $job . '.sh')) {
            $status = 'queued';
        }
    }

    $download = null;
    if ($row['compiled_status'] === 'success') {
        $file = rtrim($fap_path, '/') . '/'
            . $row['compiled_path_fap'] . '/'
            . $row['application_appid'] . '.fap';

        if (is_file($file)) {
            $download = '/faps/' . $row['compiled_path_fap'] . '/'
                . rawurlencode($row['application_appid']) . '.fap';
        }
    }

    $items[] = [
        'job' => $job,
        'date' => $row['compiled_date'],
        'status' => $status,
        'queue_position' => null,
        'request_count' => 1,
        'application' => [
            'name' => $row['application_name'],
            'appid' => $row['application_appid'],
            'repository' => $row['application_url_git'],
            'commit' => null,
        ],
        'firmware' => [
            'name' => $row['firmware_name'],
            'version' => $row['firmware_version_name'],
            'channel' => $row['firmware_version_type'],
        ],
        'download' => $download,
    ];
}

usort(
    $items,
    static fn(array $a, array $b): int => strcmp($b['date'], $a['date'])
);
$items = array_slice($items, 0, 50);

$legacyTotal = (int) $bdd_connexion->query('SELECT COUNT(*) FROM fzco_compiled')->fetchColumn();
$newTotal = (int) $bdd_connexion->query('SELECT COUNT(*) FROM fzco_build_job')->fetchColumn();

$monthStart = date('Y-m-01 00:00:00');

$legacyMonth = $bdd_connexion->prepare(
    'SELECT COUNT(*) FROM fzco_compiled WHERE compiled_date >= :month_start'
);
$legacyMonth->execute(['month_start' => $monthStart]);

$newMonth = $bdd_connexion->prepare(
    'SELECT COUNT(*) FROM fzco_build_job WHERE created_at >= :month_start'
);
$newMonth->execute(['month_start' => $monthStart]);

$legacySuccess = (int) $bdd_connexion->query(
    'SELECT COUNT(*) FROM fzco_compiled WHERE compiled_status = "success"'
)->fetchColumn();

$newSuccess = (int) $bdd_connexion->query(
    'SELECT COUNT(*) FROM fzco_build_job WHERE build_status = "success"'
)->fetchColumn();

echo json_encode([
    'ok' => true,
    'stats' => [
        'total' => $legacyTotal + $newTotal,
        'this_month' => (int) $legacyMonth->fetchColumn() + (int) $newMonth->fetchColumn(),
        'success' => $legacySuccess + $newSuccess,
    ],
    'queue' => [
        'waiting' => count($queuePositions),
    ],
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
