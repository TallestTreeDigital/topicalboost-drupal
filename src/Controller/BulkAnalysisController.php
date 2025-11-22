<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for bulk analysis functionality.
 */
class BulkAnalysisController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a BulkAnalysisController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Builds the bulk analysis page.
   */
  public function buildPage() {
    $form = \Drupal::formBuilder()->getForm('\Drupal\topicalboost\Form\BulkAnalysisForm');

    return [
      '#theme' => 'ttd_bulk_analysis_page',
      '#form' => $form,
      '#attached' => [
        'library' => [
          'ttd_topics/bulk_analysis',
          'ttd_topics/ttd_topics.styles',
          'ttd_topics/select2',
        ],
        'drupalSettings' => [
          'ttd_topics' => [
            'bulk_analysis_endpoints' => [
              'count' => \Drupal::url('topicalboost.bulk_analysis.count'),
              'initiate' => \Drupal::url('topicalboost.bulk_analysis.initiate'),
              'progress' => \Drupal::url('topicalboost.bulk_analysis.progress'),
              'reset' => \Drupal::url('topicalboost.bulk_analysis.reset'),
              'poll' => \Drupal::url('topicalboost.bulk_analysis.poll'),
              'apply_results' => \Drupal::url('topicalboost.bulk_analysis.apply_results'),
            ],
            'nonce' => \Drupal::csrfToken()->get('ttd_bulk_analysis'),
          ],
        ],
      ],
    ];
  }

  /**
   * Get node count for bulk analysis filtering.
   */
  public function getNodeCount(Request $request) {
    $filters = $this->parseFilters($request);

    // Validate content types.
    if (empty($filters['content_types'])) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'No content types selected for analysis',
        'data' => ['count' => 0],
      ]);
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', $filters['content_types'], 'IN');

    // Apply date filters if provided.
    if (!empty($filters['start_date'])) {
      $query->condition('n.created', strtotime($filters['start_date']), '>=');
    }
    if (!empty($filters['end_date'])) {
      $query->condition('n.created', strtotime($filters['end_date']) + 86400, '<');
    }

    // Apply status filters.
    if ($filters['include_drafts']) {
      $query->condition('n.status', [0, 1], 'IN');
    }
    else {
      $query->condition('n.status', 1);
    }

    // Filter by topicless posts if requested.
    if ($filters['only_topicless'] && $this->database->schema()->tableExists('node__field_ttd_topics')) {
      $query->leftJoin('node__field_ttd_topics', 'tt', 'n.nid = tt.entity_id');
      $query->isNull('tt.field_ttd_topics_target_id');
    }

    // Filter by reanalysis if not enabled.
    if (!$filters['reanalyze'] && $this->database->schema()->tableExists('node__field_ttd_last_analyzed')) {
      $query->leftJoin('node__field_ttd_last_analyzed', 'tla', 'n.nid = tla.entity_id');
      $query->isNull('tla.field_ttd_last_analyzed_value');
    }

    $count = $query->countQuery()->execute()->fetchField();

    $count = (int) $count;

    // If count is 0, return success with helpful message
    if ($count === 0) {
      return new JsonResponse([
        'success' => TRUE,
        'message' => 'No published content found for the selected content types and filters.',
        'data' => ['count' => 0],
      ]);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['count' => $count],
    ]);
  }

  /**
   * Initiate bulk analysis using the bulk API endpoint.
   */
  public function initiateAnalysis(Request $request) {
    // SAFEGUARD 1: Check if an analysis is already in progress
    $existing_request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
    if ($existing_request_id) {
      // Check if the existing analysis is actually still running or just stale
      $apply_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress');

      // If apply is complete, the analysis lifecycle is ending but cleanup hasn't happened yet
      if ($apply_progress && isset($apply_progress['stage']) && $apply_progress['stage'] === 'complete') {
        $completed_at = \Drupal::state()->get('topicalboost.bulk_analysis.completed_at');
        $current_time = time();
        // Allow new analysis after 60 seconds of completion to account for cleanup delay
        if ($completed_at && ($current_time - $completed_at) < 60) {
          return new JsonResponse([
            'success' => FALSE,
            'message' => 'A previous analysis is still completing. Please wait a moment and try again.',
          ]);
        }
      } else {
        // Analysis is actively running (not completed yet)
        return new JsonResponse([
          'success' => FALSE,
          'message' => 'An analysis is currently in progress. Please wait for it to complete before starting a new one.',
        ]);
      }
    }

    $filters = $this->parseFilters($request);

    // Get total node count.
    $content_count = $this->getNodeCountForFilters($filters);

    if ($content_count === 0) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'No nodes found matching the criteria',
      ]);
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    // Call the bulk initiate API endpoint.
    $client = new Client();

    try {
      $response = $client->post($api_base_url . '/analyze/bulk/initiate', [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'content_count' => $content_count,
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody(), TRUE);

      if (isset($result['request_id'])) {
        $request_id = $result['request_id'];

        // Store the request ID in state.
        \Drupal::state()->set('topicalboost.bulk_analysis.request_id', $request_id);
        \Drupal::state()->set('topicalboost.bulk_analysis.filters', $filters);
        \Drupal::state()->set('topicalboost.bulk_analysis.content_count', $content_count);

        // Calculate pages and batch size (50 nodes per batch like WordPress)
        $batch_size = 50;
        $page_count = ceil($content_count / $batch_size);

        // Clear any existing bulk analysis jobs.
        $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
        $queue = $queue_storage->load('ttd_topics_analysis');
        $this->clearBulkAnalysisJobs($queue);

        // Schedule bulk batch jobs.
        for ($page = 1; $page <= $page_count; $page++) {
          $job = Job::create('ttd_bulk_batch_send', [
            'request_id' => $request_id,
            'page' => $page,
            'page_count' => $page_count,
            'filters' => $filters,
            'batch_size' => $batch_size,
          ]);
          $queue->enqueueJob($job);
        }

        // Start the analysis poller for hands-off automation.
        $poller_job = Job::create('ttd_bulk_analysis_poller', [
          'request_id' => $request_id,
        ]);
        $queue->enqueueJob($poller_job);

        return new JsonResponse([
          'success' => TRUE,
          'data' => [
            'request_id' => $request_id,
            'total_nodes' => $content_count,
            'page_count' => $page_count,
            'message' => 'Bulk analysis initiated. Batches are being sent in the background.',
          ],
        ]);
      }
      else {
        return new JsonResponse([
          'success' => FALSE,
          'message' => $result['message'] ?? 'Failed to initiate bulk analysis',
        ]);
      }

    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error initiating bulk analysis: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'API request failed: ' . $e->getMessage(),
      ]);
    }
  }

  /**
   * Poll bulk analysis progress.
   */
  public function pollAnalysis(Request $request) {
    $request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');

    \Drupal::logger('ttd_topics')->debug('pollAnalysis() called - current request_id: @request_id', [
      '@request_id' => $request_id ?? 'NULL',
    ]);

    // SAFEGUARD: Sanity check - ensure state consistency
    // If frontend sends a different request_id, it means they might be out of sync
    $frontend_request_id = $request->query->get('request_id');
    if ($frontend_request_id && $request_id && $frontend_request_id !== $request_id) {
      \Drupal::logger('ttd_topics')->warning('Frontend/backend request ID mismatch. Frontend: @frontend, Backend: @backend', [
        '@frontend' => $frontend_request_id,
        '@backend' => $request_id,
      ]);
      // Return backend's current state, which is the source of truth
    }

    if (!$request_id) {
      \Drupal::logger('ttd_topics')->debug('pollAnalysis() returning empty state - no active request_id');
      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'request_id' => NULL,
          'batch_progress' => ['completed' => 0, 'total' => 0],
          'analysis_status' => NULL,
          'apply_progress' => NULL,
          'content_count' => 0,
        ],
      ]);
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    $client = new Client();

    try {
      // Get batch progress from queue.
      $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $queue_storage->load('ttd_topics_analysis');
      $batch_progress = $this->getBatchProgress($queue, $request_id);

      // Poll API for analysis status.
      $response = $client->get($api_base_url . '/poll/analysis', [
        'headers' => ['x-api-key' => $api_key],
        'query' => ['request_id' => $request_id],
        'timeout' => 10,
      ]);

      $analysis_status = json_decode($response->getBody(), TRUE);

      // Debug logging to see the actual API response structure.
      \Drupal::logger('ttd_topics')->info('API Response for request @request_id: @response', [
        '@request_id' => $request_id,
        '@response' => json_encode($analysis_status, JSON_PRETTY_PRINT),
      ]);

      // Auto-start applying results if analysis is complete and apply hasn't started yet.
      if (isset($analysis_status['message']) && $analysis_status['message'] === 'Analysis complete') {
        $apply_progress = $this->getApplyProgress($request_id);

        // If no apply progress exists yet, automatically start applying results.
        if (!$apply_progress) {
          $this->autoStartApplyResults($request_id);
          $apply_progress = $this->getApplyProgress($request_id);
        }
      }
      else {
        $apply_progress = $this->getApplyProgress($request_id);
      }

      // Only clean up when apply is actually complete (not during apply processing).
      // This prevents early cleanup if user refreshes page during apply phase.
      if ($apply_progress && isset($apply_progress['stage']) && $apply_progress['stage'] === 'complete') {
        $completed_at = \Drupal::state()->get('topicalboost.bulk_analysis.completed_at');
        $current_time = time();

        // Clean up state after 30 seconds of apply being complete.
        if ($completed_at && ($current_time - $completed_at) > 30) {
          $this->cleanupCompletedAnalysis();
          // Return clean state.
          return new JsonResponse([
            'success' => TRUE,
            'data' => [
              'request_id' => NULL,
              'batch_progress' => ['completed' => 0, 'total' => 0],
              'analysis_status' => NULL,
              'apply_progress' => NULL,
              'content_count' => 0,
            ],
          ]);
        }
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'request_id' => $request_id,
          'batch_progress' => $batch_progress,
          'analysis_status' => $analysis_status,
          'apply_progress' => $apply_progress,
          'content_count' => \Drupal::state()->get('topicalboost.bulk_analysis.content_count', 0),
        ],
      ]);

    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error polling bulk analysis: @error', ['@error' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Failed to poll analysis status',
      ]);
    }
  }

  /**
   * Apply analysis results (Step 3) - using optimized /v2/result/posts endpoint.
   */
  public function applyResults(Request $request) {
    $request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');

    if (!$request_id) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'No active bulk analysis found',
      ]);
    }

    // Schedule jobs to retrieve and apply results.
    $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
    $queue = $queue_storage->load('ttd_topics_analysis');

    // Clear any existing apply jobs.
    $this->clearApplyJobs($queue, $request_id);

    // Get content count for progress tracking
    $content_count = \Drupal::state()->get('topicalboost.bulk_analysis.content_count', 0);

    // Initialize apply progress for posts-based processing
    \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', [
      'stage' => 'posts',
      'posts' => ['completed' => 0, 'total' => $content_count, 'current_page' => 1],
    ]);

    // Schedule optimized posts retrieval job (single call gets posts + entities).
    $job = Job::create('ttd_bulk_apply_posts_optimized', [
      'request_id' => $request_id,
      'page' => 1,
    ]);
    $queue->enqueueJob($job);

    return new JsonResponse([
      'success' => TRUE,
      'message' => 'Started applying analysis results (optimized)',
    ]);
  }

  /**
   * Get bulk analysis progress.
   */
  public function getProgress(Request $request) {
    // This is now handled by pollAnalysis - keeping for compatibility.
    return $this->pollAnalysis($request);
  }

  /**
   * Reset bulk analysis (clear queue and state).
   */
  public function resetAnalysis(Request $request) {
    $request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
    $cleared_jobs = 0;

    \Drupal::logger('ttd_topics')->warning('resetAnalysis() called - request_id before deletion: @request_id', [
      '@request_id' => $request_id ?? 'NULL',
    ]);

    // SAFEGUARD: Log reset attempts for audit purposes
    if ($request_id) {
      \Drupal::logger('ttd_topics')->warning('Reset analysis requested for request @request_id', [
        '@request_id' => $request_id,
      ]);
    }

    if ($request_id) {
      $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
      $queue = $queue_storage->load('ttd_topics_analysis');

      // Count jobs before clearing for logging.
      $bulk_jobs_count = $this->countBulkAnalysisJobs();
      $apply_jobs_count = $this->countApplyJobs($request_id);
      $cleared_jobs = $bulk_jobs_count + $apply_jobs_count;

      \Drupal::logger('ttd_topics')->warning('Clearing @bulk_count bulk jobs and @apply_count apply jobs', [
        '@bulk_count' => $bulk_jobs_count,
        '@apply_count' => $apply_jobs_count,
      ]);

      // Clear all related jobs.
      $this->clearBulkAnalysisJobs($queue);
      $this->clearApplyJobs($queue, $request_id);

      // Clear all related state.
      \Drupal::logger('ttd_topics')->warning('Deleting state keys for request @request_id', [
        '@request_id' => $request_id,
      ]);

      \Drupal::state()->delete('topicalboost.bulk_analysis.request_id');
      \Drupal::state()->delete('topicalboost.bulk_analysis.filters');
      \Drupal::state()->delete('topicalboost.bulk_analysis.content_count');
      \Drupal::state()->delete('topicalboost.bulk_analysis.apply_progress');
      \Drupal::state()->delete('topicalboost.bulk_analysis.completed_at');
      \Drupal::state()->delete('topicalboost.bulk_analysis.customer_id_page_count');
      \Drupal::state()->delete('topicalboost.bulk_analysis.entity_page_count');

      // Verify state was actually deleted
      $verify_request_id = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
      \Drupal::logger('ttd_topics')->warning('VERIFICATION: request_id after deletion: @request_id', [
        '@request_id' => $verify_request_id ?? 'NULL (DELETED SUCCESSFULLY)',
      ]);

      // Log the cancellation.
      \Drupal::logger('ttd_topics')->info('Bulk analysis cancelled - cleared @count queued jobs for request @request_id', [
        '@count' => $cleared_jobs,
        '@request_id' => $request_id,
      ]);
    } else {
      \Drupal::logger('ttd_topics')->warning('resetAnalysis() called but no active request_id found');
    }

    $message = $cleared_jobs > 0
      ? "Bulk analysis cancelled - cleared {$cleared_jobs} queued jobs"
      : 'Bulk analysis reset successfully - no active jobs found';

    return new JsonResponse([
      'success' => TRUE,
      'message' => $message,
      'data' => ['cleared_jobs' => $cleared_jobs],
    ]);
  }

  /**
   * Get node count for given filters.
   */
  private function getNodeCountForFilters($filters) {
    $query = $this->database->select('node_field_data', 'n');
    $query->condition('n.type', $filters['content_types'], 'IN');

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

    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Get batch progress from queue.
   */
  private function getBatchProgress($queue, $request_id) {
    $total_batches = $this->database->select('advancedqueue', 'aq')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', 'ttd_bulk_batch_send')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->countQuery()
      ->execute()
      ->fetchField();

    $pending_batches = $this->database->select('advancedqueue', 'aq')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', 'ttd_bulk_batch_send')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();

    $completed_batches = $total_batches - $pending_batches;

    return [
      'total' => (int) $total_batches,
      'completed' => (int) $completed_batches,
      'pending' => (int) $pending_batches,
    ];
  }

  /**
   * Get apply progress.
   */
  private function getApplyProgress($request_id) {
    return \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', NULL);
  }

  /**
   * Clear bulk analysis jobs from queue.
   */
  private function clearBulkAnalysisJobs($queue) {
    // Clear batch send jobs and poller jobs.
    $this->database->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_batch_send', 'ttd_bulk_analysis_poller'], 'IN')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->execute();
  }

  /**
   * Clear apply jobs from queue.
   */
  private function clearApplyJobs($queue, $request_id) {
    $this->database->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities'], 'IN')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->execute();
  }

  /**
   * Count bulk analysis jobs in queue.
   */
  private function countBulkAnalysisJobs() {
    return $this->database->select('advancedqueue', 'aq')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_batch_send', 'ttd_bulk_analysis_poller'], 'IN')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Count apply jobs in queue for a specific request.
   */
  private function countApplyJobs($request_id) {
    return $this->database->select('advancedqueue', 'aq')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities'], 'IN')
      ->condition('payload', '%' . $request_id . '%', 'LIKE')
      ->condition('state', ['queued', 'processing'], 'IN')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Parse filters from request.
   */
  private function parseFilters(Request $request) {
    $content = json_decode($request->getContent(), TRUE);

    return [
      'content_types' => $content['content_types'] ?? [],
      'start_date' => $content['start_date'] ?? '',
      'end_date' => $content['end_date'] ?? '',
      'include_drafts' => $content['include_drafts'] ?? FALSE,
      'only_topicless' => $content['only_topicless'] ?? FALSE,
      'reanalyze' => $content['reanalyze'] ?? FALSE,
    ];
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

      \Drupal::logger('ttd_topics')->info('Auto-started applying results (optimized) for request @request_id', [
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
   * Clean up completed analysis state.
   */
  private function cleanupCompletedAnalysis() {
    // Clear all bulk analysis state.
    \Drupal::state()->delete('topicalboost.bulk_analysis.request_id');
    \Drupal::state()->delete('topicalboost.bulk_analysis.filters');
    \Drupal::state()->delete('topicalboost.bulk_analysis.content_count');
    \Drupal::state()->delete('topicalboost.bulk_analysis.apply_progress');
    \Drupal::state()->delete('topicalboost.bulk_analysis.completed_at');
    \Drupal::state()->delete('topicalboost.bulk_analysis.customer_id_page_count');
    \Drupal::state()->delete('topicalboost.bulk_analysis.entity_page_count');

    \Drupal::logger('ttd_topics')->info('Cleaned up completed bulk analysis state');
  }

}
