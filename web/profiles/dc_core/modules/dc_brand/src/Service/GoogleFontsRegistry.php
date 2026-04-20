<?php

namespace Drupal\dc_brand\Service;

/**
 * Curated list of Google Fonts offered in the brand settings dropdown.
 *
 * Intentionally opinionated: ~40 families across sans, serif, mono, and
 * display categories that hold up under modern web use. Each entry carries
 * the weight list we request in the Google Fonts URL so display=swap loads
 * just what the site needs.
 */
class GoogleFontsRegistry {

  /**
   * @return array<string, array{family: string, category: string, weights: array<int>}>
   *   Keyed by family name.
   */
  public function all(): array {
    return [
      // Sans.
      'Inter'              => ['family' => 'Inter',              'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'DM Sans'            => ['family' => 'DM Sans',            'category' => 'sans',    'weights' => [400, 500, 700]],
      'Manrope'            => ['family' => 'Manrope',            'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'Plus Jakarta Sans'  => ['family' => 'Plus Jakarta Sans',  'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'Figtree'            => ['family' => 'Figtree',            'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'Geist'              => ['family' => 'Geist',              'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Space Grotesk'      => ['family' => 'Space Grotesk',      'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Outfit'             => ['family' => 'Outfit',             'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'Work Sans'          => ['family' => 'Work Sans',          'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Nunito'             => ['family' => 'Nunito',             'category' => 'sans',    'weights' => [400, 600, 700, 800]],
      'Poppins'            => ['family' => 'Poppins',            'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Sora'               => ['family' => 'Sora',               'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Lato'               => ['family' => 'Lato',               'category' => 'sans',    'weights' => [400, 700, 900]],
      'Open Sans'          => ['family' => 'Open Sans',          'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'Montserrat'         => ['family' => 'Montserrat',         'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],
      'Raleway'            => ['family' => 'Raleway',            'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'IBM Plex Sans'      => ['family' => 'IBM Plex Sans',      'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Rubik'              => ['family' => 'Rubik',              'category' => 'sans',    'weights' => [400, 500, 600, 700]],
      'Urbanist'           => ['family' => 'Urbanist',           'category' => 'sans',    'weights' => [400, 500, 600, 700, 800]],

      // Serif.
      'Source Serif 4'     => ['family' => 'Source Serif 4',     'category' => 'serif',   'weights' => [400, 500, 600, 700]],
      'Playfair Display'   => ['family' => 'Playfair Display',   'category' => 'serif',   'weights' => [400, 500, 600, 700, 800]],
      'Merriweather'       => ['family' => 'Merriweather',       'category' => 'serif',   'weights' => [400, 700, 900]],
      'Lora'               => ['family' => 'Lora',               'category' => 'serif',   'weights' => [400, 500, 600, 700]],
      'EB Garamond'        => ['family' => 'EB Garamond',        'category' => 'serif',   'weights' => [400, 500, 600, 700]],
      'Fraunces'           => ['family' => 'Fraunces',           'category' => 'serif',   'weights' => [400, 500, 600, 700, 800]],
      'Newsreader'         => ['family' => 'Newsreader',         'category' => 'serif',   'weights' => [400, 500, 600, 700]],
      'Crimson Pro'        => ['family' => 'Crimson Pro',        'category' => 'serif',   'weights' => [400, 500, 600, 700]],
      'Cormorant Garamond' => ['family' => 'Cormorant Garamond', 'category' => 'serif',   'weights' => [400, 500, 600, 700]],
      'DM Serif Display'   => ['family' => 'DM Serif Display',   'category' => 'serif',   'weights' => [400]],

      // Display.
      'Bricolage Grotesque'=> ['family' => 'Bricolage Grotesque','category' => 'display', 'weights' => [400, 500, 600, 700, 800]],
      'Instrument Serif'   => ['family' => 'Instrument Serif',   'category' => 'display', 'weights' => [400]],
      'Clash Display'      => ['family' => 'Clash Display',      'category' => 'display', 'weights' => [400, 500, 600, 700]],
      'Syne'               => ['family' => 'Syne',               'category' => 'display', 'weights' => [400, 500, 600, 700, 800]],
      'Archivo'            => ['family' => 'Archivo',            'category' => 'display', 'weights' => [400, 500, 600, 700, 800]],

      // Mono.
      'JetBrains Mono'     => ['family' => 'JetBrains Mono',     'category' => 'mono',    'weights' => [400, 500, 600, 700]],
      'IBM Plex Mono'      => ['family' => 'IBM Plex Mono',      'category' => 'mono',    'weights' => [400, 500, 600, 700]],
      'Fira Code'          => ['family' => 'Fira Code',          'category' => 'mono',    'weights' => [400, 500, 600, 700]],
      'Space Mono'         => ['family' => 'Space Mono',         'category' => 'mono',    'weights' => [400, 700]],
      'Geist Mono'         => ['family' => 'Geist Mono',         'category' => 'mono',    'weights' => [400, 500, 600, 700]],
    ];
  }

  /**
   * Build the Google Fonts CSS URL for a single family with its weights.
   *
   * @param string $family
   *   Family name exactly as registered in ::all().
   *
   * @return string|null
   *   URL for the stylesheet, or NULL if the family isn't registered.
   */
  public function stylesheetUrl(string $family): ?string {
    $registry = $this->all();
    if (!isset($registry[$family])) {
      return NULL;
    }
    $weights = implode(';', array_map(fn($w) => "0,{$w}", $registry[$family]['weights']));
    $slug = str_replace(' ', '+', $family);
    return "https://fonts.googleapis.com/css2?family={$slug}:ital,wght@{$weights}&display=swap";
  }

  /**
   * Build options array keyed by family for a Drupal select element.
   *
   * @return array<string, string>
   */
  public function formOptions(): array {
    $options = [];
    foreach ($this->all() as $name => $info) {
      $options[$name] = sprintf('%s (%s)', $name, ucfirst($info['category']));
    }
    return $options;
  }

}
