<?php

/**
 * Static regression checks for cursor-only sync upgrade behavior.
 *
 * Run from the module root:
 *   php tests/cli/test-sync-cursor-upgrade.php
 */

$root = dirname(__DIR__, 2);

function ttd_sync_cursor_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }

  echo "PASS: {$message}\n";
}

$sync_service = file_get_contents($root . '/src/Service/TtdSyncService.php');
$sync_job = file_get_contents($root . '/src/Plugin/AdvancedQueue/JobType/TtdSyncPull.php');
$install_file = file_get_contents($root . '/ttd_topics.install');
$module_file = file_get_contents($root . '/ttd_topics.module');

ttd_sync_cursor_assert(strpos($sync_service, "'after_id' => 0") !== false, 'New sync jobs start with an explicit cursor');
ttd_sync_cursor_assert(strpos($sync_service, 'if ($after_id === NULL)') !== false, 'Sync service rejects missing cursors');
ttd_sync_cursor_assert(strpos($sync_service, '$this->cancelSync();') !== false, 'Legacy sync jobs cancel active sync state');
ttd_sync_cursor_assert(strpos($sync_service, "'cancelled_legacy' => TRUE") !== false, 'Legacy sync cancellation returns a success result');
ttd_sync_cursor_assert(strpos($sync_service, "'after_id' => (int) \$response['next_after_id']") !== false, 'Follow-up sync jobs preserve API cursors');
ttd_sync_cursor_assert(strpos($sync_service, 'splitOversizedTopicPull') === false, 'Legacy offset split path is removed');

ttd_sync_cursor_assert(strpos($sync_job, "\$payload['after_id'] ?? NULL") !== false, 'Queue job reads the cursor payload');
ttd_sync_cursor_assert(strpos($sync_job, "'cancelled_legacy'") !== false, 'Queue job treats legacy cancellation as success');

ttd_sync_cursor_assert(strpos($install_file, 'function ttd_topics_update_9008()') !== false, 'Drupal update hook exists for cursor upgrade cleanup');
ttd_sync_cursor_assert(strpos($install_file, "->condition('queue_id', 'ttd_topics_analysis')") !== false, 'Cleanup is scoped to the TopicalBoost queue');
ttd_sync_cursor_assert(strpos($install_file, "->condition('type', 'ttd_sync_pull')") !== false, 'Cleanup is scoped to sync pull jobs');
ttd_sync_cursor_assert(strpos($install_file, "->condition('state', 'processing', '<>')") !== false, 'Cleanup does not delete currently processing jobs');
ttd_sync_cursor_assert(strpos($install_file, "delete('ttd_active_sync')") !== false, 'Cleanup clears active sync state once');

ttd_sync_cursor_assert(strpos($module_file, 'function ttd_topics_get_node_absolute_url') !== false, 'Stable absolute URL helper is present');
ttd_sync_cursor_assert(strpos($module_file, "'x-tb-site-url'") !== false, 'API headers include stable site URL when available');

echo "Sync cursor upgrade regression checks passed.\n";
