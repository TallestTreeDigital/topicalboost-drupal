<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Runs a bounded archive metadata batch with saved progress.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_content_url_repair",
 *   label = @Translation("TopicalBoost archive URL repair"),
 *   max_retries = 5,
 *   retry_delay = 300
 * )
 */
class TtdContentUrlRepair extends JobTypeBase {
  public function process(Job $job) {
    try {
      $state = \Drupal::service('ttd_topics.content_url_repair')->run();
      return JobResult::success('Archive URL repair status: ' . ($state['status'] ?? 'unknown'));
    }
    catch (\Throwable $error) {
      return JobResult::failure('Archive URL repair could not resume: ' . $error->getMessage());
    }
  }
}
