<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;

/** Resumes one archive pass from its last confirmed metadata batch. */
class ContentUrlRepairService {
  public const STATE = 'ttd_topics.content_url_repair.v1';
  public const ENABLED = 'ttd_topics.content_url_repair.enabled';
  public const JOB = 'ttd_content_url_repair';
  public const QUEUE = 'ttd_topics_url_repair';
  public const BATCH_SIZE = 25;
  public const DELAY = 60;

  private function context(): string {
    $config = \Drupal::config('ttd_topics.settings');
    $types = $config->get('enabled_content_types') ?: [];
    sort($types);
    return hash('sha256', json_encode([TOPICALBOOST_API_ENDPOINT, \ttd_topics_get_site_base_url(),
      $config->get('topicalboost_api_key'), $types]));
  }

  private function enabled(): bool {
    $config = \Drupal::config('ttd_topics.settings');
    return in_array(\Drupal::keyValue('state')->get(self::ENABLED, FALSE), [TRUE, 1, '1'], TRUE)
      && $config->get('topicalboost_api_key') && $config->get('enabled_content_types')
      && !\Drupal::keyValue('state')->get('ttd_topics.content_url_repair.disabled', FALSE);
  }

  public function queueIfDue(bool $follow_up = FALSE): bool {
    if (!$this->enabled()) return FALSE;
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::STATE . '.queue', 30)) return FALSE;
    try {
      $state = \Drupal::keyValue('state')->get(self::STATE, []);
      if (($state['context'] ?? '') === $this->context() && ($state['status'] ?? '') === 'complete') return FALSE;
      $pending = \Drupal::database()->select('advancedqueue', 'aq')
        ->condition('queue_id', self::QUEUE)->condition('type', self::JOB)
        ->condition('state', $follow_up ? ['queued'] : ['queued', 'processing'], 'IN')
        ->countQuery()->execute()->fetchField();
      if ($pending) return FALSE;
      $due = ($state['context'] ?? '') === $this->context() ? (int) ($state['next_at'] ?? 0) : 0;
      if (!$due) $due = time() + 60 + hexdec(substr($this->context(), 0, 2));
      $storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $storage->load(self::QUEUE);
      if (!$queue) {
        $queue = $storage->create(['id' => self::QUEUE, 'label' => 'TopicalBoost archive URL repair',
          'dependencies' => ['module' => ['ttd_topics']],
          'backend' => 'database', 'backend_configuration' => [], 'processor' => 'cron',
          'processing_time' => 10, 'locked' => FALSE]);
        $queue->save();
      }
      $queue->enqueueJob(Job::create(self::JOB, ['version' => 1]), max(0, $due - time()));
      return TRUE;
    }
    finally { $lock->release(self::STATE . '.queue'); }
  }

  public function run(): array {
    if (!$this->enabled()) return ['status' => 'paused'];
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::STATE . '.run', 30)) return ['status' => 'busy'];
    $state = \Drupal::keyValue('state')->get(self::STATE, []);
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      if (($state['context'] ?? '') !== $this->context()) {
        $state = ['context' => $this->context(), 'status' => 'pending', 'cursor' => 0,
          'end' => NULL, 'repaired' => 0, 'metadata_updated' => 0,
          'unchanged' => 0, 'skipped' => 0, 'failed_batches' => 0, 'failures' => 0,
          'batches' => 0, 'requests' => 0, 'started_at' => time()];
        \Drupal::state()->set(self::STATE, $state);
      }
      if ($state['status'] === 'complete' || (int) ($state['next_at'] ?? 0) > time()) return $state;
      if (!isset($state['end'])) {
        $last = $storage->getQuery()->accessCheck(FALSE)->sort('nid', 'DESC')->range(0, 1)->execute();
        $state['end'] = $last ? (int) reset($last) : 0;
        \Drupal::state()->set(self::STATE, $state);
      }
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)
        ->condition('type', \Drupal::config('ttd_topics.settings')->get('enabled_content_types'), 'IN')
        ->exists('field_ttd_last_analyzed')->condition('nid', $state['cursor'], '>')
        ->condition('nid', $state['end'], '<=')->sort('nid', 'ASC')->range(0, self::BATCH_SIZE)->execute();
      if (!$ids) {
        $state['status'] = 'complete';
        $state['completed_at'] = time();
        $state['next_at'] = 0;
        \Drupal::state()->set(self::STATE, $state);
        return $state;
      }
      $service = \Drupal::service('ttd_topics.analysis_service');
      $items = [];
      $skipped = 0;
      foreach ($ids as $id) {
        $storage->resetCache([$id]);
        \Drupal::service('path_alias.manager')->cacheClear('/node/' . $id);
        $node = $storage->load($id);
        try {
          $body = $node ? $service->contentUrlMetadata($node) : NULL;
          $query = [];
          parse_str((string) (parse_url($body['url'] ?? '', PHP_URL_QUERY) ?: ''), $query);
          if (!$node || !$service->isContentUrlSyncEligible($node) || !$body || isset($query['p'])) {
            $skipped++;
            continue;
          }
          $items[(string) $id] = $body;
        }
        finally {
          // Metadata does not need cached article bodies for the whole batch.
          unset($node);
          $storage->resetCache([$id]);
          // Field lists hold parent-node cycles that otherwise retain large bodies.
          gc_collect_cycles();
        }
      }
      if ($items) {
        $state['requests']++;
        $response = $service->updateContentUrls(array_values($items));
        $results = $response['results'] ?? NULL;
        if (!is_array($results) || count($results) !== count($items)) throw new \RuntimeException('The API did not confirm the metadata batch.');
        $seen = [];
        foreach ($results as $result) {
          $id = (string) ($result['postId'] ?? '');
          if (!isset($items[$id]) || isset($seen[$id]) || (empty($result['skipped'])
            && (!is_bool($result['urlChanged'] ?? NULL) || !is_bool($result['metadataChanged'] ?? NULL)))) {
            throw new \RuntimeException('The API returned an incomplete metadata batch.');
          }
          $seen[$id] = TRUE;
        }
        foreach ($results as $result) {
          $label = !empty($result['skipped']) ? 'skipped' : (!empty($result['urlChanged']) ? 'repaired'
            : (!empty($result['metadataChanged']) ? 'metadata_updated' : 'unchanged'));
          $state[$label]++;
          if ($label !== 'skipped') $service->confirmContentUrlMetadata($items[(string) $result['postId']]);
        }
      }
      $state['skipped'] += $skipped;
      $state['cursor'] = (int) max($ids);
      $state['batches']++;
      $state['failures'] = 0;
      $state['status'] = 'pending';
      $state['next_at'] = time() + self::DELAY;
      unset($state['last_error']);
      \Drupal::state()->set(self::STATE, $state);
    }
    catch (\Throwable $error) {
      $state['failed_batches'] = ($state['failed_batches'] ?? 0) + 1;
      $state['failures'] = ($state['failures'] ?? 0) + 1;
      $state['status'] = 'retrying';
      $state['last_error'] = $error->getMessage();
      $state['next_at'] = time() + min(3600, 60 * (2 ** min(6, $state['failures'] - 1)));
      \Drupal::state()->set(self::STATE, $state);
    }
    finally {
      $lock->release(self::STATE . '.run');
      $this->queueIfDue(TRUE);
    }
    return $state;
  }
}
