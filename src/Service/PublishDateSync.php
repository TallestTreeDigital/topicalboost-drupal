<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;
use Drupal\node\NodeInterface;

/** Sends historical publish dates once per connection and content scope. */
final class PublishDateSync {
  public const STATE = 'topicalboost.publish_date_catchup.v1';
  public const JOB = 'ttd_publish_date_catchup';
  public const QUEUE = 'ttd_topics_publish_dates';
  public const BATCH_SIZE = 100;
  private const LOCK = 'ttd_topics.publish_dates';
  private const WINDOW = 10000;
  private const MAX_WINDOWS = 20;

  private function context(): array {
    $config = \Drupal::config('ttd_topics.settings');
    $types = array_values(array_unique(array_filter((array) $config->get('enabled_content_types'))));
    sort($types);
    $endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
    $headers = \ttd_topics_api_headers((string) $config->get('topicalboost_api_key'));
    $timezone = \Drupal::config('system.date')->get('timezone.default') ?: 'UTC';
    return ['endpoint' => $endpoint, 'headers' => $headers, 'types' => $types, 'timezone' => $timezone,
      'scope' => hash('sha256', json_encode([$endpoint, $headers, $types, $timezone], JSON_THROW_ON_ERROR))];
  }

  private function initialState(array $context): array {
    return ['scope' => $context['scope'], 'status' => 'pending', 'cursor' => NULL, 'upper_id' => NULL,
      'processed' => 0, 'written' => 0, 'skipped' => 0, 'attempts' => 0, 'unreadable_ids' => [],
      'started_at' => time(), 'next_at' => time() + random_int(300, 600)];
  }

