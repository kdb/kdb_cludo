<?php

namespace Drupal\kdb_cludo\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\views\Views;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\kdb_cludo\Services\CludoProfileService;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * General Settings form for the KDB Cludo search pages.
 */
class CludoSettingsForm extends ConfigFormBase {

  /**
   * The config domain that has the saved settings.
   */
  public const CONFIG_SETTINGS_KEY = 'kdb_cludo.settings';

  public function __construct(ConfigFactoryInterface $configFactory, private CacheTagsInvalidatorInterface $cacheTagsInvalidator, private CludoProfileService $cludoProfileService) {
    parent::__construct($configFactory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('cache_tags.invalidator'),
      $container->get('kdb_cludo.cludo_profile'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'kdb_cludo_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      self::CONFIG_SETTINGS_KEY,
    ];
  }

  /**
   * The form elements, containing settings for each profile.
   *
   * We have this in a separate method, as we need to be able to pull
   * these in the submit form easily.
   *
   * @return array<mixed>
   *   Form elements, used by BuildForm().
   */
  protected function getProfileFormElements(): array {
    $default_title = $this->t('Search', [], ['context' => 'kdb_cludo']);
    $default_input_placeholder = $this->t('Search bibliotek.kk.dk', [], ['context' => 'kdb_cludo']);

    return [
      'enabled' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable', [], ['context' => 'kdb_cludo']),
      ],
      'show_title' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show title on page', [], ['context' => 'kdb_cludo']),
        '#description' => $this->t('If not selected, it will only be shown in the browser tab.', [], ['context' => 'kdb_cludo']),
      ],
      'title' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => $this->t('Page title', [], ['context' => 'kdb_cludo']),
        '#placeholder' => $default_title,
      ],
      'input_label' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => $this->t('Input-field label', [], ['context' => 'kdb_cludo']),
        '#placeholder' => $default_title,
      ],
      'input_placeholder' => [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#title' => $this->t('Input-field placeholder', [], ['context' => 'kdb_cludo']),
        '#description' => $this->t('What is shown inside the search input field by default, as a placeholder.', [], ['context' => 'kdb_cludo']),
        '#placeholder' => $default_input_placeholder,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_SETTINGS_KEY);
    $form = parent::buildForm($form, $form_state);

    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cludo API setup', [], ['context' => 'kdb_cludo']),
      'customer_id' => [
        '#type' => 'number',
        '#title' => $this->t('Cludo customer ID', [], ['context' => 'kdb_cludo']),
        '#default_value' => $config->get("customer_id"),
      ],
      'api_key' => [
        '#type' => 'textfield',
        '#title' => $this->t('Cludo API key', [], ['context' => 'kdb_cludo']),
        '#description' => $this->t('<a href="@url">Cludo documentation</a>', [
          '@url' => 'https://docs.cludo.com/#authentication_basic',
        ], ['context' => 'kdb_cludo']),
        '#default_value' => $config->get("api_key"),
      ],
    ];

    $form['url_pushing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('URL pushing to Cludo', [], ['context' => 'kdb_cludo']),
      'enable_url_pushing' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable URL pushing', [], ['context' => 'kdb_cludo']),
        '#description' => $this->t(
          'If enabled, content will automatically be pushed to Cludo as soon as it is saved.
        <strong>NOTICE!</strong> Make sure that English content is marked up correctly, or it may be pushed to the danish crawler (or vice versa)',
          [], ['context' => 'kdb_cludo']
        ),
        '#default_value' => !empty($config->get("enable_url_pushing")),
      ],
      'crawler_id' => [
        '#type' => 'number',
        '#title' => $this->t('Crawler ID', [], ['context' => 'kdb_cludo']),
        '#default_value' => $config->get('crawler_id'),
      ],
      'crawler_id_english' => [
        '#type' => 'number',
        '#title' => $this->t('Crawler ID (English content)', [], ['context' => 'kdb_cludo']),
        '#default_value' => $config->get('crawler_id_english'),
      ],
    ];

    foreach ($this->cludoProfileService->getProfiles() as $profile) {
      $id = $profile->id;
      $form["profile_{$id}"] = [
        '#type' => 'fieldset',
        '#title' => $profile->label,
        '#description' => $this->t('<a href="@url" target="_blank">@url</a> | engineId: @id', [
          '@url' => Url::fromRoute($profile->cludoRouteName)->toString(),
          '@id' => $profile->cludoEngineId,
        ], ['context' => 'kdb_cludo']),
      ];

      foreach ($this->getProfileFormElements() as $key => $element) {
        $element['#default_value'] = $config->get("profiles.{$id}.$key") ?? 'Search';

        if ($key !== 'enabled') {
          $element['#states'] = [
            'visible' => [
              ":input[name=\"{$id}_enabled\"]" => ['checked' => TRUE],
            ],
          ];
        }

        $form["profile_{$id}"]["{$id}_{$key}"] = $element;
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(self::CONFIG_SETTINGS_KEY);

    $config->set('customer_id', $form_state->getValue('customer_id'));
    $config->set('api_key', $form_state->getValue('api_key'));
    $config->set('enable_url_pushing', $form_state->getValue('enable_url_pushing'));
    $config->set('crawler_id', $form_state->getValue('crawler_id'));
    $config->set('crawler_id_english', $form_state->getValue('crawler_id_english'));

    $profiles_settings = [];

    foreach ($this->cludoProfileService->getProfiles() as $profile) {
      $profile_setting_keys = array_keys($this->getProfileFormElements());
      $profile_settings = [];

      foreach ($profile_setting_keys as $setting_key) {
        $profile_settings[$setting_key] = $form_state->getValue("{$profile->id}_{$setting_key}");
      }

      $profiles_settings[$profile->id] = $profile_settings;

      $view = $profile->viewId ? Views::getView($profile->viewId) : NULL;

      if ($view instanceof ViewExecutable) {
        $view->storage->invalidateCaches();
      }
    }

    $config->set('profiles', $profiles_settings);
    $config->save();

    $this->cacheTagsInvalidator->invalidateTags(['kdb_cludo']);

    parent::submitForm($form, $form_state);
  }

}
