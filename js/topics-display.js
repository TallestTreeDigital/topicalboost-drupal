(function ($, Drupal) {
  'use strict';

  /**
   * Topics display behavior for show more/less functionality
   */
  Drupal.behaviors.topicsDisplay = {
    attach: function (context, settings) {
      // Handle old topics display format
      $('.toggle-topics-btn', context).once('topics-toggle').each(function () {
        const $button = $(this);
        const $topicsContainer = $button.siblings('.topic-items');
        const maxVisible = parseInt($topicsContainer.attr('data-max-visible')) || 10;
        const $topics = $topicsContainer.find('.field--item');

        // Initialize state
        $topics.each(function (index) {
          if (index >= maxVisible) {
            $(this).addClass('hidden');
          }
        });

        // Hide button if not needed
        if ($topics.length <= maxVisible) {
          $button.hide();
          return;
        }

        // Handle toggle click
        $button.on('click', function (e) {
          e.preventDefault();

          if ($button.text() === 'Show more') {
            // Show all topics
            $topicsContainer.find('.field--item.hidden').removeClass('hidden');
            $button.text('Show less');
          } else {
            // Hide extra topics
            $topics.each(function (index) {
              if (index >= maxVisible) {
                $(this).addClass('hidden');
              }
            });
            $button.text('Show more');
          }
        });
      });

      // Handle new inline mentions display format
      $('.toggle-mentions-btn', context).once('mentions-toggle').each(function () {
        const $button = $(this);
        const $mentionsContainer = $button.parent('.mentions-list');
        const maxVisible = parseInt($mentionsContainer.attr('data-max-visible')) || 10;
        const $mentions = $mentionsContainer.find('.mention-tag');
        const hiddenCount = $mentions.filter('.hidden').length;

        // Initialize state - ensure proper hiding on page load
        $mentions.each(function (index) {
          if (index >= maxVisible) {
            $(this).addClass('hidden');
          }
        });

        // Hide button if not needed
        if ($mentions.length <= maxVisible) {
          $button.hide();
          return;
        }

        // Handle toggle click
        $button.on('click', function (e) {
          e.preventDefault();

          const buttonText = $button.text();
          
          if (buttonText.includes('more')) {
            // Show all mentions
            $mentionsContainer.find('.mention-tag.hidden').removeClass('hidden');
            $button.text('Show less');
          } else {
            // Hide extra mentions
            $mentions.each(function (index) {
              if (index >= maxVisible) {
                $(this).addClass('hidden');
              }
            });
            const newHiddenCount = $mentions.filter('.hidden').length;
            $button.text('+' + newHiddenCount + ' more');
          }
        });
      });
    }
  };

})(jQuery, Drupal);
