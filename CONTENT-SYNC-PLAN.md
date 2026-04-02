# Content Sync Integration Plan

## Overview

Add Content Sync (content-sync.io) as a plug-and-play feature for Decoupled.io spaces. Content Sync enables content sharing and synchronization between Drupal sites — useful for staging/production workflows, multi-site content distribution, and content migration.

## Current State

- Existing Content Sync project at `https://app.content-sync.io/projects/6830786c5436d550036e9faa/`
- Previously tested with DrupalX
- No integration in the current Decoupled.io platform

## Architecture

```
Decoupled.io Space (Drupal)
├── cms_content_sync module
├── Flow config (push/pull)
├── Pool config (which content syncs where)
└── Connected to Sync Core ←→ Content Sync Cloud (content-sync.io)
                                   ↕
                              Other Drupal Sites
```

## Drupal Module Setup

### Package

```bash
composer require drupal/cms_content_sync
drush en cms_content_sync
```

### Dependencies (auto-installed)

- `drupal/webhooks` (^1.0)
- `drupal/encrypt` (^3.0)
- `drupal/real_aes` (^2.0)
- `edge-box/sync-core` (^3.2.11)

### Programmatic Configuration

Using `SimpleFlowSetupHelper` via drush:

```bash
drush ev "
  use Drupal\cms_content_sync\Controller\FlowControllerSimple;

  // Create a push flow for all node types
  \$helper = FlowControllerSimple::createFlow('push', 'Push All Content');
  
  // Option A: Enable specific bundles
  \$helper->enableBundle('node', 'article');
  \$helper->enableBundle('node', 'page');
  \$helper->enableBundle('node', 'landing_page');
  
  // Option B: Auto-configure all node types (from the CEO's instructions)
  // \$helper->configureEntityTypeSettings(['node']);
  
  \$helper->save();
  echo 'Flow created successfully';
"
```

## Auto-Configuration Plan

### Phase 1: Module Installation + Basic Flow (Manual)

**Goal:** Add `cms_content_sync` to the Drupal project so all new spaces have it available.

1. Add to `composer.json` in `decoupled-project`
2. Add to `dc_core` install profile as an optional dependency
3. Create an update hook or drush command that:
   - Creates a default push flow
   - Configures all node types for sync
   - Sets up a default pool

**What's still manual:**
- User must register at content-sync.io and get a project
- User must enter the Sync Core URL/credentials in Drupal admin
- User must configure which pool their space belongs to

### Phase 2: MCP Tool for Configuration

**Goal:** AI assistants can configure Content Sync via MCP.

New MCP tool: `configure_content_sync`

```json
{
  "name": "configure_content_sync",
  "arguments": {
    "spaceId": 123,
    "mode": "push",           // push | pull | both
    "contentTypes": ["article", "page", "landing_page"]
  }
}
```

This would:
1. Enable the module if not already enabled
2. Create a flow with the specified mode
3. Configure the specified content types
4. Return instructions for connecting to content-sync.io

### Phase 3: OAuth Integration (Ideal)

**Goal:** One-click Content Sync setup from the Decoupled.io dashboard.

**How Vercel does it (for reference):**
1. User clicks "Add Integration" on Vercel
2. Vercel redirects to the integration's OAuth authorize URL
3. User approves access
4. Integration receives an access token
5. Integration can now manage Vercel projects via API

**What we'd need from Content Sync:**
- An OAuth app registration (like Vercel's integration marketplace)
- API endpoints to programmatically:
  - Create projects
  - Register sites to a project
  - Generate site credentials (UUID + secret)
  - Configure pools and flows

**Research needed:**
- Does content-sync.io expose a REST API for project management?
- Can we register as an "integration partner" to get OAuth app credentials?
- Is there an API to programmatically register a Drupal site to a Sync Core?

**If Content Sync has an API:**
```
User clicks "Enable Content Sync" in Decoupled.io dashboard
  → Dashboard creates Content Sync project via API
  → Dashboard registers the Drupal space to the project
  → Dashboard configures the Drupal module via our existing MCP/API
  → Done — content sync is working
```

**If Content Sync does NOT have an API:**
```
User clicks "Enable Content Sync" in Decoupled.io dashboard
  → Dashboard enables the module on the Drupal space
  → Dashboard creates a default flow via drush
  → User is redirected to content-sync.io to create a project manually
  → User copies Sync Core URL back to Drupal settings
  → Semi-automated — still requires manual steps
```

## Implementation Steps

### Immediate (this branch)

1. Add `drupal/cms_content_sync` to `composer.json`
2. Create a drush command or update hook for auto-configuration
3. Test with the existing Content Sync project
4. Document the manual setup process

### Near-term

5. Add MCP tool `configure_content_sync`
6. Add Content Sync section to Decoupled.io docs
7. Test end-to-end with two Decoupled.io spaces

### Future (pending Content Sync API availability)

8. OAuth integration for one-click setup
9. Dashboard UI for Content Sync management
10. Automatic pool/flow creation on space creation

## Questions for Content Sync Team

1. Is there a REST API for managing projects, sites, and pools?
2. Can we register as an integration partner for OAuth-based setup?
3. Is there a way to programmatically register a Drupal site to a Sync Core without the admin UI?
4. What's the pricing model for Decoupled.io customers (per-site? per-project?)
5. Does `configureEntityTypeSettings(['node'])` work as a replacement for calling `enableBundle()` per type?

## Existing Project

- **URL:** https://app.content-sync.io/projects/6830786c5436d550036e9faa/settings/overview
- **Status:** Previously tested with DrupalX — can be deleted/recreated for this integration
