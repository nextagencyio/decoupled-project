<?php

namespace Drupal\auditcrawl\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\NodeType;

/**
 * One-time setup prompt. Only shown if the `article` content type
 * is missing or our custom fields haven't been installed. When the
 * admin confirms, we create a stock 'Article' content type (title +
 * body + revisions, preview disabled) and install our custom
 * fields on it.
 */
class SetupForm extends FormBase {

  public function getFormId() {
    return 'auditcrawl_setup_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $articleExists = (bool) NodeType::load('article');

    // Branded header so this screen doesn't feel like a fallback.
    $form['header'] = [
      '#markup' => '
        <div class="auditcrawl-header">
          <div class="auditcrawl-header__brand">
            <div class="auditcrawl-header__icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="m8 11 2 2 4-4"></path><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
            </div>
            <div>
              <h1 class="auditcrawl-header__title">One-time setup</h1>
              <p class="auditcrawl-header__subtitle">' .
                ($articleExists
                  ? 'Add a few fields to your existing <code>article</code> content type so we can store AuditCrawl metadata on each draft.'
                  : 'AuditCrawl creates drafts as <code>article</code> nodes, but your site doesn&apos;t have an Article content type yet. Create one now?') .
              '</p>
            </div>
          </div>
        </div>
      ',
      '#allowed_tags' => ['div', 'h1', 'p', 'code', 'svg', 'path', 'circle', 'strong'],
    ];

    if ($articleExists) {
      $form['summary'] = [
        '#markup' => '<p>We&apos;ll add these fields to <code>article</code>: report code, strategy index, target keywords, priority, meta description, generated timestamp, word count. No existing articles are modified.</p>',
        '#allowed_tags' => ['p', 'code', 'strong'],
      ];
    }
    else {
      $form['summary'] = [
        '#markup' => '
          <ul style="margin-left: 20px; list-style: disc;">
            <li><strong>Article</strong> content type (machine name <code>article</code>)</li>
            <li>Title + body fields</li>
            <li>Revisions enabled</li>
            <li>Preview disabled</li>
            <li>Plus our own fields for report code, target keywords, and generated metadata</li>
          </ul>
          <p style="margin-top: 1em;"><em>Nothing is removed. If you already had an <code>article</code> content type, we&apos;d have reused it.</em></p>
        ',
        '#allowed_tags' => ['ul', 'li', 'p', 'code', 'strong', 'em'],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $articleExists ? $this->t('Add our fields') : $this->t('Yes, create the Article content type'),
        '#button_type' => 'primary',
      ],
      'cancel' => [
        '#type' => 'link',
        '#title' => $this->t('Cancel'),
        '#url' => Url::fromRoute('system.admin'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $createdType = FALSE;
    if (!NodeType::load('article')) {
      NodeType::create([
        'type' => 'article',
        'name' => 'Article',
        'description' => 'Use articles for time-sensitive content like news, press releases, or blog posts. Created by AuditCrawl as part of first-time setup.',
        'new_revision' => TRUE,
        // DRUPAL_DISABLED = 0 → preview disabled on the edit form.
        'preview_mode' => 0,
        'display_submitted' => TRUE,
      ])->save();
      $createdType = TRUE;
    }

    // Ensure the 'body' field exists on article. Doing this manually
    // rather than via node_add_body_field() because:
    //   - It's deprecated in Drupal 11.3+ (removed in 12).
    //   - It requires the node:body FieldStorageConfig to already
    //     exist (from the Standard profile); decoupled install
    //     profiles like dc_core don't ship it.
    if (!\Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'body')) {
      \Drupal\field\Entity\FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'cardinality' => 1,
      ])->save();
    }
    if (!\Drupal\field\Entity\FieldConfig::loadByName('node', 'article', 'body')) {
      \Drupal\field\Entity\FieldConfig::create([
        'field_storage' => \Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'body'),
        'bundle' => 'article',
        'label' => 'Body',
        'settings' => ['display_summary' => TRUE, 'allowed_formats' => []],
      ])->save();
      \Drupal::service('entity_display.repository')->getFormDisplay('node', 'article', 'default')
        ->setComponent('body', ['type' => 'text_textarea_with_summary'])->save();
    }

    // Invalidate the bundle-info cache so FieldConfig::create below
    // sees the newly-created 'article' bundle. Without this, field
    // creation intermittently fails with a "non-existent config
    // entity" warning because the entity-type manager is still
    // holding the pre-create snapshot.
    \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    \Drupal\auditcrawl\Service\NodeWriter::installFields();

    if ($createdType) {
      $this->messenger()->addStatus($this->t('Article content type created + AuditCrawl fields installed.'));
    }
    else {
      $this->messenger()->addStatus($this->t('AuditCrawl fields installed on your existing Article content type.'));
    }
    $form_state->setRedirect('auditcrawl.report');
  }

}
