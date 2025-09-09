<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\kdb_cludo\CludoProfile;

/**
 * Defining the Cludo Search page for english help universe.
 */
class CludoSearchHelpEnglish extends CludoSearchBase {

  public function __construct() {
    $this->profile = new CludoProfile(
      id: 'help_en',
      translatableLabel: 'Help search page (English)',
      cludoType: 'help',
      cludoRouteName: 'kdb_cludo.search_page.help_english',
      cludoEngineId: 12014,
      cludoLanguage: 'en',
    );
  }

}
