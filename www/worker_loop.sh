#!/bin/sh
set -u

TASK_INTERVAL="${FZOC_TASK_INTERVAL:-2}"
MAINT_INTERVAL="${FZOC_MAINT_INTERVAL:-3600}"
FIRMWARE_SYNC_INTERVAL="${FZOC_FIRMWARE_SYNC_INTERVAL:-3600}"
RETENTION_DAYS="${FZOC_RETENTION_DAYS:-30}"

TASKS="/usr/local/fzoc/www/tasks"
GITS="/usr/local/fzoc/www/gits"
FAPS="/usr/local/fzoc/www/public/faps"
FIRMWARE_CACHE="/usr/local/fzoc/www/firmware-cache"

mkdir -p "$TASKS/running" "$TASKS/result" "$GITS" "$FAPS" "$FIRMWARE_CACHE"
chown -R www-data:www-data "$TASKS" "$GITS" "$FAPS" "$FIRMWARE_CACHE" 2>/dev/null || true
chmod -R ug+rwX "$TASKS" "$GITS" "$FAPS" "$FIRMWARE_CACHE" 2>/dev/null || true

echo "[fzoc-worker] started: task_interval=${TASK_INTERVAL}s maintenance_interval=${MAINT_INTERVAL}s firmware_sync_interval=${FIRMWARE_SYNC_INTERVAL}s retention=${RETENTION_DAYS}d"

php /usr/local/fzoc/www/crons/sync_firmware_catalog.php \
    >> "$TASKS/worker-maintenance.log" 2>&1 || true

php /usr/local/fzoc/www/crons/task_delete.php \
    >> "$TASKS/worker-maintenance.log" 2>&1 || true

last_maintenance="$(date +%s)"
last_firmware_sync="$last_maintenance"

while true
do
    php /usr/local/fzoc/www/crons/task_runner.php

    now="$(date +%s)"

    if [ $((now - last_firmware_sync)) -ge "$FIRMWARE_SYNC_INTERVAL" ]; then
        echo "[fzoc-worker] firmware catalog sync started"

        php /usr/local/fzoc/www/crons/sync_firmware_catalog.php \
            >> "$TASKS/worker-maintenance.log" 2>&1 || true

        last_firmware_sync="$now"
        echo "[fzoc-worker] firmware catalog sync completed"
    fi

    if [ $((now - last_maintenance)) -ge "$MAINT_INTERVAL" ]; then
        echo "[fzoc-worker] maintenance started"

        sh /usr/local/fzoc/www/crons/update_ufbt.sh \
            >> "$TASKS/worker-maintenance.log" 2>&1 || true

        php /usr/local/fzoc/www/crons/task_delete.php \
            >> "$TASKS/worker-maintenance.log" 2>&1 || true

        find "$TASKS/result" -type f -name '*.result' -mtime +2 -delete 2>/dev/null || true

        last_maintenance="$now"
        echo "[fzoc-worker] maintenance completed"
    fi

    sleep "$TASK_INTERVAL"
done
