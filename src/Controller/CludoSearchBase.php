<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dpl_breadcrumb\Services\BreadcrumbHelper;
use Drupal\drupal_typed\DrupalTyped;
use Drupal\kdb_cludo\CludoProfile;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base, used for embedding Cludo search pages and defining profiles.
 */
abstract class CludoSearchBase extends ControllerBase {

  /**
   * The related Cludo Profile, containing settings and config context.
   */
  public CludoProfile $profile;

  /**
   * Display facets and filters.
   */
  public bool $showFilters = TRUE;

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

    if (!$config['enabled']) {
      throw new AccessDeniedHttpException();
    }

    $breadcrumb = NULL;
    $cache_tags = ['kdb_cludo'];
    $breadcrumb_node_id = $request->query->get('breadcrumb');

    if ($breadcrumb_node_id) {
      $service = DrupalTyped::service(BreadcrumbHelper::class, 'dpl_breadcrumb.breadcrumb_helper');
      $breadcrumb_node = $this->entityTypeManager()->getStorage('node')->load($breadcrumb_node_id);

      if ($breadcrumb_node instanceof NodeInterface) {
        $cache_tags[] = "node:{$breadcrumb_node->id()}";

        $breadcrumb = $service->getBreadcrumb($breadcrumb_node);

        if (empty($breadcrumb->getLinks())) {
          $breadcrumb->addLink($breadcrumb_node->toLink());
        }
      }
    }

    $jsSettings = $this->profile->getJsSettings();

    if ($this->showFilters) {
      $theme = 'kdb_cludo_search_page';
      $jsSettings['searchInputSelectors'] = ['#cludo-search-input-subsearch'];
    }
    else {
      $theme = 'kdb_cludo_search_page__simple';
      $jsSettings['searchInputSelectors'] = ['#cludo-search-input', '.cludo-header-search'];
    }

    if ($this->profile->cludoType === 'help') {
      $jsSettings['searchInputSelectors'] = ['#cludo-search-input-help'];

    }

    $return = [
      '#theme' => $theme,
      '#profile' => $this->profile,
      '#title' => $config['show_title'] ? $this->getTitle() : NULL,
      '#breadcrumb' => $breadcrumb,
      '#attached' => [
        'library' => [
          'kdb_cludo/base',
        ],

        'drupalSettings' => [
          'kdb_cludo' => $jsSettings,
        ],

      ],
      '#cache' => [
        'tags' => $cache_tags,
      ],
    ];

    $placeholder = $config['input_placeholder'] ?? NULL;

    if ($placeholder) {
      $return['#label'] = $placeholder;
    }

    return $return;
  }

}
