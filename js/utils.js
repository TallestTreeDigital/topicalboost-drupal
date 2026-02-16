/**
 * @file
 * Utility functions for TopicalBoost admin topics editor.
 */

(function (Drupal) {
  'use strict';

  // Create namespace
  window.ttdTopicsUtils = window.ttdTopicsUtils || {};

  /**
   * Format count with K/M notation.
   */
  window.ttdTopicsUtils.formatCount = function(count) {
    if (!count && count !== 0) return '--';
    const num = parseInt(count);
    if (num >= 1000000) {
      return (num / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    } else if (num >= 1000) {
      return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    }
    return num.toString();
  };

  /**
   * Get KD CSS class based on difficulty.
   */
  window.ttdTopicsUtils.getKdClass = function(difficulty) {
    if (difficulty <= 30) return 'ttd-kd-easy';
    if (difficulty <= 60) return 'ttd-kd-medium';
    if (difficulty <= 80) return 'ttd-kd-hard';
    return 'ttd-kd-very-hard';
  };

  /**
   * Get KD color based on difficulty.
   */
  window.ttdTopicsUtils.getKdColor = function(difficulty) {
    if (difficulty > 80) return '#dc2626'; // red-600
    if (difficulty > 60) return '#ea580c'; // orange-600
    if (difficulty > 30) return '#ca8a04'; // yellow-600
    return '#16a34a'; // green-600
  };

  /**
   * Get KD label.
   */
  window.ttdTopicsUtils.getKdLabel = function(difficulty) {
    if (difficulty <= 30) return 'Easy';
    if (difficulty <= 60) return 'Medium';
    if (difficulty <= 80) return 'Hard';
    return 'Very Hard';
  };

  /**
   * Render a topic item.
   */
  window.ttdTopicsUtils.renderTopicItem = function(topic, type, section) {
    const isManual = type === 'manual';
    const ttdId = topic.ttd_id || '';
    const termId = topic.term_id || topic.id || '';
    const count = topic.count || 0;
    const name = topic.name || '';
    const isRejected = topic.rejected || false;
    const countFormatted = this.formatCount(count);

    // Build classes
    const classes = [
      'topic-item',
      isManual ? 'manual-topic' : 'api-topic',
      section === 'mainEntity' ? 'main-entity-topic' :
      section === 'about' ? 'about-topic' :
      section === 'mentions' ? 'mentions-topic' : 'below-threshold-topic'
    ];
    if (isRejected) classes.push('rejected');

    let html = '<div class="' + classes.join(' ') + '" ' +
               'data-term-id="' + termId + '" ' +
               'data-ttd-id="' + ttdId + '" ' +
               'draggable="true">';

    // Checkbox for auto topics
    if (!isManual) {
      html += '<input type="checkbox" name="topics[]" value="' + termId + '" ' +
              (isRejected ? '' : 'checked="checked"') + ' ' +
              'aria-label="Accept ' + name + '" />';
    }

    // Remove button for manual topics
    if (isManual) {
      html += '<button type="button" class="remove-topic" ' +
              'data-term-id="' + termId + '" ' +
              'aria-label="Remove ' + name + '" title="Remove this manual topic">×</button>';
    }

    // KD Badge for mainEntity and about
    if (section === 'mainEntity' || section === 'about') {
      html += '<span class="ttd-kd-badge ttd-kd-no-data" title="Click to fetch demand data">--</span>';
    }

    // Topic count
    html += '<span class="topic-count" data-count="' + count + '">' + countFormatted + '</span>';

    // Topic name
    html += '<div class="topic-name-container"><label>' + name + '</label></div>';

    // Drag handle
    html += '<span class="drag-handle" aria-label="Drag to reorder">⋮⋮</span>';

    html += '</div>';

    return html;
  };

  /**
   * Render search results.
   */
  window.ttdTopicsUtils.renderSearchResults = function($container, results) {
    $container.empty();

    if (!results || results.length === 0) {
      $container.html('<div class="ttd-search-no-results">No topics found</div>').show();
      return;
    }

    results.forEach(function(result) {
      const $item = jQuery('<div class="ttd-search-result-item"></div>');

      const name = result.name || '';
      const count = result.count || 0;
      const source = result.source || 'local';
      const exists = result.exists || false;
      const inPost = result.in_post || false;

      $item.html(
        '<span class="result-name">' + name + '</span>' +
        '<span class="result-count">' + window.ttdTopicsUtils.formatCount(count) + '</span>' +
        '<span class="result-source">' + source + '</span>'
      );

      $item.data('result', result);

      if (inPost) {
        $item.addClass('in-post');
      } else if (exists) {
        $item.addClass('exists');
      }

      $item.on('click', function() {
        const data = jQuery(this).data('result');
        window.ttdTopicsUtils.addTopicFromSearch(data, $container, $item);
      });

      $container.append($item);
    });

    $container.show();
  };

  /**
   * Add topic from search results.
   */
  window.ttdTopicsUtils.addTopicFromSearch = function(topic, $searchResults, $clickedItem) {
    const $parentContainer = $searchResults.closest('.ttd-topics-container');
    const nodeId = $parentContainer.data('node-id');
    const termId = topic.term_id || topic.id;
    const ttdId = topic.ttd_id || termId;

    if (!nodeId || !termId) {
      console.error('Missing node ID or term ID');
      return;
    }

    // Show loading state
    $clickedItem.addClass('updating');

    // Add the topic to the post as a manual topic
    jQuery.ajax({
      url: '/api/topicalboost/topics/update',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({
        node_id: nodeId,
        topic_id: termId,
        add_manual: true
      }),
      success: function(response) {
        if (response.success) {
          // Find the mentions section
          const $mentionsSection = $parentContainer.find('.ttd-mentions-section');
          const $mentionsList = $mentionsSection.find('.ttd-topics-list');

          // Check if topic already exists
          const $existingTopic = $mentionsList.find('[data-ttd-id="' + ttdId + '"]');

          if (!$existingTopic.length) {
            // Create the manual topic item
            const manualTopic = {
              ttd_id: ttdId,
              term_id: termId,
              name: topic.name,
              count: 1,
              manual: true,
              is_manual: true,
              in_post: true
            };

            // Render the topic
            const html = window.ttdTopicsUtils.renderTopicItem(manualTopic, 'manual', 'mentions');
            const $newItem = jQuery(html);

            // Add to mentions section
            $mentionsList.append($newItem);

            // Show and expand mentions section
            $mentionsSection.show();
            const $header = $mentionsSection.find('.ttd-section-header');
            $header.attr('aria-expanded', 'true');
            $header.find('.ttd-section-arrow')
              .removeClass('dashicons-arrow-right-alt2')
              .addClass('dashicons-arrow-down-alt2');
            $mentionsList.show();

            // Hide "no topics" message
            $mentionsSection.find('.ttd-no-topics-message').hide();

            // Update section count
            const count = $mentionsList.find('.topic-item').length;
            $mentionsSection.find('.ttd-section-count').text('(' + count + ')');
          }

          // Clear search and hide results
          jQuery('#ttd-topics-search').val('');
          $searchResults.hide();
        } else {
          console.error('Failed to add manual topic:', response);
        }
      },
      error: function(xhr, status, error) {
        console.error('Error adding manual topic:', error);
      },
      complete: function() {
        $clickedItem.removeClass('updating');
      }
    });
  };

  /**
   * Bind search handlers.
   */
  window.ttdTopicsUtils.bindSearchHandlers = function($container) {
    const $searchInput = $container.find('#ttd-topics-search');
    const $searchResults = $container.find('#ttd-topics-search-results');
    let searchTimeout;

    $searchInput.on('input', function() {
      const query = jQuery(this).val().trim();

      clearTimeout(searchTimeout);

      if (query.length < 2) {
        $searchResults.hide();
        return;
      }

      searchTimeout = setTimeout(function() {
        window.ttdTopicsUtils.performSearch(query, $container);
      }, 800);
    });

    // Close results on outside click
    jQuery(document).on('click', function(e) {
      if (!jQuery(e.target).closest('.ttd-topics-search-container').length) {
        $searchResults.hide();
      }
    });
  };

  /**
   * Perform search (local + API).
   */
  window.ttdTopicsUtils.performSearch = function(query, $container) {
    const nodeId = $container.data('node-id');
    const $searchResults = $container.find('#ttd-topics-search-results');

    // Show loading
    $searchResults.html('<div class="ttd-search-loading">Searching...</div>').show();

    // Parallel AJAX calls
    const localPromise = jQuery.ajax({
      url: '/api/topicalboost/search',
      method: 'GET',
      data: { q: query, node_id: nodeId }
    });

    const apiPromise = jQuery.ajax({
      url: '/api/topicalboost/lookup',
      method: 'GET',
      data: { q: query, node_id: nodeId }
    });

    Promise.all([localPromise, apiPromise]).then(function(responses) {
      const localResults = responses[0].results || [];
      const apiResults = responses[1].results || [];

      // Merge and deduplicate by ttd_id
      const mergedResults = [];
      const seenIds = new Set();

      localResults.forEach(function(r) {
        if (r.ttd_id && !seenIds.has(r.ttd_id)) {
          seenIds.add(r.ttd_id);
          mergedResults.push(r);
        }
      });

      apiResults.forEach(function(r) {
        if (r.ttd_id && !seenIds.has(r.ttd_id)) {
          seenIds.add(r.ttd_id);
          mergedResults.push(r);
        }
      });

      window.ttdTopicsUtils.renderSearchResults($searchResults, mergedResults);
    }).catch(function(error) {
      console.error('Search error:', error);
      $searchResults.html('<div class="ttd-search-error">Search failed</div>');
    });
  };

  /**
   * Bind search input handlers.
   */
  window.ttdTopicsUtils.bindSearchHandlers = function($container) {
    const $searchInput = $container.find('#ttd-topics-search');
    const $searchResults = $container.find('#ttd-topics-search-results');
    let searchTimeout;

    $searchInput.on('input', function() {
      const query = jQuery(this).val().trim();

      clearTimeout(searchTimeout);

      if (query.length < 2) {
        $searchResults.hide();
        return;
      }

      searchTimeout = setTimeout(function() {
        window.ttdTopicsUtils.performSearch(query, $container);
      }, 800);
    });

    // Close results on outside click
    jQuery(document).on('click', function(e) {
      if (!jQuery(e.target).closest('.ttd-topics-search-container').length) {
        $searchResults.hide();
      }
    });
  };

})(Drupal);
