<?php

namespace Drupal\kdb_cludo\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\views\Views;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\kdb_cludo\Services\CludoProfileService;
use Drupal\kdb_cludo\Services\CludoPushQueue;
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

  public function __construct(ConfigFactoryInterface $configFactory, private CacheTagsInvalidatorInterface $cacheTagsInvalidator, private CludoProfileService $cludoProfileService, protected CludoPushQueue $cludoPushQueue) {
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
      $container->get('kdb_cludo.push_queue'),
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
      'push_urls_per_request' => [
        '#type' => 'number',
        '#min' => 1,
        '#title' => $this->t('URLs per request', [], ['context' => 'kdb_cludo']),
        '#description' => $this->t('How many URLs we send to Cludo in one request. Lower this if Cludo starts rejecting our requests.', [], ['context' => 'kdb_cludo']),
        '#default_value' => $config->get('push_urls_per_request') ?: CludoPushQueue::DEFAULT_URLS_PER_REQUEST,
      ],
      'push_requests_per_cron' => [
        '#type' => 'number',
        '#min' => 1,
        '#title' => $this->t('Requests per cron run', [], ['context' => 'kdb_cludo']),
        '#description' => $this->t('How many requests we make to Cludo on a single cron run. This is what keeps a large backlog from turning into a burst that Cludo rate limits.', [], ['context' => 'kdb_cludo']),
        '#default_value' => $config->get('push_requests_per_cron') ?: CludoPushQueue::DEFAULT_REQUESTS_PER_CRON,
      ],
    ];

    $pending = $this->cludoPushQueue->getPendingCount();

    $form['url_pushing']['queue_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Push queue', [], ['context' => 'kdb_cludo']),
      '#markup' => $this->formatPlural(
        $pending,
        '@count URL is waiting to be pushed to Cludo. URLs are pushed on cron.',
        '@count URLs are waiting to be pushed to Cludo. URLs are pushed on cron.',
        [], ['context' => 'kdb_cludo']
      ),
    ];

    $form['url_pushing']['process_queue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Push queued URLs now', [], ['context' => 'kdb_cludo']),
      '#submit' => ['::processQueueSubmit'],
      // We're not saving the form, so the other fields shouldn't be validated.
      '#limit_validation_errors' => [],
      '#access' => ($pending > 0),
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
    $config->set('push_urls_per_request', $form_state->getValue('push_urls_per_request'));
    $config->set('push_requests_per_cron', $form_state->getValue('push_requests_per_cron'));

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

  /**
   * Submit handler for draining the push queue, without waiting for cron.
   *
   * This pushes at most one cron run worth of URLs - anything beyond that is
   * left for cron, so we don't sit and time out on a large backlog.
   *
   * @param array<mixed> $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function processQueueSubmit(array &$form, FormStateInterface $form_state): void {
    $pushed = $this->cludoPushQueue->processQueue();
    $pending = $this->cludoPushQueue->getPendingCount();

    $this->messenger()->addStatus($this->formatPlural(
      $pushed,
      'Pushed @count URL to Cludo. @pending left in the queue.',
      'Pushed @count URLs to Cludo. @pending left in the queue.',
      ['@pending' => $pending],
      ['context' => 'kdb_cludo']
    ));
  }

}
