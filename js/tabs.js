(function (Drupal, $) {
  'use strict';

  Drupal.behaviors.ttdTopicsTabs = {
    attach: function (context, settings) {

      // Function to activate a tab
      function activateTab(tabId) {
        // Remove active class from all buttons and panels
        $('.ttd-topics-tab-button').removeClass('active');
        $('.ttd-topics-tab-panel').removeClass('active');

        // Add active class to corresponding button and panel
        var $activeButton = $('.ttd-topics-tab-button[data-tab="' + tabId + '"]');
        $activeButton.addClass('active');
        $('#' + tabId).addClass('active');

        // Show/hide submit button based on whether current tab has settings
        var hasSettings = $activeButton.attr('data-has-settings') === 'true';
        var $form = $('.ttd-topics-tabs-container').closest('form');

        Drupal.ttd_topics.debug.log('Tab activated:', tabId, 'Has settings:', hasSettings, 'Form found:', $form.length);

        if (hasSettings) {
          $form.removeClass('ttd-topics-hide-submit');
          // Also directly show the submit button as fallback
          $form.find('.form-actions input[type="submit"]').show();
          Drupal.ttd_topics.debug.log('Showing submit button');
        } else {
          $form.addClass('ttd-topics-hide-submit');
          // Also directly hide the submit button as fallback
          $form.find('.form-actions input[type="submit"]').hide();
          Drupal.ttd_topics.debug.log('Hiding submit button - class added to form and direct hide applied');
        }

        // Save active tab to localStorage
        try {
          localStorage.setItem('ttd-topics-active-tab', tabId);
        } catch (e) {
          // localStorage not available, ignore
        }
      }

      // Function to get tab ID from hash or localStorage
      function getTabFromHash() {
        var hash = window.location.hash.substring(1); // Remove #
        var tabMap = {
          'analytics': 'tab-analytics',
          'settings': 'tab-settings',
          'api': 'tab-api',
          'schema': 'tab-schema'
        };

        // If there's a hash, use it
        if (hash && tabMap[hash]) {
          return tabMap[hash];
        }

        // If no hash, check localStorage for last active tab
        try {
          var savedTab = localStorage.getItem('ttd-topics-active-tab');
          if (savedTab && $('#' + savedTab).length > 0) {
            return savedTab;
          }
        } catch (e) {
          // localStorage not available, ignore
        }

        // Default to analytics
        return 'tab-analytics';
      }

      // Set initial tab based on URL hash
      $(document).ready(function () {
        var initialTab = getTabFromHash();
        activateTab(initialTab);
      });

      // Handle tab button clicks
      $('.ttd-topics-tab-button', context).once('ttd-topics-tabs').on('click', function (e) {
        var targetPanel = $(this).data('tab');
        activateTab(targetPanel);

        // Update URL hash
        var hash = $(this).attr('href');
        if (hash && hash !== '#') {
          // Use an object for the state parameter and an empty string for the title to
          // avoid the aggregator erroneously converting `null` to `NULL`, which causes
          // a ReferenceError in the compiled asset.
          window.history.pushState({ tab: targetPanel }, '', hash);
        }
      });

      // Handle browser back/forward navigation
      $(window).on('hashchange', function () {
        var tabId = getTabFromHash();
        activateTab(tabId);
      });

      // Handle form submission to preserve current tab
      $('form', context).once('ttd-topics-form-submit').on('submit', function () {
        var $activeTab = $('.ttd-topics-tab-panel.active');
        if ($activeTab.length > 0) {
          try {
            localStorage.setItem('ttd-topics-active-tab', $activeTab.attr('id'));
          } catch (e) {
            // localStorage not available, ignore
          }
        }
      });

      // Initialize Select2 for content types multi-select with a slight delay
      setTimeout(function () {
        $('.ttd-topics-select2', context).once('select2-init').each(function () {
          // Check if select2 is available
          if (typeof $(this).select2 === 'function') {
            $(this).select2({
              placeholder: 'Select content types...',
              width: '100%',
              allowClear: false,
              closeOnSelect: false,
              theme: 'default'
            });
          } else {
            Drupal.ttd_topics.debug.error('Select2 is not loaded. Please check library configuration.');
            Drupal.ttd_topics.debug.log('Available jQuery methods:', Object.getOwnPropertyNames($.fn).filter(function (p) {
              return p.indexOf('select') !== -1;
            }));
          }
        });

        // Convert checkboxes to toggle switches
        $('.ttd-topics-toggle-field input[type="checkbox"]', context).once('toggle-init').each(function () {
          var $checkbox = $(this);
          var $label = $('label[for="' + $checkbox.attr('id') + '"]');

          // Create toggle switch HTML
          var toggleHtml = '<div class="ttd-topics-toggle"><span class="ttd-topics-toggle-slider"></span></div>';

          // Hide the original checkbox
          $checkbox.hide();

          // Insert toggle at the beginning of the label text
          if ($label.length > 0) {
            $label.prepend(toggleHtml);
          } else {
            // Fallback - create our own label structure
            var labelText = $checkbox.attr('title') || 'Toggle';
            var $newLabel = $('<label for="' + $checkbox.attr('id') + '">' + toggleHtml + labelText + '</label>');
            $checkbox.after($newLabel);
            $label = $newLabel;
          }

          var $toggle = $label.find('.ttd-topics-toggle');
          var $slider = $toggle.find('.ttd-topics-toggle-slider');

          // Function to update toggle appearance
          function updateToggleState() {
            if ($checkbox.is(':checked')) {
              $slider.addClass('active');
            } else {
              $slider.removeClass('active');
            }
          }

          // Set initial state
          updateToggleState();

          // Make the toggle clickable
          $toggle.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
            updateToggleState();
          });

          // Make sure label clicks work but don't conflict with toggle
          $label.on('click', function (e) {
            if (!$(e.target).closest('.ttd-topics-toggle').length) {
              $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
              updateToggleState();
            }
          });

          // Update when checkbox changes from other sources
          $checkbox.on('change', function () {
            updateToggleState();
          });
        });
      }, 100);
    }
  };

})(Drupal, jQuery);
