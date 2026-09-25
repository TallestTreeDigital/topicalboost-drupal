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
        const $topicsListContainer = $container.find('#ttd-topics-list-container');
        const $topicsSearchContainer = $container.find('.ttd-topics-search-container');
        const hasBeenAnalyzed = !!(settings.ttdTopics && settings.ttdTopics.hasBeenAnalyzed);
        $container.on('click', '.ttd-wide-rejected-toggle', function(e) {
          e.preventDefault();
          e.stopPropagation();

          const $toggle = $(this);
          const $list = $toggle.closest('.ttd-topics-section').find('.ttd-topics-list');
          const hiddenCount = parseInt($toggle.data('count'), 10) || 0;
          $list.toggleClass('show-rejected');
          $toggle.text($list.hasClass('show-rejected') ? Drupal.t('hide') : hiddenCount + ' ' + Drupal.t('hidden'));
        });

        if (typeof window.ttdHasBeenAnalyzed === 'undefined') {
          window.ttdHasBeenAnalyzed = hasBeenAnalyzed;
        } else if (hasBeenAnalyzed) {
          window.ttdHasBeenAnalyzed = true;
        }

        // Track dragged item
        let draggedItem = null;
        let draggedTtdId = null;
        let draggedTermId = null;
        const draggableTopicSelector = '.topic-item.api-topic, .topic-item.manual-topic';

        // Tell the meta generator that saved topics changed, so it reloads its
        // About topics instead of keeping the list from page load.
        function notifyTopicsChanged() {
          $(document).trigger('ttd:tierUpdated', {
            hasFocusTopics: $container.find('.ttd-about-section .ttd-topics-list .topic-item').length > 0
          });
        }

        function getSectionLimit($section) {
          const tier = $section.data('section');

          if (tier === 'about') {
            return parseInt($section.data('max-recommended'), 10) || 5;
          }

          return null;
        }

        function getSectionLimitLabel($section) {
          const maxAllowed = getSectionLimit($section);

          return maxAllowed === null ? '' : maxAllowed + ' max';
        }

        function resetSectionWarning($section) {
          const $warning = $section.find('.ttd-section-warning');
          const maxAllowed = getSectionLimit($section);

          if (!$warning.length || maxAllowed === null) {
            return;
          }

          const count = $section.find('.ttd-topics-list .topic-item').length;
          $warning.text(getSectionLimitLabel($section)).removeClass('ttd-warning-flash');

          if (count > maxAllowed) {
            $warning.show();
          }
          else {
            $warning.hide();
          }
        }

        function flashFullWarning($section) {
          const $warning = $section.find('.ttd-section-warning');

          if ($warning.length) {
            $warning.text(Drupal.t('FULL')).addClass('ttd-warning-flash').show();
          }
        }

        function isSectionAtCapacity($section, item) {
          const maxAllowed = getSectionLimit($section);

          if (maxAllowed === null) {
            return false;
          }

          if (item && $.contains($section.get(0), item)) {
            return false;
          }

          return $section.find('.ttd-topics-list .topic-item').length >= maxAllowed;
        }

        function showCapacityStatus($section) {
          if (!$topicsStatus.length) {
            return;
          }

          $topicsStatus
            .text(Drupal.t('About is full. Move one topic out before adding another one.'))
            .removeClass('analyzing success')
            .addClass('error')
            .show();
        }

        /**
         * Initialize drag-and-drop.
         */
        function initDragDrop() {
          $container.find(draggableTopicSelector).attr('draggable', 'true');
        }

        initDragDrop();

        /**
         * Warn before saving when the post has not had topics reviewed yet.
         */
        function initPreSaveWarning() {
          if (window.ttdHasBeenAnalyzed) {
            return;
          }

          const $form = $container.closest('form');
          if (!$form.length || $form.data('ttd-pre-save-warning')) {
            return;
          }

          $form.data('ttd-pre-save-warning', true);
          $form.on('submit.ttdPreSaveWarning', function(e) {
            if (window.ttdHasBeenAnalyzed || $form.data('ttd-pre-save-confirmed')) {
              return true;
            }

            const message = Drupal.t('Topics have not been reviewed yet. Run analysis to set your main focus and generate SEO titles.');
            const saveAnyway = window.confirm(message + '\n\n' + Drupal.t('Save anyway?'));
            if (!saveAnyway) {
              e.preventDefault();
              e.stopImmediatePropagation();
              if ($container.offset()) {
                $('html, body').animate({ scrollTop: $container.offset().top - 80 }, 200);
              }
              return false;
            }

            $form.data('ttd-pre-save-confirmed', true);
            return true;
          });
        }

        initPreSaveWarning();

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
                notifyTopicsChanged();
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
        $container.on('dragstart', draggableTopicSelector, function(e) {
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
        $container.on('dragend', draggableTopicSelector, function() {
          $(this).removeClass('dragging');
          $container.find('.ttd-topics-section')
            .removeClass('drop-zone-hover drop-zone-active drop-zone-blocked');
          $container.find('.ttd-section-warning.ttd-warning-flash').each(function() {
            resetSectionWarning($(this).closest('.ttd-topics-section'));
          });

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
          if (isSectionAtCapacity($section, draggedItem)) {
            e.originalEvent.dataTransfer.dropEffect = 'none';
            $section.removeClass('drop-zone-hover').addClass('drop-zone-blocked');
            flashFullWarning($section);
            return;
          }

          e.originalEvent.dataTransfer.dropEffect = 'move';
          $section.removeClass('drop-zone-blocked');
          resetSectionWarning($section);

          if (!draggedItem || !$.contains($section.get(0), draggedItem)) {
            $section.addClass('drop-zone-hover');
          }
        });

        /**
         * Drag leave.
         */
        $container.on('dragleave', '.ttd-topics-section[data-section]', function(e) {
          if (!$.contains(this, e.relatedTarget)) {
            const $section = $(this);
            $section.removeClass('drop-zone-hover drop-zone-blocked');
            resetSectionWarning($section);
          }
        });

        /**
         * Drop.
         */
        $container.on('drop', '.ttd-topics-section[data-section]', function(e) {
          e.preventDefault();

          const $targetSection = $(this);
          const newTier = $targetSection.data('section');

          if (!draggedItem) {
            return;
          }

          const $sourceSection = $(draggedItem).closest('.ttd-topics-section');
          const oldTier = $sourceSection.data('section');

          if (isSectionAtCapacity($targetSection, draggedItem)) {
            e.originalEvent.dataTransfer.dropEffect = 'none';
            $targetSection.removeClass('drop-zone-hover').addClass('drop-zone-blocked');
            flashFullWarning($targetSection);
            showCapacityStatus($targetSection);
            return;
          }

          $targetSection.removeClass('drop-zone-hover drop-zone-blocked');
          resetSectionWarning($targetSection);

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
                  notifyTopicsChanged();
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
                if (newTier === 'about') {
                  let $badge = $item.find('.ttd-kd-badge');
                  if (!$badge.length) {
                    const $insertAfter = $item.find('input[type="checkbox"]').length ?
                      $item.find('input[type="checkbox"]') : $item.find('.remove-topic');
                    $insertAfter.after('<span class="ttd-kd-badge ttd-kd-loading"><span class="ttd-badge-spinner"></span></span>');
                    $badge = $item.find('.ttd-kd-badge');
                  }

                  if (response.data && response.data.demand_metrics &&
                      !response.data.demand_metrics.pending &&
                      !response.data.demand_metrics.refreshing) {
                    renderDemandMetrics($badge, response.data.demand_metrics);
                  }
                  else {
                    fetchDemandMetrics(draggedTermId, $badge);
                  }
                }

                notifyTopicsChanged();
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
          const $sourceSection = $item.closest('.ttd-topics-section');
          const $sourceList = $sourceSection.find('.ttd-topics-list');
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
               .addClass(newTier === 'about' ? 'about-topic' :
                        newTier === 'mentions' ? 'mentions-topic' : 'below-threshold-topic');

          // Remove KD badge if not focus topic
          if (newTier !== 'about') {
            $item.find('.ttd-kd-badge').remove();
          }

          // Move element
          $targetList.append($item);

          // Hide "no topics" message
          $targetSection.find('.ttd-no-topics-message').hide();

          // Show empty message in source if needed
          if ($sourceList.find('.topic-item').length === 0) {
            $sourceSection.find('.ttd-no-topics-message').show();
          }

          resetSectionWarning($sourceSection);
          resetSectionWarning($targetSection);
          updateSectionCounts();
        }

        /**
         * Fetch demand metrics.
         */
        function getDemandMetricsPollDelaySeconds(retryAfter, pollAttempt) {
          const serverDelay = Math.max(1, parseInt(retryAfter, 10) || 2);
          return Math.min(8, Math.max(serverDelay, 2 + pollAttempt));
        }

        function fetchDemandMetrics(termId, $badge, completeCallback, options) {
          if (!termId) {
            if (typeof completeCallback === 'function') {
              completeCallback();
            }
            return;
          }

          options = options || {};
          const pollAttempt = options.pollAttempt || 0;
          const maxPolls = options.maxPolls || 10;
          const pollToken = options.pollToken || (Date.now() + '-' + Math.random());

          if (pollAttempt === 0) {
            $badge.data('demandPollToken', pollToken);
          }

          // Keep stale metrics visible while a background refresh is queued.
          if (!$badge.hasClass('ttd-kd-stale')) {
            renderDemandPending($badge);
          }

          $.ajax({
            url: '/api/topicalboost/demand',
            type: 'GET',
            data: {
              term_id: termId,
              force_refresh: pollAttempt > 0 ? 1 : 0
            },
            success: function(response) {
              if ($badge.data('demandPollToken') !== pollToken) {
                return;
              }

              const data = response && response.data ? response.data : {};
              const isPending = !!(response && response.success && data.pending);
              const hasMetrics = !!(response && response.success &&
                data.keyword_difficulty !== undefined &&
                data.traffic_potential !== undefined);

              if (hasMetrics) {
                renderDemandMetrics($badge, data);
              }

              if ((isPending || (hasMetrics && data.refreshing)) && pollAttempt < maxPolls) {
                const retrySeconds = getDemandMetricsPollDelaySeconds(data.retry_after_seconds, pollAttempt);
                window.setTimeout(function() {
                  if ($badge.data('demandPollToken') !== pollToken) return;
                  fetchDemandMetrics(termId, $badge, completeCallback, {
                    pollAttempt: pollAttempt + 1,
                    maxPolls: maxPolls,
                    pollToken: pollToken
                  });
                }, retrySeconds * 1000);
                return;
              }

              if (data.cooldown) {
                renderDemandCooldown($badge, data.retry_after_seconds);
              }
              else if (isPending) {
                renderNoDemandData($badge, 'Demand metrics are taking longer than expected. Click to retry.');
              }
              else if (!hasMetrics) {
                renderNoDemandData($badge, 'No demand data available');
              }

              if (typeof completeCallback === 'function') {
                completeCallback();
              }
            },
            error: function(xhr) {
              if ($badge.data('demandPollToken') !== pollToken) {
                return;
              }
              if (xhr && xhr.status === 503) {
                renderDemandCooldown($badge);
              }
              else {
                renderNoDemandData($badge, 'Failed to load');
              }
              if (typeof completeCallback === 'function') {
                completeCallback();
              }
            }
          });
        }

        /**
         * Render demand metrics in a topic badge.
         */
        function renderDemandMetrics($badge, metrics) {
          if (metrics && metrics.cooldown) {
            renderDemandCooldown($badge, metrics.retry_after_seconds);
            return;
          }

          const tp = metrics && metrics.traffic_potential ? parseInt(metrics.traffic_potential, 10) : 0;
          const kd = metrics && metrics.keyword_difficulty ? parseInt(metrics.keyword_difficulty, 10) : 0;

          if (!tp) {
            renderNoDemandData($badge, 'No demand data available');
            return;
          }

          const tpFormatted = window.ttdTopicsUtils.formatCount(tp);
          const kdClass = window.ttdTopicsUtils.getKdClass(kd);
          const label = window.ttdTopicsUtils.getKdLabel(kd);

          const staleNote = metrics.stale ? '\nRefreshing in the background…' : '';

          $badge.removeClass('ttd-kd-loading ttd-kd-no-data ttd-kd-stale ttd-kd-easy ttd-kd-medium ttd-kd-hard ttd-kd-very-hard')
                .addClass(kdClass)
                .toggleClass('ttd-kd-stale', !!metrics.stale)
                .attr('title', 'Estimated traffic opportunity: ' + tpFormatted + '\nDifficulty: ' + kd + '/100 (' + label + ')' + staleNote)
                .text(tpFormatted);
        }

        /**
         * Keep the badge in a non-blocking loading state while the API worker runs.
         */
        function renderDemandPending($badge) {
          $badge.removeClass('ttd-kd-no-data ttd-kd-stale ttd-kd-easy ttd-kd-medium ttd-kd-hard ttd-kd-very-hard')
                .addClass('ttd-kd-loading')
                .attr('title', 'Fetching demand metrics in the background…')
                .html('<span class="ttd-badge-spinner"></span>');
        }

        /**
         * Render a temporary unavailable state for demand metrics cooldowns.
         */
        function renderDemandCooldown($badge, retryAfter) {
          const retryText = retryAfter ? '\nRetry after about ' + retryAfter + ' seconds.' : '';
          $badge.removeClass('ttd-kd-loading ttd-kd-stale ttd-kd-easy ttd-kd-medium ttd-kd-hard ttd-kd-very-hard')
                .addClass('ttd-kd-no-data')
                .attr('title', 'Demand metrics temporarily unavailable.' + retryText + '\n\nClick to retry later')
                .text('--');
        }

        /**
         * Render the badge when demand metrics are unavailable.
         */
        function renderNoDemandData($badge, title) {
          $badge.removeClass('ttd-kd-loading ttd-kd-stale ttd-kd-easy ttd-kd-medium ttd-kd-hard ttd-kd-very-hard')
                .addClass('ttd-kd-no-data')
                .attr('title', title)
                .text('--');
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
         * Auto-fetch demand metrics for focus-topic badges rendered without cached data.
         */
        function runAutoFetchDemandMetrics() {
          const $refreshBadges = $container.find('.ttd-kd-badge.ttd-kd-no-data, .ttd-kd-badge.ttd-kd-stale');
          if (!$refreshBadges.length) {
            return;
          }

          let fetchIndex = 0;

          function fetchNextMetric() {
            if (fetchIndex >= $refreshBadges.length) {
              return;
            }

            const $badge = $refreshBadges.eq(fetchIndex);
            fetchIndex++;

            if (!$badge.length || $badge.hasClass('ttd-kd-loading')) {
              fetchNextMetric();
              return;
            }

            const termId = $badge.closest('.topic-item').data('term-id');
            fetchDemandMetrics(termId, $badge, fetchNextMetric);
          }

          fetchNextMetric();
          fetchNextMetric();
        }

        /**
         * Update section counts.
         */
        function updateSectionCounts() {
          $container.find('.ttd-topics-section[data-section]').each(function() {
            const $section = $(this);
            const $count = $section.find('.ttd-section-count');
            const count = $section.find('.ttd-topics-list .topic-item').length;

            $count.text('(' + count + ')');
            resetSectionWarning($section);
          });
        }

        updateSectionCounts();

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
                notifyTopicsChanged();
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
        let analysisPollInterval = null;

        function stopAnalysisPolling() {
          if (analysisPollInterval) {
            clearInterval(analysisPollInterval);
            analysisPollInterval = null;
          }
        }

        function setAnalysisStatus(message, state) {
          $topicsStatus
            .removeClass('analyzing success error')
            .addClass(state || '')
            .text(message)
            .css('display', '');
        }

        function setAnalysisBusy(isBusy, clearTopics) {
          $container.toggleClass('analysis-in-progress', isBusy);
          $container.attr('aria-busy', isBusy ? 'true' : 'false');

          if (isBusy && clearTopics) {
            $container.find('.ttd-topics-section .ttd-topics-list').empty();
          }

          if (isBusy) {
            $topicsListContainer.hide().attr('hidden', 'hidden');
            $topicsSearchContainer.hide().attr('hidden', 'hidden');
          }
          else {
            $topicsListContainer.show().removeAttr('hidden');
            $topicsSearchContainer.show().removeAttr('hidden');
          }

          $searchInput.val('').prop('disabled', isBusy);
          $searchResults.empty().hide();
        }

        function startAnalysisPolling($button) {
          stopAnalysisPolling();
          setAnalysisBusy(true, true);

          let attempts = 0;
          const maxAttempts = 180; // 15 minutes at 5-second intervals.

          const poll = function() {
            attempts++;

            $.ajax({
              url: '/ttd-topics/check-analysis-status/' + nodeId,
              type: 'GET',
              timeout: 5000,
              success: function(response) {
                if (response && response.completed) {
                  stopAnalysisPolling();
                  window.ttdHasBeenAnalyzed = true;
                  setAnalysisStatus(Drupal.t('Analysis complete. Refreshing topics...'), 'success');

                  setTimeout(function() {
                    window.location.reload();
                  }, 1200);
                  return;
                }

                if (response && response.error) {
                  stopAnalysisPolling();
                  setAnalysisStatus(response.message || Drupal.t('Analysis failed. You can retry.'), 'error');
                  setAnalysisBusy(false);
                  $button.removeClass('analyzing').prop('disabled', false);
                  return;
                }

                setAnalysisStatus(Drupal.t('Analysis in progress. This page will refresh when topics are ready.'), 'analyzing');

                if (attempts >= maxAttempts) {
                  stopAnalysisPolling();
                  setAnalysisStatus(Drupal.t('Analysis is taking longer than expected. Refreshing to check status...'), 'analyzing');
                  setTimeout(function() {
                    window.location.reload();
                  }, 1200);
                }
              },
              error: function() {
                if (attempts >= maxAttempts) {
                  stopAnalysisPolling();
                  setAnalysisStatus(Drupal.t('Unable to confirm analysis status. Refreshing to check status...'), 'error');
                  setTimeout(function() {
                    window.location.reload();
                  }, 1200);
                }
              }
            });
          };

          poll();
          analysisPollInterval = setInterval(poll, 5000);
        }

        $getTopicsButton.on('click', function() {
          const $button = $(this);
          let pollingStarted = false;

          if ($button.hasClass('analyzing')) return;

          $button.addClass('analyzing').prop('disabled', true);
          setAnalysisStatus(Drupal.t('Analysis in progress. This page will refresh when topics are ready.'), 'analyzing');
          setAnalysisBusy(true, false);

          $.ajax({
            url: '/api/topicalboost/analyze-node/' + nodeId,
            type: 'POST',
            contentType: 'application/json',
            success: function(response) {
              if (response.success) {
                setAnalysisStatus(Drupal.t('Analysis in progress. This page will refresh when topics are ready.'), 'analyzing');
                pollingStarted = true;
                startAnalysisPolling($button);

                if (response.data && response.data.changed) {
                  const $changedField = $('input[name="changed"]');
                  if ($changedField.length) {
                    $changedField.val(response.data.changed);
                  }
                }
              }
              else {
                setAnalysisStatus(response.message || Drupal.t('Unable to queue analysis.'), 'error');
              }
            },
            error: function(xhr) {
              const response = xhr.responseJSON || {};
              setAnalysisStatus(response.message || Drupal.t('Unable to queue analysis.'), 'error');
            },
            complete: function() {
              if (!pollingStarted) {
                setAnalysisBusy(false);
                $button.removeClass('analyzing').prop('disabled', false);
              }
            }
          });
        });

        // Initialize search handlers
        if (typeof window.ttdTopicsUtils.bindSearchHandlers === 'function') {
          window.ttdTopicsUtils.bindSearchHandlers($container);
        }

        window.ttdRunAutoFetchDemandMetrics = runAutoFetchDemandMetrics;
        runAutoFetchDemandMetrics();
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
