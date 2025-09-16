<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\dpl_breadcrumb\Services\BreadcrumbHelper;
use Drupal\drupal_typed\DrupalTyped;
use Drupal\kdb_cludo\CludoProfile;
use Drupal\kdb_cludo\Services\CludoProfileService;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base, used for embedding Cludo search pages and defining profiles.
 */
class CludoSearchPage extends ControllerBase {

  /**
   * The related Cludo Profile, containing settings and config context.
   */
  public ?CludoProfile $profile;

  /**
   * Getting the title to be displayed in browser tab, and possibly on page.
   */
  public function getTitle(Request $request): string {
    $this->setCludoProfile($request);

    return $this->profile?->getTitle() ?? '';
  }

  /**
   * Setting the Cludo profile, from attribute set in routing.yml.
   */
  protected function setCludoProfile(Request $request): ?CludoProfile {
    $cludoProfileService = DrupalTyped::service(CludoProfileService::class, 'kdb_cludo.cludo_profile');
    $cludoProfileId = $request->attributes->get('_cludoProfileId');
    $this->profile = $cludoProfileService->getProfile($cludoProfileId);

    return $this->profile;
  }

  /**
   * The search results page.
   *
   * @return array<mixed>
   *   Render array, for the page.
   */
  public function page(Request $request): array {
    $this->setCludoProfile($request);

    if (!$this->profile || !$this->profile->getEnabled()) {
      throw new AccessDeniedHttpException();
    }

    $breadcrumb = NULL;
    $cache_tags = ['kdb_cludo'];
    $breadcrumb_node_id = $request->query->get('breadcrumb');

    if ($breadcrumb_node_id) {
      $breadcrumb_node = $this->entityTypeManager()->getStorage('node')->load($breadcrumb_node_id);

      if ($breadcrumb_node instanceof NodeInterface) {
        $breadcrumbHelper = DrupalTyped::service(BreadcrumbHelper::class, 'dpl_breadcrumb.breadcrumb_helper');
        $breadcrumb = $breadcrumbHelper->getBreadcrumb($breadcrumb_node);

        if (empty($breadcrumb->getLinks())) {
          $breadcrumb->addLink($breadcrumb_node->toLink());
        }

        $pathValidator = DrupalTyped::service(PathValidatorInterface::class, 'path.validator');
        $currentUrl = $pathValidator->getUrlIfValid($request->getRequestUri());
        $breadcrumb_links = $breadcrumb->getLinks();

        if ($currentUrl) {
          $breadcrumb_links = array_merge(
            [Link::fromTextAndUrl($this->getTitle($request), $currentUrl)],
            $breadcrumb_links
          );
        }

        $breadcrumb = (new Breadcrumb())->setLinks($breadcrumb_links);
      }
    }

    $jsSettings = $this->profile->getJsSettings();

    if ($this->profile->showFilters) {
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
      '#title' => $this->profile->getShowTitle() ? $this->getTitle($request) : NULL,
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
        'contexts' => ['url.query_args:breadcrumb'],
      ],
    ];

    $placeholder = $this->profile->getInputPlaceholder();

    if ($placeholder) {
      $return['#label'] = $placeholder;
    }

    return $return;
  }

}
