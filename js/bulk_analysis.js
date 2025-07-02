/**
 * Enhanced Bulk Analysis JavaScript - Bulk API Implementation
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  let analysisInProgress = false;
  let pollInterval = null;
  let updateCountTimeout = null;
  let currentRequestId = null;
  let currentStage = null; // 'sending', 'analyzing', 'applying'
  let currentFilters = {
    startDate: null,
    endDate: null,
    contentTypes: [],
    reanalyze: false,
    includeDrafts: false,
    onlyTopicless: false
  };

  Drupal.behaviors.ttdBulkAnalysis = {
    attach: function (context, settings) {
      const $form = $('#ttd-bulk-analysis-form', context);
      if ($form.length === 0) { return;
      }

      // Prevent multiple initializations
      if ($form.hasClass('ttd-initialized')) { return;
      }
      $form.addClass('ttd-initialized');

      // Check if we have the required settings
      if (!drupalSettings.ttd_topics || !drupalSettings.ttd_topics.bulk_analysis_endpoints) {
        Drupal.ttd_topics.debug.error('TtdTopics bulk analysis endpoints not found in drupalSettings');
        showMessage('Configuration error: Missing bulk analysis endpoints', 'error');
        return;
      }

      // Check if this is an empty state form (no content types enabled)
      if ($('.ttd_topics-empty-state', context).length > 0) {
        Drupal.ttd_topics.debug.log('Empty state detected - no content types enabled');
        return;
      }

      Drupal.ttd_topics.debug.log('TTD Bulk Analysis initialized', drupalSettings.ttd_topics.bulk_analysis_endpoints);

      // Check for existing analysis on page load
      checkExistingAnalysis();

      // Set default date range to "Last Month" first
      setDefaultDateRange();

      // Initialize components
      initializeDateRangeButtons();
      initializeContentTypeCards();
      initializeFormInteractions();
      initializeButtons();

      // Set initial UI state - show loading status
      if (!analysisInProgress) {
        $('#ttd-bulk-analysis-progress').hide();
        $('#ttd-selection-status').hide(); // Start with selection status hidden
        showMessage('Loading content selection...', 'info');

        // Load initial selection count with delay to ensure form is ready and filters are set
        setTimeout(function () {
          updateCurrentFilters();
          // Only update selection count if we have content types enabled
          if (currentFilters.contentTypes.length > 0) {
            updateSelectionCount();
          } else {
            $('#ttd-selection-status').show();
            $('#ttd-selection-status .ttd-selection-count').text('0');
            showMessage('Please select at least one content type to analyze', 'warning');
            $('#ttd-bulk-analysis-analyze-button').prop('disabled', true);
          }
        }, 1000);
      }

      // Add helpful tooltips and guidance
      addUserGuidance();
    }
  };

  /**
   * Check for existing analysis on page load
   */
  function checkExistingAnalysis() {
    const endpoints = drupalSettings.ttd_topics.bulk_analysis_endpoints;

    $.ajax({
      url: endpoints.poll,
      method: 'GET',
      headers: {
        'X-CSRF-Token': drupalSettings.ttd_topics.nonce
      }
    })
    .done(function (response) {
      if (response.success && response.data.request_id) {
        currentRequestId = response.data.request_id;
        analysisInProgress = true;

        // Determine current stage and continue polling
        const contentCount = response.data.content_count || 0;
        if (response.data.batch_progress.completed < response.data.batch_progress.total) {
          currentStage = 'sending';
          const contentProcessed = Math.min(response.data.batch_progress.completed * 50, contentCount);
          showMessage('Step 1/3: Preparing content for analysis (' + formatNumber(contentProcessed) + ' of ' + formatNumber(contentCount) + ' items)', 'progress');
        } else if (response.data.analysis_status && !response.data.analysis_status.ready) {
          currentStage = 'analyzing';
          const percent = response.data.analysis_status.percent || 0;
          const analyzed = response.data.analysis_status.analyzed || 0;
          const totalContent = response.data.analysis_status.content_count || contentCount;
          showMessage('Step 2/3: Analyzing content • ' + percent + '% complete (' + formatNumber(analyzed) + ' of ' + formatNumber(totalContent) + ' items)', 'progress');
        } else if (response.data.apply_progress) {
          currentStage = 'applying';
          updateApplyProgress(response.data.apply_progress, contentCount);
        }

        updateUIForAnalysis(true);
        startProgressPolling();
      } else {
        // No existing analysis - let main initialization handle initial state
      }
    })
    .fail(function () {
      // Request failed - let main initialization handle initial state  
    });
  }

  /**
   * Initialize date range button interactions
   */
  function initializeDateRangeButtons() {
    $('.ttd-date-range-btn').once('ttd-date-range-btn').on('click', function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $button = $(this);
      const days = $button.data('days');

      // Update active state
      $('.ttd-date-range-btn').removeClass('active');
      $button.addClass('active');

      // Set date values
      if (days === 'all') {
        currentFilters.startDate = null;
        currentFilters.endDate = null;
        $('#ttd-bulk-analysis-start-date').val('');
        $('#ttd-bulk-analysis-end-date').val('');
      } else {
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(endDate.getDate() - parseInt(days));

        currentFilters.startDate = startDate.toISOString().split('T')[0];
        currentFilters.endDate = endDate.toISOString().split('T')[0];

        $('#ttd-bulk-analysis-start-date').val(currentFilters.startDate);
        $('#ttd-bulk-analysis-end-date').val(currentFilters.endDate);
      }

      updateSelectionCount();
    });

    // Handle custom date changes
    $('#ttd-bulk-analysis-start-date, #ttd-bulk-analysis-end-date').once('ttd-date-inputs').on('change', function () {
      $('.ttd-date-range-btn').removeClass('active');

      currentFilters.startDate = $('#ttd-bulk-analysis-start-date').val();
      currentFilters.endDate = $('#ttd-bulk-analysis-end-date').val();

      // Validate date range
      if (currentFilters.startDate && currentFilters.endDate) {
        const start = new Date(currentFilters.startDate);
        const end = new Date(currentFilters.endDate);

        if (start > end) {
          showMessage('Start date cannot be after end date', 'error', 4000);
          return;
        }
      }

      updateSelectionCount();
    });
  }

  /**
   * Initialize content type card interactions
   */
  function initializeContentTypeCards() {
    // Get initially enabled content types
    $('.ttd-content-type-card.ttd-enabled').each(function () {
      const contentType = $(this).data('content-type');
      currentFilters.contentTypes.push(contentType);
    });

    $('.ttd-content-type-card').once('ttd-content-cards').on('click', function () {
      const $card = $(this);
      const contentType = $card.data('content-type');
      const $input = $card.find('.ttd-content-type-input');

      // Toggle state with animation
      $card.toggleClass('ttd-enabled');

      // Update filters
      if ($card.hasClass('ttd-enabled')) {
        if (!currentFilters.contentTypes.includes(contentType)) {
          currentFilters.contentTypes.push(contentType);
        }
        $input.prop('disabled', false);

        // Add visual feedback
        $card.css('transform', 'scale(1.05)');
        setTimeout(() => $card.css('transform', ''), 150);
      } else {
        currentFilters.contentTypes = currentFilters.contentTypes.filter(type => type !== contentType);
        $input.prop('disabled', true);
      }

      // Update count
      updateSelectionCount();
    });

    // Add keyboard navigation
    $('.ttd-content-type-card').once('ttd-content-keyboard').attr('tabindex', '0').on('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        $(this).click();
      }
    });
  }

  /**
   * Initialize form interactions
   */
  function initializeFormInteractions() {
    // Handle checkbox changes
    $('#ttd-bulk-analysis-reanalyze').once('ttd-reanalyze-cb').on('change', function () {
      currentFilters.reanalyze = $(this).is(':checked');
      updateSelectionCount();
    });

    $('#ttd-bulk-analysis-include-drafts').once('ttd-drafts-cb').on('change', function () {
      currentFilters.includeDrafts = $(this).is(':checked');
      updateSelectionCount();
    });

    $('#ttd-bulk-analysis-only-topicless').once('ttd-topicless-cb').on('change', function () {
      currentFilters.onlyTopicless = $(this).is(':checked');
      updateSelectionCount();
    });
  }

  /**
   * Initialize action buttons
   */
  function initializeButtons() {
    // Ensure buttons start in clean state
    const $analyzeBtn = $('#ttd-bulk-analysis-analyze-button');
    const $resetBtn = $('#ttd-bulk-analysis-reset-button');

    // Set initial button states
    $analyzeBtn.text('Analyze Content').prop('disabled', false);
    $resetBtn.hide();

    // Handle analyze button click
    $analyzeBtn.once('ttd-analyze-btn').on('click', function (e) {
      e.preventDefault();
      if (!analysisInProgress) {
        startAnalysis();
      }
    });

    // Handle reset button click
    $resetBtn.once('ttd-reset-btn').on('click', function (e) {
      e.preventDefault();
      resetAnalysis();
    });
  }

  /**
   * Update current filters from form state
   */
  function updateCurrentFilters() {
    currentFilters.startDate = $('#ttd-bulk-analysis-start-date').val() || null;
    currentFilters.endDate = $('#ttd-bulk-analysis-end-date').val() || null;
    currentFilters.reanalyze = $('#ttd-bulk-analysis-reanalyze').is(':checked');
    currentFilters.includeDrafts = $('#ttd-bulk-analysis-include-drafts').is(':checked');
    currentFilters.onlyTopicless = $('#ttd-bulk-analysis-only-topicless').is(':checked');

    // Content types are updated in real-time via card interactions
  }

  /**
   * Update selection count with debouncing
   */
  function updateSelectionCount() {
    if (analysisInProgress) { return;
    }

    clearTimeout(updateCountTimeout);
    updateCountTimeout = setTimeout(doUpdateSelectionCount, 500);
  }

  /**
   * Perform the actual count update
   */
  function doUpdateSelectionCount() {
    if (analysisInProgress) { return;
    }

    updateCurrentFilters();

    // Validate content types
    if (currentFilters.contentTypes.length === 0) {
      $('#ttd-selection-status').show();
      $('#ttd-selection-status .ttd-selection-count').text('0');
      showMessage('Please select at least one content type to analyze', 'warning');
      $('#ttd-bulk-analysis-analyze-button').prop('disabled', true);
      return;
    }

    // Don't show the selection status until we know there's content
    // Just show a general loading message instead
    showMessage('Calculating content selection...', 'info');

    const endpoints = drupalSettings.ttd_topics.bulk_analysis_endpoints;

    $.ajax({
      url: endpoints.count,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.ttd_topics.nonce
      },
      data: JSON.stringify({
        content_types: currentFilters.contentTypes,
        start_date: currentFilters.startDate,
        end_date: currentFilters.endDate,
        include_drafts: currentFilters.includeDrafts,
        only_topicless: currentFilters.onlyTopicless,
        reanalyze: currentFilters.reanalyze
      })
    })
    .done(function (response) {
      if (response.success) {
        const count = response.data.count;
        const $selectionStatus = $('#ttd-selection-status');
        
        // Update button state
        const $analyzeBtn = $('#ttd-bulk-analysis-analyze-button');
        if (count > 0) {
          // Show selection status and update count
          $selectionStatus.show();
          $('#ttd-selection-status .ttd-selection-count').text(formatNumber(count));
          $analyzeBtn.prop('disabled', false);
          showMessage('Ready to analyze ' + formatNumber(count) + ' content items', 'ready');
        } else {
          // Show selection status with 0 count when no items
          $selectionStatus.show();
          $('#ttd-selection-status .ttd-selection-count').text('0');
          $analyzeBtn.prop('disabled', true);
          
          // Use backend message if provided, otherwise provide specific frontend message
          if (response.message) {
            showMessage(response.message, 'warning');
          } else {
            // Provide more specific message about why there are no items
            const selectedTypes = currentFilters.contentTypes;
            if (selectedTypes.length === 1) {
              showMessage('No published posts found for the "' + selectedTypes[0] + '" content type with your current filters', 'warning');
            } else if (selectedTypes.length > 1) {
              showMessage('No published posts found for the selected content types (' + selectedTypes.join(', ') + ') with your current filters', 'warning');
            } else {
              showMessage('No content items match your current filters', 'warning');
            }
          }
        }
      } else {
        $('#ttd-selection-status').show();
        $('#ttd-selection-status .ttd-selection-count').text('0');
        showMessage('Error counting content: ' + (response.message || 'Unknown error'), 'error');
        $('#ttd-bulk-analysis-analyze-button').prop('disabled', true);
      }
    })
    .fail(function (xhr) {
      Drupal.ttd_topics.debug.error('Count request failed:', xhr);
      // Try to extract error message from response
      let errMsg = 'Error counting content items. Please try again.';
      if (xhr.responseJSON && xhr.responseJSON.message) {
        errMsg = xhr.responseJSON.message;
      } else if (xhr.responseText) {
        try {
          const parsed = JSON.parse(xhr.responseText);
          if (parsed.message) {
            errMsg = parsed.message;
          }
        } catch (e) {
          // Not JSON – ignore
        }
      }
      $('#ttd-selection-status').show();
      $('#ttd-selection-status .ttd-selection-count').text('0');
      showMessage(errMsg, 'error');
      $('#ttd-bulk-analysis-analyze-button').prop('disabled', true);
    });
  }

  /**
   * Start bulk analysis process
   */
  function startAnalysis() {
    if (analysisInProgress) { return;
    }

    updateCurrentFilters();

    // Validate
    if (currentFilters.contentTypes.length === 0) {
      showMessage('Please select at least one content type', 'error');
      return;
    }

    analysisInProgress = true;
    currentStage = 'initiating';
          updateUIForAnalysis(true);

    showMessage('Initiating bulk analysis...', 'progress');

    const endpoints = drupalSettings.ttd_topics.bulk_analysis_endpoints;

    $.ajax({
      url: endpoints.initiate,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.ttd_topics.nonce
      },
      data: JSON.stringify({
        content_types: currentFilters.contentTypes,
        start_date: currentFilters.startDate,
        end_date: currentFilters.endDate,
        include_drafts: currentFilters.includeDrafts,
        only_topicless: currentFilters.onlyTopicless,
        reanalyze: currentFilters.reanalyze
      })
    })
    .done(function (response) {
      if (response.success) {
        currentRequestId = response.data.request_id;
        currentStage = 'sending';
        showMessage('Step 1/3: Sending content batches (0 of ' + response.data.page_count + ')', 'progress');
        startProgressPolling();
      } else {
        analysisFailed(response.message || 'Failed to initiate analysis');
      }
    })
          .fail(function (xhr) {
        Drupal.ttd_topics.debug.error('Initiate analysis failed:', xhr);
      analysisFailed('Failed to start analysis. Please try again.');
    });
  }

  /**
   * Start progress polling
   */
  function startProgressPolling() {
    if (pollInterval) { clearInterval(pollInterval);
    }

    pollInterval = setInterval(function () {
      pollProgress();
    }, 2000); // Poll every 2 seconds

    // Poll immediately
    pollProgress();
  }

  /**
   * Poll analysis progress
   */
  function pollProgress() {
    if (!analysisInProgress || !currentRequestId) {
      stopProgressPolling();
      return;
    }

    const endpoints = drupalSettings.ttd_topics.bulk_analysis_endpoints;

    $.ajax({
      url: endpoints.poll,
      method: 'GET',
      headers: {
        'X-CSRF-Token': drupalSettings.ttd_topics.nonce
      }
    })
    .done(function (response) {
      if (response.success && response.data.request_id === currentRequestId) {
        updateProgressDisplay(response.data);
      } else if (!response.data.request_id) {
        // Analysis completed or was reset - don't update progress display
        // as it would show 0/0 briefly before completion
        analysisComplete();
      }
    })
    .fail(function (xhr) {
      Drupal.ttd_topics.debug.error('Progress poll failed:', xhr);
      // Don't fail the analysis for a single poll failure, just continue
    });
  }

  /**
   * Update progress display based on current stage
   */
  function updateProgressDisplay(data) {
    const batchProgress = data.batch_progress;
    const analysisStatus = data.analysis_status;
    const applyProgress = data.apply_progress;
    const contentCount = data.content_count || 0;

    // Debug logging to see what we're getting from the API
    if (analysisStatus) {
      Drupal.ttd_topics.debug.log('Analysis Status received:', analysisStatus);
      Drupal.ttd_topics.debug.log('Available keys:', Object.keys(analysisStatus));
    }

    // Check for completion FIRST to avoid updating progress with reset values
    if (applyProgress && applyProgress.stage === 'complete') {
      analysisComplete();
      return;
    }

    // Step 1: Sending content for analysis
    if (batchProgress.completed < batchProgress.total) {
      currentStage = 'sending';
      // Calculate content items processed based on batch size (50 items per batch)
      const contentProcessed = Math.min(batchProgress.completed * 50, contentCount);
      showMessage('Step 1/3: Preparing content for analysis (' + formatNumber(contentProcessed) + ' of ' + formatNumber(contentCount) + ' items)', 'progress');
      updateProgressBar(contentProcessed, contentCount);
    }
    // Step 2: Server analysis
    else if (analysisStatus && !analysisStatus.ready) {
      currentStage = 'analyzing';
      const percent = analysisStatus.percentage || analysisStatus.percent || 0;
      const analyzed = analysisStatus.analyzed || 0;
          const totalContent = analysisStatus.content_count || contentCount || 100;
    Drupal.ttd_topics.debug.log('Analysis status:', {
      percent: percent,
      analyzed: analyzed,
      contentCount: totalContent,
      ready: analysisStatus.ready
    });
      showMessage('Step 2/3: Analyzing content • ' + percent + '% complete (' + formatNumber(analyzed) + ' of ' + formatNumber(totalContent) + ' items)', 'progress');
      updateProgressBar(analyzed, totalContent);
    }
    // Step 3: Applying results
    else if (analysisStatus && analysisStatus.ready && !applyProgress) {
      // Analysis ready, start applying results
      startApplyingResults();
    }
    else if (applyProgress && contentCount > 0) {
      // Only update apply progress if we have valid content count to avoid 0/0 display
      currentStage = 'applying';
      updateApplyProgress(applyProgress, contentCount);
    }
  }

  /**
   * Start applying analysis results
   */
  function startApplyingResults() {
    const endpoints = drupalSettings.ttd_topics.bulk_analysis_endpoints;

    $.ajax({
      url: endpoints.apply_results,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.ttd_topics.nonce
      }
    })
    .done(function (response) {
      if (response.success) {
        showMessage('Step 3/3: Applying results to content...', 'progress');
      } else {
        analysisFailed('Failed to start applying results: ' + (response.message || 'Unknown error'));
      }
    })
    .fail(function (xhr) {
      Drupal.ttd_topics.debug.error('Apply results failed:', xhr);
      analysisFailed('Failed to start applying results');
    });
  }

  /**
   * Update apply progress display
   */
  function updateApplyProgress(progress, contentCount) {
    if (progress.stage === 'customer_ids') {
      const p = progress.customer_ids;
      // Calculate estimated content items processed (50 items per page)
      const itemsProcessed = Math.min(p.completed * 50, contentCount);
      showMessage('Step 3/3: Applying results • Phase 1/2 • ' + formatNumber(itemsProcessed) + ' of ' + formatNumber(contentCount) + ' items', 'progress');
      updateProgressBar(itemsProcessed, contentCount);
    } else if (progress.stage === 'entities') {
      const p = progress.entities;
      
      // Skip displaying initial 0 progress to avoid visual flash
      if (p.completed === 0 && p.total > 0) {
        showMessage('Step 3/3: Applying results • Phase 2/2 • Processing...', 'progress');
        // Don't update progress bar for initial 0 state
        return;
      }
      
      // Calculate estimated content items processed (50 items per page)
      const itemsProcessed = Math.min(p.completed * 50, contentCount);
      showMessage('Step 3/3: Applying results • Phase 2/2 • ' + formatNumber(itemsProcessed) + ' of ' + formatNumber(contentCount) + ' items', 'progress');
      updateProgressBar(itemsProcessed, contentCount);
    } else if (progress.stage === 'complete') {
      analysisComplete();
    }
  }

  /**
   * Update progress bar
   */
  function updateProgressBar(completed, total) {
    if (total > 0) {
      const percentage = Math.round((completed / total) * 100);
      $('#ttd-bulk-analysis-progress-bar').css('width', percentage + '%');
      $('#ttd-progress-completed').text(formatNumber(completed));
      $('#ttd-progress-total').text(formatNumber(total));
      $('#ttd-bulk-analysis-progress').show();
    }
  }

  /**
   * Analysis completed successfully
   */
  function analysisComplete() {
    stopProgressPolling();
    analysisInProgress = false;
    currentRequestId = null;
    currentStage = null;

    // Immediately hide progress section to prevent brief flash of reset values
    $('#ttd-bulk-analysis-progress').hide();

    // Don't immediately reset the UI - keep the completion state
    showCompletionMessage();
  }

  /**
   * Show completion message with option to start new analysis
   */
  function showCompletionMessage() {
    const $messageArea = $('#ttd-bulk-analysis-message');

    // Clear any existing timeout
    if ($messageArea.data('timeout')) {
      clearTimeout($messageArea.data('timeout'));
    }

    // Build completion message with action button
    let messageHtml = '<div class="ttd-message-base ttd-status-success ttd-analysis-complete">';
    messageHtml += '<div class="ttd-message-content">';
    messageHtml += '<div class="ttd-completion-main">';
    messageHtml += '<div class="ttd-completion-title">✓ Bulk Analysis Complete!</div>';
    messageHtml += '<div class="ttd-completion-text">Your content has been successfully analyzed and topics applied.</div>';
    messageHtml += '</div>';
    messageHtml += '<div class="ttd-completion-actions">';
    messageHtml += '<button type="button" class="ttd-btn ttd-btn-primary" id="ttd-start-new-analysis">Start New Analysis</button>';
    messageHtml += '</div>';
    messageHtml += '</div>';
    messageHtml += '</div>';

    $messageArea.html(messageHtml).show();

    // Hide progress section and selection status
    $('#ttd-bulk-analysis-progress').hide();
    $('#ttd-selection-status').hide();

    // Hide the cancel button since analysis is complete
    const $resetBtn = $('#ttd-bulk-analysis-reset-button');
    $resetBtn.hide();

    // Disable the main analyze button and form controls
    const $analyzeBtn = $('#ttd-bulk-analysis-analyze-button');
    const $form = $('#ttd-bulk-analysis-form');

    $analyzeBtn.text('Analysis Complete').prop('disabled', true);
    $form.find('input, select, button').not('#ttd-start-new-analysis').prop('disabled', true);
    $('.ttd-content-type-card').addClass('disabled').attr('aria-disabled', 'true');
    $('.ttd-date-range-btn').addClass('disabled').prop('disabled', true);
    $('.ttd-bulk-analysis-filters, .ttd-content-type-selection').addClass('ttd-analysis-disabled');

    // Handle start new analysis button
    $('#ttd-start-new-analysis').once('ttd-start-new').on('click', function () {
      startNewAnalysis();
    });
  }

  /**
   * Start a new analysis (clear completion state)
   */
  function startNewAnalysis() {
    // Reset UI to normal state
    updateUIForAnalysis(false);

    // Show selection status again
    $('#ttd-selection-status').show();

    // Show loading message and update count
    showMessage('Loading content selection...', 'info');
    setTimeout(function () {
      updateCurrentFilters();
      updateSelectionCount();
    }, 500);
  }

  /**
   * Analysis failed
   */
  function analysisFailed(message) {
    stopProgressPolling();
    analysisInProgress = false;
    currentRequestId = null;
    currentStage = null;

    updateUIForAnalysis(false);
    showMessage('Analysis failed: ' + message, 'error');
  }

  /**
   * Reset/cancel analysis
   */
  function resetAnalysis() {
    if (!confirm('Are you sure you want to cancel the current analysis?')) {
      return;
    }

    const endpoints = drupalSettings.ttd_topics.bulk_analysis_endpoints;

    $.ajax({
      url: endpoints.reset,
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': drupalSettings.ttd_topics.nonce
      }
    })
    .done(function (response) {
      stopProgressPolling();
      analysisInProgress = false;
      currentRequestId = null;
      currentStage = null;

      updateUIForAnalysis(false);
      
      // Show detailed cancellation message with job count if available
      let message = response.message || 'Analysis cancelled successfully';
      if (response.data && response.data.cleared_jobs > 0) {
        message += ` (${response.data.cleared_jobs} queued jobs cleared)`;
      }
      showMessage(message, 'info');
      updateSelectionCount();
    })
    .fail(function (xhr) {
      Drupal.ttd_topics.debug.error('Reset analysis failed:', xhr);
      showMessage('Failed to cancel analysis', 'error');
    });
  }

  /**
   * Update UI for analysis state
   */
  function updateUIForAnalysis(inProgress) {
    const $analyzeBtn = $('#ttd-bulk-analysis-analyze-button');
    const $resetBtn = $('#ttd-bulk-analysis-reset-button');
    const $form = $('#ttd-bulk-analysis-form');

    if (inProgress) {
      // Add class to form for analysis state styling
      $form.addClass('analysis-in-progress');

      // Disable the analyze button and change text
      $analyzeBtn.text('Analysis in Progress...').prop('disabled', true);

      // Show the reset/cancel button
      $resetBtn.show();

      // Hide selection status during analysis since it's not relevant
      $('#ttd-selection-status').addClass('ttd-hidden-during-analysis');

      // Show progress section
      $('#ttd-bulk-analysis-progress').show();

      // Disable all form controls except the reset button
      $form.find('input, select, button').not($resetBtn).prop('disabled', true);

      // Disable content type cards and make them visually inactive
      $('.ttd-content-type-card').addClass('disabled').attr('aria-disabled', 'true');
      $('.ttd-content-type-card').off('click keydown'); // Remove event handlers

      // Disable date range buttons
      $('.ttd-date-range-btn').addClass('disabled').prop('disabled', true);

      // Add visual overlay to form sections
      $('.ttd-bulk-analysis-filters, .ttd-content-type-selection').addClass('ttd-analysis-disabled');

      // Prevent count updates during analysis
      if (updateCountTimeout) {
        clearTimeout(updateCountTimeout);
        updateCountTimeout = null;
      }

    } else {
      // Remove class from form for analysis state styling
      $form.removeClass('analysis-in-progress');

      // Re-enable the analyze button and reset text
      $analyzeBtn.text('Analyze Content').prop('disabled', false);

      // Hide the reset button
      $resetBtn.hide();

      // Show selection status again
      $('#ttd-selection-status').removeClass('ttd-hidden-during-analysis');

      // Hide progress section
      $('#ttd-bulk-analysis-progress').hide();

      // Re-enable all form controls
      $form.find('input, select, button').prop('disabled', false);

      // Re-enable content type cards
      $('.ttd-content-type-card').removeClass('disabled').removeAttr('aria-disabled');

      // Re-enable date range buttons
      $('.ttd-date-range-btn').removeClass('disabled').prop('disabled', false);

      // Remove visual overlay
      $('.ttd-bulk-analysis-filters, .ttd-content-type-selection').removeClass('ttd-analysis-disabled');

      // Re-initialize interactions after enabling
      initializeContentTypeCards();
      initializeDateRangeButtons();
    }
  }

  /**
   * Show status message
   */
  function showMessage(message, type = 'info', timeout = 0, allowDismiss = false) {
    const $messageArea = $('#ttd-bulk-analysis-message');

    // Clear any existing timeout
    if ($messageArea.data('timeout')) {
      clearTimeout($messageArea.data('timeout'));
    }

    // Build message HTML
    let messageHtml = '<div class="ttd-message-base ttd-status-' + type + '">';
    messageHtml += '<div class="ttd-message-content">' + message + '</div>';
    if (allowDismiss) {
      messageHtml += '<button class="ttd-message-dismiss" onclick="$(this).parent().fadeOut()">×</button>';
    }
    messageHtml += '</div>';

    $messageArea.html(messageHtml).show();

    // Auto-hide after timeout
    if (timeout > 0) {
      const timeoutId = setTimeout(function () {
        $messageArea.fadeOut();
      }, timeout);
      $messageArea.data('timeout', timeoutId);
    }
  }

  /**
   * Stop progress polling
   */
  function stopProgressPolling() {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  }

  /**
   * Format number with thousands separator
   */
  function formatNumber(num) {
    return new Intl.NumberFormat('en-US').format(num);
  }

  /**
   * Set default date range to "Last Month"
   */
  function setDefaultDateRange() {
    const endDate = new Date();
    const startDate = new Date();
    startDate.setDate(endDate.getDate() - 30);

    currentFilters.startDate = startDate.toISOString().split('T')[0];
    currentFilters.endDate = endDate.toISOString().split('T')[0];

    $('#ttd-bulk-analysis-start-date').val(currentFilters.startDate);
    $('#ttd-bulk-analysis-end-date').val(currentFilters.endDate);
    $('.ttd-date-range-btn[data-days="30"]').addClass('active');
  }

  /**
   * Add user guidance tooltips
   */
  function addUserGuidance() {
    // Add helpful tooltips for better UX
    $('#ttd-bulk-analysis-reanalyze').attr('title', 'Re-analyze content that has already been processed');
    $('#ttd-bulk-analysis-include-drafts').attr('title', 'Include draft content in the analysis');
    $('#ttd-bulk-analysis-only-topicless').attr('title', 'Only analyze content that doesn\'t have any topics yet');
  }

})(jQuery, Drupal, drupalSettings);
