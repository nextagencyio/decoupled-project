<?php

namespace Drupal\dc_canvas\Service;

use Drupal\canvas\Entity\Component;
use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Imports decoupled.io starter content as Canvas pages.
 *
 * Reads the same content file dc_import consumes (paragraph-based landing
 * pages) and builds a canvas_page per landing page instead: each section
 * paragraph becomes a Canvas component instance and each nested paragraph
 * list becomes children in the slot of the same name.
 */
class ContentImporter {

  /**
   * Paragraph bundles whose Canvas component has a different machine name.
   */
  protected const COMPONENT_ALIASES = [
    'sidebyside' => 'side_by_side',
    'quote' => 'testimonials',
  ];

  /**
   * Canvas pages cannot be aliased "/"; frontends resolve /home as the home.
   */
  protected const HOME_ALIAS = '/home';

  /**
   * Content items keyed by their import id.
   */
  protected array $items = [];

  /**
   * Non-fatal problems (e.g. an image that could not be downloaded).
   */
  protected array $notices = [];

  /**
   * Constructs a ContentImporter.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UuidInterface $uuid,
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
    protected FileRepositoryInterface $fileRepository,
  ) {}

  /**
   * Imports every landing page in a content file as a Canvas page.
   *
   * @param array $data
   *   Decoded content file with a "content" list.
   *
   * @return array
   *   Lists keyed by "created", "skipped" and "warnings".
   */
  public function import(array $data): array {
    $result = ['created' => [], 'skipped' => [], 'warnings' => []];
    $this->notices = [];
    $this->items = array_column($data['content'] ?? [], NULL, 'id');
    $storage = $this->entityTypeManager->getStorage('canvas_page');

    foreach ($data['content'] ?? [] as $item) {
      if (($item['type'] ?? '') !== 'node.landing_page') {
        continue;
      }
      $alias = ($item['path'] ?? '') === '/' ? self::HOME_ALIAS : ($item['path'] ?? NULL);
      $title = $item['values']['title'] ?? 'Untitled';
      if ($alias && $this->aliasExists($alias)) {
        $result['skipped'][] = "$title ($alias already exists)";
        continue;
      }

      $tree = [];
      $missing = [];
      foreach ($item['values']['sections'] ?? [] as $ref) {
        $this->addComponent($ref, $tree, $missing);
      }
      // Never publish a partial page: a missing component means the
      // frontend's library has not been synced (drush dc-canvas:sync).
      if ($missing) {
        array_push($result['warnings'], ...array_unique($missing));
        $result['skipped'][] = "$title (components missing; sync the frontend first)";
        continue;
      }

      $page = $storage->create([
        'title' => $title,
        'status' => TRUE,
        'components' => $tree,
      ] + ($alias ? ['path' => ['alias' => $alias]] : []));
      $violations = $page->validate();
      if (count($violations)) {
        foreach ($violations as $violation) {
          $result['warnings'][] = "$title: {$violation->getPropertyPath()}: {$violation->getMessage()}";
        }
        $result['skipped'][] = "$title (invalid component tree)";
        continue;
      }
      $page->save();
      $result['created'][] = "$title → " . ($alias ?: $page->toUrl()->toString());
    }
    array_push($result['warnings'], ...array_unique($this->notices));
    return $result;
  }

