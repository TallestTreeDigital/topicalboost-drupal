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
  const SYNC_STATE_KEY = 'ttd_active_sync';
  const LAST_SYNC_KEY = 'ttd_last_sync_at';
  const API_HIDDEN_STATE_KEY = 'ttd_api_hidden_entity_ids';
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
      'headers' => [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key,
        'x-tb-plugin-version' => $this->getModuleVersion(),
        'x-tb-platform' => 'drupal',
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
      $enabled_types = array_filter($config->get('enabled_content_types') ?: ['article']);
      if (empty($enabled_types)) {
        return [
          'postCount' => 0,
          'topicCount' => 0,
          'relationshipCount' => 0,
          'avgTopicsPerPost' => 0.0,
        ];
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

      return [
        'postCount' => $post_count,
        'topicCount' => $topic_count,
        'relationshipCount' => $relationship_count,
        'avgTopicsPerPost' => $posts_with_topics > 0 ? round($relationship_count / $posts_with_topics, 1) : 0.0,
      ];
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to gather local sync metrics: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
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

    $topic_page_size = static::TOPIC_PAGE_SIZE;
    $topic_pages = $sync_topics > 0 ? (int) ceil($sync_topics / $topic_page_size) : 0;
    $rel_pages = $sync_posts > 0 ? (int) ceil($sync_posts / $batch_size) : 0;
    $jobs_scheduled = 0;

    $start_response = $this->apiRequest('POST', '/sync/start', [
      'contentCount' => $sync_posts,
      'entityCount' => $sync_topics,
      'incremental' => $is_incremental,
    ]);

    $sync_state = [
      'started_at' => \Drupal::time()->getRequestTime(),
      'total_jobs' => ($sync_topics > 0 ? 1 : 0) + ($sync_posts > 0 ? 1 : 0),
      'completed_jobs' => 0,
      'failed_jobs' => 0,
      'topic_pages' => $topic_pages,
      'rel_pages' => $rel_pages,
      'posts_applied' => 0,
      'posts_skipped' => 0,
      'incremental' => $is_incremental,
      'since' => $last_sync ?: NULL,
      'api_request_id' => $start_response['request_id'] ?? NULL,
      'status' => 'running',
    ];
    \Drupal::state()->set(static::SYNC_STATE_KEY, $sync_state);

    $queue = $this->getQueue();
    if (!$queue && ($topic_pages + $rel_pages) > 0) {
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
    }

    if ($jobs_scheduled === 0) {
      $this->finishSync(0);
      return array_merge($sync_state, ['active' => FALSE, 'status' => 'complete']);
    }

    \Drupal::logger('ttd_topics')->info('Started @mode sync: estimated @topics topic pages, @rels relationship pages.', [
      '@mode' => $is_incremental ? 'incremental' : 'full',
      '@topics' => $topic_pages,
      '@rels' => $rel_pages,
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

    if ($this->shouldAutoSync($status['local'], $status['api'])) {
      $this->startSync($status);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Check whether count differences warrant an API-to-site pull.
   */
  private function shouldAutoSync(array $local, array $api) {
    $tolerance = 0.05;
    $topics_synced = $this->withinTolerance($local['topicCount'], $api['topics'] ?? 0, $tolerance);
    $relationships_synced = $this->withinTolerance($local['relationshipCount'], $api['relationships'] ?? 0, $tolerance);

    $api_has_more_topics = ($api['topics'] ?? 0) > $local['topicCount'];
    $api_has_more_relationships = ($api['relationships'] ?? 0) > $local['relationshipCount'];

    return ((!$topics_synced && $api_has_more_topics) || (!$relationships_synced && $api_has_more_relationships));
  }

  /**
   * Pull one sync page from the API and apply it locally.
   */
  public function pullSyncPage(string $type, int $page, int $page_size, ?string $since = NULL, $after_id = NULL) {
    if ($type === 'topics' && $page_size > static::TOPIC_PAGE_SIZE && $after_id === NULL) {
      return $this->splitOversizedTopicPull($page, $page_size, $since);
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
   * Split legacy 100-topic pull jobs into smaller child jobs before doing work.
   */
  private function splitOversizedTopicPull(int $page, int $page_size, ?string $since = NULL) {
    $queue = $this->getQueue();
    if (!$queue) {
      throw new \RuntimeException('TopicalBoost analysis queue is not available');
    }

    $child_page_size = static::TOPIC_PAGE_SIZE;
    $first_offset = max(0, ($page - 1) * $page_size);
    $child_count = (int) ceil($page_size / $child_page_size);

    for ($index = 0; $index < $child_count; $index++) {
      $child_offset = $first_offset + ($index * $child_page_size);
      $queue->enqueueJob(Job::create(static::SYNC_JOB_TYPE, [
        'type' => 'topics',
        'page' => (int) floor($child_offset / $child_page_size) + 1,
        'page_size' => $child_page_size,
        'since' => $since,
      ]));
    }

    $this->expandActiveSyncTotalJobs($child_count, 'topics');
    $this->recordPullResult([]);

    return [
      'topics' => 0,
      'posts_applied' => 0,
      'posts_skipped' => 0,
      'split_jobs' => $child_count,
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

    $this->expandActiveSyncTotalJobs(1);
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
