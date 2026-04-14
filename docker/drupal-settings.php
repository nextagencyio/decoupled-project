<?php

// Minimal committed settings.php used by the FrankenPHP Docker image.
//
// The normal `web/sites/default/settings.php` is gitignored (security), so
// it doesn't get uploaded by `railway up`. The Dockerfile COPYs this file
// into place instead. It's intentionally minimal and defers all
// environment-specific config to settings.railway.php.

// Drupal requires $databases to exist even if empty before settings.railway.php
// populates it.
$databases = [];

$settings['update_free_access'] = FALSE;
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['migrate_node_migrate_type_classic'] = FALSE;
$settings['config_sync_directory'] = '../config/sync';

// Railway runtime overrides — this is where $databases['default']['default']
// actually gets populated from MYSQL* env vars.
if (is_readable(__DIR__ . '/settings.railway.php')) {
  include __DIR__ . '/settings.railway.php';
}

// Host trust is also set by settings.railway.php, but keep a safety net.
$settings['trusted_host_patterns'][] = '^localhost$';
