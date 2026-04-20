<?php

namespace Drupal\dc_brand\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fires the Astro build webhook, with a debounce window.
 *
 * Saving the brand form sets a "pending since" timestamp in state. Subsequent
 * saves inside the debounce window push that timestamp forward. When the
 * window closes (on the next schedule() call that happens ≥ debounce_seconds
 * after the last save), the webhook fires. A "deploy now" route bypasses the
 * window entirely.
 *
 * We rely on kernel request/termination to act as our scheduler — every
 * request that hits the site checks whether there's a pending debounced
 * dispatch waiting, and fires it if the window has elapsed. That's enough
 * for a small-scale site; a cron-driven runner would be more robust but is
 * out of scope for v1.
 */
class BuildHookDispatcher {

  private const STATE_PENDING_SINCE = 'dc_brand.pending_since';
  private const STATE_LAST_FIRED    = 'dc_brand.last_fired';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Mark a build as pending; deferred to the debounce window.
   */
  public function schedule(): void {
    $this->state->set(self::STATE_PENDING_SINCE, $this->time->getCurrentTime());
    $this->maybeFire();
  }

  /**
   * Fire immediately, ignoring the debounce window.
   */
  public function dispatchNow(): bool {
    return $this->fire();
  }

  /**
   * If a pending dispatch has aged past the debounce window, fire it.
   */
  public function maybeFire(): void {
    $pending = (int) $this->state->get(self::STATE_PENDING_SINCE, 0);
    if ($pending === 0) {
      return;
    }
    $debounce = (int) $this->configFactory->get('dc_brand.settings')->get('build_hook.debounce_seconds') ?: 60;
    if (($this->time->getCurrentTime() - $pending) >= $debounce) {
      $this->fire();
    }
  }

  /**
   * POST the build hook URL.
   */
  private function fire(): bool {
    $url = (string) $this->configFactory->get('dc_brand.settings')->get('build_hook.url');
    if ($url === '') {
      // Nothing configured — silently clear the pending flag so we don't
      // accumulate stale state.
      $this->state->delete(self::STATE_PENDING_SINCE);
      return FALSE;
    }
    try {
      $this->httpClient->request('POST', $url, [
        'timeout'         => 10,
        'connect_timeout' => 5,
      ]);
      $this->state->delete(self::STATE_PENDING_SINCE);
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
