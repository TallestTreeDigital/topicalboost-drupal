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

    $this->applyCustomFieldFilter($query, $filters);

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
   * Applies the optional custom-field populated filter.
   */
  private function applyCustomFieldFilter($query, array $filters): void {
    if (empty($filters['custom_field_filter']) || empty($filters['custom_field'])) {
      return;
    }

    $field_name = preg_replace('/[^a-z0-9_]/', '', (string) $filters['custom_field']);
    if ($field_name === '') {
      return;
    }

    $config = \Drupal::config('ttd_topics.settings');
    $allowed_fields = array_filter($config->get('analysis_custom_fields') ?: []);
    if (!in_array($field_name, $allowed_fields, TRUE)) {
      return;
    }

    $database = \Drupal::database();
    $table = 'node__' . $field_name;
    if (!$database->schema()->tableExists($table)) {
      return;
    }

    $alias = 'cf_' . substr(hash('sha1', $field_name), 0, 8);
    $query->innerJoin($table, $alias, "n.nid = {$alias}.entity_id AND {$alias}.deleted = 0");
    $query->distinct();

    $candidate_columns = [
      $field_name . '_value',
      $field_name . '_target_id',
      $field_name . '_uri',
      $field_name . '_summary',
      $field_name . '_alt',
      $field_name . '_title',
    ];

    $or = $query->orConditionGroup();
    $has_value_column = FALSE;
    foreach ($candidate_columns as $column) {
      if (!$database->schema()->fieldExists($table, $column)) {
        continue;
      }
      $has_value_column = TRUE;
      $or->isNotNull("{$alias}.{$column}");
      $or->condition("{$alias}.{$column}", '', '<>');
    }
    if ($has_value_column) {
      $query->condition($or);
    }
  }

  /**
   * Prepare node data for the API.
   */
  private function prepareNodeData(NodeInterface $node) {
    // Get analysis content using field collector
    $field_collector = \Drupal::service('ttd_topics.field_collector');
    $analysis_text = $field_collector->collect($node);

    $data = [
      'url' => \ttd_topics_get_node_absolute_url($node),
      'title' => $node->getTitle(),
      'text' => $analysis_text,
      'status' => $node->isPublished() ? 'publish' : 'draft',
    ];

    if ($node->isPublished()) {
      $data['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z', $node->getCreatedTime());
    }

    return $data;
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
      'headers' => \ttd_topics_api_headers($api_key),
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
