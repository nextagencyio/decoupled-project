<?php

declare(strict_types=1);

namespace Drupal\dc_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Fetches audit JSON files from the deployed Astro frontend.
 *
 * Each GitHub Action (a11y / seo / performance) writes its result
 * to a JSON file the frontend serves publicly. This service fetches
 * and caches those files so the /dashboard route doesn't hit the
 * origin on every page load.
 *
 * Each scanner's URL is configured independently — a full public URL,
 * no path convention assumed:
 *
 *   dc_dashboard.settings:
 *     audit_url_a11y:        'https://<frontend>/audits/a11y/latest.json'
 *     audit_url_seo:         'https://<frontend>/audits/seo/latest.json'
 *     audit_url_performance: 'https://<frontend>/audits/performance/latest.json'
 *     ttl_seconds: 60
 *
 * Override any one at runtime via state if you need to point at a
 * preview deployment:
 *
 *   drush state:set dc_dashboard.audit_url_a11y 'https://preview-pr-42.vercel.app/audits/a11y/latest.json'
 */
final class AuditFetcher {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolve the full JSON URL for one scanner.
   *
   * Order: per-scanner state override, then config, then null.
   *
   * @param string $scanner
   *   One of 'a11y', 'seo', 'performance'.
   */
  private function getUrl(string $scanner): ?string {
    $key = "audit_url_{$scanner}";
    $stateOverride = \Drupal::state()->get("dc_dashboard.{$key}");
    if (is_string($stateOverride) && $stateOverride !== '') {
      return $stateOverride;
    }
    $configValue = $this->configFactory
      ->get('dc_dashboard.settings')
      ->get($key);
    if (is_string($configValue) && $configValue !== '') {
      return $configValue;
    }
    $this->logger->error(
      'dc_dashboard: @key is not configured. ' .
      'Set dc_dashboard.settings:@key or state dc_dashboard.@key.',
      ['@key' => $key]
    );
    return NULL;
  }

  /**
   * Resolve the cache TTL from config, default 60s.
   */
  private function getTtl(): int {
    $configValue = $this->configFactory
      ->get('dc_dashboard.settings')
      ->get('ttl_seconds');
    return is_int($configValue) && $configValue > 0 ? $configValue : 60;
  }

  /**
   * Return the parsed JSON for one scanner, or null on fetch failure.
   *
   * @param string $scanner
   *   One of 'a11y', 'seo', 'performance'.
   */
  public function fetch(string $scanner): ?array {
    $cid = "dc_dashboard:audit:{$scanner}";
    $cached = $this->cache->get($cid);
    if ($cached && $cached->data) {
      return $cached->data;
    }

    $url = $this->getUrl($scanner);
    if (!$url) {
      // Cache the null briefly so a misconfigured site doesn't hammer
      // the (nonexistent) origin on every request.
      $this->cache->set($cid, NULL, time() + 60);
      return NULL;
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 10,
        'headers' => ['Accept' => 'application/json'],
      ]);
      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (GuzzleException | \JsonException $e) {
      $this->logger->warning('Audit fetch failed for @scanner: @msg', [
        '@scanner' => $scanner,
        '@msg' => $e->getMessage(),
      ]);
      $this->cache->set($cid, NULL, time() + 60);
      return NULL;
    }

    $this->cache->set($cid, $data, time() + $this->getTtl());
    return $data;
  }

  /**
   * Return all three reports in one keyed array.
   */
  public function fetchAll(): array {
    return [
      'a11y' => $this->fetch('a11y'),
      'seo' => $this->fetch('seo'),
      'performance' => $this->fetch('performance'),
    ];
  }

}
