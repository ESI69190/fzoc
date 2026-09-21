# FZOC

**Flipper Zero Online Compiler**

Ce dépôt est le fork `ESI69190/fzoc` du projet original
[`inaz0/fzoc`](https://github.com/inaz0/fzoc).

## Évolutions du fork ESI69190

- interface modernisée avec **Metro UI 4** ;
- soumission des compilations en **XHR/fetch**, sans rechargement de page ;
- suivi temps réel de l'état `queued / running / success / error` ;
- affichage des logs de build depuis le navigateur ;
- **worker Docker permanent**, aucun cron hôte nécessaire ;
- détection automatique de la branche par défaut Git (`main`, `master`, etc.) ;
- correction du filtre `BANNED_APPLICATION_WORDS` lorsqu'il est vide ;
- validation plus robuste de `application.fam` ;
- vérification de l'existence réelle du `.fap` avant de déclarer un build réussi ;
- prise en charge propre des permissions des répertoires runtime.

## Installation rapide

```bash
git clone https://github.com/ESI69190/fzoc.git
cd fzoc
cp dotenv.example .env
docker compose up -d --build
```

Pour un reverse proxy situé sur une autre machine :

```ini
EXPOSE_HOST=0.0.0.0
EXPOSE_PORT=8090
```

La documentation complète est dans [`INSTALL.md`](INSTALL.md).

## Architecture

```text
Navigateur
    |
    | fetch / XHR
    v
PHP-FPM API
    |
    | crée un job
    v
www/tasks
    |
    v
fzoc-worker
    |
    +--> uFBT
    +--> logs
    +--> .fap
    |
    v
API de statut
    |
    v
Navigateur
```

## API interne

- `POST /api/compile.php` : crée une compilation ;
- `GET /api/status.php?job=<id>` : suit une compilation ;
- `GET /api/recent.php` : expose les builds récents et les statistiques.

## Projet original

Le projet FZOC a été créé par
[Alexandre Joly / inaz0](https://github.com/inaz0).

Ce fork conserve la licence du projet d'origine. Consultez [`LICENSE`](LICENSE).

## Interface

L'interface utilise
[Metro UI CSS 4](https://github.com/olton/Metro-UI-CSS-4),
chargé depuis une version épinglée `4.5.12`.
