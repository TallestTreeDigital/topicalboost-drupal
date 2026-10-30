<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\Backend;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database;
use Drupal\ttd_topics\Service\AnalyticsInventoryService;

/**
 * Prefers live inventory changes while preserving the existing queue's progress.
 *
 * @AdvancedQueueBackend(
 *   id = "ttd_description_priority",
 *   label = @Translation("TopicalBoost inventory priority")
 * )
 */
class DescriptionPriorityDatabase extends Database {

  private const JOB_TABLE = 'advancedqueue';
  private const COUNTER_COLLECTION = 'ttd_description_queue';
  private const LIVE_BURST = 3;

  /**
   * Claim three preferred live updates, then one oldest-due FIFO job.
   *
   * The short database transaction serializes claims, not execution. Its counter
   * survives worker restarts, so a steady edit stream cannot starve catch-up.
   * Other job types retain their existing available-time/job-ID order.
   */
  public function claimJob() {
    if ($this->queueId !== AnalyticsInventoryService::QUEUE_ID) {
      return parent::claimJob();
    }
    $this->connection->merge('key_value')
      ->insertFields(['value' => serialize(0)])
      ->keys(['collection' => self::COUNTER_COLLECTION, 'name' => $this->queueId])->execute();
    $transaction = $this->connection->startTransaction();
    $raw = $this->connection->select('key_value', 'kv')->fields('kv', ['value'])
      ->condition('collection', self::COUNTER_COLLECTION)->condition('name', $this->queueId)
      ->forUpdate()->execute()->fetchField();
    if (is_resource($raw)) {
      $raw = stream_get_contents($raw);
    }
    $burst = max(0, (int) @unserialize($raw, ['allowed_classes' => FALSE]));
    $now = $this->time->getCurrentTime();
    $job = $this->candidate($now, $burst < self::LIVE_BURST);
    if (!$job) {
      $job = $this->candidate($now, FALSE);
    }
    if (!$job) {
      return NULL;
    }
    $expires = $now + $this->configuration['lease_time'];
    $claimed = $this->connection->update(self::JOB_TABLE)
      ->fields(['state' => Job::STATE_PROCESSING, 'expires' => $expires])
      ->condition('job_id', $job['job_id'])->condition('state', Job::STATE_QUEUED)
      ->condition('expires', 0)->execute();
    if (!$claimed) {
      // A claimant using the old backend can race during a rolling upgrade.
      return NULL;
    }
    $next = $burst < self::LIVE_BURST && $job['type'] === AnalyticsInventoryService::NODE_JOB_TYPE
      ? $burst + 1 : 0;
    $this->connection->update('key_value')->fields(['value' => serialize($next)])
      ->condition('collection', self::COUNTER_COLLECTION)->condition('name', $this->queueId)->execute();
    $job['state'] = Job::STATE_PROCESSING;
    $job['expires'] = $expires;
    return $this->constructJobFromDefinition($job);
  }

  private function candidate(int $now, bool $live) {
    $query = $this->connection->select(self::JOB_TABLE, 'aq')->fields('aq')
      ->condition('queue_id', $this->queueId)->condition('state', Job::STATE_QUEUED)
      ->condition('available', $now, '<=')->condition('expires', 0)
      ->orderBy('available')->orderBy('job_id')->range(0, 1);
    if ($live) $query->condition('type', AnalyticsInventoryService::NODE_JOB_TYPE);
    return $query->execute()->fetchAssoc();
  }

}
