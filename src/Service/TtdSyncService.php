<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;

/**
 * Handles site data sync between Drupal and the TopicalBoost API.
 */
class TtdSyncService {

  const QUEUE_ID = 'ttd_topics_analysis';
  const SYNC_JOB_TYPE = 'ttd_sync_pull';
  const HIDDEN_SYNC_JOB_TYPE = 'ttd_sync_hidden_entities';
  const LOCAL_HIDDEN_BACKFILL_JOB_TYPE = 'ttd_backfill_local_hidden_entities';
  const LOCAL_MANUAL_TOPIC_BACKFILL_JOB_TYPE = 'ttd_backfill_local_manual_topics';
  const CURATION_SYNC_JOB_TYPE = 'ttd_sync_curation_scores';
  const SYNC_STATE_KEY = 'ttd_active_sync';
  const LAST_SYNC_KEY = 'ttd_last_sync_at';
  const API_HIDDEN_STATE_KEY = 'ttd_api_hidden_entity_ids';
  const LOCAL_HIDDEN_BACKFILL_COMPLETE_KEY = 'ttd_local_hidden_backfill_complete';
  const LOCAL_HIDDEN_BACKFILL_CURSOR_KEY = 'ttd_local_hidden_backfill_cursor';
  const LOCAL_HIDDEN_BACKFILL_STATS_KEY = 'ttd_local_hidden_backfill_stats';
  const LOCAL_HIDDEN_BACKFILL_RETRY_KEY = 'ttd_local_hidden_backfill_retry_at';
  const LOCAL_HIDDEN_BACKFILL_LAST_QUEUED_KEY = 'ttd_local_hidden_backfill_last_queued';
  const LOCAL_MANUAL_TOPIC_BACKFILL_COMPLETE_KEY = 'ttd_local_manual_topic_backfill_complete';
  const LOCAL_MANUAL_TOPIC_BACKFILL_CURSOR_KEY = 'ttd_local_manual_topic_backfill_cursor';
  const LOCAL_MANUAL_TOPIC_BACKFILL_STATS_KEY = 'ttd_local_manual_topic_backfill_stats';
  const LOCAL_MANUAL_TOPIC_BACKFILL_RETRY_KEY = 'ttd_local_manual_topic_backfill_retry_at';
  const LOCAL_MANUAL_TOPIC_BACKFILL_LAST_QUEUED_KEY = 'ttd_local_manual_topic_backfill_last_queued';
  const CURATION_SCORE_COLLECTION = 'ttd_topics.curation_scores';
  const CURATION_LAST_SYNC_KEY = 'ttd_curation_scores_sync_last_run';
  const TOPIC_PAGE_SIZE = 25;

