<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Job type for syncing API-hidden entities into local term hide state.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_sync_hidden_entities",
 *   label = @Translation("TopicalBoost Hidden Entities Sync"),
 *   max_retries = 3,
 *   retry_delay = 300
 * )
 */
class TtdHiddenEntitiesSync extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    try {
      $stats = \Drupal::service('ttd_topics.sync_service')->syncHiddenEntities();

      return JobResult::success(sprintf(
        'Hidden entities sync complete: %d hidden, %d unhidden, %d already hidden, %d not found',
        $stats['hidden'] ?? 0,
        $stats['unhidden'] ?? 0,
        $stats['already_hidden'] ?? 0,
        $stats['not_found'] ?? 0
      ));
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Hidden entities sync failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Hidden entities sync failed: ' . $e->getMessage());
    }
  }

}
