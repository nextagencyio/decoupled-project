<?php

namespace Drupal\dc_chatbot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Decoupled Drupal Chatbot settings.
 */
class ChatbotConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['dc_chatbot.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dc_chatbot_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('dc_chatbot.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Chatbot'),
      '#description' => $this->t('Check this box to enable the chatbot functionality.'),
      '#default_value' => $config->get('enabled', FALSE),
    ];

    // Show API key status from environment variable (read-only)
    $apiKey = getenv('CHATBOT_API_KEY');
    $apiKeyStatus = !empty($apiKey)
      ? $this->t('✅ Configured (from CHATBOT_API_KEY environment variable)')
      : $this->t('❌ Not configured - CHATBOT_API_KEY environment variable is not set');

    $form['api_key_status'] = [
      '#type' => 'item',
      '#title' => $this->t('API Key Status'),
      '#markup' => '<div class="description">' . $apiKeyStatus . '</div>',
      '#description' => $this->t('The API key is securely stored as an environment variable and cannot be edited through this form. Contact your system administrator if you need to update it.'),
    ];

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Decoupled Drupal Chatbot API URL'),
      '#description' => $this->t('The URL endpoint for communicating with Decoupled Drupal\'s centralized AI system. This connects your site\'s chatbot to our intelligent response engine.'),
      '#default_value' => $config->get('api_url', 'http://host.docker.internal:3333/api/chatbot'),
      '#maxlength' => 255,
      '#disabled' => TRUE,
      '#attributes' => ['readonly' => 'readonly'],
    ];


    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Only save the enabled checkbox - API key comes from environment variable
    // and API URL is read-only
    $this->config('dc_chatbot.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
