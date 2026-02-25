(function (Drupal, $) {
  'use strict';

  Drupal.behaviors.ttdTopicsTabs = {
    attach: function (context, settings) {

      // Function to activate a tab
      function activateTab(tabId) {
        // Remove active class from all nav items and panels
        $('.ttd-nav-item').removeClass('active');
        $('.ttd-settings-panel').removeClass('active');

        // Add active class to corresponding nav item and panel
        var $activeItem = $('.ttd-nav-item[data-tab="' + tabId + '"]');
        $activeItem.addClass('active');
        $('#' + tabId).addClass('active');

        // Show/hide submit button based on whether current tab has settings
        var hasSettings = $activeItem.attr('data-has-settings') !== 'false';
        var $form = $('.ttd-settings-layout').closest('form');
        var $actions = $form.find('.form-actions');

        Drupal.ttd_topics.debug.log('Tab activated:', tabId, 'Has settings:', hasSettings);

        if (hasSettings) {
          $actions.show();
        } else {
          $actions.hide();
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
          'setup': 'tab-setup',
          'content': 'tab-content',
          'topiclist': 'tab-topiclist',
          'behavior': 'tab-behavior',
          'widgets': 'tab-widgets',
          'schema': 'tab-schema',
          'developer': 'tab-developer',
          'analytics': 'tab-analytics',
          'bulk-analysis': 'tab-bulk-analysis'
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

        // Default to setup
        return 'tab-setup';
      }

      // Move submit button into page header (one-time DOM move)
      $(document).ready(function () {
        var $form = $('.ttd-settings-wrap');
        var $header = $form.find('.ttd-page-header');
        var $actions = $form.find('.form-actions');
        if ($header.length && $actions.length && !$header.find('.form-actions').length) {
          $actions.appendTo($header);
        }

        var initialTab = getTabFromHash();
        activateTab(initialTab);
      });

      // Handle nav item clicks
      $('.ttd-nav-item', context).once('ttd-topics-tabs').on('click', function (e) {
        e.preventDefault();
        var targetPanel = $(this).data('tab');
        activateTab(targetPanel);

        // Update URL hash
        var hashMap = {
          'tab-setup': '#setup',
          'tab-content': '#content',
          'tab-topiclist': '#topiclist',
          'tab-behavior': '#behavior',
          'tab-widgets': '#widgets',
          'tab-schema': '#schema',
          'tab-developer': '#developer',
          'tab-analytics': '#analytics',
          'tab-bulk-analysis': '#bulk-analysis'
        };
        var hash = hashMap[targetPanel];
        if (hash) {
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
        var $activeTab = $('.ttd-settings-panel.active');
        if ($activeTab.length > 0) {
          try {
            localStorage.setItem('ttd-topics-active-tab', $activeTab.attr('id'));
          } catch (e) {
            // localStorage not available, ignore
          }
        }
      });

      // Initialize Select2 for multi-select fields with a slight delay
      setTimeout(function () {
        $('.ttd-topics-select2', context).once('select2-init').each(function () {
          if (typeof $(this).select2 === 'function') {
            $(this).select2({
              placeholder: $(this).data('placeholder') || 'Select...',
              width: '100%',
              allowClear: false,
              closeOnSelect: false,
              theme: 'default'
            });
          } else {
            Drupal.ttd_topics.debug.error('Select2 is not loaded. Please check library configuration.');
          }
        });

        // Convert checkboxes to toggle switches
        $('.ttd-topics-toggle-field input[type="checkbox"]', context).once('toggle-init').each(function () {
          var $checkbox = $(this);
          var $label = $('label[for="' + $checkbox.attr('id') + '"]');

          // Create toggle switch HTML matching WP structure
          var toggleHtml = '<span class="ttd-toggle-switch"><span class="ttd-toggle-slider"></span></span>';

          // Hide the original checkbox
          $checkbox.hide();

          // Insert toggle at the beginning of the label text
          if ($label.length > 0) {
            $label.prepend(toggleHtml);
          } else {
            var labelText = $checkbox.attr('title') || 'Toggle';
            var $newLabel = $('<label for="' + $checkbox.attr('id') + '">' + toggleHtml + labelText + '</label>');
            $checkbox.after($newLabel);
            $label = $newLabel;
          }

          var $toggle = $label.find('.ttd-toggle-switch');
          var $slider = $toggle.find('.ttd-toggle-slider');

          function updateToggleState() {
            if ($checkbox.is(':checked')) {
              $slider.addClass('active');
            } else {
              $slider.removeClass('active');
            }
          }

          updateToggleState();

          $toggle.on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
            updateToggleState();
          });

          $label.on('click', function (e) {
            if (!$(e.target).closest('.ttd-toggle-switch').length) {
              $checkbox.prop('checked', !$checkbox.prop('checked')).trigger('change');
              updateToggleState();
            }
          });

          $checkbox.on('change', function () {
            updateToggleState();
          });
        });
      }, 100);
    }
  };

})(Drupal, jQuery);
