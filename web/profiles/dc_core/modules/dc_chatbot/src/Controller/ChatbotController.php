<?php

namespace Drupal\dc_chatbot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dc_chatbot\Service\ChatbotService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for chatbot API endpoints.
 */
class ChatbotController extends ControllerBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The chatbot service.
   *
   * @var \Drupal\dc_chatbot\Service\ChatbotService
   */
  protected $chatbotService;

  /**
   * Constructs a new ChatbotController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\dc_chatbot\Service\ChatbotService $chatbot_service
   *   The chatbot service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ChatbotService $chatbot_service) {
    $this->configFactory = $config_factory;
    $this->chatbotService = $chatbot_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('dc_chatbot.chatbot_service')
    );
  }

  /**
   * Handles chat requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function chat(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);

      if (empty($data['message'])) {
        return new JsonResponse([
          'error' => 'Message is required',
        ], Response::HTTP_BAD_REQUEST);
      }

      // Check if this is a model content request
      if (!empty($data['mode']) && $data['mode'] === 'model-content') {
        return $this->handleModelContentRequest($data);
      }

      $response = $this->chatbotService->processMessage($data['message'], $data);

      return new JsonResponse([
        'response' => $response,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('dc_chatbot')->error('Chat error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Handle model content requests by calling Next.js API and importing results.
   */
  private function handleModelContentRequest(array $data) {
    try {
      // Get AI-generated configuration from Next.js API
      $aiResponse = $this->chatbotService->processMessage($data['message'], $data);
      
      // Extract JSON from AI response
      if (preg_match('/```json\s*([\s\S]*?)\s*```/', $aiResponse, $matches)) {
        $jsonConfig = json_decode($matches[1], TRUE);
        
        if (json_last_error() === JSON_ERROR_NONE && isset($jsonConfig['model'])) {
          // Import directly using the dc_import service
          $importer = \Drupal::service('dc_import.importer');
          $importResult = $importer->import($jsonConfig, FALSE);
          
          // Format the success message
          $response = $this->formatImportResult($importResult);
          
          return new JsonResponse([
            'response' => $response,
            'timestamp' => time(),
          ]);
        }
      }
      
      // Fallback: return AI response with manual import instructions
      $response = $aiResponse . "\n\n⚠️ **Configuration generated successfully!**\n\nYou can use the JSON configuration above to manually import via the [Import Form](/admin/config/content/dc-import).";
      
      return new JsonResponse([
        'response' => $response,
        'timestamp' => time(),
      ]);
    }
    catch (\Exception $e) {
      $this->getLogger('dc_chatbot')->error('Model content error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Error generating content model: ' . $e->getMessage(),
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }


  /**
   * Import configuration using dc_import service.
   */
  private function importConfiguration(array $config) {
    try {
      // Get the dc_import service
      $importer = \Drupal::service('dc_import.importer');

      // Perform the import
      $result = $importer->import($config, FALSE);

      return $result;
    }
    catch (\Exception $e) {
      throw new \Exception('Import failed: ' . $e->getMessage());
    }
  }

  /**
   * Format import result for user response.
   */
  private function formatImportResult(array $result) {
    $summary = $result['summary'] ?? [];
    $warnings = $result['warnings'] ?? [];
    $created_nodes = $result['created_nodes'] ?? [];

    if (empty($summary)) {
      return 'Configuration imported successfully!';
    }

    $response = "✅ **Content model created successfully!**\n\n";

    // Deduplicate summary items
    $unique_summary = array_unique($summary);

    // Extract node information from summary messages for later use
    $created_content = [];
    foreach ($unique_summary as $item) {
      // Look for "Created node: Title (ID: X, type: Y)" pattern
      if (preg_match('/Created node: (.+) \(ID: (\d+), type: (.+)\)/', $item, $matches)) {
        $title = $matches[1];
        $nid = $matches[2];
        $type = $matches[3];

        $created_content[] = [
          'title' => $title,
          'nid' => $nid,
          'type' => $type
        ];
      }
    }

    foreach ($unique_summary as $item) {
      $response .= "• {$item}\n";
    }

    if (!empty($warnings)) {
      $response .= "\n⚠️ **Warnings:**\n";
      // Deduplicate warnings too
      $unique_warnings = array_unique($warnings);
      foreach ($unique_warnings as $warning) {
        $response .= "• {$warning}\n";
      }
    }

    // Add links to created content at the end
    if (!empty($created_content)) {
      $response .= "\n📄 **Sample content created:**\n";
      foreach ($created_content as $node_info) {
        $title = $node_info['title'];
        $nid = $node_info['nid'];

        $edit_link = "/node/{$nid}/edit";
        $response .= "• [{$title}]({$edit_link})\n";
      }
    }

    return $response;
  }

  /**
   * Handles chatbot configuration requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function chatbotConfig(Request $request) {
    $config = $this->configFactory->get('dc_chatbot.settings');

    if ($request->isMethod('POST')) {
      $data = json_decode($request->getContent(), TRUE);

      if (isset($data['api_key'])) {
        $config_editable = $this->configFactory->getEditable('dc_chatbot.settings');
        $config_editable->set('api_key', $data['api_key']);
        // Auto-enable the chatbot when API key is configured
        $config_editable->set('enabled', TRUE);

        // Set Next.js API URL if provided
        if (isset($data['api_url'])) {
          $config_editable->set('api_url', $data['api_url']);
        }

        $config_editable->save();

        return new JsonResponse(['status' => 'API key updated and chatbot enabled']);
      }
    }

    return new JsonResponse([
      'enabled' => $config->get('enabled', FALSE),
      'api_key_configured' => !empty($config->get('api_key')),
    ]);
  }

}
