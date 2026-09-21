<?php

// Database configuration
$bdd_username = getenv('BDD_USERNAME') ?: 'fzco';
$bdd_password = getenv('BDD_PASSWORD') ?: 'fzco';
$bdd_name     = getenv('BDD_NAME') ?: 'fzco';
$bdd_host     = getenv('BDD_HOST') ?: 'db_fzoc';
$bdd_port     = (int) (getenv('BDD_PORT') ?: 3306);

// Debug
$debug = filter_var(getenv('DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);

// uFBT configuration
$path_to_ufbt = getenv('UFBT_PATH') ?: __DIR__ . '/ufbt/';

// Runtime paths
$task_list         = __DIR__ . '/tasks/';
$path_task_updates = __DIR__ . '/tasks_update/';
$fap_path          = __DIR__ . '/public/faps/';

// Cloudflare Turnstile
$is_active_cloudflare_turnstile = filter_var(
    getenv('IS_ACTIVE_CLOUDFLARE_TURNSTILE') ?: 'false',
    FILTER_VALIDATE_BOOLEAN
);
$cloudflare_turnstile_sitekey   = getenv('CLOUDFLARE_TURNSTILE_SITEKEY') ?: 'fake';
$cloudflare_turnstile_secretkey = getenv('CLOUDFLARE_TURNSTILE_SERVERKEY') ?: 'fake';

require_once __DIR__ . '/class/fzcoPDO.class.php';
