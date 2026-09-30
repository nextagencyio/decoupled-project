# Decoupled Canvas (dc_canvas)

Decoupled.io integration for Drupal Canvas headless. Replaces `dc_puck` as the
visual page builder on new tenants.

- Registers the tenant frontend as Canvas's primary headless frontend (on
  install from `dc_config.frontend`, and from dc_config's connect flow).
- Keeps frontends working on simple_oauth 6: confidential consumers without
  grant types get `client_credentials` + the `dc_frontend` scope, and token
  requests without a `scope` get the consumer's default scopes.
- Revalidates published Canvas pages through `dc_revalidate`.

## Commands

```bash
# Point Canvas at a frontend (the editor's preview iframe + component source).
drush dc-canvas:frontend http://localhost:4321

# Sync the frontend's component library without opening the editor.
# --fetch-url is only needed when the server reaches the frontend at another
# address than editors do (a dev server on the DDEV host, for example).
drush dc-canvas:sync --fetch-url=http://host.docker.internal:4321

# Import a starter content file's landing pages as Canvas pages. Each section
# paragraph becomes a component; nested paragraph lists become slot children;
# remote images are downloaded into the media library. "/" is aliased /home.
# Idempotent: pages whose alias exists are skipped.
drush dc-canvas:import https://raw.githubusercontent.com/nextagencyio/decoupled-components-astro/main/data/components-content.json
```

## Dashboard endpoints

Authenticated with the space auth token in `X-Decoupled-Token`, like dc_import.

- `GET /api/dc-canvas/status` — whether Canvas is available, plus registered
  frontends and component/page counts. Older tenants (dc_puck) return 404.
- `POST /api/dc-canvas/setup` with `{"frontend_url": "…", "content": {…}}` —
  registers the frontend, syncs its component library and, if `content` (a
  starter content file) is given, imports its landing pages as Canvas pages.
  Returns 502 while the frontend is not reachable yet; safe to retry.

## Local setup from scratch

```bash
ddev drush si dc_core --account-name=admin --account-pass=admin -y
# Put the logged "Next.js Frontend" client id/secret in the frontend's
# .env.local, then start it: npm run dev (decoupled-components-astro).
ddev drush dc-canvas:frontend http://localhost:4321
ddev drush dc-canvas:sync --fetch-url=http://host.docker.internal:4321
ddev drush dc-canvas:import <content file path or URL>
ddev drush uli /canvas
```
