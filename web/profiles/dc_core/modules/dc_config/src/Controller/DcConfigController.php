<?php

namespace Drupal\dc_config\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\dc_config\Service\VercelApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\Random;

/**
 * Controller for Decoupled Drupal configuration page.
 */
class DcConfigController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Vercel API service.
   *
   * @var \Drupal\dc_config\Service\VercelApiService
   */
  protected $vercelApi;

  /**
   * Constructs a DcConfigController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\dc_config\Service\VercelApiService $vercel_api
   *   The Vercel API service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    VercelApiService $vercel_api
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->vercelApi = $vercel_api;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('dc_config.vercel_api')
    );
  }

  /**
   * Homepage that shows configuration information.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   A render array or redirect response.
   */
  public function homePage() {
    // Check if user has permission to administer site configuration.
    if (!$this->currentUser()->hasPermission('administer site configuration')) {
      return new RedirectResponse('/user');
    }

    // Show the configuration page for authorized users.
    return $this->configPage();
  }

  /**
   * Helper function to create a code block with copy and download buttons.
   *
   * @param string $code
   *   The code content.
   * @param string $language
   *   The language type (optional).
   * @param string $title
   *   The title for the code block (optional).
   * @param bool $show_download
   *   Whether to show the download button (optional).
   *
   * @return string
   *   HTML markup for code block with copy and download buttons.
   */
  private function createCodeBlock($code, $language = '', $title = '', $show_download = FALSE) {
    $id = 'code-' . uniqid();
    $title_html = $title ? '<div class="dc-config-code-title">' . $title . '</div>' : '';
    $has_title_class = $title ? ' has-title' : '';

    $download_button = '';
    if ($show_download) {
      $download_button = '<button class="dc-config-download-button" data-target="' . $id . '" data-filename=".env.local" title="Download as .env file">
          💾 Download
        </button>';
    }

    return '<div class="dc-config-code-block' . $has_title_class . '">
      ' . $title_html . '
      <div class="dc-config-code-content">
        <pre id="' . $id . '">' . htmlspecialchars($code) . '</pre>
        <div class="dc-config-buttons">
          <button class="dc-config-copy-button" data-target="' . $id . '" title="Copy to clipboard">
            📋 Copy
          </button>
          ' . $download_button . '
        </div>
      </div>
    </div>';
  }

  /**
   * Fetch starters from the solutions registry.
   *
   * @return array
   *   Array of starter data.
   */
  private function fetchStartersRegistry() {
    $cid = 'dc_config:starters_registry';
    $cache = \Drupal::cache()->get($cid);

    if ($cache) {
      return $cache->data;
    }

    try {
      $client = \Drupal::httpClient();
      $response = $client->get('https://www.decoupled.io/solutions.json', [
        'timeout' => 5,
      ]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
      $starters = $data['starters'] ?? [];

      // Cache for 1 hour.
      \Drupal::cache()->set($cid, $starters, time() + 3600);

      return $starters;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->warning('Failed to fetch starters registry: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get SVG icon markup for a starter.
   *
   * @param string $icon
   *   The icon name.
   *
   * @return string
   *   SVG markup.
   */
  private function getIconSvg($icon) {
    $icons = [
      'newspaper' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2"/><path d="M18 14h-8"/><path d="M15 18h-5"/><path d="M10 6h8v4h-8V6Z"/></svg>',
      'shopping-cart' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>',
      'message-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>',
      'search' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
      'graduation-cap' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
      'globe' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>',
      'credit-card' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>',
      'plus' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5v14"/></svg>',
    ];

    return $icons[$icon] ?? $icons['plus'];
  }

  /**
   * Build the starter selection grid HTML.
   *
   * @return string
   *   HTML markup for the starter grid.
   */
  private function buildStarterGrid() {
    $starters = $this->fetchStartersRegistry();

    if (empty($starters)) {
      return '';
    }

    $cards = '';
    foreach ($starters as $starter) {
      $contentUrl = $starter['contentUrl'] ?? '';
      $vercelUrl = $starter['vercelUrl'] ?? '';
      $hasContent = !empty($contentUrl);
      $disabledClass = $hasContent ? '' : ' dc-config-starter-card--no-content';

      $cards .= '<div class="dc-config-starter-card' . $disabledClass . '"
                      data-starter-id="' . htmlspecialchars($starter['id']) . '"
                      data-content-url="' . htmlspecialchars($contentUrl) . '"
                      data-vercel-url="' . htmlspecialchars($vercelUrl) . '">
        <div class="dc-config-starter-icon" style="background-color: ' . htmlspecialchars($starter['color']) . '">
          ' . $this->getIconSvg($starter['icon']) . '
        </div>
        <div class="dc-config-starter-name">' . htmlspecialchars($starter['name']) . '</div>
      </div>';
    }

    return '
    <div class="dc-config-section dc-config-starter-section">
      <h2>Choose a Starter Template</h2>
      <p class="dc-config-subtitle">Select a template to import content types and sample data into your Drupal site.</p>
      <div class="dc-config-starter-grid">' . $cards . '</div>
      <button type="button" class="dc-config-import-btn" id="import-starter-btn" disabled>
        Import Selected Starter
      </button>
      <div id="import-status" class="dc-config-import-status"></div>
    </div>

    <div class="dc-config-divider">
      <span>then deploy to Vercel</span>
    </div>
    ';
  }

  /**
   * Displays the Next.js configuration page.
   *
   * @return array
   *   A render array.
   */
  public function configPage() {
    $build = [];

    // Attach the custom library for styling and JavaScript.
    $build['#attached']['library'][] = 'dc_config/dc_config';

    // Get Vercel connection status.
    $vercel_status = $this->vercelApi->getConnectionStatus();
    $vercel_connected = $vercel_status['connected'];
    $vercel_project_name = $vercel_status['project_name'];
    $vercel_last_synced = $vercel_status['last_synced'];

    // Pass Vercel status to JavaScript.
    $build['#attached']['drupalSettings']['dcConfig'] = [
      'vercelConnected' => $vercel_connected,
      'vercelProjectName' => $vercel_project_name,
      'vercelLastSynced' => $vercel_last_synced,
      'csrfToken' => \Drupal::csrfToken()->get('dc_config_vercel_sync'),
      'disconnectToken' => \Drupal::csrfToken()->get('dc_config_vercel_disconnect'),
    ];

    // Get or create Next.js consumer information.
    $client_id = '';
    $client_secret = '';

    try {
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      // Clear cache to ensure we get fresh data
      $consumer_storage->resetCache();
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);

      if (empty($consumers)) {
        // Create the OAuth consumer automatically.
        $consumer = $this->createOAuthConsumer($consumer_storage);
        if (!$consumer) {
          // If consumer creation fails, generate temporary values.
          $client_id = 'TEMP_' . bin2hex(random_bytes(16));
          $client_secret = bin2hex(random_bytes(16));

          $build['warning'] = [
            '#type' => 'markup',
            '#markup' => '<div class="messages messages--warning">
              <h3>⚠️ Temporary Configuration</h3>
              <p>OAuth consumer could not be created automatically. Using temporary values below.</p>
              <p>For production use, please create the OAuth consumer manually or run: <code>ddev drush php:script scripts/consumers-next.php</code></p>
            </div>',
          ];
        }
        else {
          $client_id = $consumer->getClientId();
          $client_secret = $consumer->get('secret')->value;
        }
      }
      else {
        $consumer = reset($consumers);
        $client_id = $consumer->getClientId();

        // Get secret from consumer (only works if not hashed)
        $stored_secret = $consumer->get('secret')->value;

        if (empty($stored_secret) || preg_match('/^\$2[ayb]\$/', $stored_secret)) {
          // Secret is hashed or missing - user needs to generate a new one
          // (Vercel sync will auto-generate fresh secrets)
          $client_secret = '[Click "Generate New Client Secret" below]';
        }
        else {
          $client_secret = $stored_secret;
        }
      }
    }
    catch (\Exception $e) {
      // If all else fails, provide temporary values.
      $client_id = 'TEMP_' . bin2hex(random_bytes(16));
      $client_secret = bin2hex(random_bytes(16));

      $build['error'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">
          <h3>⚠️ Temporary Configuration</h3>
          <p>Could not access OAuth consumer storage. Using temporary values below.</p>
          <p>Error: ' . htmlspecialchars($e->getMessage()) . '</p>
          <p>For production use, please ensure all required modules are installed and configured properly.</p>
        </div>',
      ];
    }

    // Get Next.js settings.
    $next_config = $this->configFactory->get('next.settings');
    $next_base_url = $next_config->get('base_url') ?: 'http://host.docker.internal:3333';

    // Get revalidate secret from dc_revalidate.settings
    $revalidate_config = $this->configFactory->get('dc_revalidate.settings');
    $revalidate_secret = $revalidate_config->get('revalidate_secret');

    // Generate revalidation secret if it doesn't exist
    if (empty($revalidate_secret) || $revalidate_secret === 'not-set') {
      $random = new Random();
      $revalidate_secret = bin2hex(random_bytes(16));

      // Save the new revalidation secret to dc_revalidate.settings
      $revalidate_config_editable = $this->configFactory->getEditable('dc_revalidate.settings');
      $revalidate_config_editable->set('revalidate_secret', $revalidate_secret);
      $revalidate_config_editable->save();
    }

    // Get current site URL.
    global $base_url;
    $site_url = $base_url ?: \Drupal::request()->getSchemeAndHttpHost();

    // Determine if this is a local development environment.
    $host = parse_url($site_url, PHP_URL_HOST);
    $is_local = (strpos($host, 'localhost') !== FALSE || strpos($host, '127.0.0.1') !== FALSE || strpos($host, '.local') !== FALSE);

    // Use HTTPS for production URLs.
    if (!$is_local && parse_url($site_url, PHP_URL_SCHEME) === 'http') {
      $site_url = str_replace('http://', 'https://', $site_url);
    }

    // Prepare code blocks.
    $env_content = "# Required - Drupal backend URL
NEXT_PUBLIC_DRUPAL_BASE_URL=" . $site_url . "
NEXT_IMAGE_DOMAIN=" . parse_url($site_url, PHP_URL_HOST) . "

# Authentication - OAuth credentials
DRUPAL_CLIENT_ID=" . $client_id . "
DRUPAL_CLIENT_SECRET=" . $client_secret . "

# Required for On-demand Revalidation
DRUPAL_REVALIDATE_SECRET=" . $revalidate_secret;

    // Only include NODE_TLS_REJECT_UNAUTHORIZED for local development.
    if ($is_local) {
      $env_content .= "

# Allow self-signed certificates for development
NODE_TLS_REJECT_UNAUTHORIZED=0";
    }

    $npm_run_dev = "npm run dev";

    // Build starter grid.
    $starter_grid = $this->buildStarterGrid();

    $build['instructions'] = [
      '#type' => 'markup',
      '#markup' => '<div class="dc-config-main-layout">
        <div class="dc-config-content-area">
          <div class="dc-config-header">
            <h1>Your headless CMS is ready!</h1>
            <p>Follow the steps below to connect your Next.js frontend and start building.</p>
          </div>

          <div class="dc-config-main-content">
            <!-- Starter Selection Section -->
            ' . $starter_grid . '

            <!-- Vercel Integration Section -->
            <div class="dc-config-vercel-hero">
              ' . ($vercel_connected ?
                // Connected State - Full width card
                '<div class="dc-config-vercel-connected-card">
                  <div class="dc-config-vercel-connected-header">
                    <div class="dc-config-vercel-connected-status">
                      <span class="dc-config-status-dot dc-config-status-dot--connected"></span>
                      <span>Connected to Vercel</span>
                    </div>
                  </div>

                  <div class="dc-config-vercel-connected-body">
                    <div class="dc-config-vercel-project-selector">
                      <label for="vercel-project">Select a project to sync environment variables:</label>
                      <select id="vercel-project" class="dc-config-select">
                        <option value="">Loading projects...</option>
                      </select>
                    </div>

                    <div class="dc-config-vercel-actions">
                      <button type="button" id="vercel-sync-btn" class="dc-config-sync-button" disabled>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                        Sync Environment Variables
                      </button>
                      <div id="vercel-sync-status" class="dc-config-sync-status"></div>
                    </div>

                    ' . ($vercel_last_synced ? '<p class="dc-config-vercel-last-sync">Last synced: ' . date('M j, Y g:i A', $vercel_last_synced) . '</p>' : '') . '
                  </div>

                  <div class="dc-config-vercel-connected-footer">
                    <form method="post" action="/dc-config/vercel/disconnect" style="display: inline;">
                      <input type="hidden" name="form_token" value="' . \Drupal::csrfToken()->get('dc_config_vercel_disconnect') . '">
                      <button type="submit" class="dc-config-disconnect-link">Disconnect from Vercel</button>
                    </form>
                  </div>
                </div>'
                :
                // Not Connected State - Sequential steps layout
                '<div class="dc-config-vercel-steps">
                  <!-- Step 1: Deploy -->
                  <div class="dc-config-vercel-step">
                    <div class="dc-config-step-marker">
                      <span class="dc-config-step-number-badge">1</span>
                      <div class="dc-config-step-line"></div>
                    </div>
                    <div class="dc-config-vercel-step-card dc-config-vercel-step-card--deploy">
                      <div class="dc-config-vercel-step-content">
                        <h3>Deploy your Next.js frontend</h3>
                        <p>Create a new project from our starter template with one click.</p>
                      </div>
                      <a href="https://vercel.com/new/clone?repository-url=https://github.com/nextagencyio/decoupled-starter&project-name=my-app"
                         target="_blank"
                         class="dc-config-vercel-deploy-btn">
                        <svg width="18" height="18" viewBox="0 0 76 65" fill="none" xmlns="http://www.w3.org/2000/svg">
                          <path d="m37.5274 0 36.9815 64H.5459Z" fill="currentColor"/>
                        </svg>
                        Deploy with Vercel
                        <span class="dc-config-arrow">→</span>
                      </a>
                    </div>
                  </div>

                  <!-- Step 2: Connect -->
                  <div class="dc-config-vercel-step">
                    <div class="dc-config-step-marker">
                      <span class="dc-config-step-number-badge">2</span>
                    </div>
                    <div class="dc-config-vercel-step-card dc-config-vercel-step-card--connect">
                      <div class="dc-config-vercel-step-content">
                        <h3>Connect to sync environment variables</h3>
                        <p>Automatically configure your Vercel project with the credentials it needs.</p>
                      </div>
                      <a href="/dc-config/vercel/connect" class="dc-config-vercel-connect-btn">
                        <svg width="18" height="18" viewBox="0 0 76 65" fill="none" xmlns="http://www.w3.org/2000/svg">
                          <path d="m37.5274 0 36.9815 64H.5459Z" fill="currentColor"/>
                        </svg>
                        Connect to Vercel
                      </a>
                    </div>
                  </div>
                </div>'
              ) . '
            </div>

            <div class="dc-config-divider">
              <span>or configure manually</span>
            </div>

            <div class="dc-config-section">
              <h2>🔧 Environment Configuration</h2>
              <p>Create or update your <code>.env.local</code> file in your Next.js project root:</p>

              ' . $this->createCodeBlock($env_content, 'env', '.env.local', TRUE) . '

              <div class="dc-config-generate-secret">
                <form method="post" action="/dc-config/generate-secret" style="display: inline;">
                  <input type="hidden" name="form_token" value="' . \Drupal::csrfToken()->get('dc_config_generate_secret') . '">
                  <button type="submit" class="dc-config-generate-button">
                    🔑&nbsp;&nbsp;Generate New Client Secret
                  </button>
                </form>
                <p class="dc-config-generate-help">Generate a new OAuth client secret for enhanced security.</p>
              </div>
            </div>

          </div>
        </div>

        <div class="dc-config-sidebar">
          <div class="dc-config-sidebar-card">
            <div class="dc-config-sidebar-content">
            <h3>
              <span class="dc-config-sidebar-icon">📋</span>
              Setup Checklist
            </h3>
            <div class="dc-config-checklist">
              <div class="dc-config-checklist-item">
                <div class="dc-config-step-number">1</div>
                <div class="dc-config-step-content">
                  <div class="dc-config-step-title">Copy Environment Variables</div>
                  <div class="dc-config-step-description">Add the .env.local configuration to your Next.js project</div>
                </div>
              </div>
              <div class="dc-config-checklist-item">
                <div class="dc-config-step-number">2</div>
                <div class="dc-config-step-content">
                  <div class="dc-config-step-title">Install Dependencies</div>
                  <div class="dc-config-step-description">Run npm install in your project directory</div>
                </div>
              </div>
              <div class="dc-config-checklist-item">
                <div class="dc-config-step-number">3</div>
                <div class="dc-config-step-content">
                  <div class="dc-config-step-title">Start Development Server</div>
                  <div class="dc-config-step-description">Run npm run dev to start your application</div>
                </div>
              </div>
              <div class="dc-config-checklist-item">
                <div class="dc-config-step-number">4</div>
                <div class="dc-config-step-content">
                  <div class="dc-config-step-title">Test Connection</div>
                  <div class="dc-config-step-description">Verify your frontend connects to this Drupal backend</div>
                </div>
              </div>
            </div>

            <div class="dc-config-help-links">
              <h4>📚 Resources</h4>
              <ul>
                <li><a href="https://github.com/nextagencyio/decoupled-starter" target="_blank">Starter Project →</a></li>
                <li><a href="https://nextjs.org/docs" target="_blank">Next.js Docs →</a></li>
                <li><a href="/admin/config" target="_blank">Drupal Config →</a></li>
              </ul>
            </div>
            </div>
          </div>
        </div>
      </div>',
    ];

    // Allow HTML attributes to preserve styling.
    $build['instructions']['#allowed_tags'] = [
      'div',
      'h1',
      'h2',
      'h3',
      'h4',
      'p',
      'pre',
      'code',
      'button',
      'form',
      'input',
      'select',
      'option',
      'label',
      'ul',
      'li',
      'a',
      'span',
      'strong',
      'br',
      'script',
    ];

    return $build;
  }

  /**
   * Creates an OAuth consumer for Next.js frontend.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $consumer_storage
   *   The consumer storage service.
   *
   * @return \Drupal\consumer\Entity\Consumer|null
   *   The created consumer entity or null if creation failed.
   */
  private function createOAuthConsumer($consumer_storage) {
    try {
      $random = new Random();

      $client_id = Crypt::randomBytesBase64();
      $client_secret = $random->word(8);

      // Create previewer consumer data.
      $consumer_data = [
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'label' => 'Next.js Frontend',
        'user_id' => 2,
        'third_party' => TRUE,
        'is_default' => FALSE,
      ];

      // Check if consumer__roles table exists before adding roles.
      $database = \Drupal::database();
      if ($database->schema()->tableExists('consumer__roles')) {
        $consumer_data['roles'] = ['previewer'];
      }

      $consumer = $consumer_storage->create($consumer_data);
      $consumer->save();

      return $consumer;
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Failed to create OAuth consumer: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Generates a new revalidation secret and OAuth client secret.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response back to the configuration page.
   */
  public function generateSecret(Request $request) {
    // Check if user has permission to administer site configuration.
    if (!$this->currentUser()->hasPermission('administer site configuration')) {
      return new RedirectResponse('/user');
    }

    // Verify CSRF token
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_generate_secret')) {
      \Drupal::messenger()->addError('Invalid form token. Please try again.');
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }


    // Also regenerate OAuth client secret if needed
    try {
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);

      if (!empty($consumers)) {
        $consumer = reset($consumers);

        // Always generate a new plain text secret when the button is clicked
        $random = new Random();
        $client_secret = $random->word(8);

        // Set the secret field directly
        $consumer->set('secret', $client_secret);
        $consumer->save();

        // Clear entity cache to ensure fresh data on page reload
        $consumer_storage->resetCache([$consumer->id()]);
        \Drupal::entityTypeManager()->clearCachedDefinitions();

        \Drupal::logger('dc_config')->info('Generated new OAuth client secret');
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Failed to update OAuth consumer secret: @message', ['@message' => $e->getMessage()]);
    }

    // Add success message
    \Drupal::messenger()->addStatus('New client secret generated successfully!');

    // Redirect back to the configuration page
    return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
  }

  /**
   * AJAX endpoint to generate new OAuth client secret.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the new client secret.
   */
  public function generateSecretAjax(Request $request) {
    // Check if user has permission to administer site configuration.
    if (!$this->currentUser()->hasPermission('administer site configuration')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Verify CSRF token
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_generate_secret')) {
      return new JsonResponse(['error' => 'Invalid form token'], 403);
    }

    $response_data = [
      'success' => false,
      'client_secret' => '',
      'client_id' => '',
      'revalidate_secret' => '',
      'message' => ''
    ];

    try {
      // Get existing revalidation secret to preserve it
      $revalidate_config = $this->configFactory->get('dc_revalidate.settings');
      $existing_revalidate_secret = $revalidate_config->get('revalidate_secret');

      // If no existing revalidate secret, generate one
      if (empty($existing_revalidate_secret) || $existing_revalidate_secret === 'not-set') {
        $random = new Random();
        $existing_revalidate_secret = bin2hex(random_bytes(16));

        // Save the new revalidate secret to config
        $revalidate_config_editable = $this->configFactory->getEditable('dc_revalidate.settings');
        $revalidate_config_editable->set('revalidate_secret', $existing_revalidate_secret);
        $revalidate_config_editable->save();
      }

      $response_data['revalidate_secret'] = $existing_revalidate_secret;

      // Regenerate OAuth client secret
      $consumer_storage = $this->entityTypeManager->getStorage('consumer');
      $consumer_storage->resetCache();
      $consumers = $consumer_storage->loadByProperties(['label' => 'Next.js Frontend']);

      if (!empty($consumers)) {
        $consumer = reset($consumers);
        $response_data['client_id'] = $consumer->getClientId();

        // Generate new client secret
        $random = new Random();
        $client_secret = $random->word(8);

        $consumer->set('secret', $client_secret);
        $consumer->save();

        // Clear cache
        $consumer_storage->resetCache([$consumer->id()]);

        $response_data['client_secret'] = $client_secret;
        $response_data['success'] = true;
        $response_data['message'] = 'New client secret generated successfully!';
      } else {
        $response_data['error'] = 'OAuth consumer not found';
      }

    } catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('AJAX secret generation failed: @message', ['@message' => $e->getMessage()]);
      $response_data['error'] = 'Failed to generate client secret: ' . $e->getMessage();
    }

    return new JsonResponse($response_data);
  }

  // ============================================================
  // Vercel OAuth Integration Methods
  // ============================================================

  /**
   * Initiates the Vercel OAuth flow.
   *
   * Redirects to the central MCP server which handles OAuth with Vercel.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the OAuth authorization URL.
   */
  public function vercelConnect() {
    // Check if Vercel OAuth is available on the central server.
    if (!$this->vercelApi->isConfigured()) {
      $this->messenger()->addError($this->t('Vercel integration is not available. Please try again later.'));
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    // Build the callback URL for this Drupal site.
    $callbackUrl = Url::fromRoute('dc_config.vercel_callback', [], ['absolute' => TRUE])->toString();

    // Get the authorization URL from the central server.
    $authUrl = $this->vercelApi->getAuthorizationUrl($callbackUrl);

    \Drupal::logger('dc_config')->info('Initiating Vercel OAuth flow, redirecting to: @url', ['@url' => $authUrl]);

    // Use TrustedRedirectResponse for external URL redirect.
    return new TrustedRedirectResponse($authUrl);
  }

  /**
   * Handles the OAuth callback from the central MCP server.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect response to the configuration page.
   */
  public function vercelCallback(Request $request) {
    $exchangeCode = $request->query->get('exchange_code');
    $teamId = $request->query->get('team_id');
    $error = $request->query->get('error');
    $errorDescription = $request->query->get('error_description');

    // Handle errors.
    if ($error) {
      $message = $errorDescription ?: $error;
      $this->messenger()->addError($this->t('Failed to connect to Vercel: @message', ['@message' => $message]));
      \Drupal::logger('dc_config')->error('Vercel OAuth error: @error - @description', [
        '@error' => $error,
        '@description' => $errorDescription,
      ]);
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    // Validate exchange code.
    if (empty($exchangeCode)) {
      $this->messenger()->addError($this->t('Invalid OAuth callback: missing exchange code.'));
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    // Exchange the code for an access token.
    $success = $this->vercelApi->exchangeCodeForToken($exchangeCode, $teamId);

    if ($success) {
      $this->messenger()->addStatus($this->t('Successfully connected to Vercel! You can now sync environment variables.'));
      \Drupal::logger('dc_config')->info('Vercel OAuth completed successfully');
    }
    else {
      $this->messenger()->addError($this->t('Failed to complete Vercel connection. Please try again.'));
    }

    return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
  }

  /**
   * Disconnects from Vercel.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\JsonResponse
   *   A redirect or JSON response.
   */
  public function vercelDisconnect(Request $request) {
    // Verify CSRF token.
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_vercel_disconnect')) {
      if ($request->isXmlHttpRequest()) {
        return new JsonResponse(['error' => 'Invalid form token'], 403);
      }
      $this->messenger()->addError($this->t('Invalid form token. Please try again.'));
      return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
    }

    $this->vercelApi->disconnect();
    $this->messenger()->addStatus($this->t('Disconnected from Vercel.'));
    \Drupal::logger('dc_config')->info('Disconnected from Vercel');

    if ($request->isXmlHttpRequest()) {
      return new JsonResponse(['success' => TRUE, 'message' => 'Disconnected from Vercel']);
    }

    return new RedirectResponse(Url::fromRoute('dc_config.homepage')->toString());
  }

  /**
   * Returns a list of Vercel projects as JSON.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the list of projects.
   */
  public function vercelProjects() {
    if (!$this->vercelApi->isConnected()) {
      return new JsonResponse(['error' => 'Not connected to Vercel'], 401);
    }

    $projects = $this->vercelApi->getProjects();

    return new JsonResponse([
      'success' => TRUE,
      'projects' => array_map(function ($project) {
        return [
          'id' => $project['id'],
          'name' => $project['name'],
          'framework' => $project['framework'] ?? NULL,
          'link' => $project['link']['productionBranch'] ?? NULL,
        ];
      }, $projects),
    ]);
  }

  /**
   * Syncs environment variables to a Vercel project.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function vercelSync(Request $request) {
    // Verify CSRF token.
    $token = $request->request->get('form_token');
    if (!\Drupal::csrfToken()->validate($token, 'dc_config_vercel_sync')) {
      return new JsonResponse(['error' => 'Invalid form token'], 403);
    }

    if (!$this->vercelApi->isConnected()) {
      return new JsonResponse(['error' => 'Not connected to Vercel'], 401);
    }

    $projectId = $request->request->get('project_id');
    $projectName = $request->request->get('project_name');

    if (empty($projectId)) {
      return new JsonResponse(['error' => 'Missing project_id'], 400);
    }

    // Generate fresh secrets for this sync (more secure than storing plain text).
    $freshSecrets = $this->generateFreshSecrets();
    if (!$freshSecrets['success']) {
      return new JsonResponse(['error' => $freshSecrets['error']], 500);
    }

    // Get the environment variables with fresh secrets.
    $envVars = $this->getEnvironmentVariablesWithSecrets(
      $freshSecrets['client_id'],
      $freshSecrets['client_secret'],
      $freshSecrets['revalidate_secret']
    );

    // Sync to Vercel.
    $success = $this->vercelApi->setEnvironmentVariables($projectId, $envVars);

    if ($success) {
      // Save the connected project info.
      if ($projectName) {
        $this->vercelApi->connectProject($projectId, $projectName);
      }

      \Drupal::logger('dc_config')->info('Synced environment variables to Vercel project: @project (with fresh secrets)', [
        '@project' => $projectName ?: $projectId,
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Environment variables synced with fresh secrets!',
        'variables_synced' => array_keys($envVars),
      ]);
    }

    return new JsonResponse([
      'error' => 'Failed to sync environment variables to Vercel',
    ], 500);
  }

  /**
   * Generate fresh OAuth and revalidation secrets.
   *
   * This regenerates secrets atomically so both Drupal and Vercel stay in sync.
   *
   * @return array
   *   Array with success status and secrets or error message.
   */
  protected function generateFreshSecrets(): array {
    $random = new Random();

    try {
      // Generate new client secret and update consumer.
      $consumerStorage = $this->entityTypeManager->getStorage('consumer');
      $consumers = $consumerStorage->loadByProperties(['label' => 'Next.js Frontend']);

      if (empty($consumers)) {
        return ['success' => FALSE, 'error' => 'OAuth consumer not found'];
      }

      $consumer = reset($consumers);
      $clientId = $consumer->getClientId();
      $clientSecret = $random->word(8);

      // Update the consumer with fresh secret.
      $consumer->set('secret', $clientSecret);
      $consumer->save();
      $consumerStorage->resetCache([$consumer->id()]);

      // Generate or get revalidate secret.
      $revalidateConfig = $this->configFactory->get('dc_revalidate.settings');
      $revalidateSecret = $revalidateConfig->get('revalidate_secret');

      if (empty($revalidateSecret) || $revalidateSecret === 'not-set') {
        $revalidateSecret = bin2hex(random_bytes(16));
        $this->configFactory->getEditable('dc_revalidate.settings')
          ->set('revalidate_secret', $revalidateSecret)
          ->save();
      }

      return [
        'success' => TRUE,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'revalidate_secret' => $revalidateSecret,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Failed to generate fresh secrets: @message', ['@message' => $e->getMessage()]);
      return ['success' => FALSE, 'error' => 'Failed to generate secrets: ' . $e->getMessage()];
    }
  }

  /**
   * Get environment variables with provided secrets.
   *
   * @param string $clientId
   *   The OAuth client ID.
   * @param string $clientSecret
   *   The OAuth client secret (plain text).
   * @param string $revalidateSecret
   *   The revalidation secret.
   *
   * @return array
   *   Array of environment variable key-value pairs.
   */
  protected function getEnvironmentVariablesWithSecrets(string $clientId, string $clientSecret, string $revalidateSecret): array {
    global $base_url;
    $siteUrl = $base_url ?: \Drupal::request()->getSchemeAndHttpHost();

    // Use HTTPS for production URLs.
    $host = parse_url($siteUrl, PHP_URL_HOST);
    $isLocal = (strpos($host, 'localhost') !== FALSE || strpos($host, '127.0.0.1') !== FALSE || strpos($host, '.local') !== FALSE);
    if (!$isLocal && parse_url($siteUrl, PHP_URL_SCHEME) === 'http') {
      $siteUrl = str_replace('http://', 'https://', $siteUrl);
    }

    return [
      'NEXT_PUBLIC_DRUPAL_BASE_URL' => $siteUrl,
      'NEXT_IMAGE_DOMAIN' => parse_url($siteUrl, PHP_URL_HOST),
      'DRUPAL_CLIENT_ID' => $clientId,
      'DRUPAL_CLIENT_SECRET' => $clientSecret,
      'DRUPAL_REVALIDATE_SECRET' => $revalidateSecret,
    ];
  }

  /**
   * Get the Vercel connection status.
   *
   * @return array
   *   The connection status.
   */
  public function getVercelStatus(): array {
    return $this->vercelApi->getConnectionStatus();
  }

  // ============================================================
  // Starter Content Import Methods
  // ============================================================

  /**
   * Import starter content from a remote JSON URL.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function importStarter(Request $request) {
    $contentUrl = $request->request->get('content_url');

    if (empty($contentUrl)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'No content URL provided',
      ], 400);
    }

    // Validate URL is from allowed domains (GitHub raw content).
    $allowedDomains = [
      'raw.githubusercontent.com',
      'gist.githubusercontent.com',
    ];
    $urlHost = parse_url($contentUrl, PHP_URL_HOST);
    if (!in_array($urlHost, $allowedDomains)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Content URL must be from GitHub raw content',
      ], 400);
    }

    try {
      // Fetch content JSON from the remote URL.
      $client = \Drupal::httpClient();
      $response = $client->get($contentUrl, [
        'timeout' => 30,
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);

      $contentJson = json_decode($response->getBody()->getContents(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid JSON in content file: ' . json_last_error_msg(),
        ], 400);
      }

      // Check if dc_import service exists.
      if (\Drupal::hasService('dc_import.content_importer')) {
        $importer = \Drupal::service('dc_import.content_importer');
        $result = $importer->import($contentJson);

        return new JsonResponse([
          'success' => TRUE,
          'message' => 'Content imported successfully',
          'stats' => $result,
        ]);
      }

      // Fallback: Call internal dc-import API endpoint.
      // Get the base URL of this site.
      global $base_url;
      $siteUrl = $base_url ?: \Drupal::request()->getSchemeAndHttpHost();

      // Make internal request to /api/dc-import.
      $importResponse = $client->post($siteUrl . '/api/dc-import', [
        'json' => $contentJson,
        'timeout' => 120,
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

      $importResult = json_decode($importResponse->getBody()->getContents(), TRUE);

      if (!empty($importResult['success']) || !empty($importResult['status'])) {
        return new JsonResponse([
          'success' => TRUE,
          'message' => $importResult['message'] ?? 'Content imported successfully',
          'stats' => $importResult['stats'] ?? $importResult,
        ]);
      }

      return new JsonResponse([
        'success' => FALSE,
        'error' => $importResult['error'] ?? 'Import failed with unknown error',
      ], 500);

    }
    catch (\GuzzleHttp\Exception\RequestException $e) {
      \Drupal::logger('dc_config')->error('Failed to fetch content from @url: @message', [
        '@url' => $contentUrl,
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Failed to fetch content: ' . $e->getMessage(),
      ], 500);
    }
    catch (\Exception $e) {
      \Drupal::logger('dc_config')->error('Import failed: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Import failed: ' . $e->getMessage(),
      ], 500);
    }
  }

}
