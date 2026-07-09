<?php

/**
 * Static regression checks for WordPress-parity hidden-topic backfill.
 *
 * Run from the module root:
 *   php tests/cli/test-hidden-backfill-parity.php
 */

$root = dirname(__DIR__, 2);

function ttd_hidden_backfill_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }

  echo "PASS: {$message}\n";
}

$sync_service = file_get_contents($root . '/src/Service/TtdSyncService.php');
$backfill_job = file_get_contents($root . '/src/Plugin/AdvancedQueue/JobType/TtdLocalHiddenEntitiesBackfill.php');
$module_file = file_get_contents($root . '/ttd_topics.module');
$drush_services = file_get_contents($root . '/drush.services.yml');

ttd_hidden_backfill_assert(strpos($sync_service, 'backfillLocalHiddenEntitiesBatch') !== false, 'Sync service exposes cursor-batched local hidden backfill');
ttd_hidden_backfill_assert(strpos($sync_service, "taxonomy_term__field_hide") !== false, 'Backfill uses Drupal field table joins instead of taxonomy entity loading');
ttd_hidden_backfill_assert(strpos($sync_service, "taxonomy_term__field_ttd_id") !== false, 'Backfill resolves canonical TopicalBoost entity IDs from field_ttd_id');
ttd_hidden_backfill_assert(strpos($sync_service, "->range(0, max(1, min(1000, \$limit)))") !== false, 'Backfill query is explicitly batched');
ttd_hidden_backfill_assert(strpos($sync_service, "'/telemetry/editorial-signals/hidden-backfill'") !== false, 'Backfill uses the bulk hidden-backfill API endpoint');

ttd_hidden_backfill_assert(strpos($backfill_job, 'id = "ttd_backfill_local_hidden_entities"') !== false, 'AdvancedQueue job type exists for local hidden backfill');
ttd_hidden_backfill_assert(strpos($backfill_job, 'private const BATCH_SIZE = 100;') !== false, 'Backfill job uses WordPress-sized batches');
ttd_hidden_backfill_assert(strpos($backfill_job, 'private const NEXT_BATCH_DELAY = 60;') !== false, 'Backfill job schedules continuation batches with a short delay');
ttd_hidden_backfill_assert(strpos($backfill_job, 'private const RETRY_DELAY = 21600;') !== false, 'Backfill job backs off failed API calls for six hours');
ttd_hidden_backfill_assert(strpos($backfill_job, 'LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY') !== false, 'Backfill job records a durable completion flag');
ttd_hidden_backfill_assert(strpos($backfill_job, "LOCK_NAME = 'ttd_topics.local_hidden_backfill'") !== false, 'Backfill job uses a lock to avoid overlapping batches');
ttd_hidden_backfill_assert(strpos($backfill_job, 'hasQueuedContinuation') !== false, 'Backfill job avoids duplicate queued continuations');

ttd_hidden_backfill_assert(strpos($module_file, 'LOCAL_HIDDEN_BACKFILL_JOB_TYPE') !== false, 'Cron queues the local hidden backfill job');
ttd_hidden_backfill_assert(strpos($module_file, 'LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY') !== false, 'Cron respects the one-shot completion flag');
ttd_hidden_backfill_assert(strpos($module_file, 'LOCAL_HIDDEN_BACKFILL_RETRY_KEY') !== false, 'Cron respects the retry window');

ttd_hidden_backfill_assert(strpos($drush_services, 'SyncLocalCurationOverridesCommand') !== false, 'Drush local curation override command is registered');

echo "Hidden backfill parity regression checks passed.\n";
