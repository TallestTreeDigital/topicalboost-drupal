<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Sends one bounded publish-date batch. Saved progress owns retries.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_publish_date_catchup",
 *   label = @Translation("TopicalBoost publish date catch-up"),
 *   max_retries = 3,
 *   retry_delay = 300
 * )
 */
class TtdPublishDateCatchup extends JobTypeBase {
  public function process(Job $job) {
    try {
      $result = \Drupal::service('ttd_topics.publish_dates')->run();
      return JobResult::success('Publish date catch-up: ' . ($result['status'] ?? 'pending'));
    }
    catch (\Throwable $error) {
      return JobResult::failure('Publish date catch-up could not resume.');
    }
  }
}
