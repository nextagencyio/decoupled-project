<?php

/**
 * @file
 * Shared multisite configuration for all Drupal sites.
 *
 * This file is included by each site's settings.php to provide common
 * configuration across all sites in the multisite installation.
 *
 * Include this file in your site's settings.php:
 *
 * if (file_exists($app_root . '/' . $site_path . '/../shared.settings.php')) {
 *   include $app_root . '/' . $site_path . '/../shared.settings.php';
 * }
 */

/**
 * Redis Configuration
 *
 * Use Redis for caching to improve performance.
 * Redis is much faster than database caching for page cache and render cache.
 */
if (extension_loaded('redis')) {
  // Redis connection settings
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = 'redis';
  $settings['redis.connection']['port'] = 6379;

  // Use Redis for all cache bins except form cache
  $settings['cache']['default'] = 'cache.backend.redis';

  // Use database for form cache to avoid issues with large forms
  $settings['cache']['bins']['form'] = 'cache.backend.database';

  // Optional: Use Redis for lock backend (better concurrency)
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

  // Redis cache bin settings
  $settings['redis_compress_length'] = 100;
  $settings['redis_compress_level'] = 1;

  // Use site-specific cache prefix to avoid multisite conflicts
  // Extract site name from $site_path (e.g., 'sites/example' -> 'example')
  $site_name = basename($site_path);
  $settings['cache_prefix'] = 'drupal_' . $site_name . '_';
}

/**
 * Performance Settings
 *
 * These settings are applied to all sites for optimal performance.
 */

// Enable aggregate CSS files
$config['system.performance']['css']['preprocess'] = TRUE;

// Enable aggregate JavaScript files
$config['system.performance']['js']['preprocess'] = TRUE;

// Page cache for anonymous users (1 day)
$config['system.performance']['cache']['page']['max_age'] = 86400;

/**
 * Trusted Host Patterns
 *
 * Prevent HTTP Host header attacks by validating the Host header.
 * This is set dynamically based on DOMAIN_SUFFIX environment variable.
 */
$domain_suffix = getenv('DOMAIN_SUFFIX');
if ($domain_suffix) {
  $settings['trusted_host_patterns'] = [
    '^.+\.' . preg_quote($domain_suffix) . '$',
    '^' . preg_quote($domain_suffix) . '$',
  ];
}

/**
 * File System Paths
 *
 * These are common across all multisite installations.
 */
// Temporary directory
$settings['file_temp_path'] = '/tmp';

// Private file path (per-site, but structure is consistent)
// Individual sites should override this in their settings.php if needed

/**
 * Configuration Sync Directory
 *
 * Each site should override this in their own settings.php.
 */
// $settings['config_sync_directory'] = '../config/sync';

/**
 * Logging Configuration
 *
 * Enable verbose error reporting for development, disable for production.
 */
if (getenv('DRUPAL_ENV') === 'development') {
  $config['system.logging']['error_level'] = 'verbose';
} else {
  $config['system.logging']['error_level'] = 'hide';
}

/**
 * Reverse Proxy Configuration
 *
 * Sites behind nginx-proxy need this for correct IP detection.
 */
$settings['reverse_proxy'] = TRUE;
$settings['reverse_proxy_addresses'] = ['172.0.0.0/8'];
$settings['reverse_proxy_trusted_headers'] = \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_FOR | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_HOST | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PORT | \Symfony\Component\HttpFoundation\Request::HEADER_X_FORWARDED_PROTO;

