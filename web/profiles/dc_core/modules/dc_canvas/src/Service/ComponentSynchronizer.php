<?php

namespace Drupal\dc_canvas\Service;

use Drupal\canvas_headless\ExternalComponentSync;
use Drupal\canvas_headless\FrontendUrl;
use Drupal\canvas_headless\PreviewUrlGeneratorInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;

/**
 * Syncs the frontend's component library into Canvas without a browser.
 *
 * Canvas normally syncs when an editor opens the editor: the browser fetches
 * the frontend's component metadata and posts it back. Provisioning and
 * content imports need the components before anyone has opened the editor,
 * so this performs the same exchange server-side.
 */
class ComponentSynchronizer {

  /**
   * Constructs a ComponentSynchronizer.
   */
  public function __construct(
    protected FrontendManager $frontendManager,
    protected ExternalComponentSync $componentSync,
    protected PreviewUrlGeneratorInterface $previewUrlGenerator,
    protected AccountSwitcherInterface $accountSwitcher,
    protected ClientInterface $httpClient,
  ) {}

  /**
   * Synchronizes components from the primary frontend.
   *
   * @param string|null $fetch_url
   *   Base URL to fetch the metadata from, when the server reaches the
   *   frontend at a different address than editors do (e.g.
   *   http://host.docker.internal:4321 for a local dev server).
   *
   * @return array
   *   The sync result: created, updated, unchanged, warnings, errors.
   */
  public function sync(?string $fetch_url = NULL): array {
    $url = $this->frontendManager->getUrls()[0] ?? NULL;
    $frontend = $url ? FrontendUrl::fromConfig($url) : NULL;
    if (!$frontend) {
      throw new \RuntimeException('No Canvas headless frontend is registered.');
    }

    // Assertions are minted for the current user; act as the site owner.
    $this->accountSwitcher->switchTo(User::load(1));
    try {
      $assertion = $this->previewUrlGenerator->issueForPath('/');
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
    if (!$assertion) {
      throw new \RuntimeException('A preview assertion could not be issued.');
    }

    $response = $this->httpClient->request('GET', rtrim($fetch_url ?: $url, '/') . ExternalComponentSync::COMPONENT_METADATA_PATH, [
      'headers' => [
        'Authorization' => 'Bearer ' . $assertion,
        'Accept' => 'application/json',
        // Keep the frontend's own Host when fetching through another
        // address; dev servers reject hosts they do not recognise.
        'Host' => parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : ''),
      ],
      'timeout' => 30,
    ]);
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);

    return $this->componentSync->synchronize($frontend, $payload);
  }

}
