<?php

declare(strict_types=1);

namespace Drupal\dc_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dc_import\Service\DrupalContentImporter;
use Drupal\dc_import\Service\MakeLinkRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Make.decoupled.io's publish endpoint.
 *
 * This is intentionally a thin wrapper around DrupalContentImporter:
 * the make app already speaks dc_import's `{model, content}` JSON DSL
 * (the editor's components are auto-generated from the same JSON), so
 * the only make-specific work here is identity bookkeeping.
 *
 * Per request:
 *   1. Hand the {model, content} payload to DrupalContentImporter::import().
 *      The importer creates/updates node types, paragraph types, fields,
 *      form displays, and content rows. UUIDs from make come in as the
 *      content `id` strings, so the importer's normal `@id` ref system
 *      already works for cross-paragraph links.
 *   2. Read $result['created'] (id → entity_type/entity_id/bundle map)
 *      and upsert dc_make_link rows so future re-publishes can find the
 *      same entities by UUID, and the edit-redirect hook can recognise
 *      make-managed entities.
 *   3. Honour `deletedUuids[]` separately — dc_import doesn't do deletes,
 *      so we walk the link table and delete the matching entities.
 *
 * Body shape:
 *   {
 *     "model":   [...]   // dc_import model array (same shape as components-content.json)
 *     "content": [...]   // dc_import content array; `id` strings are make UUIDs
 *     "projectUuid": "<uuid>"     // anchor for parent_project_uuid on link rows
 *     "deletedUuids": ["<uuid>"]  // optional; entities to remove by make UUID
 *   }
 *
 * Auth: OAuth bearer (route `_auth: ['oauth2']`); the MCP Agent
 * consumer's user_id=1 admin context can write nodes/terms/paragraphs.
 */
final class MakePublishController extends ControllerBase {

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $entityTypes,
    private readonly DrupalContentImporter $importer,
    private readonly MakeLinkRepository $links,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('dc_import.importer'),
      $container->get('dc_import.make_link_repository'),
    );
  }

  public function publish(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || empty($payload['projectUuid'])) {
      return $this->errorResponse('invalid_payload', 'Missing projectUuid.', 400);
    }
    $projectUuid = (string) $payload['projectUuid'];
    $deletedUuids = is_array($payload['deletedUuids'] ?? null) ? $payload['deletedUuids'] : [];
    $adminEmail = isset($payload['adminEmail']) && is_string($payload['adminEmail'])
      ? trim($payload['adminEmail'])
      : '';

    // dc_import's import() takes the whole envelope; pass it through
    // unchanged. It already validates `model` / `content`. We use the
    // same DB transaction as the existing importer behavior — wrap the
    // whole publish so a failure leaves Drupal untouched and the link
    // table unchanged.
    $tx = $this->db->startTransaction('dc_import_make_publish');
    try {
      $importPayload = [];
      if (isset($payload['model']))   $importPayload['model']   = $payload['model'];
      if (isset($payload['content'])) $importPayload['content'] = $payload['content'];
      $result = $this->importer->import($importPayload, FALSE);

      $linked = [];
      foreach ($result['created'] ?? [] as $uuid => $info) {
        // The make UUID came in as the content `id`. We re-use it both
        // as our own primary key in dc_make_link and as the entity's
        // identity in future re-publishes. parent_project_uuid is
        // self-referential when the entity is the project's landing
        // page; otherwise it's the project we were sent.
        $parent = $uuid === $projectUuid ? $uuid : $projectUuid;
        $entity = $this->entityTypes->getStorage($info['entity_type'])->load($info['entity_id']);
        if (!$entity) {
          continue;
        }
        $this->links->link($entity, $uuid, $parent);
        $linked[] = [
          'uuid' => $uuid,
          'entity_type' => $info['entity_type'],
          'entity_id' => $info['entity_id'],
          'bundle' => $info['bundle'],
        ];
      }

      $deleted = [];
      foreach ($deletedUuids as $uuid) {
        $row = $this->links->unlinkUuid((string) $uuid);
        if (!$row) continue;
        $entity = $this->entityTypes->getStorage($row['entity_type'])->load($row['entity_id']);
        if ($entity) {
          $entity->delete();
        }
        $deleted[] = $uuid;
      }

      // Align user 1's email to the make user's email so the CMS admin
      // and the make customer are the same identity (mirrors what the
      // dashboard does at space provisioning via --account-mail). Only
      // touch it if it actually drifted; on subsequent re-publishes
      // this is a fast no-op.
      $emailChanged = FALSE;
      if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $userStorage = $this->entityTypes->getStorage('user');
        /** @var \Drupal\user\UserInterface|null $admin */
        $admin = $userStorage->load(1);
        if ($admin && $admin->getEmail() !== $adminEmail) {
          $admin->setEmail($adminEmail);
          $admin->save();
          $emailChanged = TRUE;
        }
      }

      return new JsonResponse([
        'ok' => TRUE,
        'linked' => $linked,
        'deleted' => $deleted,
        'adminEmailUpdated' => $emailChanged,
        'summary' => $result['summary'] ?? [],
        'warnings' => $result['warnings'] ?? [],
      ]);
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      \Drupal::logger('dc_import')->error('make publish failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResponse('publish_failed', $e->getMessage(), 500);
    }
  }

  private function errorResponse(string $code, string $message, int $status): JsonResponse {
    return new JsonResponse(
      ['ok' => FALSE, 'error' => $code, 'message' => $message],
      $status,
    );
  }

}
