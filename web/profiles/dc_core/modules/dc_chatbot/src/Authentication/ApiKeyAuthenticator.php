<?php

namespace Drupal\dc_chatbot\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * API Key authentication provider.
 */
class ApiKeyAuthenticator implements AuthenticationProviderInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new ApiKeyAuthenticator object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request) {
    return $request->headers->has('X-API-Key') || $request->query->has('api_key');
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request) {
    $api_key = $request->headers->get('X-API-Key') ?: $request->query->get('api_key');
    
    if (empty($api_key)) {
      return NULL;
    }

    $config = $this->configFactory->get('dc_chatbot.settings');
    $configured_key = $config->get('api_key');

    if (empty($configured_key)) {
      $this->loggerFactory->get('dc_chatbot')->warning('API key authentication attempted but no key configured');
      return NULL;
    }

    if (hash_equals($configured_key, $api_key)) {
      // Return anonymous user for API key authentication
      return \Drupal::entityTypeManager()->getStorage('user')->load(0);
    }

    $this->loggerFactory->get('dc_chatbot')->warning('Invalid API key attempted: @key', [
      '@key' => substr($api_key, 0, 8) . '...',
    ]);

    return NULL;
  }

}