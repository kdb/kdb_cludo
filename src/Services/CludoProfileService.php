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
    $profileIds = [
      'main', 'article', 'event', 'help', 'help_en',
    ];

    $profiles = [];

    foreach ($profileIds as $profileId) {
      $profile = $this->getProfile($profileId);

      if ($profile) {
        $profiles[] = $profile;
      }
    }

    return $profiles;
  }

  /**
   * Finding a profile, by ID lookup.
   */
  public function getProfile(string $id): ?CludoProfile {
    switch ($id) {
      case 'main':
        return new CludoProfile(
          id: 'main',
          translatableLabel: 'Main search page (/search/web)',
          cludoType: 'main',
          cludoRouteName: 'kdb_cludo.search_page.main',
          cludoEngineId: 14490,
          showFilters: FALSE,
          viewId: 'editorial_search',
          viewRouteName: 'view.editorial_search.page'
        );

      case 'article':
        return new CludoProfile(
            id: 'article',
            translatableLabel: 'Article search page (/articles)',
            cludoType: 'article',
            cludoRouteName: 'kdb_cludo.search_page.article',
            cludoEngineId: 14521,
            viewId: 'articles',
            viewRouteName: 'view.articles.all'
          );

      case 'event':
        return new CludoProfile(
          id: 'event',
          translatableLabel: 'Events search page (/events)',
          cludoType: 'event',
          cludoRouteName: 'kdb_cludo.search_page.event',
          cludoEngineId: 14520,
          viewId: 'events',
          viewRouteName: 'entity.eventinstance.collection',
        );

      case 'help':
        return new CludoProfile(
          id: 'help',
          translatableLabel: 'Help search page',
          cludoType: 'help',
          cludoRouteName: 'kdb_cludo.search_page.help',
          cludoEngineId: 11866,
        );

      case 'help_en':
        return new CludoProfile(
          id: 'help_en',
          translatableLabel: 'Help search page (English)',
          cludoType: 'help',
          cludoRouteName: 'kdb_cludo.search_page.help_english',
          cludoEngineId: 12014,
          cludoLanguage: 'en',
        );
    }

    return NULL;
  }

}
