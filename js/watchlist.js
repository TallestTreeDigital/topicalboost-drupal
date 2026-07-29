(function ($, Drupal, once) {
  'use strict';

  var $search, $guidance, $spinner, $results, $items, $count, $capacity, $feedback;
  var MAX_WATCHLIST_SIZE = 50;
  var CAPACITY_WARNING_THRESHOLD = 40;
  var searchTimer = null;
  var watchlistItems = [];
  var loaded = false;
  var currentSearchXhr = null;
  var currentSearchQuery = '';

  Drupal.behaviors.ttdWatchlist = {
    attach: function (context) {
      $(once('ttd-watchlist', '#ttd-watchlist-search', context)).each(function () {
        $search = $(this);
        $guidance = $('#ttd-watchlist-guidance');
        $spinner = $('#ttd-watchlist-spinner');
        $results = $('#ttd-watchlist-results');
        $items = $('#ttd-watchlist-items');
        $count = $('#ttd-watchlist-count span');
        $capacity = $('#ttd-watchlist-capacity');
        $feedback = $('#ttd-watchlist-feedback');

        // Load when watchlist tab becomes visible.
        $(document).on('click', '[data-tab="tab-watchlist"]', function () {
          if (!loaded) loadWatchlist();
        });

        // If already on watchlist tab, load immediately.
        if ($('#tab-watchlist').hasClass('active')) {
          loadWatchlist();
        }

        $search.on('input', function () {
          var query = $(this).val().trim();
          clearTimeout(searchTimer);
          if (query.length < 2) {
            $results.hide().empty();
            return;
          }
          searchTimer = setTimeout(function () {
            searchEntities(query);
          }, 300);
        });

        $search.on('keydown', function (e) {
          if (e.key === 'Enter') e.preventDefault();
          if (e.key === 'Escape') {
            $results.hide();
            $search.val('');
          }
        });

        $(document).on('mousedown', function (e) {
          if (!$(e.target).closest('.ttd-watchlist-search-wrapper').length) {
            $results.hide();
          }
        });
      });
    }
  };

  function loadWatchlist() {
    $.ajax({
      url: '/api/topicalboost/watchlist',
      type: 'GET',
      dataType: 'json',
      success: function (response) {
        if (response.success && response.data && response.data.items) {
          watchlistItems = response.data.items;
        } else {
          watchlistItems = [];
        }
        loaded = true;
        renderWatchlist();
      },
      error: function () {
        $items.html('<p class="ttd-watchlist-empty" style="color: #d63638;">Failed to load topics.</p>');
      }
    });
  }

  function renderWatchlist() {
    $count.text(watchlistItems.length);
    updateCapacityState();

    if (watchlistItems.length === 0) {
      $items.html('<p class="ttd-watchlist-empty">No topics added. Search for a niche or frequently missed topic to give it an extra check during analysis.</p>');
      return;
    }

    var html = '';
    watchlistItems.forEach(function (item) {
      var name = item.kgName || item.wbName || item.nlName || item.label;
      var desc = item.guidance || item.kgDescription || item.wbDescription || '';
      html += '<span class="ttd-watchlist-chip" data-entity-id="' + item.entityId + '" title="' + escAttr(desc) + '">';
      html += escHtml(name);
      html += '<button type="button" class="ttd-watchlist-chip-remove" aria-label="Remove">&times;</button>';
      html += '</span>';
    });

    $items.html(html);

    $items.find('.ttd-watchlist-chip-remove').on('click', function () {
      var $chip = $(this).closest('.ttd-watchlist-chip');
      var entityId = $chip.data('entity-id');
      removeFromWatchlist(entityId, $chip);
    });
  }

  function updateCapacityState() {
    var count = watchlistItems.length;
    var remaining = Math.max(0, MAX_WATCHLIST_SIZE - count);
    var atLimit = count >= MAX_WATCHLIST_SIZE;

    $search.prop('disabled', atLimit);
    $search.attr('placeholder', atLimit ? 'Topic limit reached' : 'Search for an entity...');

    if (atLimit) {
      $capacity.text('50-topic limit reached. Remove a topic to add another.').show();
      $results.hide().empty();
      return;
    }

    if (count >= CAPACITY_WARNING_THRESHOLD) {
      $capacity.text(remaining + (remaining === 1 ? ' spot remaining' : ' spots remaining')).show();
      return;
    }

    $capacity.hide().empty();
  }

  function isAtLimit() {
    return watchlistItems.length >= MAX_WATCHLIST_SIZE;
  }

  function searchEntities(query) {
    if (isAtLimit()) return;

    if (currentSearchXhr) currentSearchXhr.abort();
    currentSearchQuery = query;
    $spinner.addClass('is-active');

    currentSearchXhr = $.ajax({
      url: '/api/topicalboost/watchlist/search',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ query: query }),
      dataType: 'json',
      success: function (response) {
        currentSearchXhr = null;
        if (query !== currentSearchQuery) return;
        $spinner.removeClass('is-active');

        if (!response.success || !response.data) {
          $results.hide();
          return;
        }

        var candidates = response.data.candidates || [];
        if (candidates.length === 0) {
          var createHtml = '<div class="ttd-watchlist-no-results-wrapper">';
          createHtml += '<div class="ttd-watchlist-result-item ttd-watchlist-no-results">No matching entities found in knowledge bases.</div>';
          createHtml += '<div class="ttd-watchlist-result-item ttd-watchlist-create-custom" data-name="' + escAttr(query) + '">';
          createHtml += '<div class="ttd-watchlist-result-name">Create "' + escHtml(query) + '" as a custom Priority Topic</div>';
          createHtml += '<div class="ttd-watchlist-result-desc">Not found in Google KG or Wikidata &mdash; guidance is required</div>';
          createHtml += '</div></div>';
          $results.html(createHtml).show();

          $results.find('.ttd-watchlist-create-custom').on('click', function () {
            var name = $(this).data('name');
            var guidance = $guidance.val() || '';
            if (!guidance.trim()) {
              showFeedback('Describe what should count as this custom Priority Topic first.', 'error');
              $guidance.trigger('focus');
              return;
            }
            if (name) createCustomEntity(name, guidance);
          });
          return;
        }

        var html = '';
        candidates.forEach(function (c) {
          var alreadyAdded = watchlistItems.some(function (w) { return w.entityId === c.entityId; });
          var disabledClass = (!c.entityId || alreadyAdded) ? ' ttd-watchlist-result-disabled' : '';
          var badge = alreadyAdded ? ' <span class="ttd-watchlist-already">already added</span>' : '';

          html += '<div class="ttd-watchlist-result-item' + disabledClass + '" data-entity-id="' + (c.entityId || '') + '" data-name="' + escAttr(c.name) + '" data-has-description="' + (c.description ? '1' : '0') + '">';
          html += '<div class="ttd-watchlist-result-name">' + escHtml(c.name) + badge + '</div>';
          if (c.description) {
            html += '<div class="ttd-watchlist-result-desc">' + escHtml(c.description) + '</div>';
          }
          html += '</div>';
        });

        $results.html(html).show();

        $results.find('.ttd-watchlist-result-item:not(.ttd-watchlist-result-disabled)').on('click', function () {
          var entityId = $(this).data('entity-id');
          var name = $(this).data('name');
          var guidance = $guidance.val() || '';
          if (!guidance.trim() && String($(this).data('has-description')) !== '1') {
            showFeedback('Add guidance so the analyzer knows what should count as this Priority Topic.', 'error');
            $guidance.trigger('focus');
            return;
          }
          if (entityId) addToWatchlist(entityId, name, guidance);
        });
      },
      error: function (jqXHR, textStatus) {
        currentSearchXhr = null;
        if (textStatus !== 'abort') $spinner.removeClass('is-active');
      }
    });
  }

  function addToWatchlist(entityId, label, guidance) {
    if (isAtLimit()) {
      showFeedback('Remove a topic before adding another.', 'error');
      return;
    }

    $results.hide();
    $search.val('');
    showFeedback('Adding...', 'info');

    $.ajax({
      url: '/api/topicalboost/watchlist/add',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ entity_id: entityId, label: label, description: guidance, surface: 'settings' }),
      dataType: 'json',
      success: function (response) {
        if (response.success && response.data && response.data.item) {
          $guidance.val('');
          watchlistItems.push(response.data.item);
          renderWatchlist();
          showFeedback('Topic will receive an extra check across the site', 'success');
        } else {
          showFeedback((response.data && response.data.message) || 'Failed to add Priority Topic', 'error');
        }
      },
      error: function () {
        showFeedback('Failed to add Priority Topic', 'error');
      }
    });
  }

  function createCustomEntity(name, guidance) {
    if (isAtLimit()) {
      showFeedback('Remove a topic before adding another.', 'error');
      return;
    }

    $results.hide();
    $search.val('');
    showFeedback('Creating custom Priority Topic...', 'info');

    $.ajax({
      url: '/api/topicalboost/watchlist/create-custom',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ name: name, description: guidance, surface: 'settings' }),
      dataType: 'json',
      success: function (response) {
        if (response.success && response.data && response.data.item) {
          $guidance.val('');
          watchlistItems.push(response.data.item);
          renderWatchlist();
          showFeedback('Custom topic will receive an extra check across the site', 'success');
        } else {
          showFeedback((response.data && response.data.message) || 'Failed to create custom Priority Topic', 'error');
        }
      },
      error: function () {
        showFeedback('Failed to create custom Priority Topic', 'error');
      }
    });
  }

  function removeFromWatchlist(entityId, $chip) {
    $chip.css('opacity', '0.5');

    $.ajax({
      url: '/api/topicalboost/watchlist/remove',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ entity_id: entityId, surface: 'settings' }),
      dataType: 'json',
      success: function (response) {
        if (response.success) {
          watchlistItems = watchlistItems.filter(function (w) { return w.entityId !== entityId; });
          renderWatchlist();
          showFeedback('Topic removed', 'success');
        } else {
          $chip.css('opacity', '1');
          showFeedback('Failed to remove Priority Topic', 'error');
        }
      },
      error: function () {
        $chip.css('opacity', '1');
        showFeedback('Failed to remove Priority Topic', 'error');
      }
    });
  }

  function showFeedback(message, type) {
    var cls = type === 'error' ? 'error-message' : (type === 'success' ? 'success-message' : '');
    $feedback.html('<div class="' + cls + '">' + escHtml(message) + '</div>');
    setTimeout(function () { $feedback.empty(); }, 3000);
  }

  function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  function escAttr(str) {
    return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

})(jQuery, Drupal, once);
