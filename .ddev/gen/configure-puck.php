<?php

/**
 * Turn on the dc_puck visual editor for the landing content type.
 *
 * The landing node + its 7 paragraph bundles (hero, pathway_group +
 * pathway_card, mission_band, cta_band, section_ref, rich_text) are built
 * by build-landing-paragraphs.php and exposed to GraphQL by
 * expose-landing-graphql.php. dc_puck AUTO-DETECTS those bundles — there's
 * no per-component mapping to hand-write here. We only flip the switches:
 *
 *   - enable the module for `landing`
 *   - tell it the paragraph field is `field_content` (its default is
 *     `field_sections`, which our landing node does NOT use)
 *   - point it at the frontend editor route
 *
 * The frontend editor URL is the Astro dev server in local live-mode
 * (`http://localhost:4400/editor`); dc_puck appends `/{nid}?token=...`.
 * (The deployed Vercel pilot can't reach DDEV, so Puck editing is a
 * LOCAL demonstration — same as the rest of the live-mode edit loop.)
 *
 *   ddev drush php:script .ddev/gen/configure-puck.php
 *
 * Idempotent — safe to re-run; part of the branch's reset insurance.
 */

use Drupal\Core\Extension\ModuleInstallerInterface;

// 1. Ensure the module is installed (it ships disabled in dc_core).
/** @var ModuleInstallerInterface $installer */
$installer = \Drupal::service('module_installer');
if (!\Drupal::moduleHandler()->moduleExists('dc_puck')) {
  $installer->install(['dc_puck']);
  echo "  installed module: dc_puck\n";
}

$state = \Drupal::state();

// 2. Enable + scope to the landing bundle ONLY (structured content stays
//    in forms; the homepage is the one drag-and-drop page).
$state->set('dc_puck.enabled', TRUE);

$enabled_types = $state->get('dc_puck.enabled_content_types', []);
if (!in_array('landing', $enabled_types, TRUE)) {
  $enabled_types[] = 'landing';
}
$state->set('dc_puck.enabled_content_types', array_values($enabled_types));

// 3. The landing node's paragraph field (NOT the dc_puck default).
$state->set('dc_puck.sections_field', 'field_content');

// 4. Frontend editor base URL — the site ORIGIN, no /editor suffix. The
//    module appends "/editor/{nid}?token=..." itself (TokenController::
//    generate), so a trailing /editor here yields /editor/editor/{nid}.
//    Override per engagement if the dev port differs (e.g. Sharon CDC 4616).
$editor_url = getenv('PUCK_EDITOR_URL') ?: 'http://localhost:4400';
$state->set('dc_puck.editor_url', $editor_url);

// 5. Reset the auto-detected component map so it re-derives from the
//    CURRENT paragraph bundles (picks up any newly-added component).
$state->delete('dc_puck.component_map');

echo "dc_puck: enabled for [landing], sections_field=field_content, editor_url={$editor_url}\n";
echo "Component map cleared — will auto-derive from live paragraph bundles on next load.\n";
