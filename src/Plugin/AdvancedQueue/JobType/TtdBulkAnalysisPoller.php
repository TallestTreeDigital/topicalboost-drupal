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

      // Check if analysis is complete.
      if (isset($analysis_status['message']) && $analysis_status['message'] === 'Analysis complete') {
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
          // Use failure with requeue to wait and retry.
          $status_message = $analysis_status['message'] ?? 'In progress';
          return JobResult::failure('Analysis status: ' . $status_message . ' - will retry in 3 minutes', 0, TRUE);
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

      // Only continue polling if the request is still active.
      if ($current_request_id === $request_id) {
        \Drupal::logger('ttd_topics')->warning('Error polling analysis status for @request_id: @error - will retry in 3 minutes', [
          '@request_id' => $request_id,
          '@error' => $e->getMessage(),
        ]);

        return JobResult::failure('API error: ' . $e->getMessage() . ' - will retry in 3 minutes', 0, TRUE);
      }
      else {
        // Request ID changed - stop polling.
        return JobResult::success('Request ID changed during error - stopping poll for old request ' . $request_id);
      }
    }
  }

  /**
   * Automatically start applying results when analysis is complete.
   */
  private function autoStartApplyResults($request_id) {
    try {
      // Schedule customer IDs retrieval job.
      $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $queue_storage->load('ttd_topics_analysis');

      // Clear any existing apply jobs first.
      $this->clearApplyJobs($queue, $request_id);

      // Initialize apply progress.
      \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', [
        'stage' => 'starting',
        'customer_ids' => ['completed' => 0, 'total' => 0, 'current_page' => 1],
        'entities' => ['completed' => 0, 'total' => 0, 'current_page' => 1],
      ]);

      $job = Job::create('ttd_bulk_apply_customer_ids', [
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
   * Clear apply jobs from queue.
   */
  private function clearApplyJobs($queue, $request_id) {
    $database = \Drupal::database();
    $database->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities'], 'IN')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->execute();
  }

}
