<?php

declare(strict_types=1);

namespace Drupal\dc_import\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Read/write access to the dc_make_link identity map.
 *
 * The make publish endpoint and the edit-redirect hook are the only
 * callers. Every other part of Drupal stays unaware of make's UUIDs;
 * lookups go through this service so the storage detail (a custom
 * table vs a future field) is encapsulated.
 */
final class MakeLinkRepository {

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Loads the entity linked to the given make UUID, or NULL if none.
   *
   * Optionally narrows by entity type so two different entity types
   * sharing a UUID (which shouldn't happen, but theoretically could)
   * resolve unambiguously.
   */
  public function findEntityByUuid(string $uuid, ?string $entityType = NULL): ?EntityInterface {
    $query = $this->db->select('dc_make_link', 'l')
      ->fields('l', ['entity_type', 'entity_id'])
      ->condition('uuid', $uuid);
    if ($entityType !== NULL) {
      $query->condition('entity_type', $entityType);
    }
    $row = $query->execute()->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    return $this->entityTypeManager
      ->getStorage($row['entity_type'])
      ->load($row['entity_id']);
  }

  /**
   * Returns the make UUID for an entity, or NULL if it isn't linked.
   *
   * Used by the edit-redirect hook to detect make-managed entities.
   */
  public function findUuidForEntity(EntityInterface $entity): ?string {
    $row = $this->db->select('dc_make_link', 'l')
      ->fields('l', ['uuid'])
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->execute()
      ->fetchAssoc();
    return $row ? $row['uuid'] : NULL;
  }

  /**
   * Returns the link row for an entity, or NULL if it isn't linked.
   *
   * Includes parent_project_uuid + bundle so the redirect hook can
   * build the correct make.decoupled.io URL without another lookup.
   */
  public function findLinkForEntity(EntityInterface $entity): ?array {
    $row = $this->db->select('dc_make_link', 'l')
      ->fields('l')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Idempotent upsert: links an entity to a make UUID.
   *
   * Called after the publish endpoint has saved the entity. If the row
   * already exists (re-publish), only the `updated` timestamp moves.
   */
  public function link(
    EntityInterface $entity,
    string $uuid,
    string $parentProjectUuid,
  ): void {
    $now = \Drupal::time()->getRequestTime();
    $this->db->merge('dc_make_link')
      ->key('uuid', $uuid)
      ->insertFields([
        'uuid' => $uuid,
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => (int) $entity->id(),
        'bundle' => $entity->bundle(),
        'parent_project_uuid' => $parentProjectUuid,
        'created' => $now,
        'updated' => $now,
      ])
      ->updateFields([
        'entity_type' => $entity->getEntityTypeId(),
        'entity_id' => (int) $entity->id(),
        'bundle' => $entity->bundle(),
        'parent_project_uuid' => $parentProjectUuid,
        'updated' => $now,
      ])
      ->execute();
  }

  /**
   * Removes the link row for an entity. Safe if no row exists.
   *
   * Called from hook_entity_delete so a manually-deleted entity in
   * Drupal doesn't leave an orphan row that would point a future
   * publish at a vanished entity id.
   */
  public function unlinkEntity(EntityInterface $entity): void {
    $this->db->delete('dc_make_link')
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('entity_id', (int) $entity->id())
      ->execute();
  }

  /**
   * Removes a link row by UUID. Used when make tells us a UUID was
   * deleted on its side via the publish payload's `deletedXxxUuids`.
   * Returns the entity id that was unlinked (so the caller can also
   * delete the underlying entity), or NULL if the UUID wasn't known.
   */
  public function unlinkUuid(string $uuid): ?array {
    $row = $this->db->select('dc_make_link', 'l')
      ->fields('l', ['entity_type', 'entity_id'])
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return NULL;
    }
    $this->db->delete('dc_make_link')->condition('uuid', $uuid)->execute();
    return $row;
  }

}
