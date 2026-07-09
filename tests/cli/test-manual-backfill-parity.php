<?php

/**
 * Static regression checks for WordPress-parity manual-topic backfill.
 *
 * Run from the module root:
 *   php tests/cli/test-manual-backfill-parity.php
 */

$root = dirname(__DIR__, 2);

function ttd_manual_backfill_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }

  echo "PASS: {$message}\n";
}

$sync_service = file_get_contents($root . '/src/Service/TtdSyncService.php');
$backfill_job = file_get_contents($root . '/src/Plugin/AdvancedQueue/JobType/TtdLocalManualTopicsBackfill.php');
$module_file = file_get_contents($root . '/ttd_topics.module');

ttd_manual_backfill_assert(strpos($sync_service, 'backfillLocalManualTopicsBatch') !== false, 'Sync service exposes cursor-batched local manual topic backfill');
ttd_manual_backfill_assert(strpos($sync_service, "node__field_manual_topics") !== false, 'Backfill uses Drupal manual topic field tables instead of node entity loading');
ttd_manual_backfill_assert(strpos($sync_service, "taxonomy_term__field_ttd_id") !== false, 'Backfill resolves canonical TopicalBoost entity IDs from field_ttd_id');
ttd_manual_backfill_assert(strpos($sync_service, "'/telemetry/editorial-signals/manual-backfill'") !== false, 'Backfill uses the bulk manual-backfill API endpoint');
ttd_manual_backfill_assert(strpos($sync_service, "'manualEntryMethod' => 'current_state_backfill'") !== false, 'Backfill marks synthetic current-state events');

ttd_manual_backfill_assert(strpos($backfill_job, 'id = "ttd_backfill_local_manual_topics"') !== false, 'AdvancedQueue job type exists for local manual topic backfill');
ttd_manual_backfill_assert(strpos($backfill_job, 'private const BATCH_SIZE = 100;') !== false, 'Backfill job uses WordPress-sized batches');
ttd_manual_backfill_assert(strpos($backfill_job, 'private const NEXT_BATCH_DELAY = 60;') !== false, 'Backfill job schedules continuation batches with a short delay');
ttd_manual_backfill_assert(strpos($backfill_job, 'private const RETRY_DELAY = 21600;') !== false, 'Backfill job backs off failed API calls for six hours');
ttd_manual_backfill_assert(strpos($backfill_job, 'LOCAL_MANUAL_TOPIC_BACKFILL_COMPLETE_KEY') !== false, 'Backfill job records a durable completion flag');
ttd_manual_backfill_assert(strpos($backfill_job, "LOCK_NAME = 'ttd_topics.local_manual_topic_backfill'") !== false, 'Backfill job uses a lock to avoid overlapping batches');
ttd_manual_backfill_assert(strpos($backfill_job, 'hasQueuedContinuation') !== false, 'Backfill job avoids duplicate queued continuations');

ttd_manual_backfill_assert(strpos($module_file, 'LOCAL_MANUAL_TOPIC_BACKFILL_JOB_TYPE') !== false, 'Cron queues the local manual topic backfill job');
ttd_manual_backfill_assert(strpos($module_file, 'LOCAL_MANUAL_TOPIC_BACKFILL_COMPLETE_KEY') !== false, 'Cron respects the one-shot completion flag');
ttd_manual_backfill_assert(strpos($module_file, 'LOCAL_MANUAL_TOPIC_BACKFILL_RETRY_KEY') !== false, 'Cron respects the retry window');

echo "Manual topic backfill parity regression checks passed.\n";
