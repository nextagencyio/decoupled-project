<?php

namespace Drupal\dc_canvas\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\dc_canvas\Service\ComponentSynchronizer;
use Drupal\dc_canvas\Service\ContentImporter;
use Drupal\dc_canvas\Service\FrontendManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoints the Decoupled.io dashboard uses to wire a frontend to Canvas.
 */
class SetupController extends ControllerBase {

  /**
   * Constructs a SetupController.
   */
  public function __construct(
    protected FrontendManager $frontendManager,
    protected ComponentSynchronizer $componentSynchronizer,
    protected ContentImporter $contentImporter,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('dc_canvas.frontend_manager'),
      $container->get('dc_canvas.component_synchronizer'),
      $container->get('dc_canvas.content_importer'),
      $container->get('state'),
    );
  }

  /**
   * Reports whether Canvas is available and what is connected to it.
   *
   * The dashboard probes this to tell Canvas tenants from older ones that
   * still use the Puck editor.
   */
  public function status(Request $request): JsonResponse {
    if ($denied = $this->deny($request)) {
      return $denied;
    }
    return new JsonResponse([
      'available' => TRUE,
      'frontends' => $this->frontendManager->getUrls(),
      'components' => count($this->entityTypeManager()->getStorage('component')->getQuery()->condition('id', 'js.', 'STARTS_WITH')->accessCheck(FALSE)->execute()),
      'pages' => (int) $this->entityTypeManager()->getStorage('canvas_page')->getQuery()->accessCheck(FALSE)->count()->execute(),
    ]);
  }

  /**
   * Connects a deployed frontend: register, sync components, import content.
   *
   * Body: { "frontend_url": "https://…", "content": { "content": [...] } }.
   * "content" is optional and is a starter content file; its landing pages
   * become Canvas pages. Call this once the frontend is live with its Drupal
   * credentials, because the component library is read from it.
   */
  public function setup(Request $request): JsonResponse {
    if ($denied = $this->deny($request)) {
      return $denied;
    }
    $data = json_decode($request->getContent(), TRUE);
    $frontend_url = is_array($data) && is_string($data['frontend_url'] ?? NULL) ? trim($data['frontend_url']) : '';
    if ($frontend_url === '') {
      return new JsonResponse(['error' => 'Missing frontend_url'], 400);
    }

    try {
      $this->frontendManager->setPrimary($frontend_url);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => 'Invalid frontend_url: ' . $e->getMessage()], 400);
    }
    $result = ['success' => FALSE, 'frontend_registered' => TRUE, 'components' => NULL, 'pages' => NULL];

    // The library comes from the frontend itself, so this fails until the
    // frontend is deployed with credentials for this site. The caller can
    // retry; registering twice is harmless.
    try {
      // fetch_url is for local development, where the server reaches the
      // frontend at a different address than editors do.
      $fetch_url = is_string($data['fetch_url'] ?? NULL) ? $data['fetch_url'] : NULL;
      $result['components'] = $this->componentSynchronizer->sync($fetch_url);
    }
    catch (\Throwable $e) {
      $this->getLogger('dc_canvas')->warning('Component sync from @url failed: @message', ['@url' => $frontend_url, '@message' => $e->getMessage()]);
      $result['components'] = ['error' => $e->getMessage()];
      return new JsonResponse($result, 502);
    }

    if (is_array($data['content'] ?? NULL)) {
      $result['pages'] = $this->contentImporter->import($data['content']);
    }
    $result['success'] = TRUE;
    return new JsonResponse($result);
  }

  /**
   * Returns an error response unless the request carries the space token.
   */
  protected function deny(Request $request): ?JsonResponse {
    if ($this->state->get('dc_config.skip_auth', FALSE)) {
      return NULL;
    }
    $token = (string) $request->headers->get('X-Decoupled-Token');
    $expected = (string) $this->state->get('dc_import.space_auth_token');
    if ($token === '') {
      return new JsonResponse(['error' => 'Missing token'], 401);
    }
    if ($expected === '' || !hash_equals($expected, $token)) {
      return new JsonResponse(['error' => 'Invalid token'], 403);
    }
    return NULL;
  }

}
