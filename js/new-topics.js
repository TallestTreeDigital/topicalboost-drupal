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
        var $pagination = $wrapper.find('.ttd-new-topics-pagination');
        var currentDays = 30;
        var currentPage = 1;
        var perPage = 25;

        function escapeHtml(value) {
          return Drupal.checkPlain(value === null || value === undefined ? '' : String(value));
        }

        function renderPagination(pagination) {
          $pagination.empty();

          if (!pagination || pagination.total_pages <= 1) {
            $pagination.hide();
            return;
          }

          var html = '<span class="ttd-pagination-info">Page ' +
            pagination.current_page + ' of ' + pagination.total_pages +
            ' (' + pagination.total + ' topics)</span> ';

          if (pagination.current_page > 1) {
            html += '<button type="button" class="button ttd-new-topics-page" data-page="' + (pagination.current_page - 1) + '">Previous</button> ';
          }
          if (pagination.current_page < pagination.total_pages) {
            html += '<button type="button" class="button ttd-new-topics-page" data-page="' + (pagination.current_page + 1) + '">Next</button>';
          }

          $pagination.html(html).show();
        }

        function loadTopics() {
          $loading.show();
          $table.hide();
          $empty.hide();
          $pagination.hide();

          $.ajax({
            url: Drupal.url('api/topicalboost/new-topics'),
            data: {
              days: currentDays,
              page: currentPage,
              per_page: perPage
            },
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
                var schemaTypes = Array.isArray(topic.schema_types) && topic.schema_types.length
                  ? topic.schema_types.join(', ')
                  : '-';
                var hideClass = topic.is_hidden ? ' active' : '';
                var forceShowClass = topic.force_show ? ' active' : '';
                var disabled = topic.tid ? '' : ' disabled';
                var editLink = topic.tid
                  ? '<a href="' + Drupal.url('taxonomy/term/' + topic.tid + '/edit') + '">' + escapeHtml(topic.name) + '</a>'
                  : escapeHtml(topic.name);

                var row = '<tr>' +
                  '<td>' + editLink + '</td>' +
                  '<td>' + escapeHtml(topic.created) + '</td>' +
                  '<td>' + topic.post_count + '</td>' +
                  '<td>' + escapeHtml(schemaTypes) + '</td>' +
                  '<td><button type="button" class="button ttd-toggle-hide' + hideClass + '" data-tid="' + topic.tid + '" data-current="' + (topic.is_hidden ? '1' : '0') + '"' + disabled + '>' + (topic.is_hidden ? 'Hidden' : 'Visible') + '</button></td>' +
                  '<td><button type="button" class="button ttd-toggle-force-show' + forceShowClass + '" data-tid="' + topic.tid + '" data-current="' + (topic.force_show ? '1' : '0') + '"' + disabled + '>' + (topic.force_show ? 'Forced' : 'Normal') + '</button></td>' +
                  '</tr>';
                $tbody.append(row);
              });
              renderPagination(data.pagination);
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
          currentDays = $(this).data('days');
          currentPage = 1;
          loadTopics();
        });

        $pagination.on('click', '.ttd-new-topics-page', function () {
          currentPage = $(this).data('page');
          loadTopics();
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
        $tbody.on('click', '.ttd-toggle-hide', function () {
          var $button = $(this);
          var tid = $button.data('tid');
          var isHidden = $button.data('current') === 1 || $button.data('current') === '1';
          $button.prop('disabled', true);
          $.ajax({
            url: Drupal.url('api/topicalboost/topic/' + tid + '/toggle-visibility'),
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-Token': csrfToken
            },
            success: function (data) {
              if (data.success) {
                var hidden = !isHidden;
                $button.data('current', hidden ? '1' : '0');
                $button.text(hidden ? 'Hidden' : 'Visible');
                $button.toggleClass('active', hidden);
              }
            },
            complete: function () {
              $button.prop('disabled', false);
            }
          });
        });

        // Toggle force show.
        $tbody.on('click', '.ttd-toggle-force-show', function () {
          var $button = $(this);
          var tid = $button.data('tid');
          var currentVal = $button.data('current') === 1 || $button.data('current') === '1';
          var forceShow = currentVal ? 0 : 1;
          $button.prop('disabled', true);
          $.ajax({
            url: Drupal.url('api/topicalboost/topic/' + tid + '/toggle-force-show'),
            method: 'POST',
            data: JSON.stringify({ force_show: forceShow }),
            contentType: 'application/json',
            headers: {
              'X-Requested-With': 'XMLHttpRequest',
              'X-CSRF-Token': csrfToken
            },
            success: function (data) {
              if (data.success) {
                var forced = !!data.force_show;
                $button.data('current', forced ? '1' : '0');
                $button.text(forced ? 'Forced' : 'Normal');
                $button.toggleClass('active', forced);
              }
            },
            complete: function () {
              $button.prop('disabled', false);
            }
          });
        });

        // Load default (30 days).
        loadTopics();
      });
    }
  };
})(jQuery, Drupal, once);
