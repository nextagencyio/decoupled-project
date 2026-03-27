<?php

namespace Drupal\dc_puck\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Maps Puck editor JSON to/from Drupal paragraph entities.
 *
 * The mapping config (stored in state) defines how each Puck component type
 * maps to a paragraph bundle and which Puck props map to which Drupal fields.
 */
class PuckMappingService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StateInterface $state,
  ) {}

  /**
   * Get the component mapping configuration.
   *
   * Returns an array keyed by Puck component type:
   * [
   *   'Hero' => [
   *     'paragraph_type' => 'hero',
   *     'fields' => [
   *       'title' => ['drupal_field' => 'field_title', 'type' => 'string'],
   *       'subtitle' => ['drupal_field' => 'field_subtitle', 'type' => 'text'],
   *     ],
   *   ],
   * ]
   */
  public function getMapping(): array {
    $mapping = $this->state->get('dc_puck.component_map', []);
    if (empty($mapping)) {
      $mapping = $this->buildDefaultMapping();
      $this->state->set('dc_puck.component_map', $mapping);
    }
    return $mapping;
  }

  /**
   * Save the component mapping configuration.
   */
  public function setMapping(array $mapping): void {
    $this->state->set('dc_puck.component_map', $mapping);
  }

  /**
   * Load a node's paragraphs and transform to Puck JSON data.
   */
  public function loadPuckData(NodeInterface $node): array {
    $mapping = $this->getMapping();
    $reverseMap = $this->buildReverseMap($mapping);

    $content = [];
    if ($node->hasField('field_sections')) {
      foreach ($node->get('field_sections')->referencedEntities() as $paragraph) {
        $bundle = $paragraph->bundle();
        if (!isset($reverseMap[$bundle])) {
          continue;
        }

        $puckType = $reverseMap[$bundle]['puck_type'];
        $fieldMap = $reverseMap[$bundle]['fields'];

        $props = [
          'id' => $puckType . '-' . substr($paragraph->uuid(), 0, 8),
          '_drupalUuid' => $paragraph->uuid(),
          '_drupalRevisionId' => $paragraph->getRevisionId(),
        ];

        foreach ($fieldMap as $drupalField => $puckProp) {
          if (!$paragraph->hasField($drupalField)) {
            continue;
          }
          $fieldItem = $paragraph->get($drupalField)->first();
          if (!$fieldItem) {
            $props[$puckProp['prop']] = '';
            continue;
          }

          if ($puckProp['type'] === 'text') {
            $props[$puckProp['prop']] = $fieldItem->value ?? '';
          }
          else {
            $props[$puckProp['prop']] = $fieldItem->value ?? '';
          }
        }

        $content[] = [
          'type' => $puckType,
          'props' => $props,
        ];
      }
    }

    return [
      'content' => $content,
      'root' => [
        'props' => [
          'title' => $node->getTitle(),
        ],
      ],
      'zones' => new \stdClass(),
    ];
  }

  /**
   * Transform Puck JSON data and save as paragraphs on a node.
   */
  public function savePuckData(NodeInterface $node, array $puckData): void {
    $mapping = $this->getMapping();
    $paragraphStorage = $this->entityTypeManager->getStorage('paragraph');

    // Track existing paragraphs by UUID for update/delete detection.
    $existingParagraphs = [];
    if ($node->hasField('field_sections')) {
      foreach ($node->get('field_sections')->referencedEntities() as $paragraph) {
        $existingParagraphs[$paragraph->uuid()] = $paragraph;
      }
    }

    $usedUuids = [];
    $newSections = [];

    foreach ($puckData['content'] ?? [] as $component) {
      $puckType = $component['type'] ?? '';
      $props = $component['props'] ?? [];

      if (!isset($mapping[$puckType])) {
        continue;
      }

      $componentMap = $mapping[$puckType];
      $paragraphType = $componentMap['paragraph_type'];
      $fieldMap = $componentMap['fields'];

      // Check if this is an existing paragraph (has _drupalUuid).
      $drupalUuid = $props['_drupalUuid'] ?? NULL;
      $paragraph = NULL;

      if ($drupalUuid && isset($existingParagraphs[$drupalUuid])) {
        // Update existing paragraph.
        $paragraph = $existingParagraphs[$drupalUuid];
        $usedUuids[] = $drupalUuid;
      }
      else {
        // Create new paragraph.
        $paragraph = $paragraphStorage->create(['type' => $paragraphType]);
      }

      // Map Puck props to Drupal fields.
      foreach ($fieldMap as $puckProp => $fieldConfig) {
        $drupalField = $fieldConfig['drupal_field'];
        $fieldType = $fieldConfig['type'];
        $value = $props[$puckProp] ?? '';

        if (!$paragraph->hasField($drupalField)) {
          continue;
        }

        if ($fieldType === 'text') {
          $paragraph->set($drupalField, [
            'value' => $value,
            'format' => 'basic_html',
          ]);
        }
        else {
          $paragraph->set($drupalField, $value);
        }
      }

      $paragraph->save();
      $newSections[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    // Delete paragraphs that are no longer referenced.
    foreach ($existingParagraphs as $uuid => $paragraph) {
      if (!in_array($uuid, $usedUuids)) {
        $paragraph->delete();
      }
    }

    // Update node sections.
    $node->set('field_sections', $newSections);
    $node->save();
  }

  /**
   * Build a reverse map: paragraph_bundle => puck_type + field mappings.
   */
  protected function buildReverseMap(array $mapping): array {
    $reverse = [];
    foreach ($mapping as $puckType => $config) {
      $bundle = $config['paragraph_type'];
      $fields = [];
      foreach ($config['fields'] as $puckProp => $fieldConfig) {
        $drupalField = $fieldConfig['drupal_field'];
        $fields[$drupalField] = [
          'prop' => $puckProp,
          'type' => $fieldConfig['type'],
        ];
      }
      $reverse[$bundle] = [
        'puck_type' => $puckType,
        'fields' => $fields,
      ];
    }
    return $reverse;
  }

  /**
   * Auto-detect paragraph types and build a default mapping.
   *
   * Scans all paragraph types that are referenced by a field_sections field
   * and maps field_* fields to camelCase Puck props.
   */
  protected function buildDefaultMapping(): array {
    $mapping = [];
    $paragraphTypeStorage = $this->entityTypeManager->getStorage('paragraphs_type');
    $fieldConfigStorage = $this->entityTypeManager->getStorage('field_config');

    foreach ($paragraphTypeStorage->loadMultiple() as $type) {
      $bundle = $type->id();
      $label = $type->label();

      // Convert bundle to PascalCase Puck type name.
      $puckType = str_replace(' ', '', ucwords(str_replace('_', ' ', $bundle)));

      $fields = [];
      // Load all field instances for this paragraph type.
      $fieldConfigs = $fieldConfigStorage->loadByProperties([
        'entity_type' => 'paragraph',
        'bundle' => $bundle,
      ]);

      foreach ($fieldConfigs as $fieldConfig) {
        $fieldName = $fieldConfig->getName();
        if (!str_starts_with($fieldName, 'field_')) {
          continue;
        }

        // Convert field_background_color to backgroundColor.
        $puckProp = $this->fieldNameToCamelCase($fieldName);
        $fieldType = $fieldConfig->getType();

        // Determine if this is a text (formatted) or string (plain) field.
        $type = in_array($fieldType, ['text_long', 'text_with_summary']) ? 'text' : 'string';

        $fields[$puckProp] = [
          'drupal_field' => $fieldName,
          'type' => $type,
          'label' => $fieldConfig->getLabel(),
        ];
      }

      if (!empty($fields)) {
        $mapping[$puckType] = [
          'paragraph_type' => $bundle,
          'label' => $label,
          'fields' => $fields,
        ];
      }
    }

    return $mapping;
  }

  /**
   * Convert a Drupal field name to a camelCase Puck prop name.
   * field_background_color => backgroundColor
   * field_primary_cta_text => primaryCtaText
   */
  protected function fieldNameToCamelCase(string $fieldName): string {
    // Remove field_ prefix.
    $name = preg_replace('/^field_/', '', $fieldName);
    // Convert snake_case to camelCase.
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
  }

}
