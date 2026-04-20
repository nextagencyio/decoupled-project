<?php

namespace Drupal\dc_brand\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\dc_brand\Service\BuildHookDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Schedules a debounced build when dc_brand.settings saves, and piggybacks on
 * every request termination to flush pending builds whose window has elapsed.
 */
class BrandChangeSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly BuildHookDispatcher $dispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE     => ['onConfigSave'],
      KernelEvents::TERMINATE => ['onTerminate'],
    ];
  }

  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== 'dc_brand.settings') {
      return;
    }
    $this->dispatcher->schedule();
  }

  public function onTerminate(TerminateEvent $event): void {
    $this->dispatcher->maybeFire();
  }

}
