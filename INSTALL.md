# Installation

## Prérequis Docker

- Docker Engine
- Docker Compose v2
- Git
- Un reverse proxy est recommandé pour HTTPS

## Installation Docker

Clonez le dépôt puis créez votre configuration :

```bash
git clone https://github.com/ESI69190/fzoc.git
cd fzoc
cp dotenv.example .env
```

Modifiez au minimum :

```ini
BDD_PASSWORD=un_mot_de_passe_solide
```

### Exposition réseau

Par défaut, FZOC écoute uniquement sur localhost :

```ini
EXPOSE_HOST=127.0.0.1
EXPOSE_PORT=8090
```

Si votre reverse proxy est sur une autre machine, utilisez :

```ini
EXPOSE_HOST=0.0.0.0
EXPOSE_PORT=8090
```

et limitez l'accès au port 8090 avec le firewall au seul reverse proxy.

### Lancement

```bash
docker compose up -d --build
```

Vérification :

```bash
docker compose ps
docker compose logs -f http fpm worker
```

L'interface est disponible sur :

```text
http://127.0.0.1:8090
```

ou sur l'adresse correspondant à `EXPOSE_HOST`.

## Aucun cron requis en Docker

Le fork `ESI69190/fzoc` utilise un service Docker `worker` permanent.

Il assure :

- la consommation des tâches de compilation ;
- la mise à jour périodique des SDK uFBT ;
- le nettoyage des anciens builds ;
- la conservation temporaire des logs de compilation.

Les intervalles sont configurables dans `.env` :

```ini
FZOC_TASK_INTERVAL=2
FZOC_MAINT_INTERVAL=3600
```

Il ne faut donc pas ajouter les anciens crons de l'upstream.

## Permissions runtime

Le worker initialise automatiquement les répertoires suivants :

```text
www/gits
www/tasks
www/tasks/running
www/tasks/result
www/public/faps
```

et applique les droits nécessaires à PHP-FPM.

## Reverse proxy

Le backend HTTP de FZOC reste en HTTP sur le port configuré par `EXPOSE_PORT`.
Le TLS doit de préférence être terminé par Apache, Nginx, Traefik ou un autre reverse proxy.

Exemple de cible :

```text
http://IP_DOCKER_FZOC:8090
```

## Mise à jour

```bash
git pull origin main
docker compose up -d --build
```

Consultez ensuite :

```bash
docker compose logs --tail=100 worker
```

## Installation sans Docker

L'installation sans Docker reste possible avec PHP 8.x, MariaDB/MySQL, Git et uFBT,
mais le worker Docker et l'initialisation automatique des permissions ne s'appliquent pas.
Dans ce mode, `www/worker_loop.sh` peut servir de référence pour mettre en place un service
systemd permanent.
