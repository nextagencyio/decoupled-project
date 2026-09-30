<?php

namespace Drupal\dc_api\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fills in the consumer's default scopes on scope-less token requests.
 *
 * simple_oauth 5.x issued client_credentials tokens without a "scope"
 * parameter; 6.x rejects such requests. Every deployed decoupled.io frontend
 * (decoupled-client) omits it, so apply the consumer's configured scopes
 * server-side rather than changing every client.
 */
class DefaultScopeSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a DefaultScopeSubscriber.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (32) so the route name is known; before the controller
    // converts the request to PSR-7.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Adds default scopes to client_credentials token requests without any.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();
    if ($request->attributes->get('_route') !== 'oauth2_token.token'
      || $request->request->get('grant_type') !== 'client_credentials'
      || trim((string) $request->request->get('scope', '')) !== '') {
      return;
    }

    $client_id = $request->request->get('client_id') ?: $request->getUser();
    if (!$client_id) {
      return;
    }
    $consumers = $this->entityTypeManager->getStorage('consumer')->loadByProperties(['client_id' => $client_id]);
    $consumer = reset($consumers);
    if (!$consumer || !$consumer->hasField('scopes')) {
      return;
    }

    $names = [];
    foreach ($consumer->get('scopes')->getScopes() as $scope) {
      $names[] = $scope->getName();
    }
    if ($names) {
      $request->request->set('scope', implode(' ', $names));
    }
  }

}
