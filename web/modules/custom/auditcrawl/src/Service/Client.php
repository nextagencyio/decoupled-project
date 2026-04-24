<?php

namespace Drupal\auditcrawl\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * HTTP wrapper around the auditcrawl.com /api/wp/* endpoints.
 *
 * Mirrors the WP plugin's class-auditcrawl-api.php — same two auth
 * modes (public endpoints keyed on a report code, premium endpoints
 * gated by a Bearer license key), same uniform result shape callers
 * can switch on:
 *   ['ok' => true,  'data' => array, 'status' => int]
 *   ['ok' => false, 'error' => string, 'status' => int]
 *
 * We deliberately hit the same /api/wp/* URLs the WordPress plugin
 * uses — those endpoints don't care what's calling them, they just
 * want a code or a license key. Renaming the URL namespace can come
 * later as a Next-side refactor; not worth breaking parity now.
 */
class Client {

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /** Fetch a report by the saved code (or a provided override). */
  public function fetchReport(?string $codeOrToken = NULL): array {
    $code = $codeOrToken ?? $this->config()->get('report_code');
    if (!$code) {
      return $this->fail('No report code configured. Connect a report first.', 0);
    }
    $param = preg_match('/^[0-9a-f-]{36}$/i', $code) ? 'token' : 'code';
    return $this->request('GET', "/api/wp/report?{$param}=" . rawurlencode($code));
  }

  /** GET /api/wp/license/validate?siteUrl=... — premium entitlement check. */
  public function validateLicense(): array {
    $site = rawurlencode($this->siteUrl());
    return $this->request('GET', '/api/wp/license/validate?siteUrl=' . $site, NULL, ['auth' => 'license']);
  }

  /** GET /api/wp/license/portal — returns a Stripe billing-portal URL. */
  public function licensePortal(): array {
    return $this->request('GET', '/api/wp/license/portal', NULL, ['auth' => 'license']);
  }

  /** POST /api/wp/license/rotate — mints a fresh key + emails it. */
  public function rotateLicense(): array {
    return $this->request('POST', '/api/wp/license/rotate', [], ['auth' => 'license']);
  }

  /** POST /api/wp/license/move — re-bind license to this site. */
  public function moveLicense(): array {
    return $this->request('POST', '/api/wp/license/move', [
      'newSiteUrl' => $this->siteUrl(),
    ], ['auth' => 'license']);
  }

  /**
   * POST /api/wp/content/generate — premium endpoint; decrements one
   * content credit per successful call. Server refunds on failure.
   */
  public function generateContent(array $body): array {
    if (!isset($body['siteUrl'])) {
      $body['siteUrl'] = $this->siteUrl();
    }
    // 90s timeout because the Groq+research+image pipeline on the
    // server side averages 20-30s with occasional spikes.
    return $this->request('POST', '/api/wp/content/generate', $body, [
      'auth' => 'license',
      'timeout' => 90,
    ]);
  }

  /**
   * Find the highest-priority empty draft stub for the connected
   * report and fill it via /api/wp/content/generate. Returns TRUE if
   * a stub was filled, FALSE if there was nothing to do (no license,
   * no connected report, all stubs filled, out of credits).
   *
   * Called by hook_cron.
   */
  public function fillNextEmptyStub(): bool {
    $config = $this->config();
    $license = $config->get('license_key');
    $code = $config->get('report_code');
    if (!$license || !$code) {
      return FALSE;
    }

    // Get the report so we know what the strategy priority order is.
    $report = $this->fetchReport();
    if (!$report['ok']) {
      $this->logger()->warning('Cron: could not load report: @e', ['@e' => $report['error']]);
      return FALSE;
    }
    $strategy = $report['data']['report']['strategy']['contentStrategy'] ?? [];
    $stubMap = $config->get('stub_node_ids') ?: [];

    // Priority-sorted list of (idx, item) with a live, unfilled stub node.
    $priRank = ['high' => 0, 'medium' => 1, 'low' => 2];
    $candidates = [];
    foreach ($strategy as $i => $item) {
      if (empty($stubMap[$i])) continue;
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($stubMap[$i]);
      if (!$node || $node->bundle() !== 'article') continue;
      if ((bool) $node->get('field_ac_generated_at')->value) continue;
      $candidates[] = [
        'idx' => $i,
        'nid' => $node->id(),
        'rank' => $priRank[strtolower($item['priority'] ?? 'low')] ?? 2,
        'item' => $item,
      ];
    }
    usort($candidates, fn($a, $b) => $a['rank'] <=> $b['rank']);
    $pick = $candidates[0] ?? NULL;
    if (!$pick) {
      return FALSE;
    }

    $res = $this->generateContent([
      'reportCode' => $code,
      'strategyItemIndex' => $pick['idx'],
    ]);
    if (!$res['ok']) {
      $this->logger()->warning('Cron: generate failed for node @nid: @e', [
        '@nid' => $pick['nid'],
        '@e' => $res['error'],
      ]);
      return FALSE;
    }

    $draft = $res['data']['draft'] ?? NULL;
    if (!$draft) {
      return FALSE;
    }

    // Write body + meta + mark filled. Caller (ApiController) uses the
    // exact same function when the user clicks "Generate now", so the
    // write path stays consistent whether cron or a human triggered it.
    \Drupal\auditcrawl\Service\NodeWriter::fill($pick['nid'], $draft);
    return TRUE;
  }

  // ── Internal ─────────────────────────────────────────────────

  protected function request(string $method, string $path, $body = NULL, array $opts = []): array {
    $needsLicense = ($opts['auth'] ?? NULL) === 'license';
    $licenseKey = $needsLicense ? $this->config()->get('license_key') : NULL;
    if ($needsLicense && !$licenseKey) {
      return $this->fail('No license key configured. Premium features require a subscription.', 0);
    }

    $headers = [
      'Accept' => 'application/json',
      'User-Agent' => 'AuditCrawl-Drupal/0.1.0',
    ];
    if ($needsLicense) {
      $headers['Authorization'] = 'Bearer ' . $licenseKey;
    }
    if ($body !== NULL) {
      $headers['Content-Type'] = 'application/json';
    }

    try {
      $response = $this->httpClient->request($method, $this->apiBase() . $path, [
        'headers' => $headers,
        'body' => $body === NULL ? NULL : json_encode($body),
        'timeout' => $opts['timeout'] ?? 30,
        'http_errors' => FALSE,
      ]);
    }
    catch (RequestException $e) {
      return $this->fail($e->getMessage(), 0);
    }

    $status = $response->getStatusCode();
    $raw = (string) $response->getBody();
    $data = json_decode($raw, TRUE);

    if ($status >= 200 && $status < 300) {
      return ['ok' => TRUE, 'data' => is_array($data) ? $data : [], 'status' => $status];
    }
    $error = is_array($data) && !empty($data['error']) ? $data['error'] : 'API returned HTTP ' . $status;
    return $this->fail($error, $status);
  }

  protected function fail(string $error, int $status): array {
    return ['ok' => FALSE, 'error' => $error, 'status' => $status];
  }

  protected function config() {
    return $this->configFactory->get('auditcrawl.settings');
  }

  protected function apiBase(): string {
    return rtrim($this->config()->get('api_base') ?? 'https://auditcrawl.com', '/');
  }

  protected function siteUrl(): string {
    return \Drupal::request()->getSchemeAndHttpHost() . '/';
  }

  protected function logger() {
    return $this->loggerFactory->get('auditcrawl');
  }

}
