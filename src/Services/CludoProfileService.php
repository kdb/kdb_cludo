<?php

namespace Drupal\kdb_cludo\Services;

use Drupal\kdb_cludo\CludoProfile;

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
