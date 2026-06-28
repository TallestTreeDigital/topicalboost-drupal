<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Job type for syncing API curation scores into local render cache.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_sync_curation_scores",
 *   label = @Translation("TopicalBoost Curation Scores Sync"),
 *   max_retries = 3,
 *   retry_delay = 300
 * )
 */
class TtdCurationScoresSync extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    try {
      $stats = \Drupal::service('ttd_topics.sync_service')->syncCurationScores();

      return JobResult::success(sprintf(
        'Curation scores sync complete: %d scores, %d updated, %d unchanged, %d removed',
        $stats['scores'] ?? 0,
        $stats['updated'] ?? 0,
        $stats['unchanged'] ?? 0,
        $stats['removed'] ?? 0
      ));
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Curation scores sync failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Curation scores sync failed: ' . $e->getMessage());
    }
  }

}
