<?php

namespace Drupal\dc_brand\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

/**
 * Resolves dc_brand.settings into the shape the Astro starter expects.
 *
 * Pre-computes HSL ramps, absolute logo URLs, and Google Fonts stylesheet URLs
 * so the frontend doesn't need to know anything about Drupal's storage model.
 */
class BrandResolver {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly HslScaleGenerator $hslScaleGenerator,
    private readonly GoogleFontsRegistry $googleFontsRegistry,
  ) {}

  /**
   * Build the full resolved payload.
   *
   * @return array<string, mixed>
   */
  public function resolve(): array {
    $config = $this->configFactory->get('dc_brand.settings');

    $fonts = [
      'heading' => $this->resolveFont((string) $config->get('fonts.heading')),
      'body'    => $this->resolveFont((string) $config->get('fonts.body')),
    ];

    $colors = [];
    foreach (['primary', 'secondary', 'accent', 'neutral', 'background'] as $key) {
      $hex = (string) $config->get("colors.{$key}");
      $colors[$key] = [
        'hex'   => $hex,
        'scale' => $this->hslScaleGenerator->generate($hex),
      ];
    }

    $logos = [
      'light' => $this->resolveLogo($config->get('logos.light')),
      'dark'  => $this->resolveLogo($config->get('logos.dark')),
    ];

    return [
      'fonts'  => $fonts,
      'colors' => $colors,
      'logos'  => $logos,
    ];
  }

  /**
   * Resolve a font family name to {family, href, weights}.
   *
   * @return array{family: string, href: ?string, weights: array<int>, category: string}
   */
  private function resolveFont(string $family): array {
    $registry = $this->googleFontsRegistry->all();
    if (!isset($registry[$family])) {
      // Unknown family — still surface the name so the frontend can apply it
      // as a fallback family, but no href to load.
      return [
        'family'   => $family,
        'href'     => NULL,
        'weights'  => [],
        'category' => 'sans',
      ];
    }
    return [
      'family'   => $family,
      'href'     => $this->googleFontsRegistry->stylesheetUrl($family),
      'weights'  => $registry[$family]['weights'],
      'category' => $registry[$family]['category'],
    ];
  }

  /**
   * Resolve a logo file ID to a public URL + alt.
   *
   * @return array{url: ?string, alt: string}|null
   */
  private function resolveLogo(mixed $fid): ?array {
    if (empty($fid)) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load((int) $fid);
    if (!$file) {
      return NULL;
    }
    return [
      'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
      'alt' => $file->getFilename(),
    ];
  }

}
