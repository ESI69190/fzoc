(() => {
    'use strict';

    async function readJsonResponse(response) {
        const text = await response.text();

        try {
            return JSON.parse(text);
        } catch (error) {
            const clean = text
                .replace(/<br\s*\/?>/gi, ' ')
                .replace(/<[^>]+>/g, '')
                .replace(/\s+/g, ' ')
                .trim();

            throw new Error(
                clean
                    ? 'Réponse serveur invalide : ' + clean.slice(0, 350)
                    : 'Réponse serveur vide ou invalide (HTTP ' + response.status + ').'
            );
        }
    }

    const form = document.getElementById('compile-form');
    const button = document.getElementById('compile-button');
    const message = document.getElementById('form-message');

    let recentTimer = null;

    function setMessage(text, type = 'error') {
        message.hidden = !text;
        message.textContent = text || '';
        message.className = 'fz-message mt-4 ' + (type === 'success' ? 'success' : 'error');
    }

    function setButtonBusy(busy) {
        button.disabled = busy;
        button.innerHTML = busy
            ? '<span class="mif-spinner4 ani-spin icon mr-1"></span> Préparation…'
            : '<span class="mif-play ani-hover-horizontal icon mr-1"></span> Compiler';
    }

    function statusLabel(status) {
        const labels = {
            queued: 'en file',
            pending: 'en file',
            running: 'en cours',
            success: 'compilé',
            error: 'erreur',
            impossible: 'erreur',
            deleted: 'supprimé'
        };

        return labels[status] || status;
    }

    function cell(text) {
        const td = document.createElement('td');
        td.textContent = text ?? '';
        return td;
    }

    function buildAction(item) {
        const action = document.createElement('td');
        action.className = 'fz-table-action';

        if (item.download) {
            const link = document.createElement('a');
            link.className = 'button success fz-fap-button';
            link.href = item.download;
            link.innerHTML = '<span class="mif-download ani-hover-horizontal"> FAP</span>';
            action.appendChild(link);
            return action;
        }

        if (item.status === 'queued' || item.status === 'pending') {
            const queued = document.createElement('span');
            queued.className = 'button secondary fz-progress-button';

            const position = item.queue_position
                ? 'File #' + item.queue_position
                : 'En file';

            queued.innerHTML =
                '<span class="mif-hour-glass icon"></span><span>' + position + '</span>';
            action.appendChild(queued);
            return action;
        }

        if (item.status === 'running') {
            const progress = document.createElement('span');
            progress.className = 'button secondary fz-progress-button';
            progress.innerHTML =
                '<span class="mif-spinner4 ani-spin icon"></span><span>En cours</span>';
            action.appendChild(progress);
            return action;
        }

        if (['error', 'impossible'].includes(item.status)) {
            const failed = document.createElement('span');
            failed.className = 'button alert fz-error-button';
            failed.innerHTML =
                '<span class="mif-cross icon"></span><span>Erreur</span>';
            action.appendChild(failed);
            return action;
        }

        action.textContent = '—';
        return action;
    }

    async function loadRecent() {
        clearTimeout(recentTimer);

        try {
            const response = await fetch('/api/recent.php', {cache: 'no-store'});
            const data = await readJsonResponse(response);

            if (!response.ok || !data.ok) {
                throw new Error('recent_failed');
            }

            document.getElementById('stat-total').textContent = data.stats.total;
            document.getElementById('stat-month').textContent = data.stats.this_month;
            document.getElementById('stat-success').textContent = data.stats.success;

            const tbody = document.getElementById('recent-body');
            tbody.replaceChildren();

            if (!data.items.length) {
                const tr = document.createElement('tr');
                const td = cell('Aucune compilation pour le moment.');
                td.colSpan = 5;
                td.className = 'text-center p-4';
                tr.appendChild(td);
                tbody.appendChild(tr);
            } else {
                for (const item of data.items) {
                    const tr = document.createElement('tr');
                    const app = document.createElement('td');

                    const repo = document.createElement('a');
                    repo.href = item.application.repository;
                    repo.target = '_blank';
                    repo.rel = 'noopener noreferrer';
                    repo.className = 'button primary outline small fz-repo-button';
                    repo.title = item.application.repository;

                    const repoIcon = document.createElement('span');
                    repoIcon.className = 'mif-git ani-hover-horizontal icon';

                    const repoLabel = document.createElement('span');
                    repoLabel.textContent = item.application.name;

                    repo.appendChild(repoIcon);
                    repo.appendChild(repoLabel);
                    app.appendChild(repo);

                    tr.appendChild(app);
                    tr.appendChild(cell(item.date));

                    const status = document.createElement('td');
                    const badge = document.createElement('span');
                    badge.className = 'fz-status status-' + item.status;
                    badge.textContent = statusLabel(item.status);

                    if (item.request_count > 1) {
                        badge.title = item.request_count + ' demandes regroupées';
                    }

                    status.appendChild(badge);
                    tr.appendChild(status);

                    tr.appendChild(cell(
                        item.firmware.name + ' · ' +
                        item.firmware.version + ' · ' +
                        item.firmware.channel
                    ));

                    tr.appendChild(buildAction(item));
                    tbody.appendChild(tr);
                }
            }
        } catch (error) {
            // Keep the last known table. The next refresh will retry.
        }

        recentTimer = setTimeout(loadRecent, 2000);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        setMessage('');
        setButtonBusy(true);

        try {
            const response = await fetch('/api/compile.php', {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await readJsonResponse(response);

            if (!response.ok || !data.ok) {
                throw new Error(
                    data.message ||
                    data.error ||
                    'La compilation n’a pas pu être lancée.'
                );
            }

            let successMessage;

            if (data.cache_hit) {
                successMessage =
                    data.application.name +
                    ' est déjà compilé pour cette révision et ce firmware.';
            } else if (data.deduplicated) {
                successMessage =
                    'Une compilation identique de ' +
                    data.application.name +
                    ' est déjà ' +
                    (data.status === 'running' ? 'en cours.' : 'dans la file.');
            } else {
                successMessage =
                    data.application.name +
                    ' ajouté à la file' +
                    (data.queue_position ? ' (position ' + data.queue_position + ')' : '') +
                    '.';
            }

            setMessage(successMessage, 'success');
            await loadRecent();

            if (window.turnstile) {
                window.turnstile.reset();
            }
        } catch (error) {
            setMessage(error.message);
        } finally {
            setButtonBusy(false);
        }
    });

    loadRecent();
})();
