# Decoupled Drupal Project

Drupal 11 installation powering the headless backend for
[decoupled.io](https://decoupled.io). Custom `dc_core` install profile,
FrankenPHP-based Docker image, and a `fly.toml` for building + pushing
the image to Fly.io.

This repo is **application code only**. Provisioning of Fly tenant apps —
the orchestration that spins up a new Drupal site per customer — lives in
the [decoupled-dashboard](https://github.com/nextagencyio/decoupled-dashboard)
repo under `scripts/fly/`. If you're looking for `bin/provision-tenant.sh`,
it moved there.

## Stack

- **Drupal 11** on PHP 8.5
- **FrankenPHP** (Caddy + PHP worker mode) — Dockerfile + Caddyfile in repo root
- **PostgreSQL** (Drupal runs on Postgres via `settings.platform.php`)
- **APCu** for hot cache bins via Drupal's chainedfast backend
- **dc_core** install profile (see `web/profiles/dc_core/`)
  - Custom modules: `dc_chatbot`, `dc_config`, `dc_import`, `dc_mail`, `dc_puck`, `dc_revalidate`
  - GraphQL Compose for headless API
  - OAuth consumers auto-generated per install via `hook_install()`

## Local development

DDEV-based local dev (see `.ddev/config.yaml`):

```bash
ddev start
ddev composer install
ddev drush site:install dc_core -y
```

## Building the Fly image

Every tenant on Fly pulls its image from the `decoupled-drupal-frankenphp`
Fly app's registry. To ship a new image:

```bash
fly deploy
```

This builds the FrankenPHP image on Fly's builder, pushes it to
`registry.fly.io/decoupled-drupal-frankenphp`, and redeploys the source app.
New tenants provisioned after this automatically pick up the new image
(the dashboard's provisioner resolves the current tag at create time).

**Rolling the image out to existing tenants** is the dashboard's
`fly-fleet-deploy.yml` GitHub Action — it loops over every `tenant-*` app
and `fly deploy`s them against the new tag. Run from the dashboard repo.

## Repository structure

```
Dockerfile                          FrankenPHP build
Caddyfile                           Caddy config (serves /app/web)
frankenphp-worker.php               FrankenPHP worker bootstrap
fly.toml                            Fly config for the source app
composer.json                       Drupal + contrib dependencies
docker/
├── apcu.ini                        Enables APCu (shipped disabled in the image)
└── drupal-settings.php             Committed settings.php shim
web/
├── profiles/
│   └── dc_core/                    Install profile + custom modules
├── sites/
│   └── default/
│       ├── settings.platform.php   Reads DATABASE_URL / HASH_SALT from env
│       └── settings.platformsh.php (legacy, unused on Fly)
└── ...
```

## Requirements

- PHP 8.3+ (or DDEV, which brings its own). Production images run PHP 8.5 via `dunglas/frankenphp:1-php8.5`.
- Composer 2
- `flyctl` for deploying the source image to Fly
- Drupal 11

## License

GPL-2.0-or-later (same as Drupal core).
