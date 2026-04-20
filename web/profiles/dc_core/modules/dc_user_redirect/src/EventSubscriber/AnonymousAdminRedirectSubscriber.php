<?php

namespace Drupal\dc_user_redirect\EventSubscriber;

use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Bounces anonymous users hitting an admin route's 403 to /user/login,
 * preserving the original path as ?destination= so they land where they
 * intended after signing in.
 *
 * Only fires for admin routes — public-route 403s still render Drupal's
 * normal access-denied page so we don't accidentally hide content.
 */
class AnonymousAdminRedirectSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected AdminContext $adminContext,
    protected RouteMatchInterface $routeMatch,
  ) {}

  public static function getSubscribedEvents(): array {
    // Run before Drupal's default exception subscribers so we can swap the
    // 403 response for a redirect.
    return [KernelEvents::EXCEPTION => ['onException', 100]];
  }

  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if (!$event->getThrowable() instanceof AccessDeniedHttpException) {
      return;
    }
    if (!$this->currentUser->isAnonymous()) {
      return;
    }
    $route = $this->routeMatch->getRouteObject();
    if (!$route || !$this->adminContext->isAdminRoute($route)) {
      return;
    }
    $request = $event->getRequest();
    $destination = $request->getRequestUri();
    $login = Url::fromRoute('user.login', [], [
      'query' => ['destination' => $destination],
    ])->toString();
    $event->setResponse(new RedirectResponse($login));
  }

}
