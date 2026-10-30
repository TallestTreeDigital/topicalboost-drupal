<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Catches up saved descriptions in small, checkpointed pages.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_saved_description_catchup",
 *   label = @Translation("TopicalBoost Saved Description Catch-up"),
 *   max_retries = 5,
 *   retry_delay = 300
 * )
 */
class TtdSavedDescriptionCatchup extends JobTypeBase {

  public function process(Job $job) {
    try {
      $result = \Drupal::service('ttd_topics.analytics_inventory')->syncDescriptionCatchup();
      return JobResult::success('Description catch-up: ' . $result['status']);
    }
    catch (\Throwable $error) {
      return JobResult::failure('Description catch-up will retry.', PHP_INT_MAX, 300);
    }
  }

}
