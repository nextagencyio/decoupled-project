<?php

namespace Drupal\auditcrawl\Controller;

use Drupal\auditcrawl\Service\NodeWriter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX endpoints called from js/admin.js. Mirrors the five WP
 * plugin wp_ajax_* handlers one-for-one so the branded toast + modal
 * JavaScript can be shared between the two plugins nearly verbatim.
 *
 * CSRF is handled by the _csrf_token requirement in routing.yml;
 * permission is enforced there too, so by the time we hit these
 * methods we know the caller has 'administer auditcrawl'.
 */
class AjaxController extends ControllerBase {

  /**
   * POST /admin/content/auditcrawl/ajax/create-stub
   * Body: { strategy_index: int }
   */
  public function createStub(Request $request): JsonResponse {
    if (!\Drupal\auditcrawl\Service\NodeWriter::isSetupComplete()) {
      return new JsonResponse(['error' => 'Setup incomplete. Visit /admin/content/auditcrawl/setup first.'], 409);
    }
    $body = $this->jsonBody($request);
    $idx = (int) ($body['strategy_index'] ?? -1);
    if ($idx < 0) {
      return new JsonResponse(['error' => 'strategy_index required'], 400);
    }

    $config = $this->config('auditcrawl.settings');
    $code = (string) $config->get('report_code');
    if (!$code) {
      return new JsonResponse(['error' => 'No report connected'], 400);
    }

    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->fetchReport();
    if (!$res['ok']) {
      return new JsonResponse(['error' => $res['error']], 502);
    }

    $items = $res['data']['report']['strategy']['contentStrategy'] ?? [];
    if (empty($items[$idx])) {
      return new JsonResponse(['error' => "strategy_index {$idx} out of range"], 400);
    }

    $stubMap = $config->get('stub_node_ids') ?: [];
    if (!empty($stubMap[$idx])) {
      // Idempotent — return the existing stub.
      return new JsonResponse([
        'postId' => (int) $stubMap[$idx],
        'editUrl' => Url::fromRoute('entity.node.edit_form', ['node' => $stubMap[$idx]])->toString(),
      ]);
    }

    $node = NodeWriter::createStub($idx, $items[$idx], $code);
    $stubMap[$idx] = (int) $node->id();
    \Drupal::configFactory()->getEditable('auditcrawl.settings')
      ->set('stub_node_ids', $stubMap)->save();

    return new JsonResponse([
      'postId' => (int) $node->id(),
      'editUrl' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString(),
    ]);
  }

  /**
   * POST /admin/content/auditcrawl/ajax/generate-now
   * Body: { post_id: int }
   */
  public function generateNow(Request $request): JsonResponse {
    $body = $this->jsonBody($request);
    $nid = (int) ($body['post_id'] ?? 0);
    if ($nid <= 0) {
      return new JsonResponse(['error' => 'post_id required'], 400);
    }

    $node = \Drupal\node\Entity\Node::load($nid);
    if (!$node || $node->bundle() !== 'article') {
      return new JsonResponse(['error' => 'Node not found'], 404);
    }

    $idx = (int) ($node->get('field_ac_strategy_index')->value ?? -1);
    $code = (string) $node->get('field_ac_report_code')->value ?? '';
    if ($idx < 0 || !$code) {
      return new JsonResponse(['error' => 'Node is not an AuditCrawl stub'], 400);
    }

    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->generateContent([
      'reportCode' => $code,
      'strategyItemIndex' => $idx,
    ]);
    if (!$res['ok']) {
      return new JsonResponse(['error' => $res['error']], 502);
    }
    $draft = $res['data']['draft'] ?? NULL;
    if (!$draft) {
      return new JsonResponse(['error' => 'Empty draft payload'], 502);
    }

    $filled = NodeWriter::fill($nid, $draft);
    return new JsonResponse([
      'editUrl' => Url::fromRoute('entity.node.edit_form', ['node' => $nid])->toString(),
      'wordCount' => (int) ($filled->get('field_ac_word_count')->value ?? 0),
      'creditsRemaining' => $res['data']['contentCreditsRemaining'] ?? NULL,
    ]);
  }

  /** POST /admin/content/auditcrawl/ajax/rotate-license */
  public function rotateLicense(Request $request): JsonResponse {
    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->rotateLicense();
    if (!$res['ok']) {
      return new JsonResponse(['error' => $res['error']], $res['status'] ?: 500);
    }
    $newKey = $res['data']['newLicenseKey'] ?? NULL;
    if ($newKey) {
      \Drupal::configFactory()->getEditable('auditcrawl.settings')
        ->set('license_key', $newKey)->save();
    }
    return new JsonResponse(['newLicenseKey' => $newKey]);
  }

  /** POST /admin/content/auditcrawl/ajax/move-license */
  public function moveLicense(Request $request): JsonResponse {
    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->moveLicense();
    if (!$res['ok']) {
      return new JsonResponse(['error' => $res['error']], $res['status'] ?: 500);
    }
    return new JsonResponse($res['data']);
  }

  /** POST /admin/content/auditcrawl/ajax/open-portal */
  public function openPortal(Request $request): JsonResponse {
    /** @var \Drupal\auditcrawl\Service\Client $client */
    $client = \Drupal::service('auditcrawl.client');
    $res = $client->licensePortal();
    if (!$res['ok']) {
      return new JsonResponse(['error' => $res['error']], $res['status'] ?: 500);
    }
    return new JsonResponse(['url' => $res['data']['url'] ?? NULL]);
  }

  /**
   * Decode a JSON request body. Returns [] on empty / invalid so
   * callers can `?? -1` on missing keys.
   */
  protected function jsonBody(Request $request): array {
    $raw = $request->getContent();
    if (!$raw) return [];
    $data = json_decode($raw, TRUE);
    return is_array($data) ? $data : [];
  }

}
