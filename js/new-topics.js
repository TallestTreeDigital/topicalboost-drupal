(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.ttdNewTopics = {
    attach: function (context) {
      once('ttd-new-topics', '.ttd-new-topics-wrap', context).forEach(function (wrapper) {
        var $wrapper = $(wrapper);
        var $table = $wrapper.find('.ttd-new-topics-table');
        var $tbody = $wrapper.find('#ttd-new-topics-body');
        var $loading = $wrapper.find('.ttd-new-topics-loading');
        var $empty = $wrapper.find('.ttd-new-topics-empty');
        var $buttons = $wrapper.find('.ttd-new-topics-filter-btn');

        function loadTopics(days) {
          $loading.show();
          $table.hide();
          $empty.hide();

          $.ajax({
            url: Drupal.url('api/topicalboost/new-topics'),
            data: { days: days },
            dataType: 'json',
            success: function (data) {
              $loading.hide();
              $tbody.empty();

              if (!data.topics || data.topics.length === 0) {
                $empty.show();
                return;
              }

              $table.show();
              data.topics.forEach(function (topic) {
                var schemaTypes = topic.schema_types || '';
                var hideChecked = topic.is_hidden ? ' checked' : '';
                var forceShowChecked = topic.force_show ? ' checked' : '';

                var row = '<tr>' +
                  '<td><a href="' + Drupal.url('taxonomy/term/' + topic.tid + '/edit') + '">' + Drupal.checkPlain(topic.name) + '</a></td>' +
                  '<td>' + Drupal.checkPlain(topic.created) + '</td>' +
                  '<td>' + topic.post_count + '</td>' +
                  '<td>' + Drupal.checkPlain(schemaTypes) + '</td>' +
                  '<td><input type="checkbox" class="ttd-toggle-hide" data-tid="' + topic.tid + '"' + hideChecked + '></td>' +
                  '<td><input type="checkbox" class="ttd-toggle-force-show" data-tid="' + topic.tid + '"' + forceShowChecked + '></td>' +
                  '</tr>';
                $tbody.append(row);
              });
            },
            error: function () {
              $loading.hide();
              $empty.text('Error loading topics.').show();
            }
          });
        }

        // Filter button clicks.
        $buttons.on('click', function () {
          $buttons.removeClass('is-active');
          $(this).addClass('is-active');
          loadTopics($(this).data('days'));
        });

        // Get CSRF token for POST requests.
        var csrfToken = null;
        $.ajax({
          url: Drupal.url('session/token'),
          async: false,
          dataType: 'text',
          success: function (token) { csrfToken = token; }
        });

        // Toggle visibility.
        $tbody.on('change', '.ttd-toggle-hide', function () {
          var tid = $(this).data('tid');
          $.ajax({
            url: Drupal.url('api/topicalboost/topic/' + tid + '/toggle-visibility'),
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-Token': csrfToken
            }
          });
        });

        // Toggle force show.
        $tbody.on('change', '.ttd-toggle-force-show', function () {
          var tid = $(this).data('tid');
          var forceShow = $(this).is(':checked') ? 1 : 0;
          $.ajax({
            url: Drupal.url('api/topicalboost/topic/' + tid + '/toggle-force-show'),
            method: 'POST',
            data: JSON.stringify({ force_show: forceShow }),
            contentType: 'application/json',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-Token': csrfToken
            }
          });
        });

        // Load default (30 days).
        loadTopics(30);
      });
    }
  };
})(jQuery, Drupal, once);
