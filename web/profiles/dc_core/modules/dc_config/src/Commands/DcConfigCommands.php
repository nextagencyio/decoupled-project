<?php

namespace Drupal\dc_config\Commands;

use Drupal\Component\Utility\Random;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for dc_config module.
 */
class DcConfigCommands extends DrushCommands {

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
  ) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * Initialize a site restored from a template DB snapshot.
   *
   * Called by clone-tenant.sh's --rotate-secrets path after the volume
   * snapshot is restored, so the cloned tenant doesn't ship with the
   * source space's secrets baked in.
   *
   * Steps:
   *   1. Regenerate OAuth2 RSA keypair.
   *   2. Rotate all non-default consumer secrets (client_secret).
   *   3. Reset admin user password (and optionally email).
   *   4. Update site name (and optionally base_url).
   *   5. Clear per-tenant secrets baked into Drupal config + state by
   *      the source: dc_brand.settings.build_hook.url,
   *      dc_brand.settings.preview.url, dc_import.space_auth_token.
   *   6. Clear caches.
   *   7. Emit credentials as JSON to stdout for capture.
   *
   * @command dc:init-site
   * @option admin-password Admin user password (generated if omitted).
   * @option admin-email    Admin user email address.
   * @option site-name      Human-readable site name.
   * @option base-url       Public base URL (e.g. https://mysite.decoupled.website).
   * @usage drush dc:init-site --admin-password=secret --admin-email=admin@example.com --site-name=mysite --base-url=https://mysite.decoupled.website
   */
  public function initSite(array $options = [
    'admin-password' => NULL,
    'admin-email'    => NULL,
    'site-name'      => NULL,
    'base-url'       => NULL,
  ]): void {
    $random = new Random();

    // 1. Regenerate OAuth RSA keypair.
    $site_path = \Drupal::getContainer()->getParameter('site.path');
    $private_path = $site_path . '/files/private/oauth';

    if (!is_dir($private_path)) {
      mkdir($private_path, 0755, TRUE);
    }

    // Delete existing keys so the generator creates fresh ones.
    foreach (['private.key', 'public.key'] as $file) {
      $path = $private_path . '/' . $file;
      if (file_exists($path)) {
        unlink($path);
      }
    }

    /** @var \Drupal\simple_oauth\Service\KeyGeneratorService $key_generator */
    $key_generator = \Drupal::service('simple_oauth.key.generator');
    $key_generator->generateKeys($private_path);

    // simple_oauth requires 0600/0660 on its key files.
    foreach (['private.key', 'public.key'] as $file) {
      $path = $private_path . '/' . $file;
      if (file_exists($path)) {
        chmod($path, 0600);
      }
    }

    // 2. Rotate secrets for all non-default consumers and collect credentials.
    $consumer_storage = $this->entityTypeManager->getStorage('consumer');
    $consumers = $consumer_storage->loadMultiple();

    $consumer_credentials = [];
    foreach ($consumers as $consumer) {
      $label = $consumer->label();
      if ($label === 'Default Consumer') {
        continue;
      }
      $new_secret = $random->word(32);
      $consumer->set('secret', $new_secret);
      $consumer->save();
      $consumer_credentials[$label] = [
        'client_id' => $consumer->getClientId(),
        'client_secret' => $new_secret,
      ];
    }

    // 3. Set admin user password and email.
    $admin_password = $options['admin-password'] ?? bin2hex(random_bytes(8));
    $admin_email    = $options['admin-email'] ?? NULL;

    /** @var \Drupal\user\UserInterface $admin */
    $admin = $this->entityTypeManager->getStorage('user')->load(1);
    if ($admin) {
      $admin->setPassword($admin_password);
      if ($admin_email) {
        $admin->setEmail($admin_email);
      }
      $admin->save();
    }

    // 4. Update site name and base_url.
    $site_name = $options['site-name'];
    $base_url   = $options['base-url'];

    if ($site_name || $base_url) {
      $site_config = $this->configFactory->getEditable('system.site');
      if ($site_name) {
        $site_config->set('name', $site_name);
      }
      $site_config->save();
    }

    // 5. Clear per-tenant secrets baked into Drupal config + state by
    //    the source tenant. If we leave these in place, the cloned
    //    site triggers builds and previews against the source's
    //    Netlify site (build_hook.url) and authenticates dashboard
    //    callbacks as the source space (space_auth_token). The
    //    dashboard's frontend provision flow + drupal-ready hook
    //    push fresh values back in after this command runs.
    $brand_config = $this->configFactory->getEditable('dc_brand.settings');
    $brand_config->set('build_hook.url', '');
    $brand_config->set('preview.url', '');
    $brand_config->save();
    \Drupal::state()->delete('dc_import.space_auth_token');

    // 6. Clear caches.
    drupal_flush_all_caches();

    // 7. Output credentials as JSON for the init container to capture.
    $output = [
      'admin_password' => $admin_password,
      'admin_email'    => $admin_email ?? ($admin ? $admin->getEmail() : ''),
      'site_name'      => $site_name ?? '',
      'base_url'       => $base_url ?? '',
      'consumers'      => $consumer_credentials,
    ];

    $this->output()->writeln(json_encode($output, JSON_PRETTY_PRINT));
  }

}
