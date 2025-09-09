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
   * The config settings.
   */
  public ImmutableConfig $config;

  public function __construct(
    public string $id,
    protected string $translatableLabel,
    public string $cludoType,
    public string $cludoRouteName,
    public int $cludoEngineId,
    public string $cludoLanguage = 'da',
    public ?string $viewId = NULL,
    public ?string $viewRouteName = NULL,
  ) {
    if (!empty($this->translatableLabel)) {
      $configService = DrupalTyped::service(ConfigFactoryInterface::class, 'config.factory');
      $this->config = $configService->get(CludoSettingsForm::CONFIG_SETTINGS_KEY);

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
   * Getting the editor-saved config settings for this profile.
   *
   * @return array<mixed>
   *   Config values.
   */
  public function getConfigSettings(): array {
    return $this->config->get("profiles.{$this->id}") ?? [];
  }

  /**
   * Child classes override this to provide page-specific settings.
   *
   * @return array<mixed>
   *   Values to be passed to drupalSettings.
   */
  public function getJsSettings(): array {
    return [
      'searchType' => $this->cludoType,
      'engineId' => $this->cludoEngineId,
      'language' => $this->cludoLanguage,
      'searchUrl' => $this->getCludoUrl()?->toString(),
      'searchInputSelectors' => ['#cludo-search-input', '.cludo-header-search'],
    ];
  }

}
