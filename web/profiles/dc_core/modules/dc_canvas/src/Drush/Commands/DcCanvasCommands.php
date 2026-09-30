<?php

namespace Drupal\dc_canvas\Drush\Commands;

use Drupal\dc_canvas\Service\ComponentSynchronizer;
use Drupal\dc_canvas\Service\ContentImporter;
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
    #[Autowire(service: 'dc_canvas.component_synchronizer')]
    private readonly ComponentSynchronizer $componentSynchronizer,
    #[Autowire(service: 'dc_canvas.content_importer')]
    private readonly ContentImporter $contentImporter,
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

  /**
   * Syncs the primary frontend's component library into Canvas.
   */
  #[CLI\Command(name: 'dc-canvas:sync', aliases: ['dccs'])]
  #[CLI\Option(name: 'fetch-url', description: 'Base URL the server fetches component metadata from, if different from the registered frontend URL.')]
  #[CLI\Usage(name: 'drush dc-canvas:sync --fetch-url=http://host.docker.internal:4321', description: 'Sync from a dev server running on the DDEV host.')]
  public function sync(array $options = ['fetch-url' => self::REQ]): void {
    $result = $this->componentSynchronizer->sync($options['fetch-url'] ?: NULL);
    foreach (array_merge($result['warnings'], $result['errors']) as $message) {
      $this->logger()->warning($message);
    }
    $this->logger()->success(sprintf('Components: %d created, %d updated, %d unchanged.', $result['created'], $result['updated'], $result['unchanged']));
  }

  /**
   * Imports the landing pages of a starter content file as Canvas pages.
   */
  #[CLI\Command(name: 'dc-canvas:import', aliases: ['dcci'])]
  #[CLI\Argument(name: 'source', description: 'Path or URL of a decoupled.io content file (e.g. components-content.json).')]
  #[CLI\Usage(name: 'drush dc-canvas:import https://raw.githubusercontent.com/nextagencyio/decoupled-components-astro/main/data/components-content.json', description: 'Import the starter content.')]
  public function import(string $source): void {
    $json = @file_get_contents($source);
    $data = $json ? json_decode($json, TRUE) : NULL;
    if (!is_array($data)) {
      throw new \InvalidArgumentException("Could not read a content file from $source.");
    }
    $result = $this->contentImporter->import($data);
    foreach ($result['warnings'] as $message) {
      $this->logger()->warning($message);
    }
    foreach ($result['skipped'] as $message) {
      $this->logger()->notice("Skipped: $message");
    }
    $this->io()->listing($result['created']);
    $this->logger()->success(sprintf('%d Canvas page(s) created, %d skipped.', count($result['created']), count($result['skipped'])));
  }

}
