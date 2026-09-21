<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config_example.php';
} else {
    require_once __DIR__ . '/../../config.php';
}

$query = $bdd_connexion->query('
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

$items = [];

foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
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
        $file = rtrim($fap_path, '/') . '/' . $row['compiled_path_fap'] . '/' . $row['application_appid'] . '.fap';
        if (is_file($file)) {
            $download = '/faps/' . $row['compiled_path_fap'] . '/' . rawurlencode($row['application_appid']) . '.fap';
        }
    }

    $items[] = [
        'job' => $job,
        'date' => $row['compiled_date'],
        'status' => $status,
        'application' => [
            'name' => $row['application_name'],
            'appid' => $row['application_appid'],
            'repository' => $row['application_url_git'],
        ],
        'firmware' => [
            'name' => $row['firmware_name'],
            'version' => $row['firmware_version_name'],
            'channel' => $row['firmware_version_type'],
        ],
        'download' => $download,
    ];
}

$total = (int) $bdd_connexion->query('SELECT COUNT(*) FROM fzco_compiled')->fetchColumn();

$monthQuery = $bdd_connexion->prepare('
    SELECT COUNT(*)
    FROM fzco_compiled
    WHERE compiled_date >= :month_start
');
$monthQuery->execute(['month_start' => date('Y-m-01 00:00:00')]);
$thisMonth = (int) $monthQuery->fetchColumn();

$success = (int) $bdd_connexion->query(
    'SELECT COUNT(*) FROM fzco_compiled WHERE compiled_status = "success"'
)->fetchColumn();

echo json_encode([
    'ok' => true,
    'stats' => [
        'total' => $total,
        'this_month' => $thisMonth,
        'success' => $success,
    ],
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
