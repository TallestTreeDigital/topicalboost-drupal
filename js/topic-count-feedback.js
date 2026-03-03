(function ($, Drupal, once) {
  'use strict';

  /**
   * Topic count feedback - shows how many topics will display at current threshold.
   */
  Drupal.behaviors.topicCountFeedback = {
    attach: function (context) {
      let minFrequencyField = $('input[name*="post_topic_minimum_display_count"]', context);

      if (minFrequencyField.length === 0) {
        return;
      }

      $(once('ttd_topics-feedback', minFrequencyField)).each(function () {
        const field = $(this);
        let debounceTimer;

        // Create feedback element after the field
        const feedbackEl = $('<div class="ttd-threshold-feedback"></div>');
        field.closest('.form-item, .js-form-item').append(feedbackEl);

        function updateTopicCount(minFrequency) {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function () {
            feedbackEl.html('<em>Calculating...</em>').removeClass('success warning error').addClass('loading');

            $.ajax({
              url: '/api/topicalboost/topic-count',
              method: 'GET',
              data: { min_frequency: minFrequency },
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              xhrFields: { withCredentials: true },
              success: function (response) {
                const count = response.count;
                const total = response.total;
                const percentage = response.percentage;

                if (count === 0) {
                  feedbackEl.html('No topics will be displayed.').removeClass('loading success').addClass('warning');
                } else {
                  feedbackEl.html(count.toLocaleString() + ' topics (' + percentage + '% of ' + total.toLocaleString() + ') will be displayed.')
                    .removeClass('loading warning')
                    .addClass(percentage < 20 ? 'warning' : 'success');
                }
              },
              error: function () {
                feedbackEl.html('Unable to calculate.').removeClass('loading success warning').addClass('error');
              }
            });
          }, 300);
        }

        field.on('input change', function () {
          updateTopicCount(parseInt($(this).val()) || 1);
        });

        // Initial load
        updateTopicCount(parseInt(field.val()) || 1);
      });
    }
  };

})(jQuery, Drupal, once);
