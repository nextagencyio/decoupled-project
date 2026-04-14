<?php

/**
 * FrankenPHP worker script for Drupal 11.
 *
 * The worker bootstraps Drupal's kernel ONCE when the process starts, then
 * loops calling frankenphp_handle_request() for each HTTP request. Between
 * requests the PHP process stays alive and the Drupal service container
 * stays in memory — skipping the 50-200ms bootstrap cost that normally
 * happens on every request in classic mode.
 *
 * Caveats:
 *   - Any static state in Drupal code or contrib modules that wasn't
 *     designed to be reset between requests will leak. Most Drupal core
 *     things are fine; some contrib is not.
 *   - MAX_REQUESTS bounds the worker's lifetime so long-lived memory
 *     leaks don't matter in practice — the worker is recycled after N
 *     requests.
 *   - Admin operations that rebuild the service container (module install,
 *     config import that adds services) require a worker restart.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

ignore_user_abort(true);

$autoloader = require_once __DIR__ . '/web/autoload.php';

// Boot Drupal's kernel once, using the first incoming request to establish
// the site path. Subsequent requests reuse this kernel.
$kernel = NULL;

$handler = static function () use (&$kernel, $autoloader) {
    $request = Request::createFromGlobals();

    if ($kernel === NULL) {
        $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
        $kernel->boot();
    }

    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
};

// Recycle the worker after N requests to bound memory growth.
$max_requests = (int) ($_SERVER['MAX_REQUESTS'] ?? 500);

for ($requests = 0, $running = TRUE; $running && $requests < $max_requests; ++$requests) {
    $running = \frankenphp_handle_request($handler);
    gc_collect_cycles();
}
