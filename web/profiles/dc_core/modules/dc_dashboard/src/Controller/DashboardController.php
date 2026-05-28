<?php

declare(strict_types=1);

namespace Drupal\dc_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dc_dashboard\Service\AuditFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the /dashboard page.
 *
 * Pulls the three audit reports (a11y, SEO, performance) from the
 * deployed duck-website Vercel domain, distills them into a small
 * set of summary tiles + per-page tables, and hands them to a Twig
 * template for rendering.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly AuditFetcher $auditFetcher,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('dc_dashboard.audit_fetcher'));
  }

  public function page(): array {
    $reports = $this->auditFetcher->fetchAll();
    $config = $this->config('dc_dashboard.settings');

    return [
      '#theme' => 'dc_dashboard',
      '#a11y' => $this->summarizeA11y($reports['a11y']),
      '#seo' => $this->summarizeSeo($reports['seo']),
      '#performance' => $this->summarizePerformance($reports['performance']),
      '#site' => [
        'name' => $config->get('site_name') ?? \Drupal::config('system.site')->get('name') ?? 'Site',
        'url' => $config->get('site_url') ?? '',
        'repo' => $config->get('site_repo') ?? '',
      ],
      '#attached' => [
        'library' => ['dc_dashboard/dashboard'],
      ],
      '#cache' => [
        'max-age' => (int) ($config->get('ttl_seconds') ?: 60),
        'contexts' => ['url.path'],
        'tags' => ['config:dc_dashboard.settings'],
      ],
    ];
  }

  /**
   * Reshape a11y JSON into a template-friendly structure.
   */
  private function summarizeA11y(?array $data): array {
    if (!$data) return $this->emptyReport('Accessibility', 'WCAG 2.2 AA via axe-core');
    $summary = $data['summary'] ?? [];
    $pass = !empty($summary['pass']);
    return [
      'title' => 'Accessibility',
      'standard' => $data['standard'] ?? 'WCAG 2.2 AA',
      'runAt' => $data['runAt'] ?? null,
      'baseUrl' => $data['baseUrl'] ?? null,
      'status' => $pass ? 'pass' : 'fail',
      'headline' => [
        'value' => ($summary['pagesPassing'] ?? 0) . ' / ' . ($summary['pagesAudited'] ?? 0),
        'label' => 'pages passing',
      ],
      'metrics' => [
        ['label' => 'Total violations', 'value' => $summary['totalViolations'] ?? 0],
        ['label' => 'Pages audited',    'value' => $summary['pagesAudited'] ?? 0],
        ['label' => 'Pages passing',    'value' => $summary['pagesPassing'] ?? 0],
      ],
      'pages' => array_map(static function (array $p): array {
        return [
          'label' => $p['label'] ?? $p['url'],
          'url' => $p['url'],
          'violations' => $p['violationCount'] ?? (is_array($p['violations'] ?? null) ? count($p['violations']) : 0),
          'status' => empty($p['violations']) ? 'pass' : 'fail',
        ];
      }, $data['pages'] ?? []),
    ];
  }

  /**
   * Reshape SEO JSON into a template-friendly structure.
   */
  private function summarizeSeo(?array $data): array {
    if (!$data) return $this->emptyReport('SEO', 'Lighthouse SEO + structural');
    $summary = $data['summary'] ?? [];
    $pass = !empty($summary['pass']);
    return [
      'title' => 'SEO',
      'standard' => $data['standard'] ?? 'Lighthouse SEO',
      'runAt' => $data['runAt'] ?? null,
      'baseUrl' => $data['baseUrl'] ?? null,
      'status' => $pass ? 'pass' : 'fail',
      'headline' => [
        'value' => ($summary['avgScore'] ?? 'n/a') . '',
        'label' => 'avg Lighthouse SEO score',
      ],
      'metrics' => [
        ['label' => 'Avg score',     'value' => $summary['avgScore'] ?? '—'],
        ['label' => 'Pages passing', 'value' => ($summary['pagesPassing'] ?? 0) . ' / ' . ($summary['pagesAudited'] ?? 0)],
        ['label' => 'Sitemap',       'value' => !empty($data['site']['sitemap']['ok']) ? 'ok' : 'fail'],
        ['label' => 'Robots.txt',    'value' => !empty($data['site']['robots']['ok']) ? 'ok' : 'fail'],
      ],
      'pages' => array_map(static function (array $p): array {
        $errors = 0; $warnings = 0;
        foreach ($p['issues'] ?? [] as $issue) {
          if (($issue['severity'] ?? '') === 'error') $errors++;
          elseif (($issue['severity'] ?? '') === 'warn') $warnings++;
        }
        return [
          'label' => $p['label'] ?? $p['url'],
          'url' => $p['url'],
          'score' => $p['lighthouseScore'] ?? null,
          'errors' => $errors,
          'warnings' => $warnings,
          'status' => $errors > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'pass'),
        ];
      }, $data['pages'] ?? []),
    ];
  }

  /**
   * Reshape performance JSON into a template-friendly structure.
   */
  private function summarizePerformance(?array $data): array {
    if (!$data || empty($data['pages'])) return $this->emptyReport('Performance', 'Lighthouse + Core Web Vitals');
    $summary = $data['summary'] ?? [];
    $pass = !empty($summary['pass']);
    return [
      'title' => 'Performance',
      'standard' => $data['standard'] ?? 'Lighthouse + CWV',
      'runAt' => $data['runAt'] ?? null,
      'baseUrl' => $data['baseUrl'] ?? null,
      'status' => $pass ? 'pass' : 'fail',
      'headline' => [
        'value' => ($summary['avgMobileScore'] ?? 'n/a') . '',
        'label' => 'avg mobile Lighthouse score',
      ],
      'metrics' => [
        ['label' => 'Avg mobile',  'value' => $summary['avgMobileScore'] ?? '—'],
        ['label' => 'Avg desktop', 'value' => $summary['avgDesktopScore'] ?? '—'],
        ['label' => 'Pages',       'value' => $summary['pagesAudited'] ?? 0],
      ],
      'pages' => array_map(static function (array $p): array {
        $m = $p['mobile']['metrics'] ?? [];
        $score = $p['mobile']['score'] ?? null;
        $lcp = $m['lcp'] ?? null;
        $cls = $m['cls'] ?? null;
        $status = 'pass';
        if ($score !== null && $score < 80) $status = 'fail';
        elseif ($lcp !== null && $lcp > 2500) $status = 'fail';
        elseif ($cls !== null && $cls > 0.1) $status = 'fail';
        elseif ($score !== null && $score < 90) $status = 'warn';
        return [
          'label' => $p['label'] ?? $p['url'],
          'url' => $p['url'],
          'mobileScore' => $score,
          'desktopScore' => $p['desktop']['score'] ?? null,
          'lcp' => $lcp,
          'cls' => $cls,
          'status' => $status,
        ];
      }, $data['pages'] ?? []),
    ];
  }

  /**
   * Placeholder card when a report hasn't run yet.
   */
  private function emptyReport(string $title, string $standard): array {
    return [
      'title' => $title,
      'standard' => $standard,
      'runAt' => null,
      'baseUrl' => null,
      'status' => 'pending',
      'headline' => ['value' => '—', 'label' => 'awaiting first run'],
      'metrics' => [],
      'pages' => [],
    ];
  }

}
