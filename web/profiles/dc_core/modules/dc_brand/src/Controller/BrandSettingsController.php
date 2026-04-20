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

}
