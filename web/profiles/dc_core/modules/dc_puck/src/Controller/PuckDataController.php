<?php

namespace Drupal\dc_puck\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dc_puck\Service\PuckMappingService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles loading and saving Puck editor data as Drupal paragraphs.
 *
 * GET /api/puck/load/{node} — Load paragraphs as Puck JSON.
 * POST /api/puck/save/{node} — Save Puck JSON as paragraphs.
 */
class PuckDataController extends ControllerBase {

  public function __construct(
    protected PuckMappingService $mappingService,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dc_puck.mapping'),
    );
  }

  /**
   * Load a node's paragraphs and return as Puck editor JSON.
   */
  public function load(NodeInterface $node): JsonResponse {
    if (!$node->hasField('field_sections')) {
      return new JsonResponse([
        'error' => 'Node does not have a sections field.',
      ], 400);
    }

    $puckData = $this->mappingService->loadPuckData($node);

    return new JsonResponse($puckData);
  }

  /**
   * Save Puck editor JSON as paragraphs on a node.
   */
  public function save(NodeInterface $node, Request $request): JsonResponse {
    if (!$node->hasField('field_sections')) {
      return new JsonResponse([
        'error' => 'Node does not have a sections field.',
      ], 400);
    }

    // Validate the signed token from the request.
    $token = $request->headers->get('X-Puck-Token', '');
    if (empty($token)) {
      $body = json_decode($request->getContent(), TRUE);
      $token = $body['_token'] ?? '';
    }

    if (!$this->validateToken($token, $node)) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $body = json_decode($request->getContent(), TRUE);
    if (empty($body) || !isset($body['content'])) {
      return new JsonResponse([
        'error' => 'Invalid Puck data. Expected { content: [...], root: {...} }',
      ], 400);
    }

    try {
      $this->mappingService->savePuckData($node, $body);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Page saved successfully.',
        'node' => [
          'nid' => (int) $node->id(),
          'changed' => $node->getChangedTime(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'error' => 'Save failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Return the component mapping configuration.
   */
  public function mapping(): JsonResponse {
    $mapping = $this->mappingService->getMapping();
    return new JsonResponse($mapping);
  }

  /**
   * Validate a signed Puck token for a specific node.
   */
  protected function validateToken(string $token, NodeInterface $node): bool {
    if (empty($token)) {
      return FALSE;
    }

    $decoded = base64_decode($token);
    if (!$decoded) {
      return FALSE;
    }

    $parts = explode(':', $decoded);
    if (count($parts) !== 4) {
      return FALSE;
    }

    [$uid, $nid, $timestamp, $hmac] = $parts;

    // Check expiry (8 hours).
    if (abs(time() - (int) $timestamp) > 28800) {
      return FALSE;
    }

    // Verify node ID matches.
    if ((int) $nid !== (int) $node->id()) {
      return FALSE;
    }

    // Verify HMAC.
    $secret = \Drupal::state()->get('dc_puck.token_secret', '');
    if (empty($secret)) {
      return FALSE;
    }

    $expectedHmac = hash_hmac('sha256', "{$uid}:{$nid}:{$timestamp}", $secret);
    return hash_equals($expectedHmac, $hmac);
  }

}
