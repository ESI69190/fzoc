<?php

declare(strict_types=1);

function fzcoEnsureFirmwareCatalogSchema(PDO $db): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fzco_firmware_source (
    firmware_slug VARCHAR(64) NOT NULL,
    firmware_name VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    source_type VARCHAR(32) NOT NULL,
    directory_url TEXT NULL,
    repository_url TEXT NULL,
    api_url TEXT NULL,
    build_method VARCHAR(32) NOT NULL,
    legacy_firmware_id INT NULL,
    state_slug VARCHAR(64) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_synced_at DATETIME NULL,
    PRIMARY KEY (firmware_slug),
    KEY idx_fzco_firmware_source_active (active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fzco_firmware_catalog (
    catalog_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    firmware_slug VARCHAR(64) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    version_name VARCHAR(255) NOT NULL,
    version_ref VARCHAR(255) NOT NULL,
    version_stamp DATETIME NOT NULL,
    source_commit VARCHAR(64) NULL,
    build_method VARCHAR(32) NULL,
    sdk_url TEXT NULL,
    is_latest TINYINT(1) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (catalog_id),
    UNIQUE KEY uq_fzco_firmware_catalog (firmware_slug, channel, version_ref),
    KEY idx_fzco_firmware_catalog_list (firmware_slug, channel, active, version_stamp),
    KEY idx_fzco_firmware_catalog_latest (firmware_slug, channel, is_latest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $buildMethodColumn = $db->query(
        "SHOW COLUMNS FROM fzco_firmware_catalog LIKE 'build_method'"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$buildMethodColumn) {
        $db->exec(
            'ALTER TABLE fzco_firmware_catalog
             ADD COLUMN build_method VARCHAR(32) NULL AFTER source_commit'
        );
    }

    $sdkUrlColumn = $db->query(
        "SHOW COLUMNS FROM fzco_firmware_catalog LIKE 'sdk_url'"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$sdkUrlColumn) {
        $db->exec(
            'ALTER TABLE fzco_firmware_catalog
             ADD COLUMN sdk_url TEXT NULL AFTER build_method'
        );
    }

    $db->exec(
        'ALTER TABLE fzco_firmware_catalog
         MODIFY COLUMN channel VARCHAR(64) NOT NULL'
    );

    $sources = [
        ['official', 'Official', 10, 'hybrid', 'https://update.flipperzero.one/firmware/directory.json', 'https://github.com/flipperdevices/flipperzero-firmware.git', 'https://api.github.com/repos/flipperdevices/flipperzero-firmware', 'ufbt', 1, 'official'],
        ['momentum', 'Momentum', 20, 'hybrid', 'https://up.momentum-fw.dev/firmware/directory.json', 'https://github.com/Next-Flip/Momentum-Firmware.git', 'https://api.github.com/repos/Next-Flip/Momentum-Firmware', 'ufbt', 2, 'momentum'],
        ['unleashed', 'Unleashed', 30, 'hybrid', 'https://up.unleashedflip.com/directory.json', 'https://github.com/DarkFlippers/unleashed-firmware.git', 'https://api.github.com/repos/DarkFlippers/unleashed-firmware', 'ufbt', 3, 'unleashed'],
        ['roguemaster', 'RogueMaster', 40, 'github_releases', null, 'https://github.com/RogueMaster/flipperzero-firmware-wPlugins.git', 'https://api.github.com/repos/RogueMaster/flipperzero-firmware-wPlugins', 'fbt_source', 4, 'roguemaster'],
    ];

    $upsert = $db->prepare(<<<'SQL'
INSERT INTO fzco_firmware_source (
    firmware_slug, firmware_name, sort_order, source_type, directory_url,
    repository_url, api_url, build_method, legacy_firmware_id, state_slug, active
) VALUES (
    :slug, :name, :sort_order, :source_type, :directory_url,
    :repository_url, :api_url, :build_method, :legacy_firmware_id, :state_slug, 1
)
ON DUPLICATE KEY UPDATE
    firmware_name = VALUES(firmware_name),
    sort_order = VALUES(sort_order),
    source_type = VALUES(source_type),
    directory_url = VALUES(directory_url),
    repository_url = VALUES(repository_url),
    api_url = VALUES(api_url),
    build_method = VALUES(build_method),
    legacy_firmware_id = VALUES(legacy_firmware_id),
    state_slug = VALUES(state_slug),
    active = 1
SQL);

    foreach ($sources as $source) {
        $upsert->execute([
            'slug' => $source[0],
            'name' => $source[1],
            'sort_order' => $source[2],
            'source_type' => $source[3],
            'directory_url' => $source[4],
            'repository_url' => $source[5],
            'api_url' => $source[6],
            'build_method' => $source[7],
            'legacy_firmware_id' => $source[8],
            'state_slug' => $source[9],
        ]);
    }

    $catalogCount = (int) $db->query(
        'SELECT COUNT(*) FROM fzco_firmware_catalog'
    )->fetchColumn();

    if ($catalogCount === 0) {
        fzcoImportLegacyFirmwareVersions($db);
    }

    $db->exec(
        "DELETE FROM fzco_firmware_catalog
         WHERE channel NOT IN ('release', 'rc', 'dev')"
    );

    $db->exec(
        "DELETE FROM fzco_firmware_catalog
         WHERE channel = 'release'
           AND (
               LOWER(version_name) REGEXP '(^|[-_.])(rc|beta|preview|candidate)([-_.0-9]|$)'
               OR LOWER(version_ref) REGEXP '(^|[-_.])(rc|beta|preview|candidate)([-_.0-9]|$)'
           )"
    );

    foreach (['official', 'momentum', 'unleashed', 'roguemaster'] as $firmwareSlug) {
        fzcoLimitFirmwareVersions($db, $firmwareSlug);
    }

    $done = true;
}

function fzcoFirmwareVersionLimit(): int
{
    $limit = (int) (getenv('FZOC_FIRMWARE_VERSION_LIMIT') ?: 5);

    return max(1, min($limit, 20));
}

function fzcoLimitFirmwareVersions(
    PDO $db,
    string $firmwareSlug,
    ?int $limit = null
): void {
    $limit ??= fzcoFirmwareVersionLimit();

    $query = $db->prepare(
        'SELECT catalog_id, channel
         FROM fzco_firmware_catalog
         WHERE firmware_slug = :slug
           AND channel IN ("release", "rc", "dev")
         ORDER BY channel, version_stamp DESC, catalog_id DESC'
    );
    $query->execute(['slug' => $firmwareSlug]);

    $keepIds = [];
    $counts = [];

    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $channel = (string) $row['channel'];
        $counts[$channel] = ($counts[$channel] ?? 0) + 1;

        if ($counts[$channel] <= $limit) {
            $keepIds[] = (int) $row['catalog_id'];
        }
    }

    $deactivate = $db->prepare(
        'UPDATE fzco_firmware_catalog
         SET active = 0, is_latest = 0
         WHERE firmware_slug = :slug'
    );
    $deactivate->execute(['slug' => $firmwareSlug]);

    if ($keepIds !== []) {
        $placeholders = implode(',', array_fill(0, count($keepIds), '?'));
        $activate = $db->prepare(
            'UPDATE fzco_firmware_catalog
             SET active = 1
             WHERE catalog_id IN (' . $placeholders . ')'
        );
        $activate->execute($keepIds);
    }

    fzcoNormalizeLatestFirmwareFlags($db, $firmwareSlug);
}

function fzcoImportLegacyFirmwareVersions(PDO $db): void
{
    $map = [1 => 'official', 2 => 'momentum', 3 => 'unleashed'];

    try {
        $query = $db->query(
            'SELECT f.firmware_id, fv.firmware_version_name,
                    fv.firmware_version_type, fv.firmware_version_update_date,
                    fv.firmware_version_is_active
             FROM fzco_firmware f
             INNER JOIN fzco_depend d ON d.depend_firmware_id = f.firmware_id
             INNER JOIN fzco_firmware_version fv
                ON fv.firmware_version_id = d.depend_firmware_version_id
             WHERE f.firmware_is_active = 1'
        );
    } catch (Throwable $e) {
        return;
    }

    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $firmwareId = (int) $row['firmware_id'];
        if (!isset($map[$firmwareId])) {
            continue;
        }

        $version = trim((string) $row['firmware_version_name']);
        if ($version === '') {
            continue;
        }

        $channel = fzcoNormalizeFirmwareChannel(
            (string) $row['firmware_version_type']
        );

        if ($channel === null) {
            continue;
        }

        fzcoUpsertFirmwareVersion(
            $db,
            $map[$firmwareId],
            $channel,
            $version,
            $version,
            (string) $row['firmware_version_update_date'],
            null,
            false,
            'ufbt'
        );
    }
}

function fzcoNormalizeFirmwareChannel(string $channel): ?string
{
    $channel = strtolower(trim($channel));

    if ($channel === '') {
        return null;
    }

    // CI / pull-request feeds are not firmware channels users should compile against.
    if (
        preg_match('/^pr[-_]?\d+/i', $channel)
        || str_contains($channel, 'pull-request')
        || str_contains($channel, 'pull_request')
        || str_contains($channel, 'github-actions')
        || str_contains($channel, 'ci-')
    ) {
        return null;
    }

    // Test prerelease markers BEFORE generic release-* handling.
    if (
        $channel === 'rc'
        || str_contains($channel, 'release-candidate')
        || str_contains($channel, 'candidate')
        || str_contains($channel, 'preview')
        || str_contains($channel, 'beta')
        || preg_match('/(^|[-_])rc($|[-_0-9])/', $channel)
    ) {
        return 'rc';
    }

    if (
        $channel === 'release'
        || $channel === 'stable'
        || str_starts_with($channel, 'release-')
        || str_ends_with($channel, '-release')
    ) {
        return 'release';
    }

    if (
        $channel === 'dev'
        || $channel === 'develop'
        || $channel === 'development'
        || str_contains($channel, 'nightly')
        || str_starts_with($channel, 'dev-')
        || str_ends_with($channel, '-dev')
    ) {
        return 'dev';
    }

    return null;
}

function fzcoFirmwareChannelLabel(string $channel): string
{
    return match ($channel) {
        'release' => 'Stable / Release',
        'rc' => 'RC / Préversion',
        'dev' => 'Développement',
        default => ucfirst(str_replace(['-', '_'], ' ', $channel)),
    };
}

function fzcoFirmwareTimestamp(mixed $value): string
{
    if (is_numeric($value)) {
        return date('Y-m-d H:i:s', (int) $value);
    }

    $timestamp = strtotime((string) $value);

    return $timestamp === false
        ? date('Y-m-d H:i:s')
        : date('Y-m-d H:i:s', $timestamp);
}

function fzcoFirmwareHttpJson(string $url): array|false
{
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    $token = trim((string) (getenv('FZOC_GITHUB_TOKEN') ?: ''));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_HEADER => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'FZOC/ESI69190',
    ]);

    $body = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false || $code < 200 || $code >= 300) {
        return false;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : false;
}

