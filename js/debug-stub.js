(function (Drupal, drupalSettings) {
  'use strict';

  // Create ttd_topics namespace if it doesn't exist
  Drupal.ttd_topics = Drupal.ttd_topics || {};

  // Simple debug stub that only logs if debug mode is enabled
  Drupal.ttd_topics.debug = {
    log: function() {
      // Only log if debug mode is enabled and console is available
      const debugMode = (drupalSettings && drupalSettings.ttd_topics && drupalSettings.ttd_topics.debug_mode) ||
                       (Drupal.settings && Drupal.settings.ttd_topics && Drupal.settings.ttd_topics.debug_mode);
      if (debugMode && typeof console !== 'undefined' && console.log) {
        console.log.apply(console, ['[TtdTopics Debug]'].concat(Array.prototype.slice.call(arguments)));
      }
    },
    error: function() {
      // Always log errors regardless of debug mode
      if (typeof console !== 'undefined' && console.error) {
        console.error.apply(console, ['[TtdTopics Error]'].concat(Array.prototype.slice.call(arguments)));
      }
    },
    warn: function() {
      // Only log warnings if debug mode is enabled
      const debugMode = (drupalSettings && drupalSettings.ttd_topics && drupalSettings.ttd_topics.debug_mode) ||
                       (Drupal.settings && Drupal.settings.ttd_topics && Drupal.settings.ttd_topics.debug_mode);
      if (debugMode && typeof console !== 'undefined' && console.warn) {
        console.warn.apply(console, ['[TtdTopics Warning]'].concat(Array.prototype.slice.call(arguments)));
      }
    }
  };

})(Drupal, drupalSettings); 