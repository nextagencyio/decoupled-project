<?php

namespace Drupal\dc_brand\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\dc_brand\Service\BuildHookDispatcher;
use Drupal\dc_brand\Service\ColorPresetsRegistry;
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

  // Service props must be `protected`, not `private`, and not `readonly`.
  // Drupal's DependencySerializationTrait (used by FormBase) calls
  // `get_object_vars($this)` from FormBase's scope to detect services to
  // swap out on serialize; private props declared in a subclass aren't
  // visible there, so they'd be serialized as raw objects and leave the
  // deserialized form unable to see the container-backed services.
  public function __construct(
    protected GoogleFontsRegistry $googleFontsRegistry,
    protected ColorPresetsRegistry $colorPresets,
    protected BuildHookDispatcher $dispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self(
      $container->get('dc_brand.google_fonts_registry'),
      $container->get('dc_brand.color_presets_registry'),
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

    $form['rebuild_notice'] = [
      '#type'   => 'markup',
      '#markup' => '<p class="messages messages--warning">' . $this->t('<strong>Changes are not instant.</strong> Saves here schedule a static-site rebuild, debounced by ~60 seconds to coalesce rapid edits. Once the debounce window closes, the build itself typically takes another 30–90 seconds. Refresh the frontend (or the Live Preview iframe) once the build finishes to see the new styling.') . '</p>',
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
    $form['typography']['note'] = [
      '#type'   => 'markup',
      '#markup' => '<p class="brand-fonts-note">' . $this->t('Powered by <a href="https://fonts.google.com" target="_blank" rel="noopener">Google Fonts</a>. Families below are loaded with <code>display=swap</code> for fast first paint.') . '</p>',
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

    // Presets — click a palette to populate all 5 color slots at once.
    $preset_markup = '<div class="brand-presets"><p class="brand-presets-label">' . $this->t('Quick-start palettes') . '</p><div class="brand-presets-grid">';
    foreach ($this->colorPresets->all() as $id => $preset) {
      $payload = htmlspecialchars(json_encode($preset['colors']), ENT_QUOTES);
      $swatches = '';
      foreach ($preset['colors'] as $hex) {
        $swatches .= '<span style="background-color:' . htmlspecialchars($hex) . '"></span>';
      }
      $preset_markup .= '<button type="button" class="brand-preset" data-brand-preset="' . $payload . '">'
        . '<span class="brand-preset-swatches">' . $swatches . '</span>'
        . '<span class="brand-preset-label">' . htmlspecialchars($preset['label']) . '</span>'
        . '</button>';
    }
    $preset_markup .= '</div></div>';
    $form['colors']['presets'] = [
      '#type'   => 'markup',
      '#markup' => Markup::create($preset_markup),
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

    // -- Live preview -------------------------------------------------
    $form['preview'] = [
      '#type'  => 'details',
      '#title' => $this->t('Live preview'),
      '#group' => 'tabs',
      '#tree'  => TRUE,
      '#description' => $this->t('Embed the frontend\'s brand-preview page in an iframe so you can see the current state of the deployed site without leaving this screen.'),
    ];
    $iframe_preview_url = (string) $this->configFactory()
      ->get('decoupled_preview_iframe.settings')
      ->get('preview_url');
    $form['preview']['url'] = [
      '#type'          => 'url',
      '#title'         => $this->t('Preview URL'),
      '#default_value' => $config->get('preview.url') ?: $iframe_preview_url,
      '#placeholder'   => 'http://localhost:4351/brand-preview',
      '#description'   => $this->t('Typically <code>&lt;your-frontend&gt;/brand-preview</code>. Defaults to the frontend URL configured in <a href=":url">Decoupled Preview</a>. The frontend must allow iframe embedding (most Astro/Next sites do by default).', [
        ':url' => '/admin/config/decoupled_preview_iframe/settings',
      ]),
    ];
    $preview_url = (string) ($config->get('preview.url') ?: $iframe_preview_url);
    if ($preview_url !== '') {
      $iframe_markup = '<div class="brand-preview-iframe-wrap">'
        . '<div class="brand-preview-toolbar">'
        . '<span class="brand-preview-url">' . htmlspecialchars($preview_url) . '</span>'
        . '<button type="button" class="button brand-preview-refresh" data-brand-preview-refresh>' . $this->t('Refresh') . '</button>'
        . '<a href="' . htmlspecialchars($preview_url) . '" target="_blank" rel="noopener" class="button">' . $this->t('Open in new tab') . '</a>'
        . '</div>'
        . '<iframe src="' . htmlspecialchars($preview_url) . '" data-brand-preview loading="lazy"></iframe>'
        . '<p class="brand-preview-note">' . $this->t('Saves trigger a rebuild (after the build-hook debounce). Hit <em>Refresh</em> once the build is done to see the new render.') . '</p>'
        . '</div>';
      $form['preview']['iframe'] = [
        '#type'   => 'markup',
        '#markup' => Markup::create($iframe_markup),
      ];
    }

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

    // Mark any uploaded files as permanent, register file_usage so cron
    // doesn't orphan them, and decrement the old file's usage when a slot
    // is replaced so the replaced asset can be cleaned up on the next
    // temporary-file sweep.
    $file_storage = \Drupal::entityTypeManager()->getStorage('file');
    $file_usage   = \Drupal::service('file.usage');
    foreach (['light', 'dark'] as $slot) {
      $old_fid = (int) $config->get("logos.{$slot}");
      $fids = $form_state->getValue(['logos', $slot]);
      $new_fid = is_array($fids) ? (int) reset($fids) : (int) $fids;

      if ($new_fid && $new_fid !== $old_fid) {
        $file = $file_storage->load($new_fid);
        if ($file) {
          if ($file->isTemporary()) {
            $file->setPermanent();
            $file->save();
          }
          $file_usage->add($file, 'dc_brand', 'config', $slot);
        }
      }

      if ($old_fid && $old_fid !== $new_fid) {
        $old_file = $file_storage->load($old_fid);
        if ($old_file) {
          $file_usage->delete($old_file, 'dc_brand', 'config', $slot);
        }
      }

      $config->set("logos.{$slot}", $new_fid ?: NULL);
    }

    $config->set('build_hook.url',              (string) $form_state->getValue(['build_hook', 'url']));
    $config->set('build_hook.debounce_seconds', (int)    $form_state->getValue(['build_hook', 'debounce_seconds']));
    $config->set('preview.url',                 (string) $form_state->getValue(['preview', 'url']));
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
