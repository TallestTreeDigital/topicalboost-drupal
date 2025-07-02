<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Job type for retrieving customer IDs from bulk analysis results.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_bulk_apply_customer_ids",
 *   label = @Translation("TopicalBoost Bulk Apply Customer IDs"),
 *   max_retries = 3,
 *   retry_delay = 60
 * )
 */
class TtdBulkApplyCustomerIds extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $request_id = $payload['request_id'];
    $page = $payload['page'];

    try {
      $config = \Drupal::config('ttd_topics.settings');
      $api_key = $config->get('topicalboost_api_key');
      $api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

      $client = new Client();

      // If this is the first page, get analysis status to check page counts.
      if ($page == 1) {
        $status_response = $client->get($api_base_url . '/poll/analysis', [
          'headers' => ['x-api-key' => $api_key],
          'query' => ['request_id' => $request_id],
          'timeout' => 30,
        ]);

        $status_result = json_decode($status_response->getBody(), TRUE);
        $customer_id_page_count = $status_result['customer_id_page_count'] ?? 0;
        $entity_page_count = $status_result['entity_page_count'] ?? 0;

        // Store page counts for future reference.
        \Drupal::state()->set('topicalboost.bulk_analysis.customer_id_page_count', $customer_id_page_count);
        \Drupal::state()->set('topicalboost.bulk_analysis.entity_page_count', $entity_page_count);

        if ($customer_id_page_count == 0) {
          // No customer IDs to process, skip to entities.
          if ($entity_page_count > 0) {
            $current_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', []);
            $current_progress['stage'] = 'entities';
            $current_progress['entities'] = ['completed' => 0, 'total' => $entity_page_count, 'current_page' => 1];
            \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $current_progress);

            $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
            $queue = $queue_storage->load('ttd_topics_analysis');
            $entities_job = Job::create('ttd_bulk_apply_entities', [
              'request_id' => $request_id,
              'page' => 1,
            ]);
            $queue->enqueueJob($entities_job);

            return JobResult::success('No customer IDs to process - started entities phase with ' . $entity_page_count . ' pages');
          }
          else {
            // No customer IDs or entities, mark as complete.
            $current_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', []);
            $current_progress['stage'] = 'complete';
            \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $current_progress);
            \Drupal::state()->set('topicalboost.bulk_analysis.completed_at', time());

            return JobResult::success('No customer IDs or entities to process - marked as complete');
          }
        }
      }

      // Get customer IDs for this page.
      $response = $client->get($api_base_url . '/result/customer_ids', [
        'headers' => ['x-api-key' => $api_key],
        'query' => [
          'request_id' => $request_id,
          'page' => $page,
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody(), TRUE);

      if (isset($result['customer_ids']) && !empty($result['customer_ids'])) {
        // Mark all customer IDs as analyzed.
        $this->markCustomerIdsAsAnalyzed($result['customer_ids']);

        // Update apply progress.
        $current_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', [
          'stage' => 'customer_ids',
          'customer_ids' => ['completed' => 0, 'total' => 0, 'current_page' => 1],
        ]);

        $current_progress['customer_ids']['completed'] = $page;
        $current_progress['customer_ids']['total'] = $result['page_count'] ?? $page;
        $current_progress['customer_ids']['current_page'] = $page;

        \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $current_progress);

        // Use stored page counts to determine if there are more pages.
        $customer_id_page_count = \Drupal::state()->get('topicalboost.bulk_analysis.customer_id_page_count', $result['page_count'] ?? 0);
        $entity_page_count = \Drupal::state()->get('topicalboost.bulk_analysis.entity_page_count', 0);

        // If there are more customer ID pages, schedule the next one.
        if ($page < $customer_id_page_count) {
          $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
          $queue = $queue_storage->load('ttd_topics_analysis');
          $next_job = Job::create('ttd_bulk_apply_customer_ids', [
            'request_id' => $request_id,
            'page' => $page + 1,
          ]);
          $queue->enqueueJob($next_job);
        }
        else {
          // Customer IDs phase complete, start entities phase if there are entities.
          if ($entity_page_count > 0) {
            $current_progress['stage'] = 'entities';
            $current_progress['entities'] = ['completed' => 0, 'total' => $entity_page_count, 'current_page' => 1];
            \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $current_progress);

            $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
            $queue = $queue_storage->load('ttd_topics_analysis');
            $entities_job = Job::create('ttd_bulk_apply_entities', [
              'request_id' => $request_id,
              'page' => 1,
            ]);
            $queue->enqueueJob($entities_job);
          }
          else {
            // No entities to process, mark as complete.
            $current_progress['stage'] = 'complete';
            \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $current_progress);
            \Drupal::state()->set('topicalboost.bulk_analysis.completed_at', time());
          }
        }

        return JobResult::success('Processed customer IDs page ' . $page . ' of ' . ($result['page_count'] ?? $page) . ' - marked ' . count($result['customer_ids']) . ' nodes as analyzed');
      }
      else {
        return JobResult::success('No customer IDs found for page ' . $page);
      }

    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error retrieving customer IDs page @page: @message', [
        '@page' => $page,
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Error retrieving customer IDs page ' . $page . ': ' . $e->getMessage());
    }
  }

  /**
   * Mark customer IDs (node IDs) as analyzed.
   */
  private function markCustomerIdsAsAnalyzed($customer_ids) {
    if (empty($customer_ids)) {
      return;
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $timestamp = \Drupal::time()->getRequestTime();

    // Load nodes and update their analyzed timestamp.
    $nodes = $node_storage->loadMultiple($customer_ids);

    foreach ($nodes as $node) {
      if ($node) {
        $node->set('field_ttd_last_analyzed', $timestamp);
        $node->save();
      }
    }

    \Drupal::logger('ttd_topics')->info('Marked @count nodes as analyzed during bulk analysis customer ID processing', [
      '@count' => count($nodes),
    ]);
  }

}
