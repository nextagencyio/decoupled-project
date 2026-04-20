<?php

namespace Drupal\dc_brand\Service;

/**
 * Curated color palettes marketers can apply in one click.
 *
 * Each preset carries all five slots (primary / secondary / accent /
 * neutral / background) so the ramp preview repaints immediately on
 * click. Picked to cover the common product looks — neutral/minimal,
 * vibrant, enterprise-blue, dark SaaS, warm/earthy — without being
 * prescriptive. Users can still hand-tune after applying.
 */
class ColorPresetsRegistry {

  /**
   * @return array<string, array{label: string, colors: array<string, string>}>
   */
  public function all(): array {
    return [
      'ocean' => [
        'label'  => 'Ocean',
        'colors' => [
          'primary'    => '#0284c7',
          'secondary'  => '#14b8a6',
          'accent'     => '#f97316',
          'neutral'    => '#475569',
          'background' => '#0f172a',
        ],
      ],
      'midnight' => [
        'label'  => 'Midnight',
        'colors' => [
          'primary'    => '#6366f1',
          'secondary'  => '#a855f7',
          'accent'     => '#ec4899',
          'neutral'    => '#475569',
          'background' => '#020617',
        ],
      ],
      'sunset' => [
        'label'  => 'Sunset',
        'colors' => [
          'primary'    => '#f97316',
          'secondary'  => '#ec4899',
          'accent'     => '#eab308',
          'neutral'    => '#44403c',
          'background' => '#1c1917',
        ],
      ],
      'forest' => [
        'label'  => 'Forest',
        'colors' => [
          'primary'    => '#16a34a',
          'secondary'  => '#65a30d',
          'accent'     => '#d97706',
          'neutral'    => '#525252',
          'background' => '#0a0a0a',
        ],
      ],
      'candy' => [
        'label'  => 'Candy',
        'colors' => [
          'primary'    => '#ec4899',
          'secondary'  => '#8b5cf6',
          'accent'     => '#f59e0b',
          'neutral'    => '#525252',
          'background' => '#ffffff',
        ],
      ],
      'minimal' => [
        'label'  => 'Minimal',
        'colors' => [
          'primary'    => '#18181b',
          'secondary'  => '#3f3f46',
          'accent'     => '#71717a',
          'neutral'    => '#a1a1aa',
          'background' => '#fafafa',
        ],
      ],
      'enterprise' => [
        'label'  => 'Enterprise',
        'colors' => [
          'primary'    => '#1d4ed8',
          'secondary'  => '#0891b2',
          'accent'     => '#22c55e',
          'neutral'    => '#475569',
          'background' => '#f8fafc',
        ],
      ],
      'terracotta' => [
        'label'  => 'Terracotta',
        'colors' => [
          'primary'    => '#c2410c',
          'secondary'  => '#84cc16',
          'accent'     => '#eab308',
          'neutral'    => '#57534e',
          'background' => '#1c1917',
        ],
      ],
    ];
  }

}
