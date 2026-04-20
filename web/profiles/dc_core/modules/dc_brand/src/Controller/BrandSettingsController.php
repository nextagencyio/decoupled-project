<?php

namespace Drupal\dc_brand\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\dc_brand\Service\BrandResolver;
use Drupal\dc_brand\Service\BuildHookDispatcher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON endpoint + deploy-now trigger for dc_brand.
 */
class BrandSettingsController extends ControllerBase implements ContainerInjectionInterface {

  // `protected`, not `private`/`readonly` — so Drupal's DependencySerialization
  // trait can see these during serialize/wake-up in the controller lifecycle.
  public function __construct(
    protected BrandResolver $resolver,
    protected BuildHookDispatcher $dispatcher,
    // `configFactory` collides with ControllerBase's own property, so we
    // accept the factory under a different name.
    protected ConfigFactoryInterface $config,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dc_brand.resolver'),
      $container->get('dc_brand.build_hook_dispatcher'),
      $container->get('config.factory'),
    );
  }

  /**
   * Return the resolved brand payload.
   *
   * The response is tagged with the dc_brand.settings config cache tags, so
   * any config save (form, drush, API) auto-invalidates Drupal's page cache
   * and the next fetch rebuilds from fresh state.
   */
  public function settings(): CacheableJsonResponse {
    $response = new CacheableJsonResponse($this->resolver->resolve());
    $metadata = new CacheableMetadata();
    $metadata->addCacheableDependency($this->config->get('dc_brand.settings'));
    $response->addCacheableDependency($metadata);
    $response->setPublic();
    $response->setMaxAge(60);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    return $response;
  }

  /**
   * Force an immediate build dispatch, skipping the debounce window.
   */
  public function deployNow(): Response {
    $ok = $this->dispatcher->dispatchNow();
    return new JsonResponse(['ok' => $ok], $ok ? 200 : 500);
  }

  /**
   * Set dc_brand.settings:build_hook.url without touching other brand config.
   *
   * Called during space provisioning once the Netlify build hook is known.
   * Auth reuses the X-Decoupled-Token PAT flow that dc_config_import uses.
   */
  public function setBuildHook(Request $request): JsonResponse {
    if (!$this->authenticate($request)) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Authentication required'], 401);
    }
    $data = json_decode((string) $request->getContent(), TRUE);
    $url = is_array($data) ? (string) ($data['url'] ?? '') : '';
    if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Invalid URL'], 400);
    }
    $config = $this->config->getEditable('dc_brand.settings');
    $config->set('build_hook.url', $url);
    if (!$config->get('build_hook.debounce_seconds')) {
      $config->set('build_hook.debounce_seconds', 60);
    }
    $config->save();
    return new JsonResponse(['success' => TRUE, 'url' => $url]);
  }

  private function authenticate(Request $request): bool {
    $token = $request->headers->get('X-Decoupled-Token');
    if (!$token) {
      return FALSE;
    }
    // Mirror dc_config_import's minimal PAT/bearer check. Platform-level
    // validation still happens upstream at the dashboard boundary.
    if (str_starts_with($token, 'dc_tok_')) {
      return TRUE;
    }
    return strlen($token) >= 32 && (bool) preg_match('/^[a-zA-Z0-9_.-]+$/', $token);
  }

}
