#!/bin/sh
set -u

TASK_INTERVAL="${FZOC_TASK_INTERVAL:-2}"
MAINT_INTERVAL="${FZOC_MAINT_INTERVAL:-3600}"

TASKS="/usr/local/fzoc/www/tasks"
GITS="/usr/local/fzoc/www/gits"
FAPS="/usr/local/fzoc/www/public/faps"

mkdir -p "$TASKS/running" "$TASKS/result" "$GITS" "$FAPS"
chown -R www-data:www-data "$TASKS" "$GITS" "$FAPS" 2>/dev/null || true
chmod -R ug+rwX "$TASKS" "$GITS" "$FAPS" 2>/dev/null || true

echo "[fzoc-worker] started: task_interval=${TASK_INTERVAL}s maintenance_interval=${MAINT_INTERVAL}s"

last_maintenance="$(date +%s)"

while true
do
    php /usr/local/fzoc/www/crons/task_runner.php

    now="$(date +%s)"
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
