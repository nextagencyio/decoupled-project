<?php

/**
 * @file
 * Drush script to test AI model content generation and import.
 * 
 * Usage: drush scr web/modules/custom/dc_chatbot/scripts/test-ai-import.php
 */

use Drupal\dc_chatbot\Service\ChatbotService;
use Drupal\dc_import\Service\DrupalContentImporter;

echo "🤖 Testing AI Model Content Generation and Import\n";
echo "================================================\n\n";

// Test description
$test_description = "product catalog with name, price, category, and description";
echo "Test description: '$test_description'\n\n";

try {
  // Step 1: Get ChatbotService
  echo "1️⃣ Getting ChatbotService...\n";
  $chatbot_service = \Drupal::service('dc_chatbot.chatbot_service');
  
  if (!$chatbot_service) {
    throw new Exception('ChatbotService not available');
  }
  echo "✅ ChatbotService loaded\n\n";

  // Step 2: Call AI to generate content model
  echo "2️⃣ Calling AI to generate content model...\n";
  $context = [
    'mode' => 'model-content',
    'spaceId' => 'test-drush',
    'timestamp' => time()
  ];
  
  $ai_response = $chatbot_service->processMessage($test_description, $context);
  echo "AI Response length: " . strlen($ai_response) . " characters\n";
  echo "AI Response preview: " . substr($ai_response, 0, 200) . "...\n\n";

  // Step 3: Extract JSON from response
  echo "3️⃣ Extracting JSON configuration...\n";
  if (preg_match('/```json\s*([\s\S]*?)\s*```/', $ai_response, $matches)) {
    $json_config = json_decode($matches[1], TRUE);
    
    if (json_last_error() === JSON_ERROR_NONE) {
      echo "✅ JSON extracted and parsed successfully\n";
      echo "Model entities: " . count($json_config['model'] ?? []) . "\n";
      echo "Content entries: " . count($json_config['content'] ?? []) . "\n";
      
      // Show the bundle names
      $bundles = [];
      foreach ($json_config['model'] ?? [] as $model) {
        $bundles[] = $model['bundle'] ?? 'unknown';
      }
      echo "Bundle names: " . implode(', ', $bundles) . "\n\n";
    } else {
      throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
  } else {
    throw new Exception('No JSON found in AI response');
  }

  // Step 4: Import using dc_import service
  echo "4️⃣ Importing content model...\n";
  $importer = \Drupal::service('dc_import.importer');
  
  if (!$importer) {
    throw new Exception('DrupalContentImporter service not available');
  }
  
  $import_result = $importer->import($json_config, FALSE);
  
  echo "✅ Import completed\n";
  echo "Operations: " . count($import_result['summary'] ?? []) . "\n";
  echo "Warnings: " . count($import_result['warnings'] ?? []) . "\n\n";
  
  // Show import results
  if (!empty($import_result['summary'])) {
    echo "Import Summary:\n";
    foreach ($import_result['summary'] as $summary) {
      echo "  • $summary\n";
    }
    echo "\n";
  }
  
  if (!empty($import_result['warnings'])) {
    echo "⚠️ Warnings:\n";
    foreach ($import_result['warnings'] as $warning) {
      echo "  • $warning\n";
    }
    echo "\n";
  }

  // Step 5: Verify the content types were created
  echo "5️⃣ Verifying created content types...\n";
  $entity_type_manager = \Drupal::entityTypeManager();
  
  foreach ($bundles as $bundle) {
    // Check if node type exists
    $node_type = $entity_type_manager->getStorage('node_type')->load($bundle);
    if ($node_type) {
      echo "✅ Content type '$bundle' exists: " . $node_type->label() . "\n";
      
      // Check fields
      $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', $bundle);
      $custom_fields = array_filter($fields, function($field) {
        return strpos($field->getName(), 'field_') === 0;
      });
      
      echo "   Fields: " . count($custom_fields) . " custom fields\n";
      foreach ($custom_fields as $field) {
        echo "     • " . $field->getLabel() . " (" . $field->getType() . ")\n";
      }
    } else {
      echo "❌ Content type '$bundle' not found\n";
    }
  }
  
  // Step 6: Check for created content
  echo "\n6️⃣ Verifying sample content...\n";
  $created_nodes = [];
  foreach ($import_result['summary'] ?? [] as $summary) {
    if (preg_match('/Created node: (.+) \(ID: (\d+), type: (.+)\)/', $summary, $matches)) {
      $created_nodes[] = [
        'title' => $matches[1],
        'nid' => $matches[2],
        'type' => $matches[3]
      ];
    }
  }
  
  foreach ($created_nodes as $node_info) {
    $node = $entity_type_manager->getStorage('node')->load($node_info['nid']);
    if ($node) {
      echo "✅ Sample content exists: '{$node_info['title']}' (ID: {$node_info['nid']})\n";
      echo "   URL: /node/{$node_info['nid']}/edit\n";
    } else {
      echo "❌ Sample content missing: {$node_info['title']}\n";
    }
  }

  echo "\n🎉 Test completed successfully!\n";
  echo "Summary: AI generated and imported content type with " . count($bundles) . " bundle(s) and " . count($created_nodes) . " sample node(s).\n";

} catch (Exception $e) {
  echo "\n❌ Test failed: " . $e->getMessage() . "\n";
  echo "Trace: " . $e->getTraceAsString() . "\n";
  exit(1);
}