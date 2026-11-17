<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;

/** Restores tracking without starting or uploading another analysis. */
final class BulkAnalysisRecovery {
  public const LOCK = 'ttd_topics.bulk_analysis.lifecycle';
  public const EMPTY_STATE = 'topicalboost.bulk_analysis.no_active_request';

  private function connection(): array {
    $config = \Drupal::config('ttd_topics.settings');
    $endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
    $headers = \ttd_topics_api_headers((string) $config->get('topicalboost_api_key'));
    return ['endpoint' => $endpoint, 'headers' => $headers,
      'scope' => hash('sha256', json_encode([$endpoint, $headers], JSON_THROW_ON_ERROR))];
  }

  public function recover(bool $locked = FALSE): ?int {
    $store = \Drupal::keyValue('state');
    $active = $store->get(BulkAnalysisLifecycle::REQUEST);
    if ($active) return (int) $active;
    $context = $this->connection();
    if (empty($context['headers']['x-api-key'])) return NULL;
    $lock = \Drupal::lock();
    if (!$locked && !$lock->acquire(self::LOCK, 60.0)) {
      throw new \RuntimeException('Bulk analysis status is being checked. Try again shortly.');
    }
    try {
      $active = $store->get(BulkAnalysisLifecycle::REQUEST);
      if ($active) return (int) $active;
      $empty = $store->get(self::EMPTY_STATE, []);
      if (($empty['scope'] ?? '') === $context['scope'] && ($empty['until'] ?? 0) > time()) return NULL;
      $response = \Drupal::httpClient()->request('GET', $context['endpoint'] . '/poll/active-request', [
        'headers' => $context['headers'], 'timeout' => 10,
      ]);
      $result = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($result) || !array_key_exists('request_id', $result)) {
        throw new \RuntimeException('The API did not confirm active analysis status.');
      }
      if ($this->connection()['scope'] !== $context['scope']) {
        throw new \RuntimeException('The API connection changed. Check analysis status again.');
      }
      $id = $result['request_id'];
      if ($id === NULL || (!empty($id) && BulkAnalysisLifecycle::terminalStatus($id))) {
        $store->set(self::EMPTY_STATE, ['scope' => $context['scope'], 'until' => time() + 30]);
        return NULL;
      }
      if (!is_int($id) || $id <= 0 || !is_int($result['content_count'] ?? NULL) || $result['content_count'] <= 0
        || !empty($result['terminal']) || !empty($result['ready'])) {
        throw new \RuntimeException('The API returned an invalid active analysis.');
      }
      $queue = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load('ttd_topics_analysis');
      if (!$queue) throw new \RuntimeException('The analysis queue is unavailable.');
      $db = \Drupal::database();
      $transaction = $db->startTransaction();
      $pending = $db->select('advancedqueue', 'q')->condition('queue_id', 'ttd_topics_analysis')
        ->condition('type', 'ttd_bulk_analysis_poller')->condition('state', ['queued', 'processing'], 'IN')
        ->condition(BulkAnalysisLifecycle::requestCondition($id))->countQuery()->execute()->fetchField();
      if (!$pending) $queue->enqueueJob(Job::create('ttd_bulk_analysis_poller', ['request_id' => $id]));
      // Request-less progress can belong to an older run. Results are idempotent.
      foreach (['filters', 'apply_progress', 'completed_at', 'customer_id_page_count', 'entity_page_count'] as $key) {
        $store->delete('topicalboost.bulk_analysis.' . $key);
      }
      $store->set('topicalboost.bulk_analysis.content_count', $result['content_count']);
      $store->set('topicalboost.bulk_analysis.started_at', strtotime($result['created_at'] ?? '') ?: time());
      $store->set(BulkAnalysisLifecycle::REQUEST, $id);
      $store->delete(self::EMPTY_STATE);
      unset($transaction);
      \Drupal::state()->resetCache();
      return $id;
    }
    catch (\Throwable $error) {
      if (isset($transaction)) $transaction->rollBack();
      throw $error;
    }
    finally {
      if (!$locked) $lock->release(self::LOCK);
    }
  }
}
