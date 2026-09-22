<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config_example.php';
} else {
    require_once __DIR__ . '/../config.php';
}

require_once __DIR__ . '/../class/fzcoI18n.class.php';

$lang = fzcoResolveLanguage($_GET['lang'] ?? null);
$tr = fzcoTranslations($lang);
$turnstileEnabled = $is_active_cloudflare_turnstile;

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="<?= e($lang) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#4F415D">

    <title>FZOC | Flipper Zero Online Compiler</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/olton/Metro-UI-CSS-4@4.5.12/build/metro.css">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/gh/olton/Metro-UI-CSS-4@4.5.12/build/icons.css">
    <link rel="stylesheet" href="/assets/css/fzoc-metro.css">

    <link rel="apple-touch-icon" sizes="180x180"
          href="https://eyeshield-informatique.tech/favicon/apple-touch-icon.png?v=2">
    <link rel="icon" type="image/png" sizes="32x32"
          href="https://eyeshield-informatique.tech/favicon/favicon-32x32.png?v=2">
    <link rel="icon" type="image/png" sizes="16x16"
          href="https://eyeshield-informatique.tech/favicon/favicon-16x16.png?v=2">
    <link rel="icon" type="image/x-icon"
          href="https://eyeshield-informatique.tech/favicon/favicon.ico?v=2">
    <link rel="manifest"
          href="https://eyeshield-informatique.tech/favicon/site.webmanifest">

    <?php if ($turnstileEnabled): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>

<header class="esi-topbar">
    <a class="esi-brand" href="/?lang=<?= e($lang) ?>">
        <img class="esi-logo"
             src="https://eyeshield-informatique.tech/images/s.png"
             alt="Eyeshield Informatique">
        <span class="esi-brand-text">
            <strong>EYESHIELD INFORMATIQUE</strong>
        </span>
    </a>

    <nav class="esi-nav" aria-label="Navigation">
        <a class="active" href="/?lang=<?= e($lang) ?>">FZOC</a>

        <span class="esi-lang-switch" aria-label="Language">
            <a href="/?lang=fr"
               lang="fr"
               hreflang="fr"
               class="<?= $lang === 'fr' ? 'current' : '' ?>">FR</a>
            <span aria-hidden="true">|</span>
            <a href="/?lang=en"
               lang="en"
               hreflang="en"
               class="<?= $lang === 'en' ? 'current' : '' ?>">EN</a>
        </span>

        <a href="https://github.com/ESI69190/fzoc"
           target="_blank"
           rel="noopener noreferrer">
            <span class="mif-github icon mr-1"></span>
            GitHub
        </a>
    </nav>
</header>

