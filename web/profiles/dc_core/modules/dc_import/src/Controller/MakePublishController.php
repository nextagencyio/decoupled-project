<?php

declare(strict_types=1);

namespace Drupal\dc_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\dc_import\Service\MakeLinkRepository;
use Drupal\dc_puck\Service\PuckMappingService;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Endpoint that the make.decoupled.io builder pushes content into.
 *
 * One project deploy → one POST. Body shape:
 *
 *   {
 *     "project": { "uuid", "title", "slug", "page": { "title", "blocks": [...puck], "root": {...} }},
 *     "posts":   [{ "uuid", "title", "slug", "body", "status", "publishedAt", "coverImage": {url, alt}, "tagUuids": [...] }],
 *     "tags":    [{ "uuid", "name", "slug" }],
 *     "deletedPostUuids": [...],
 *     "deletedTagUuids":  [...]
 *   }
 *
 * Upserts are by make UUID via the dc_make_link table — re-publishing
 * the same project is idempotent. Wraps all entity writes in a single
 * DB transaction so a failure mid-publish doesn't leave Drupal in a
 * mixed state. Auth is OAuth bearer (route `_auth: ['oauth2']`); the
 * MCP Agent consumer's user_id=1 admin context already has permission
 * to write nodes/terms.
 *
 * Path 1/Model A: Drupal is downstream of make. The edit-redirect hook
 * (dc_import.module) sends admins back to make when they try to edit
 * make-managed entities, so we don't need to worry about Drupal-side
 * edits being clobbered between publishes.
 */
