<?php

// Platform-agnostic settings applied when the container runs under any
// PaaS that supports env-var injection (Fly.io, Railway, Render, Cloud Run,
// plain docker-run, etc.). Gated on env vars so this file no-ops in DDEV /
// local dev contexts.
//
// Trigger vars (any one enables the block):
//   PLATFORM_DEV=1          — preferred, explicit
//   RAILWAY_ENVIRONMENT=*   — auto-set by Railway
//   FLY_APP_NAME=*          — auto-set by Fly.io
//   RAILWAY_DEV=1           — legacy alias

if (
  getenv('PLATFORM_DEV') === false &&
  getenv('RAILWAY_ENVIRONMENT') === false &&
  getenv('FLY_APP_NAME') === false &&
  getenv('RAILWAY_DEV') === false
) {
  return;
}

// ============================================================================
// Database
// ============================================================================
//
// Auto-detect between Postgres and MySQL based on which env vars are set.
// Railway's MySQL plugin injects MYSQLHOST etc.; Fly Postgres (or our
// manual setup) injects DATABASE_URL or POSTGRES_HOST. We support both so
// the same image runs on either cloud without code changes.

if ($database_url = getenv('DATABASE_URL')) {
  // Parse a postgres:// or mysql:// URL (the format Fly Postgres and most
  // managed providers inject).
  $parts = parse_url($database_url);
  $scheme = $parts['scheme'] ?? 'mysql';
  $databases['default']['default'] = [
    'database'  => ltrim($parts['path'] ?? '', '/'),
    'username'  => $parts['user'] ?? '',
    'password'  => $parts['pass'] ?? '',
    'host'      => $parts['host'] ?? '127.0.0.1',
    'port'      => $parts['port'] ?? ($scheme === 'postgres' ? 5432 : 3306),
    'driver'    => $scheme === 'postgres' || $scheme === 'postgresql' ? 'pgsql' : 'mysql',
    'prefix'    => '',
  ];
}
elseif (getenv('POSTGRES_HOST') !== false || getenv('PGHOST') !== false) {
  // Explicit Postgres env vars.
  $databases['default']['default'] = [
    'database'  => getenv('POSTGRES_DB') ?: getenv('PGDATABASE') ?: 'drupal',
    'username'  => getenv('POSTGRES_USER') ?: getenv('PGUSER') ?: 'drupal',
    'password'  => getenv('POSTGRES_PASSWORD') ?: getenv('PGPASSWORD') ?: '',
    'host'      => getenv('POSTGRES_HOST') ?: getenv('PGHOST') ?: '127.0.0.1',
    'port'      => getenv('POSTGRES_PORT') ?: getenv('PGPORT') ?: '5432',
    'driver'    => 'pgsql',
    'prefix'    => '',
  ];
}
else {
  // Default: MySQL/MariaDB (Railway's plugin vars, or our docker-run vars).
  $databases['default']['default'] = [
    'database'  => getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'drupal',
    'username'  => getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'drupal',
    'password'  => getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '',
    'host'      => getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '127.0.0.1',
    'port'      => getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306',
    'driver'    => 'mysql',
    'prefix'    => '',
    'collation' => 'utf8mb4_general_ci',
  ];
}

// ============================================================================
// Redis (optional — wired if REDIS_URL is set)
// ============================================================================
//
// On Fly.io we use a shared Upstash Redis (one cluster, many tenants). To
// keep tenants from stepping on each other's keys, we prefix every cache
// entry with the Fly app name. Set FLY_APP_NAME is auto-injected by Fly.

if ($redis_url = getenv('REDIS_URL')) {
  $parts = parse_url($redis_url);
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host']      = $parts['host'];
  $settings['redis.connection']['port']      = $parts['port'] ?? 6379;
  if (!empty($parts['pass'])) {
    $settings['redis.connection']['password'] = $parts['pass'];
  }
  // Tenant key isolation on a shared Redis. Falls back to the DB name so
  // local-docker / ddev runs don't collide either.
  $settings['cache_prefix'] = getenv('FLY_APP_NAME') ?: ($databases['default']['default']['database'] ?? 'drupal');

  $settings['cache']['default']              = 'cache.backend.redis';
  $settings['cache']['bins']['bootstrap']    = 'cache.backend.chainedfast';
  $settings['cache']['bins']['discovery']    = 'cache.backend.chainedfast';
  $settings['cache']['bins']['config']       = 'cache.backend.chainedfast';
}

// ============================================================================
// Security + host trust
// ============================================================================

$settings['hash_salt'] = getenv('HASH_SALT') ?: 'local-docker-spike-not-for-prod';

$settings['trusted_host_patterns'] = [
  '^localhost$',
  '^127\.0\.0\.1$',
  '^.+\.railway\.app$',
  '^.+\.up\.railway\.app$',
  '^.+\.fly\.dev$',
];

// ============================================================================
// File system
// ============================================================================
//
// In Railway, mount a persistent volume at /app/web/sites/default/files so
// uploads + aggregated assets survive redeploys. Drupal writes its twig + php
// storage under this path by default.

$settings['file_temp_path']   = '/tmp';
$settings['file_public_path'] = 'sites/default/files';

// config_sync_directory is set in docker/drupal-settings.php (relative path,
// interpreted by Drupal from the site directory). Don't use DRUPAL_ROOT here
// — Drupal 11's minimal index.php no longer defines that constant before
// settings.php is loaded, so referencing it causes a fatal during settings
// initialization, which silently kills the DB config and sends every request
// to /core/install.php.
