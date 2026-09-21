<?php

declare(strict_types=1);

/**
 * Queue/cache schema for the ESI FZOC evolution.
 *
 * The build key is unique: one exact repository commit + one exact firmware
 * state + one engine version can only have one build job.
 */
function fzcoEnsureBuildQueueSchema(PDO $db): void
{
    static $done = false;

    if ($done) {
        return;
    }

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fzco_build_job (
    build_job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_job_id CHAR(32) NOT NULL,
    build_key CHAR(64) NOT NULL,

    application_name VARCHAR(255) NOT NULL,
    application_appid VARCHAR(255) NOT NULL,
    application_url_git TEXT NOT NULL,

    repository_branch VARCHAR(255) NOT NULL,
    repository_commit VARCHAR(64) NOT NULL,

    firmware_id INT NOT NULL,
    firmware_name VARCHAR(255) NOT NULL,
    firmware_version_id INT NOT NULL,
    firmware_version_name VARCHAR(255) NOT NULL,
    firmware_version_stamp DATETIME NOT NULL,
    sdk_channel ENUM('release','dev') NOT NULL,

    engine_version VARCHAR(64) NOT NULL,
    build_path VARCHAR(255) NOT NULL,
    build_status ENUM('queued','running','success','error','impossible','deleted') NOT NULL DEFAULT 'queued',

    priority INT NOT NULL DEFAULT 0,
    request_count INT UNSIGNED NOT NULL DEFAULT 1,

    created_at DATETIME NOT NULL,
    queued_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_requested_at DATETIME NOT NULL,
    error_code VARCHAR(64) NULL,

    PRIMARY KEY (build_job_id),
    UNIQUE KEY uq_fzco_build_job_public (public_job_id),
    UNIQUE KEY uq_fzco_build_job_key (build_key),
    KEY idx_fzco_build_queue (build_status, priority, request_count, queued_at),
    KEY idx_fzco_build_recent (last_requested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS fzco_build_request (
    request_id CHAR(32) NOT NULL,
    build_job_id BIGINT UNSIGNED NOT NULL,
    requested_at DATETIME NOT NULL,

    PRIMARY KEY (request_id),
    KEY idx_fzco_build_request_job (build_job_id, requested_at),

    CONSTRAINT fk_fzco_build_request_job
        FOREIGN KEY (build_job_id)
        REFERENCES fzco_build_job(build_job_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $done = true;
}

function fzcoQueuePosition(PDO $db, int $buildJobId): ?int
{
    $query = $db->query(
        "SELECT build_job_id
         FROM fzco_build_job
         WHERE build_status = 'queued'
         ORDER BY priority DESC, request_count DESC, queued_at ASC, build_job_id ASC"
    );

    $position = 0;
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
        ++$position;
        if ((int) $id === $buildJobId) {
            return $position;
        }
    }

    return null;
}
