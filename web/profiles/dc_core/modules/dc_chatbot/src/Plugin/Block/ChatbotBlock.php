<?php

namespace Drupal\dc_chatbot\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Chatbot' Block.
 *
 * @Block(
 *   id = "dc_chatbot_block",
 *   admin_label = @Translation("Decoupled Drupal Chatbot"),
 *   category = @Translation("Decoupled"),
 * )
 */
class ChatbotBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ChatbotBlock object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'button_text' => $this->t('Chat with us'),
      'button_position' => 'bottom-right',
      'button_color' => '#007cba',
      'show_on_mobile' => TRUE,
      'trigger_delay' => 0,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $form['button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Button Text'),
      '#description' => $this->t('The text displayed on the chatbot trigger button.'),
      '#default_value' => $config['button_text'],
      '#required' => TRUE,
      '#maxlength' => 50,
    ];

    $form['button_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Button Position'),
      '#description' => $this->t('Where to position the chatbot trigger button on the page.'),
      '#default_value' => $config['button_position'],
      '#options' => [
        'bottom-right' => $this->t('Bottom Right'),
        'bottom-left' => $this->t('Bottom Left'),
        'top-right' => $this->t('Top Right'),
        'top-left' => $this->t('Top Left'),
      ],
    ];

    $form['button_color'] = [
      '#type' => 'color',
      '#title' => $this->t('Button Color'),
      '#description' => $this->t('The background color of the chatbot trigger button.'),
      '#default_value' => $config['button_color'],
    ];

    $form['show_on_mobile'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show on Mobile'),
      '#description' => $this->t('Display the chatbot on mobile devices.'),
      '#default_value' => $config['show_on_mobile'],
    ];

    $form['trigger_delay'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-trigger Delay (seconds)'),
      '#description' => $this->t('Automatically open the chatbot after this many seconds. Set to 0 to disable.'),
      '#default_value' => $config['trigger_delay'],
      '#min' => 0,
      '#max' => 300,
      '#step' => 1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $this->configuration['button_text'] = $form_state->getValue('button_text');
    $this->configuration['button_position'] = $form_state->getValue('button_position');
    $this->configuration['button_color'] = $form_state->getValue('button_color');
    $this->configuration['show_on_mobile'] = $form_state->getValue('show_on_mobile');
    $this->configuration['trigger_delay'] = $form_state->getValue('trigger_delay');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->configFactory->get('dc_chatbot.settings');
    $block_config = $this->getConfiguration();

    // Don't render if chatbot is disabled
    if (!$config->get('enabled', FALSE)) {
      return [];
    }

    // Don't render if API key is not configured (check environment variable)
    $apiKey = getenv('CHATBOT_API_KEY');
    if (empty($apiKey)) {
      return [];
    }

    return [
      '#theme' => 'dc_chatbot_block',
      '#button_text' => $block_config['button_text'],
      '#button_position' => $block_config['button_position'],
      '#button_color' => $block_config['button_color'],
      '#show_on_mobile' => $block_config['show_on_mobile'],
      '#trigger_delay' => $block_config['trigger_delay'],
      '#welcome_message' => 'Hello! I\'m your Decoupled Drupal assistant. How can I help you today?',
      '#attached' => [
        'library' => [
          'dc_chatbot/chatbot',
        ],
        'drupalSettings' => [
          'decoupledChatbot' => [
            'enabled' => TRUE,
            'buttonPosition' => $block_config['button_position'],
            'buttonColor' => $block_config['button_color'],
            'showOnMobile' => $block_config['show_on_mobile'],
            'triggerDelay' => $block_config['trigger_delay'] * 1000, // Convert to milliseconds
            'welcomeMessage' => 'Hello! I\'m your Decoupled Drupal assistant. How can I help you today?',
            'spaceId' => $this->getSpaceId(),
            'nextjsApiUrl' => $this->getNextjsApiUrl(),
          ],
        ],
      ],
      '#cache' => [
        'tags' => ['config:dc_chatbot.settings'],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'use decoupled chatbot');
  }

  /**
   * Get the space ID for this site.
   *
   * This method attempts to identify the current space/site ID
   * that can be used to map to the Next.js dashboard.
   */
  protected function getSpaceId() {
    // Try to get from environment variable or settings
    $space_id = getenv('DECOUPLED_SPACE_ID');
    if ($space_id) {
      return $space_id;
    }

    // Try to extract from the site's base URL or hostname
    $request = \Drupal::request();
    $host = $request->getHttpHost();

    // If using subdomain pattern (e.g., spacename.domain.com)
    $parts = explode('.', $host);
    if (count($parts) >= 3) {
      return $parts[0]; // Return subdomain as space ID
    }

    // Fallback: use the full hostname
    return $host;
  }

  /**
   * Get the Next.js API URL.
   */
  protected function getNextjsApiUrl() {
    // Check for environment variable first
    $api_url = getenv('NEXTJS_API_URL');
    if ($api_url) {
      return rtrim($api_url, '/');
    }

    // Check for site-specific configuration
    $config = $this->configFactory->get('dc_chatbot.settings');
    $configured_url = $config->get('api_url');
    if ($configured_url) {
      return rtrim($configured_url, '/');
    }

    // Development/local fallback
    $request = \Drupal::request();
    $host = $request->getHttpHost();

    if (strpos($host, 'localhost') !== FALSE || strpos($host, '127.0.0.1') !== FALSE || strpos($host, '.ddev.site') !== FALSE) {
      return 'http://host.docker.internal:3333';
    }

    // Production fallback - determine dashboard URL based on current domain
    $parts = explode('.', $host);
    if (count($parts) >= 2) {
      // Extract root domain (e.g., from site.decoupled.dev -> decoupled.dev)
      $root_domain = implode('.', array_slice($parts, 1));
      return 'https://dashboard.' . $root_domain;
    }

    return 'https://dashboard.drupalcloud.com/api/chatbot';
  }

}
