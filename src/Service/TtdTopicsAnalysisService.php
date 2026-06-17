<?php

namespace Drupal\ttd_topics\Service;

use Drupal\node\NodeInterface;
use Drupal\ttd_topics\Event\AnalysisCompleteEvent;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Service for performing TopicalBoost analysis.
 */
class TtdTopicsAnalysisService {

  /**
   * Perform single analysis for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to analyze.
   * @param bool $force_analysis
   *   Whether to force analysis even if already analyzed.
   * @param bool $save_entity
   *   Whether to save the entity after analysis. Set to FALSE when called from forms.
   *
   * @return array
   *   Result array with success/failure status and message.
   */
  public function performSingleAnalysis(NodeInterface $node, $force_analysis = FALSE, $save_entity = TRUE) {
    $identifier = $node->id() ? $node->id() : $node->uuid();

    // Check if the node is eligible for analysis.
    $is_eligible = $node->isPublished() || $force_analysis;
    $is_analyzed = !$node->get('field_ttd_last_analyzed')->isEmpty();

    if (!$is_eligible || ($is_analyzed && !$force_analysis)) {
      return [
        'success' => TRUE,
        'message' => 'Node not eligible for analysis or already analyzed',
      ];
    }

    try {
      // Set analysis in progress.
      $node->set('field_ttd_analysis_in_progress', TRUE);
      if ($save_entity) {
        $node->save();
      }

      $this->executeAnalysis($node, $save_entity);

      // Clear analysis in progress.
      $node->set('field_ttd_analysis_in_progress', FALSE);
      if ($save_entity) {
        $node->save();
      }

      return [
        'success' => TRUE,
        'message' => 'TopicalBoost analysis completed for node ' . $identifier,
      ];
    }
    catch (\Exception $e) {
      // Clear analysis in progress in case of error.
      $node->set('field_ttd_analysis_in_progress', FALSE);
      if ($save_entity) {
        $node->save();
      }

      \Drupal::logger('ttd_topics')->error('Error processing node @id: @message', [
        '@id' => $identifier,
        '@message' => $e->getMessage(),
      ]);

      return [
        'success' => FALSE,
        'message' => 'Error processing node ' . $identifier . ': ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Start a single-node analysis request with the TopicalBoost API.
   */
  public function startSingleAnalysis(NodeInterface $node) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = TOPICALBOOST_API_ENDPOINT;

    $client = new Client();

    try {
      // Get analysis content using field collector
      $field_collector = \Drupal::service('ttd_topics.field_collector');
      $analysis_text = $field_collector->collect($node);

      // Step 1: Initiate analysis.
      $response = $client->post($api_base_url . '/analyze/single', [
        'headers' => array_merge(\ttd_topics_api_headers($api_key), [
          'x-tb-plugin-version' => $this->getModuleVersion(),
          'x-tb-platform' => 'drupal',
        ]),
        'json' => array_filter([
          'customer_id' => $node->id(),
          'url' => \ttd_topics_get_node_absolute_url($node),
          'title' => $node->getTitle(),
          'text' => $analysis_text,
          'status' => $node->isPublished() ? 'publish' : 'draft',
          'publishedAt' => $node->isPublished() ? gmdate('Y-m-d\TH:i:s\Z', $node->getCreatedTime()) : NULL,
        ], function ($v) { return $v !== NULL; }),
        'timeout' => 60,
      ]);

      $result = json_decode($response->getBody(), TRUE);
      if (empty($result['request_id'])) {
        throw new \Exception('Analysis request did not return a request id');
      }

      return $result['request_id'];
    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error starting TopicalBoost analysis: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Check whether a TopicalBoost API analysis request is ready.
   */
  public function isAnalysisReady($request_id, $timeout = 30) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = TOPICALBOOST_API_ENDPOINT;

    $client = new Client();
    $poll_response = $client->get($api_base_url . '/poll/analysis', [
      'headers' => [
        'x-api-key' => $api_key,
        'x-tb-plugin-version' => $this->getModuleVersion(),
        'x-tb-platform' => 'drupal',
      ],
      'query' => ['request_id' => $request_id],
      'timeout' => $timeout,
    ]);

    $poll_result = json_decode($poll_response->getBody(), TRUE);
    return !empty($poll_result['ready']);
  }

  /**
   * Fetch and apply single-node analysis results from the TopicalBoost API.
   */
  public function applyAnalysisResult(NodeInterface $node, $request_id, $save_entity = TRUE) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = TOPICALBOOST_API_ENDPOINT;

    $client = new Client();
    $results_response = $client->get($api_base_url . '/result/entities', [
      'headers' => [
        'x-api-key' => $api_key,
        'x-tb-plugin-version' => $this->getModuleVersion(),
        'x-tb-platform' => 'drupal',
      ],
      'query' => [
        'request_id' => $request_id,
        'include_demand_metrics' => 'true',
      ],
      'timeout' => 30,
    ]);

    $analysis_results = json_decode($results_response->getBody(), TRUE);

    // Process and save the results.
    $this->saveAnalysisResults($node, $analysis_results, $save_entity);

    // Update the ttd_last_analyzed field.
    $node->set('field_ttd_last_analyzed', \Drupal::time()->getRequestTime());
    if ($save_entity) {
      $node->save();
    }

    return $analysis_results;
  }

  /**
   * Execute the analysis for a node.
   */
  private function executeAnalysis(NodeInterface $node, $save_entity = TRUE) {
    try {
      $request_id = $this->startSingleAnalysis($node);

      // Step 2: Poll for analysis completion.
      $completed = FALSE;
      $max_attempts = 30;
      $attempt = 0;

      while (!$completed && $attempt < $max_attempts) {
        // Wait for 10 seconds between polls.
        sleep(10);
        if ($this->isAnalysisReady($request_id)) {
          $completed = TRUE;
        }

        $attempt++;
      }

      if (!$completed) {
        throw new \Exception('Analysis timed out');
      }

      // Step 3: Get analysis results.
      $this->applyAnalysisResult($node, $request_id, $save_entity);

    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error during TopicalBoost analysis: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Save the analysis results to the node.
   */
  private function saveAnalysisResults(NodeInterface $node, array $results, $save_entity = TRUE) {
    $topicalboost = [];
    $api_term_ids = [];
    $api_ttd_ids = [];
    $post_id = $node->id();

    foreach ($results['entities'] as $entity) {
      $name = $entity['name'] ?? $entity['nl_name'] ?? $entity['kg_name'] ?? $entity['wb_name'] ?? NULL;
      if (!empty($name)) {
        $term_id = $this->getOrCreateTerm($name, $entity['id'], $entity, $save_entity);
        if ($term_id) {
          $api_term_ids[] = (int) $term_id;
          $api_ttd_ids[] = (int) $entity['id'];

          // Store demand metrics (KD/KV) if present
          $this->storeDemandMetricsForTerm($term_id, $entity);

          // Store salience data if present (nested in Contents array)
          // API returns 'salience' (from Google NLP), we store as 'salience_score'
          if ($post_id && !empty($entity['Contents']) && is_array($entity['Contents'])) {
            foreach ($entity['Contents'] as $content) {
              if (!is_array($content)) {
                continue;
              }
              if (isset($content['customer_id']) && intval($content['customer_id']) === intval($post_id)) {
                $salience_score = $content['salience'] ?? $content['salience_score'] ?? NULL;
                $salience_category = $content['salience_category'] ?? $content['tier'] ?? $content['llm_tier'] ?? NULL;
                if ($salience_score !== NULL || $salience_category !== NULL) {
                  $this->storeSalienceForEntity($entity['id'], $post_id, $salience_score, $salience_category);
                }
                break;
              }
            }
          }
        }
      }
    }

    $final_topic_ids = function_exists('ttd_topics_merge_analysis_topic_ids_with_manual')
      ? \ttd_topics_merge_analysis_topic_ids_with_manual($node, $api_term_ids, $api_ttd_ids)
      : $api_term_ids;
    $this->deleteStalePostRelationships($post_id, $this->getTtdIdsForTermIds($final_topic_ids));
    foreach ($final_topic_ids as $term_id) {
      $topicalboost[] = ['target_id' => $term_id];
    }

    $node->set('field_ttd_topics', $topicalboost);
    if ($save_entity) {
      $node->save();
      \Drupal::service('event_dispatcher')->dispatch(
        new AnalysisCompleteEvent($node, $final_topic_ids),
        'ttd_topics.analysis_complete'
      );
    }
  }

  /**
   * Gets TopicalBoost entity IDs for term IDs.
   */
  private function getTtdIdsForTermIds(array $term_ids): array {
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (empty($term_ids)) {
      return [];
    }

    $database = \Drupal::database();
    if (!$database->schema()->tableExists('taxonomy_term__field_ttd_id')) {
      return [];
    }

    return array_values(array_unique(array_map('intval', $database->select('taxonomy_term__field_ttd_id', 'ttd')
      ->fields('ttd', ['field_ttd_id_value'])
      ->condition('entity_id', $term_ids, 'IN')
      ->execute()
      ->fetchCol())));
  }

  /**
   * Removes entity-post rows that no longer belong to the node after analysis.
   */
  private function deleteStalePostRelationships($node_id, array $final_ttd_ids): void {
    $database = \Drupal::database();
    if (!$node_id || !$database->schema()->tableExists('ttd_entity_post_ids')) {
      return;
    }

    $delete = $database->delete('ttd_entity_post_ids')
      ->condition('post_id', (string) $node_id);

    if (!empty($final_ttd_ids)) {
      $delete->condition('entity_id', $final_ttd_ids, 'NOT IN');
    }

    $delete->execute();
    if (function_exists('ttd_topics_reset_runtime_caches')) {
      \ttd_topics_reset_runtime_caches();
    }
  }

  /**
   * Store salience score and category for an entity-post relationship.
   *
   * @param string $entity_id
   *   The TTD entity ID.
   * @param int $post_id
   *   The node ID.
   * @param float|null $salience_score
   *   The salience score (0-1).
   * @param string|null $salience_category
   *   The salience category ('mainEntity', 'about', or 'mentions').
   */
  private function storeSalienceForEntity($entity_id, $post_id, $salience_score, $salience_category = NULL) {
    $database = \Drupal::database();

    if ($salience_category !== NULL && !in_array($salience_category, ['mainEntity', 'about', 'mentions'], TRUE)) {
      $salience_category = NULL;
    }

    try {
      // Update existing record with salience data.
      $updated = $database->update('ttd_entity_post_ids')
        ->fields([
          'salience_score' => $salience_score,
          'salience_category' => $salience_category,
          'updatedAt' => date('Y-m-d H:i:s'),
        ])
        ->condition('entity_id', $entity_id)
        ->condition('post_id', $post_id)
        ->execute();

      // If no record existed, insert one.
      if ($updated === 0) {
        $database->insert('ttd_entity_post_ids')
          ->fields([
            'entity_id' => $entity_id,
            'post_id' => $post_id,
            'salience_score' => $salience_score,
            'salience_category' => $salience_category,
            'createdAt' => date('Y-m-d H:i:s'),
            'updatedAt' => date('Y-m-d H:i:s'),
          ])
          ->execute();
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error storing salience for entity @id on post @post: @message', [
        '@id' => $entity_id,
        '@post' => $post_id,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get or create a taxonomy term for a TTD Topic.
   */
  private function getOrCreateTerm($name, $ttd_id, $entity_data, $save_entity = TRUE) {
    if (empty($name)) {
      return NULL;
    }

    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $database = \Drupal::database();

    // First, check if the entity exists in the ttd_entities table.
    $existing_entity = $database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', $ttd_id)
      ->execute()
      ->fetchAssoc();

    if (!$existing_entity) {
      // Insert the entity into ttd_entities table.
      try {
        $database->insert('ttd_entities')
          ->fields([
            'ttd_id' => $ttd_id,
            'name' => $name,
            'createdAt' => $this->convertToMysqlDatetime($entity_data['createdAt'] ?? NULL),
            'updatedAt' => $this->convertToMysqlDatetime($entity_data['updatedAt'] ?? NULL),
            // Add other fields from $entity_data as necessary.
          ])
          ->execute();
      }
      catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->error('Error inserting entity @ttd_id: @message', [
          '@ttd_id' => $ttd_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
    else {
      // Exclude fields that are not columns in ttd_entities table
      $exclude_fields = ['id', 'Contents', 'SchemaTypes', 'WBCategories', 'keyword_difficulty', 'search_volume', 'traffic_potential'];
      foreach ($exclude_fields as $field) {
        unset($entity_data[$field]);
      }

      $update_fields = [];
      foreach ($entity_data as $key => $value) {
        if ($existing_entity[$key] != $value) {
          $update_fields[$key] = $value;
        }
      }
      if (!empty($update_fields)) {
        try {
          // Convert datetime fields before updating.
          foreach ($update_fields as $key => $value) {
            if (in_array($key, ['createdAt', 'updatedAt'])) {
              $update_fields[$key] = $this->convertToMysqlDatetime($value);
            }
          }

          $database->update('ttd_entities')
            ->fields($update_fields)
            ->condition('ttd_id', $ttd_id)
            ->execute();
        }
        catch (\Exception $e) {
          \Drupal::logger('ttd_topics')->error('Error updating entity @ttd_id: @message', [
            '@ttd_id' => $ttd_id,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    // Handle related data (schema_types and wb_categories)
    $this->handleRelatedData($ttd_id, 'schema_types', $entity_data['schema_types'] ?? []);
    $this->handleRelatedData($ttd_id, 'wb_categories', $entity_data['wb_categories'] ?? []);

    // Now, try to load the term by the TTD ID.
    $terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'field_ttd_id' => (string) $ttd_id,
    ]);

    if (!empty($terms)) {
      $term = reset($terms);
      return $term->id();
    }

    // If not found, try to load by name.
    $terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'name' => $name,
    ]);

    if (!empty($terms)) {
      $term = reset($terms);

      // Update the term with TTD ID if it's missing.
              if ($term->get('field_ttd_id')->isEmpty()) {
          $term->set('field_ttd_id', (string) $ttd_id);
        $term->save();
      }
      return $term->id();
    }

    // If still not found, create a new term only if we're saving permanently
    if ($save_entity) {
      try {
        $term = Term::create([
          'vid' => 'ttd_topics',
          'name' => $name,
          'field_ttd_id' => (string) $ttd_id,
        ]);

        $term->save();

        // Verify the term was saved successfully.
        if ($term->id()) {
          return $term->id();
        }
        else {
          \Drupal::logger('ttd_topics')->error('Term @name was created but has no ID', ['@name' => $name]);
          return NULL;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->error('Error creating term @name: @message', [
          '@name' => $name,
          '@message' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    // For temporary analysis, don't create new terms - return NULL
    return NULL;
  }

  /**
   * Handle related data for schema types and WB categories.
   */
  private function handleRelatedData($ttd_id, $type, $data) {
    if (empty($data) || !is_array($data)) {
      return;
    }

    $database = \Drupal::database();
    $table = "ttd_entity_{$type}";
    $id_field = "{$type}_id";

    // Delete existing relations.
    try {
      $database->delete($table)
        ->condition('entity_id', $ttd_id)
        ->execute();
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error deleting existing @type for entity @ttd_id: @message', [
        '@type' => $type,
        '@ttd_id' => $ttd_id,
        '@message' => $e->getMessage(),
      ]);
    }

    // Insert new relations.
    foreach ($data as $item) {
      try {
        $database->insert($table)
          ->fields([
            'entity_id' => $ttd_id,
            $id_field => $item,
          ])
          ->execute();
      }
      catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->error('Error inserting @type @item for entity @ttd_id: @message', [
          '@type' => $type,
          '@item' => $item,
          '@ttd_id' => $ttd_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Convert ISO 8601 datetime to MySQL datetime format.
   */
  private function convertToMysqlDatetime($datetime) {
    if (empty($datetime)) {
      return NULL;
    }

    try {
      // Parse ISO 8601 format and convert to MySQL format.
      $date = new \DateTime($datetime);
      return $date->format('Y-m-d H:i:s');
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->warning('Invalid datetime format: @datetime', [
        '@datetime' => $datetime,
      ]);
      return NULL;
    }
  }

  /**
   * Store demand metrics (KD/KV) for a term from entity data.
   *
   * @param int $term_id
   *   The taxonomy term ID.
   * @param array $entity_data
   *   Entity data from API response.
   */
  private function storeDemandMetricsForTerm($term_id, array $entity_data) {
    // Check if entity has keyword_difficulty, search_volume, and/or traffic_potential
    $has_kd = isset($entity_data['keyword_difficulty']) && $entity_data['keyword_difficulty'] !== NULL;
    $has_sv = isset($entity_data['search_volume']) && $entity_data['search_volume'] !== NULL;
    $has_tp = isset($entity_data['traffic_potential']) && $entity_data['traffic_potential'] !== NULL;

    if (!$has_kd && !$has_sv && !$has_tp) {
      return;
    }

    // Build metrics data structure
    $metrics_data = [];

    if ($has_kd) {
      $metrics_data['keyword_difficulty'] = (int) $entity_data['keyword_difficulty'];
    }

    if ($has_sv) {
      $metrics_data['search_volume'] = (int) $entity_data['search_volume'];
    }

    if ($has_tp) {
      $metrics_data['traffic_potential'] = (int) $entity_data['traffic_potential'];
    }

    // Store using module function
    ttd_store_demand_metrics($term_id, $metrics_data);
  }

  /**
   * Update the stored content URL for a node (e.g. after draft -> publish).
   */
  public function updateContentUrl(NodeInterface $node) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = TOPICALBOOST_API_ENDPOINT;

    $url = \ttd_topics_get_node_absolute_url($node);
    if (empty($url)) {
      return;
    }

    try {
      $client = new Client();
      $client->patch($api_base_url . '/content/url', [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
          'x-tb-plugin-version' => $this->getModuleVersion(),
          'x-tb-platform' => 'drupal',
        ],
        'json' => array_filter([
          'customer_id' => $node->id(),
          'url' => $url,
          'status' => $node->isPublished() ? 'publish' : 'draft',
          'publishedAt' => $node->isPublished() ? gmdate('Y-m-d\TH:i:s\Z', $node->getCreatedTime()) : NULL,
        ], function ($v) { return $v !== NULL; }),
        'timeout' => 10,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->warning('Failed to update content URL for node @id: @message', [
        '@id' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Get the module version.
   */
  protected function getModuleVersion(): string {
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('ttd_topics');
    return $info['version'] ?? 'unknown';
  }

}
