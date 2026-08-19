(function ($, Drupal, drupalSettings) {
  'use strict';

  function csrfToken() {
    return drupalSettings.topicalboostCsrfToken || '';
  }

  Drupal.topicalboostCsrfHeaders = function (headers) {
    return Object.assign({}, headers || {}, {
      'X-CSRF-Token': csrfToken()
    });
  };

  $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
    var method = (options.type || options.method || 'GET').toUpperCase();
    if (['GET', 'HEAD', 'OPTIONS', 'TRACE'].indexOf(method) !== -1) {
      return;
    }

    var url;
    try {
      url = new URL(options.url || window.location.href, window.location.href);
    }
    catch (error) {
      return;
    }

    if (url.origin === window.location.origin && url.pathname.indexOf('/api/topicalboost/') === 0) {
      jqXHR.setRequestHeader('X-CSRF-Token', csrfToken());
    }
  });
})(jQuery, Drupal, drupalSettings);