final class MakePublishController extends ControllerBase {

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $entityTypes,
    private readonly MakeLinkRepository $links,
    private readonly PuckMappingService $puckMapping,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('dc_import.make_link_repository'),
      $container->get('dc_puck.mapping'),
    );
  }

  public function publish(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload) || !isset($payload['project']['uuid'])) {
      return $this->errorResponse('invalid_payload', 'Missing project.uuid.', 400);
    }

    $project = $payload['project'];
    $posts   = $payload['posts'] ?? [];
    $tags    = $payload['tags'] ?? [];
    $deletedPostUuids = $payload['deletedPostUuids'] ?? [];
    $deletedTagUuids  = $payload['deletedTagUuids'] ?? [];

    // All-or-nothing: any failure rolls the whole publish back so
    // Drupal never ends up half-updated relative to make's state.
    $tx = $this->db->startTransaction('dc_import_make_publish');

    try {
      $this->ensureTagsVocabulary();

      $tagResults = [];
      foreach ($tags as $tag) {
        $tagResults[] = $this->upsertTag($tag, $project['uuid']);
      }

      // Tags must be upserted before posts so post→tag references can
      // resolve via dc_make_link. Make's payload ordering already
      // guarantees this; we re-iterate here defensively.

      $postResults = [];
      foreach ($posts as $post) {
        $postResults[] = $this->upsertArticle($post, $project['uuid']);
      }

      $projectResult = $this->upsertLandingPage($project);

      $deletedTags  = $this->deleteByUuids($deletedTagUuids);
      $deletedPosts = $this->deleteByUuids($deletedPostUuids);

      return new JsonResponse([
        'ok' => TRUE,
        'project' => $projectResult,
        'posts'   => $postResults,
        'tags'    => $tagResults,
        'deleted' => [
          'posts' => $deletedPosts,
          'tags'  => $deletedTags,
        ],
      ]);
    }
    catch (PublishException $e) {
      $tx->rollBack();
      return $this->errorResponse($e->code, $e->getMessage(), 400, $e->details);
    }
    catch (\Throwable $e) {
      $tx->rollBack();
      \Drupal::logger('dc_import')->error('make publish failed: @msg', ['@msg' => $e->getMessage()]);
      return $this->errorResponse('internal_error', $e->getMessage(), 500);
    }
  }

  /**
   * Upserts a make project as a `landing_page` node.
   *
   * Title comes from project.page.title (the page is what the visitor
   * sees; project.title is admin-facing). Puck blocks go through
   * dc_puck's PuckMappingService, which converts them to paragraph
   * entities on field_sections — same path the in-Drupal editor uses
   * so admin loads see exactly what make published.
   */
  private function upsertLandingPage(array $project): array {
    $uuid = $project['uuid'];
    $page = $project['page'] ?? [];
    $title = (string) ($page['title'] ?? $project['title'] ?? 'Untitled');

    $node = $this->links->findEntityByUuid($uuid, 'node');
    $action = 'updated';
    if ($node instanceof NodeInterface) {
      $node->setTitle($title);
    }
    else {
      $action = 'created';
      $node = $this->entityTypes->getStorage('node')->create([
        'type' => 'landing_page',
        'title' => $title,
        'status' => 1,
        'uid' => 1,
      ]);
    }
    $node->save();

    // Hand the puck doc to dc_puck's mapping service. It owns the
    // block→paragraph translation; we just hand it the right shape.
    $puckDoc = [
      'content' => $page['blocks'] ?? [],
      'root' => $page['root'] ?? new \stdClass(),
    ];
    $this->puckMapping->savePuckData($node, $puckDoc);

    // Self-referential: the project landing page's parent_project is
    // the project itself, since there's exactly one landing page per
    // project (Topic 1 decision).
    $this->links->link($node, $uuid, $uuid);

    return [
      'uuid' => $uuid,
      'nid' => (int) $node->id(),
      'action' => $action,
      'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];
  }

  /**
   * Upserts a make post as an `article` node. Tags are resolved from
   * make tag UUIDs via dc_make_link — make sends `tags` first, so
   * any tagUuid referenced here must already have a term row.
   */
  private function upsertArticle(array $post, string $projectUuid): array {
    $uuid = $post['uuid'];
    $node = $this->links->findEntityByUuid($uuid, 'node');
    $action = 'updated';

    if (!$node instanceof NodeInterface) {
      $action = 'created';
      $node = $this->entityTypes->getStorage('node')->create([
        'type' => 'article',
        'uid' => 1,
      ]);
    }

    $node->setTitle((string) ($post['title'] ?? 'Untitled'));
    $node->set('status', ($post['status'] ?? 'draft') === 'published' ? 1 : 0);

    if ($node->hasField('body')) {
      $node->set('body', [
        'value' => (string) ($post['body'] ?? ''),
        'format' => 'full_html',
      ]);
    }

    $tagTerms = [];
    foreach (($post['tagUuids'] ?? []) as $tagUuid) {
      $term = $this->links->findEntityByUuid($tagUuid, 'taxonomy_term');
      if (!$term instanceof TermInterface) {
        throw new PublishException(
          'tag_not_found',
          "Post {$uuid} references tag {$tagUuid}, which doesn't exist on this site yet.",
          ['postUuid' => $uuid, 'tagUuid' => $tagUuid],
        );
      }
      $tagTerms[] = ['target_id' => $term->id()];
    }
    if ($node->hasField('field_tags')) {
      $node->set('field_tags', $tagTerms);
    }

    if (!empty($post['publishedAt'])) {
      // `created` is the closest stock article field to "published at".
      // Image sideloading is intentionally out of scope for v1 — cover
      // images stay as remote URLs. We don't try to set field_image
      // because it's a managed file reference, not a URL field.
      $node->set('created', strtotime($post['publishedAt']) ?: \Drupal::time()->getRequestTime());
    }

    $node->save();
    $this->links->link($node, $uuid, $projectUuid);

    return [
      'uuid' => $uuid,
      'nid' => (int) $node->id(),
      'action' => $action,
    ];
  }

  /**
   * Upserts a make tag as a term in the `tags` vocabulary.
   */
  private function upsertTag(array $tag, string $projectUuid): array {
    $uuid = $tag['uuid'];
    $term = $this->links->findEntityByUuid($uuid, 'taxonomy_term');
    $action = 'updated';

    if ($term instanceof TermInterface) {
      $term->setName((string) ($tag['name'] ?? $term->getName()));
    }
    else {
      $action = 'created';
      $term = $this->entityTypes->getStorage('taxonomy_term')->create([
        'vid' => 'tags',
        'name' => (string) ($tag['name'] ?? 'Untitled'),
      ]);
    }
    $term->save();
    $this->links->link($term, $uuid, $projectUuid);

    return [
      'uuid' => $uuid,
      'tid' => (int) $term->id(),
      'action' => $action,
    ];
  }

  /**
   * Deletes entities for the given set of make UUIDs, removing both
   * the dc_make_link row and the underlying entity.
   *
   * Missing UUIDs are silently ignored — make's notion of "deleted"
   * may include entities that never made it across, and treating that
   * as an error would surface false-positive failures on retries.
   */
  private function deleteByUuids(array $uuids): array {
    $deleted = [];
    foreach ($uuids as $uuid) {
      $row = $this->links->unlinkUuid($uuid);
      if (!$row) {
        continue;
      }
      $entity = $this->entityTypes->getStorage($row['entity_type'])->load($row['entity_id']);
      if ($entity) {
        $entity->delete();
      }
      $deleted[] = $uuid;
    }
    return $deleted;
  }

  /**
   * Auto-creates the `tags` vocabulary if it's missing.
   *
   * The dc_core profile ships it on install, but a site builder could
   * have deleted it. Auto-create keeps the publish path defensive
   * without bouncing the customer back to support.
   */
  private function ensureTagsVocabulary(): void {
    $storage = $this->entityTypes->getStorage('taxonomy_vocabulary');
    if ($storage->load('tags')) {
      return;
    }
    $storage->create([
      'vid' => 'tags',
      'name' => 'Tags',
      'description' => 'Auto-created by dc_import on first make publish.',
    ])->save();
  }

  private function errorResponse(string $code, string $message, int $status, array $details = []): JsonResponse {
    $body = ['ok' => FALSE, 'error' => $code, 'message' => $message];
    if ($details) {
      $body['details'] = $details;
    }
    return new JsonResponse($body, $status);
  }

}

/**
 * Internal exception used to bubble validation failures up to the
 * publish() handler so the transaction rolls back cleanly. Callers
 * outside this controller shouldn't catch this.
 */
final class PublishException extends \RuntimeException {
  public function __construct(
    public readonly string $code,
    string $message,
    public readonly array $details = [],
  ) {
    parent::__construct($message);
  }
}
