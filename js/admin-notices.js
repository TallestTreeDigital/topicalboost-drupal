(function (Drupal, once) {
  'use strict';

  const storagePrefix = 'ttd_notice_dismissed_';

  function isDismissed(id) {
    try {
      return window.localStorage.getItem(storagePrefix + id) === '1';
    }
    catch (e) {
      return false;
    }
  }

  function dismiss(id) {
    try {
      window.localStorage.setItem(storagePrefix + id, '1');
    }
    catch (e) {
      // Ignore storage failures; the current notice can still be hidden.
    }
  }

  Drupal.behaviors.ttdTopicsAdminNotices = {
    attach: function (context) {
      once('ttd-topics-admin-notice', '[data-ttd-notice-id]', context).forEach(function (notice) {
        const id = notice.getAttribute('data-ttd-notice-id');
        if (id && isDismissed(id)) {
          notice.hidden = true;
        }
      });

      once('ttd-topics-admin-notice-dismiss', '[data-ttd-notice-dismiss]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          const notice = button.closest('[data-ttd-notice-id]');
          if (!notice) {
            return;
          }
          const id = notice.getAttribute('data-ttd-notice-id');
          if (id) {
            dismiss(id);
          }
          notice.hidden = true;
        });
      });
    }
  };
})(Drupal, once);
