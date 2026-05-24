(function ($, Drupal, once) {
  'use strict';

  var TOLERANCE = 0.05;
  var pollTimer = null;
  var syncStartedAt = null;
  var lastSyncResult = null;

  function fmt(n) {
    return Number(n).toLocaleString();
  }

  function synced(a, b) {
    if (a === b) return true;
    var max = Math.max(a, b);
    return max === 0 || Math.abs(a - b) / max <= TOLERANCE;
  }

  function apiGet(path) {
    return $.ajax({ url: path, type: 'GET', dataType: 'json' });
  }

  function apiPost(path, data) {
    return $.ajax({
      url: path,
      type: 'POST',
      contentType: 'application/json',
      data: data ? JSON.stringify(data) : '{}',
      dataType: 'json'
    });
  }

  Drupal.behaviors.ttdSync = {
    attach: function (context) {
      $(once('ttd-sync', '#ttd-sync-container', context)).each(function () {
        init();
      });
    }
  };

  function init() {
    apiGet('/api/topicalboost/sync/progress').done(function (response) {
      if (response.success && response.data.active) {
        showSyncInProgress(response.data);
        startPolling();
        return;
      }
      apiGet('/api/topicalboost/sync/check').done(function (result) {
        if (result.success) renderStatus(result.data);
      });
    });
  }

  function renderStatus(data) {
    var local = data.local;
    var api = data.api;
    var diff = data.diff;

    $('#sync-site-topics').text(fmt(local.topicCount));
    $('#sync-api-topics').text(fmt(api.topics));
    $('#sync-site-rels').text(fmt(local.relationshipCount));
    $('#sync-api-rels').text(fmt(api.relationships));

    var ts = synced(local.topicCount, api.topics);
    var rs = synced(local.relationshipCount, api.relationships);
    var allSynced = ts && rs;

    $('#sync-status-topics').html(ts ? '<span class="synced">&#10003;</span>' : '<span class="unsynced">&#9679;</span>');
    $('#sync-status-rels').html(rs ? '<span class="synced">&#10003;</span>' : '<span class="unsynced">&#9679;</span>');

    if (allSynced) {
      $('.ttd-sync-dot').addClass('is-synced').removeClass('is-unsynced');
      $('.ttd-sync-label').text('Data in sync');
    } else {
      $('.ttd-sync-dot').addClass('is-unsynced').removeClass('is-synced');
      $('.ttd-sync-label').text('Data out of sync');
    }
    $('#ttd-sync-status-label').show();

    var canSync = false;

    if (diff.topics > 0) {
      $('#sync-hint-topics').text('+' + fmt(diff.topics) + ' to pull from API').show();
      canSync = true;
    } else if (!ts) {
      $('#sync-hint-topics').text(fmt(local.topicCount - api.topics) + ' extra on site (local only)').show();
    } else {
      $('#sync-hint-topics').hide();
    }

    if (diff.relationships > 0) {
      $('#sync-hint-rels').text('+' + fmt(diff.relationships) + ' to pull from API').show();
      canSync = true;
    } else if (!rs) {
      $('#sync-hint-rels').text(fmt(local.relationshipCount - api.relationships) + ' extra on site (local only)').show();
    } else {
      $('#sync-hint-rels').hide();
    }

    $('#ttd-sync-summary').hide();
    $('#ttd-sync-btn').toggle(canSync);
  }

  function startSync() {
    $('#ttd-sync-btn').hide();
    $('#ttd-sync-summary').hide();
    $('#ttd-sync-result').hide();
    $('.ttd-sync-card-hint').hide();
    $('#ttd-sync-cancel').show();
    $('#ttd-sync-progress').show();
    $('#sync-progress-text').text('Starting...');
    $('#sync-progress-stage').text('');
    $('.ttd-sync-card').addClass('is-syncing');

    syncStartedAt = Date.now();

    apiPost('/api/topicalboost/sync/start').done(function (result) {
      if (!result.success) {
        showResult('error', 'Failed to start sync: ' + ((result.data && result.data.message) || 'Unknown error'));
        syncStartedAt = null;
        return;
      }
      startPolling();
    }).fail(function () {
      showResult('error', 'Failed to start sync.');
    });
  }

  function startPolling() {
    pollTimer = setInterval(function () {
      apiGet('/api/topicalboost/sync/progress').done(function (result) {
        if (!result.success) return;
        var p = result.data;

        if (!p.active) {
          stopPolling();
          showResult('success', 'Sync complete.');
          refreshStatus();
          return;
        }

        if (p.total_jobs && p.completed !== undefined) {
          var done = (p.completed || 0) + (p.failed || 0);
          var pct = p.total_jobs > 0 ? Math.round((done / p.total_jobs) * 100) : 0;
          $('#sync-progress-fill').css('width', pct + '%');
          $('#sync-progress-text').text(pct + '%');
        }
      });
    }, 3000);
  }

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function cancelSync() {
    stopPolling();
    apiPost('/api/topicalboost/sync/cancel').done(function () {
      $('#ttd-sync-progress').hide();
      $('#ttd-sync-cancel').hide();
      showResult('info', 'Sync cancelled.');
      refreshStatus();
    });
  }

  function refreshStatus() {
    setTimeout(function () {
      $('.ttd-sync-dot').removeClass('is-synced is-unsynced');
      $('#ttd-sync-summary').hide();
      $('#ttd-sync-btn').hide();

      apiGet('/api/topicalboost/sync/check').done(function (result) {
        if (result.success) {
          renderStatus(result.data);
          if (lastSyncResult) {
            showResult(lastSyncResult.type, lastSyncResult.msg);
          }
        }
      });
    }, 2000);
  }

  function showSyncInProgress(progress) {
    syncStartedAt = progress.started_at ? progress.started_at * 1000 : Date.now();
    $('.ttd-sync-dot').addClass('is-unsynced');
    $('.ttd-sync-label').text('Sync in progress');
    $('#ttd-sync-status-label').show();
    $('#ttd-sync-progress').show();
    $('#ttd-sync-cancel').show();
  }

  function showResult(type, message) {
    $('.ttd-sync-card').removeClass('is-syncing');
    var classes = {
      success: 'messages messages--status',
      error: 'messages messages--error',
      warning: 'messages messages--warning',
      info: 'messages messages--status'
    };
    $('#ttd-sync-result')
      .html('<div class="' + classes[type] + '">' + message + '</div>')
      .show();
    $('#ttd-sync-progress').hide();
    $('#ttd-sync-cancel').hide();
    lastSyncResult = { type: type, msg: message };
  }

  $(document).on('click', '#ttd-sync-btn', startSync);
  $(document).on('click', '#ttd-sync-cancel', cancelSync);

})(jQuery, Drupal, once);
