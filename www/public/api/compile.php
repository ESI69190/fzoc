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

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            remove_tree($target);
        } else {
            @unlink($target);
        }
    }

    @rmdir($path);
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

function fetch_application_fam(array $repository, string $branch): array
{
    if ($repository['provider'] === 'github') {
        $url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/application.fam',
            rawurlencode($repository['owner']),
            rawurlencode($repository['repo']),
            rawurlencode($branch)
        );
    } else {
        $url = sprintf(
            'https://gitlab.com/%s/-/raw/%s/application.fam',
            $repository['path'],
            rawurlencode($branch)
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
[$famBody, $famUrl, $famError] = fetch_application_fam($repository, $defaultBranch);

if ($famBody === false) {
    json_response([
        'ok' => false,
        'error' => 'application_fam_not_found',
        'message' => 'application.fam est introuvable à la racine de la branche par défaut.',
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

$timestamp = time();
$repoHash = md5($gitUrl);
$relativePath = $repoHash . '/' . $timestamp;
$jobId = $repoHash . '_' . $timestamp;
$destinationDir = __DIR__ . '/../../gits/' . $relativePath;
$cloneDir = $destinationDir . '/new';

if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
    json_response([
        'ok' => false,
        'error' => 'runtime_permissions',
        'message' => 'Impossible de créer le répertoire de travail. Vérifiez les permissions de www/gits.',
    ], 500);
}

$cloneOutput = [];
$cloneExit = 0;
exec(
    'git clone --depth 1 ' . escapeshellarg($gitUrl) . ' ' . escapeshellarg($cloneDir) . ' 2>&1',
    $cloneOutput,
    $cloneExit
);

if ($cloneExit !== 0) {
    remove_tree($destinationDir);
    json_response([
        'ok' => false,
        'error' => 'git_clone_failed',
        'message' => 'Le dépôt n’a pas pu être cloné.',
        'details' => $debug ? implode("\n", $cloneOutput) : null,
    ], 422);
}

$applicationQuery = $bdd_connexion->prepare('
    SELECT application_id
    FROM fzco_application
    WHERE application_url_git = :git_url
    LIMIT 1
');
$applicationQuery->execute(['git_url' => $gitUrl]);
$applicationId = $applicationQuery->fetchColumn();

try {
    $bdd_connexion->beginTransaction();

    if ($applicationId === false) {
        $insertApplication = $bdd_connexion->prepare('
            INSERT INTO fzco_application (
                application_name,
                application_appid,
                application_url_git
            ) VALUES (
                :name,
                :appid,
                :git_url
            )
        ');
        $insertApplication->execute([
            'name' => $appName,
            'appid' => $appId,
            'git_url' => $gitUrl,
        ]);
        $applicationId = (int) $bdd_connexion->lastInsertId();
    } else {
        $applicationId = (int) $applicationId;

        $updateApplication = $bdd_connexion->prepare('
            UPDATE fzco_application
            SET application_name = :name,
                application_appid = :appid
            WHERE application_id = :id
        ');
        $updateApplication->execute([
            'name' => $appName,
            'appid' => $appId,
            'id' => $applicationId,
        ]);
    }

    $insertCompiled = $bdd_connexion->prepare('
        INSERT INTO fzco_compiled (
            compiled_firmware_version_id,
            compiled_application_id,
            compiled_date,
            compiled_path_fap,
            compiled_status
        ) VALUES (
            :firmware_version_id,
            :application_id,
            :compiled_date,
            :compiled_path,
            "pending"
        )
    ');
    $insertCompiled->execute([
        'firmware_version_id' => (int) $firmware['firmware_version_id'],
        'application_id' => $applicationId,
        'compiled_date' => date('Y-m-d H:i:s', $timestamp),
        'compiled_path' => $relativePath,
    ]);

    $bdd_connexion->commit();
} catch (Throwable $e) {
    if ($bdd_connexion->inTransaction()) {
        $bdd_connexion->rollBack();
    }
    remove_tree($destinationDir);

    json_response([
        'ok' => false,
        'error' => 'database_error',
        'message' => 'La demande n’a pas pu être enregistrée.',
        'details' => $debug ? $e->getMessage() : null,
    ], 500);
}

$stateDir = rtrim($path_to_ufbt, '/') . '/fz_' . $firmware['firmware_ufbt_path'] . '_' . $versionType;
$outputDir = rtrim($fap_path, '/') . '/' . $relativePath;
$outputFap = $outputDir . '/' . $appId . '.fap';

$taskLines = [
    '#!/bin/sh',
    'set -eu',
    'cd ' . escapeshellarg(rtrim($path_to_ufbt, '/')),
    '. bin/activate',
    'cd ' . escapeshellarg($cloneDir),
    'ufbt dotenv_create --state-dir ' . escapeshellarg($stateDir),
];

$updateCommand = 'ufbt update';
if ($versionType === 'dev') {
    $updateCommand .= ' --channel dev';
}
$updateCommand .= ' --index-url=' . escapeshellarg($firmware['firmware_url_update']);
$taskLines[] = $updateCommand;
$taskLines[] = 'ufbt';
$taskLines[] = 'mkdir -p ' . escapeshellarg($outputDir);
$taskLines[] = 'FAP_FILE="$(find dist -maxdepth 1 -type f -name \'*.fap\' | head -n 1)"';
$taskLines[] = 'test -n "$FAP_FILE"';
$taskLines[] = 'mv "$FAP_FILE" ' . escapeshellarg($outputFap);
$taskLines[] = '';

$taskFile = rtrim($task_list, '/') . '/' . $jobId . '.sh';
$tempTaskFile = rtrim($task_list, '/') . '/.' . $jobId . '.tmp';

if (file_put_contents($tempTaskFile, implode("\n", $taskLines), LOCK_EX) === false
    || !chmod($tempTaskFile, 0755)
    || !rename($tempTaskFile, $taskFile)) {
    @unlink($tempTaskFile);
    @unlink($taskFile);
    remove_tree($destinationDir);

    $cleanup = $bdd_connexion->prepare('
        DELETE FROM fzco_compiled
        WHERE compiled_path_fap = :path
          AND compiled_status = "pending"
    ');
    $cleanup->execute(['path' => $relativePath]);

    json_response([
        'ok' => false,
        'error' => 'runtime_permissions',
        'message' => 'Impossible de créer la tâche de compilation. Vérifiez les permissions de www/tasks.',
    ], 500);
}

json_response([
    'ok' => true,
    'job' => $jobId,
    'status' => 'queued',
    'application' => [
        'name' => $appName,
        'appid' => $appId,
        'repository' => $gitUrl,
        'branch' => $defaultBranch,
    ],
    'firmware' => [
        'name' => $firmware['firmware_name'],
        'version' => $firmware['firmware_version_name'],
        'channel' => $versionType,
    ],
]);
