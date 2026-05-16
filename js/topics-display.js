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
        let hiddenCount = $mentions.filter('.hidden, .ttd-topics-display-hidden').length;
        const serverControlledVisibility = hiddenCount > 0;

        // Keep server-selected high-salience topics visible and only fall back to
        // numeric hiding for older markup that did not pre-mark hidden links.
        $mentions.each(function (index) {
          const $mention = $(this);
          const shouldHide = serverControlledVisibility
            ? $mention.hasClass('hidden') || $mention.hasClass('ttd-topics-display-hidden')
            : index >= maxVisible;

          $mention.data('ttdInitiallyHidden', shouldHide);

          if (shouldHide) {
            $mention.addClass('hidden ttd-topics-display-hidden');
          }
        });

        hiddenCount = $mentions.filter('.hidden, .ttd-topics-display-hidden').length;

        if (hiddenCount === 0) {
          $button.hide();
          return;
        }

        // Handle toggle click - use stopPropagation to prevent conflicts with
        // event delegation handlers (like BeyondWords) without breaking them
        $button.on('click.topicsDisplay', function (e) {
          e.preventDefault();
          e.stopPropagation(); // CRITICAL: Stops event from bubbling to parent delegated handlers
          e.stopImmediatePropagation(); // Extra protection for handlers on this element

          // Return early if already processing to prevent race conditions
          if ($button.data('processing')) {
            return false;
          }
          $button.data('processing', true);

          const buttonText = $button.text().toLowerCase();

          if (buttonText.includes('more')) {
            // Show all mentions
            $mentionsContainer.find('.mention-tag.hidden, .mention-tag.ttd-topics-display-hidden').removeClass('hidden ttd-topics-display-hidden');
            $button.text('Less');
          } else {
            // Restore the initial hidden set.
            $mentions.each(function (index) {
              const $mention = $(this);
              if ($mention.data('ttdInitiallyHidden')) {
                $mention.addClass('hidden ttd-topics-display-hidden');
              }
            });
            $button.text('More');
          }

          // Reset processing flag
          $button.data('processing', false);
          return false; // Extra safety to prevent default
        });

        $button.on('keydown.topicsDisplay', function (e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            $button.trigger('click.topicsDisplay');
          }
        });
      });
    }
  };

})(jQuery, Drupal);
