<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/../../config.php')) {
    require_once __DIR__ . '/../../config_example.php';
} else {
    require_once __DIR__ . '/../../config.php';
}

require_once __DIR__ . '/../../class/fzcoFirmwareCatalog.class.php';
require_once __DIR__ . '/../../class/fzcoTime.class.php';

fzcoMaybeSyncFirmwareCatalog($bdd_connexion);

$query = $bdd_connexion->query(
    'SELECT
        s.firmware_slug, s.firmware_name, s.sort_order, s.build_method,
        s.last_synced_at, c.catalog_id, c.channel, c.version_name,
        c.version_ref, c.version_stamp, c.is_latest,
        COALESCE(c.build_method, s.build_method) AS selected_build_method
     FROM fzco_firmware_source s
     INNER JOIN fzco_firmware_catalog c
        ON c.firmware_slug = s.firmware_slug
     WHERE s.active = 1 AND c.active = 1
     ORDER BY
        s.sort_order,
        CASE c.channel
            WHEN "release" THEN 1
            WHEN "rc" THEN 2
            WHEN "dev" THEN 3
            ELSE 9
        END,
        c.version_stamp DESC,
        c.catalog_id DESC'
);

$firmwares = [];

foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $slug = (string) $row['firmware_slug'];
    $channel = (string) $row['channel'];

    if (!isset($firmwares[$slug])) {
        $firmwares[$slug] = [
            'slug' => $slug,
            'name' => $row['firmware_name'],
            'build_method' => $row['build_method'],
            'last_synced_at' => $row['last_synced_at'],
            'channels' => [],
        ];
    }

    if (!isset($firmwares[$slug]['channels'][$channel])) {
        $firmwares[$slug]['channels'][$channel] = [
            'id' => $channel,
            'label' => fzcoFirmwareChannelLabel($channel),
            'versions' => [],
        ];
    }

    $firmwares[$slug]['channels'][$channel]['versions'][] = [
        'id' => (int) $row['catalog_id'],
        'name' => $row['version_name'],
        'ref' => $row['version_ref'],
        'date' => fzcoUtcToDisplayTime((string) $row['version_stamp']),
        'latest' => (bool) $row['is_latest'],
        'build_method' => $row['selected_build_method'],
    ];
}

foreach ($firmwares as &$firmware) {
    $firmware['channels'] = array_values($firmware['channels']);
}
unset($firmware);

echo json_encode([
    'ok' => true,
    'timezone' => fzcoDisplayTimezoneName(),
    'firmwares' => array_values($firmwares),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
