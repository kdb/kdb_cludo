<?php

namespace Drupal\kdb_cludo;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\drupal_typed\DrupalTyped;
use Drupal\kdb_cludo\Form\CludoSettingsForm;

/**
 * A combined profile, containing all the context for displaying a search.
 */
class CludoProfile {

  /**
   * The translated label, created when passing simple string to constructor.
   */
  public ?TranslatableMarkup $label;

  /**
   * The general config, for the entire kdb_cludo module.
   */
  public ImmutableConfig $config;

  /**
   * The config-saved settings for this specific profile.
   *
   * @var array<mixed>
   */
  public array $profileSettings;

  public function __construct(
    public string $id,
    protected string $translatableLabel,
    public string $cludoType,
    public string $cludoRouteName,
    public int $cludoEngineId,
    public string $cludoLanguage = 'da',
    public bool $showFilters = TRUE,
    public ?string $viewId = NULL,
    public ?string $viewRouteName = NULL,
  ) {
    if (!empty($this->translatableLabel)) {
      $configService = DrupalTyped::service(ConfigFactoryInterface::class, 'config.factory');
      $this->config = $configService->get(CludoSettingsForm::CONFIG_SETTINGS_KEY);
      $this->profileSettings = $this->config->get("profiles.{$this->id}") ?? [];

      // @codingStandardsIgnoreLine Drupal.Semantics.FunctionT.StringLiteralsOnly
      $this->label = new TranslatableMarkup($this->translatableLabel);
    }

  }

  /**
   * Simple getter.
   */
  public function get(string $key): mixed {
    return $this->{$key} ?? NULL;
  }

  /**
   * Getting the URL object, pointing to the Cludo Search page.
   */
  public function getCludoUrl(): ?Url {
    return $this->getUrl($this->cludoRouteName);
  }

  /**
   * Getting the URL object, pointing to the original non-Cludo view.
   */
  public function getViewUrl(): ?Url {
    return $this->getUrl($this->viewRouteName);
  }

  /**
   * Loading URL object, based on route name.
   */
  protected function getUrl(?string $routeName): ?Url {
    if (!$routeName) {
      return NULL;
    }

    try {
      return Url::fromRoute($routeName);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Getting editor-set setting: enabled.
   */
  public function getEnabled(): bool {
    return $this->profileSettings['enabled'] ?? FALSE;
  }

  /**
   * Getting editor-set setting: show title on page.
   */
  public function getShowTitle(): bool {
    return $this->profileSettings['show_title'] ?? FALSE;
  }

  /**
   * Getting editor-set setting: page title.
   */
  public function getTitle(): string {
    return $this->profileSettings['title'] ?? '';
  }

  /**
   * Getting editor-set setting: input label text.
   */
  public function getInputLabel(): string {
    return $this->profileSettings['input_label'] ?? '';
  }

  /**
   * Getting editor-set setting: input placeholder text.
   */
  public function getInputPlaceholder(): string {
    return $this->profileSettings['input_placeholder'] ?? '';
  }

  /**
   * The JS settings that need to be sent along for Cludo embedding to work.
   *
   * @return array<mixed>
   *   Values to be passed to drupalSettings.
   */
  public function getJsSettings(): array {
    return [
      'customerId' => $this->config->get('customer_id'),
      'searchType' => $this->cludoType,
      'engineId' => $this->cludoEngineId,
      'language' => $this->cludoLanguage,
      'searchUrl' => $this->getCludoUrl()?->toString(),
    ];
  }

}
