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
          // Schedule a new polling job for 3 minutes in the future.
          // AdvancedQueue doesn't properly handle JobResult::failure() with requeue,
          // so we schedule a new job instead.
          try {
            $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
            $queue = $queue_storage->load('ttd_topics_analysis');

            $next_job = Job::create('ttd_bulk_analysis_poller', [
              'request_id' => $request_id,
            ]);
            $queue->enqueueJob($next_job, 180); // 180 second delay = 3 minutes

            $status_message = $analysis_status['message'] ?? 'In progress';
            return JobResult::success('Analysis status: ' . $status_message . ' - scheduled next poll in 3 minutes');
          } catch (\Exception $e) {
            \Drupal::logger('ttd_topics')->error('Failed to schedule next poller job: @error', [
              '@error' => $e->getMessage(),
            ]);
            $status_message = $analysis_status['message'] ?? 'In progress';
            return JobResult::failure('Failed to schedule next poll: ' . $e->getMessage());
          }
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

        // Schedule a new polling job for 3 minutes in the future instead of using failure with requeue.
        try {
          $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
          $queue = $queue_storage->load('ttd_topics_analysis');

          $next_job = Job::create('ttd_bulk_analysis_poller', [
            'request_id' => $request_id,
          ]);
          $queue->enqueueJob($next_job, 180); // 180 second delay = 3 minutes

          return JobResult::success('API error occurred, scheduled next poll in 3 minutes: ' . $e->getMessage());
        } catch (\Exception $schedule_error) {
          \Drupal::logger('ttd_topics')->error('Failed to schedule next poller job after API error: @error', [
            '@error' => $schedule_error->getMessage(),
          ]);
          return JobResult::failure('API error with failed retry scheduling: ' . $e->getMessage());
        }
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
