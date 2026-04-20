<?php

namespace Drupal\dc_brand\Service;

/**
 * Derives a 9-stop HSL ramp from a single hex color.
 *
 * We step lightness symmetrically around the input color so 500 is the user's
 * chosen hue/saturation/lightness, and the ramp runs 50 (lightest) to 900
 * (darkest). Saturation holds roughly constant; lightness is the only axis
 * that moves. Matches the "pick one primary, auto-derive rest" UX.
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
    [$h, $s, $l] = $this->hexToHsl($hex);
    $ramp = [];
    foreach (self::LIGHTNESS_MAP as $stop => $target_l) {
      // PHP coerces numeric string array keys to int, so iterate-bound
      // $stop is int 50/100/500/…. Cast to string for comparison and for
      // the output key so downstream JSON has stable string keys.
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
