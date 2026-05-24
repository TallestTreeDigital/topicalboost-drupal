(function (Drupal, $, once) {
  'use strict';

  Drupal.behaviors.ttdAuthorManager = {
    attach: function (context, settings) {
      once('ttd-author-manager-toggle', '#edit-author-manager-enabled', context).forEach(function (checkbox) {
        var $checkbox = $(checkbox);
        var $settings = $('#ttd-author-manager-settings');
        function updateVisibility() {
          $settings.toggle($checkbox.is(':checked'));
        }
        $checkbox.on('change', updateVisibility);
        updateVisibility();
      });

      once('ttd-author-field-mapping', '#ttd-author-field-name', context).forEach(function (select) {
        var $select = $(select);
        var endpoint = settings.ttd_topics && settings.ttd_topics.authorFieldMappingUrl;
        if (!endpoint) {
          return;
        }

        $select.on('change', function () {
          var fieldName = $select.val();
          var $mapping = $('#ttd-author-field-mapping');
          if (!fieldName) {
            $mapping.empty().hide();
            return;
          }

          fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ field_name: fieldName })
          })
            .then(function (response) {
              return response.json();
            })
            .then(function (json) {
              if (json.success) {
                $mapping.replaceWith(json.html);
              }
            });
        });
      });
    }
  };

})(Drupal, jQuery, once);
