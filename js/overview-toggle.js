(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    var link = e.target.closest('.ttd-toggle-visibility');
    if (!link) return;

    e.preventDefault();
    var url = link.getAttribute('href');
    var token = drupalSettings.topicalboostCsrfToken || '';

    fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': token,
      },
    })
    .then(function (response) { return response.json(); })
    .then(function () {
      window.location.reload();
    })
    .catch(function (err) {
      console.error('Toggle visibility failed:', err);
    });
  });
})();
