<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\kdb_cludo\CludoProfile;

/**
 * Defining the Cludo Search page for main search.
 */
class CludoSearchMain extends CludoSearchBase {

  public function __construct() {
    $this->showFilters = FALSE;

    $this->profile = new CludoProfile(
      id: 'main',
      translatableLabel: 'Main search page (/search/web)',
      cludoType: 'main',
      cludoRouteName: 'kdb_cludo.search_page.main',
      cludoEngineId: 14490,
      viewId: 'editorial_search',
      viewRouteName: 'view.editorial_search.page'
    );
  }

}
