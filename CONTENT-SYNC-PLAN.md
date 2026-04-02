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

**Research findings (April 2026):**

Content Sync does **NOT** have:
- A public REST API for project/site management
- An OAuth integration marketplace (like Vercel)
- Any documented way to programmatically register a Drupal site

Site registration is **UI-only** — done through the Drupal admin at `/admin/config/services/cms-content-sync`. Each site gets a 64-character auto-generated password shared with the Sync Core, and subsequent communication uses short-lived JWTs.

Sites must be internet-accessible for the Sync Core to reach them (or use the "private environment" submodule with `drush cspep` polling for localhost).

**Security:** EU IPs `63.34.184.33`, `63.35.18.137`; US IPs `3.222.171.239`, `34.205.151.183` (for IP whitelisting).

**What IS automatable:**
- Flow/pool creation (config entities — exportable via `drush config:export`)
- Push/pull operations (`drush cs-push <flow>`, `drush cms_content_sync:pull <flow>`)
- Config deployment across environments via CI/CD
- settings.php overrides for site name, base URL, flow status

**Realistic integration path:**
```
1. Module pre-installed in Drupal image (composer require)
2. MCP tool / drush command auto-configures flows for all node types
3. User registers site at content-sync.io (manual — no automation API)
4. User can push/pull content via drush or MCP
```

**To unlock full automation, we'd need an Edge Box partnership:**
- Programmatic site registration API
- Platform pricing for Decoupled.io customers
- Possibly self-hosting Sync Core on our Contabo server (Enterprise license, Docker-based)

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

## Questions for Edge Box (Content Sync Team)

1. ~~Is there a REST API for managing projects, sites, and pools?~~ → **No (confirmed by research)**
2. Can we get a programmatic site registration API as a platform partner?
3. What's the pricing model for a platform like ours? (per-site? volume discount?)
4. Is the on-premise Sync Core (Docker) a viable option for us to self-host?
5. Does `configureEntityTypeSettings(['node'])` work in the 3.0.x branch? (Not found in public 2.x API docs — may be 3.x only)
6. Can `SimpleFlowSetupHelper` / `FlowControllerSimple` be used via drush to auto-create flows?

## Research Notes

- **SimpleFlowSetupHelper**: Not found in public 2.x API docs. May exist in 3.0.x branch or Content Cloud product. The CEO mentioned it specifically, so it may be an internal/partner API.
- **Pricing**: Enterprise custom pricing only — no public price list, no free tier. Factors: number of sites, volume of updates, SLA requirements.
- **On-Premise Sync Core**: Available with Enterprise license. Docker-based with MongoDB, RabbitMQ, Elasticsearch, S3. Could potentially run on our Contabo server.
- **Module version**: All connected sites must run the exact same `cms_content_sync` version.

## Existing Project

- **URL:** https://app.content-sync.io/projects/6830786c5436d550036e9faa/settings/overview
- **Status:** Previously tested with DrupalX — can be deleted/recreated for this integration
