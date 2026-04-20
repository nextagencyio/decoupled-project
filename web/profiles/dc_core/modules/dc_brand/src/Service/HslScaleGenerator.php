<?php

namespace Drupal\dc_brand\Service;

/**
 * Derives a 10-stop HSL ramp from a single hex color.
 *
 * If the input hex matches any stop of a known Tailwind palette
 * (violet / teal / indigo / gray / etc.), we return that palette's
 * full canonical ramp so the site's perceptual match is pixel-accurate.
 * Otherwise we fall back to a lightness-only synth: 500 is the user's
 * chosen H/S/L, other stops step lightness symmetrically.
 */
class HslScaleGenerator {

  /**
   * Target lightness (%) for each stop. 500 slot is replaced with the actual
   * input lightness at runtime so the user's chosen color always lands on 500.
   */
  private const LIGHTNESS_MAP = [
    '50'  => 97,
    '100' => 93,
    '200' => 85,
    '300' => 74,
    '400' => 62,
    '500' => 50, // Overridden with actual input L.
    '600' => 42,
    '700' => 33,
    '800' => 24,
    '900' => 15,
  ];

  /**
   * Canonical Tailwind v3 scales, keyed by normalized hex (lowercase, no #).
   * Any matching hex (on any stop) returns the full scale of that palette.
   */
  private const KNOWN_SCALES = [
    // violet
    'violet' => [
      '50'  => [250, 100, 98.0],
      '100' => [251, 91,  95.5],
      '200' => [251, 95,  91.8],
      '300' => [252, 94,  85.0],
      '400' => [255, 92,  76.3],
      '500' => [258, 90,  66.3],
      '600' => [262, 83,  57.8],
      '700' => [263, 70,  50.4],
      '800' => [263, 69,  42.2],
      '900' => [264, 68,  34.9],
    ],
    'teal' => [
      '50'  => [166, 76, 96.7],
      '100' => [167, 85, 89.2],
      '200' => [168, 84, 78.2],
      '300' => [171, 77, 64.1],
      '400' => [172, 66, 50.4],
      '500' => [173, 80, 40.0],
      '600' => [175, 84, 32.2],
      '700' => [175, 77, 26.1],
      '800' => [176, 69, 21.8],
      '900' => [176, 61, 18.8],
    ],
    'indigo' => [
      '50'  => [226, 100, 96.7],
      '100' => [226, 100, 93.9],
      '200' => [228, 96,  88.8],
      '300' => [230, 94,  82.2],
      '400' => [234, 89,  73.9],
      '500' => [239, 84,  66.7],
      '600' => [243, 75,  58.6],
      '700' => [245, 58,  50.8],
      '800' => [244, 55,  41.4],
      '900' => [242, 47,  34.3],
    ],
    'gray' => [
      '50'  => [210, 40, 98.0],
      '100' => [220, 14, 95.9],
      '200' => [220, 13, 90.9],
      '300' => [216, 12, 83.9],
      '400' => [218, 11, 64.9],
      '500' => [220, 9,  46.1],
      '600' => [215, 14, 34.1],
      '700' => [217, 19, 26.7],
      '800' => [215, 28, 17.1],
      '900' => [221, 39, 11.0],
    ],
  ];

  /**
   * Reverse index: hex (no leading #) -> palette name. Rebuilt lazily.
   */
  private static ?array $hexToPalette = NULL;

  /**
   * Generate a 9-stop ramp from a hex color.
   *
   * @param string $hex
   *   A hex color like "#3b82f6" or "3b82f6".
   *
   * @return array<string, array{h: float, s: float, l: float, css: string}>
   *   Keyed by stop ("50" … "900"). Each entry has h/s/l numeric values and
   *   a `css` string ready for a CSS custom property (`"221 83% 53%"`).
   */
  public function generate(string $hex): array {
    $palette = $this->matchKnownPalette($hex);
    if ($palette !== NULL) {
      return $this->rampFromHslMap(self::KNOWN_SCALES[$palette]);
    }
    [$h, $s, $l] = $this->hexToHsl($hex);
    $ramp = [];
    foreach (self::LIGHTNESS_MAP as $stop => $target_l) {
      $stop_key = (string) $stop;
      $stop_l   = ($stop_key === '500') ? $l : $target_l;
      $h_out = round($h);
      $s_out = round($s, 1);
      $l_out = round($stop_l, 1);
      $ramp[$stop_key] = [
        'h' => $h_out,
        's' => $s_out,
        'l' => $l_out,
        'css' => sprintf('%d %s%% %s%%', $h_out, $s_out, $l_out),
      ];
    }
    return $ramp;
  }

  /**
   * Return the palette name (violet/teal/…) whose scale contains $hex on
   * any stop, or NULL if unmatched.
   */
  private function matchKnownPalette(string $hex): ?string {
    if (self::$hexToPalette === NULL) {
      self::$hexToPalette = [];
      foreach (self::KNOWN_SCALES as $name => $scale) {
        foreach ($scale as $hsl) {
          self::$hexToPalette[strtolower($this->hslToHex(...$hsl))] = $name;
        }
      }
    }
    $normalized = strtolower(ltrim($hex, '#'));
    if (strlen($normalized) === 3) {
      $normalized = $normalized[0] . $normalized[0] . $normalized[1] . $normalized[1] . $normalized[2] . $normalized[2];
    }
    return self::$hexToPalette[$normalized] ?? NULL;
  }

  private function rampFromHslMap(array $scale): array {
    $ramp = [];
    foreach ($scale as $stop => $hsl) {
      [$h, $s, $l] = $hsl;
      $ramp[(string) $stop] = [
        'h'   => $h,
        's'   => $s,
        'l'   => $l,
        'css' => sprintf('%d %s%% %s%%', $h, $s, $l),
      ];
    }
    return $ramp;
  }

  private function hslToHex(float $h, float $s, float $l): string {
    $s /= 100;
    $l /= 100;
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    if     ($h < 60)  { [$r, $g, $b] = [$c, $x, 0]; }
    elseif ($h < 120) { [$r, $g, $b] = [$x, $c, 0]; }
    elseif ($h < 180) { [$r, $g, $b] = [0, $c, $x]; }
    elseif ($h < 240) { [$r, $g, $b] = [0, $x, $c]; }
    elseif ($h < 300) { [$r, $g, $b] = [$x, 0, $c]; }
    else              { [$r, $g, $b] = [$c, 0, $x]; }
    return sprintf('%02x%02x%02x',
      (int) round(($r + $m) * 255),
      (int) round(($g + $m) * 255),
      (int) round(($b + $m) * 255)
    );
  }

  /**
   * Convert a hex string to HSL triple (h 0–360, s 0–100, l 0–100).
   *
   * @return array{0: float, 1: float, 2: float}
   */
  private function hexToHsl(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $d = $max - $min;

    // Grayscale (including pure white / pure black) — no hue, no saturation.
    // Use value equality rather than `$d === 0.0` because PHP's integer
    // division returns an int 0 that fails strict comparison with float 0.0.
    if ($max === $min) {
      $h = 0;
      $s = 0;
    }
    else {
      $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
      switch ($max) {
        case $r:
          $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
          break;

        case $g:
          $h = (($b - $r) / $d) + 2;
          break;

        default:
          $h = (($r - $g) / $d) + 4;
          break;
      }
      $h *= 60;
    }

    return [$h, $s * 100, $l * 100];
  }

}
