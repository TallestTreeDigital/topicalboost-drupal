(function ($, once) {
  'use strict';

  // Polyfill for the legacy jQuery once plugin that existed in Drupal <9.
  // In Drupal 10/11, the once() utility is provided as a standalone function
  // but the jQuery prototype method was removed. This polyfill restores
  // the old syntax (e.g. $(selector).once('namespace')) so that older
  // custom scripts continue to work without modification.
  if (typeof $.fn.once !== 'function') {
    $.fn.once = function (id) {
      // Default to an empty string if no ID is provided, matching old behaviour.
      var nspace = id || 'jquery-once-polyfill';
      // The `once()` utility can accept an Element|NodeList instead of a selector.
      // We pass the current jQuery collection to filter out already-processed
      // elements and return the new set as a jQuery collection.
      var elements = once(nspace, this);
      return $(elements);
    };
  }
})(jQuery, once); 