(function () {
  'use strict';

  var buffer = [];
  var flushTimer = null;
  var MAX_BUFFER = 10;

  function isTopicalBoostError(filename) {
    if (!filename) return false;
    return filename.indexOf('ttd_topics') !== -1 ||
           filename.indexOf('topicalboost') !== -1 ||
           filename.indexOf('ttd-topics') !== -1;
  }

  function bufferError(errorData) {
    if (buffer.length >= MAX_BUFFER) return;
    buffer.push(errorData);
    scheduleFlush();
  }

  function scheduleFlush() {
    if (flushTimer) return;
    flushTimer = setTimeout(flushErrors, 5000);
  }

  function flushErrors() {
    flushTimer = null;
    if (buffer.length === 0) return;

    var errors = buffer.splice(0, buffer.length);
    var endpoint = Drupal.url('api/topicalboost/telemetry/js-errors');

    if (navigator.sendBeacon) {
      var blob = new Blob([JSON.stringify({ errors: errors })], { type: 'application/json' });
      navigator.sendBeacon(endpoint, blob);
    } else {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.setRequestHeader('Content-Type', 'application/json');
      xhr.send(JSON.stringify({ errors: errors }));
    }
  }

  // Capture unhandled errors.
  window.addEventListener('error', function (event) {
    if (!isTopicalBoostError(event.filename)) return;

    bufferError({
      message: event.message || 'Unknown error',
      file: event.filename || '',
      line: event.lineno || 0,
      column: event.colno || 0,
      stack: event.error ? event.error.stack || '' : '',
      user_agent: navigator.userAgent
    });
  });

  // Capture unhandled promise rejections.
  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason || {};
    var stack = reason.stack || '';

    if (!isTopicalBoostError(stack)) return;

    bufferError({
      message: reason.message || String(reason) || 'Unhandled promise rejection',
      file: '',
      line: 0,
      column: 0,
      stack: stack,
      user_agent: navigator.userAgent
    });
  });

  // Flush on page unload.
  window.addEventListener('beforeunload', function () {
    if (buffer.length > 0) {
      flushErrors();
    }
  });
})();
