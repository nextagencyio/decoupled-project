<?php

namespace Drupal\auditcrawl\Service;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;

/**
 * Stub node lifecycle — create empty stubs and fill them later.
 *
 * Mirrors the WP plugin's wp_insert_post / wp_update_post pair.
 * Static helpers so both the interactive AJAX path (ApiController)
 * and the cron path (Client::fillNextEmptyStub) share the same
 * write semantics.
 *
 * Storage:
 *   - Nodes are the 'article' content type (present in every stock
 *     Drupal install; the dc_core profile ships it).
 *   - We stash AuditCrawl-specific metadata on the node via a handful
 *     of text/int fields created on module install. Using fields
 *     rather than third_party_settings or private tables so the data
 *     is visible in admin UI + Views + export.
 */
class NodeWriter {

  /**
   * Create a blank draft stub for a strategy item. Parallels the WP
   * plugin's ajax_create_stubs handler.
   */
  public static function createStub(int $strategyIndex, array $item, string $reportCode): Node {
    $values = [
      'type' => 'article',
      'title' => mb_substr($item['title'] ?? 'Untitled', 0, 255),
      'status' => 0, // unpublished draft
      'field_ac_report_code' => $reportCode,
      'field_ac_strategy_index' => $strategyIndex,
      'field_ac_target_keywords' => $item['targetKeywords'] ?? '',
      'field_ac_priority' => $item['priority'] ?? '',
    ];
    // Only set body if the field exists on the article content type.
    // Some bespoke installs have article without a body field.
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    if (isset($fields['body'])) {
      $values['body'] = [
        'value' => '<!-- AuditCrawl: body not yet written. Click "Generate now" if you have a premium license, or write it manually. -->',
        'format' => 'basic_html',
      ];
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Replace a stub's content with a generated draft payload. Called
   * from both the "Generate now" AJAX endpoint and hook_cron.
   */
  public static function fill(int $nid, array $draft): ?Node {
    $node = Node::load($nid);
    if (!$node || $node->bundle() !== 'article') return NULL;

    if (!empty($draft['title'])) {
      $node->setTitle(mb_substr($draft['title'], 0, 255));
    }
    // Defensive: if the admin's 'article' content type is missing the
    // body field for whatever reason, skip it rather than crashing.
    // SetupForm tries to guarantee body exists, but older or bespoke
    // installs may not have run through it.
    if ($node->hasField('body')) {
      $node->get('body')->setValue([
        'value' => $draft['body_html'] ?? '',
        'format' => 'full_html',
        'summary' => $draft['excerpt'] ?? '',
      ]);
    }

    if (!empty($draft['meta_description'])) {
      $node->set('field_ac_meta_description', mb_substr($draft['meta_description'], 0, 200));
    }
    $node->set('field_ac_generated_at', date('c'));
    if (!empty($draft['word_count'])) {
      $node->set('field_ac_word_count', (int) $draft['word_count']);
    }

    // Featured image: sideload the URL into the media library and
    // point node.field_image at it. Parallels the WP plugin's
    // media_sideload_image + set_post_thumbnail.
    if (!empty($draft['image_url']) && $node->hasField('field_image')) {
      $file = self::sideloadImage($draft['image_url'], $draft['title'] ?? 'AuditCrawl');
      if ($file) {
        $alt = $draft['title'] ?? '';
        $node->set('field_image', [
          'target_id' => $file->id(),
          'alt' => mb_substr($alt, 0, 200),
        ]);
      }
    }

    $node->save();
    return $node;
  }

  /**
   * Download a URL into Drupal's public files and return the File
   * entity. Returns NULL on any failure so callers can degrade
   * gracefully (no featured image is better than a broken one).
   */
  protected static function sideloadImage(string $url, string $alt): ?File {
    try {
      $data = @file_get_contents($url);
      if (!$data) return NULL;
      $filename = 'auditcrawl-' . substr(md5($url), 0, 8) . '.jpg';
      $fs = \Drupal::service('file_system');
      $dir = 'public://auditcrawl';
      $fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
      $path = $fs->saveData($data, $dir . '/' . $filename, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
      if (!$path) return NULL;
      $file = File::create(['uri' => $path, 'status' => 1]);
      $file->save();
      return $file;
    }
    catch (\Throwable $e) {
      \Drupal::logger('auditcrawl')->warning('Image sideload failed: @e', ['@e' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Ensure the custom fields we write to exist on the 'article'
   * content type. Called from SetupForm (after article type is
   * confirmed present) and from hook_install when article already
   * exists. Idempotent — safe to call repeatedly.
   */
  public static function installFields(): void {
    $fields = [
      'field_ac_report_code' => ['type' => 'string', 'label' => 'AuditCrawl report code'],
      'field_ac_strategy_index' => ['type' => 'integer', 'label' => 'AuditCrawl strategy index'],
      'field_ac_target_keywords' => ['type' => 'string_long', 'label' => 'AuditCrawl target keywords'],
      'field_ac_priority' => ['type' => 'string', 'label' => 'AuditCrawl priority'],
      'field_ac_meta_description' => ['type' => 'string_long', 'label' => 'AuditCrawl meta description'],
      'field_ac_generated_at' => ['type' => 'string', 'label' => 'AuditCrawl generated at'],
      'field_ac_word_count' => ['type' => 'integer', 'label' => 'AuditCrawl word count'],
    ];
    foreach ($fields as $field_name => $spec) {
      $storage = FieldStorageConfig::loadByName('node', $field_name);
      if (!$storage) {
        FieldStorageConfig::create([
          'field_name' => $field_name,
          'entity_type' => 'node',
          'type' => $spec['type'],
          'cardinality' => 1,
        ])->save();
      }
      $fieldConfig = FieldConfig::loadByName('node', 'article', $field_name);
      if (!$fieldConfig) {
        FieldConfig::create([
          'field_name' => $field_name,
          'entity_type' => 'node',
          'bundle' => 'article',
          'label' => $spec['label'],
          'translatable' => FALSE,
        ])->save();
      }
    }
  }

  /** True if the 'article' bundle exists AND has all our custom fields. */
  public static function isSetupComplete(): bool {
    if (!\Drupal\node\Entity\NodeType::load('article')) return FALSE;
    $required = [
      'field_ac_report_code',
      'field_ac_strategy_index',
      'field_ac_generated_at',
    ];
    foreach ($required as $field_name) {
      if (!FieldConfig::loadByName('node', 'article', $field_name)) return FALSE;
    }
    return TRUE;
  }

}
