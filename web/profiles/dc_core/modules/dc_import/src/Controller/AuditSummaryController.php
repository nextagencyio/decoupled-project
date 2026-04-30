<?php

declare(strict_types=1);

namespace Drupal\dc_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns a single JSON snapshot of the site's content + module health.
 *
 * Powers the "Drupal" tab on the decoupled.io dashboard's Site Health
 * view (see decoupled-dashboard/docs/SITE-HEALTH-AUDITS.md). The whole
 * point of one endpoint is that the cron auditor can ingest the site's
 * shape with a single request — counts, taxonomy size, module update
 * status, and a small slice of recent watchdog rows.
 *
 * Auth: route requires `oauth2` + `access content`. The dashboard
 * exchanges its space-scoped MCP Agent client_credentials for a bearer
 * token (see ImportApiController::getMcpCredentials) and calls this
 * endpoint with `Authorization: Bearer …`. The MCP Agent consumer is
 * `user_id: 1`, so we run with admin context.
 *
 * Response shape mirrors the TypeScript `DrupalAuditSummary` interface
 * in decoupled-dashboard/lib/drupal-audit-summary.ts. Keep them in sync
 * if you change anything here.
 */
final class AuditSummaryController extends ControllerBase {

  // ControllerBase already declares $entityTypeManager + $database as
  // protected, non-readonly properties in Drupal 11. We can't redeclare
  // them as readonly via constructor promotion, so we keep our own
  // distinct properties for the database + module list, and use the
  // parent's $this->entityTypeManager() accessor.
  public function __construct(
    private readonly Connection $db,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * Build the audit summary payload.
   */
  public function summary(): JsonResponse {
    return new JsonResponse([
      'generated_at'    => gmdate('c'),
      'drupal_version'  => \Drupal::VERSION,
      'php_version'     => PHP_VERSION,
      'content_types'   => $this->contentTypeStats(),
      'totals'          => $this->totals(),
      'taxonomy'        => $this->taxonomyStats(),
      'modules'         => $this->moduleStats(),
      'recent_dblog'    => $this->recentDblog(),
    ]);
  }

  /**
   * Per-content-type counts plus the most recent edit timestamp. We
   * iterate node-type entities so disabled-but-defined types still
   * show up with a zero count rather than silently disappearing.
   */
  private function contentTypeStats(): array {
    $type_storage = $this->entityTypeManager()->getStorage('node_type');
    $types = $type_storage->loadMultiple();
    $out = [];

    foreach ($types as $type) {
      $machine_name = $type->id();
      $count_q = $this->db->select('node_field_data', 'n')
        ->condition('n.type', $machine_name)
        ->condition('n.default_langcode', 1);
      $count_q->addExpression('COUNT(*)', 'cnt');
      $count = (int) $count_q->execute()->fetchField();

      $last_q = $this->db->select('node_field_data', 'n')
        ->condition('n.type', $machine_name)
        ->condition('n.default_langcode', 1);
      $last_q->addExpression('MAX(n.changed)', 'last');
      $last = $last_q->execute()->fetchField();

      $out[] = [
        'machine_name'   => $machine_name,
        'label'          => (string) $type->label(),
        'count'          => $count,
        'last_edited_at' => $last ? gmdate('c', (int) $last) : NULL,
      ];
    }
    return $out;
  }

  /**
   * Site-wide totals. Cheap aggregate queries — no per-row work.
   */
  private function totals(): array {
    $node_count = (int) $this->db
      ->select('node_field_data', 'n')
      ->condition('n.default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    $user_count = (int) $this->db
      ->select('users_field_data', 'u')
      ->condition('u.uid', 0, '>')
      ->condition('u.default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    // Media might not be installed — guard so older installs don't 500.
    $media_count = 0;
    if ($this->moduleHandler()->moduleExists('media')) {
      $media_count = (int) $this->db
        ->select('media_field_data', 'm')
        ->condition('m.default_langcode', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
    }

    return [
      'nodes' => $node_count,
      'users' => $user_count,
      'media' => $media_count,
    ];
  }

  /**
   * Taxonomy size. Skipped if `taxonomy` isn't enabled.
   */
  private function taxonomyStats(): array {
    if (!$this->moduleHandler()->moduleExists('taxonomy')) {
      return ['vocab_count' => 0, 'term_count' => 0];
    }
    $vocab_count = (int) $this->entityTypeManager()
      ->getStorage('taxonomy_vocabulary')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();

    $term_count = (int) $this->db
      ->select('taxonomy_term_field_data', 't')
      ->condition('t.default_langcode', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'vocab_count' => $vocab_count,
      'term_count'  => $term_count,
    ];
  }

  /**
   * Module count + update-availability counts. The update.module data
   * cache may be stale — we read whatever is there rather than
   * refreshing on every audit (the dashboard runs daily; update.module
   * has its own cron-driven refresh).
   *
   * If update.module isn't enabled we still return the enabled count
   * so the inventory tab works; update fields are zeroed.
   */
  private function moduleStats(): array {
    $enabled = count($this->moduleList->getAllInstalledInfo());
    $updates_available = 0;
    $security_updates = 0;

    if ($this->moduleHandler()->moduleExists('update')) {
      // Available release info gets populated by update.module's cron.
      $available = update_get_available(FALSE);
      if ($available) {
        $projects = update_calculate_project_data($available);
        foreach ($projects as $project) {
          $status = $project['status'] ?? NULL;
          // UPDATE_NOT_CURRENT is the lowest "fix me" tier.
          if ($status === UPDATE_NOT_CURRENT
              || $status === UPDATE_NOT_SECURE
              || $status === UPDATE_REVOKED
              || $status === UPDATE_NOT_SUPPORTED) {
            $updates_available++;
          }
          if ($status === UPDATE_NOT_SECURE) {
            $security_updates++;
          }
        }
      }
    }

    return [
      'enabled'           => $enabled,
      'updates_available' => $updates_available,
      'security_updates'  => $security_updates,
    ];
  }

  /**
   * Most recent dblog rows likely to surface as QA findings:
   * broken images, file references, and content validation failures.
   * Capped at 50 rows and only emergency..warning severities so we
   * skip debug noise.
   *
   * Empty array if dblog isn't enabled — many production installs
   * route logs elsewhere via syslog.
   */
  private function recentDblog(): array {
    if (!$this->moduleHandler()->moduleExists('dblog')) {
      return [];
    }
    $rows = $this->db
      ->select('watchdog', 'w')
      ->fields('w', ['type', 'severity', 'message', 'variables', 'timestamp', 'location'])
      ->condition('w.type', ['image', 'file', 'content', 'access denied'], 'IN')
      ->condition('w.severity', 4, '<=')
      ->orderBy('w.timestamp', 'DESC')
      ->range(0, 50)
      ->execute()
      ->fetchAll();

    $out = [];
    foreach ($rows as $row) {
      // Substitute placeholders so dashboard ingestion can show the
      // user a real message instead of `%file` tokens.
      $vars = unserialize((string) $row->variables, ['allowed_classes' => FALSE]);
      $message = is_array($vars) ? strtr((string) $row->message, $vars) : (string) $row->message;
      $out[] = [
        'severity'  => (int) $row->severity,
        'type'      => (string) $row->type,
        'message'   => $message,
        'timestamp' => gmdate('c', (int) $row->timestamp),
        'location'  => $row->location ?: NULL,
      ];
    }
    return $out;
  }

}
