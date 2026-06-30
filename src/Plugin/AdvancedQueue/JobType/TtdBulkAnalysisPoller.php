<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Job type for polling bulk analysis status and auto-triggering apply.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_bulk_analysis_poller",
 *   label = @Translation("TopicalBoost Bulk Analysis Poller"),
 *   max_retries = 50,
 *   retry_delay = 180
 * )
 */
class TtdBulkAnalysisPoller extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $request_id = $payload['request_id'];

    try {
      $config = \Drupal::config('ttd_topics.settings');
      $api_key = $config->get('topicalboost_api_key');
      $api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

      $client = new Client();
      $response = $client->get($api_base_url . '/poll/analysis', [
        'headers' => ['x-api-key' => $api_key],
        'query' => ['request_id' => $request_id],
        'timeout' => 10,
      ]);

      $analysis_status = json_decode($response->getBody(), TRUE);

      if ($this->shouldClearStaleRequest($request_id, $analysis_status ?: [])) {
        $this->cleanupStaleRequest($request_id);
        return JobResult::success('Cleared stale bulk analysis request ' . $request_id);
      }

      // Check if analysis is complete.
      if ($this->isAnalysisComplete($analysis_status ?: [])) {
        $apply_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', NULL);

        // If no apply progress exists yet, automatically start applying results.
        if (!$apply_progress) {
          $this->autoStartApplyResults($request_id);
          return JobResult::success('Analysis complete - auto-started apply process for request ' . $request_id);
        }
        else {
          // Apply is already running or complete.
          return JobResult::success('Analysis complete - apply process already running/complete for request ' . $request_id);
        }
      }
      else {
        // Analysis not complete yet - check if we should continue polling.
        $current_request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
        if ($current_request_id === $request_id) {
          $status_message = $analysis_status['message'] ?? 'In progress';
          return $this->scheduleNextPoll($request_id, 'Analysis status: ' . $status_message . ' - scheduled next poll in 3 minutes');
        }
        else {
          // Request ID changed - stop polling this old request.
          return JobResult::success('Request ID changed - stopping poll for old request ' . $request_id);
        }
      }

    }
    catch (RequestException $e) {
      // If there's an API error, check if the request_id is still valid.
      $current_request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
      if ($current_request_id !== $request_id) {
        // Request ID changed or was cleared - stop polling.
        return JobResult::success('Request ID changed - stopping poll for old request ' . $request_id);
      }

      if ($this->isRequestNotFoundException($e) && !$this->hasActiveLocalWork($request_id)) {
        $this->cleanupStaleRequest($request_id);
        return JobResult::success('API returned not found; cleared stale bulk analysis request ' . $request_id);
      }

      // Only continue polling if the request is still active.
      if ($current_request_id === $request_id) {
        \Drupal::logger('ttd_topics')->warning('Error polling analysis status for @request_id: @error - will retry in 3 minutes', [
          '@request_id' => $request_id,
          '@error' => $e->getMessage(),
        ]);

        return $this->scheduleNextPoll($request_id, 'API error occurred, scheduled next poll in 3 minutes: ' . $e->getMessage());
      }
      else {
        // Request ID changed - stop polling.
        return JobResult::success('Request ID changed during error - stopping poll for old request ' . $request_id);
      }
    }
  }

  /**
   * Automatically start applying results when analysis is complete (using optimized posts endpoint).
   */
  private function autoStartApplyResults($request_id) {
    try {
      // Schedule optimized posts retrieval job.
      $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $queue_storage->load('ttd_topics_analysis');

      // Clear any existing apply jobs first.
      $this->clearApplyJobs($queue, $request_id);

      // Get content count for progress tracking
      $content_count = \Drupal::state()->get('topicalboost.bulk_analysis.content_count', 0);

      // Initialize apply progress for posts-based processing.
      \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', [
        'stage' => 'posts',
        'posts' => ['completed' => 0, 'total' => $content_count, 'current_page' => 1],
      ]);

      $job = Job::create('ttd_bulk_apply_posts_optimized', [
        'request_id' => $request_id,
        'page' => 1,
      ]);
      $queue->enqueueJob($job);

      \Drupal::logger('ttd_topics')->info('Auto-started applying results for request @request_id', [
        '@request_id' => $request_id,
      ]);

    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to auto-start applying results: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Schedule a new poller job for a request.
   */
  private function scheduleNextPoll($request_id, string $success_message) {
    try {
      $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $queue_storage->load('ttd_topics_analysis');

      $next_job = Job::create('ttd_bulk_analysis_poller', [
        'request_id' => $request_id,
      ]);
      $queue->enqueueJob($next_job, 180);

      return JobResult::success($success_message);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to schedule next poller job: @error', [
        '@error' => $e->getMessage(),
      ]);
      return JobResult::failure('Failed to schedule next poll: ' . $e->getMessage());
    }
  }

  /**
   * Determine whether a poll response represents a completed request.
   */
  private function isAnalysisComplete(array $analysis_status): bool {
    return !empty($analysis_status['ready'])
      || (isset($analysis_status['message']) && $analysis_status['message'] === 'Analysis complete');
  }

  /**
   * Determine whether a stale request should be cleared.
   */
  private function shouldClearStaleRequest(string $request_id, array $analysis_status): bool {
    if ($this->hasActiveLocalWork($request_id)) {
      return FALSE;
    }

    return $this->isNoRequestFoundStatus($analysis_status)
      || $this->isEmptyCompletedAnalysis($analysis_status);
  }

  /**
   * Determine whether an API poll response means the request no longer exists.
   */
  private function isNoRequestFoundStatus(array $analysis_status): bool {
    $message = strtolower((string) ($analysis_status['message'] ?? ''));
    if (str_contains($message, 'no request found')) {
      return TRUE;
    }

    return array_key_exists('request_id', $analysis_status)
      && empty($analysis_status['request_id'])
      && str_contains($message, 'request');
  }

  /**
   * Determine whether a completed request has no uploaded content.
   */
  private function isEmptyCompletedAnalysis(array $analysis_status): bool {
    if (!$this->isAnalysisComplete($analysis_status)) {
      return FALSE;
    }

    if (!empty($analysis_status['empty_upload'])) {
      return TRUE;
    }

    $message = strtolower((string) ($analysis_status['message'] ?? ''));
    if (str_contains($message, 'no content was received') || str_contains($message, 'no content received')) {
      return TRUE;
    }

    $content_total = $analysis_status['content_total'] ?? $analysis_status['content_count'] ?? NULL;
    $analyzed = $analysis_status['analyzed'] ?? NULL;
    $posts_page_count = $analysis_status['posts_page_count'] ?? NULL;

    if ($content_total !== NULL && (int) $content_total === 0 && $analyzed !== NULL && (int) $analyzed === 0) {
      return TRUE;
    }

    return $content_total !== NULL
      && (int) $content_total === 0
      && $posts_page_count !== NULL
      && (int) $posts_page_count === 0;
  }

  /**
   * Determine whether local batch or apply jobs still exist for a request.
   */
  private function hasActiveLocalWork(string $request_id): bool {
    $database = \Drupal::database();

    $batch_count = (int) $database->select('advancedqueue', 'aq')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', 'ttd_bulk_batch_send')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    $apply_count = (int) $database->select('advancedqueue', 'aq')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities', 'ttd_bulk_apply_posts_optimized'], 'IN')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    return $batch_count > 0 || $apply_count > 0;
  }

  /**
   * Determine whether a request exception means the bulk request is gone.
   */
  private function isRequestNotFoundException(RequestException $e): bool {
    $response = $e->getResponse();
    if ($response && $response->getStatusCode() === 404) {
      return TRUE;
    }

    $body = $response ? (string) $response->getBody() : '';
    return str_contains(strtolower($body), 'no request found');
  }

  /**
   * Clean up local state and queued jobs for a stale request.
   */
  private function cleanupStaleRequest(string $request_id): void {
    $current_request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
    if ($current_request_id !== $request_id) {
      return;
    }

    $database = \Drupal::database();
    $database->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_analysis_poller', 'ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities', 'ttd_bulk_apply_posts_optimized'], 'IN')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->execute();

    \Drupal::state()->delete('topicalboost.bulk_analysis.request_id');
    \Drupal::state()->delete('topicalboost.bulk_analysis.filters');
    \Drupal::state()->delete('topicalboost.bulk_analysis.content_count');
    \Drupal::state()->delete('topicalboost.bulk_analysis.started_at');
    \Drupal::state()->delete('topicalboost.bulk_analysis.apply_progress');
    \Drupal::state()->delete('topicalboost.bulk_analysis.completed_at');
    \Drupal::state()->delete('topicalboost.bulk_analysis.customer_id_page_count');
    \Drupal::state()->delete('topicalboost.bulk_analysis.entity_page_count');

    \Drupal::logger('ttd_topics')->warning('Cleaned up stale bulk analysis request @request_id.', [
      '@request_id' => $request_id,
    ]);
  }

  /**
   * Clear apply jobs from queue.
   */
  private function clearApplyJobs($queue, $request_id) {
    $database = \Drupal::database();
    $database->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities', 'ttd_bulk_apply_posts_optimized'], 'IN')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->execute();
  }

}
