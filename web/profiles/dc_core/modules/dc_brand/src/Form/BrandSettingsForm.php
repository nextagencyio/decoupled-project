<?php

namespace Drupal\dc_brand\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dc_brand\Service\BuildHookDispatcher;
use Drupal\dc_brand\Service\GoogleFontsRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Brand settings admin form — fonts, colors, logos, build hook.
 *
 * Rendered inside vertical tabs for a one-screen-fits-all feel. Colors use
 * native <input type="color"> pickers with a live 9-stop HSL ramp rendered
 * below each (matches what the frontend will receive). Fonts show a live
 * Google-Fonts-loaded sample line under each dropdown.
 */
class BrandSettingsForm extends ConfigFormBase {

  public function __construct(
    private readonly GoogleFontsRegistry $googleFontsRegistry,
    private readonly BuildHookDispatcher $dispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('dc_brand.google_fonts_registry'),
      $container->get('dc_brand.build_hook_dispatcher'),
    );
    $instance->setConfigFactory($container->get('config.factory'));
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dc_brand.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dc_brand_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dc_brand.settings');
    $font_options = $this->googleFontsRegistry->formOptions();

    $form['#attached']['library'][] = 'dc_brand/brand-form';
    $form['#id'] = 'dc-brand-settings-form';

    $form['intro'] = [
      '#type'   => 'markup',
      '#markup' => '<p class="messages messages--info">' . $this->t('Pick fonts, colors, and logos once. Every connected frontend (Astro, Next.js, etc.) picks up the values at build time from <code>/api/dc-brand/settings</code>.') . '</p>',
    ];

    $form['tabs'] = [
      '#type'     => 'vertical_tabs',
      '#default_tab' => 'edit-typography',
    ];

    // -- Typography ---------------------------------------------------
    $form['typography'] = [
      '#type'  => 'details',
      '#title' => $this->t('Typography'),
      '#group' => 'tabs',
      '#tree'  => TRUE,
    ];
    $form['typography']['heading'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Heading font'),
      '#options'       => $font_options,
      '#default_value' => $config->get('fonts.heading'),
      '#description'   => $this->t('Applied to h1–h6 and any <code>font-display</code> element.'),
      '#attributes'    => ['data-brand-font' => 'heading'],
      '#suffix'        => '<div class="brand-font-preview is-heading" data-brand-font-preview="heading"></div>',
    ];
    $form['typography']['body'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Body font'),
      '#options'       => $font_options,
      '#default_value' => $config->get('fonts.body'),
      '#description'   => $this->t('Applied to body copy everywhere.'),
      '#attributes'    => ['data-brand-font' => 'body'],
      '#suffix'        => '<div class="brand-font-preview" data-brand-font-preview="body"></div>',
    ];

    // -- Colors -------------------------------------------------------
    $form['colors'] = [
      '#type'  => 'details',
      '#title' => $this->t('Colors'),
      '#group' => 'tabs',
      '#tree'  => TRUE,
      '#description' => $this->t('Pick one color per slot. A 9-stop ramp (50–900) is derived automatically using HSL lightness stepping. The ramp preview updates live.'),
    ];
    foreach (['primary', 'secondary', 'accent', 'neutral', 'background'] as $key) {
      $default = $config->get("colors.{$key}") ?: '#3b82f6';
      $form['colors'][$key] = [
        '#type'          => 'color',
        '#title'         => $this->t('@label', ['@label' => ucfirst($key)]),
        '#default_value' => $default,
        '#required'      => TRUE,
        '#attributes'    => [
          'data-brand-color' => $key,
          'class'            => ['brand-color-input'],
        ],
        '#prefix' => '<div class="brand-color-field">',
        '#suffix' => '<div class="brand-color-meta">'
          . '<span class="brand-color-label">' . ucfirst($key) . '</span>'
          . '<span class="brand-color-hex" data-brand-hex="' . $key . '">' . $default . '</span>'
          . '</div>'
          . '<div class="brand-ramp" data-brand-ramp="' . $key . '"></div>'
          . '</div>',
        '#title_display' => 'invisible',
      ];
    }

