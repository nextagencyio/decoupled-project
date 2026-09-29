<?php

namespace Drupal\dc_canvas\Drush\Commands;

use Drupal\dc_canvas\Service\FrontendManager;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for Decoupled Canvas.
 */
final class DcCanvasCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs DcCanvasCommands.
   */
  public function __construct(
    #[Autowire(service: 'dc_canvas.frontend_manager')]
    private readonly FrontendManager $frontendManager,
  ) {
    parent::__construct();
  }

  /**
   * Registers a frontend as the primary Canvas headless frontend.
   */
  #[CLI\Command(name: 'dc-canvas:frontend', aliases: ['dccf'])]
  #[CLI\Argument(name: 'url', description: 'Frontend origin, e.g. http://localhost:4321')]
  #[CLI\Usage(name: 'drush dc-canvas:frontend http://localhost:4321', description: 'Point the Canvas editor at a local Astro dev server.')]
  public function frontend(string $url): void {
    $changed = $this->frontendManager->setPrimary($url);
    $this->logger()->success($changed ? "Primary Canvas frontend set to $url." : "$url is already the primary Canvas frontend.");
    $this->io()->listing($this->frontendManager->getUrls());
  }

}
