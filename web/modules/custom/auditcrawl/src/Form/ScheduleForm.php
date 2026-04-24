<?php

namespace Drupal\auditcrawl\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Premium-scheduling settings form — mirror of the WP plugin's
 * render_schedule_page().
 *
 * On save we just persist the license key. The "Rotate / Move /
 * Generate now / Stripe portal" buttons below are rendered as
 * plain DOM elements so the branded admin.js can wire them to our
 * AJAX endpoints (same UX as the WP plugin — Drupal's #ajax
 * system would work too but the WP-style toast/modal is more
 * consistent with the rest of the module).
 */
class ScheduleForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['auditcrawl.settings'];
  }

  public function getFormId() {
    return 'auditcrawl_schedule_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('auditcrawl.settings');
    $licenseKey = (string) $config->get('license_key');

    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $entitlement = $licenseKey ? $client->validateLicense() : NULL;

    // Branded header + credit pill.
    $pill = '';
    if ($entitlement && $entitlement['ok']) {
      $credits = (int) ($entitlement['data']['license']['contentCredits'] ?? 0);
      $plan = $entitlement['data']['license']['plan'] ?? '';
      $pill = '<span class="auditcrawl-pill"><strong>' . $credits . '</strong> drafts left · ' . htmlspecialchars($plan) . '</span>';
    }
    elseif ($licenseKey) {
      $pill = '<span class="auditcrawl-pill auditcrawl-pill--muted">License inactive</span>';
    }
    else {
      $pill = '<span class="auditcrawl-pill auditcrawl-pill--muted">No license yet</span>';
    }
    $form['header'] = [
      '#markup' => '
        <div class="auditcrawl-header">
          <div class="auditcrawl-header__brand">
            <div class="auditcrawl-header__icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="m8 11 2 2 4-4"></path><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
            </div>
            <div>
              <h1 class="auditcrawl-header__title">Premium scheduling</h1>
              <p class="auditcrawl-header__subtitle">Auto-write draft nodes with AI on a schedule. <a href="https://auditcrawl.com/wordpress" target="_blank" rel="noopener">Learn more →</a></p>
            </div>
          </div>
          ' . $pill . '
        </div>
      ',
      '#allowed_tags' => ['div', 'h1', 'p', 'a', 'span', 'strong', 'code', 'svg', 'path', 'circle'],
    ];

    $form['license_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('License key'),
      '#default_value' => $licenseKey,
      '#placeholder' => '00000000-0000-0000-0000-000000000000',
      '#description' => $this->t('Paste the license key from your subscription email.'),
      '#maxlength' => 64,
    ];

    if ($entitlement && $entitlement['ok']) {
      $form['manage'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['auditcrawl-manage-actions']],
        'description' => [
          '#markup' => '<p class="description">' . $this->t('Rotate your key if you suspect it&apos;s been leaked. Move your license if you&apos;re switching this license to a different Drupal install (one move per 25 days).') . '</p>',
        ],
        'portal' => [
          '#markup' => '<button type="button" class="button" data-auditcrawl-action="open-portal">Manage subscription on Stripe →</button> ',
        ],
        'rotate' => [
          '#markup' => '<button type="button" class="button" data-auditcrawl-action="rotate-license">Rotate license key</button> ',
        ],
        'move' => [
          '#markup' => '<button type="button" class="button" data-auditcrawl-action="move-license">Move to this site</button>',
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('auditcrawl.settings')
      ->set('license_key', trim((string) $form_state->getValue('license_key')))
      ->save();
    $this->messenger()->addStatus($this->t('License saved.'));
  }

}
