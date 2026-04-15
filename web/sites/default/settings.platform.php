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

// MySQL/MariaDB connections need an init_command that bumps the
// transaction isolation level from the MariaDB default (REPEATABLE-READ)
// down to READ-COMMITTED. Drupal's status report flags REPEATABLE-READ
// as a warning because it interacts badly with Drupal's optimistic
// locking and entity revision logic. The init_command runs on every
// new Drupal connection, so the SESSION value is correct even though
// the server default stays at REPEATABLE-READ.
// See https://www.drupal.org/docs/system-requirements/setting-mysql-transaction-isolation-level
$mysql_init_commands = [
  'isolation_level' => "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED",
];

if ($database_url = getenv('DATABASE_URL')) {
  // Parse a postgres:// or mysql:// URL (the format Fly Postgres and most
  // managed providers inject).
  $parts = parse_url($database_url);
  $scheme = $parts['scheme'] ?? 'mysql';
  $is_pg = $scheme === 'postgres' || $scheme === 'postgresql';
  $databases['default']['default'] = [
    'database'  => ltrim($parts['path'] ?? '', '/'),
    'username'  => $parts['user'] ?? '',
    'password'  => $parts['pass'] ?? '',
    'host'      => $parts['host'] ?? '127.0.0.1',
    'port'      => $parts['port'] ?? ($is_pg ? 5432 : 3306),
    'driver'    => $is_pg ? 'pgsql' : 'mysql',
    'prefix'    => '',
  ];
  if (!$is_pg) {
    $databases['default']['default']['init_commands'] = $mysql_init_commands;
  }
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
    'init_commands' => $mysql_init_commands,
  ];
}

// ============================================================================
// APCu (chainedfast for hot bins)
// ============================================================================
//
// Drupal core's chainedfast backend uses APCu as an in-process fast tier
// on top of the persistent (database) backend. It's free, needs no
// external service, and survives for the lifetime of the FrankenPHP
// worker — which is long-lived, so the hot bins stay hot.
//
// APCu is per-process, so with multiple workers each has its own cache.
// Fine for a single-worker FrankenPHP tenant; if we later scale workers,
// revisit (keys just refresh more often).

if (function_exists('apcu_enabled') && apcu_enabled()) {
  $settings['cache']['bins']['bootstrap'] = 'cache.backend.chainedfast';
  $settings['cache']['bins']['discovery'] = 'cache.backend.chainedfast';
  $settings['cache']['bins']['config']    = 'cache.backend.chainedfast';
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
