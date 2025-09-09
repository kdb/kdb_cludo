<?php

namespace Drupal\kdb_cludo\Controller;

use Drupal\kdb_cludo\CludoProfile;

/**
 * Defining the Cludo Search page for events.
 */
class CludoSearchEvent extends CludoSearchBase {

  public function __construct() {
    $this->profile = new CludoProfile(
      id: 'event',
      translatableLabel: 'Events search page (/events)',
      cludoType: 'event',
      cludoRouteName: 'kdb_cludo.search_page.event',
      cludoEngineId: 14520,
      viewId: 'events',
      viewRouteName: 'view.events.all',
    );
  }

}