  /**
   * Appends a paragraph reference (and its children) to a component tree.
   */
  protected function addComponent(string $ref, array &$tree, array &$warnings, ?string $parent = NULL, ?string $slot = NULL): void {
    $item = $this->items[ltrim($ref, '@')] ?? NULL;
    if (!$item) {
      $warnings[] = "Unresolved reference $ref";
      return;
    }
    $bundle = preg_replace('/^paragraph\./', '', $item['type']);
    $component = Component::load('js.' . (self::COMPONENT_ALIASES[$bundle] ?? $bundle));
    if (!$component) {
      $warnings[] = "No Canvas component for paragraph type $bundle";
      return;
    }
    $definitions = $component->getSettings()['prop_field_definitions'] ?? [];

    $uuid = $this->uuid->generate();
    $inputs = [];
    $children = [];
    foreach ($item['values'] ?? [] as $field => $value) {
      $prop = lcfirst(str_replace('_', '', ucwords($field, '_')));
      // A list of paragraph references is a slot of child components.
      if (is_array($value) && isset($value[0]) && is_string($value[0]) && str_starts_with($value[0], '@')) {
        $children[$prop] = $value;
        continue;
      }
      if (!isset($definitions[$prop]) || $value === NULL || $value === '') {
        continue;
      }
      $value = $this->toInput($value, $definitions[$prop], $warnings);
      if ($value !== NULL) {
        $inputs[$prop] = $value;
      }
    }
    // Required enum props have no "empty" option in Canvas; fall back to the
    // component's default when the paragraph left them blank.
    foreach ($definitions as $prop => $definition) {
      if (!isset($inputs[$prop]) && !empty($definition['required']) && isset($definition['default_value'][0]['value'])) {
        $inputs[$prop] = $definition['default_value'][0]['value'];
      }
    }

    $tree[] = [
      'uuid' => $uuid,
      'component_id' => $component->id(),
      'component_version' => $component->getActiveVersion(),
      'inputs' => $inputs,
    ] + ($parent ? ['parent_uuid' => $parent, 'slot' => $slot] : []);

    foreach ($children as $child_slot => $refs) {
      foreach ($refs as $child_ref) {
        $this->addComponent($child_ref, $tree, $warnings, $uuid, $child_slot);
      }
    }
  }

  /**
   * Converts a content-file value to a Canvas component input.
   */
  protected function toInput(mixed $value, array $definition, array &$warnings): mixed {
    switch ($definition['field_type'] ?? '') {
      case 'entity_reference':
        $media_id = is_array($value) && !empty($value['url']) ? $this->importImage($value['url'], $value['alt'] ?? '', $warnings) : NULL;
        return $media_id ? ['target_id' => $media_id] : NULL;

      case 'link':
        // A bare "#" is a placeholder for "no link yet"; Canvas rejects it.
        return is_string($value) && $value !== '#' ? $value : NULL;

      case 'boolean':
        return (bool) $value;

      case 'integer':
        return (int) $value;

      case 'list_string':
        return (string) $value;
    }
    // Plain string lists (e.g. pricing features) become a rich-text list.
    if (is_array($value)) {
      return '<ul>' . implode('', array_map(fn($line) => '<li>' . Html::escape((string) $line) . '</li>', $value)) . '</ul>';
    }
    return is_scalar($value) ? (string) $value : NULL;
  }

  /**
   * Downloads a remote image into the media library, once per URL.
   */
  protected function importImage(string $url, string $alt, array &$warnings): ?int {
    $media_storage = $this->entityTypeManager->getStorage('media');
    $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], TRUE) ? $extension : 'jpg';
    $name = 'import-' . substr(hash('sha256', $url), 0, 16) . '.' . $extension;

    if ($existing = $media_storage->loadByProperties(['bundle' => 'image', 'name' => $name])) {
      return (int) reset($existing)->id();
    }
    try {
      $directory = 'public://canvas-import';
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $body = (string) $this->httpClient->request('GET', $url, ['timeout' => 30])->getBody();
      $file = $this->fileRepository->writeData($body, "$directory/$name", FileExists::Replace);
      $media = $media_storage->create([
        'bundle' => 'image',
        'name' => $name,
        'field_media_image' => ['target_id' => $file->id(), 'alt' => $alt],
        'status' => TRUE,
      ]);
      $media->save();
      return (int) $media->id();
    }
    catch (\Throwable $e) {
      $this->notices[] = "Image $url could not be imported: {$e->getMessage()}";
      return NULL;
    }
  }

  /**
   * Whether a path alias is already taken.
   */
  protected function aliasExists(string $alias): bool {
    return (bool) $this->entityTypeManager->getStorage('path_alias')->loadByProperties(['alias' => $alias]);
  }

}
