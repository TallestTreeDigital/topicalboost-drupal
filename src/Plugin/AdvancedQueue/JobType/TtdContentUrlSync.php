<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Refreshes analyzed content metadata after a node or alias save.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_content_url_sync",
 *   label = @Translation("TopicalBoost Content URL Sync"),
 *   max_retries = 5,
 *   retry_delay = 300
 * )
 */
class TtdContentUrlSync extends JobTypeBase {

  public function process(Job $job) {
    try {
      $result = \Drupal::service('ttd_topics.analysis_service')->syncContentUrl(
        (int) ($job->getPayload()['node_id'] ?? 0),
      );
      return JobResult::success('Content URL sync complete: ' . json_encode($result));
    }
    catch (\Throwable $e) {
      return JobResult::failure('Content URL sync failed: ' . $e->getMessage());
    }
  }

}
