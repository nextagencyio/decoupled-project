<?php

namespace Drupal\auditcrawl\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

/**
 * Main /admin/content/auditcrawl page — renders the Content Strategy table
 * with a Create-draft button per row. Mirrors the WP plugin's
 * render_report_page().
 */
class ReportController extends ControllerBase {

  public function render() {
    // First-time setup guard. If the 'article' content type is
    // missing (or our fields haven't been installed), redirect to
    // SetupForm which asks the admin for consent before making
    // site-wide changes like creating a content type.
    if (!\Drupal\auditcrawl\Service\NodeWriter::isSetupComplete()) {
      return new \Symfony\Component\HttpFoundation\RedirectResponse(
        \Drupal\Core\Url::fromRoute('auditcrawl.setup')->toString()
      );
    }

    $config = $this->config('auditcrawl.settings');
    $code = (string) $config->get('report_code');

    // Unconnected empty state.
    if ($code === '') {
      return [
        '#theme_wrappers' => ['container'],
        'header' => $this->headerBuild('SEO Content Strategy', 'No report connected yet.', ''),
        'notice' => [
          '#markup' => '<div class="messages messages--warning">' . $this->t('No report connected. <a href=":u">Connect a report</a> to get started.', [
            ':u' => Url::fromRoute('auditcrawl.connect')->toString(),
          ]) . '</div>',
        ],
      ];
    }

    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->fetchReport();
    if (!$res['ok']) {
      return [
        'header' => $this->headerBuild('SEO Content Strategy', 'Could not load the connected report.', ''),
        'notice' => [
          '#markup' => '<div class="messages messages--error">' . $this->t('Could not load report (@e). <a href=":u">Reconnect</a>?', [
            '@e' => $res['error'],
            ':u' => Url::fromRoute('auditcrawl.connect')->toString(),
          ]) . '</div>',
        ],
      ];
    }

    $data = $res['data'];
    $report = $data['report'] ?? [];
    $strategy = $report['strategy']['contentStrategy'] ?? [];
    $hostname = $data['hostname'] ?? '';
    $viewUrl = $data['viewUrl'] ?? '#';

    // Credit pill subtitle — only valid when a license is configured.
    $pillHtml = '';
    if ($config->get('license_key')) {
      $ent = $client->validateLicense();
      if ($ent['ok']) {
        $credits = (int) ($ent['data']['license']['contentCredits'] ?? 0);
        $pillHtml = '<span class="auditcrawl-pill"><strong>' . $credits . '</strong> drafts left this period <a href="' . Url::fromRoute('auditcrawl.schedule')->toString() . '">Manage →</a></span>';
      }
    }
    else {
      $pillHtml = '<span class="auditcrawl-pill auditcrawl-pill--muted">Free tier · <a href="' . Url::fromRoute('auditcrawl.schedule')->toString() . '">Upgrade →</a></span>';
    }

    $subtitle = '<strong>' . htmlspecialchars($hostname) . '</strong>'
      . ' · <a href="' . htmlspecialchars($viewUrl) . '" target="_blank" rel="noopener">View on auditcrawl.com →</a>'
      . ' · Connected as <code>' . htmlspecialchars($code) . '</code>'
      . ' · <a href="' . Url::fromRoute('auditcrawl.connect')->toString() . '">Change</a>';

    $header = $this->headerBuild('SEO Content Strategy', $subtitle, $pillHtml);

    // Build the table rows.
    $stubMap = $config->get('stub_node_ids') ?: [];
    $rows = '';
    foreach ($strategy as $i => $piece) {
      $nid = (int) ($stubMap[$i] ?? 0);
      $node = $nid ? Node::load($nid) : NULL;
      $priority = strtolower($piece['priority'] ?? '');
      $chipClass = in_array($priority, ['high', 'medium', 'low'], TRUE)
        ? 'auditcrawl-chip auditcrawl-chip--' . $priority
        : 'auditcrawl-chip';

      $status = '';
      if ($node) {
        $editUrl = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString();
        $status .= '<a href="' . $editUrl . '" class="button button--small">Open draft</a>';
        $hasLicense = (bool) $config->get('license_key');
        $isFilled = (bool) $node->get('field_ac_generated_at')->value;
        if ($hasLicense && !$isFilled) {
          $status .= ' <button type="button" class="button button--small auditcrawl-generate-now" data-post-id="' . $node->id() . '">Generate now</button>';
        }
        if ($isFilled) {
          $wordCount = (int) $node->get('field_ac_word_count')->value;
          $status .= ' <span style="color:#059669;font-size:11px;">✓ ' . $wordCount . ' words</span>';
        }
      }
      else {
        $status = '<button type="button" class="button button--primary button--small auditcrawl-create-stub" data-strategy-index="' . $i . '">Create draft</button>';
      }

      $rows .= '<tr data-strategy-index="' . $i . '">
        <td>' . ($i + 1) . '</td>
        <td><strong>' . htmlspecialchars($piece['title'] ?? '') . '</strong></td>
        <td>' . htmlspecialchars($piece['targetKeywords'] ?? '') . '</td>
        <td><span class="' . $chipClass . '">' . htmlspecialchars($priority ?: '—') . '</span></td>
        <td class="auditcrawl-stub-status" data-post-id="' . ($node ? $node->id() : 0) . '">' . $status . '</td>
      </tr>';
    }

    $table = '
      <h2>Content Strategy — create draft posts</h2>
      <p>Each row is one article from your strategy. Click <strong>Create draft</strong> to add an unpublished Drupal article with the title + target keywords pre-filled. You write the body, or upgrade to the <a href="' . Url::fromRoute('auditcrawl.schedule')->toString() . '">premium scheduling tier</a> to have us write it for you.</p>
      <table class="auditcrawl-strategy-table">
        <thead><tr><th>#</th><th>Title</th><th>Target keywords</th><th>Priority</th><th>Status</th></tr></thead>
        <tbody>' . $rows . '</tbody>
      </table>
    ';

    return [
      'header' => $header,
      'table' => ['#markup' => $table, '#allowed_tags' => ['h2', 'p', 'strong', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'button', 'code']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Render the branded header band used on all three AuditCrawl
   * admin pages. Parallels the WP plugin's render_header() helper.
   */
  protected function headerBuild(string $title, string $subtitleHtml, string $pillHtml): array {
    return [
      '#markup' => '
        <div class="auditcrawl-header">
          <div class="auditcrawl-header__brand">
            <div class="auditcrawl-header__icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="m8 11 2 2 4-4"></path><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
            </div>
            <div>
              <h1 class="auditcrawl-header__title">' . htmlspecialchars($title) . '</h1>
              ' . ($subtitleHtml ? '<p class="auditcrawl-header__subtitle">' . $subtitleHtml . '</p>' : '') . '
            </div>
          </div>
          ' . $pillHtml . '
        </div>
      ',
      '#allowed_tags' => ['div', 'h1', 'p', 'strong', 'a', 'span', 'code', 'svg', 'path', 'circle', 'button'],
    ];
  }

}