function fzcoUpsertFirmwareVersion(
    PDO $db,
    string $firmwareSlug,
    string $channel,
    string $versionName,
    string $versionRef,
    string $versionStamp,
    ?string $sourceCommit,
    bool $isLatest,
    ?string $buildMethod = null,
    ?string $sdkUrl = null
): void {
    $statement = $db->prepare(<<<'SQL'
INSERT INTO fzco_firmware_catalog (
    firmware_slug, channel, version_name, version_ref, version_stamp,
    source_commit, build_method, sdk_url, is_latest, active, created_at, updated_at
) VALUES (
    :firmware_slug, :channel, :version_name, :version_ref, :version_stamp,
    :source_commit, :build_method, :sdk_url, :is_latest, 1, NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    version_name = VALUES(version_name),
    version_stamp = VALUES(version_stamp),
    source_commit = COALESCE(VALUES(source_commit), source_commit),
    build_method = CASE
        WHEN VALUES(sdk_url) IS NOT NULL THEN VALUES(build_method)
        WHEN is_latest = 1 AND VALUES(is_latest) = 0 THEN build_method
        ELSE COALESCE(VALUES(build_method), build_method)
    END,
    sdk_url = COALESCE(VALUES(sdk_url), sdk_url),
    is_latest = GREATEST(is_latest, VALUES(is_latest)),
    active = 1,
    updated_at = NOW()
SQL);

    $statement->execute([
        'firmware_slug' => $firmwareSlug,
        'channel' => $channel,
        'version_name' => $versionName,
        'version_ref' => $versionRef,
        'version_stamp' => $versionStamp,
        'source_commit' => $sourceCommit,
        'build_method' => $buildMethod,
        'sdk_url' => $sdkUrl,
        'is_latest' => $isLatest ? 1 : 0,
    ]);
}

function fzcoSyncDirectoryFirmware(PDO $db, array $source): bool
{
    $url = trim((string) ($source['directory_url'] ?? ''));
    if ($url === '') {
        return false;
    }

    $json = fzcoFirmwareHttpJson($url);
    if ($json === false || !isset($json['channels']) || !is_array($json['channels'])) {
        return false;
    }

    $resetLatest = $db->prepare(
        'UPDATE fzco_firmware_catalog SET is_latest = 0 WHERE firmware_slug = :slug'
    );
    $resetLatest->execute(['slug' => $source['firmware_slug']]);

    foreach ($json['channels'] as $channelData) {
        if (!is_array($channelData)) {
            continue;
        }

        $channel = fzcoNormalizeFirmwareChannel(
            (string) ($channelData['id'] ?? $channelData['name'] ?? '')
        );

        if ($channel === null) {
            continue;
        }

        $versions = $channelData['versions'] ?? null;

        if (!is_array($versions)) {
            continue;
        }

        $first = true;

        foreach ($versions as $versionData) {
            if (!is_array($versionData)) {
                continue;
            }

            $versionName = trim((string) (
                $versionData['version']
                ?? $versionData['name']
                ?? $versionData['id']
                ?? ''
            ));

            if ($versionName === '') {
                continue;
            }

            $versionRef = trim((string) (
                $versionData['branch']
                ?? $versionData['version']
                ?? $versionName
            ));

            $sdkUrl = null;
            foreach (($versionData['files'] ?? []) as $fileInfo) {
                if (
                    is_array($fileInfo)
                    && ($fileInfo['type'] ?? null) === 'sdk_zip'
                    && ($fileInfo['target'] ?? 'f7') === 'f7'
                    && filter_var((string) ($fileInfo['url'] ?? ''), FILTER_VALIDATE_URL)
                ) {
                    $sdkUrl = (string) $fileInfo['url'];
                    break;
                }
            }

            fzcoUpsertFirmwareVersion(
                $db,
                (string) $source['firmware_slug'],
                $channel,
                $versionName,
                $versionRef,
                fzcoFirmwareTimestamp(
                    $versionData['timestamp'] ?? $versionData['date'] ?? 'now'
                ),
                null,
                $first,
                'ufbt',
                $sdkUrl
            );

            $first = false;
        }
    }

    return true;
}

function fzcoFirmwareTagChannel(string $tag): ?string
{
    $tag = trim($tag);

    if ($tag === '') {
        return null;
    }

    if (
        preg_match('/(?:^|[-_.])(rc|beta|preview|candidate)(?:[-_.0-9]|$)/i', $tag)
    ) {
        return 'rc';
    }

    return 'release';
}

function fzcoFirmwareTagAllowed(string $firmwareSlug, string $tag): bool
{
    return match ($firmwareSlug) {
        'official' => (bool) preg_match(
            '/^\d+\.\d+\.\d+(?:[-_.]?(?:rc|beta|preview)[-_.]?\d*)?$/i',
            $tag
        ),
        'momentum' => (bool) preg_match('/^mntm-\d+$/i', $tag),
        'unleashed' => (bool) preg_match('/^unlshd-\d+[a-z]?$/i', $tag),
        default => false,
    };
}

function fzcoSyncGitTagHistory(PDO $db, array $source): bool
{
    $repositoryUrl = trim((string) ($source['repository_url'] ?? ''));
    $firmwareSlug = trim((string) ($source['firmware_slug'] ?? ''));

    if ($repositoryUrl === '' || $firmwareSlug === '') {
        return false;
    }

    $output = [];
    $exitCode = 0;

    exec(
        'git ls-remote --tags --refs '
        . escapeshellarg($repositoryUrl)
        . ' 2>/dev/null',
        $output,
        $exitCode
    );

    if ($exitCode !== 0 || $output === []) {
        return false;
    }

    $found = false;
    $sequence = 0;
    $baseTimestamp = strtotime('2000-01-01 00:00:00');

    foreach ($output as $line) {
        $parts = preg_split('/\s+/', trim($line), 2);
        $sha = strtolower(trim((string) ($parts[0] ?? '')));
        $ref = trim((string) ($parts[1] ?? ''));

        if (
            !preg_match('/^[a-f0-9]{40,64}$/', $sha)
            || !str_starts_with($ref, 'refs/tags/')
        ) {
            continue;
        }

        $tag = substr($ref, strlen('refs/tags/'));

        if (!fzcoFirmwareTagAllowed($firmwareSlug, $tag)) {
            continue;
        }

        $channel = fzcoFirmwareTagChannel($tag);
        if ($channel === null) {
            continue;
        }

        ++$sequence;

        // Git ls-remote does not expose tag dates. The synthetic date is only
        // a deterministic fallback for ordering; a later GitHub API sync will
        // replace it with the real publication date.
        $versionStamp = date(
            'Y-m-d H:i:s',
            $baseTimestamp + $sequence
        );

        fzcoUpsertFirmwareVersion(
            $db,
            $firmwareSlug,
            $channel,
            $tag,
            $tag,
            $versionStamp,
            $sha,
            false,
            'fbt_source'
        );

        $found = true;
    }

    return $found;
}

function fzcoSyncGithubReleaseHistory(
    PDO $db,
    array $source,
    bool $markLatest,
    ?string $devBranch = null
): bool {
    $apiUrl = rtrim((string) ($source['api_url'] ?? ''), '/');
    if ($apiUrl === '') {
        return false;
    }

    $latestPerChannel = [];
    $importedPerChannel = [];
    $versionLimit = fzcoFirmwareVersionLimit();
    $gotRelease = false;

    for ($page = 1; $page <= 10; ++$page) {
        $releases = fzcoFirmwareHttpJson(
            $apiUrl . '/releases?per_page=100&page=' . $page
        );

        if ($releases === false || $releases === []) {
            break;
        }

        foreach ($releases as $release) {
            if (!is_array($release) || ($release['draft'] ?? false)) {
                continue;
            }

            $tag = trim((string) ($release['tag_name'] ?? ''));
            if ($tag === '') {
                continue;
            }

            $channel = ($release['prerelease'] ?? false) ? 'rc' : 'release';

            if (($importedPerChannel[$channel] ?? 0) >= $versionLimit) {
                continue;
            }

            $name = trim((string) ($release['name'] ?? $tag)) ?: $tag;

            $sdkUrl = null;
            foreach (($release['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $assetName = strtolower(trim((string) ($asset['name'] ?? '')));
                $assetUrl = trim((string) ($asset['browser_download_url'] ?? ''));

                if (
                    $assetName !== ''
                    && str_contains($assetName, 'sdk')
                    && str_ends_with($assetName, '.zip')
                    && filter_var($assetUrl, FILTER_VALIDATE_URL)
                ) {
                    $sdkUrl = $assetUrl;
                    break;
                }
            }

            $isLatest = false;
            if ($markLatest && !isset($latestPerChannel[$channel])) {
                $isLatest = true;
                $latestPerChannel[$channel] = true;
            }

            fzcoUpsertFirmwareVersion(
                $db,
                (string) $source['firmware_slug'],
                $channel,
                $name,
                $tag,
                fzcoFirmwareTimestamp(
                    $release['published_at']
                    ?? $release['created_at']
                    ?? 'now'
                ),
                null,
                $isLatest,
                $sdkUrl !== null ? 'ufbt' : 'fbt_source',
                $sdkUrl
            );

            $importedPerChannel[$channel] =
                ($importedPerChannel[$channel] ?? 0) + 1;
            $gotRelease = true;
        }

        if (
            (($importedPerChannel['release'] ?? 0) >= $versionLimit
                && ($importedPerChannel['rc'] ?? 0) >= $versionLimit)
            || count($releases) < 100
        ) {
            break;
        }
    }

    $gotDev = false;

    if ($devBranch !== null && $devBranch !== '') {
        $branch = fzcoFirmwareHttpJson(
            $apiUrl . '/branches/' . rawurlencode($devBranch)
        );

        if (is_array($branch)) {
            $sha = strtolower(trim((string) ($branch['commit']['sha'] ?? '')));

            if (preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
                $date = (string) (
                    $branch['commit']['commit']['committer']['date']
                    ?? $branch['commit']['commit']['author']['date']
                    ?? 'now'
                );

                fzcoUpsertFirmwareVersion(
                    $db,
                    (string) $source['firmware_slug'],
                    'dev',
                    $devBranch . ' · ' . substr($sha, 0, 8),
                    $devBranch,
                    fzcoFirmwareTimestamp($date),
                    $sha,
                    true,
                    'fbt_source'
                );

                $gotDev = true;
            }
        }
    }

    return $gotRelease || $gotDev;
}

function fzcoSyncFirmwareCatalog(PDO $db): array
{
    fzcoEnsureFirmwareCatalogSchema($db);

    $query = $db->query(
        'SELECT * FROM fzco_firmware_source
         WHERE active = 1
         ORDER BY sort_order, firmware_name'
    );

    $result = [];

    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $source) {
        try {
            if ($source['source_type'] === 'hybrid') {
                $directorySuccess = fzcoSyncDirectoryFirmware($db, $source);

                $historySuccess = fzcoSyncGithubReleaseHistory(
                    $db,
                    $source,
                    !$directorySuccess
                );

                // Fall back to Git tags only when the GitHub Releases API
                // cannot provide history. Normal sync stays deliberately small.
                $tagSuccess = $historySuccess
                    ? false
                    : fzcoSyncGitTagHistory($db, $source);

                $success =
                    $directorySuccess
                    || $historySuccess
                    || $tagSuccess;
            } elseif ($source['source_type'] === 'github_releases') {
                $success = fzcoSyncGithubReleaseHistory(
                    $db,
                    $source,
                    true,
                    $source['firmware_slug'] === 'roguemaster' ? '420' : null
                );
            } else {
                $success = false;
            }
        } catch (Throwable $e) {
            $success = false;
            error_log(sprintf(
                '[FZOC firmware sync] %s: %s',
                $source['firmware_slug'],
                $e->getMessage()
            ));
        }

        $existingQuery = $db->prepare(
            'SELECT COUNT(*)
             FROM fzco_firmware_catalog
             WHERE firmware_slug = :slug
               AND active = 1'
        );
        $existingQuery->execute([
            'slug' => $source['firmware_slug'],
        ]);

        if ((int) $existingQuery->fetchColumn() > 0) {
            fzcoLimitFirmwareVersions(
                $db,
                (string) $source['firmware_slug']
            );
        }

        if ($success) {
            $update = $db->prepare(
                'UPDATE fzco_firmware_source
                 SET last_synced_at = NOW()
                 WHERE firmware_slug = :slug'
            );
            $update->execute(['slug' => $source['firmware_slug']]);
        }

        $result[$source['firmware_slug']] = $success;
    }

    return $result;
}

function fzcoNormalizeLatestFirmwareFlags(
    PDO $db,
    string $firmwareSlug
): void {
    $channelsQuery = $db->prepare(
        'SELECT DISTINCT channel
         FROM fzco_firmware_catalog
         WHERE firmware_slug = :slug
           AND active = 1'
    );
    $channelsQuery->execute(['slug' => $firmwareSlug]);

    $reset = $db->prepare(
        'UPDATE fzco_firmware_catalog
         SET is_latest = 0
         WHERE firmware_slug = :slug
           AND channel = :channel'
    );

    $latest = $db->prepare(
        'SELECT catalog_id
         FROM fzco_firmware_catalog
         WHERE firmware_slug = :slug
           AND channel = :channel
           AND active = 1
         ORDER BY version_stamp DESC, catalog_id DESC
         LIMIT 1'
    );

    $mark = $db->prepare(
        'UPDATE fzco_firmware_catalog
         SET is_latest = 1
         WHERE catalog_id = :catalog_id'
    );

    foreach ($channelsQuery->fetchAll(PDO::FETCH_COLUMN) as $channel) {
        $channel = (string) $channel;

        $reset->execute([
            'slug' => $firmwareSlug,
            'channel' => $channel,
        ]);

        $latest->execute([
            'slug' => $firmwareSlug,
            'channel' => $channel,
        ]);

        $catalogId = $latest->fetchColumn();

        if ($catalogId !== false) {
            $mark->execute(['catalog_id' => (int) $catalogId]);
        }
    }
}

function fzcoMaybeSyncFirmwareCatalog(
    PDO $db,
    bool $force = false,
    int $maxAge = 3600
): void {
    fzcoEnsureFirmwareCatalogSchema($db);

    $catalogCount = (int) $db->query(
        'SELECT COUNT(*) FROM fzco_firmware_catalog WHERE active = 1'
    )->fetchColumn();

    $cutoff = date('Y-m-d H:i:s', time() - $maxAge);
    $staleQuery = $db->prepare(
        'SELECT COUNT(*)
         FROM fzco_firmware_source
         WHERE active = 1
           AND (last_synced_at IS NULL OR last_synced_at < :cutoff)'
    );
    $staleQuery->execute(['cutoff' => $cutoff]);
    $staleSourceCount = (int) $staleQuery->fetchColumn();

    if (!$force && $catalogCount > 0 && $staleSourceCount === 0) {
        return;
    }

    $lock = (int) $db->query(
        "SELECT GET_LOCK('fzoc_firmware_catalog_sync', 3)"
    )->fetchColumn();

    if ($lock !== 1) {
        return;
    }

    try {
        fzcoSyncFirmwareCatalog($db);
    } finally {
        $db->query("SELECT RELEASE_LOCK('fzoc_firmware_catalog_sync')");
    }
}
