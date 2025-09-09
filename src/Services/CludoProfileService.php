<?php

namespace Drupal\kdb_cludo\Services;

use Drupal\kdb_cludo\CludoProfile;
use Drupal\kdb_cludo\Controller\CludoSearchArticle;
use Drupal\kdb_cludo\Controller\CludoSearchEvent;
use Drupal\kdb_cludo\Controller\CludoSearchHelp;
use Drupal\kdb_cludo\Controller\CludoSearchHelpEnglish;
use Drupal\kdb_cludo\Controller\CludoSearchMain;

/**
 * Helper service, for getting Cludo search profiles.
 */
class CludoProfileService {

  /**
   * Getting all of the available profiles.
   *
   * @return \Drupal\kdb_cludo\CludoProfile[]
   *   List of profiles.
   */
  public function getProfiles(): array {
    return [
      (new CludoSearchMain())->profile,
      (new CludoSearchArticle())->profile,
      (new CludoSearchEvent())->profile,
      (new CludoSearchHelp())->profile,
      (new CludoSearchHelpEnglish())->profile,
    ];
  }

  /**
   * Finding a profile, by value lookup.
   */
  public function getProfileByValue(string $value, string $key = 'id'): ?CludoProfile {
    foreach ($this->getProfiles() as $profile) {
      if ($value === $profile->$key) {
        return $profile;
      }
    }

    return NULL;
  }

}
