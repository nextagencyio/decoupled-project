<?php

namespace Drupal\dc_brand\Form;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dc_brand\Service\BuildHookDispatcher;
use Drupal\dc_brand\Service\GoogleFontsRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Brand settings admin form — fonts, colors, logos, build hook.
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
    // Satisfy ConfigFormBase's expected dependencies.
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

    $form['typography'] = [
      '#type'  => 'details',
      '#title' => $this->t('Typography'),
      '#open'  => TRUE,
      '#tree'  => TRUE,
    ];
    $form['typography']['heading'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Heading font'),
      '#options'       => $font_options,
      '#default_value' => $config->get('fonts.heading'),
      '#description'   => $this->t('Applied to h1–h6 and any element with the display font class.'),
    ];
    $form['typography']['body'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Body font'),
      '#options'       => $font_options,
      '#default_value' => $config->get('fonts.body'),
      '#description'   => $this->t('Applied to body copy everywhere.'),
    ];

    $form['colors'] = [
      '#type'  => 'details',
      '#title' => $this->t('Colors'),
      '#open'  => TRUE,
      '#tree'  => TRUE,
      '#description' => $this->t('Enter one hex color per slot. A 9-stop ramp (50–900) is auto-derived using HSL lightness stepping.'),
    ];
    foreach (['primary', 'secondary', 'accent', 'neutral', 'background'] as $key) {
      $form['colors'][$key] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('@label color', ['@label' => ucfirst($key)]),
        '#default_value' => $config->get("colors.{$key}"),
        '#size'          => 12,
        '#maxlength'     => 7,
        '#attributes'    => ['placeholder' => '#3b82f6', 'pattern' => '^#?[0-9a-fA-F]{6}$'],
        '#required'      => TRUE,
      ];
    }

    $form['logos'] = [
      '#type'  => 'details',
      '#title' => $this->t('Logos'),
      '#open'  => TRUE,
      '#tree'  => TRUE,
    ];
    $form['logos']['light'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Light-mode logo'),
      '#default_value'     => $config->get('logos.light') ? [$config->get('logos.light')] : NULL,
      '#upload_location'   => 'public://brand/',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg svg webp']],
    ];
    $form['logos']['dark'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Dark-mode logo'),
      '#default_value'     => $config->get('logos.dark') ? [$config->get('logos.dark')] : NULL,
      '#upload_location'   => 'public://brand/',
      '#upload_validators' => ['FileExtension' => ['extensions' => 'png jpg jpeg svg webp']],
    ];

    $form['build_hook'] = [
      '#type'  => 'details',
      '#title' => $this->t('Build hook'),
      '#open'  => FALSE,
      '#tree'  => TRUE,
    ];
    $form['build_hook']['url'] = [
      '#type'          => 'url',
      '#title'         => $this->t('Build hook URL'),
      '#default_value' => $config->get('build_hook.url'),
      '#description'   => $this->t('Netlify or Vercel build hook URL to POST on save. Leave blank to skip auto-deploy.'),
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
    $colors = $form_state->getValue('colors');
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

    // Mark any uploaded files as permanent + register file usage.
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