  /**
   * Make an authenticated TopicalBoost API request.
   */
  public function apiRequest(string $method, string $path, ?array $json = NULL, array $query = []) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return NULL;
    }

    $options = [
      'headers' => array_merge(\ttd_topics_api_headers($api_key), [
        'x-tb-plugin-version' => $this->getModuleVersion(),
        'x-tb-platform' => 'drupal',
      ]),
      'timeout' => 30,
    ];

    if ($json !== NULL) {
      $options['json'] = $json;
    }
    if (!empty($query)) {
      $options['query'] = $query;
    }

    try {
      $response = \Drupal::httpClient()->request($method, TOPICALBOOST_API_ENDPOINT . $path, $options);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Sync API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get local metrics for sync comparison.
   */
  public function getLocalMetrics() {
    $database = \Drupal::database();
    $config = \Drupal::config('ttd_topics.settings');

    try {
      $curation_metrics = $this->getLocalCurationMetrics();
      $enabled_types = array_filter($config->get('enabled_content_types') ?: ['article']);
      if (empty($enabled_types)) {
        return array_merge([
          'postCount' => 0,
          'topicCount' => 0,
          'relationshipCount' => 0,
          'avgTopicsPerPost' => 0.0,
        ], $curation_metrics);
      }

      $post_count = (int) $database->select('node_field_data', 'n')
        ->condition('n.type', $enabled_types, 'IN')
        ->condition('n.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      $topic_count = (int) $database->select('taxonomy_term_field_data', 'td')
        ->condition('td.vid', 'ttd_topics')
        ->condition('td.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      $relationship_count = 0;
      $posts_with_topics = 0;
      if ($database->schema()->tableExists('node__field_ttd_topics')) {
        $query = $database->select('node__field_ttd_topics', 'ft');
        $query->join('node_field_data', 'n', 'ft.entity_id = n.nid');
        $query->condition('n.type', $enabled_types, 'IN');
        $query->condition('n.status', 1);
        $relationship_count = (int) $query->countQuery()->execute()->fetchField();

        $posts_query = $database->select('node__field_ttd_topics', 'ft');
        $posts_query->join('node_field_data', 'n', 'ft.entity_id = n.nid');
        $posts_query->condition('n.type', $enabled_types, 'IN');
        $posts_query->condition('n.status', 1);
        $posts_query->addExpression('COUNT(DISTINCT ft.entity_id)', 'posts_with_topics');
        $posts_with_topics = (int) $posts_query->execute()->fetchField();
      }

      return array_merge([
        'postCount' => $post_count,
        'topicCount' => $topic_count,
        'relationshipCount' => $relationship_count,
        'avgTopicsPerPost' => $posts_with_topics > 0 ? round($relationship_count / $posts_with_topics, 1) : 0.0,
      ], $curation_metrics);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to gather local sync metrics: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Report local site metrics to TopicalBoost.
   */
  public function reportSiteMetrics() {
    $metrics = $this->getLocalMetrics();
    if (!$metrics) {
      return NULL;
    }

    return $this->apiRequest('POST', '/telemetry/site-metrics', $metrics);
  }

  /**
   * Get local curation rollout metrics for the internal admin checklist.
   */
  private function getLocalCurationMetrics(): array {
    $config = \Drupal::config('ttd_topics.settings');
    $scores = \Drupal::keyValue(static::CURATION_SCORE_COLLECTION)->getAll();
    $suppressed = 0;

    foreach ($scores as $score) {
      if (is_array($score) && array_key_exists('should_curate', $score) && empty($score['should_curate'])) {
        $suppressed++;
      }
    }

    return [
      'curationScoresEnabled' => function_exists('ttd_topics_curation_scores_enabled')
        ? \ttd_topics_curation_scores_enabled()
        : (bool) ($config->get('curation_scores_enabled') ?? FALSE),
      'curationScoreThreshold' => function_exists('ttd_topics_curation_score_threshold')
        ? \ttd_topics_curation_score_threshold()
        : max(0.0, min(5.0, (float) ($config->get('curation_score_threshold') ?? 2))),
      'curationScoresLastSyncedAt' => $this->formatTimestampForApi(\Drupal::state()->get(static::CURATION_LAST_SYNC_KEY)),
      'curationScoreTerms' => count($scores),
      'curationSuppressedTerms' => $suppressed,
    ];
  }

  private function formatTimestampForApi($value): ?string {
    if (is_numeric($value)) {
      return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
    }

    if (is_string($value) && trim($value) !== '') {
      $timestamp = strtotime($value);
      if ($timestamp !== FALSE) {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
      }
    }

    return NULL;
  }

  /**
   * Compare local and API-side counts.
   */
  public function checkSyncStatus() {
    $local = $this->getLocalMetrics();
    if (!$local) {
      return NULL;
    }

    $last_sync = \Drupal::state()->get(static::LAST_SYNC_KEY, '');
    $query_params = [];
    if (!empty($last_sync)) {
      $query_params['since'] = $last_sync;
    }

    $response = $this->apiRequest('GET', '/sync/check', NULL, $query_params);
    if (!$response || !isset($response['apiCounts'])) {
      return NULL;
    }

    $api = $response['apiCounts'];
    $result = [
      'local' => $local,
      'api' => $api,
      'aliasCleanup' => $response['aliasCleanup'] ?? $response['alias_cleanup'] ?? [
        'required' => FALSE,
        'pending' => 0,
        'pending_count' => 0,
      ],
      'diff' => [
        'topics' => max(0, ($api['topics'] ?? 0) - $local['topicCount']),
        'relationships' => max(0, ($api['relationships'] ?? 0) - $local['relationshipCount']),
        'unanalyzedPosts' => max(0, $local['postCount'] - ($api['posts'] ?? 0)),
      ],
      'lastSyncAt' => $last_sync ?: NULL,
    ];

    if (isset($response['sinceCounts'])) {
      $result['sinceCounts'] = $response['sinceCounts'];
    }
    if (!empty($response['syncDisabled']) || !empty($response['sync_disabled'])) {
      $result['syncDisabled'] = TRUE;
      $result['syncDisabledReason'] = $response['syncDisabledReason'] ?? $response['sync_disabled_reason'] ?? NULL;
    }

    return $result;
  }

  /**
   * Start a sync and enqueue pull jobs.
   */
  public function startSync(?array $status = NULL) {
    $this->cleanupSyncJobs();
    \Drupal::state()->delete('ttd_sync_status_cache');

    $status = $status ?: $this->checkSyncStatus();
    if (!$status) {
      throw new \RuntimeException('Could not determine sync status');
    }

    if (!empty($status['syncDisabled'])) {
      return [
        'active' => FALSE,
        'status' => 'disabled',
        'message' => $status['syncDisabledReason'] ?? 'sync disabled',
      ];
    }

    $config = \Drupal::config('ttd_topics.settings');
    $batch_size = max(1, (int) ($config->get('batch_size') ?: 35));
    $last_sync = \Drupal::state()->get(static::LAST_SYNC_KEY, '');
    $is_incremental = !empty($last_sync) && isset($status['sinceCounts']);

    $sync_topics = $is_incremental
      ? (int) ($status['sinceCounts']['topics'] ?? 0)
      : (int) ($status['api']['topics'] ?? 0);
    $sync_posts = $is_incremental
      ? (int) ($status['sinceCounts']['posts'] ?? 0)
      : (int) ($status['api']['posts'] ?? 0);
    $alias_cleanup = $status['aliasCleanup'] ?? [];
    $sync_aliases = (int) ($alias_cleanup['pending_count'] ?? $alias_cleanup['pending'] ?? 0);

    $topic_page_size = static::TOPIC_PAGE_SIZE;
    $alias_page_size = 100;
    $topic_pages = $sync_topics > 0 ? (int) ceil($sync_topics / $topic_page_size) : 0;
    $rel_pages = $sync_posts > 0 ? (int) ceil($sync_posts / $batch_size) : 0;
    $alias_pages = $sync_aliases > 0 ? (int) ceil($sync_aliases / $alias_page_size) : 0;
    $jobs_scheduled = 0;

    $start_response = $this->apiRequest('POST', '/sync/start', [
      'contentCount' => $sync_posts,
      'entityCount' => $sync_topics,
      'incremental' => $is_incremental,
    ]);

    $sync_state = [
      'started_at' => \Drupal::time()->getRequestTime(),
      'total_jobs' => ($sync_topics > 0 ? 1 : 0) + ($sync_posts > 0 ? 1 : 0) + ($sync_aliases > 0 ? 1 : 0),
      'completed_jobs' => 0,
      'failed_jobs' => 0,
      'topic_pages' => $topic_pages,
      'rel_pages' => $rel_pages,
      'alias_pages' => $alias_pages,
      'posts_applied' => 0,
      'posts_skipped' => 0,
      'alias_cleanups' => 0,
      'incremental' => $is_incremental,
      'since' => $last_sync ?: NULL,
      'api_request_id' => $start_response['request_id'] ?? NULL,
      'status' => 'running',
    ];
    \Drupal::state()->set(static::SYNC_STATE_KEY, $sync_state);

    $queue = $this->getQueue();
    if (!$queue && ($topic_pages + $rel_pages + $alias_pages) > 0) {
      \Drupal::state()->delete(static::SYNC_STATE_KEY);
      throw new \RuntimeException('TopicalBoost analysis queue is not available');
    }

    if ($queue) {
      if ($sync_topics > 0) {
        $queue->enqueueJob(Job::create(static::SYNC_JOB_TYPE, [
          'type' => 'topics',
          'page' => 1,
          'page_size' => $topic_page_size,
          'since' => $last_sync ?: NULL,
          'after_id' => 0,
        ]));
        $jobs_scheduled++;
      }
      if ($sync_posts > 0) {
        $queue->enqueueJob(Job::create(static::SYNC_JOB_TYPE, [
          'type' => 'relationships',
          'page' => 1,
          'page_size' => $batch_size,
          'since' => $last_sync ?: NULL,
          'after_id' => 0,
        ]));
        $jobs_scheduled++;
      }
      if ($sync_aliases > 0) {
        $queue->enqueueJob(Job::create(static::SYNC_JOB_TYPE, [
          'type' => 'entity_aliases',
          'page' => 1,
          'page_size' => $alias_page_size,
          'since' => NULL,
          'after_id' => 0,
        ]));
        $jobs_scheduled++;
      }
    }

    if ($jobs_scheduled === 0) {
      $this->finishSync(0);
      return array_merge($sync_state, ['active' => FALSE, 'status' => 'complete']);
    }

    \Drupal::logger('ttd_topics')->info('Started @mode sync: estimated @topics topic pages, @rels relationship pages, @aliases alias pages.', [
      '@mode' => $is_incremental ? 'incremental' : 'full',
      '@topics' => $topic_pages,
      '@rels' => $rel_pages,
      '@aliases' => $alias_pages,
    ]);

    return $sync_state;
  }

  /**
   * Start sync if the API has materially more data than the site.
   */
  public function autoSyncCheck() {
    $config = \Drupal::config('ttd_topics.settings');
    if (!$config->get('auto_sync_enabled')) {
      return FALSE;
    }

    if (\Drupal::state()->get(static::SYNC_STATE_KEY)) {
      return FALSE;
    }

    if (\Drupal::state()->get('topicalboost.bulk_analysis.request_id') || \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress')) {
      return FALSE;
    }

    $status = $this->checkSyncStatus();
    if (!$status || empty($status['local']) || empty($status['api'])) {
      return FALSE;
    }

    if ($this->shouldAutoSync($status['local'], $status['api'], $status['aliasCleanup'] ?? [])) {
      $this->startSync($status);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check whether count differences warrant an API-to-site pull.
   */
  private function shouldAutoSync(array $local, array $api, array $alias_cleanup = []) {
    $tolerance = 0.05;
    $topics_synced = $this->withinTolerance($local['topicCount'], $api['topics'] ?? 0, $tolerance);
    $relationships_synced = $this->withinTolerance($local['relationshipCount'], $api['relationships'] ?? 0, $tolerance);

    $api_has_more_topics = ($api['topics'] ?? 0) > $local['topicCount'];
    $api_has_more_relationships = ($api['relationships'] ?? 0) > $local['relationshipCount'];

    return ((!$topics_synced && $api_has_more_topics) || (!$relationships_synced && $api_has_more_relationships))
      || !empty($alias_cleanup['required'])
      || (int) ($alias_cleanup['pending'] ?? 0) > 0;
  }

  /**
   * Pull one sync page from the API and apply it locally.
   */
  public function pullSyncPage(string $type, int $page, int $page_size, ?string $since = NULL, $after_id = NULL) {
    if ($after_id === NULL) {
      \Drupal::logger('ttd_topics')->notice('Cancelling legacy offset sync action for @type page @page; cursor pagination is required.', [
        '@type' => $type,
        '@page' => $page,
      ]);
      $this->cancelSync();
      return [
        'cancelled_legacy' => TRUE,
        'topics' => 0,
        'posts_applied' => 0,
        'posts_skipped' => 0,
      ];
    }

    $query = [
      'page' => $page,
      'page_size' => $page_size,
    ];
    if (!empty($since)) {
      $query['since'] = $since;
    }
    if ($after_id !== NULL) {
      $query['after_id'] = (int) $after_id;
    }

    if ($type === 'topics') {
      $response = $this->apiRequest('GET', '/sync/pull/topics', NULL, $query);
      if (!$response || !isset($response['entities'])) {
        throw new \RuntimeException('Failed to fetch topics page ' . $page);
      }

      foreach ($response['entities'] as $entity) {
        $this->mergeEntity($entity);
      }

      $this->scheduleNextSyncPullIfNeeded($type, $page, $page_size, $since, $response);
      $this->recordPullResult([]);
      return [
        'topics' => count($response['entities']),
        'posts_applied' => 0,
        'posts_skipped' => 0,
      ];
    }

    if ($type === 'entity_aliases') {
      $response = $this->apiRequest('GET', '/sync/pull/entity-aliases', NULL, $query);
      if (!$response || !isset($response['aliases']) || !is_array($response['aliases'])) {
        throw new \RuntimeException('Failed to fetch entity aliases page ' . $page);
      }

      $applied_alias_ids = [];
      $skipped_alias_ids = [];
      foreach ($response['aliases'] as $alias_cleanup) {
        $canonical = $alias_cleanup['canonical'] ?? NULL;
        $canonical_id = (int) ($alias_cleanup['canonical_id'] ?? ($canonical['id'] ?? 0));
        $alias_id = (int) ($alias_cleanup['alias_id'] ?? 0);
        $cleanup_version = (int) ($alias_cleanup['cleanup_version'] ?? 1);

        if ($canonical_id <= 0 || $alias_id <= 0 || !is_array($canonical)) {
          $skipped_alias_ids[] = [
            'alias_id' => $alias_id,
            'reason' => 'invalid_alias_payload',
          ];
          continue;
        }

        $result = $this->applyEntityAliasCleanup($canonical_id, $alias_id, $canonical, $cleanup_version);
        if ($result['applied']) {
          $applied_alias_ids[] = $alias_id;
        }
        else {
          $skipped_alias_ids[] = [
            'alias_id' => $alias_id,
            'reason' => $result['reason'] ?? 'cleanup_error',
          ];
        }
      }

      if (!empty($applied_alias_ids) || !empty($skipped_alias_ids)) {
        $this->apiRequest('POST', '/sync/alias-cleanup/complete', [
          'applied_alias_ids' => array_values(array_unique(array_map('intval', $applied_alias_ids))),
          'skipped_alias_ids' => $skipped_alias_ids,
        ]);
      }

      $this->scheduleNextSyncPullIfNeeded($type, $page, $page_size, NULL, $response);
      $this->recordPullResult([
        'alias_cleanups' => count($applied_alias_ids),
      ]);
      return [
        'topics' => 0,
        'posts_applied' => 0,
        'posts_skipped' => 0,
        'alias_cleanups' => count($applied_alias_ids),
      ];
    }

    if ($type !== 'relationships') {
      throw new \InvalidArgumentException('Invalid sync pull type: ' . $type);
    }

    $response = $this->apiRequest('GET', '/sync/pull/relationships', NULL, $query);
    if (!$response || !isset($response['posts'])) {
      throw new \RuntimeException('Failed to fetch relationships page ' . $page);
    }

    $entities = $response['entities'] ?? [];
    foreach ($entities as $entity) {
      $this->mergeEntity($entity);
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $applied = 0;
    $skipped = 0;
    foreach ($response['posts'] as $post) {
      $node_id = $post['customer_id'] ?? $post['customerId'] ?? $post['postId'] ?? NULL;
      if (!$node_id) {
        $skipped++;
        continue;
      }

      $node = $node_storage->load($node_id);
      if (!$node instanceof NodeInterface || !$node->hasField('field_ttd_topics')) {
        $skipped++;
        continue;
      }

      $entity_ids = $post['entity_ids'] ?? $post['entityIds'] ?? [];
      $this->applyEntitiesToNode($node, $entity_ids, $entities);
      $applied++;
    }

    $this->scheduleNextSyncPullIfNeeded($type, $page, $page_size, $since, $response);
    $this->recordPullResult([
      'posts_applied' => $applied,
      'posts_skipped' => $skipped,
    ]);

    return [
      'topics' => 0,
      'posts_applied' => $applied,
      'posts_skipped' => $skipped,
    ];
  }

  /**
   * Get current sync progress.
   */
  public function getProgress() {
    $active = \Drupal::state()->get(static::SYNC_STATE_KEY);
    if (!$active) {
      return ['active' => FALSE];
    }

    $queue_counts = $this->getSyncQueueCounts();
    $total = (int) ($active['total_jobs'] ?? 0);
    $completed = (int) ($active['completed_jobs'] ?? 0);
    $failed = (int) ($active['failed_jobs'] ?? 0) + (int) ($queue_counts['failure'] ?? 0);
    $pending = (int) ($queue_counts['queued'] ?? 0);
    $running = (int) ($queue_counts['processing'] ?? 0);
    $is_complete = $total === 0 || ($pending === 0 && $running === 0 && ($completed + $failed) >= $total);

    $stats = [
      'active' => !$is_complete,
      'completed' => $completed,
      'failed' => $failed,
      'pending' => $pending,
      'running' => $running,
      'total' => $total,
      'is_complete' => $is_complete,
      'started_at' => $active['started_at'] ?? NULL,
      'posts_applied' => $active['posts_applied'] ?? 0,
      'posts_skipped' => $active['posts_skipped'] ?? 0,
      'alias_cleanups' => $active['alias_cleanups'] ?? 0,
    ];

    if ($is_complete) {
      $this->finishSync($failed);
    }

    return $stats;
  }

  /**
   * Cancel an active sync.
   */
  public function cancelSync() {
    $this->cleanupSyncJobs();
    \Drupal::state()->delete(static::SYNC_STATE_KEY);
    \Drupal::state()->delete('ttd_sync_status_cache');
  }

  /**
   * Fetch hidden entities from the API and apply API-owned hide state locally.
   */
  public function syncHiddenEntities() {
    $response = $this->apiRequest('GET', '/result/hidden-entities');
    if (!$response || !isset($response['hidden']) || !is_array($response['hidden'])) {
      return ['hidden' => 0, 'unhidden' => 0, 'already_hidden' => 0, 'not_found' => 0];
    }

    $api_hidden_ids = [];
    foreach ($response['hidden'] as $hidden_entity) {
      $entity_id = (int) ($hidden_entity['entity_id'] ?? 0);
      if ($entity_id > 0) {
        $api_hidden_ids[$entity_id] = TRUE;
      }
    }

    $state = \Drupal::state();
    $previously_hidden = array_fill_keys(array_map('intval', $state->get(static::API_HIDDEN_STATE_KEY, [])), TRUE);
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $stats = ['hidden' => 0, 'unhidden' => 0, 'already_hidden' => 0, 'not_found' => 0];
    $next_state = [];

    foreach ($api_hidden_ids as $entity_id => $_) {
      $terms = $term_storage->loadByProperties([
        'vid' => 'ttd_topics',
        'field_ttd_id' => (string) $entity_id,
      ]);
      if (empty($terms)) {
        $stats['not_found']++;
        continue;
      }

      $term = reset($terms);
      $was_api_hidden = isset($previously_hidden[$entity_id]);
      $is_hidden = $term->hasField('field_hide') && (bool) $term->get('field_hide')->value;
      if ($is_hidden) {
        $stats['already_hidden']++;
        if ($was_api_hidden) {
          $next_state[$entity_id] = TRUE;
        }
      }
      else {
        if ($term->hasField('field_hide')) {
          $term->set('field_hide', TRUE);
          $term->save();
        }
        $stats['hidden']++;
        $next_state[$entity_id] = TRUE;
      }
    }

    foreach ($previously_hidden as $entity_id => $_) {
      if (isset($api_hidden_ids[$entity_id])) {
        continue;
      }

      $terms = $term_storage->loadByProperties([
        'vid' => 'ttd_topics',
        'field_ttd_id' => (string) $entity_id,
      ]);
      if (empty($terms)) {
        continue;
      }

      $term = reset($terms);
      if ($term->hasField('field_hide') && (bool) $term->get('field_hide')->value) {
        $term->set('field_hide', FALSE);
        $term->save();
        $stats['unhidden']++;
      }
    }

    $state->set(static::API_HIDDEN_STATE_KEY, array_keys($next_state));
    $state->set('ttd_hidden_entities_sync_last_run', \Drupal::time()->getRequestTime());

    return $stats;
  }

  /**
   * Count locally hidden topics with TopicalBoost entity IDs.
   */
  public function countLocalHiddenEntities(): int {
    $database = \Drupal::database();
    if (!$this->hasLocalHiddenBackfillTables($database)) {
      return 0;
    }

    $query = $database->select('taxonomy_term_field_data', 'td');
    $query->join('taxonomy_term__field_hide', 'tfh', 'tfh.entity_id = td.tid AND tfh.deleted = 0 AND tfh.field_hide_value = 1');
    $query->join('taxonomy_term__field_ttd_id', 'ttid', 'ttid.entity_id = td.tid AND ttid.deleted = 0');
    $query->condition('td.vid', 'ttd_topics');
    $query->isNotNull('ttid.field_ttd_id_value');
    $query->condition('ttid.field_ttd_id_value', '', '<>');
    $query->addExpression('COUNT(DISTINCT td.tid)', 'count');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Send one cursor-batched page of local hidden topics to the API.
   */
  public function backfillLocalHiddenEntitiesBatch(int $batch_size = 100, int $after_term_id = 0): array {
    $batch_size = max(1, min(1000, $batch_size));
    $after_term_id = max(0, $after_term_id);
    $stats = [
      'found' => 0,
      'sent' => 0,
      'hidden' => 0,
      'already_hidden' => 0,
      'not_found' => 0,
      'failed' => 0,
      'last_term_id' => $after_term_id,
      'has_more' => FALSE,
    ];

    $rows = $this->getLocalHiddenEntityRows($batch_size, $after_term_id);
    if (empty($rows)) {
      return $stats;
    }

    $last_row = end($rows);
    $stats['found'] = count($rows);
    $stats['last_term_id'] = (int) ($last_row['term_id'] ?? $after_term_id);
    $stats['has_more'] = count($rows) === $batch_size;

    $items = [];
    foreach ($rows as $row) {
      $entity_id = (int) $row['entity_id'];
      if ($entity_id <= 0) {
        continue;
      }

      $items[] = [
        'entityId' => $entity_id,
        'entityName' => (string) $row['entity_name'],
        'reason' => 'Backfilled from Drupal hidden topic',
        'metadata' => [
          'termId' => (int) $row['term_id'],
          'localHiddenBackfill' => TRUE,
        ],
      ];
    }

    if (empty($items)) {
      return $stats;
    }

    $response = $this->apiRequest('POST', '/telemetry/editorial-signals/hidden-backfill', [
      'items' => $items,
    ]);

    if (!is_array($response) || empty($response['logged'])) {
      $stats['failed'] = count($items);
      $stats['last_term_id'] = $after_term_id;
      $stats['has_more'] = TRUE;
      return $stats;
    }

    $stats['sent'] = count($items);
    $stats['hidden'] = (int) ($response['hidden'] ?? 0);
    $stats['already_hidden'] = (int) ($response['alreadyHidden'] ?? 0);
    $stats['not_found'] = (int) ($response['notFound'] ?? 0);

    return $stats;
  }

  /**
   * Count current manual topic assignments with TopicalBoost entity IDs.
   */
  public function countLocalManualTopicAssignments(): int {
    $database = \Drupal::database();
    if (!$this->hasLocalManualTopicBackfillTables($database)) {
      return 0;
    }

    $query = $database->select('node__field_manual_topics', 'fmt')->distinct();
    $query->fields('fmt', ['entity_id', 'field_manual_topics_target_id']);
    $query->join('taxonomy_term__field_ttd_id', 'ttid', 'ttid.entity_id = fmt.field_manual_topics_target_id AND ttid.deleted = 0');
    $query->condition('fmt.deleted', 0);
    $query->isNotNull('ttid.field_ttd_id_value');
    $query->condition('ttid.field_ttd_id_value', '', '<>');

    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Send one cursor-batched page of current manual topic assignments to the API.
   */
  public function backfillLocalManualTopicsBatch(int $batch_size = 100, int $after_node_id = 0): array {
    $batch_size = max(1, min(500, $batch_size));
    $after_node_id = max(0, $after_node_id);
    $stats = [
      'found' => 0,
      'nodes' => 0,
      'sent' => 0,
      'backfilled' => 0,
      'already_active' => 0,
      'not_found' => 0,
      'failed' => 0,
      'last_node_id' => $after_node_id,
      'has_more' => FALSE,
    ];

    $batch = $this->getLocalManualTopicRows($batch_size, $after_node_id);
    $rows = $batch['rows'];
    $stats['nodes'] = (int) ($batch['node_count'] ?? 0);
    $stats['found'] = count($rows);
    $stats['last_node_id'] = (int) ($batch['last_node_id'] ?? $after_node_id);
    $stats['has_more'] = !empty($batch['has_more']);

    if (empty($rows)) {
      return $stats;
    }

    $items = [];
    foreach ($rows as $row) {
      $entity_id = (int) $row['entity_id'];
      $node_id = (int) $row['node_id'];
      $term_id = (int) $row['term_id'];
      if ($entity_id <= 0 || $node_id <= 0 || $term_id <= 0) {
        continue;
      }

      $items[] = [
        'postId' => (string) $node_id,
        'entityId' => $entity_id,
        'entityName' => (string) $row['entity_name'],
        'reason' => 'Backfilled from Drupal current manual topic state',
        'metadata' => [
          'termId' => $term_id,
          'ttdId' => $entity_id,
          'cmsPostId' => $node_id,
          'cmsTopicId' => $term_id,
          'cmsPostPublishedAt' => (string) ($row['published_at'] ?? ''),
          'cmsPostModifiedAt' => (string) ($row['modified_at'] ?? ''),
          'manualTopicEffectiveAt' => (string) (($row['published_at'] ?? '') ?: ($row['modified_at'] ?? '')),
          'localManualTopicBackfill' => TRUE,
          'manualEntryMethod' => 'current_state_backfill',
        ],
      ];
    }

    if (empty($items)) {
      return $stats;
    }

    foreach (array_chunk($items, 1000) as $chunk) {
      $response = $this->apiRequest('POST', '/telemetry/editorial-signals/manual-backfill', [
        'items' => $chunk,
      ]);

      if (!is_array($response) || empty($response['logged'])) {
        $stats['failed'] += count($chunk);
        $stats['last_node_id'] = $after_node_id;
        $stats['has_more'] = TRUE;
        return $stats;
      }

      $stats['sent'] += count($chunk);
      $stats['backfilled'] += (int) ($response['backfilled'] ?? 0);
      $stats['already_active'] += (int) ($response['alreadyActive'] ?? 0);
      $stats['not_found'] += (int) ($response['notFound'] ?? 0);
    }

    return $stats;
  }

  /**
   * Push local Drupal curation overrides to the API as editorial signals.
   *
   * Hidden topics win if a term is both hidden and force-shown.
   */
  public function syncLocalCurationOverrides(bool $dry_run = FALSE): array {
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $hidden_terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'field_hide' => TRUE,
    ]);
    $force_show_terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'field_force_show' => TRUE,
    ]);

    $hidden_rows = $this->normalizeLocalOverrideTerms($hidden_terms);
    $hidden_ids = array_fill_keys(array_keys($hidden_rows), TRUE);
    $force_show_rows = array_filter(
      $this->normalizeLocalOverrideTerms($force_show_terms),
      static fn(array $row): bool => empty($hidden_ids[$row['entity_id']])
    );

    $stats = [
      'hidden' => count($hidden_rows),
      'force_show' => count($force_show_rows),
      'sent' => 0,
      'failed' => 0,
      'dry_run' => $dry_run,
      'samples' => [
        'hidden' => array_slice(array_values($hidden_rows), 0, 20),
        'force_show' => array_slice(array_values($force_show_rows), 0, 20),
      ],
    ];

    if ($dry_run) {
      return $stats;
    }

    foreach ($hidden_rows as $row) {
      $ok = $this->sendLocalEditorialSignal('force_hide', $row, 'Backfilled from Drupal hidden topic', 'localHiddenBackfill');
      $ok ? $stats['sent']++ : $stats['failed']++;
    }

    foreach ($force_show_rows as $row) {
      $ok = $this->sendLocalEditorialSignal('force_show', $row, 'Backfilled from Drupal Force Show topic', 'localForceShowBackfill');
      $ok ? $stats['sent']++ : $stats['failed']++;
    }

    return $stats;
  }

  /**
   * Query locally hidden topic rows without loading taxonomy entities.
   */
  private function getLocalHiddenEntityRows(int $limit = 0, int $after_term_id = 0): array {
    $database = \Drupal::database();
    if (!$this->hasLocalHiddenBackfillTables($database)) {
      return [];
    }

    $query = $database->select('taxonomy_term_field_data', 'td')->distinct();
    $query->fields('td', ['tid', 'name']);
    $query->addField('ttid', 'field_ttd_id_value', 'ttd_id');
    $query->join('taxonomy_term__field_hide', 'tfh', 'tfh.entity_id = td.tid AND tfh.deleted = 0 AND tfh.field_hide_value = 1');
    $query->join('taxonomy_term__field_ttd_id', 'ttid', 'ttid.entity_id = td.tid AND ttid.deleted = 0');
    $query->condition('td.vid', 'ttd_topics');
    $query->condition('td.tid', max(0, $after_term_id), '>');
    $query->isNotNull('ttid.field_ttd_id_value');
    $query->condition('ttid.field_ttd_id_value', '', '<>');
    $query->orderBy('td.tid', 'ASC');
    if ($limit > 0) {
      $query->range(0, max(1, min(1000, $limit)));
    }

    $rows = [];
    foreach ($query->execute() as $row) {
      $ttd_id = trim((string) $row->ttd_id);
      $entity_id = ctype_digit($ttd_id) ? (int) $ttd_id : 0;

      $rows[] = [
        'entity_id' => $entity_id,
        'entity_name' => (string) $row->name,
        'term_id' => (int) $row->tid,
      ];
    }

    return $rows;
  }

  /**
   * Query current manual topic assignment rows without loading node entities.
   */
  private function getLocalManualTopicRows(int $limit = 100, int $after_node_id = 0): array {
    $database = \Drupal::database();
    $limit = max(1, min(500, $limit));
    $after_node_id = max(0, $after_node_id);
    $result = [
      'rows' => [],
      'last_node_id' => $after_node_id,
      'has_more' => FALSE,
      'node_count' => 0,
    ];

    if (!$this->hasLocalManualTopicBackfillTables($database)) {
      return $result;
    }

    $node_query = $database->select('node__field_manual_topics', 'fmt')->distinct();
    $node_query->addField('fmt', 'entity_id', 'node_id');
    $node_query->condition('fmt.deleted', 0);
    $node_query->condition('fmt.entity_id', $after_node_id, '>');
    $node_query->orderBy('fmt.entity_id', 'ASC');
    $node_query->range(0, $limit);
    $node_ids = array_map('intval', $node_query->execute()->fetchCol());

    if (empty($node_ids)) {
      return $result;
    }

    $result['last_node_id'] = max($node_ids);
    $result['has_more'] = count($node_ids) === $limit;
    $result['node_count'] = count($node_ids);

    $query = $database->select('node__field_manual_topics', 'fmt')->distinct();
    $query->addField('fmt', 'entity_id', 'node_id');
    $query->addField('fmt', 'field_manual_topics_target_id', 'term_id');
    $query->fields('td', ['name']);
    $query->addField('ttid', 'field_ttd_id_value', 'ttd_id');
    $query->addField('n', 'created', 'node_created');
    $query->addField('n', 'changed', 'node_changed');
    $query->join('node_field_data', 'n', 'n.nid = fmt.entity_id AND n.default_langcode = 1');
    $query->join('taxonomy_term_field_data', 'td', 'td.tid = fmt.field_manual_topics_target_id');
    $query->join('taxonomy_term__field_ttd_id', 'ttid', 'ttid.entity_id = fmt.field_manual_topics_target_id AND ttid.deleted = 0');
    $query->condition('fmt.deleted', 0);
    $query->condition('fmt.entity_id', $node_ids, 'IN');
    $query->condition('td.vid', 'ttd_topics');
    $query->isNotNull('ttid.field_ttd_id_value');
    $query->condition('ttid.field_ttd_id_value', '', '<>');
    $query->orderBy('fmt.entity_id', 'ASC');
    $query->orderBy('fmt.delta', 'ASC');

    foreach ($query->execute() as $row) {
      $ttd_id = trim((string) $row->ttd_id);
      $entity_id = ctype_digit($ttd_id) ? (int) $ttd_id : 0;

      $result['rows'][] = [
        'node_id' => (int) $row->node_id,
        'term_id' => (int) $row->term_id,
        'entity_id' => $entity_id,
        'entity_name' => (string) $row->name,
        'published_at' => $this->formatTimestampForApi($row->node_created ?? NULL),
        'modified_at' => $this->formatTimestampForApi($row->node_changed ?? NULL),
      ];
    }

    return $result;
  }

  /**
   * Check whether the tables needed for fast hidden backfill exist.
   */
  private function hasLocalHiddenBackfillTables($database): bool {
    $schema = $database->schema();
    return $schema->tableExists('taxonomy_term_field_data')
      && $schema->tableExists('taxonomy_term__field_hide')
      && $schema->tableExists('taxonomy_term__field_ttd_id');
  }

  /**
   * Check whether the tables needed for fast manual topic backfill exist.
   */
  private function hasLocalManualTopicBackfillTables($database): bool {
    $schema = $database->schema();
    return $schema->tableExists('node__field_manual_topics')
      && $schema->tableExists('node_field_data')
      && $schema->tableExists('taxonomy_term_field_data')
      && $schema->tableExists('taxonomy_term__field_ttd_id');
  }

  /**
   * Normalize topic terms into API entity rows keyed by TopicalBoost entity ID.
   */
  private function normalizeLocalOverrideTerms(array $terms): array {
    $rows = [];

    foreach ($terms as $term) {
      if (!$term instanceof Term || !$term->hasField('field_ttd_id') || $term->get('field_ttd_id')->isEmpty()) {
        continue;
      }

      $entity_id = (int) $term->get('field_ttd_id')->value;
      if ($entity_id <= 0) {
        continue;
      }

      $rows[$entity_id] = [
        'entity_id' => $entity_id,
        'entity_name' => $term->label(),
        'term_id' => (int) $term->id(),
      ];
    }

    ksort($rows);
    return $rows;
  }

  /**
   * Send one local editorial signal to the API.
   */
  private function sendLocalEditorialSignal(string $action, array $row, string $reason, string $metadata_flag): bool {
    $response = $this->apiRequest('POST', '/telemetry/editorial-signals', [
      'action' => $action,
      'entityId' => (int) $row['entity_id'],
      'entityName' => (string) $row['entity_name'],
      'reason' => $reason,
      'metadata' => [
        'termId' => (int) $row['term_id'],
        $metadata_flag => TRUE,
      ],
    ]);

    return is_array($response) && !empty($response['logged']);
  }

  /**
   * Fetch curation scores from the API and cache render decisions locally.
   */
  public function syncCurationScores() {
    $collection = \Drupal::keyValue(static::CURATION_SCORE_COLLECTION);
    $threshold = function_exists('ttd_topics_curation_score_threshold') ? \ttd_topics_curation_score_threshold() : 2.0;
    $page = 1;
    $page_size = 1000;
    $has_more = TRUE;
    $seen = [];
    $stats = ['updated' => 0, 'unchanged' => 0, 'not_found' => 0, 'removed' => 0, 'pages' => 0, 'scores' => 0];

    while ($has_more && $page <= 1000) {
      $response = $this->apiRequest('GET', '/result/curation-scores', NULL, [
        'page' => $page,
        'page_size' => $page_size,
        'threshold' => $threshold,
      ]);

      if (!$response || !isset($response['scores']) || !is_array($response['scores'])) {
        \Drupal::logger('ttd_topics')->warning('Failed to fetch curation scores page @page.', ['@page' => $page]);
        return $stats;
      }

      $scores = $response['scores'];
      $stats['pages']++;
      $stats['scores'] += count($scores);

      $values = [];
      foreach ($scores as $score) {
        $entity_id = (int) ($score['entity_id'] ?? 0);
        if ($entity_id <= 0) {
          continue;
        }

        $key = (string) $entity_id;
        $seen[$key] = TRUE;
        $values[$key] = [
          'entity_id' => $entity_id,
          'score' => isset($score['score']) ? (float) $score['score'] : NULL,
          'score_int' => isset($score['score_int']) ? (int) $score['score_int'] : NULL,
          'should_curate' => !empty($score['should_curate']),
          'recommendation' => (string) ($score['recommendation'] ?? 'neutral'),
          'seed_source' => (string) ($score['seed_source'] ?? 'cooccurrence'),
          'force_show' => !empty($score['force_show']),
          'force_hide' => !empty($score['force_hide']),
          'last_computed_at' => (string) ($score['last_computed_at'] ?? ''),
          'last_synced_at' => \Drupal::time()->getRequestTime(),
        ];
      }

      if (!empty($values)) {
        $existing = $collection->getMultiple(array_keys($values));
        $changed_values = [];
        foreach ($values as $key => $value) {
          $existing_value = $existing[$key] ?? NULL;
          if (is_array($existing_value)) {
            unset($existing_value['last_synced_at']);
          }
          $compare_value = $value;
          unset($compare_value['last_synced_at']);

          if ($existing_value === $compare_value) {
            $stats['unchanged']++;
          }
          else {
            $stats['updated']++;
            $changed_values[$key] = $value;
          }
        }
        if (!empty($changed_values)) {
          $collection->setMultiple($changed_values);
        }
      }

      $has_more = !empty($response['has_more']);
      $page++;
    }

    foreach ($collection->getAll() as $key => $_value) {
      if (!isset($seen[(string) $key])) {
        $collection->delete((string) $key);
        $stats['removed']++;
      }
    }

    \Drupal::state()->set(static::CURATION_LAST_SYNC_KEY, \Drupal::time()->getRequestTime());
    \Drupal\Core\Cache\Cache::invalidateTags(['ttd_topics:curation_scores']);
    $this->reportSiteMetrics();

    return $stats;
  }

  /**
   * Load cached curation scores by TopicalBoost entity ID.
   */
  public function getCurationScores(array $entity_ids): array {
    $keys = array_values(array_unique(array_filter(array_map('strval', array_map('intval', $entity_ids)))));
    if (empty($keys)) {
      return [];
    }

    $rows = \Drupal::keyValue(static::CURATION_SCORE_COLLECTION)->getMultiple($keys);
    $scores = [];
    foreach ($rows as $key => $row) {
      $scores[(int) $key] = is_array($row) ? $row : [];
    }
    return $scores;
  }

  /**
   * Apply one canonical alias cleanup instruction locally.
   */
  private function applyEntityAliasCleanup(int $canonical_id, int $alias_id, array $canonical_entity, int $cleanup_version = 1): array {
    try {
      $canonical_term_id = $this->mergeEntity($canonical_entity);
      if (!$canonical_term_id) {
        return ['applied' => FALSE, 'reason' => 'canonical_term_missing'];
      }

      $alias_term = $this->findTermByTtdId($alias_id);
      $alias_term_id = $alias_term ? (int) $alias_term->id() : NULL;

      $this->storeEntityAlias($canonical_id, $alias_id, $canonical_term_id, $alias_term_id, $cleanup_version);
      $this->moveCustomRelationshipsToCanonical($canonical_id, $alias_id);

      if ($alias_term instanceof Term) {
        $this->moveNodeTopicReferencesToCanonical($canonical_term_id, (int) $alias_term->id());
        if ($alias_term->hasField('field_hide')) {
          $alias_term->set('field_hide', TRUE);
          $alias_term->save();
        }
      }

      if (function_exists('ttd_topics_reset_runtime_caches')) {
        \ttd_topics_reset_runtime_caches();
      }

      return ['applied' => TRUE];
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->warning('Entity alias cleanup failed for @alias: @message', [
        '@alias' => $alias_id,
        '@message' => $e->getMessage(),
      ]);
      return ['applied' => FALSE, 'reason' => 'cleanup_error'];
    }
  }

  /**
   * Find a local topic term by TopicalBoost entity ID.
   */
  private function findTermByTtdId(int $ttd_id): ?Term {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'ttd_topics',
      'field_ttd_id' => (string) $ttd_id,
    ]);
    $term = !empty($terms) ? reset($terms) : NULL;
    return $term instanceof Term ? $term : NULL;
  }

  /**
   * Upsert the local alias row used by redirects and schema filters.
   */
  private function storeEntityAlias(int $canonical_id, int $alias_id, ?int $canonical_term_id, ?int $alias_term_id, int $cleanup_version): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('ttd_entity_aliases')) {
      return;
    }

    $now = \Drupal::time()->getRequestTime();
    $existing = $database->select('ttd_entity_aliases', 'ea')
      ->fields('ea', ['id'])
      ->condition('alias_entity_id', $alias_id)
      ->execute()
      ->fetchField();

    $fields = [
      'canonical_entity_id' => $canonical_id,
      'alias_entity_id' => $alias_id,
      'canonical_term_id' => $canonical_term_id,
      'alias_term_id' => $alias_term_id,
      'cleanup_version' => $cleanup_version,
      'updated' => $now,
    ];

    if ($existing) {
      $database->update('ttd_entity_aliases')
        ->fields($fields)
        ->condition('id', $existing)
        ->execute();
      return;
    }

    $fields['created'] = $now;
    $database->insert('ttd_entity_aliases')
      ->fields($fields)
      ->execute();
  }

  /**
   * Move rows in ttd_entity_post_ids from alias entity ID to canonical ID.
   */
  private function moveCustomRelationshipsToCanonical(int $canonical_id, int $alias_id): void {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('ttd_entity_post_ids')) {
      return;
    }

    $rows = $database->select('ttd_entity_post_ids', 'ep')
      ->fields('ep')
      ->condition('entity_id', $alias_id)
      ->execute()
      ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
      $existing = $database->select('ttd_entity_post_ids', 'ep')
        ->fields('ep')
        ->condition('entity_id', $canonical_id)
        ->condition('post_id', $row['post_id'])
        ->execute()
        ->fetchAssoc();

      if ($existing) {
        $fields = [
          'updatedAt' => date('Y-m-d H:i:s'),
        ];
        if ((float) ($row['salience_score'] ?? 0) > (float) ($existing['salience_score'] ?? 0)) {
          $fields['salience_score'] = (float) $row['salience_score'];
        }
        if (empty($existing['salience_category']) && !empty($row['salience_category'])) {
          $fields['salience_category'] = $row['salience_category'];
        }
        $database->update('ttd_entity_post_ids')
          ->fields($fields)
          ->condition('id', $existing['id'])
          ->execute();
        $database->delete('ttd_entity_post_ids')
          ->condition('id', $row['id'])
          ->execute();
      }
      else {
        $database->update('ttd_entity_post_ids')
          ->fields([
            'entity_id' => $canonical_id,
            'updatedAt' => date('Y-m-d H:i:s'),
          ])
          ->condition('id', $row['id'])
          ->execute();
      }
    }
  }

  /**
   * Replace alias topic term references on nodes with the canonical term.
   */
  private function moveNodeTopicReferencesToCanonical(int $canonical_term_id, int $alias_term_id): void {
    if ($canonical_term_id === $alias_term_id || !\Drupal::database()->schema()->tableExists('node__field_ttd_topics')) {
      return;
    }

    $node_ids = \Drupal::database()->select('node__field_ttd_topics', 'ft')
      ->fields('ft', ['entity_id'])
      ->condition('field_ttd_topics_target_id', $alias_term_id)
      ->condition('deleted', 0)
      ->execute()
      ->fetchCol();

    if (empty($node_ids)) {
      return;
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    foreach ($node_storage->loadMultiple(array_unique(array_map('intval', $node_ids))) as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('field_ttd_topics')) {
        continue;
      }

      $target_ids = [];
      foreach ($node->get('field_ttd_topics') as $item) {
        $target_id = (int) $item->target_id;
        if ($target_id === $alias_term_id) {
          $target_id = $canonical_term_id;
        }
        $target_ids[$target_id] = ['target_id' => $target_id];
      }

      $node->set('field_ttd_topics', array_values($target_ids));
      $node->save();
    }
  }

  /**
   * Merge entity metadata and ensure a matching taxonomy term exists.
   */
  private function mergeEntity(array $entity_data) {
    $ttd_id = $entity_data['id'] ?? NULL;
    if (!$ttd_id) {
      return NULL;
    }

    $name = $entity_data['name'] ?? $entity_data['nl_name'] ?? $entity_data['kg_name'] ?? $entity_data['wb_name'] ?? NULL;
    if (empty($name)) {
      return NULL;
    }

    $this->storeEntityMetadata($entity_data);
    $term = $this->getOrCreateTerm($name, $ttd_id, $entity_data);
    if ($term && $term->id()) {
      $this->storeDemandMetricsForTerm($term->id(), $entity_data);
      return (int) $term->id();
    }

    return NULL;
  }

  /**
   * Apply entity IDs to a node.
   */
  private function applyEntitiesToNode(NodeInterface $node, array $entity_ids, array $entities_map) {
    $term_ids = [];
    $api_ttd_ids = [];
    foreach ($entity_ids as $entity_id) {
      if (!isset($entities_map[$entity_id])) {
        continue;
      }

      $term_id = $this->mergeEntity($entities_map[$entity_id]);
      if ($term_id) {
        $term_ids[] = $term_id;
        $api_ttd_ids[] = (int) $entity_id;
      }
    }

    $final_term_ids = function_exists('ttd_topics_merge_analysis_topic_ids_with_manual')
      ? \ttd_topics_merge_analysis_topic_ids_with_manual($node, $term_ids, $api_ttd_ids)
      : $term_ids;
    $node->set('field_ttd_topics', array_map(function ($term_id) {
      return ['target_id' => $term_id];
    }, $final_term_ids));

    if ($node->hasField('field_ttd_last_analyzed')) {
      $node->set('field_ttd_last_analyzed', \Drupal::time()->getRequestTime());
    }
    $node->save();

    $this->storeNodeRelationships($node->id(), $entity_ids, $entities_map);
  }

  /**
   * Store custom entity-post relationship rows for a node.
   */
  private function storeNodeRelationships($node_id, array $entity_ids, array $entities_map) {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('ttd_entity_post_ids')) {
      return;
    }

    $database->delete('ttd_entity_post_ids')
      ->condition('post_id', (string) $node_id)
      ->execute();

    foreach ($entity_ids as $entity_id) {
      if (!isset($entities_map[$entity_id])) {
        continue;
      }

      $content = $this->findEntityContentForNode($entities_map[$entity_id], $node_id);
      $salience_score = $content['salience'] ?? $content['salience_score'] ?? NULL;
      $salience_category = $content['salience_category'] ?? $content['tier'] ?? $content['llm_tier'] ?? NULL;
      if ($salience_category !== NULL && !in_array($salience_category, ['mainEntity', 'about', 'mentions'], TRUE)) {
        $salience_category = NULL;
      }

      try {
        $database->insert('ttd_entity_post_ids')
          ->fields([
            'entity_id' => (int) $entity_id,
            'post_id' => (string) $node_id,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
            'salience_score' => $salience_score !== NULL ? (float) $salience_score : NULL,
            'salience_category' => $salience_category,
          ])
          ->execute();
      }
      catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->warning('Failed to store sync relationship for node @node and entity @entity: @message', [
          '@node' => $node_id,
          '@entity' => $entity_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if (function_exists('ttd_topics_reset_runtime_caches')) {
      \ttd_topics_reset_runtime_caches();
    }
  }

  /**
   * Store entity metadata in the custom entity table.
   */
  private function storeEntityMetadata(array $entity_data) {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('ttd_entities')) {
      return;
    }

    $ttd_id = $entity_data['id'] ?? NULL;
    if (!$ttd_id) {
      return;
    }

    $available_fields = [
      'mid', 'nl_name', 'nl_type', 'wikipedia_url', 'kg_name', 'kg_description', 'kg_image',
      'wb_qid', 'wb_name', 'wb_description', 'wb_date_modified', 'wb_instances',
      'wb_image', 'wb_logo_image', 'official_website', 'country', 'genre',
      'creator', 'author', 'producer', 'director', 'screenwriter', 'cast_member',
      'characters', 'composer', 'publication_date', 'duration', 'start_time',
      'end_time', 'inception', 'date_of_birth', 'series', 'season',
      'mpa_film_rating', 'imdb_id', 'rotten_tomatoes_id', 'goodreads_work_id',
      'allmusic_album_id', 'spotify_album_id', 'freebase_id',
      'google_knowledge_graph_id', 'isbn_13', 'twitter_username',
      'facebook_id', 'linkedin_personal_profile_id',
    ];

    $fields = [
      'ttd_id' => (int) $ttd_id,
      'name' => $entity_data['name'] ?? $entity_data['nl_name'] ?? $entity_data['kg_name'] ?? $entity_data['wb_name'] ?? 'Unknown',
      'createdAt' => $this->convertToMysqlDatetime($entity_data['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
      'updatedAt' => $this->convertToMysqlDatetime($entity_data['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
    ];

    foreach ($available_fields as $field) {
      if (array_key_exists($field, $entity_data)) {
        $fields[$field] = is_array($entity_data[$field]) ? json_encode($entity_data[$field]) : $entity_data[$field];
      }
    }

    try {
      $existing = $database->select('ttd_entities', 'e')
        ->fields('e', ['ttd_id'])
        ->condition('ttd_id', (int) $ttd_id)
        ->execute()
        ->fetchField();

      if ($existing) {
        unset($fields['ttd_id'], $fields['createdAt']);
        $database->update('ttd_entities')
          ->fields($fields)
          ->condition('ttd_id', (int) $ttd_id)
          ->execute();
      }
      else {
        $database->insert('ttd_entities')
          ->fields($fields)
          ->execute();
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->warning('Failed to merge sync entity @entity: @message', [
        '@entity' => $ttd_id,
        '@message' => $e->getMessage(),
      ]);
    }

    $schema_types = isset($entity_data['SchemaTypes']) && is_array($entity_data['SchemaTypes']) ? $entity_data['SchemaTypes'] : [];
    $wb_categories = isset($entity_data['WBCategories']) && is_array($entity_data['WBCategories']) ? $entity_data['WBCategories'] : [];
    $this->handleRelatedData($ttd_id, 'schema_types', $schema_types);
    $this->handleRelatedData($ttd_id, 'wb_categories', $wb_categories);
  }

  /**
   * Get or create a topic term by TopicalBoost entity ID.
   */
  private function getOrCreateTerm($name, $ttd_id, array $entity_data) {
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'field_ttd_id' => (string) $ttd_id,
    ]);

    if (!empty($terms)) {
      $term = reset($terms);
      if ($term->label() !== $name) {
        $term->setName($name);
        $term->save();
      }
      return $term;
    }

    $terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'name' => $name,
    ]);

    if (!empty($terms)) {
      $term = reset($terms);
      if ($term->hasField('field_ttd_id') && $term->get('field_ttd_id')->isEmpty()) {
        $term->set('field_ttd_id', (string) $ttd_id);
        $term->save();
      }
      return $term;
    }

    $term = Term::create([
      'vid' => 'ttd_topics',
      'name' => $name,
      'field_ttd_id' => (string) $ttd_id,
      'description' => [
        'value' => $entity_data['wb_description'] ?? '',
        'format' => 'plain_text',
      ],
    ]);
    $term->save();

    return $term;
  }

  /**
   * Handle schema type and Wikibase category relation tables.
   */
  private function handleRelatedData($ttd_id, string $type, array $data) {
    if (empty($data)) {
      return;
    }

    $database = \Drupal::database();
    if ($type === 'schema_types') {
      foreach ($data as $item) {
        if (!is_array($item) || empty($item['id'])) {
          continue;
        }
        try {
          $schema_type_id = (int) $item['id'];
          $exists = $database->select('ttd_schema_types', 'st')
            ->fields('st', ['ttd_id'])
            ->condition('ttd_id', $schema_type_id)
            ->execute()
            ->fetchField();
          if (!$exists) {
            $database->insert('ttd_schema_types')
              ->fields([
                'ttd_id' => $schema_type_id,
                'name' => $item['name'] ?? 'Unknown',
                'createdAt' => $this->convertToMysqlDatetime($item['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
                'updatedAt' => $this->convertToMysqlDatetime($item['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
              ])
              ->execute();
          }
          $rel_exists = $database->select('ttd_entity_schema_types', 'est')
            ->fields('est', ['id'])
            ->condition('entity_id', (int) $ttd_id)
            ->condition('schema_type_id', $schema_type_id)
            ->execute()
            ->fetchField();
          if (!$rel_exists) {
            $database->insert('ttd_entity_schema_types')
              ->fields([
                'entity_id' => (int) $ttd_id,
                'schema_type_id' => $schema_type_id,
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s'),
              ])
              ->execute();
          }
        }
        catch (\Exception $e) {
          // Related metadata should not block sync.
        }
      }
    }
    elseif ($type === 'wb_categories') {
      foreach ($data as $item) {
        if (!is_array($item) || empty($item['id'])) {
          continue;
        }
        try {
          $category_id = (int) $item['id'];
          $exists = $database->select('ttd_wb_categories', 'wc')
            ->fields('wc', ['ttd_id'])
            ->condition('ttd_id', $category_id)
            ->execute()
            ->fetchField();
          if (!$exists) {
            $database->insert('ttd_wb_categories')
              ->fields([
                'ttd_id' => $category_id,
                'qid' => $item['qid'] ?? 'Unknown',
                'name' => $item['name'] ?? 'Unknown',
                'createdAt' => $this->convertToMysqlDatetime($item['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
                'updatedAt' => $this->convertToMysqlDatetime($item['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
              ])
              ->execute();
          }
          $rel_exists = $database->select('ttd_entity_wb_categories', 'ewc')
            ->fields('ewc', ['id'])
            ->condition('entity_id', (int) $ttd_id)
            ->condition('wb_category_id', $category_id)
            ->execute()
            ->fetchField();
          if (!$rel_exists) {
            $database->insert('ttd_entity_wb_categories')
              ->fields([
                'entity_id' => (int) $ttd_id,
                'wb_category_id' => $category_id,
                'createdAt' => date('Y-m-d H:i:s'),
                'updatedAt' => date('Y-m-d H:i:s'),
              ])
              ->execute();
          }
        }
        catch (\Exception $e) {
          // Related metadata should not block sync.
        }
      }
    }
  }

  /**
   * Record successful page processing and finish when all pages are done.
   */
  private function recordPullResult(array $stats) {
    $state = \Drupal::state();
    $active = $state->get(static::SYNC_STATE_KEY, []);
    if (empty($active)) {
      return;
    }

    $active['completed_jobs'] = (int) ($active['completed_jobs'] ?? 0) + 1;
    $active['posts_applied'] = (int) ($active['posts_applied'] ?? 0) + (int) ($stats['posts_applied'] ?? 0);
    $active['posts_skipped'] = (int) ($active['posts_skipped'] ?? 0) + (int) ($stats['posts_skipped'] ?? 0);
    $active['alias_cleanups'] = (int) ($active['alias_cleanups'] ?? 0) + (int) ($stats['alias_cleanups'] ?? 0);
    $state->set(static::SYNC_STATE_KEY, $active);

    if ((int) $active['completed_jobs'] >= (int) ($active['total_jobs'] ?? 0)) {
      $active['status'] = 'finalizing';
      $state->set(static::SYNC_STATE_KEY, $active);
      $this->finishSync(0);
    }
  }

  /**
   * Schedule the next cursor pull job when the API has another page.
   */
  private function scheduleNextSyncPullIfNeeded(string $type, int $page, int $page_size, ?string $since, array $response) {
    if (empty($response['has_next']) || !isset($response['next_after_id'])) {
      return;
    }

    $queue = $this->getQueue();
    if (!$queue) {
      throw new \RuntimeException('TopicalBoost analysis queue is not available');
    }

    $queue->enqueueJob(Job::create(static::SYNC_JOB_TYPE, [
      'type' => $type,
      'page' => $page + 1,
      'page_size' => $page_size,
      'since' => $since,
      'after_id' => (int) $response['next_after_id'],
    ]));

    $this->expandActiveSyncTotalJobs(1, $type);
  }

  /**
   * Keep progress accounting correct when additional pull jobs are queued.
   */
  private function expandActiveSyncTotalJobs(int $additional_jobs, ?string $type = NULL) {
    if ($additional_jobs <= 0) {
      return;
    }

    $state = \Drupal::state();
    $active = $state->get(static::SYNC_STATE_KEY, []);
    if (empty($active)) {
      return;
    }

    $active['total_jobs'] = (int) ($active['total_jobs'] ?? 0) + $additional_jobs;
    if ($type === 'relationships') {
      $active['rel_pages'] = (int) ($active['rel_pages'] ?? 0) + $additional_jobs;
    }
    elseif ($type === 'topics') {
      $active['topic_pages'] = (int) ($active['topic_pages'] ?? 0) + $additional_jobs;
    }
    elseif ($type === 'entity_aliases') {
      $active['alias_pages'] = (int) ($active['alias_pages'] ?? 0) + $additional_jobs;
    }
    $state->set(static::SYNC_STATE_KEY, $active);
  }

  /**
   * Finalize a sync.
   */
  private function finishSync(int $failed) {
    $state = \Drupal::state();
    $sync_state = $state->get(static::SYNC_STATE_KEY, []);

    if ($failed === 0) {
      $state->set(static::LAST_SYNC_KEY, gmdate('c'));
    }

    if (!empty($sync_state['api_request_id'])) {
      $this->apiRequest('POST', '/sync/complete', [
        'request_id' => (int) $sync_state['api_request_id'],
        'postsApplied' => (int) ($sync_state['posts_applied'] ?? 0),
        'postsSkipped' => (int) ($sync_state['posts_skipped'] ?? 0),
        'failed' => $failed,
      ]);
    }

    if ($failed === 0) {
      $this->syncHiddenEntities();
      $this->reportSiteMetrics();
    }

    $this->cleanupSyncJobs();
    $state->delete(static::SYNC_STATE_KEY);
    $state->delete('ttd_sync_status_cache');
  }

  /**
   * Clear queued/completed sync pull jobs.
   */
  private function cleanupSyncJobs() {
    $database = \Drupal::database();
    if ($database->schema()->tableExists('advancedqueue')) {
      $database->delete('advancedqueue')
        ->condition('queue_id', static::QUEUE_ID)
        ->condition('type', static::SYNC_JOB_TYPE)
        ->execute();
    }
  }

  /**
   * Get queue state counts for sync jobs.
   */
  private function getSyncQueueCounts() {
    $database = \Drupal::database();
    if (!$database->schema()->tableExists('advancedqueue')) {
      return [];
    }

    $query = $database->select('advancedqueue', 'aq');
    $query->fields('aq', ['state']);
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('queue_id', static::QUEUE_ID);
    $query->condition('type', static::SYNC_JOB_TYPE);
    $query->groupBy('state');

    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[$row->state] = (int) $row->count;
    }
    return $counts;
  }

  /**
   * Return the module queue entity.
   */
  private function getQueue() {
    $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
    return $queue_storage->load(static::QUEUE_ID);
  }

  /**
   * Compare two numbers within a tolerance percentage.
   */
  private function withinTolerance($a, $b, $tolerance) {
    if ($a === $b) {
      return TRUE;
    }
    $max = max($a, $b);
    return $max === 0 || abs($a - $b) / $max <= $tolerance;
  }

  /**
   * Find an entity's content payload for a node.
   */
  private function findEntityContentForNode(array $entity_data, $node_id) {
    $contents = isset($entity_data['Contents']) && is_array($entity_data['Contents']) ? $entity_data['Contents'] : [];
    foreach ($contents as $content) {
      if (!is_array($content)) {
        continue;
      }
      if (isset($content['customer_id']) && (string) $content['customer_id'] === (string) $node_id) {
        return $content;
      }
    }
    return [];
  }

  /**
   * Store demand metrics for a term.
   */
  private function storeDemandMetricsForTerm($term_id, array $entity_data) {
    $metrics = [];
    foreach (['keyword_difficulty', 'search_volume', 'traffic_potential'] as $key) {
      if (isset($entity_data[$key]) && $entity_data[$key] !== NULL) {
        $metrics[$key] = (int) $entity_data[$key];
      }
    }

    if (!empty($metrics) && function_exists('ttd_store_demand_metrics')) {
      ttd_store_demand_metrics($term_id, $metrics);
    }
  }

  /**
   * Convert ISO datetime to MySQL datetime.
   */
  private function convertToMysqlDatetime($datetime) {
    if (empty($datetime)) {
      return NULL;
    }

    try {
      $date = new \DateTime($datetime);
      return $date->format('Y-m-d H:i:s');
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Get module version for API telemetry.
   */
  private function getModuleVersion() {
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('ttd_topics');
    return $info['version'] ?? 'dev';
  }

}
