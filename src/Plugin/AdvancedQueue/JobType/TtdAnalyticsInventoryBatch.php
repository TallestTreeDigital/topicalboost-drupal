<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\ttd_topics\Service\AnalyticsInventoryService;

/**
 * Sends one bounded Drupal Analytics inventory page.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_analytics_inventory_batch",
 *   label = @Translation("TopicalBoost Analytics Inventory Batch"),
 *   max_retries = 5,
 *   retry_delay = 300
 * )
 */
class TtdAnalyticsInventoryBatch extends JobTypeBase {

  public function process(Job $job) {
    $payload = $job->getPayload();
    try {
      $result = \Drupal::service('ttd_topics.analytics_inventory')->syncBatch(
        (string) ($payload['sync_id'] ?? ''),
        (int) ($payload['page'] ?? 1),
        (int) ($payload['per_page'] ?? AnalyticsInventoryService::BATCH_SIZE),
      );
      return JobResult::success('Analytics inventory batch complete: ' . json_encode($result));
    }
    catch (\Throwable $e) {
      return JobResult::failure('Analytics inventory batch failed: ' . $e->getMessage());
    }
  }

}
