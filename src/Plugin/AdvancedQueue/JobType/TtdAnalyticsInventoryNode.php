<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Sends one incremental Drupal Analytics inventory update or deletion.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_analytics_inventory_node",
 *   label = @Translation("TopicalBoost Analytics Inventory Node"),
 *   max_retries = 5,
 *   retry_delay = 300
 * )
 */
class TtdAnalyticsInventoryNode extends JobTypeBase {

  public function process(Job $job) {
    $payload = $job->getPayload();
    try {
      $result = \Drupal::service('ttd_topics.analytics_inventory')->syncNode(
        (int) ($payload['node_id'] ?? 0),
        !empty($payload['deleted']),
      );
      return JobResult::success('Analytics inventory node sync complete: ' . json_encode($result));
    }
    catch (\Throwable $e) {
      return JobResult::failure('Analytics inventory node sync failed: ' . $e->getMessage());
    }
  }

}
