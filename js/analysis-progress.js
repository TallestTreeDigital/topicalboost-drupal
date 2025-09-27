(function ($, Drupal) {
  'use strict';

  /**
   * Analysis Progress Enhancement - Nielsen Norman Group UX Principles
   *
   * Enhances the analysis progress display with:
   * - Dynamic status updates
   * - Improved user feedback
   * - Accessibility features
   * - Optional progress polling
   */
  Drupal.behaviors.ttdAnalysisProgress = {
    attach: function (context, settings) {
      $('.ttd-analysis-progress', context).once('ttd-analysis-progress').each(function () {
        var $progressContainer = $(this);
        var $statusText = $progressContainer.find('.ttd-analysis-status');
        var $title = $progressContainer.find('.ttd-analysis-title');

        // Initialize progress enhancement
        initializeProgressDisplay($progressContainer, $statusText, $title);

        // Optional: Add dynamic status messages
        if (settings.ttdTopics && settings.ttdTopics.enableDynamicStatus) {
          startDynamicStatusUpdates($statusText);
        }

        // Add fallback timeout to prevent stuck progress (60 seconds)
        setTimeout(function() {
          if ($progressContainer.is(':visible') && !$progressContainer.hasClass('success') && !$progressContainer.hasClass('error')) {
            // Still showing progress after 60 seconds - likely stuck, refresh page
            showTimeoutMessage($progressContainer);
            setTimeout(function() {
              location.reload();
            }, 3000);
          }
        }, 60000);

        // Optional: Poll for completion status
        if (settings.ttdTopics && settings.ttdTopics.enableStatusPolling) {
          startStatusPolling($progressContainer);
        } else {
          // Default: refresh page after 30 seconds if no polling
          setTimeout(function() {
            if ($progressContainer.is(':visible')) {
              location.reload();
            }
          }, 30000);
        }
      });
    }
  };

  /**
   * Initialize the progress display with enhanced features
   */
  function initializeProgressDisplay($container, $statusText, $title) {
    // Add timestamp for tracking
    var startTime = Date.now();
    $container.data('start-time', startTime);

    // Add elapsed time display (optional)
    if ($container.hasClass('show-elapsed')) {
      startElapsedTimeCounter($container);
    }

    // Add keyboard accessibility
    $container.attr('tabindex', '0');

    // Add focus management for screen readers
    $container.on('focus', function() {
      announceProgress($statusText.text());
    });
  }

  /**
   * Start dynamic status message updates
   */
  function startDynamicStatusUpdates($statusText) {
    var statusMessages = [
      Drupal.t('Analyzing content and extracting topics'),
      Drupal.t('Processing text for semantic analysis'),
      Drupal.t('Identifying key themes and concepts'),
      Drupal.t('Organizing and ranking topics'),
      Drupal.t('Finalizing analysis results')
    ];

    var currentIndex = 0;
    var baseText = statusMessages[0];

    // Update status every 3 seconds
    var statusInterval = setInterval(function() {
      currentIndex = (currentIndex + 1) % statusMessages.length;

      // Get the current dots HTML to preserve it
      var $dots = $statusText.find('.ttd-analysis-dots');
      var dotsHtml = $dots.length ? $dots.prop('outerHTML') : '';

      // Update text with animation
      $statusText.fadeOut(200, function() {
        $(this).html(statusMessages[currentIndex] + dotsHtml).fadeIn(200);
      });
    }, 3000);

    // Store interval for cleanup
    $statusText.data('status-interval', statusInterval);
  }

  /**
   * Start elapsed time counter
   */
  function startElapsedTimeCounter($container) {
    var startTime = $container.data('start-time');

    var timeInterval = setInterval(function() {
      if (!$container.is(':visible')) {
        clearInterval(timeInterval);
        return;
      }

      var elapsed = Math.floor((Date.now() - startTime) / 1000);
      var minutes = Math.floor(elapsed / 60);
      var seconds = elapsed % 60;

      var timeString = minutes > 0
        ? Drupal.formatPlural(minutes, '1 minute', '@count minutes') + ' ' +
          Drupal.formatPlural(seconds, '1 second', '@count seconds')
        : Drupal.formatPlural(seconds, '1 second', '@count seconds');

      var $timeDisplay = $container.find('.ttd-elapsed-time');
      if ($timeDisplay.length === 0) {
        $container.find('.ttd-analysis-text').append(
          '<div class="ttd-elapsed-time" style="font-size: 12px; color: #64748b; margin-top: 4px;">' +
          Drupal.t('Elapsed: @time', {'@time': timeString}) + '</div>'
        );
      } else {
        $timeDisplay.text(Drupal.t('Elapsed: @time', {'@time': timeString}));
      }
    }, 1000);

    $container.data('time-interval', timeInterval);
  }

  /**
   * Poll for analysis completion status
   */
  function startStatusPolling($container) {
    var nodeId = drupalSettings.ttdTopics && drupalSettings.ttdTopics.nodeId;
    if (!nodeId) {
      return;
    }

    var pollInterval = setInterval(function() {
      // Check if container is still visible
      if (!$container.is(':visible')) {
        clearInterval(pollInterval);
        return;
      }

      // Make AJAX request to check status
      $.ajax({
        url: '/ttd-topics/check-analysis-status/' + nodeId,
        method: 'GET',
        timeout: 5000,
        success: function(response) {
          if (response.completed) {
            showCompletionState($container);
            clearInterval(pollInterval);

            // Optionally refresh the page after a delay
            setTimeout(function() {
              if (response.reload) {
                location.reload();
              }
            }, 2000);
          } else if (response.error) {
            showErrorState($container, response.message);
            clearInterval(pollInterval);
          }
        },
        error: function() {
          // Silently continue polling on error
          // Don't show error unless it's persistent
        }
      });
    }, 5000); // Poll every 5 seconds

    $container.data('poll-interval', pollInterval);
  }

  /**
   * Show completion state
   */
  function showCompletionState($container) {
    $container.removeClass('pulsing').addClass('success');

    var $title = $container.find('.ttd-analysis-title');
    var $status = $container.find('.ttd-analysis-status');

    $title.text(Drupal.t('Analysis Complete!'));
    $status.html(Drupal.t('Topics have been successfully extracted and are ready for review.'));

    // Add completion animation
    $container.animate({
      scale: 1.02
    }, 200).animate({
      scale: 1
    }, 200);

    announceProgress(Drupal.t('Analysis completed successfully'));
  }

  /**
   * Show error state
   */
  function showErrorState($container, message) {
    $container.removeClass('pulsing').addClass('error');

    var $title = $container.find('.ttd-analysis-title');
    var $status = $container.find('.ttd-analysis-status');

    $title.text(Drupal.t('Analysis Error'));
    $status.html(message || Drupal.t('An error occurred during analysis. Please try again.'));

    announceProgress(Drupal.t('Analysis encountered an error'));
  }

  /**
   * Show timeout message for stuck progress
   */
  function showTimeoutMessage($container) {
    var $title = $container.find('.ttd-analysis-title');
    var $status = $container.find('.ttd-analysis-status');

    $title.text(Drupal.t('Analysis Taking Longer Than Expected'));
    $status.html(Drupal.t('Refreshing page to check completion status...'));

    announceProgress(Drupal.t('Analysis is taking longer than expected, refreshing page'));
  }

  /**
   * Announce progress for screen readers
   */
  function announceProgress(message) {
    // Create temporary element for screen reader announcement
    var $announcement = $('<div class="sr-only" aria-live="polite"></div>');
    $announcement.text(message);
    $('body').append($announcement);

    // Remove after announcement
    setTimeout(function() {
      $announcement.remove();
    }, 1000);
  }

  /**
   * Cleanup function for when analysis is complete or page unloads
   */
  function cleanupProgressTimers($container) {
    var statusInterval = $container.find('.ttd-analysis-status').data('status-interval');
    var timeInterval = $container.data('time-interval');
    var pollInterval = $container.data('poll-interval');

    if (statusInterval) clearInterval(statusInterval);
    if (timeInterval) clearInterval(timeInterval);
    if (pollInterval) clearInterval(pollInterval);
  }

  // Cleanup on page unload
  $(window).on('beforeunload', function() {
    $('.ttd-analysis-progress').each(function() {
      cleanupProgressTimers($(this));
    });
  });

  // Enhanced progress display for bulk analysis
  Drupal.behaviors.ttdBulkAnalysisProgress = {
    attach: function (context, settings) {
      $('.bulk-analysis-progress', context).once('bulk-analysis-progress').each(function() {
        var $progressContainer = $(this);

        // Add progress bar functionality for bulk operations
        if ($progressContainer.find('.ttd-progress-bar-container').length) {
          initializeBulkProgressBar($progressContainer);
        }
      });
    }
  };

  /**
   * Initialize bulk analysis progress bar
   */
  function initializeBulkProgressBar($container) {
    var $progressBar = $container.find('.ttd-progress-bar');
    var totalItems = drupalSettings.ttdBulkAnalysis && drupalSettings.ttdBulkAnalysis.totalItems || 1;
    var processedItems = 0;

    // Simulate progress updates (replace with real data from server)
    var progressInterval = setInterval(function() {
      processedItems++;
      var percentage = Math.min((processedItems / totalItems) * 100, 100);

      $progressBar.css('width', percentage + '%');

      var $statusText = $container.find('.ttd-analysis-status');
      $statusText.html(Drupal.t('Processing @current of @total items', {
        '@current': processedItems,
        '@total': totalItems
      }));

      if (percentage >= 100) {
        clearInterval(progressInterval);
        showCompletionState($container);
      }
    }, 1000);
  }

})(jQuery, Drupal);