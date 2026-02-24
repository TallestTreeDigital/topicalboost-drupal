(function () {
  'use strict';

  var widget = document.getElementById('citations-widget');
  if (!widget) return;

  var apiKey = widget.getAttribute('data-api-key');
  var apiEndpoint = widget.getAttribute('data-api-endpoint');
  var limit = widget.getAttribute('data-limit') || 20;

  if (!apiKey || !apiEndpoint) {
    widget.innerHTML = '<p>Citations widget requires an API key.</p>';
    return;
  }

  // Load the external widget script.
  var script = document.createElement('script');
  script.src = apiEndpoint + '/widgets/citations.js';
  script.setAttribute('data-api-key', apiKey);
  script.setAttribute('data-target', 'citations-widget');
  script.setAttribute('data-limit', limit);
  script.async = true;
  document.head.appendChild(script);
})();
