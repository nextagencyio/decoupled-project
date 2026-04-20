<?php

namespace Drupal\dc_brand\Service;

/**
 * Curated list of Google Fonts offered in the brand settings dropdown.
 *
 * ~85 families spanning sans, serif, display, mono, and handwriting. Picked
 * to cover the top of Google's popularity charts plus a few modern favorites
 * (Geist, Outfit, Fraunces, Bricolage Grotesque, etc). Kept alphabetical by
 * family name so the dropdown is easy to scan; formOptions() also ksorts
 * defensively in case entries get added out of order later.
 */
class GoogleFontsRegistry {

  /**
   * @return array<string, array{family: string, category: string, weights: array<int>}>
   *   Keyed by family name, alphabetical.
   */
  public function all(): array {
    return [
      'Abril Fatface'      => ['family' => 'Abril Fatface',      'category' => 'display',     'weights' => [400]],
      'Alegreya'           => ['family' => 'Alegreya',           'category' => 'serif',       'weights' => [400, 500, 600, 700, 800]],
      'Amatic SC'          => ['family' => 'Amatic SC',          'category' => 'display',     'weights' => [400, 700]],
      'Anton'              => ['family' => 'Anton',              'category' => 'display',     'weights' => [400]],
      'Archivo'            => ['family' => 'Archivo',            'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Asap'               => ['family' => 'Asap',               'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Barlow'             => ['family' => 'Barlow',             'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Bebas Neue'         => ['family' => 'Bebas Neue',         'category' => 'display',     'weights' => [400]],
      'Bitter'             => ['family' => 'Bitter',             'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Bricolage Grotesque'=> ['family' => 'Bricolage Grotesque','category' => 'display',     'weights' => [400, 500, 600, 700, 800]],
      'Cabin'              => ['family' => 'Cabin',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Caveat'             => ['family' => 'Caveat',             'category' => 'handwriting', 'weights' => [400, 500, 600, 700]],
      'Clash Display'      => ['family' => 'Clash Display',      'category' => 'display',     'weights' => [400, 500, 600, 700]],
      'Cormorant Garamond' => ['family' => 'Cormorant Garamond', 'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Crimson Pro'        => ['family' => 'Crimson Pro',        'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Crimson Text'       => ['family' => 'Crimson Text',       'category' => 'serif',       'weights' => [400, 600, 700]],
      'DM Sans'            => ['family' => 'DM Sans',            'category' => 'sans',        'weights' => [400, 500, 700]],
      'DM Serif Display'   => ['family' => 'DM Serif Display',   'category' => 'serif',       'weights' => [400]],
      'Dancing Script'     => ['family' => 'Dancing Script',     'category' => 'handwriting', 'weights' => [400, 500, 600, 700]],
      'Dosis'              => ['family' => 'Dosis',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'EB Garamond'        => ['family' => 'EB Garamond',        'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Exo 2'              => ['family' => 'Exo 2',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Figtree'            => ['family' => 'Figtree',            'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Fira Code'          => ['family' => 'Fira Code',          'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'Fira Sans'          => ['family' => 'Fira Sans',          'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Fjalla One'         => ['family' => 'Fjalla One',         'category' => 'display',     'weights' => [400]],
      'Fraunces'           => ['family' => 'Fraunces',           'category' => 'serif',       'weights' => [400, 500, 600, 700, 800]],
      'Geist'              => ['family' => 'Geist',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Geist Mono'         => ['family' => 'Geist Mono',         'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'Heebo'              => ['family' => 'Heebo',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Hind'               => ['family' => 'Hind',               'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'IBM Plex Mono'      => ['family' => 'IBM Plex Mono',      'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'IBM Plex Sans'      => ['family' => 'IBM Plex Sans',      'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'IBM Plex Serif'     => ['family' => 'IBM Plex Serif',     'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Inconsolata'        => ['family' => 'Inconsolata',        'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'Instrument Serif'   => ['family' => 'Instrument Serif',   'category' => 'display',     'weights' => [400]],
      'Inter'              => ['family' => 'Inter',              'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'JetBrains Mono'     => ['family' => 'JetBrains Mono',     'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'Josefin Sans'       => ['family' => 'Josefin Sans',       'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Karla'              => ['family' => 'Karla',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Kumbh Sans'         => ['family' => 'Kumbh Sans',         'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Lato'               => ['family' => 'Lato',               'category' => 'sans',        'weights' => [400, 700, 900]],
      'Libre Baskerville'  => ['family' => 'Libre Baskerville',  'category' => 'serif',       'weights' => [400, 700]],
      'Libre Caslon Text'  => ['family' => 'Libre Caslon Text',  'category' => 'serif',       'weights' => [400, 700]],
      'Libre Franklin'     => ['family' => 'Libre Franklin',     'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Lora'               => ['family' => 'Lora',               'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Manrope'            => ['family' => 'Manrope',            'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Merriweather'       => ['family' => 'Merriweather',       'category' => 'serif',       'weights' => [400, 700, 900]],
      'Montserrat'         => ['family' => 'Montserrat',         'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Mulish'             => ['family' => 'Mulish',             'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Newsreader'         => ['family' => 'Newsreader',         'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Noto Sans'          => ['family' => 'Noto Sans',          'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Noto Serif'         => ['family' => 'Noto Serif',         'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Nunito'             => ['family' => 'Nunito',             'category' => 'sans',        'weights' => [400, 600, 700, 800]],
      'Nunito Sans'        => ['family' => 'Nunito Sans',        'category' => 'sans',        'weights' => [400, 600, 700]],
      'Open Sans'          => ['family' => 'Open Sans',          'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Oswald'             => ['family' => 'Oswald',             'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Outfit'             => ['family' => 'Outfit',             'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Oxygen'             => ['family' => 'Oxygen',             'category' => 'sans',        'weights' => [400, 700]],
      'PT Sans'            => ['family' => 'PT Sans',            'category' => 'sans',        'weights' => [400, 700]],
      'PT Serif'           => ['family' => 'PT Serif',           'category' => 'serif',       'weights' => [400, 700]],
      'Pacifico'           => ['family' => 'Pacifico',           'category' => 'handwriting', 'weights' => [400]],
      'Playfair Display'   => ['family' => 'Playfair Display',   'category' => 'serif',       'weights' => [400, 500, 600, 700, 800]],
      'Plus Jakarta Sans'  => ['family' => 'Plus Jakarta Sans',  'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Poppins'            => ['family' => 'Poppins',            'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Quicksand'          => ['family' => 'Quicksand',          'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Raleway'            => ['family' => 'Raleway',            'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Roboto'             => ['family' => 'Roboto',             'category' => 'sans',        'weights' => [400, 500, 700, 900]],
      'Roboto Condensed'   => ['family' => 'Roboto Condensed',   'category' => 'sans',        'weights' => [400, 500, 700]],
      'Roboto Mono'        => ['family' => 'Roboto Mono',        'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'Roboto Slab'        => ['family' => 'Roboto Slab',        'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Rubik'              => ['family' => 'Rubik',              'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Shadows Into Light' => ['family' => 'Shadows Into Light', 'category' => 'handwriting', 'weights' => [400]],
      'Signika'            => ['family' => 'Signika',            'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Sora'               => ['family' => 'Sora',               'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Source Code Pro'    => ['family' => 'Source Code Pro',    'category' => 'mono',        'weights' => [400, 500, 600, 700]],
      'Source Sans 3'      => ['family' => 'Source Sans 3',      'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Source Serif 4'     => ['family' => 'Source Serif 4',     'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Space Grotesk'      => ['family' => 'Space Grotesk',      'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Space Mono'         => ['family' => 'Space Mono',         'category' => 'mono',        'weights' => [400, 700]],
      'Syne'               => ['family' => 'Syne',               'category' => 'display',     'weights' => [400, 500, 600, 700, 800]],
      'Teko'               => ['family' => 'Teko',               'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Ubuntu'             => ['family' => 'Ubuntu',             'category' => 'sans',        'weights' => [400, 500, 700]],
      'Ubuntu Mono'        => ['family' => 'Ubuntu Mono',        'category' => 'mono',        'weights' => [400, 700]],
      'Urbanist'           => ['family' => 'Urbanist',           'category' => 'sans',        'weights' => [400, 500, 600, 700, 800]],
      'Vollkorn'           => ['family' => 'Vollkorn',           'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Work Sans'          => ['family' => 'Work Sans',          'category' => 'sans',        'weights' => [400, 500, 600, 700]],
      'Yrsa'               => ['family' => 'Yrsa',               'category' => 'serif',       'weights' => [400, 500, 600, 700]],
      'Zilla Slab'         => ['family' => 'Zilla Slab',         'category' => 'serif',       'weights' => [400, 500, 600, 700]],
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
   * Build options array keyed by family, sorted alphabetically, for a Drupal
   * select element. The label carries the category in parens so the user
   * can still tell a serif from a sans at a glance.
   *
   * @return array<string, string>
   */
  public function formOptions(): array {
    $options = [];
    foreach ($this->all() as $name => $info) {
      $options[$name] = sprintf('%s (%s)', $name, ucfirst($info['category']));
    }
    ksort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

}
