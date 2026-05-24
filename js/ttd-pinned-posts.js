(function ($, Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.ttdPinnedPosts = {
    attach: function (context) {
      var $widget = $(once('ttd-pinned-posts', '#ttd-pinned-posts-widget', context));
      if (!$widget.length) return;

      var settings = drupalSettings.ttdPinnedPosts || {};
      var maxPinned = settings.maxPinned || 3;
      var searchUrl = settings.searchUrl || '/api/topicalboost/pinned-posts/search';
      var pinned = settings.pinned || [];
      var searchTimer = null;

      var $search = $widget.find('#ttd-pinned-posts-search');
      var $results = $widget.find('#ttd-pinned-posts-results');
      var $pinnedList = $widget.find('.ttd-pinned-list');
      var $hiddenField = $('#ttd-pinned-nids');

      function renderPinnedList() {
        $pinnedList.empty();
        if (pinned.length === 0) {
          $pinnedList.append('<li class="ttd-pinned-empty">No posts pinned yet.</li>');
        } else {
          pinned.forEach(function (post, index) {
            var $li = $('<li class="ttd-pinned-item" data-nid="' + post.nid + '"></li>');
            $li.append('<span class="ttd-pinned-title">' + Drupal.checkPlain(post.title) + '</span>');
            var $remove = $('<button type="button" class="ttd-pinned-remove" title="Remove">&times;</button>');
            $remove.on('click', function () {
              removePin(post.nid);
            });
            $li.append($remove);

            // Add drag handle for reordering.
            $li.prepend('<span class="ttd-pinned-handle" title="Drag to reorder">&#9776;</span>');
            $pinnedList.append($li);
          });
        }
        updateHiddenField();
      }

      function updateHiddenField() {
        var nids = pinned.map(function (p) { return p.nid; });
        $hiddenField.val(nids.join(','));
      }

      function addPin(nid, title) {
        if (pinned.length >= maxPinned) {
          return;
        }
        // Check for duplicates.
        for (var i = 0; i < pinned.length; i++) {
          if (pinned[i].nid === nid) return;
        }
        pinned.push({ nid: nid, title: title });
        renderPinnedList();
        $results.empty();
        $search.val('');
      }

      function removePin(nid) {
        pinned = pinned.filter(function (p) { return p.nid !== nid; });
        renderPinnedList();
      }

      function performSearch(query) {
        if (query.length < 2) {
          $results.empty();
          return;
        }

        var url = Drupal.url(searchUrl.replace(/^\//, '')) + '?q=' + encodeURIComponent(query);
        if (settings.termId) {
          url += '&term_id=' + settings.termId;
        }

        $.getJSON(url, function (data) {
          $results.empty();
          var results = data.results || [];
          if (results.length === 0) {
            $results.append('<div class="ttd-pinned-no-results">No posts found.</div>');
            return;
          }

          var $list = $('<ul class="ttd-pinned-search-results"></ul>');
          results.forEach(function (post) {
            var isPinned = pinned.some(function (p) { return p.nid === post.nid; });
            var $li = $('<li class="ttd-pinned-result' + (isPinned ? ' is-pinned' : '') + '"></li>');
            var $info = $('<div class="ttd-pinned-result-info"></div>');
            $info.append('<span class="ttd-pinned-result-title">' + Drupal.checkPlain(post.title) + '</span>');
            $info.append('<span class="ttd-pinned-result-meta">' + Drupal.checkPlain(post.type) + ' &middot; ' + Drupal.checkPlain(post.date) + '</span>');
            $li.append($info);

            if (isPinned) {
              $li.append('<span class="ttd-pinned-already">Pinned</span>');
            } else if (pinned.length >= maxPinned) {
              $li.append('<span class="ttd-pinned-max">Max reached</span>');
            } else {
              var $btn = $('<button type="button" class="ttd-pinned-add-btn">Pin</button>');
              $btn.on('click', function () {
                addPin(post.nid, post.title);
              });
              $li.append($btn);
            }

            $list.append($li);
          });
          $results.append($list);
        });
      }

      // Debounced search on input.
      $search.on('input', function () {
        clearTimeout(searchTimer);
        var query = $(this).val().trim();
        searchTimer = setTimeout(function () {
          performSearch(query);
        }, 300);
      });

      // Close results on outside click.
      $(document).on('click', function (e) {
        if (!$(e.target).closest('#ttd-pinned-posts-widget').length) {
          $results.empty();
        }
      });

      // Simple sortable via drag.
      var dragItem = null;
      $pinnedList.on('mousedown', '.ttd-pinned-handle', function (e) {
        dragItem = $(this).closest('.ttd-pinned-item');
        dragItem.addClass('dragging');
      });

      $(document).on('mouseup', function () {
        if (dragItem) {
          dragItem.removeClass('dragging');
          // Rebuild pinned array from DOM order.
          var newPinned = [];
          $pinnedList.find('.ttd-pinned-item').each(function () {
            var nid = parseInt($(this).data('nid'), 10);
            var match = pinned.find(function (p) { return p.nid === nid; });
            if (match) newPinned.push(match);
          });
          pinned = newPinned;
          updateHiddenField();
          dragItem = null;
        }
      });

      $pinnedList.on('mouseover', '.ttd-pinned-item', function () {
        if (dragItem && dragItem[0] !== this) {
          var $this = $(this);
          var mouseY = event.pageY;
          var itemTop = $this.offset().top;
          var itemHeight = $this.outerHeight();

          if (mouseY < itemTop + itemHeight / 2) {
            $this.before(dragItem);
          } else {
            $this.after(dragItem);
          }
        }
      });

      // Initial render.
      renderPinnedList();
    }
  };

})(jQuery, Drupal, drupalSettings, once);
