<?php

namespace Drupal\dc_brand\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\dc_brand\Service\BuildHookDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Fires the build hook immediately when dc_brand.settings is saved.
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
      ConfigEvents::SAVE => ['onConfigSave'],
    ];
  }

  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== 'dc_brand.settings') {
      return;
    }
    $this->dispatcher->dispatchNow();
  }

}
