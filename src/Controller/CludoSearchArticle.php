<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\kdb_cludo\CludoProfile;

/**
 * Defining the Cludo Search page for articles.
 */
class CludoSearchArticle extends CludoSearchBase {

  public function __construct() {
    $this->profile = new CludoProfile(
      id: 'article',
      translatableLabel: 'Article search page (/articles)',
      cludoType: 'article',
      cludoRouteName: 'kdb_cludo.search_page.article',
      cludoEngineId: 14521,
      viewId: 'articles',
      viewRouteName: 'view.articles.all'
    );
  }

}
