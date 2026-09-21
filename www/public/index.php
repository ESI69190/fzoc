<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config_example.php';
} else {
    require_once __DIR__ . '/../config.php';
}

$firmwares = [];

try {
    $query = $bdd_connexion->query('
        SELECT
            f.firmware_id,
            f.firmware_name,
            fv.firmware_version_name
        FROM fzco_firmware f
        INNER JOIN fzco_depend d
            ON d.depend_firmware_id = f.firmware_id
        INNER JOIN fzco_firmware_version fv
            ON fv.firmware_version_id = d.depend_firmware_version_id
        WHERE f.firmware_is_active = 1
          AND fv.firmware_version_is_active = 1
          AND fv.firmware_version_type = "release"
        ORDER BY f.firmware_name
    ');

    $seen = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['firmware_id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $firmwares[] = $row;
    }
} catch (Throwable $e) {
    if ($debug) {
        $pageError = $e->getMessage();
    } else {
        $pageError = 'La base FZOC est temporairement indisponible.';
    }
}

$turnstileEnabled = $is_active_cloudflare_turnstile;
?>
<!DOCTYPE html>
<html lang="fr">
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
    <a class="esi-brand" href="https://fzoc.eyeshield-informatique.tech/">
        <img class="esi-logo"
             src="https://eyeshield-informatique.tech/images/s.png"
             alt="Eyeshield Informatique">
        <span class="esi-brand-text">
            <strong>EYESHIELD INFORMATIQUE</strong>
        </span>
    </a>

    <nav class="esi-nav" aria-label="Navigation principale">
        <a class="active" href="/">FZOC</a>
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
                <p>
                    Compilez une application Flipper Zero depuis un dépôt GitHub ou GitLab.
                    FZOC détecte la branche par défaut et suit le build sans rechargement de page.
                </p>
            </div>
        </div>
    </section>

    <?php if (isset($pageError)): ?>
        <div class="remark alert">
            <?= htmlspecialchars($pageError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <section class="grid fz-dashboard">
        <div class="row">
            <div class="cell-12">
                <div class="card fz-card">
                    <div class="card-header">
                        <div class="fz-card-title">
                            <span class="mif-hammer icon"></span>
                            Nouvelle compilation
                        </div>
                    </div>

                    <div class="card-content p-4">
                        <form id="compile-form" autocomplete="off">

                            <label for="git_url">Dépôt Git</label>
                            <input
                                id="git_url"
                                name="git_url"
                                type="url"
                                data-role="input"
                                data-prepend="<span class='mif-git'></span>"
                                placeholder="https://github.com/user/application.git"
                                required
                            >

                            <div class="row mt-4">
                                <div class="cell-md-7">
                                    <label for="firmware_target">Firmware cible</label>
                                    <select
                                        id="firmware_target"
                                        name="firmware_target"
                                        class="esi-select"
                                        required
                                    >
                                        <?php foreach ($firmwares as $firmware): ?>
                                            <option value="<?= (int) $firmware['firmware_id'] ?>">
                                                <?= htmlspecialchars(
                                                    $firmware['firmware_name'] . ' · ' . $firmware['firmware_version_name'],
                                                    ENT_QUOTES | ENT_SUBSTITUTE,
                                                    'UTF-8'
                                                ) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="cell-md-5">
                                    <label for="git_branch">Canal SDK</label>
                                    <select
                                        id="git_branch"
                                        name="git_branch"
                                        class="esi-select"
                                    >
                                        <option value="1">Release</option>
                                        <option value="2">Dev</option>
                                    </select>
                                </div>
                            </div>

                            <?php if ($turnstileEnabled): ?>
                                <div class="mt-4 cf-turnstile"
                                     data-sitekey="<?= htmlspecialchars(
                                         $cloudflare_turnstile_sitekey,
                                         ENT_QUOTES | ENT_SUBSTITUTE,
                                         'UTF-8'
                                     ) ?>"></div>
                            <?php endif; ?>

                            <div id="form-message" class="fz-message mt-4" hidden></div>

                            <div class="d-flex flex-justify-between flex-align-center mt-4">
                                <div class="text-muted">
                                    <span class="mif-info icon mr-1"></span>
                                    application.fam doit être présent à la racine du dépôt.
                                </div>

                                <button id="compile-button"
                                        class="button primary large"
                                        type="submit">
                                    <span class="mif-play ani-hover-horizontal icon mr-1"></span>
                                    Compiler
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
            <div class="fz-stat-label">Compilations totales</div>
        </div>

        <div class="fz-stat">
            <div id="stat-month" class="fz-stat-value">0</div>
            <div class="fz-stat-label">Ce mois-ci</div>
        </div>

        <div class="fz-stat">
            <div id="stat-success" class="fz-stat-value">0</div>
            <div class="fz-stat-label">Réussies</div>
        </div>
    </section>

    <section class="card fz-card mt-5 mb-6">
        <div class="card-header">
            <div class="fz-card-title">
                <span class="mif-history icon"></span>
                Compilations récentes
            </div>
        </div>

        <div class="card-content p-0">
            <div class="table-container">
                <table class="table striped compact row-hover fz-table">
                    <thead>
                    <tr>
                        <th>Application</th>
                        <th>Date</th>
                        <th>Statut</th>
                        <th>Firmware</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody id="recent-body">
                    <tr>
                        <td colspan="5" class="text-center p-4">
                            Chargement…
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
            <span>FZOC · Flipper Zero Online Compiler</span>
        </div>
        <div class="fz-footer-links">
            <a href="mailto:contact@eyeshield-informatique.tech">contact@eyeshield-informatique.tech</a>
            <a href="https://eyeshield-informatique.tech">Site principal</a>
            <a href="https://github.com/ESI69190/fzoc"
               target="_blank"
               rel="noopener noreferrer">GitHub</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/gh/olton/Metro-UI-CSS-4@4.5.12/build/metro.js"></script>

<script src="/assets/js/fzoc.js"></script>


</body>
</html>
