<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config_example.php';
} else {
    require_once __DIR__ . '/../../config.php';
}
require_once __DIR__ . '/../../class/fzcoBuildQueue.class.php';

fzcoEnsureBuildQueueSchema($bdd_connexion);

set_error_handler(
    static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }
);

set_exception_handler(
    static function (Throwable $exception) use ($debug): void {
        $errorId = bin2hex(random_bytes(4));

        error_log(sprintf(
            '[FZOC API %s] %s in %s:%d',
            $errorId,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'internal_error',
            'message' => 'Une erreur interne a interrompu la préparation de la compilation.',
            'error_id' => $errorId,
            'details' => $debug ? $exception->getMessage() : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
);

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function detect_repository(string $url): array|false
{
    if (preg_match('~^https://github\.com/([^/]+)/([^/?#]+?)(?:\.git)?/?$~i', $url, $m)) {
        $repo = preg_replace('/\.git$/i', '', $m[2]);

        return [
            'provider' => 'github',
            'clone_url' => 'https://github.com/' . $m[1] . '/' . $repo . '.git',
            'owner' => $m[1],
            'repo' => $repo,
        ];
    }

    if (preg_match('~^https://gitlab\.com/(.+?)(?:\.git)?/?$~i', $url, $m)) {
        $path = preg_replace('/\.git$/i', '', trim($m[1], '/'));
        if ($path === '' || !str_contains($path, '/')) {
            return false;
        }

        return [
            'provider' => 'gitlab',
            'clone_url' => 'https://gitlab.com/' . $path . '.git',
            'path' => $path,
        ];
    }

    return false;
}

function detect_default_branch(string $cloneUrl): string
{
    $output = [];
    $exitCode = 0;

    exec(
        'git ls-remote --symref ' . escapeshellarg($cloneUrl) . ' HEAD 2>/dev/null',
        $output,
        $exitCode
    );

    if ($exitCode === 0) {
        foreach ($output as $line) {
            if (preg_match('/^ref:\s+refs\/heads\/([^\s]+)\s+HEAD$/', trim($line), $m)) {
                return $m[1];
            }
        }
    }

    return 'main';
}

function resolve_remote_commit(string $cloneUrl, string $branch): string|false
{
    $output = [];
    $exitCode = 0;

    exec(
        'git ls-remote ' . escapeshellarg($cloneUrl)
        . ' ' . escapeshellarg('refs/heads/' . $branch)
        . ' 2>/dev/null',
        $output,
        $exitCode
    );

    if ($exitCode !== 0 || count($output) < 1) {
        return false;
    }

    $parts = preg_split('/\s+/', trim($output[0]));
    $sha = strtolower((string) ($parts[0] ?? ''));

    return preg_match('/^[a-f0-9]{40,64}$/', $sha) ? $sha : false;
}

function fetch_application_fam(array $repository, string $revision): array
{
    if ($repository['provider'] === 'github') {
        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/application.fam',
            rawurlencode($repository['owner']),
            rawurlencode($repository['repo']),
            rawurlencode($revision)
        );
    } else {
        $url = sprintf(
            'https://gitlab.com/%s/-/raw/%s/application.fam',
            $repository['path'],
            rawurlencode($revision)
        );
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'FZOC/ESI69190',
    ]);

    $body = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($body === false || $code !== 200) {
        return [false, $url, $error !== '' ? $error : 'HTTP ' . $code];
    }

    return [$body, $url, null];
}

function verify_turnstile(string $secret, string $response): bool
{
    if ($response === '') {
        return false;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'secret' => $secret,
            'response' => $response,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false || $code !== 200) {
        return false;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) && ($decoded['success'] ?? false) === true;
}

function build_task_script(
    string $gitUrl,
    string $commit,
    string $cloneDir,
    string $stateDir,
    string $versionType,
    string $updateUrl,
    string $outputDir,
    string $outputFap
): string {
    $lines = [
        '#!/bin/sh',
        'set -eu',
        'rm -rf -- ' . escapeshellarg($cloneDir),
        'mkdir -p ' . escapeshellarg(dirname($cloneDir)),
        'git init -q ' . escapeshellarg($cloneDir),
        'git -C ' . escapeshellarg($cloneDir) . ' remote add origin ' . escapeshellarg($gitUrl),
        'git -C ' . escapeshellarg($cloneDir) . ' fetch -q --depth 1 origin ' . escapeshellarg($commit),
        'git -C ' . escapeshellarg($cloneDir) . ' checkout -q --detach FETCH_HEAD',
        'cd ' . escapeshellarg(rtrim($GLOBALS['path_to_ufbt'], '/')),
        '. bin/activate',
        'cd ' . escapeshellarg($cloneDir),
        'ufbt dotenv_create --state-dir ' . escapeshellarg($stateDir),
    ];

    $updateCommand = 'ufbt update';
    if ($versionType === 'dev') {
        $updateCommand .= ' --channel dev';
    }
    $updateCommand .= ' --index-url=' . escapeshellarg($updateUrl);

    $lines[] = $updateCommand;
    $lines[] = 'ufbt';
    $lines[] = 'mkdir -p ' . escapeshellarg($outputDir);
    $lines[] = 'FAP_FILE="$(find dist -maxdepth 1 -type f -name \'*.fap\' | head -n 1)"';
    $lines[] = 'test -n "$FAP_FILE"';
    $lines[] = 'mv -f "$FAP_FILE" ' . escapeshellarg($outputFap);
    $lines[] = '';

    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$gitUrl = trim((string) ($_POST['git_url'] ?? ''));
$firmwareTarget = (int) ($_POST['firmware_target'] ?? 0);
$gitBranch = (int) ($_POST['git_branch'] ?? 0);

if ($gitUrl === '' || $firmwareTarget < 1 || !in_array($gitBranch, [1, 2], true)) {
    json_response([
        'ok' => false,
        'error' => 'missing_fields',
        'message' => 'Tous les champs obligatoires doivent être renseignés.',
    ], 422);
}

if (!filter_var($gitUrl, FILTER_VALIDATE_URL)) {
    json_response([
        'ok' => false,
        'error' => 'invalid_repository',
        'message' => 'L’URL du dépôt GitHub ou GitLab n’est pas valide.',
    ], 422);
}

$repository = detect_repository($gitUrl);
if ($repository === false) {
    json_response([
        'ok' => false,
        'error' => 'invalid_repository',
        'message' => 'Utilisez une URL de dépôt GitHub ou GitLab HTTPS.',
    ], 422);
}

$gitUrl = $repository['clone_url'];

if ($is_active_cloudflare_turnstile) {
    $turnstileResponse = (string) ($_POST['cf-turnstile-response'] ?? '');
    if (!verify_turnstile($cloudflare_turnstile_secretkey, $turnstileResponse)) {
        json_response([
            'ok' => false,
            'error' => 'captcha_failed',
            'message' => 'La validation anti-robot a échoué.',
        ], 422);
    }
}

$defaultBranch = detect_default_branch($gitUrl);
$remoteCommit = resolve_remote_commit($gitUrl, $defaultBranch);

if ($remoteCommit === false) {
    json_response([
        'ok' => false,
        'error' => 'git_revision_unavailable',
        'message' => 'Impossible de déterminer la révision actuelle du dépôt.',
    ], 422);
}

[$famBody, $famUrl, $famError] = fetch_application_fam($repository, $remoteCommit);

if ($famBody === false) {
    json_response([
        'ok' => false,
        'error' => 'application_fam_not_found',
        'message' => 'application.fam est introuvable à la racine de la révision demandée.',
        'details' => $debug ? ['url' => $famUrl, 'error' => $famError] : null,
    ], 422);
}

if (!preg_match('/appid\s*=\s*"([a-z0-9_-]+)"/i', $famBody, $appidMatch)
    || !preg_match('/name\s*=\s*"([^"]+)"/i', $famBody, $nameMatch)) {
    json_response([
        'ok' => false,
        'error' => 'invalid_application_fam',
        'message' => 'Le fichier application.fam ne contient pas un appid et un nom exploitables.',
    ], 422);
}

$appId = trim($appidMatch[1]);
$appName = trim($nameMatch[1]);

$bannedWords = array_values(array_filter(
    array_map('trim', explode(',', (string) (getenv('BANNED_APPLICATION_WORDS') ?: ''))),
    static fn(string $word): bool => $word !== ''
));

foreach ($bannedWords as $word) {
    if (stripos($appName, $word) !== false || stripos($gitUrl, $word) !== false) {
        json_response([
            'ok' => false,
            'error' => 'application_blocked',
            'message' => 'Cette application est bloquée par la politique locale de compilation.',
        ], 403);
    }
}

$versionType = $gitBranch === 2 ? 'dev' : 'release';

$firmwareQuery = $bdd_connexion->prepare('
    SELECT
        f.firmware_id,
        f.firmware_name,
        f.firmware_url_update,
        f.firmware_ufbt_path,
        fv.firmware_version_id,
        fv.firmware_version_name,
        fv.firmware_version_update_date,
        fv.firmware_version_type
    FROM fzco_firmware f
    INNER JOIN fzco_depend d
        ON d.depend_firmware_id = f.firmware_id
    INNER JOIN fzco_firmware_version fv
        ON fv.firmware_version_id = d.depend_firmware_version_id
    WHERE f.firmware_id = :firmware_id
      AND fv.firmware_version_type = :version_type
      AND f.firmware_is_active = 1
      AND fv.firmware_version_is_active = 1
    LIMIT 1
');
$firmwareQuery->execute([
    'firmware_id' => $firmwareTarget,
    'version_type' => $versionType,
]);
$firmware = $firmwareQuery->fetch(PDO::FETCH_ASSOC);

if (!$firmware) {
    json_response([
        'ok' => false,
        'error' => 'firmware_unavailable',
        'message' => 'Le firmware ou le canal demandé n’est pas disponible.',
    ], 422);
}

$engineVersion = (string) ($build_engine_version ?? '1');

$buildKeyMaterial = [
    'repository' => $gitUrl,
    'branch' => $defaultBranch,
    'commit' => $remoteCommit,
    'firmware_id' => (int) $firmware['firmware_id'],
    'firmware_version_id' => (int) $firmware['firmware_version_id'],
    'firmware_version' => $firmware['firmware_version_name'],
    'firmware_stamp' => $firmware['firmware_version_update_date'],
    'firmware_update_url' => $firmware['firmware_url_update'],
    'channel' => $versionType,
    'engine' => $engineVersion,
];

$buildKey = hash(
    'sha256',
    json_encode($buildKeyMaterial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

$now = date('Y-m-d H:i:s');
$newPublicId = bin2hex(random_bytes(16));
$buildPath = 'cache/' . substr($buildKey, 0, 2) . '/' . $buildKey;

$insertJob = $bdd_connexion->prepare('
    INSERT INTO fzco_build_job (
        public_job_id,
        build_key,
        application_name,
        application_appid,
        application_url_git,
        repository_branch,
        repository_commit,
        firmware_id,
        firmware_name,
        firmware_version_id,
        firmware_version_name,
        firmware_version_stamp,
        sdk_channel,
        engine_version,
        build_path,
        build_status,
        priority,
        request_count,
        created_at,
        queued_at,
        last_requested_at
    ) VALUES (
        :public_job_id,
        :build_key,
        :application_name,
        :application_appid,
        :application_url_git,
        :repository_branch,
        :repository_commit,
        :firmware_id,
        :firmware_name,
        :firmware_version_id,
        :firmware_version_name,
        :firmware_version_stamp,
        :sdk_channel,
        :engine_version,
        :build_path,
        "queued",
        0,
        1,
        :created_at,
        :queued_at,
        :last_requested_at
    )
    ON DUPLICATE KEY UPDATE
        build_job_id = LAST_INSERT_ID(build_job_id),
        request_count = request_count + 1,
        last_requested_at = VALUES(last_requested_at),
        application_name = VALUES(application_name),
        application_appid = VALUES(application_appid)
');

$selectJob = $bdd_connexion->prepare('
    SELECT *
    FROM fzco_build_job
    WHERE build_job_id = :id
    FOR UPDATE
');

$insertRequest = $bdd_connexion->prepare('
    INSERT INTO fzco_build_request (
        request_id,
        build_job_id,
        requested_at
    ) VALUES (
        :request_id,
        :build_job_id,
        :requested_at
    )
');

$resetJob = $bdd_connexion->prepare('
    UPDATE fzco_build_job
    SET build_status = "queued",
        queued_at = :queued_at,
        started_at = NULL,
        finished_at = NULL,
        error_code = NULL
    WHERE build_job_id = :id
');

$taskMustBeCreated = false;
$cacheHit = false;
$deduplicated = false;
$job = null;

try {
    $bdd_connexion->beginTransaction();

    $insertJob->execute([
        'public_job_id' => $newPublicId,
        'build_key' => $buildKey,
        'application_name' => $appName,
        'application_appid' => $appId,
        'application_url_git' => $gitUrl,
        'repository_branch' => $defaultBranch,
        'repository_commit' => $remoteCommit,
        'firmware_id' => (int) $firmware['firmware_id'],
        'firmware_name' => $firmware['firmware_name'],
        'firmware_version_id' => (int) $firmware['firmware_version_id'],
        'firmware_version_name' => $firmware['firmware_version_name'],
        'firmware_version_stamp' => $firmware['firmware_version_update_date'],
        'sdk_channel' => $versionType,
        'engine_version' => $engineVersion,
        'build_path' => $buildPath,
        'created_at' => $now,
        'queued_at' => $now,
        'last_requested_at' => $now,
    ]);

    $wasInserted = $insertJob->rowCount() === 1;
    $buildJobId = (int) $bdd_connexion->lastInsertId();

    $selectJob->execute(['id' => $buildJobId]);
    $job = $selectJob->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        throw new RuntimeException('Unable to reload build job');
    }

    $insertRequest->execute([
        'request_id' => bin2hex(random_bytes(16)),
        'build_job_id' => $buildJobId,
        'requested_at' => $now,
    ]);

    $expectedFap = rtrim($fap_path, '/') . '/'
        . $job['build_path'] . '/'
        . $job['application_appid'] . '.fap';

    if (!$wasInserted && $job['build_status'] === 'success' && is_file($expectedFap)) {
        $cacheHit = true;
    } elseif (!$wasInserted && in_array($job['build_status'], ['queued', 'running'], true)) {
        $deduplicated = true;
    } else {
        if (!$wasInserted) {
            $resetJob->execute([
                'queued_at' => $now,
                'id' => $buildJobId,
            ]);
            $job['build_status'] = 'queued';
            $job['queued_at'] = $now;
        }

        $taskMustBeCreated = true;
    }

    if ($taskMustBeCreated) {
        $publicId = (string) $job['public_job_id'];
        $cloneDir = __DIR__ . '/../../gits/' . $publicId . '/new';
        $stateDir = rtrim($path_to_ufbt, '/') . '/fz_'
            . $firmware['firmware_ufbt_path'] . '_' . $versionType;
        $outputDir = rtrim($fap_path, '/') . '/' . $job['build_path'];
        $outputFap = $outputDir . '/' . $appId . '.fap';

        $taskFile = rtrim($task_list, '/') . '/' . $publicId . '.sh';
        $tempTaskFile = rtrim($task_list, '/') . '/.' . $publicId . '.tmp';

        $taskBody = build_task_script(
            $gitUrl,
            $remoteCommit,
            $cloneDir,
            $stateDir,
            $versionType,
            $firmware['firmware_url_update'],
            $outputDir,
            $outputFap
        );

        if (file_put_contents($tempTaskFile, $taskBody, LOCK_EX) === false
            || !chmod($tempTaskFile, 0755)
            || !rename($tempTaskFile, $taskFile)) {
            @unlink($tempTaskFile);
            @unlink($taskFile);
            throw new RuntimeException('Unable to create build task');
        }
    }

    $bdd_connexion->commit();
} catch (Throwable $e) {
    if ($bdd_connexion->inTransaction()) {
        $bdd_connexion->rollBack();
    }

    json_response([
        'ok' => false,
        'error' => 'queue_error',
        'message' => 'La demande n’a pas pu être ajoutée à la file de compilation.',
        'details' => $debug ? $e->getMessage() : null,
    ], 500);
}

$queuePosition = null;
$currentStatus = (string) $job['build_status'];

if ($cacheHit) {
    $currentStatus = 'success';
} elseif ($currentStatus === 'queued') {
    $queuePosition = fzcoQueuePosition($bdd_connexion, (int) $job['build_job_id']);
}

$download = null;
if ($cacheHit) {
    $download = '/faps/' . $job['build_path'] . '/' . rawurlencode($appId) . '.fap';
}

json_response([
    'ok' => true,
    'job' => $job['public_job_id'],
    'status' => $currentStatus,
    'queue_position' => $queuePosition,
    'cache_hit' => $cacheHit,
    'deduplicated' => $deduplicated,
    'request_count' => (int) $job['request_count'],
    'download' => $download,
    'application' => [
        'name' => $appName,
        'appid' => $appId,
        'repository' => $gitUrl,
        'branch' => $defaultBranch,
        'commit' => $remoteCommit,
    ],
    'firmware' => [
        'name' => $firmware['firmware_name'],
        'version' => $firmware['firmware_version_name'],
        'channel' => $versionType,
    ],
]);
