(() => {
    'use strict';

    const lang = window.FZOC_LANG === 'en' ? 'en' : 'fr';
    const i18n = window.FZOC_I18N || {};

    function tr(key, replacements = {}) {
        let value = typeof i18n[key] === 'string' ? i18n[key] : key;

        for (const [name, replacement] of Object.entries(replacements)) {
            value = value.replaceAll('{' + name + '}', String(replacement));
        }

        return value;
    }

    function serverError(errorCode, fallback = '') {
        const messages = i18n.server_errors || {};
        return (errorCode && messages[errorCode]) || fallback;
    }

    function channelLabel(channel, fallback = '') {
        const key = {
            release: 'channel_release',
            rc: 'channel_rc',
            dev: 'channel_dev'
        }[channel];

        return key ? tr(key) : (fallback || channel);
    }

    function formatDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/.exec(value || '');

        if (!match) {
            return value || '';
        }

        const [, year, month, day, hour, minute, second] = match;

        return lang === 'fr'
            ? day + '/' + month + '/' + year + ' ' + hour + ':' + minute + ':' + second
            : year + '/' + month + '/' + day + ' ' + hour + ':' + minute + ':' + second;
    }

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
                    ? tr('invalid_server_response') + clean.slice(0, 350)
                    : tr('empty_server_response') + ' (HTTP ' + response.status + ').'
            );
        }
    }

    const form = document.getElementById('compile-form');
    const button = document.getElementById('compile-button');
    const message = document.getElementById('form-message');
    const firmwareSelect = document.getElementById('firmware_slug');
    const channelSelect = document.getElementById('firmware_channel');
    const versionSelect = document.getElementById('firmware_version');

    let recentTimer = null;
    let firmwareCatalog = [];

    function setMessage(text, type = 'error') {
        message.hidden = !text;
        message.textContent = text || '';
        message.className = 'fz-message mt-4 ' + (type === 'success' ? 'success' : 'error');
    }

    function setButtonBusy(busy) {
        button.disabled = busy;
        button.innerHTML = busy
            ? '<span class="mif-spinner4 ani-spin icon mr-1"></span> ' + tr('preparing')
            : '<span class="mif-play ani-hover-horizontal icon mr-1"></span> ' + tr('compile');
    }

    function statusLabel(status) {
        const labels = {
            queued: tr('status_queued'),
            pending: tr('status_queued'),
            running: tr('status_running'),
            success: tr('status_success'),
            error: tr('status_error'),
            impossible: tr('status_error'),
            deleted: tr('status_deleted')
        };

        return labels[status] || status;
    }

    function cell(text) {
        const td = document.createElement('td');
        td.textContent = text ?? '';
        return td;
    }

    function fillSelect(select, options, selectedValue = null) {
        select.replaceChildren();

        for (const optionData of options) {
            const option = document.createElement('option');
            option.value = String(optionData.value);
            option.textContent = optionData.label;

            if (selectedValue !== null && String(optionData.value) === String(selectedValue)) {
                option.selected = true;
            }

            select.appendChild(option);
        }

        select.disabled = options.length === 0;
    }

    function selectedFirmware() {
        return firmwareCatalog.find(
            firmware => firmware.slug === firmwareSelect.value
        ) || null;
    }

    function selectedChannel() {
        const firmware = selectedFirmware();

        if (!firmware) {
            return null;
        }

        return firmware.channels.find(
            channel => channel.id === channelSelect.value
        ) || null;
    }

    function refreshVersions() {
        const channel = selectedChannel();

        if (!channel) {
            fillSelect(versionSelect, []);
            return;
        }

        const latest = channel.versions.find(version => version.latest);

        fillSelect(
            versionSelect,
            channel.versions.map(version => ({
                value: version.id,
                label: (version.latest ? tr('latest_prefix') : '') + version.name
            })),
            latest ? latest.id : channel.versions[0]?.id
        );
    }

    function refreshChannels() {
        const firmware = selectedFirmware();

        if (!firmware) {
            fillSelect(channelSelect, []);
            fillSelect(versionSelect, []);
            return;
        }

        const preferred =
            firmware.channels.find(channel => channel.id === 'release') ||
            firmware.channels[0];

        fillSelect(
            channelSelect,
            firmware.channels.map(channel => ({
                value: channel.id,
                label: channelLabel(channel.id, channel.label)
            })),
            preferred?.id
        );

        refreshVersions();
    }

    async function loadFirmwareCatalog() {
        button.disabled = true;
        firmwareSelect.disabled = true;
        channelSelect.disabled = true;
        versionSelect.disabled = true;

        const response = await fetch('/api/firmwares.php', {cache: 'no-store'});
        const data = await readJsonResponse(response);

        if (!response.ok || !data.ok || !Array.isArray(data.firmwares)) {
            throw new Error(
                data.message ||
                tr('firmware_loading_error')
            );
        }

        firmwareCatalog = data.firmwares;

        fillSelect(
            firmwareSelect,
            firmwareCatalog.map(firmware => ({
                value: firmware.slug,
                label: firmware.name
            }))
        );

        refreshChannels();
        button.disabled = firmwareCatalog.length === 0;
    }

    function buildAction(item) {
        const action = document.createElement('td');
        action.className = 'fz-table-action';

        if (item.status === 'success' && item.download) {
            const link = document.createElement('a');
            link.className = 'button success shadowed fz-fap-button';
            link.href = item.download;
            link.innerHTML = '<span class="mif-download ani-hover-horizontal"> ' + tr('fap') + '</span>';
            action.appendChild(link);
        }

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
                const td = cell(tr('no_compilations'));
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
                    repo.className = 'button primary outline small shadowed fz-repo-button';
                    repo.title = item.application.repository;

                    const repoIcon = document.createElement('span');
                    repoIcon.className = 'mif-git ani-hover-horizontal icon';

                    const repoLabel = document.createElement('span');
                    repoLabel.textContent = item.application.name;

                    repo.appendChild(repoIcon);
                    repo.appendChild(repoLabel);
                    app.appendChild(repo);

                    tr.appendChild(app);
                    tr.appendChild(cell(formatDate(item.date)));

                    const status = document.createElement('td');
                    const badge = document.createElement('button');
                    badge.type = 'button';
                    badge.disabled = true;
                    badge.className = 'button shadowed fz-status-button';

                    if (item.status === 'success') {
                        badge.classList.add('success');
                    } else if (['error', 'impossible', 'deleted'].includes(item.status)) {
                        badge.classList.add('alert');
                    } else if (['queued', 'pending'].includes(item.status)) {
                        badge.classList.add('yellow');
                    } else if (item.status === 'running') {
                        badge.classList.add('warning');
                    } else {
                        badge.classList.add('secondary');
                    }

                    badge.textContent = statusLabel(item.status);

                    if (item.request_count > 1) {
                        badge.title = tr('grouped_requests', {count: item.request_count});
                    }

                    status.appendChild(badge);
                    tr.appendChild(status);

                    tr.appendChild(cell(
                        item.firmware.name + ' · ' +
                        item.firmware.version + ' · ' +
                        channelLabel(item.firmware.channel, item.firmware.channel)
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

    firmwareSelect.addEventListener('change', refreshChannels);
    channelSelect.addEventListener('change', refreshVersions);

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
                    serverError(
                        data.error,
                        data.message || tr('launch_error')
                    )
                );
            }

            let successMessage;

            if (data.cache_hit) {
                successMessage = tr('cache_hit', {
                    app: data.application.name
                });
            } else if (data.deduplicated) {
                successMessage = tr(
                    data.status === 'running'
                        ? 'deduplicated_running'
                        : 'deduplicated_queued',
                    {app: data.application.name}
                );
            } else {
                successMessage = tr(
                    data.queue_position ? 'queued_position' : 'queued',
                    {
                        app: data.application.name,
                        position: data.queue_position || ''
                    }
                );
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

    loadFirmwareCatalog().catch(error => {
        setMessage(error.message);
        button.disabled = true;
    });

    loadRecent();
})();
