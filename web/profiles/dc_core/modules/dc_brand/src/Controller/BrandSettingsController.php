<?php

namespace Drupal\dc_brand\Controller;

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

  public function __construct(
    private readonly BrandResolver $resolver,
    private readonly BuildHookDispatcher $dispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('dc_brand.resolver'),
      $container->get('dc_brand.build_hook_dispatcher'),
    );
  }

  /**
   * Return the resolved brand payload.
   */
  public function settings(): JsonResponse {
    $response = new JsonResponse($this->resolver->resolve());
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
