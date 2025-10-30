(function cludoSearchInit(Drupal, drupalSettings) {
  const settings = (drupalSettings && drupalSettings.kdb_cludo) || {};
  // Apply values coming from the controller settings.
  window.cludo_customerId = settings.customerId;
  window.cludo_engineId = settings.engineId;
  window.cludo_language = settings.language;
  window.cludo_searchUrl = settings.searchUrl;
  window.cludo_searchType = settings.searchType;
  window.cludo_searchInputSelectors = settings.searchInputSelectors;

  // Enable async/await, so we can make sure that the scripts are loaded
  // in the right order, and not loaded until the others are ready.
  function loadScript(src, attrs = {}) {
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      script.defer = true;

      // Apply optional attributes (like id, data-cid, etc.)
      Object.entries(attrs).forEach(([key, value]) => {
        script.setAttribute(key, value);
      });

      script.onload = () => resolve(script);
      script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
      document.body.appendChild(script);
    });
  }

  // The external Cludo scripts need to be placed after the init script, and
  // also need customer and engine IDs.
  // We'll fix both issues by adding them as dynamic script tags.
  (async () => {
    try {
      // The searchResults script from Cludo uses the main engineId in the URL,
      // rather than the currently selected profiles.
      const mainEngineId = '14490';
      const customerId = window.cludo_customerId;

      await loadScript(
        'https://customer.cludo.com/scripts/bundles/search-script.min.js?v2',
      );

      await loadScript(
        `https://customer.cludo.com/templates/${customerId}/${mainEngineId}/dist/js/cludo-search-results.js?v2`,
      );

      await loadScript(
        'https://customer.cludo.com/scripts/bundles/experiences/manager.js?v2',
        {
          id: 'cludo-experience-manager',
          'data-cid': customerId,
        },
      );
    } catch (err) {
      console.error(err);
    }
  })();
})(Drupal, drupalSettings);
