# `dc_dashboard` — Drupal compliance dashboard for headless Astro projects

A Drupal 11 module bundled with the `dc_core` profile. It renders a
single `/dashboard` page that pulls live a11y / SEO / performance scan
results from the Astro frontend half of a decoupled Drupal + Astro
project.

It ships with `dc_core` but is **not installed by default** — it is
not listed in the profile's `dependencies:`, so a fresh install does
not enable it. Enable it per engagement with `drush en dc_dashboard`.

Originally built for the Town of Duck project (`decoupled-project`
+ `duck-website`); generalized so any engagement enables it and sets
its scanner URLs rather than forking code.

## The shape it expects

Your Astro frontend repo has GitHub Actions workflows that publish
audit JSON to `public/audits/<scanner>/latest.json`:

```
public/audits/
├── a11y/
│   ├── latest.json           ← always overwritten
│   └── runs/<ISO>.json       ← optional historical archive
├── seo/
│   └── latest.json
└── performance/
    └── latest.json
```

After Vercel/Netlify deploys, these files are served as static assets,
typically at `https://<frontend>.vercel.app/audits/<scanner>/latest.json`
— but the module assumes no path convention. You give it the three
full URLs explicitly (see Configuration), so the files can live on any
host, path, or filename.

The module fetches those URLs server-side, caches them briefly, and
renders the consolidated dashboard.

## JSON schema expected

Each scanner's `latest.json` looks like:

```json
{
  "runAt": "2026-05-12T17:12:18.930Z",
  "baseUrl": "https://example.vercel.app",
  "standard": "WCAG 2.2 AA",
  "summary": {
    "pagesAudited": 13,
    "pagesPassing": 13,
    "totalViolations": 0,
    "pass": true
  },
  "pages": [
    {
      "label": "Home",
      "url": "/",
      "ok": true,
      "violationCount": 0,
      "violations": []
    }
  ]
}
```

SEO and performance variants use the same envelope with scanner-
specific page fields (Lighthouse score, LCP, CLS, issues, etc.).
See `src/Controller/DashboardController.php` for the exact shape
each summarizer expects.

## Install

The module already ships in the `dc_core` profile at
`web/profiles/dc_core/modules/dc_dashboard`, so there's nothing to
copy. Just enable and configure it:

```bash
# Enable it (not on by default)
drush en dc_dashboard -y

# Configure the three scanner JSON URLs (full public URLs)
drush config:set dc_dashboard.settings audit_url_a11y \
  'https://<your-frontend>.vercel.app/audits/a11y/latest.json' -y
drush config:set dc_dashboard.settings audit_url_seo \
  'https://<your-frontend>.vercel.app/audits/seo/latest.json' -y
drush config:set dc_dashboard.settings audit_url_performance \
  'https://<your-frontend>.vercel.app/audits/performance/latest.json' -y

# Site metadata (cosmetic, shown on the page)
drush config:set dc_dashboard.settings site_name 'Town of X' -y
drush config:set dc_dashboard.settings site_url \
  'https://<your-frontend>.vercel.app' -y
drush config:set dc_dashboard.settings site_repo \
  'nextagencyio/<your-frontend>' -y

# Clear caches
drush cr
```

The dashboard renders at `/dashboard`. Wire it as the site front page
(System / Basic site settings) if you want it to be the admin landing.

## Configuration

| Config key | Description | Default |
|---|---|---|
| `dc_dashboard.settings:audit_url_a11y` | Full URL to the accessibility audit JSON | empty — required |
| `dc_dashboard.settings:audit_url_seo` | Full URL to the SEO audit JSON | empty — required |
| `dc_dashboard.settings:audit_url_performance` | Full URL to the performance audit JSON | empty — required |
| `dc_dashboard.settings:ttl_seconds` | Cache TTL for fetched audit JSON | 60 |
| `dc_dashboard.settings:site_name` | Display name shown on the dashboard | falls back to `system.site:name` |
| `dc_dashboard.settings:site_url` | Audited site URL (rendered on dashboard) | empty |
| `dc_dashboard.settings:site_repo` | GitHub repo slug for the frontend (e.g. `owner/repo`) | empty |

You can override any single scanner URL at runtime via Drupal state
(useful for pointing at a preview deployment):

```bash
drush state:set dc_dashboard.audit_url_a11y 'https://preview-pr-42.vercel.app/audits/a11y/latest.json'
```

State takes precedence over config, per scanner.

## Pair with the GitHub Actions workflows

This module is the **read** half. The **write** half lives in the
Astro frontend repo as three workflow files:

- `.github/workflows/a11y.yml` — axe-core WCAG 2.2 AA scan
- `.github/workflows/seo.yml` — Lighthouse SEO + structural
- `.github/workflows/performance.yml` — Lighthouse + Core Web Vitals

Each runs on:
- pull requests (audit the PR, comment results, no commit)
- pushes to main (audit and commit results to `public/audits/`)
- daily cron (audit live production URL, commit drift)
- workflow_dispatch (manual)

Reference implementation: `~/nodejs/duck-website/.github/workflows/`.

## Why generalized

The original Duck-specific copy hardcoded:
- `AuditFetcher::DEFAULT_BASE = 'https://duck-website.vercel.app/audits'`
- `DashboardController::page()` site metadata (`'Town of Duck'`, etc.)

This copy reads all of that from `dc_dashboard.settings` config, so an
engagement enables the module and sets its scanner URLs + metadata
rather than forking code.

## Files

```
dc_dashboard/
├── dc_dashboard.info.yml            module metadata
├── dc_dashboard.libraries.yml       CSS library
├── dc_dashboard.module              (intentionally minimal)
├── dc_dashboard.routing.yml         /dashboard route
├── dc_dashboard.services.yml        AuditFetcher service definition
├── config/
│   ├── install/dc_dashboard.settings.yml    default config
│   └── schema/dc_dashboard.schema.yml       config schema
├── css/dashboard.css                page styling
├── templates/dashboard.html.twig    page template
└── src/
    ├── Controller/DashboardController.php
    └── Service/AuditFetcher.php
```

## Companion: `proposal-drupal-astro` skill

This module is referenced by the `proposal-drupal-astro` Claude Code
skill in `~/nodejs/rfpbids/.claude/skills/proposal-drupal-astro/`. See
that skill for the full proposal methodology this module fits into.
