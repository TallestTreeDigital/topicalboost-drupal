<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Checks Drupal Analytics entitlement and starts a full inventory sync.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_analytics_inventory_probe",
 *   label = @Translation("TopicalBoost Analytics Inventory Probe"),
 *   max_retries = 5,
 *   retry_delay = 300
 * )
 */
class TtdAnalyticsInventoryProbe extends JobTypeBase {

  public function process(Job $job) {
    try {
      $result = \Drupal::service('ttd_topics.analytics_inventory')->probe();
      return JobResult::success('Analytics inventory probe complete: ' . json_encode($result));
    }
    catch (\Throwable $e) {
      return JobResult::failure('Analytics inventory probe failed: ' . $e->getMessage());
    }
  }

}
