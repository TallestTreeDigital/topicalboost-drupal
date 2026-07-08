<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\ttd_topics\Service\TtdSyncService;

/**
 * One-shot backfill of locally hidden Drupal topics into TopicalBoost.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_backfill_local_hidden_entities",
 *   label = @Translation("TopicalBoost Local Hidden Entities Backfill"),
 *   max_retries = 1,
 *   retry_delay = 300
 * )
 */
class TtdLocalHiddenEntitiesBackfill extends JobTypeBase {

  private const BATCH_SIZE = 100;
  private const NEXT_BATCH_DELAY = 60;
  private const RETRY_DELAY = 21600;

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    try {
      $state = \Drupal::state();
      $now = \Drupal::time()->getRequestTime();

      if ($state->get(TtdSyncService::LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY)) {
        return JobResult::success('Local hidden entity backfill already complete.');
      }

      $retry_at = (int) $state->get(TtdSyncService::LOCAL_HIDDEN_BACKFILL_RETRY_KEY, 0);
      if ($retry_at > $now) {
        return $this->enqueueNext($retry_at - $now, 'Local hidden entity backfill waiting until retry window.');
      }

      /** @var \Drupal\ttd_topics\Service\TtdSyncService $sync_service */
      $sync_service = \Drupal::service('ttd_topics.sync_service');
      $cursor = (int) $state->get(TtdSyncService::LOCAL_HIDDEN_BACKFILL_CURSOR_KEY, 0);
      $stats = $sync_service->backfillLocalHiddenEntitiesBatch(self::BATCH_SIZE, $cursor);
      $totals = $this->mergeTotals($stats);

      if ((int) ($stats['failed'] ?? 0) > 0) {
        $retry_at = $now + self::RETRY_DELAY;
        $state->set(TtdSyncService::LOCAL_HIDDEN_BACKFILL_RETRY_KEY, $retry_at);
        \Drupal::logger('ttd_topics')->warning('Local hidden entity backfill failed: @stats', [
          '@stats' => json_encode(['batch' => $stats, 'totals' => $totals]),
        ]);
        return $this->enqueueNext(self::RETRY_DELAY, 'Local hidden entity backfill failed; scheduled retry.');
      }

      $state->set(TtdSyncService::LOCAL_HIDDEN_BACKFILL_CURSOR_KEY, (int) ($stats['last_term_id'] ?? $cursor));
      if (!empty($stats['has_more'])) {
        return $this->enqueueNext(self::NEXT_BATCH_DELAY, sprintf(
          'Local hidden entity backfill sent %d rows; scheduled next batch.',
          (int) ($stats['sent'] ?? 0)
        ));
      }

      $state->delete(TtdSyncService::LOCAL_HIDDEN_BACKFILL_RETRY_KEY);
      $state->delete(TtdSyncService::LOCAL_HIDDEN_BACKFILL_CURSOR_KEY);
      $state->set(TtdSyncService::LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY, [
        'completedAt' => gmdate('c'),
        'moduleVersion' => $this->getModuleVersion(),
        'stats' => $totals,
      ]);

      if ((int) ($totals['found'] ?? 0) > 0) {
        \Drupal::logger('ttd_topics')->info('Local hidden entity backfill complete: @stats', [
          '@stats' => json_encode($totals),
        ]);
      }

      return JobResult::success(sprintf(
        'Local hidden entity backfill complete: %d sent, %d hidden, %d already hidden, %d not found.',
        (int) ($totals['sent'] ?? 0),
        (int) ($totals['hidden'] ?? 0),
        (int) ($totals['already_hidden'] ?? 0),
        (int) ($totals['not_found'] ?? 0)
      ));
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Local hidden entity backfill failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Local hidden entity backfill failed: ' . $e->getMessage());
    }
  }

  /**
   * Store cumulative stats for the one-shot backfill.
   */
  private function mergeTotals(array $stats): array {
    $state = \Drupal::state();
    $totals = $state->get(TtdSyncService::LOCAL_HIDDEN_BACKFILL_STATS_KEY, []);
    if (!is_array($totals)) {
      $totals = [];
    }

    foreach (['found', 'sent', 'hidden', 'already_hidden', 'not_found', 'failed'] as $key) {
      $totals[$key] = (int) ($totals[$key] ?? 0) + (int) ($stats[$key] ?? 0);
    }
    $totals['batches'] = (int) ($totals['batches'] ?? 0) + (((int) ($stats['found'] ?? 0) > 0) ? 1 : 0);
    $totals['lastRunAt'] = gmdate('c');

    $state->set(TtdSyncService::LOCAL_HIDDEN_BACKFILL_STATS_KEY, $totals);
    return $totals;
  }

  /**
   * Enqueue the next batch/retry of this job.
   */
  private function enqueueNext(int $delay, string $message) {
    $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
    $queue = $queue_storage->load(TtdSyncService::QUEUE_ID);
    if (!$queue) {
      return JobResult::failure('TopicalBoost analysis queue is not available for local hidden backfill.');
    }

    $queue->enqueueJob(Job::create(TtdSyncService::LOCAL_HIDDEN_BACKFILL_JOB_TYPE, []), max(0, $delay));
    return JobResult::success($message);
  }

  /**
   * Get module version for completion metadata.
   */
  private function getModuleVersion(): string {
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('ttd_topics');
    return $info['version'] ?? 'dev';
  }

}
