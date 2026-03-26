(function ($, Drupal, once) {
  'use strict';

  var $search, $spinner, $results, $items, $count, $feedback;
  var searchTimer = null;
  var watchlistItems = [];
  var loaded = false;
  var currentSearchXhr = null;
  var currentSearchQuery = '';

  Drupal.behaviors.ttdWatchlist = {
    attach: function (context) {
      $(once('ttd-watchlist', '#ttd-watchlist-search', context)).each(function () {
        $search = $(this);
        $spinner = $('#ttd-watchlist-spinner');
        $results = $('#ttd-watchlist-results');
        $items = $('#ttd-watchlist-items');
        $count = $('#ttd-watchlist-count span');
        $feedback = $('#ttd-watchlist-feedback');

        // Load when watchlist tab becomes visible.
        $(document).on('click', '[data-tab="watchlist"]', function () {
          if (!loaded) loadWatchlist();
        });

        // If already on watchlist tab, load immediately.
        if ($('#watchlist-tab').hasClass('active')) {
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
        $items.html('<p class="ttd-watchlist-empty" style="color: #d63638;">Failed to load watchlist.</p>');
      }
    });
  }

  function renderWatchlist() {
    $count.text(watchlistItems.length);

    if (watchlistItems.length === 0) {
      $items.html('<p class="ttd-watchlist-empty">No watchlist entities. Add entities your publication frequently covers for automatic detection.</p>');
      return;
    }

    var html = '';
    watchlistItems.forEach(function (item) {
      var name = item.kgName || item.wbName || item.nlName || item.label;
      var desc = item.kgDescription || item.wbDescription || '';
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

  function searchEntities(query) {
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
          createHtml += '<div class="ttd-watchlist-result-name">Create "' + escHtml(query) + '" as custom entity</div>';
          createHtml += '<div class="ttd-watchlist-result-desc">Not found in Google KG or Wikidata &mdash; add as custom entity</div>';
          createHtml += '</div></div>';
          $results.html(createHtml).show();

          $results.find('.ttd-watchlist-create-custom').on('click', function () {
            var name = $(this).data('name');
            if (name) createCustomEntity(name);
          });
          return;
        }

        var html = '';
        candidates.forEach(function (c) {
          var alreadyAdded = watchlistItems.some(function (w) { return w.entityId === c.entityId; });
          var disabledClass = (!c.entityId || alreadyAdded) ? ' ttd-watchlist-result-disabled' : '';
          var badge = alreadyAdded ? ' <span class="ttd-watchlist-already">already added</span>' : '';

          html += '<div class="ttd-watchlist-result-item' + disabledClass + '" data-entity-id="' + (c.entityId || '') + '" data-name="' + escAttr(c.name) + '">';
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
          if (entityId) addToWatchlist(entityId, name);
        });
      },
      error: function (jqXHR, textStatus) {
        currentSearchXhr = null;
        if (textStatus !== 'abort') $spinner.removeClass('is-active');
      }
    });
  }

  function addToWatchlist(entityId, label) {
    $results.hide();
    $search.val('');
    showFeedback('Adding...', 'info');

    $.ajax({
      url: '/api/topicalboost/watchlist/add',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ entity_id: entityId, label: label }),
      dataType: 'json',
      success: function (response) {
        if (response.success && response.data && response.data.item) {
          watchlistItems.push(response.data.item);
          renderWatchlist();
          showFeedback('Entity added to watchlist', 'success');
        } else {
          showFeedback((response.data && response.data.message) || 'Failed to add entity', 'error');
        }
      },
      error: function () {
        showFeedback('Failed to add entity', 'error');
      }
    });
  }

  function createCustomEntity(name) {
    $results.hide();
    $search.val('');
    showFeedback('Creating custom entity...', 'info');

    $.ajax({
      url: '/api/topicalboost/watchlist/create-custom',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ name: name }),
      dataType: 'json',
      success: function (response) {
        if (response.success && response.data && response.data.item) {
          watchlistItems.push(response.data.item);
          renderWatchlist();
          showFeedback('Custom entity created and added to watchlist', 'success');
        } else {
          showFeedback((response.data && response.data.message) || 'Failed to create custom entity', 'error');
        }
      },
      error: function () {
        showFeedback('Failed to create custom entity', 'error');
      }
    });
  }

  function removeFromWatchlist(entityId, $chip) {
    $chip.css('opacity', '0.5');

    $.ajax({
      url: '/api/topicalboost/watchlist/remove',
      type: 'POST',
      contentType: 'application/json',
      data: JSON.stringify({ entity_id: entityId }),
      dataType: 'json',
      success: function (response) {
        if (response.success) {
          watchlistItems = watchlistItems.filter(function (w) { return w.entityId !== entityId; });
          renderWatchlist();
          showFeedback('Entity removed', 'success');
        } else {
          $chip.css('opacity', '1');
          showFeedback('Failed to remove entity', 'error');
        }
      },
      error: function () {
        $chip.css('opacity', '1');
        showFeedback('Failed to remove entity', 'error');
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
