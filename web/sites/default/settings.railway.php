<?php

// Settings applied when running under FrankenPHP on Railway (or a local
// docker-run with RAILWAY_DEV=1). Gated on env vars so this file no-ops in
// DDEV / platform.sh / any other context.

if (getenv('RAILWAY_ENVIRONMENT') === false && getenv('RAILWAY_DEV') === false) {
  return;
}

// ============================================================================
// Database
// ============================================================================
//
// Railway's MySQL service plugin auto-injects MYSQLHOST / MYSQLPORT / etc.
// For local docker-run we pass the same names explicitly so the same code
// path works in both environments.
//
// Fall through to DB_* env vars for easier overrides during development.

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

// ============================================================================
// Redis (optional — only wired if REDIS_URL is set by a Railway Redis plugin)
// ============================================================================

if ($redis_url = getenv('REDIS_URL')) {
  $parts = parse_url($redis_url);
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host']      = $parts['host'];
  $settings['redis.connection']['port']      = $parts['port'] ?? 6379;
  if (!empty($parts['pass'])) {
    $settings['redis.connection']['password'] = $parts['pass'];
  }
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
