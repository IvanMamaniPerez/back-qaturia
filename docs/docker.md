# QATUR IA - Docker (Laravel 13, PHP 8.4, Alpine 3.24)

The structure is inspired by ERP SMI, but decouples the HTTP server from queue, scheduler and Reverb. No Octane or Reverb Composer dependencies are added in this branch.

## Requirements

Docker Engine + Compose v2, with a working Docker daemon. The root of this repository is already a Laravel 13 application. This branch intentionally does not change composer.json or composer.lock.

## First local boot (before Octane / Reverb)

1. Copy the sample and generate a real APP_KEY. Also change the database password if needed:

```bash
cp .env.docker.example .env
# Generate a key with a local PHP installation, or from the container after its first build:
# docker compose -f docker-compose.yml -f docker-compose.local.yml run --rm app php artisan key:generate --show
# Paste the full output as APP_KEY=... in .env BEFORE starting the app.
```

2. Validate Compose, build and start:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php -v
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php -m | grep -Ei 'swoole|redis|pdo_pgsql|imagick'
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan migrate --force
curl -f http://localhost:8085/up
```

App: http://localhost:8085 ; PostgreSQL: localhost:5434 ; Redis: localhost:6381 ; Mailpit: http://localhost:8026.

APP_HTTP_SERVER=artisan is used ONLY for local bootstrap. Production refuses to run the Laravel development HTTP server.

Optional React/Vite HMR and background workers:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml --profile frontend up -d vite
docker compose -f docker-compose.yml -f docker-compose.local.yml --profile workers up -d queue scheduler
```

Use `docker compose ... logs -f app` for Nginx and the HTTP process. Vite emits a hot file in public/hot; stop the Vite service and remove this file if you switch back to built frontend assets.

If you already started with an empty vendor/node_modules volume and change dependencies, run `composer install` and `npm ci` inside the app container, or recreate those named volumes intentionally (NEVER delete the PostgreSQL volume).

## Install Laravel Octane after Docker works

The image **already includes the PHP 8.4 Swoole extension**. Install the Laravel package after the first boot:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app composer require laravel/octane
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan octane:install --server=swoole
```

Set `APP_HTTP_SERVER=octane` in .env, then recreate the app container to load the new environment:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --force-recreate app
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan octane:status
```

For local Octane code watching, first install `chokidar` as the Octane documentation requires, then set `OCTANE_WATCH=true`. **Never enable watching in production.** For multi-business workloads, clear any per-request BusinessContext so one Octane request cannot leak context into the next.

## Install Laravel Reverb after Octane

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml exec app php artisan install:broadcasting
```

The installation may change .env, composer.json, composer.lock, package.json and Vite dependencies. Review its changes. Set the following Reverb values after installation:

- REVERB_SERVER_HOST=0.0.0.0, REVERB_SERVER_PORT=8080 (listen inside container)
- REVERB_HOST=reverb, REVERB_PORT=8080, REVERB_SCHEME=http (Laravel reaches the Compose service)
- VITE_REVERB_HOST=localhost, VITE_REVERB_PORT=8085, VITE_REVERB_SCHEME=http (browser uses Nginx)
- BROADCAST_CONNECTION=reverb
- Unique REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET; do not use the sample secret in production.
- Restrict allowed_origins in config/reverb.php to deployed origins (never * in production).

Start the independent Reverb process:

```bash
docker compose -f docker-compose.yml -f docker-compose.local.yml --profile realtime up -d reverb
```

Nginx proxies Pusher/Reverb paths /app/* and /apps/* to the internal `reverb:8080` service. **Do not publish container port 8080 directly**. Octane uses 127.0.0.1:8000 and the app's public Nginx uses port 80.

## Production

Octane MUST be installed and checked into composer.lock first. Set APP_ENV=production, APP_DEBUG=false, APP_HTTP_SERVER=octane, APP_URL=https://your-domain, OCTANE_HTTPS=true and real credentials in the deployment .env. The production image compiles the Vite frontend during the build, installs Composer dependencies with --no-dev, and excludes node_modules. Build and start:

```bash
docker compose -f docker-compose.yml -f docker-compose.production.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.production.yml build app
docker compose -f docker-compose.yml -f docker-compose.production.yml up -d
docker compose -f docker-compose.yml -f docker-compose.production.yml exec app php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.production.yml --profile workers --profile realtime up -d queue scheduler reverb
```

The production app is bound to 127.0.0.1:${APP_PORT:-8085}. Your host-level Nginx + Certbot should terminate TLS and forward requests (including WebSocket upgrade headers) to this loopback address. If the host proxy forwards X-Forwarded-Proto, configure Laravel's trusted proxies appropriately; the container Nginx is another proxy hop.

Redis and PostgreSQL are not exposed to the public internet. Do not reuse sample passwords or Reverb secrets. No migrations, new APP_KEY, or `laravelcrm:install` run automatically from the entrypoint.

### Important limitations / validation

- We could not execute Docker Engine in this environment. Run `docker compose ... config --quiet` and `docker compose ... build` on your host to validate actual package versions and the complete image build.
- The production frontend build uses the repository's existing `npm run build` script (Vite Plus). Run and verify it before deploying.
- Rebuild your Docker image after installing new Composer dependencies or changing composer.lock; production images are immutable.
- The shared app-storage volume must have sufficient space and permissions for uploads.
- This branch is **infrastructure only**. It does NOT implement QATUR IA business_id isolation, database RLS, or modify VentureDrake.
