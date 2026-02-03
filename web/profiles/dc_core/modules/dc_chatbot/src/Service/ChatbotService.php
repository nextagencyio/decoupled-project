<?php

namespace Drupal\dc_chatbot\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for handling chatbot logic.
 */
class ChatbotService {

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
   * Constructs a new ChatbotService object.
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
   * Processes a chat message and returns a response.
   *
   * @param string $message
   *   The user message.
   * @param array $context
   *   Additional context data.
   *
   * @return string
   *   The chatbot response.
   */
  public function processMessage(string $message, array $context = []) {
    $config = $this->configFactory->get('dc_chatbot.settings');

    if (!$config->get('enabled', FALSE)) {
      return 'Chatbot is currently disabled.';
    }

    // Log the interaction
    $this->loggerFactory->get('dc_chatbot')->info('Chat message processed: @message', [
      '@message' => substr($message, 0, 100),
    ]);

    // Try to call the Next.js API first
    $apiError = null;
    try {
      $nextjsResponse = $this->callNextjsAPI($message, $context);
      if ($nextjsResponse) {
        return $nextjsResponse;
      }
    }
    catch (\Exception $e) {
      $apiError = $e->getMessage();
      $this->loggerFactory->get('dc_chatbot')->warning('Next.js API failed: @error', [
        '@error' => $apiError,
      ]);
    }

    // Return error message instead of fake response
    return $this->getConnectionErrorMessage($apiError);
  }

  /**
   * Returns a user-friendly error message when API connection fails.
   *
   * @param string|null $apiError
   *   The technical error message from the API call.
   *
   * @return string
   *   A user-friendly error message.
   */
  protected function getConnectionErrorMessage($apiError = null) {
    // Get API key from environment variable
    $apiKey = getenv('CHATBOT_API_KEY');

    // Check for specific error conditions
    if (empty($apiKey)) {
      return "⚠️ **Chatbot Configuration Issue**\n\nThe chatbot is not properly configured with an API key. Please contact your site administrator to configure the chatbot settings.";
    }

    // Rate limit exceeded
    if ($apiError && strpos($apiError, 'RATE_LIMIT_EXCEEDED') !== FALSE) {
      return "⚠️ **Daily Limit Reached**\n\nYou've reached your daily limit of AI chatbot requests. Your limit will reset tomorrow. Thank you for using the chatbot!";
    }

    if ($apiError && strpos($apiError, 'Failed to connect') !== FALSE) {
      return "⚠️ **Connection Issue**\n\nI'm currently unable to connect to the AI service. This might be a temporary network issue. Please try again in a moment, or contact support if the problem persists.";
    }

    if ($apiError && (strpos($apiError, 'Invalid API key') !== FALSE || strpos($apiError, 'not configured') !== FALSE)) {
      return "⚠️ **Configuration Issue**\n\nThere's a problem with the chatbot configuration. Please contact your site administrator to resolve this issue.";
    }

    // Generic error message
    return "⚠️ **Service Unavailable**\n\nI'm experiencing technical difficulties right now. Please try again later, or contact support if you need immediate assistance.";
  }

  /**
   * Calls the Next.js API to get a chatbot response.
   *
   * @param string $message
   *   The user message.
   * @param array $context
   *   Additional context data.
   *
   * @return string|null
   *   The response from Next.js API or NULL if failed.
   */
  protected function callNextjsAPI(string $message, array $context = []) {
    $config = $this->configFactory->get('dc_chatbot.settings');

    // Get Next.js API URL from configuration
    $nextjsApiUrl = $config->get('api_url');
    if (empty($nextjsApiUrl)) {
      // No fallback detection - require explicit configuration
      \Drupal::logger('dc_chatbot')->error('Next.js API URL is not configured. Please configure it in the chatbot settings.');
      throw new \Exception('Next.js API URL is not configured. Please configure the chatbot settings.');
    }

    // Extract space ID from context or derive from hostname
    $spaceId = $context['spaceId'] ?? '';
    if (empty($spaceId)) {
      $request = \Drupal::request();
      $host = $request->getHttpHost();
      $parts = explode('.', $host);
      if (count($parts) >= 3) {
        $spaceId = $parts[0]; // Use subdomain as space ID
      }
      else {
        $spaceId = $host;
      }
    }

    $apiUrl = rtrim($nextjsApiUrl, '/');

    $payload = [
      'message' => $message,
      'spaceId' => $spaceId,
      'context' => $context,
    ];

    // Forward mode parameter if present in context
    if (isset($context['mode'])) {
      $payload['mode'] = $context['mode'];
    }

    // Get the API key for authentication from environment variable
    // This is similar to how RESEND_API_KEY works for dc_mail
    $apiKey = getenv('CHATBOT_API_KEY');
    if (empty($apiKey)) {
      throw new \Exception('CHATBOT_API_KEY environment variable is not set.');
    }

    // Get the current request to determine origin
    $request = \Drupal::request();
    $scheme = $request->getScheme();
    $host = $request->getHttpHost();
    $origin = $scheme . '://' . $host;

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => [
          'Content-Type: application/json',
          'User-Agent: Decoupled-Chatbot/1.0',
          'X-API-Key: ' . $apiKey,
          'Origin: ' . $origin,
        ],
        'content' => json_encode($payload),
        'timeout' => 30,
      ],
    ];

    $streamContext = stream_context_create($options);
    $response = @file_get_contents($apiUrl, FALSE, $streamContext);

    if ($response === FALSE) {
      throw new \Exception('Failed to connect to Next.js API');
    }

    $data = json_decode($response, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception('Invalid JSON response from Next.js API');
    }

    if (isset($data['error'])) {
      throw new \Exception($data['error']);
    }

    return $data['response'] ?? NULL;
  }

}
