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
require_once __DIR__ . '/../../class/fzcoFirmwareCatalog.class.php';

fzcoEnsureBuildQueueSchema($bdd_connexion);
fzcoEnsureFirmwareCatalogSchema($bdd_connexion);

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

function is_public_git_host(string $host): bool
{
    $host = trim($host, '[]');
    if ($host === '') {
        return false;
    }

    $lowerHost = strtolower($host);
    if ($lowerHost === 'localhost' || str_ends_with($lowerHost, '.localhost')) {
        return false;
    }

    $addresses = [];

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $addresses[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records) || count($records) === 0) {
            return false;
        }

        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $addresses[] = (string) $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $addresses[] = (string) $record['ipv6'];
            }
        }
    }

    if (count($addresses) === 0) {
        return false;
    }

    foreach (array_unique($addresses) as $address) {
        if (!filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        )) {
            return false;
        }
    }

    return true;
}

function detect_repository(string $url): array|false
{
    $url = trim($url);

    // Accept the clone formats users commonly copy from Git hosting UIs and
    // normalize them to anonymous HTTPS so the public compiler never needs
    // repository credentials or SSH keys.
    if (preg_match('~^(?:[^@/\\s]+@)([a-z0-9.-]+):(.+)$~i', $url, $m)) {
        $url = 'https://' . $m[1] . '/' . ltrim($m[2], '/');
    } elseif (preg_match('~^ssh://(?:[^@/\\s]+@)?([^/:?#]+)(?::[0-9]+)?/(.+)$~i', $url, $m)) {
        $url = 'https://' . $m[1] . '/' . ltrim($m[2], '/');
    } elseif (preg_match('~^git://([^/:?#]+)(?::[0-9]+)?/(.+)$~i', $url, $m)) {
        $url = 'https://' . $m[1] . '/' . ltrim($m[2], '/');
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = trim((string) ($parts['path'] ?? ''), '/');

    if (
        $scheme !== 'https'
        || $host === ''
        || $path === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
        || (isset($parts['port']) && (int) $parts['port'] !== 443)
        || !is_public_git_host($host)
    ) {
        return false;
    }

    return [
        'provider' => 'git',
        'clone_url' => 'https://' . $host . '/' . $path,
        'host' => $host,
        'path' => $path,
    ];
}

function git_remote_command_prefix(): string
{
    return 'GIT_TERMINAL_PROMPT=0 git'
        . ' -c protocol.file.allow=never'
        . ' -c protocol.ext.allow=never'
        . ' -c http.followRedirects=false';
}

function resolve_remote_head(string $cloneUrl): array|false
{
    $output = [];
    $exitCode = 0;

    exec(
        git_remote_command_prefix()
        . ' ls-remote --symref ' . escapeshellarg($cloneUrl)
        . ' HEAD 2>/dev/null',
        $output,
        $exitCode
    );

    if ($exitCode !== 0) {
        return false;
    }

    $branch = 'HEAD';
    $commit = null;

    foreach ($output as $line) {
        $line = trim($line);

        if (preg_match('/^ref:\\s+refs\\/heads\\/([^\\s]+)\\s+HEAD$/', $line, $m)) {
            $branch = $m[1];
            continue;
        }

        if (preg_match('/^([a-f0-9]{40,64})\\s+HEAD$/i', $line, $m)) {
            $commit = strtolower($m[1]);
        }
    }

    if ($commit === null) {
        return false;
    }

    return [
        'branch' => $branch,
        'commit' => $commit,
    ];
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

function fetch_application_fam(array $repository, string $revision): array
{
    $cloneUrl = (string) $repository['clone_url'];
    $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'fzoc-fam-'
        . bin2hex(random_bytes(8));

    if (!@mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
        return [false, $cloneUrl, 'temporary_directory_failed'];
    }

    try {
        $output = [];
        $exitCode = 0;

        exec('git init -q ' . escapeshellarg($tempDir) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            return [false, $cloneUrl, implode("\\n", $output)];
        }

        $output = [];
        exec(
            'git -C ' . escapeshellarg($tempDir)
            . ' remote add origin ' . escapeshellarg($cloneUrl)
            . ' 2>&1',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) {
            return [false, $cloneUrl, implode("\\n", $output)];
        }

        $output = [];
        exec(
            git_remote_command_prefix()
            . ' -C ' . escapeshellarg($tempDir)
            . ' fetch -q --depth 1 --filter=blob:none origin ' . escapeshellarg($revision)
            . ' 2>&1',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) {
            return [false, $cloneUrl, implode("\\n", $output)];
        }

        $output = [];
        exec(
            git_remote_command_prefix()
            . ' -C ' . escapeshellarg($tempDir)
            . ' show FETCH_HEAD:application.fam 2>&1',
            $output,
            $exitCode
        );

        if ($exitCode !== 0) {
            return [
                false,
                $cloneUrl . '#' . $revision . ':application.fam',
                implode("\\n", $output),
            ];
        }

        return [
            implode("\n", $output) . "\n",
            $cloneUrl . '#' . $revision . ':application.fam',
            null,
        ];
    } finally {
        remove_tree($tempDir);
    }
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
    string $outputDir,
    string $outputFap,
    array $firmware,
    string $firmwareCachePath,
    string $appId,
    string $publicId
): string {
    $lines = [
        '#!/bin/sh',
        'set -eu',
        'rm -rf -- ' . escapeshellarg($cloneDir),
        'mkdir -p ' . escapeshellarg(dirname($cloneDir)),
        'git init -q ' . escapeshellarg($cloneDir),
        'git -C ' . escapeshellarg($cloneDir) . ' remote add origin ' . escapeshellarg($gitUrl),
        git_remote_command_prefix() . ' -C ' . escapeshellarg($cloneDir) . ' fetch -q --depth 1 origin ' . escapeshellarg($commit),
        'git -C ' . escapeshellarg($cloneDir) . ' checkout -q --detach FETCH_HEAD',
    ];

    if ($firmware['build_method'] === 'ufbt') {
        $lines[] = 'cd ' . escapeshellarg(rtrim($GLOBALS['path_to_ufbt'], '/'));
        $lines[] = '. bin/activate';
        $lines[] = 'cd ' . escapeshellarg($cloneDir);
        $lines[] = 'ufbt dotenv_create --state-dir ' . escapeshellarg($stateDir);

        $sdkUrl = trim((string) ($firmware['sdk_url'] ?? ''));

        if ($sdkUrl !== '') {
            // Exact SDK archive, ideal for pinned and historical releases.
            $lines[] = 'ufbt update'
                . ' --url=' . escapeshellarg($sdkUrl)
                . ' --hw-target=f7';
        } else {
            // directory.json is a channel index, not a branch-root URL.
            // Using it with --branch makes uFBT append "/<branch>/" to
            // directory.json and guarantees a broken URL.
            $channel = match ($firmware['channel']) {
                'dev' => 'dev',
                'rc' => 'rc',
                default => 'release',
            };

            $lines[] = 'ufbt update'
                . ' --channel=' . escapeshellarg($channel)
                . ' --index-url=' . escapeshellarg($firmware['directory_url']);
        }

        $lines[] = 'ufbt';
        $lines[] = 'mkdir -p ' . escapeshellarg($outputDir);
        $lines[] = 'FAP_FILE="$(find dist -maxdepth 1 -type f -name \'*.fap\' | head -n 1)"';
        $lines[] = 'test -n "$FAP_FILE"';
        $lines[] = 'mv -f "$FAP_FILE" ' . escapeshellarg($outputFap);
        $lines[] = '';

        return implode("\n", $lines);
    }

    if ($firmware['build_method'] !== 'fbt_source') {
        throw new RuntimeException('Unsupported firmware build method');
    }

    $firmwareRepo = trim((string) $firmware['repository_url']);
    $firmwareRef = trim((string) (
        $firmware['source_commit']
        ?: $firmware['version_ref']
    ));

    if ($firmwareRepo === '' || $firmwareRef === '') {
        throw new RuntimeException('Firmware source reference is incomplete');
    }

    $cacheKey = hash('sha256', $firmwareRepo . '|' . $firmwareRef);
    $firmwareDir = rtrim($firmwareCachePath, '/')
        . '/' . $firmware['firmware_slug'] . '/' . $cacheKey;
    $tempFirmwareDir = $firmwareDir . '.tmp-' . $publicId;
    $applicationRelative = 'applications_user/fzoc_' . $publicId;
    $applicationDir = $firmwareDir . '/' . $applicationRelative;

    $lines[] = 'if ! git -C ' . escapeshellarg($firmwareDir)
        . ' rev-parse --git-dir >/dev/null 2>&1; then';
    $lines[] = '  rm -rf -- ' . escapeshellarg($firmwareDir);
    $lines[] = '  rm -rf -- ' . escapeshellarg($tempFirmwareDir);
    $lines[] = '  mkdir -p ' . escapeshellarg(dirname($firmwareDir));
    $lines[] = '  git init -q ' . escapeshellarg($tempFirmwareDir);
    $lines[] = '  git -C ' . escapeshellarg($tempFirmwareDir)
        . ' remote add origin ' . escapeshellarg($firmwareRepo);
    $lines[] = '  git -C ' . escapeshellarg($tempFirmwareDir)
        . ' fetch -q --depth 1 origin ' . escapeshellarg($firmwareRef);
    $lines[] = '  git -C ' . escapeshellarg($tempFirmwareDir)
        . ' checkout -q -B fzoc-build FETCH_HEAD';
    $lines[] = '  if [ ! -f ' . escapeshellarg($tempFirmwareDir . '/fbt') . ' ]; then';
    $lines[] = '    echo "[FZOC] unsupported_source_build: firmware has no fbt"';
    $lines[] = '    rm -rf -- ' . escapeshellarg($tempFirmwareDir);
    $lines[] = '    exit 65';
    $lines[] = '  fi';
    $lines[] = '  git -C ' . escapeshellarg($tempFirmwareDir)
        . ' submodule sync --recursive';
    $lines[] = '  for SUBMODULE_PATH in $(git -C ' . escapeshellarg($tempFirmwareDir)
        . ' config -f .gitmodules --get-regexp ' . escapeshellarg('^submodule\\..*\\.path$')
        . ' 2>/dev/null | awk ' . escapeshellarg('{print $2}')
        . ' | grep -v ' . escapeshellarg('^applications/')
        . ' || true); do';
    $lines[] = '    git -C ' . escapeshellarg($tempFirmwareDir)
        . ' submodule update --init --recursive --depth 1 -- "$SUBMODULE_PATH"';
    $lines[] = '  done';
    $lines[] = '  mv ' . escapeshellarg($tempFirmwareDir)
        . ' ' . escapeshellarg($firmwareDir);
    $lines[] = 'fi';
    $lines[] = 'git -C ' . escapeshellarg($firmwareDir)
        . ' checkout -q -B fzoc-build HEAD';
    $lines[] = 'test -f ' . escapeshellarg($firmwareDir . '/fbt')
        . ' || { echo "[FZOC] unsupported_source_build: firmware has no fbt"; exit 65; }';

    $cleanupCommand = 'rm -rf -- ' . escapeshellarg($applicationDir);

    $lines[] = $cleanupCommand;
    $lines[] = 'mkdir -p ' . escapeshellarg(dirname($applicationDir));
    $lines[] = 'cp -a ' . escapeshellarg($cloneDir)
        . ' ' . escapeshellarg($applicationDir);
    $lines[] = 'trap ' . escapeshellarg($cleanupCommand) . ' EXIT';
    $lines[] = 'cd ' . escapeshellarg($firmwareDir);
    $lines[] = 'FBT_NO_SYNC=1 ./fbt build APPSRC=' . escapeshellarg($applicationRelative);
    $lines[] = 'mkdir -p ' . escapeshellarg($outputDir);
    $lines[] = 'FAP_FILE="$(find build -type f -name '
        . escapeshellarg($appId . '.fap')
        . ' | head -n 1)"';
    $lines[] = 'test -n "$FAP_FILE"';
    $lines[] = 'cp -f "$FAP_FILE" ' . escapeshellarg($outputFap);
    $lines[] = '';

    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$gitUrl = trim((string) ($_POST['git_url'] ?? ''));
$firmwareSlug = strtolower(trim((string) ($_POST['firmware_slug'] ?? '')));
$firmwareChannel = strtolower(trim((string) ($_POST['firmware_channel'] ?? '')));
$firmwareCatalogId = (int) ($_POST['firmware_version'] ?? 0);

if (
    $gitUrl === ''
    || $firmwareCatalogId < 1
    || !preg_match('/^[a-z0-9_-]{1,64}$/', $firmwareSlug)
    || !preg_match('/^[a-z0-9_-]{1,32}$/', $firmwareChannel)
) {
    json_response([
        'ok' => false,
        'error' => 'missing_fields',
        'message' => 'Le firmware, le canal et la version doivent être sélectionnés.',
    ], 422);
}

$repository = detect_repository($gitUrl);
if ($repository === false) {
    json_response([
        'ok' => false,
        'error' => 'invalid_repository',
        'message' => 'Utilisez un dépôt Git public accessible en HTTPS. Les URL HTTPS, git@hôte:chemin.git, ssh:// et git:// sont acceptées.',
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

$remoteHead = resolve_remote_head($gitUrl);

if ($remoteHead === false) {
    json_response([
        'ok' => false,
        'error' => 'git_revision_unavailable',
        'message' => 'Impossible d’accéder au dépôt Git public ou de déterminer sa révision HEAD.',
    ], 422);
}

$defaultBranch = (string) $remoteHead['branch'];
$remoteCommit = (string) $remoteHead['commit'];

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

$catalogQuery = $bdd_connexion->prepare('
    SELECT
        c.catalog_id,
        c.firmware_slug,
        c.channel,
        c.version_name,
        c.version_ref,
        c.version_stamp,
        c.source_commit,
        c.sdk_url,
        s.firmware_name,
        s.directory_url,
        s.repository_url,
        COALESCE(c.build_method, s.build_method) AS selected_build_method,
        s.legacy_firmware_id,
        s.state_slug
    FROM fzco_firmware_catalog c
    INNER JOIN fzco_firmware_source s
        ON s.firmware_slug = c.firmware_slug
    WHERE c.catalog_id = :catalog_id
      AND c.firmware_slug = :firmware_slug
      AND c.channel = :channel
      AND c.active = 1
      AND s.active = 1
    LIMIT 1
');
$catalogQuery->execute([
    'catalog_id' => $firmwareCatalogId,
    'firmware_slug' => $firmwareSlug,
    'channel' => $firmwareChannel,
]);
$catalog = $catalogQuery->fetch(PDO::FETCH_ASSOC);

if (!$catalog) {
    json_response([
        'ok' => false,
        'error' => 'firmware_unavailable',
        'message' => 'La version de firmware demandée n’est pas disponible.',
    ], 422);
}

$versionType = (string) $catalog['channel'];

$firmware = [
    'firmware_id' => (int) ($catalog['legacy_firmware_id'] ?: 4),
    'firmware_name' => (string) $catalog['firmware_name'],
    'firmware_url_update' => (string) ($catalog['directory_url'] ?? ''),
    'firmware_ufbt_path' => (string) $catalog['state_slug'],
    'firmware_version_id' => (int) $catalog['catalog_id'],
    'firmware_version_name' => (string) $catalog['version_name'],
    'firmware_version_update_date' => (string) $catalog['version_stamp'],
    'firmware_slug' => (string) $catalog['firmware_slug'],
    'channel' => (string) $catalog['channel'],
    'version_ref' => (string) $catalog['version_ref'],
    'source_commit' => $catalog['source_commit'],
    'sdk_url' => (string) ($catalog['sdk_url'] ?? ''),
    'directory_url' => (string) ($catalog['directory_url'] ?? ''),
    'repository_url' => (string) ($catalog['repository_url'] ?? ''),
    'build_method' => (string) $catalog['selected_build_method'],
];

$engineVersion = (string) ($build_engine_version ?? '1');

$buildKeyMaterial = [
    'repository' => $gitUrl,
    'branch' => $defaultBranch,
    'commit' => $remoteCommit,
    'firmware_slug' => $firmware['firmware_slug'],
    'firmware_id' => (int) $firmware['firmware_id'],
    'firmware_version_id' => (int) $firmware['firmware_version_id'],
    'firmware_version' => $firmware['firmware_version_name'],
    'firmware_ref' => $firmware['version_ref'],
    'firmware_commit' => $firmware['source_commit'],
    'firmware_sdk_url' => $firmware['sdk_url'],
    'firmware_stamp' => $firmware['firmware_version_update_date'],
    'firmware_update_url' => $firmware['firmware_url_update'],
    'build_method' => $firmware['build_method'],
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
            . $firmware['firmware_ufbt_path'] . '_'
            . $versionType . '_'
            . substr(sha1($firmware['version_ref']), 0, 12);
        $outputDir = rtrim($fap_path, '/') . '/' . $job['build_path'];
        $outputFap = $outputDir . '/' . $appId . '.fap';

        $taskFile = rtrim($task_list, '/') . '/' . $publicId . '.sh';
        $tempTaskFile = rtrim($task_list, '/') . '/.' . $publicId . '.tmp';

        $taskBody = build_task_script(
            $gitUrl,
            $remoteCommit,
            $cloneDir,
            $stateDir,
            $outputDir,
            $outputFap,
            $firmware,
            $firmware_cache_path,
            $appId,
            $publicId
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
