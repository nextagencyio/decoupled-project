<?php

namespace Drupal\auditcrawl\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * "Paste your report code" settings form — mirror of the WP plugin's
 * render_connect_page(). On save, validates the code against the
 * remote API; only persists it if the report loads cleanly so the
 * Report page can't end up pointing at a bogus code.
 */
class ConnectForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['auditcrawl.settings'];
  }

  public function getFormId() {
    return 'auditcrawl_connect_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('auditcrawl.settings');
    $current = (string) $config->get('report_code');

    // Branded header — parallels render_header() in the WP plugin.
    // `#allowed_tags` is required or Drupal's default Xss::filterAdmin
    // strips the inline <svg> icon (and <path>/<circle>).
    $form['header'] = [
      '#markup' => '
        <div class="auditcrawl-header">
          <div class="auditcrawl-header__brand">
            <div class="auditcrawl-header__icon" aria-hidden="true">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" width="20" height="20"><path d="m8 11 2 2 4-4"></path><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"></path></svg>
            </div>
            <div>
              <h1 class="auditcrawl-header__title">Connect a report</h1>
              <p class="auditcrawl-header__subtitle">' . ($current
                ? 'Connected as <code>' . htmlspecialchars($current) . '</code>'
                : 'Link your paid auditcrawl.com report to this Drupal site.') . '</p>
            </div>
          </div>
        </div>
      ',
      '#allowed_tags' => ['div', 'h1', 'p', 'strong', 'a', 'span', 'code', 'svg', 'path', 'circle'],
    ];

    // Magic-link landing: ?token=... drops into the form pre-filled.
    $prefill = \Drupal::request()->query->get('token') ?: '';

    $form['report_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Report code or token'),
      '#default_value' => $prefill ?: $current,
      '#placeholder' => 'AC-XXXXXXX',
      '#description' => $this->t('From the report-ready email we sent. Paste either the code (<code>AC-XXXXXXX</code>) or the UUID token from the magic link.'),
      '#maxlength' => 64,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $code = trim((string) $form_state->getValue('report_code'));
    if ($code === '') {
      $form_state->setErrorByName('report_code', $this->t('Report code is required.'));
      return;
    }

    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->fetchReport($code);
    if (!$res['ok']) {
      $form_state->setErrorByName('report_code', $this->t('Could not load report: @e', ['@e' => $res['error']]));
      return;
    }
    // Stash the fetched hostname so submitForm can reuse it in the
    // success message without a second HTTP call.
    $form_state->setTemporaryValue('auditcrawl_hostname', $res['data']['hostname'] ?? '(unknown)');
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $code = trim((string) $form_state->getValue('report_code'));
    $this->config('auditcrawl.settings')
      ->set('report_code', $code)
      // Different report means different strategy items — clear the
      // stub map so we don't try to update nodes for the old report.
      ->set('stub_node_ids', [])
      ->save();

    $host = $form_state->getTemporaryValue('auditcrawl_hostname') ?: '(unknown)';
    $this->messenger()->addStatus($this->t('Connected to <strong>@h</strong>. Head to <a href="@u">the Report page</a> to start creating drafts.', [
      '@h' => $host,
      '@u' => \Drupal\Core\Url::fromRoute('auditcrawl.report')->toString(),
    ]));
    $form_state->setRedirect('auditcrawl.report');
  }

}
