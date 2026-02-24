/**
 * @file
 * TopicalBoost post editor functionality (WP beta features port).
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.ttdPostEditor = {
    attach: function (context, settings) {
      $('.ttd-topics-container', context).once('ttd-post-editor').each(function() {
        const $container = $(this);
        const nodeId = $container.data('node-id');

        if (!nodeId) return;

        const $searchInput = $container.find('#ttd-topics-search');
        const $searchResults = $container.find('#ttd-topics-search-results');
        const $getTopicsButton = $container.find('#get-topics-button');
        const $topicsStatus = $container.find('#ttd-topics-status');

        // Track dragged item
        let draggedItem = null;
        let draggedTtdId = null;
        let draggedTermId = null;

        /**
         * Initialize drag-and-drop.
         */
        function initDragDrop() {
          $container.find('.topic-item.api-topic, .topic-item.manual-topic').attr('draggable', 'true');
        }

        initDragDrop();

        /**
         * Checkbox change handler (accept/reject).
         */
        $container.on('change', '.topic-item input[type="checkbox"]', function() {
          const $checkbox = $(this);
          const $topicItem = $checkbox.closest('.topic-item');
          const topicId = $checkbox.val();
          const isAccepted = $checkbox.prop('checked');

          if (!topicId || !nodeId) return;

          $topicItem.addClass('updating');

          $.ajax({
            url: '/api/topicalboost/topics/update',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              node_id: nodeId,
              topic_id: topicId,
              is_accepted: isAccepted
            }),
            success: function(response) {
              if (response.success) {
                $topicItem.toggleClass('rejected', !isAccepted);
              } else {
                $checkbox.prop('checked', !isAccepted);
                console.error('Failed to update topic:', response);
              }
            },
            error: function(xhr, status, error) {
              $checkbox.prop('checked', !isAccepted);
              console.error('AJAX error:', status, error);
            },
            complete: function() {
              $topicItem.removeClass('updating');
            }
          });
        });

        /**
         * Section header toggle (collapsible sections).
         */
        $container.on('click', 'button.ttd-section-header', function() {
          const $header = $(this);
          const $section = $header.closest('.ttd-topics-section');
          const $list = $section.find('.ttd-topics-list');
          const $arrow = $header.find('.ttd-section-arrow');
          const isExpanded = $header.attr('aria-expanded') === 'true';

          $header.attr('aria-expanded', !isExpanded);
          $list.slideToggle(200);
          $arrow.toggleClass('dashicons-arrow-right-alt2', isExpanded)
                .toggleClass('dashicons-arrow-down-alt2', !isExpanded);
        });

        /**
         * "Add manually" link handler.
         */
        $container.on('click', '.ttd-add-manual-link', function(e) {
          e.preventDefault();
          $searchInput.focus();
        });

        /**
         * Drag start.
         */
        $container.on('dragstart', '.topic-item.api-topic, .topic-item.manual-topic', function(e) {
          draggedItem = this;
          draggedTtdId = $(this).data('ttd-id');
          draggedTermId = $(this).data('term-id');
          $(this).addClass('dragging');

          e.originalEvent.dataTransfer.effectAllowed = 'move';
          e.originalEvent.dataTransfer.setData('text/plain', draggedTtdId || draggedTermId);

          $container.find('.ttd-topics-section[data-section]')
            .not($(this).closest('.ttd-topics-section'))
            .addClass('drop-zone-active');
        });

        /**
         * Drag end.
         */
        $container.on('dragend', '.topic-item.api-topic, .topic-item.manual-topic', function() {
          $(this).removeClass('dragging');
          $container.find('.ttd-topics-section')
            .removeClass('drop-zone-hover drop-zone-active drop-zone-blocked');

          draggedItem = null;
          draggedTtdId = null;
          draggedTermId = null;
        });

        /**
         * Drag over.
         */
        $container.on('dragover', '.ttd-topics-section[data-section]', function(e) {
          e.preventDefault();

          const $section = $(this);
          const tier = $section.data('section');

          // Check capacity
          const maxAllowed = tier === 'mainEntity' ? 1 : (tier === 'about' ? 4 : null);
          if (maxAllowed !== null) {
            const currentCount = $section.find('.ttd-topics-list .topic-item').length;
            if (currentCount >= maxAllowed) {
              e.originalEvent.dataTransfer.dropEffect = 'none';
              $section.addClass('drop-zone-blocked');
              return;
            }
          }

          e.originalEvent.dataTransfer.dropEffect = 'move';

          if (!$section.find(draggedItem).length) {
            $section.addClass('drop-zone-hover');
          }
        });

        /**
         * Drag leave.
         */
        $container.on('dragleave', '.ttd-topics-section[data-section]', function(e) {
          if (!$.contains(this, e.relatedTarget)) {
            $(this).removeClass('drop-zone-hover drop-zone-blocked');
          }
        });

        /**
         * Drop.
         */
        $container.on('drop', '.ttd-topics-section[data-section]', function(e) {
          e.preventDefault();

          const $targetSection = $(this);
          const newTier = $targetSection.data('section');
          const $sourceSection = $(draggedItem).closest('.ttd-topics-section');
          const oldTier = $sourceSection.data('section');

          $targetSection.removeClass('drop-zone-hover drop-zone-blocked');

          if (newTier === oldTier) return;

          const $item = $(draggedItem);
          $item.addClass('updating');

          // Handle below-threshold (remove override)
          if (newTier === 'below-threshold') {
            $.ajax({
              url: '/api/topicalboost/tier/remove',
              type: 'POST',
              contentType: 'application/json',
              data: JSON.stringify({
                node_id: nodeId,
                ttd_id: draggedTtdId,
                term_id: draggedTermId
              }),
              success: function(response) {
                if (response.success) {
                  moveTopic($item, $targetSection, newTier);
                }
              },
              error: function(xhr, status, error) {
                console.error('Error removing tier override:', error);
              },
              complete: function() {
                $item.removeClass('updating');
              }
            });
            return;
          }

          // Update tier
          $.ajax({
            url: '/api/topicalboost/tier/update',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              node_id: nodeId,
              ttd_id: draggedTtdId,
              term_id: draggedTermId,
              new_tier: newTier
            }),
            success: function(response) {
              if (response.success) {
                moveTopic($item, $targetSection, newTier);

                // Add/update KD badge for focus topics
                if (newTier === 'mainEntity' || newTier === 'about') {
                  let $badge = $item.find('.ttd-kd-badge');
                  if (!$badge.length) {
                    const $insertAfter = $item.find('input[type="checkbox"]').length ?
                      $item.find('input[type="checkbox"]') : $item.find('.remove-topic');
                    $insertAfter.after('<span class="ttd-kd-badge ttd-kd-loading"><span class="ttd-badge-spinner"></span></span>');
                    $badge = $item.find('.ttd-kd-badge');
                  }

                  // Fetch metrics
                  fetchDemandMetrics(draggedTermId, $badge);
                }
              } else {
                console.error('Failed to update tier:', response);
              }
            },
            error: function(xhr, status, error) {
              console.error('Error updating tier:', error);
            },
            complete: function() {
              $item.removeClass('updating');
            }
          });
        });

        /**
         * Move topic to new section.
         */
        function moveTopic($item, $targetSection, newTier) {
          const $targetList = $targetSection.find('.ttd-topics-list');

          // Expand section if collapsed
          if ($targetList.is(':hidden')) {
            const $header = $targetSection.find('.ttd-section-header');
            $header.attr('aria-expanded', 'true');
            $header.find('.ttd-section-arrow')
              .removeClass('dashicons-arrow-right-alt2')
              .addClass('dashicons-arrow-down-alt2');
            $targetList.show();
          }

          // Update classes
          $item.removeClass('main-entity-topic about-topic mentions-topic below-threshold-topic')
               .addClass(newTier === 'mainEntity' ? 'main-entity-topic' :
                        newTier === 'about' ? 'about-topic' :
                        newTier === 'mentions' ? 'mentions-topic' : 'below-threshold-topic');

          // Remove KD badge if not focus topic
          if (newTier !== 'mainEntity' && newTier !== 'about') {
            $item.find('.ttd-kd-badge').remove();
          }

          // Move element
          $targetList.append($item);

          // Hide "no topics" message
          $targetSection.find('.ttd-no-topics-message').hide();

          // Show empty message in source if needed
          const $sourceSection = $item.closest('.ttd-topics-section').parent()
            .find('[data-section="' + $item.data('old-tier') + '"]');
          if ($sourceSection.find('.ttd-topics-list .topic-item').length === 0) {
            $sourceSection.find('.ttd-no-topics-message').show();
          }

          updateSectionCounts();
        }

        /**
         * Fetch demand metrics.
         */
        function fetchDemandMetrics(termId, $badge) {
          if (!termId) return;

          $badge.addClass('ttd-kd-loading').html('<span class="ttd-badge-spinner"></span>');

          $.ajax({
            url: '/api/topicalboost/demand',
            type: 'GET',
            data: { term_id: termId },
            success: function(response) {
              if (response.success && response.data) {
                const tp = response.data.traffic_potential || 0;
                const kd = response.data.keyword_difficulty || 0;

                const tpFormatted = window.ttdTopicsUtils.formatCount(tp);
                const kdClass = window.ttdTopicsUtils.getKdClass(kd);
                const label = window.ttdTopicsUtils.getKdLabel(kd);

                $badge.removeClass('ttd-kd-loading ttd-kd-no-data ttd-kd-easy ttd-kd-medium ttd-kd-hard ttd-kd-very-hard')
                      .addClass(kdClass)
                      .attr('title', 'Traffic Potential: ' + tpFormatted + '\nDifficulty: ' + kd + '/100 (' + label + ')')
                      .text(tpFormatted);
              }
            },
            error: function() {
              $badge.removeClass('ttd-kd-loading').addClass('ttd-kd-no-data')
                    .attr('title', 'Failed to load').text('--');
            }
          });
        }

        /**
         * KD badge click handler (refresh).
         */
        $container.on('click', '.ttd-kd-badge', function(e) {
          e.preventDefault();
          e.stopPropagation();

          const $badge = $(this);
          if ($badge.hasClass('ttd-kd-loading')) return;

          const termId = $badge.closest('.topic-item').data('term-id');
          fetchDemandMetrics(termId, $badge);
        });

        /**
         * Update section counts.
         */
        function updateSectionCounts() {
          $container.find('.ttd-topics-section[data-section]').each(function() {
            const $section = $(this);
            const $count = $section.find('.ttd-section-count');
            const count = $section.find('.ttd-topics-list .topic-item').length;

            $count.text('(' + count + ')');
          });
        }

        /**
         * Remove manual topic.
         */
        $container.on('click', '.remove-topic', function(e) {
          e.preventDefault();

          const $button = $(this);
          const $item = $button.closest('.topic-item');
          const termId = $button.data('term-id');

          if (!termId) return;

          $item.addClass('updating');

          $.ajax({
            url: '/api/topicalboost/topics/update',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
              node_id: nodeId,
              topic_id: termId,
              remove_manual: true
            }),
            success: function(response) {
              if (response.success) {
                $item.remove();
                updateSectionCounts();
              }
            },
            error: function(xhr, status, error) {
              console.error('Error removing manual topic:', error);
            },
            complete: function() {
              $item.removeClass('updating');
            }
          });
        });

        /**
         * Run Analysis button.
         */
        $getTopicsButton.on('click', function() {
          const $button = $(this);

          if ($button.hasClass('analyzing')) return;

          $button.addClass('analyzing').prop('disabled', true);
          $topicsStatus.text('Analyzing...').addClass('analyzing').show();

          // In Drupal, this would trigger the existing analysis workflow
          // For now, just show a message
          setTimeout(function() {
            $button.removeClass('analyzing').prop('disabled', false);
            $topicsStatus.text('Analysis would be triggered here').removeClass('analyzing');
          }, 2000);
        });

        // Initialize search handlers
        if (typeof window.ttdTopicsUtils.bindSearchHandlers === 'function') {
          window.ttdTopicsUtils.bindSearchHandlers($container);
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
