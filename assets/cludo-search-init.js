(function cludoSearchInit(Drupal, drupalSettings) {
  const settings = (drupalSettings && drupalSettings.kdb_cludo) || {};
  // Apply values coming from the controller settings.
  window.cludo_customerId = settings.customerId;
  window.cludo_engineId = settings.engineId;
  window.cludo_language = settings.language;
  window.cludo_searchUrl = settings.searchUrl;
  window.cludo_searchType = settings.searchType;
  window.cludo_searchInputSelectors = settings.searchInputSelectors;

  // The searchResults script from Cludo uses the main engineId in the URL,
  // rather than the currently selected profiles.
  const mainEngineId = '14490';

  // The external Cludo scripts need to be placed after the init script, and
  // also need customer and engine IDs.
  // We'll fix both issues by adding them as dynamic script tags.
  const searchResultsScript = document.createElement('script');
  searchResultsScript.src = `https://customer.cludo.com/templates/${settings.customerId}/${mainEngineId}/dist/js/cludo-search-results.js`;
  searchResultsScript.defer = true;
  document.body.appendChild(searchResultsScript);

  const managerScript = document.createElement('script');
  managerScript.src =
    'https://customer.cludo.com/scripts/bundles/experiences/manager.js';
  managerScript.defer = true;
  managerScript.id = 'cludo-experience-manager';
  managerScript.setAttribute('data-cid', settings.customerId);
  document.body.appendChild(managerScript);
})(Drupal, drupalSettings);
