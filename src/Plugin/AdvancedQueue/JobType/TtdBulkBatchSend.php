<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\node\NodeInterface;
use GuzzleHttp\Client;

/**
 * Job type for sending content batches to the bulk analysis API.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_bulk_batch_send",
 *   label = @Translation("TopicalBoost Bulk Batch Send"),
 *   max_retries = 3,
 *   retry_delay = 30
 * )
 */
class TtdBulkBatchSend extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $request_id = $payload['request_id'];
    $page = $payload['page'];
    $page_count = $payload['page_count'];
    $filters = $payload['filters'];
    $batch_size = $payload['batch_size'] ?? 50;

    try {
      // Get nodes data for this page/batch.
      $nodes_data = $this->getNodesData($filters, $page, $batch_size);

      if (empty($nodes_data)) {
        return JobResult::success('No nodes found for batch ' . $page);
      }

      // Send batch to bulk API.
      $this->sendBatchToApi($request_id, $page, $page_count, $nodes_data);

      return JobResult::success('Sent batch ' . $page . ' of ' . $page_count . ' with ' . count($nodes_data) . ' nodes');

    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error sending bulk batch @page: @message', [
        '@page' => $page,
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Error sending batch ' . $page . ': ' . $e->getMessage());
    }
  }

  /**
   * Get nodes data for a specific page/batch.
   */
  private function getNodesData($filters, $page, $batch_size) {
    $offset = ($page - 1) * $batch_size;

    $database = \Drupal::database();
    $query = $database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.type', $filters['content_types'], 'IN');

    // Apply same filters as the controller.
    if (!empty($filters['start_date'])) {
      $query->condition('n.created', strtotime($filters['start_date']), '>=');
    }
    if (!empty($filters['end_date'])) {
      $query->condition('n.created', strtotime($filters['end_date']) + 86400, '<');
    }

    if ($filters['include_drafts']) {
      $query->condition('n.status', [0, 1], 'IN');
    }
    else {
      $query->condition('n.status', 1);
    }

    if ($filters['only_topicless']) {
      $query->leftJoin('node__field_ttd_topics', 'tt', 'n.nid = tt.entity_id');
      $query->isNull('tt.field_ttd_topics_target_id');
    }

    if (!$filters['reanalyze']) {
      $query->leftJoin('node__field_ttd_last_analyzed', 'tla', 'n.nid = tla.entity_id');
      $query->isNull('tla.field_ttd_last_analyzed_value');
    }

    $query->range($offset, $batch_size);
    $query->orderBy('n.nid');

    $node_ids = $query->execute()->fetchCol();

    if (empty($node_ids)) {
      return [];
    }

    // Load nodes and prepare data.
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $nodes = $node_storage->loadMultiple($node_ids);

    $nodes_data = [];
    foreach ($nodes as $node) {
      // Use node ID as array key, just like WordPress uses post ID as key.
      $nodes_data[$node->id()] = $this->prepareNodeData($node);
    }

    return $nodes_data;
  }

  /**
   * Prepare node data for the API.
   */
  private function prepareNodeData(NodeInterface $node) {
    // Get the body content.
    $body_content = '';
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body_content = $node->get('body')->value;
    }

    // Get enabled custom fields if any.
    $config = \Drupal::config('ttd_topics.settings');
    $enabled_fields = $config->get('analysis_custom_fields') ?: [];

    $custom_fields_content = '';
    foreach ($enabled_fields as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $field_value = $node->get($field_name)->value;
        if (!empty($field_value)) {
          $custom_fields_content .= ' ' . $field_value;
        }
      }
    }

    // Combine content.
    $full_content = trim($body_content . ' ' . $custom_fields_content);

    return [
      'url' => $node->toUrl()->setAbsolute()->toString(),
      'title' => $node->getTitle(),
      'text' => $full_content,
    ];
  }

  /**
   * Send batch data to the bulk API endpoint.
   */
  private function sendBatchToApi($request_id, $page, $page_count, $nodes_data) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    $client = new Client();

    $response = $client->post($api_base_url . '/analyze/bulk/send', [
      'headers' => [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key,
      ],
      'json' => [
        'request_id' => $request_id,
        'page' => $page,
        'page_count' => $page_count,
        'contents_data' => $nodes_data,
      ],
      // Longer timeout for bulk uploads.
      'timeout' => 60,
    ]);

    $result = json_decode($response->getBody(), TRUE);

    // Log the result for debugging.
    \Drupal::logger('ttd_topics')->info('Sent bulk batch @page/@total with @count nodes. API response: @response', [
      '@page' => $page,
      '@total' => $page_count,
      '@count' => count($nodes_data),
      '@response' => json_encode($result),
    ]);

    return $result;
  }

}
