<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Sync API endpoints.
 *
 * Compares local site metrics vs API counts and orchestrates data sync.
 */
class SyncController extends ControllerBase {

  /**
   * Helper to make authenticated API requests.
   */
  private function apiRequest(string $method, string $path, array $json = NULL, array $query = []) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return NULL;
    }

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key,
      ],
      'timeout' => 30,
    ];

    if ($json !== NULL) {
      $options['json'] = $json;
    }
    if (!empty($query)) {
      $options['query'] = $query;
    }

    try {
      $client = \Drupal::httpClient();
      $url = TOPICALBOOST_API_ENDPOINT . $path;
      $response = $client->request($method, $url, $options);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Sync API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get local site metrics for sync comparison.
   */
  private function getLocalMetrics() {
    $database = \Drupal::database();
    $config = \Drupal::config('ttd_topics.settings');

    try {
      $enabled_types = $config->get('enabled_content_types') ?: ['article'];

      // Count published nodes of enabled types.
      $post_count = (int) $database->select('node_field_data', 'n')
        ->condition('n.type', $enabled_types, 'IN')
        ->condition('n.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // Count topics.
      $topic_count = (int) $database->select('taxonomy_term_field_data', 'td')
        ->condition('td.vid', 'ttd_topics')
        ->condition('td.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // Count relationships (topic assignments).
      $query = $database->select('taxonomy_index', 'ti');
      $query->join('taxonomy_term_field_data', 'td', 'ti.tid = td.tid');
      $query->join('node_field_data', 'n', 'ti.nid = n.nid');
      $query->condition('td.vid', 'ttd_topics');
      $query->condition('n.type', $enabled_types, 'IN');
      $query->condition('n.status', 1);
      $relationship_count = (int) $query->countQuery()->execute()->fetchField();

      // Average topics per post.
      $query2 = $database->select('taxonomy_index', 'ti');
      $query2->join('taxonomy_term_field_data', 'td', 'ti.tid = td.tid');
      $query2->join('node_field_data', 'n', 'ti.nid = n.nid');
      $query2->condition('td.vid', 'ttd_topics');
      $query2->condition('n.type', $enabled_types, 'IN');
      $query2->condition('n.status', 1);
      $query2->addExpression('COUNT(DISTINCT ti.nid)', 'posts_with_topics');
      $posts_with_topics = (int) $query2->execute()->fetchField();

      $avg = $posts_with_topics > 0 ? round($relationship_count / $posts_with_topics, 1) : 0.0;

      return [
        'postCount' => $post_count,
        'topicCount' => $topic_count,
        'relationshipCount' => $relationship_count,
        'avgTopicsPerPost' => $avg,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to gather local metrics: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Check sync status - compare local vs API counts.
   */
  public function check(Request $request) {
    $local = $this->getLocalMetrics();
    if (!$local) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Could not gather local metrics'],
      ], 500);
    }

    $state = \Drupal::state();
    $last_sync = $state->get('ttd_last_sync_at', '');
    $query_params = [];
    if (!empty($last_sync)) {
      $query_params['since'] = $last_sync;
    }

    $response = $this->apiRequest('GET', '/sync/check', NULL, $query_params);

    if (!$response || !isset($response['apiCounts'])) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Could not check sync status'],
      ], 500);
    }

    $api = $response['apiCounts'];

    $result = [
      'local' => $local,
      'api' => $api,
      'diff' => [
        'topics' => max(0, $api['topics'] - $local['topicCount']),
        'relationships' => max(0, $api['relationships'] - $local['relationshipCount']),
        'unanalyzedPosts' => max(0, $local['postCount'] - $api['posts']),
      ],
      'lastSyncAt' => $last_sync ?: NULL,
    ];

    if (isset($response['sinceCounts'])) {
      $result['sinceCounts'] = $response['sinceCounts'];
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $result,
    ]);
  }

  /**
   * Start sync operation.
   *
   * In Drupal, we use Advanced Queue instead of Action Scheduler.
   * The sync is initiated and jobs are queued.
   */
  public function start(Request $request) {
    $state = \Drupal::state();

    // Clear previous sync state.
    $state->delete('ttd_active_sync');
    $state->delete('ttd_sync_status_cache');

    $last_sync = $state->get('ttd_last_sync_at', '');
    $is_incremental = !empty($last_sync);

    // Notify API that sync is starting.
    try {
      $start_response = $this->apiRequest('POST', '/sync/start', [
        'incremental' => $is_incremental,
      ]);
    }
    catch (\Exception $e) {
      // Non-critical.
    }

    $sync_state = [
      'started_at' => time(),
      'incremental' => $is_incremental,
      'since' => $last_sync ?: NULL,
      'api_request_id' => $start_response['request_id'] ?? NULL,
      'status' => 'running',
    ];

    $state->set('ttd_active_sync', $sync_state);

    // Queue the sync pull via Advanced Queue.
    try {
      $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $queue_storage->load('ttd_topics_analysis');

      if ($queue) {
        $job = \Drupal\advancedqueue\Job::create('ttd_sync_pull', [
          'since' => $last_sync ?: NULL,
          'incremental' => $is_incremental,
        ]);
        $queue->enqueueJob($job);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to queue sync job: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $sync_state,
    ]);
  }

  /**
   * Get sync progress.
   */
  public function progress(Request $request) {
    $state = \Drupal::state();
    $active = $state->get('ttd_active_sync');

    if (!$active) {
      return new JsonResponse([
        'success' => TRUE,
        'data' => ['active' => FALSE],
      ]);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => array_merge($active, ['active' => $active['status'] === 'running']),
    ]);
  }

  /**
   * Cancel sync operation.
   */
  public function cancel(Request $request) {
    $state = \Drupal::state();
    $state->delete('ttd_active_sync');
    $state->delete('ttd_sync_status_cache');

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['message' => 'Sync cancelled'],
    ]);
  }

}
