<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\kdb_cludo\CludoProfile;

/**
 * Defining the Cludo Search page for help universe.
 */
class CludoSearchHelp extends CludoSearchBase {

  public function __construct() {
    $this->profile = new CludoProfile(
      id: 'help',
      translatableLabel: 'Help search page',
      cludoType: 'help',
      cludoRouteName: 'kdb_cludo.search_page.help',
      cludoEngineId: 11866,
    );
  }

}
