<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config_example.php';
} else {
    require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/../class/fzcoFirmwareCatalog.class.php';

fzcoMaybeSyncFirmwareCatalog($bdd_connexion, true, 0);