    // -- Logos --------------------------------------------------------
    $form['logos'] = [
      '#type'  => 'details',
      '#title' => $this->t('Logos'),
      '#group' => 'tabs',
      '#tree'  => TRUE,
      '#description' => $this->t('PNG, SVG, JPG, or WebP. A light-mode logo is required; dark-mode is optional but recommended for sites that support dark mode.'),
    ];
    $form['logos']['light'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Light-mode logo'),
      '#default_value'     => $config->get('logos.light') ? [$config->get('logos.light')] : NULL,
      '#upload_location'   => 'public://brand/',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg svg webp']],
      '#prefix'            => '<div class="brand-logo-upload">',
      '#suffix'            => '</div>',
    ];
    $form['logos']['dark'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Dark-mode logo'),
      '#default_value'     => $config->get('logos.dark') ? [$config->get('logos.dark')] : NULL,
      '#upload_location'   => 'public://brand/',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg svg webp']],
      '#prefix'            => '<div class="brand-logo-upload">',
      '#suffix'            => '</div>',
    ];

    // -- Build hook ---------------------------------------------------
    $form['build_hook'] = [
      '#type'  => 'details',
      '#title' => $this->t('Build hook'),
      '#group' => 'tabs',
      '#tree'  => TRUE,
      '#description' => $this->t('Optional. If set, a POST fires on save (after the debounce window) to rebuild your static frontend.'),
    ];
    $form['build_hook']['url'] = [
      '#type'          => 'url',
      '#title'         => $this->t('Build hook URL'),
      '#default_value' => $config->get('build_hook.url'),
      '#placeholder'   => 'https://api.netlify.com/build_hooks/...',
      '#description'   => $this->t('Netlify or Vercel build hook URL. Leave blank to skip auto-deploy.'),
    ];
    $form['build_hook']['debounce_seconds'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Debounce window (seconds)'),
      '#default_value' => $config->get('build_hook.debounce_seconds') ?: 60,
      '#min'           => 0,
      '#description'   => $this->t('Minimum seconds between builds. Rapid saves inside this window collapse into a single deploy.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $colors = $form_state->getValue('colors') ?? [];
    foreach ($colors as $key => $hex) {
      $hex = trim((string) $hex);
      if (!preg_match('/^#?[0-9a-fA-F]{6}$/', $hex)) {
        $form_state->setErrorByName("colors][{$key}", $this->t('@label must be a 6-digit hex color (e.g. #3b82f6).', ['@label' => ucfirst($key)]));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('dc_brand.settings');

    $config->set('fonts.heading', $form_state->getValue(['typography', 'heading']));
    $config->set('fonts.body',    $form_state->getValue(['typography', 'body']));

    foreach (['primary', 'secondary', 'accent', 'neutral', 'background'] as $key) {
      $hex = trim((string) $form_state->getValue(['colors', $key]));
      if ($hex !== '' && $hex[0] !== '#') {
        $hex = '#' . $hex;
      }
      $config->set("colors.{$key}", strtolower($hex));
    }

    // Mark any uploaded files as permanent.
    foreach (['light', 'dark'] as $slot) {
      $fids = $form_state->getValue(['logos', $slot]);
      $fid = is_array($fids) ? reset($fids) : $fids;
      $config->set("logos.{$slot}", $fid ? (int) $fid : NULL);
      if ($fid) {
        $file = \Drupal::entityTypeManager()->getStorage('file')->load((int) $fid);
        if ($file && $file->isTemporary()) {
          $file->setPermanent();
          $file->save();
        }
      }
    }

    $config->set('build_hook.url',              (string) $form_state->getValue(['build_hook', 'url']));
    $config->set('build_hook.debounce_seconds', (int)    $form_state->getValue(['build_hook', 'debounce_seconds']));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
