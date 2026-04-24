# AuditCrawl for Drupal

Connect your AuditCrawl content-strategy report to Drupal and turn
each recommended article into a draft node. Optional premium
subscription auto-fills drafts with AI-written content on a schedule.

This is the Drupal counterpart to the AuditCrawl WordPress plugin and
uses the same `auditcrawl.com/api/wp/*` endpoints under the hood.

## Installation

1. Copy this module into `web/modules/custom/auditcrawl/`.
2. `drush en auditcrawl`
3. Visit `/admin/content/auditcrawl/connect` and paste the report code from
   your AuditCrawl report-ready email (e.g. `AC-XYZAB7K`).
4. The Content Strategy appears at `/admin/content/auditcrawl` — click
   **Create draft** on any row to create an unpublished Article node.
5. (Optional) Add a license key at `/admin/content/auditcrawl/schedule` to
   enable premium auto-write.

## Dependencies

- Drupal 10.3+ or 11
- PHP 8.2+
- The `article` content type (ships with every core install / the
  `dc_core` install profile used in this project).

## External services

This module calls out to `auditcrawl.com` to fetch the report and
(with a premium license) request AI-written article drafts. Nothing
leaves the site until the admin explicitly opts in by pasting a code
or license key.

- **Terms of Service**: <https://auditcrawl.com/terms>
- **Privacy Policy**: <https://auditcrawl.com/privacy>

## License

GPL-2.0-or-later — matches Drupal core's own license.
