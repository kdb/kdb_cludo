<?php

namespace Drupal\kdb_cludo\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Overriding config dynamically.
 */
class ConfigOverrides implements ConfigFactoryOverrideInterface {

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $names
   *   The available config names.
   *
   * @return array<mixed>
   *   The config overrides.
   */
  public function loadOverrides($names): array {
    return $this->enableParagraph($names, 'kdb_cludo', ['article', 'page']);
  }

  /**
   * Allowing a specific paragraph to be added to specific node content types.
   *
   * @param array<mixed> $names
   *   The available config names, passed from $this->loadOverrides().
   * @param string $paragraph_id
   *   The paragraph ID.
   * @param array<mixed> $content_types
   *   The node content types that should be able to add the paragraph to.
   *
   * @return array<mixed>
   *   The overrides, used for returning $this->loadOverrides().
   */
  protected function enableParagraph(array $names, string $paragraph_id, array $content_types): array {
    $overrides = [];

    foreach ($content_types as $content_type) {
      $key = "field.field.node.$content_type.field_paragraphs";

      if (in_array($key, $names)) {
        $overrides[$key]['settings']['handler_settings']['target_bundles'] = [
          $paragraph_id => $paragraph_id,
        ];

        $overrides[$key]['settings']['handler_settings']['target_bundles_drag_drop'] = [
          $paragraph_id => [
            'weight' => 0,
            'enabled' => TRUE,
          ],
        ];
      }
    }

    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'ConfigOverrides';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
