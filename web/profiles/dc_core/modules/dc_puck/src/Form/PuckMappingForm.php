<?php

namespace Drupal\dc_puck\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dc_puck\Service\PuckMappingService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Puck component-to-paragraph mapping.
 */
class PuckMappingForm extends FormBase {

  public function __construct(
    protected PuckMappingService $mappingService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dc_puck.mapping'),
    );
  }

  public function getFormId(): string {
    return 'dc_puck_mapping_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $mapping = $this->mappingService->getMapping();

    $form['description'] = [
      '#markup' => '<p>This page shows how Puck editor component types map to Drupal paragraph types and fields. The mapping is auto-detected from your paragraph type definitions. You can edit the JSON below to customize the mapping.</p>',
    ];

    $form['editor_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Puck Editor URL'),
      '#default_value' => \Drupal::state()->get('dc_puck.editor_url', ''),
      '#description' => $this->t('The URL of your Puck editor app (e.g., http://localhost:3456 or https://puck.example.com).'),
      '#required' => TRUE,
    ];

    $form['sections_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sections Field Name'),
      '#default_value' => \Drupal::state()->get('dc_puck.sections_field', 'field_sections'),
      '#description' => $this->t('The machine name of the paragraph reference field on your content type (e.g., field_sections, field_components).'),
      '#required' => TRUE,
    ];

    $form['mapping_display'] = [
      '#type' => 'details',
      '#title' => $this->t('Component Mapping'),
      '#open' => TRUE,
    ];

    // Show a summary table of the current mapping.
    $header = [
      $this->t('Puck Component'),
      $this->t('Paragraph Type'),
      $this->t('Fields'),
    ];
    $rows = [];
    foreach ($mapping as $puckType => $config) {
      $fields = [];
      foreach ($config['fields'] as $puckProp => $fieldConfig) {
        $fields[] = "{$puckProp} → {$fieldConfig['drupal_field']} ({$fieldConfig['type']})";
      }
      $rows[] = [
        $puckType,
        $config['paragraph_type'],
        implode(', ', $fields),
      ];
    }

    $form['mapping_display']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No paragraph types found. Create paragraph types and fields first, then revisit this page.'),
    ];

    $form['mapping_json'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced: Edit Mapping JSON'),
      '#open' => FALSE,
    ];

    $form['mapping_json']['json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mapping JSON'),
      '#default_value' => json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
      '#rows' => 30,
      '#description' => $this->t('Edit the mapping JSON directly. Be careful — invalid JSON will break the mapping.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
    ];

    $form['actions']['regenerate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Regenerate from paragraph types'),
      '#submit' => ['::regenerateMapping'],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $json = $form_state->getValue('json');
    if (!empty($json)) {
      $decoded = json_decode($json, TRUE);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('json', $this->t('Invalid JSON: @error', [
          '@error' => json_last_error_msg(),
        ]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Save settings.
    \Drupal::state()->set('dc_puck.editor_url', $form_state->getValue('editor_url'));
    \Drupal::state()->set('dc_puck.sections_field', $form_state->getValue('sections_field'));

    // Save mapping JSON.
    $json = $form_state->getValue('json');
    if (!empty($json)) {
      $decoded = json_decode($json, TRUE);
      if ($decoded !== NULL) {
        $this->mappingService->setMapping($decoded);
      }
    }

    $this->messenger()->addStatus($this->t('Puck editor configuration saved.'));
  }

  /**
   * Regenerate mapping from current paragraph types.
   */
  public function regenerateMapping(array &$form, FormStateInterface $form_state): void {
    // Clear the stored mapping to force regeneration.
    $this->mappingService->setMapping([]);
    // Trigger getMapping() which auto-generates from paragraph types.
    $this->mappingService->getMapping();
    $this->messenger()->addStatus($this->t('Mapping regenerated from paragraph type definitions.'));
  }

}