  /** Cron repairs a lost wakeup without restarting a completed generation. */
  public function queueIfDue(bool $follow_up = FALSE): bool {
    $context = $this->context();
    if (empty($context['headers']['x-api-key'])) return FALSE;
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::LOCK . '.schedule', 30.0)) return FALSE;
    try {
      $state = \Drupal::keyValue('state')->get(self::STATE);
      if (($state['scope'] ?? '') === $context['scope'] && ($state['status'] ?? '') === 'complete') return FALSE;
      $pending = \Drupal::database()->select('advancedqueue', 'q')->condition('queue_id', self::QUEUE)
        ->condition('type', self::JOB)->condition('state', $follow_up ? ['queued'] : ['queued', 'processing'], 'IN')
        ->countQuery()->execute()->fetchField();
      if ($pending) return FALSE;
      // Workers own checkpoint changes. Scheduling cannot replace a live worker.
      $due = ($state['scope'] ?? '') === $context['scope'] ? (int) ($state['next_at'] ?? 0) : time() + random_int(300, 600);
      $storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $storage->load(self::QUEUE);
      if (!$queue) {
        $queue = $storage->create(['id' => self::QUEUE, 'label' => 'TopicalBoost publish date catch-up',
          'dependencies' => ['module' => ['ttd_topics']], 'backend' => 'database', 'backend_configuration' => [],
          'processor' => 'cron', 'processing_time' => 10, 'locked' => FALSE]);
        $queue->save();
      }
      $queue->enqueueJob(Job::create(self::JOB, ['version' => 1]), max(0, $due - time()));
      return TRUE;
    }
    finally { $lock->release(self::LOCK . '.schedule'); }
  }

  public function run(): array {
    $context = $this->context();
    if (empty($context['headers']['x-api-key'])) return ['status' => 'paused'];
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::LOCK, 900.0)) return ['status' => 'busy'];
    $store = \Drupal::keyValue('state');
    $state = $store->get(self::STATE);
    try {
      if (($state['scope'] ?? '') !== $context['scope']) {
        $state = $this->initialState($context);
        $store->set(self::STATE, $state);
        return $state;
      }
      if ($state['status'] === 'complete' || $state['next_at'] > time()) return $state;
      if (!$context['types']) {
        $state['status'] = 'waiting';
        $state['next_at'] = time() + 21600;
        $store->set(self::STATE, $state);
        return $state;
      }
      $db = \Drupal::database();
      if ($state['upper_id'] === NULL) {
        $last = $db->select('node', 'n')->fields('n', ['nid'])->orderBy('nid', 'DESC')->range(0, 1)->execute()->fetchField();
        $state['upper_id'] = (int) $last;
        $state['cursor'] = (int) $last + 1;
        $store->set(self::STATE, $state);
      }
      $cursor = $state['cursor'];
      $ids = [];
      for ($window = 0; $cursor > 1 && count($ids) < self::BATCH_SIZE && $window < self::MAX_WINDOWS; $window++) {
        $floor = max(1, $cursor - self::WINDOW);
        $rows = $db->select('node_field_data', 'n')->fields('n', ['nid'])->distinct()
          ->condition('nid', $floor, '>=')->condition('nid', $cursor, '<')
          ->condition('type', $context['types'], 'IN')->condition('status', 1)
          ->orderBy('nid', 'DESC')->range(0, self::BATCH_SIZE - count($ids))->execute()->fetchCol();
        $ids = array_merge($ids, array_map('intval', $rows));
        $cursor = count($ids) === self::BATCH_SIZE ? (int) end($ids) : $floor;
      }
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $posts = []; $unreadable = [];
      foreach ($ids as $id) {
        try {
          $storage->resetCache([$id]);
          $node = $storage->load($id);
          if (!$node instanceof NodeInterface || !$node->isPublished() || !in_array($node->bundle(), $context['types'], TRUE)) continue;
          $timestamp = (int) $node->getCreatedTime();
          $posts[] = ['post_id' => $id, 'status' => 'publish',
            'published_at' => $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : NULL,
            'published_date' => $timestamp > 0 ? (new \DateTimeImmutable('@' . $timestamp))
              ->setTimezone(new \DateTimeZone($context['timezone']))->format('Y-m-d') : NULL];
        }
        catch (\Throwable $error) {
          if ($state['attempts'] < 3) throw $error;
          $unreadable[] = $id;
        }
        finally {
          unset($node);
          $storage->resetCache([$id]);
          gc_collect_cycles();
        }
      }
      if ($unreadable && !$posts) throw new \RuntimeException('No publish dates in this batch could be read.');
      $written = 0;
      if ($posts) {
        $response = \Drupal::httpClient()->request('POST', $context['endpoint'] . '/content/publish-dates', [
          'headers' => $context['headers'], 'json' => ['posts' => $posts], 'timeout' => 60,
        ]);
        $result = json_decode((string) $response->getBody(), TRUE);
        if (($result['ok'] ?? FALSE) !== TRUE || ($result['received'] ?? NULL) !== count($posts)
          || !is_int($result['matched'] ?? NULL) || $result['matched'] < 0 || $result['matched'] > count($posts)) {
          throw new \RuntimeException('The API did not confirm the publish date batch.');
        }
        $written = $result['matched'];
      }
      if ($context['scope'] !== $this->context()['scope']) return ['status' => 'replaced'];
      $state['cursor'] = $cursor;
      $state['processed'] += count($ids);
      $state['written'] += $written;
      $state['skipped'] += count($ids) - $written;
      $state['unreadable_ids'] = array_slice(array_merge($state['unreadable_ids'], $unreadable), 0, 1000);
      $state['attempts'] = 0;
      $state['status'] = $cursor <= 1 ? 'complete' : 'pending';
      $state['next_at'] = time() + 60;
      if ($state['status'] === 'complete') $state['completed_at'] = time();
      $store->set(self::STATE, $state);
      return $state;
    }
    catch (\Throwable $error) {
      if (is_array($state) && $context['scope'] === $this->context()['scope']) {
        $state['attempts']++;
        $state['status'] = 'retrying';
        $delays = [60, 300, 900, 3600, 21600];
        $state['next_at'] = time() + $delays[min($state['attempts'] - 1, count($delays) - 1)];
        $store->set(self::STATE, $state);
        \Drupal::logger('ttd_topics')->warning('Publish-date catch-up batch failed, attempt @attempt: @message', [
          '@attempt' => $state['attempts'],
          '@message' => $error->getMessage(),
        ]);
      }
      return ['status' => 'retrying'];
    }
    finally {
      $lock->release(self::LOCK);
      $this->queueIfDue(TRUE);
    }
  }
}