<main class="container-fluid fz-shell">

    <section class="fz-hero">
        <div class="fz-hero-brand">
            <img class="esi-logo esi-logo-large"
                 src="https://eyeshield-informatique.tech/images/s.png"
                 alt="">
            <div>
                <div class="fz-kicker">EYESHIELD INFORMATIQUE</div>
                <h2>Flipper Zero Online Compiler</h2>
                <p><?= e((string) $tr['hero_description']) ?></p>
            </div>
        </div>
    </section>

    <?php if (isset($pageError)): ?>
        <div class="remark alert">
            <?= e((string) $pageError) ?>
        </div>
    <?php endif; ?>

    <section class="grid fz-dashboard">
        <div class="row">
            <div class="cell-12">
                <div class="card fz-card">
                    <div class="card-header">
                        <div class="fz-card-title">
                            <span class="mif-hammer icon"></span>
                            <?= e((string) $tr['new_compilation']) ?>
                        </div>
                    </div>

                    <div class="card-content p-4">
                        <form id="compile-form" autocomplete="off">

                            <label for="git_url"><?= e((string) $tr['git_repository']) ?></label>
                            <input
                                id="git_url"
                                name="git_url"
                                type="text"
                                inputmode="url"
                                autocapitalize="none"
                                autocorrect="off"
                                spellcheck="false"
                                data-role="input"
                                data-prepend="<span class='mif-git'></span>"
                                placeholder="<?= e((string) $tr['git_placeholder']) ?>"
                                required
                            >

                            <div class="row mt-4">
                                <div class="cell-md-4">
                                    <label for="firmware_slug"><?= e((string) $tr['firmware']) ?></label>
                                    <select
                                        id="firmware_slug"
                                        name="firmware_slug"
                                        class="esi-select"
                                        required
                                    >
                                        <option value=""><?= e((string) $tr['loading']) ?></option>
                                    </select>
                                </div>

                                <div class="cell-md-4">
                                    <label for="firmware_channel"><?= e((string) $tr['channel']) ?></label>
                                    <select
                                        id="firmware_channel"
                                        name="firmware_channel"
                                        class="esi-select"
                                        required
                                        disabled
                                    >
                                        <option value="">—</option>
                                    </select>
                                </div>

                                <div class="cell-md-4">
                                    <label for="firmware_version"><?= e((string) $tr['version']) ?></label>
                                    <select
                                        id="firmware_version"
                                        name="firmware_version"
                                        class="esi-select"
                                        required
                                        disabled
                                    >
                                        <option value="">—</option>
                                    </select>
                                </div>
                            </div>

                            <?php if ($turnstileEnabled): ?>
                                <div class="mt-4 cf-turnstile"
                                     data-sitekey="<?= e((string) $cloudflare_turnstile_sitekey) ?>"></div>
                            <?php endif; ?>

                            <div id="form-message" class="fz-message mt-4" hidden></div>

                            <div class="fz-retention-note">
                                <span class="mif-shield icon mr-1"></span>
                                <?= e((string) $tr['retention_notice']) ?>
                            </div>

                            <div class="d-flex flex-justify-between flex-align-center mt-4">
                                <div class="text-muted">
                                    <span class="mif-info icon mr-1"></span>
                                    <?= e((string) $tr['fam_hint']) ?>
                                </div>

                                <button id="compile-button"
                                        class="button primary large shadowed"
                                        type="submit">
                                    <span class="mif-play ani-hover-horizontal icon mr-1"></span>
                                    <?= e((string) $tr['compile']) ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="fz-stats">
        <div class="fz-stat">
            <div id="stat-total" class="fz-stat-value">0</div>
            <div class="fz-stat-label"><?= e((string) $tr['stats_total']) ?></div>
        </div>

        <div class="fz-stat">
            <div id="stat-month" class="fz-stat-value">0</div>
            <div class="fz-stat-label"><?= e((string) $tr['stats_month']) ?></div>
        </div>

        <div class="fz-stat">
            <div id="stat-success" class="fz-stat-value">0</div>
            <div class="fz-stat-label"><?= e((string) $tr['stats_success']) ?></div>
        </div>
    </section>

    <section class="card fz-card mt-5 mb-6">
        <div class="card-header">
            <div class="fz-card-title">
                <span class="mif-history icon"></span>
                <?= e((string) $tr['recent_compilations']) ?>
            </div>
        </div>

        <div class="fz-status-legend" aria-label="<?= e((string) $tr['legend_title']) ?>">
            <span class="fz-status-legend-title">
                <span class="mif-info icon"></span>
                <?= e((string) $tr['legend_title']) ?>
            </span>

            <span class="fz-status-legend-item">
                <span class="button yellow shadowed fz-status-button fz-status-legend-button"><?= e((string) $tr['legend_queued']) ?></span>
                <span><?= e((string) $tr['legend_queued_help']) ?></span>
            </span>

            <span class="fz-status-legend-item">
                <span class="button warning shadowed fz-status-button fz-status-legend-button"><?= e((string) $tr['legend_running']) ?></span>
                <span><?= e((string) $tr['legend_running_help']) ?></span>
            </span>

            <span class="fz-status-legend-item">
                <span class="button success shadowed fz-status-button fz-status-legend-button"><?= e((string) $tr['legend_success']) ?></span>
                <span><?= e((string) $tr['legend_success_help']) ?></span>
            </span>

            <span class="fz-status-legend-item">
                <span class="button alert shadowed fz-status-button fz-status-legend-button"><?= e((string) $tr['legend_error']) ?></span>
                <span><?= e((string) $tr['legend_error_help']) ?></span>
            </span>
        </div>

        <div class="card-content p-0">
            <div class="table-container">
                <table class="table striped compact row-hover fz-table">
                    <thead>
                    <tr>
                        <th><?= e((string) $tr['table_application']) ?></th>
                        <th><?= e((string) $tr['table_date']) ?></th>
                        <th><?= e((string) $tr['table_status']) ?></th>
                        <th><?= e((string) $tr['table_firmware']) ?></th>
                        <th><?= e((string) $tr['table_action']) ?></th>
                    </tr>
                    </thead>
                    <tbody id="recent-body">
                    <tr>
                        <td colspan="5" class="text-center p-4">
                            <?= e((string) $tr['loading']) ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</main>

<footer class="fz-footer">
    <div class="fz-footer-inner">
        <div>
            <strong>EYESHIELD INFORMATIQUE</strong><br>
            <span><?= e((string) $tr['footer_product']) ?></span>
        </div>
        <div class="fz-footer-links">
            <a href="mailto:contact@eyeshield-informatique.tech">contact@eyeshield-informatique.tech</a>
            <a href="https://eyeshield-informatique.tech"><?= e((string) $tr['main_site']) ?></a>
            <a href="https://github.com/ESI69190/fzoc"
               target="_blank"
               rel="noopener noreferrer">GitHub</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/gh/olton/Metro-UI-CSS-4@4.5.12/build/metro.js"></script>
<script>
window.FZOC_LANG = <?= json_encode(
    $lang,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
window.FZOC_I18N = <?= json_encode(
    $tr['js'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
) ?>;
</script>
<script src="/assets/js/fzoc.js"></script>

</body>
</html>
