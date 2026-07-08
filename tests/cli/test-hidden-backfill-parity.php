<?php

/**
 * Static regression checks for hidden-topic local backfill.
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

ttd_hidden_backfill_assert(strpos($sync_service, 'backfillLocalHiddenEntitiesBatch') !== FALSE, 'Sync service exposes cursor-batched local hidden backfill');
ttd_hidden_backfill_assert(strpos($sync_service, "taxonomy_term__field_hide") !== FALSE, 'Backfill uses Drupal field table joins instead of taxonomy entity loading');
ttd_hidden_backfill_assert(strpos($sync_service, "taxonomy_term__field_ttd_id") !== FALSE, 'Backfill resolves canonical TopicalBoost entity IDs from field_ttd_id');
ttd_hidden_backfill_assert(strpos($sync_service, "->range(0, max(1, min(1000, \$limit)))") !== FALSE, 'Backfill query is explicitly batched');
ttd_hidden_backfill_assert(strpos($sync_service, "'/telemetry/editorial-signals/hidden-backfill'") !== FALSE, 'Backfill uses the bulk hidden-backfill API endpoint');

ttd_hidden_backfill_assert(strpos($backfill_job, 'id = "ttd_backfill_local_hidden_entities"') !== FALSE, 'AdvancedQueue job type exists for local hidden backfill');
ttd_hidden_backfill_assert(strpos($backfill_job, 'private const BATCH_SIZE = 100;') !== FALSE, 'Backfill job uses WordPress-sized batches');
ttd_hidden_backfill_assert(strpos($backfill_job, 'private const NEXT_BATCH_DELAY = 60;') !== FALSE, 'Backfill job schedules continuation batches with a short delay');
ttd_hidden_backfill_assert(strpos($backfill_job, 'private const RETRY_DELAY = 21600;') !== FALSE, 'Backfill job backs off failed API calls for six hours');
ttd_hidden_backfill_assert(strpos($backfill_job, 'LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY') !== FALSE, 'Backfill job records a durable completion flag');

ttd_hidden_backfill_assert(strpos($module_file, 'LOCAL_HIDDEN_BACKFILL_JOB_TYPE') !== FALSE, 'Cron queues the local hidden backfill job');
ttd_hidden_backfill_assert(strpos($module_file, 'LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY') !== FALSE, 'Cron respects the one-shot completion flag');
ttd_hidden_backfill_assert(strpos($module_file, 'LOCAL_HIDDEN_BACKFILL_RETRY_KEY') !== FALSE, 'Cron respects the retry window');

echo "Hidden backfill parity regression checks passed.\n";
