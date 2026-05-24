(function () {
  'use strict';

  var widget = document.getElementById('searchclippings-widget');
  if (!widget) return;

  var apiKey = widget.getAttribute('data-api-key');
  var apiEndpoint = widget.getAttribute('data-api-endpoint');

  if (!apiKey || !apiEndpoint) {
    widget.innerHTML = '<p>Search Clippings widget requires an API key.</p>';
    return;
  }

  // Load the external widget script.
  var script = document.createElement('script');
  script.src = apiEndpoint + '/widgets/search-clippings.js';
  script.setAttribute('data-api-key', apiKey);
  script.setAttribute('data-target', 'searchclippings-widget');
  script.async = true;
  document.head.appendChild(script);
})();
