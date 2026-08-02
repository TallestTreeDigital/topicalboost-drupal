/**
 * @file
 * Contextual site-wide topic detection action for the node editor.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  let alwaysCheckEntityIds = new Set();
  let feedbackTimer = null;
  let nodeId = null;

  function canManage() {
    return !!(drupalSettings.ttdTopics && drupalSettings.ttdTopics.canManageAlwaysCheck);
  }

  Drupal.behaviors.ttdEditorAlwaysCheck = {
    attach: function (context) {
      $('.ttd-topics-container', context).once('ttd-editor-always-check').each(function() {
        if (!canManage()) return;

        nodeId = parseInt($(this).attr('data-node-id'), 10) || null;
        loadAlwaysCheckTopics();

        $(this).on('click', '.ttd-always-check-dismiss', function() {
          hideFeedback();
        });
      });
    }
  };

  function loadAlwaysCheckTopics() {
    $.ajax({
      url: '/api/topicalboost/watchlist',
      type: 'GET',
      dataType: 'json'
    }).done(function(response) {
      const items = response && response.success && response.data ? response.data.items : [];
      alwaysCheckEntityIds = new Set((items || []).map(function(item) {
        return parseInt(item.entityId, 10);
      }).filter(Boolean));
    });
  }

  function offer(entityId, label, description) {
    entityId = parseInt(entityId, 10);
    label = String(label || '').trim();
    description = String(description || '').trim();
    if (!entityId || !label || !canManage()) return;

    if (alwaysCheckEntityIds.has(entityId)) {
      showMessage(label + ' was added to this post and is already checked across the site.');
      return;
    }

    const $feedback = resetFeedback();
    $('<span class="ttd-always-check-message"></span>')
      .text(label + ' was added to this post.')
      .appendTo($feedback);

    $('<button type="button" class="button-link ttd-always-check-action"></button>')
      .text(Drupal.t('Add to Priority Topics'))
      .on('click', function() {
        if (description) {
          addToAlwaysCheck(entityId, label, $(this), description);
          return;
        }
        requestDescription(entityId, label);
      })
      .appendTo($feedback);

    appendDismiss($feedback);
    scheduleHide(12000);
  }

  function requestDescription(entityId, label) {
    const $feedback = resetFeedback().addClass('is-description-required');
    const $form = $('<div class="ttd-priority-description-form"></div>').appendTo($feedback);

    $('<strong class="ttd-priority-description-title"></strong>')
      .text(Drupal.t('Describe what should count as “@topic”', {'@topic': label}))
      .appendTo($form);
    $('<p class="ttd-priority-description-help"></p>')
      .text(Drupal.t('This tells TopicalBoost what the topic means and prevents false matches based only on its name.'))
      .appendTo($form);

    const $description = $('<textarea class="ttd-priority-description-input" rows="2" maxlength="1024" aria-label="' + Drupal.t('Priority Topic description') + '" placeholder="' + Drupal.t('Describe the meaning, related concepts, or conditions that should count.') + '"></textarea>')
      .appendTo($form);
    const $actions = $('<div class="ttd-priority-description-actions"></div>').appendTo($form);
    const $submit = $('<button type="button" class="button button--primary ttd-priority-description-submit" disabled></button>')
      .text(Drupal.t('Add Priority Topic'))
      .on('click', function() {
        const value = $description.val().trim();
        if (!value) return;
        addToAlwaysCheck(entityId, label, $submit, value);
      })
      .appendTo($actions);
    $('<button type="button" class="button-link ttd-priority-description-cancel"></button>')
      .text(Drupal.t('Cancel'))
      .on('click', hideFeedback)
      .appendTo($actions);

    $description.on('input', function() {
      $submit.prop('disabled', !$(this).val().trim());
    }).trigger('focus');
    appendDismiss($feedback);
  }

  function addToAlwaysCheck(entityId, label, $button, description) {
    $button.prop('disabled', true).text(Drupal.t('Adding...'));

    $.ajax({
      url: '/api/topicalboost/watchlist/add',
      type: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({
        entity_id: entityId,
        label: label,
        description: description,
        post_id: nodeId,
        surface: 'editor'
      })
    }).done(function(response) {
      if (!response || !response.success) {
        showError(Drupal.t('Could not update Priority Topics.'));
        return;
      }

      alwaysCheckEntityIds.add(entityId);
      const $feedback = resetFeedback();
      $('<span class="ttd-always-check-message"></span>')
        .text(label + ' ' + Drupal.t('will be checked in future analyses across this site.'))
        .appendTo($feedback);

      $('<button type="button" class="button-link ttd-always-check-undo"></button>')
        .text(Drupal.t('Undo'))
        .on('click', function() {
          removeFromAlwaysCheck(entityId, label, $(this));
        })
        .appendTo($feedback);

      appendDismiss($feedback);
      scheduleHide(8000);
    }).fail(function() {
      showError(Drupal.t('Could not update Priority Topics.'));
    });
  }

  function removeFromAlwaysCheck(entityId, label, $button) {
    $button.prop('disabled', true).text(Drupal.t('Undoing...'));

    $.ajax({
      url: '/api/topicalboost/watchlist/remove',
      type: 'POST',
      contentType: 'application/json',
      dataType: 'json',
      data: JSON.stringify({
        entity_id: entityId,
        post_id: nodeId,
        surface: 'editor'
      })
    }).done(function(response) {
      if (!response || !response.success) {
        showError(Drupal.t('Could not undo this change.'));
        return;
      }

      alwaysCheckEntityIds.delete(entityId);
      showMessage(label + ' ' + Drupal.t('is no longer checked across the site.'));
    }).fail(function() {
      showError(Drupal.t('Could not undo this change.'));
    });
  }

  function resetFeedback() {
    window.clearTimeout(feedbackTimer);
    return $('#ttd-editor-priority-feedback')
      .empty()
      .removeClass('is-error is-description-required')
      .addClass('is-visible');
  }

  function appendDismiss($feedback) {
    $('<button type="button" class="ttd-always-check-dismiss" aria-label="' + Drupal.t('Dismiss') + '"></button>')
      .html('&times;')
      .appendTo($feedback);
  }

  function showMessage(message) {
    const $feedback = resetFeedback();
    $('<span class="ttd-always-check-message"></span>').text(message).appendTo($feedback);
    appendDismiss($feedback);
    scheduleHide(6000);
  }

  function showError(message) {
    const $feedback = resetFeedback().addClass('is-error');
    $('<span class="ttd-always-check-message"></span>').text(message).appendTo($feedback);
    appendDismiss($feedback);
    scheduleHide(8000);
  }

  function scheduleHide(delay) {
    feedbackTimer = window.setTimeout(hideFeedback, delay);
  }

  function hideFeedback() {
    window.clearTimeout(feedbackTimer);
    $('#ttd-editor-priority-feedback').removeClass('is-visible is-error').empty();
  }

  window.ttdAlwaysCheckTopics = { offer: offer };
})(jQuery, Drupal, drupalSettings);
