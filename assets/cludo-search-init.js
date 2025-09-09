(function cludoSearchInit(Drupal, drupalSettings) {
  const settings = (drupalSettings && drupalSettings.kdb_cludo) || {};
  // Apply values coming from the controller settings.
  window.cludo_engineId = settings.engineId;
  window.cludo_language = settings.language;
  window.cludo_searchUrl = settings.searchUrl;
  window.cludo_searchType = settings.searchType;
  window.cludo_searchInputSelectors = settings.searchInputSelectors;
})(Drupal, drupalSettings);
