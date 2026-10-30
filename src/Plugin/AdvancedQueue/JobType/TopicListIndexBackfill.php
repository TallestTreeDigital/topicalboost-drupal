<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Builds the topic list index in bounded batches.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_topic_list_index_backfill",
 *   label = @Translation("TopicalBoost topic list index"),
 *   max_retries = 10,
 *   retry_delay = 30
 * )
 */
class TopicListIndexBackfill extends JobTypeBase {
  public function process(Job $job) {
    $index = \Drupal::service('ttd_topics.topic_list_index');
    $advanced = $index->backfill();
    // A worker that did not acquire the lock leaves scheduling to its owner.
    if ($advanced && !$index->ready()) {
      $index->schedule(TRUE);
    }
    return JobResult::success();
  }
}
