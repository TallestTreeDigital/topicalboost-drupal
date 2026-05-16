<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;

/**
 * Job type for pulling one page of sync data from the API.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_sync_pull",
 *   label = @Translation("TopicalBoost Sync Pull"),
 *   max_retries = 3,
 *   retry_delay = 60
 * )
 */
class TtdSyncPull extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $type = $payload['type'] ?? '';
    $page = (int) ($payload['page'] ?? 1);
    $page_size = (int) ($payload['page_size'] ?? 50);
    $since = $payload['since'] ?? NULL;

    try {
      $result = \Drupal::service('ttd_topics.sync_service')->pullSyncPage($type, $page, $page_size, $since);

      if (!empty($result['split_jobs'])) {
        return JobResult::success('Split topics page ' . $page . ' into ' . $result['split_jobs'] . ' smaller pull jobs');
      }

      if ($type === 'topics') {
        return JobResult::success('Pulled topics page ' . $page . ' with ' . ($result['topics'] ?? 0) . ' entities');
      }

      return JobResult::success('Pulled relationships page ' . $page . ': ' . ($result['posts_applied'] ?? 0) . ' applied, ' . ($result['posts_skipped'] ?? 0) . ' skipped');
    }
    catch (\Exception $e) {
      \Drupal::service('ttd_topics.sync_service')->cancelSync();
      \Drupal::logger('ttd_topics')->error('Sync pull failed for @type page @page: @message', [
        '@type' => $type,
        '@page' => $page,
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Sync pull failed: ' . $e->getMessage());
    }
  }

}
