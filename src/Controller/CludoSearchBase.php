<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\drupal_typed\DrupalTyped;
use Drupal\kdb_cludo\CludoProfile;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base, used for embedding Cludo search pages and defining profiles.
 */
abstract class CludoSearchBase extends ControllerBase {

  /**
   * The related Cludo Profile, containing settings and config context.
   */
  public CludoProfile $profile;

  /**
   * Getting the title to be displayed in browser tab, and possibly on page.
   */
  public function getTitle(): ?string {
    $config = $this->profile->getConfigSettings();

    return $config['title'] ?? NULL;
  }

  /**
   * The search results page.
   *
   * @return array<mixed>
   *   Render array, for the page.
   */
  public function page(Request $request): array {
    $config = $this->profile->getConfigSettings();

    $cache_tags = ['kdb_cludo'];




    return [
      '#theme' => 'kdb_cludo_search_page',
      '#title' => $config['show_title'] ? $this->getTitle() : NULL,
      '#attached' => [
        'library' => [
          'kdb_cludo/base',
        ],

        'drupalSettings' => [
          'kdb_cludo' => $this->profile->getJsSettings(),
        ],

      ],
      '#cache' => [
        'tags' => $cache_tags,
      ],

    ];
  }

}
