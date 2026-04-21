<?php

namespace Drupal\dc_brand\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fires the configured static-site build webhook.
 *
 * Every save of dc_brand.settings triggers an immediate POST to the build
 * hook URL. Earlier versions of this service debounced builds across a
 * window; the debounce was removed in favor of fire-on-save so the user's
 * change is visible as soon as the build finishes.
 */
class BuildHookDispatcher {

  private const STATE_LAST_FIRED = 'dc_brand.last_fired';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * POST the build hook URL immediately.
   */
  public function dispatchNow(): bool {
    $url = (string) $this->configFactory->get('dc_brand.settings')->get('build_hook.url');
    if ($url === '') {
      return FALSE;
    }
    try {
      $this->httpClient->request('POST', $url, [
        'timeout'         => 10,
        'connect_timeout' => 5,
      ]);
      $this->state->set(self::STATE_LAST_FIRED, $this->time->getCurrentTime());
      $this->loggerFactory->get('dc_brand')->info('Build hook fired: @url', ['@url' => $url]);
      return TRUE;
    }
    catch (GuzzleException $e) {
      $this->loggerFactory->get('dc_brand')->error('Build hook failed: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

}
