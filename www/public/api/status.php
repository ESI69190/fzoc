<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config_example.php';
} else {
    require_once __DIR__ . '/../../config.php';
}

function status_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$job = trim((string) ($_GET['job'] ?? ''));
if (!preg_match('/^([a-f0-9]{32})_([0-9]+)$/i', $job, $m)) {
    status_response(['ok' => false, 'error' => 'invalid_job'], 400);
}

$compiledPath = $m[1] . '/' . $m[2];

$query = $bdd_connexion->prepare('
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
    WHERE c.compiled_path_fap = :path
    LIMIT 1
');
$query->execute(['path' => $compiledPath]);
$row = $query->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    status_response(['ok' => false, 'error' => 'job_not_found'], 404);
}

$queuedFile = rtrim($task_list, '/') . '/' . $job . '.sh';
$runningFile = rtrim($task_list, '/') . '/running/' . $job . '.sh';
$resultFile = rtrim($task_list, '/') . '/result/' . $job . '.result';

$status = $row['compiled_status'];

if ($status === 'pending') {
    if (is_file($runningFile)) {
        $status = 'running';
    } elseif (is_file($queuedFile)) {
        $status = 'queued';
    }
}

$log = '';
if (is_file($resultFile)) {
    $lines = file($resultFile, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        $log = implode(PHP_EOL, array_slice($lines, -80));
    }
}

$download = null;
if ($row['compiled_status'] === 'success') {
    $file = rtrim($fap_path, '/') . '/' . $compiledPath . '/' . $row['application_appid'] . '.fap';
    if (is_file($file)) {
        $download = '/faps/' . $compiledPath . '/' . rawurlencode($row['application_appid']) . '.fap';
    }
}

status_response([
    'ok' => true,
    'job' => $job,
    'status' => $status,
    'database_status' => $row['compiled_status'],
    'date' => $row['compiled_date'],
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
    'log' => $log,
]);
