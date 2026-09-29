<?php

namespace Drupal\dc_canvas\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Manages the headless frontends registered with Canvas.
 *
 * Canvas embeds the first registered frontend as its preview iframe and
 * syncs that frontend's component library, so the tenant's primary
 * frontend is always kept at the top of the list.
 */
class FrontendManager {

  /**
   * Constructs a FrontendManager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the registered frontend URLs, primary first.
   *
   * @return string[]
   *   The frontend origins.
   */
  public function getUrls(): array {
    $frontends = $this->configFactory->get('canvas_headless.settings')->get('frontends') ?? [];
    return array_column($frontends, 'url');
  }

  /**
   * Registers a frontend as the primary Canvas frontend.
   *
   * @param string $url
   *   The frontend origin, e.g. "https://my-site.netlify.app".
   *
   * @return bool
   *   TRUE if the configuration changed.
   */
  public function setPrimary(string $url): bool {
    $url = $this->normalize($url);
    $config = $this->configFactory->getEditable('canvas_headless.settings');
    $frontends = $config->get('frontends') ?? [];

    if (($frontends[0]['url'] ?? NULL) === $url) {
      return FALSE;
    }

    // Keep the component list the editor already synced for this URL.
    $existing = array_values(array_filter($frontends, fn($f) => $f['url'] === $url));
    $others = array_values(array_filter($frontends, fn($f) => $f['url'] !== $url));
    $primary = $existing[0] ?? ['url' => $url, 'components' => []];

    $config->set('frontends', array_merge([$primary], $others))->save();
    $this->loggerFactory->get('dc_canvas')->notice('Registered Canvas headless frontend @url.', ['@url' => $url]);
    return TRUE;
  }

  /**
   * Normalizes a frontend URL to a bare origin.
   */
  protected function normalize(string $url): string {
    $parts = parse_url(trim($url));
    if (empty($parts['scheme']) || empty($parts['host'])) {
      throw new \InvalidArgumentException(sprintf('"%s" is not an absolute URL.', $url));
    }
    $origin = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
      $origin .= ':' . $parts['port'];
    }
    return $origin;
  }

}
