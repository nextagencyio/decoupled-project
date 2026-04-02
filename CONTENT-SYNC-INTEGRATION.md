# Content Sync Integration — Technical Findings

## How Registration Actually Works (from code review)

The Content Sync module uses a **JWT-based registration flow** through an embedded iframe:

```
1. Drupal admin → /admin/config/services/cms-content-sync
2. Admin UI loads iframe from Content Sync Cloud
3. User authenticates / creates project in the iframe
4. Content Sync Cloud returns a JWT token to the iframe
5. Drupal's Embed controller receives the JWT
6. Drupal calls registerSiteWithJwt() with the JWT
7. Sync Core API registers the site, returns a UUID
8. Site UUID + auto-generated secret stored in Drupal config
9. All future communication uses short-lived JWTs signed with the shared secret
```

**Key file:** `vendor/edge-box/sync-core/src/V2/SyncCore.php` → `registerSiteWithJwt($options)`

### What registerSiteWithJwt sends to the Sync Core:

```php
$dto = new RegisterSiteDto($options);
$dto->setBaseUrl($this->application->getSiteBaseUrl());  // Drupal site URL
$dto->setSecret($this->getSiteSecret());                  // 64-char auto-generated
$dto->setRestUrls($this->getRestUrls());                  // REST endpoints
$dto->setAuthenticationType($type);                        // cookie or basic_auth
$dto->setAuthenticationUsername($auth['username']);
// JWT is passed as authorization for the registration request
```

### Registration response:

```php
$entity = $this->sendToSyncCoreWithJwtAndExpect($request, SiteEntity::class, $options['jwt']);
$siteId = $entity->getUuid();
$this->application->setSiteUuid($siteId);  // Stored in Drupal state
```

## Auto-Config Approach (Similar to Vercel Integration)

### The Flow We Could Build:

```
1. User clicks "Enable Content Sync" in Decoupled.io dashboard
2. Dashboard opens Content Sync Cloud in a popup/redirect
   → URL: https://app.content-sync.io/embed/register?redirectUrl=...&appType=drupal&baseUrl=...
3. User logs in / selects project on Content Sync
4. Content Sync redirects back to our dashboard with a JWT
5. Our dashboard passes the JWT to the Drupal space via API
6. Drupal calls registerSiteWithJwt(jwt) → site is registered
7. Our dashboard calls drush to auto-configure flows
8. Done — content sync is working
```

This is essentially the same pattern as our Vercel OAuth integration — the user authenticates on the third-party service, we get a token back, and use it to configure the Drupal site.

### What We Need from Content Sync:

1. **Embed registration URL** — We need the exact URL pattern for the registration embed/redirect flow. The module uses `$this->core->getCloudEmbedUrl()` + route parameters.

2. **Redirect URL support** — The `RegisterSiteEmbed` class already has `$options['redirectUrl']` — this is how the JWT gets back to Drupal after registration.

3. **Confirmation that we can initiate registration from outside the Drupal admin** — The current flow goes through the Drupal admin iframe, but we'd want to trigger it from our dashboard.

## Programmatic Setup (What We Can Do Today)

### 1. Create a Pool

```php
use Drupal\cms_content_sync\Entity\Pool;

Pool::createPool(
  'Decoupled Pool',           // Pool name
  'decoupled_pool',           // Pool machine name
  'https://cloud.content-sync.io/api/sync-core',  // Backend URL
  'cookie'                    // Authentication type
);
```

### 2. Create a Flow with all node types

```php
use Drupal\cms_content_sync\Controller\FlowControllerSimple;

$helper = FlowControllerSimple::createFlow(
  'push',                     // Flow type: push or pull
  'Push All Content',         // Flow name
  'push_all_content',         // Machine name
  TRUE,                       // Active
  NULL,                       // All pools
);

// If $helper is a SimpleFlowSetupHelper (not just a string):
if (is_object($helper)) {
  $helper->configureEntityTypeSettings(['node']);
  $helper->save();
}
```

### 3. Export config to Sync Core

```bash
drush cse  # Exports pool/flow config to the Sync Core
```

## Drush Commands Available

| Command | What it does |
|---------|-------------|
| `drush cse` | Export config to Sync Core (required after any entity/field changes) |
| `drush cs-push <flow>` | Push all entities from a flow |
| `drush cms_content_sync:pull <flow>` | Pull entities for a flow |
| `drush cspep` | Start polling for private environments (localhost) |

## settings.php Overrides

```php
// Override site name (useful for dev/stage/prod)
$settings["cms_content_sync_site_name"] = "my-site-staging";

// Override base URL
$settings["cms_content_sync_base_url"] = "https://my-site.example.com";

// Enable/disable flows per environment
$config["cms_content_sync.flow.push_all_content"]["status"] = TRUE;
```

## Key Classes

| Class | Purpose |
|-------|---------|
| `FlowControllerSimple` | Creates flows programmatically via `createFlow()` |
| `SimpleFlowSetupHelper` | Returned by `createFlow()` — configure entity types, pools, behaviors |
| `Pool::createPool()` | Creates pool config programmatically |
| `SyncCore::registerSiteWithJwt()` | Registers a site with the Sync Core using a JWT |
| `RegisterSiteEmbed` | Handles the iframe-based registration flow |
| `EmbedService` | Service class for all embed operations |

## Module Dependencies

```
drupal/cms_content_sync (3.2.1)
├── drupal/webhooks (^1.0)
├── drupal/encrypt (^3.0)
├── drupal/real_aes (^2.0)
└── edge-box/sync-core (^3.2.11)
    └── edge-box/sync-core-v2-raw-client (auto-generated OpenAPI client)
```

## Next Steps

1. **Test the programmatic flow creation** — Try the drush commands on local Docker
2. **Contact Edge Box** about the embed registration URL pattern — can we use it from outside the Drupal admin?
3. **Build a dashboard integration** that opens the Content Sync registration in a popup, captures the JWT, and passes it to the Drupal space
4. **Create an MCP tool** `configure_content_sync` that enables the module and creates flows
