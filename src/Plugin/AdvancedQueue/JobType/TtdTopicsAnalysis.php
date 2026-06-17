<?php

namespace Drupal\ttd_topics\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Job type for processing TopicalBoost single node analysis.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_topics_analysis",
 *   label = @Translation("TopicalBoost Analysis"),
 *   max_retries = 3,
 *   retry_delay = 60,
 *   cron = {"time" = 300}
 * )
 */
class TtdTopicsAnalysis extends JobTypeBase {

  /**
   * {@inheritdoc}
   */
  public function process(Job $job) {
    $payload = $job->getPayload();
    $identifier = $payload['node_id'];
    $force_analysis = $payload['force_analysis'] ?? FALSE;

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = is_numeric($identifier)
      ? $node_storage->load($identifier)
      : current($node_storage->loadByProperties(['uuid' => $identifier]));

    if (!$node || !($node instanceof NodeInterface)) {
      return JobResult::failure('Node not found');
    }

    // Check if the node is eligible for analysis.
    $config = \Drupal::config('ttd_topics.settings');
    $analysis_in_progress = $node->hasField('field_ttd_analysis_in_progress') && (bool) $node->get('field_ttd_analysis_in_progress')->value;
    $is_held_for_analysis = (bool) $config->get('block_until_analyzed') && $analysis_in_progress && !$node->isPublished();
    $is_eligible = $node->isPublished() || $force_analysis || $is_held_for_analysis;
    $is_analyzed = !$node->get('field_ttd_last_analyzed')->isEmpty();

    if (!$is_eligible || ($is_analyzed && !$force_analysis)) {
      return JobResult::success('Node not eligible for analysis or already analyzed');
    }

    try {
      // Set analysis in progress.
      $node->set('field_ttd_analysis_in_progress', TRUE);
      $node->save();

      $this->performSingleAnalysis($node, $force_analysis || $is_held_for_analysis);

      // Clear analysis in progress.
      $node->set('field_ttd_analysis_in_progress', FALSE);

      // If block_until_analyzed is enabled, publish the node after analysis.
      if ($config->get('block_until_analyzed') && !$node->isPublished()) {
        $node->setPublished();
        \Drupal::logger('topicalboost')->info('Published node @nid after analysis completed (block_until_analyzed).', ['@nid' => $node->id()]);
      }

      $node->save();

      return JobResult::success('TopicalBoost analysis completed for node ' . $identifier);
    }
    catch (\Exception $e) {
      // Clear analysis in progress in case of error.
      $node->set('field_ttd_analysis_in_progress', FALSE);
      $node->save();

      \Drupal::logger('ttd_topics')->error('Error processing node @id: @message', [
        '@id' => $identifier,
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Error processing node ' . $identifier . ': ' . $e->getMessage());
    }
  }

  /**
   * Perform single analysis for a node.
   */
  private function performSingleAnalysis(NodeInterface $node, bool $force_analysis = FALSE) {
    $result = \Drupal::service('ttd_topics.analysis_service')->performSingleAnalysis($node, $force_analysis);

    if (empty($result['success'])) {
      throw new \Exception($result['message'] ?? 'Analysis failed');
    }
  }

  /**
   * Save the analysis results to the node.
   */
  private function saveAnalysisResults(NodeInterface $node, array $results) {
    $topicalboost = [];
    foreach ($results['entities'] as $entity) {
      $name = $entity['name'] ?? $entity['nl_name'] ?? $entity['kg_name'] ?? $entity['wb_name'] ?? NULL;
      if (!empty($name)) {
        $term_id = $this->getOrCreateTerm($name, $entity['id'], $entity);
        if ($term_id) {
          $topicalboost[] = [
            'target_id' => $term_id,
          ];

          // Store demand metrics (KD/KV) if present
          $this->storeDemandMetricsForTerm($term_id, $entity);
        }
      }
    }
    $node->set('field_ttd_topics', $topicalboost);
    $node->save();
  }

  /**
   * Get or create a taxonomy term for a TTD Topic.
   */
  private function getOrCreateTerm($name, $ttd_id, $entity_data) {
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

    // If still not found, create a new term.
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
    // Check if entity has keyword_difficulty, search_volume, and/or traffic_potential.
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

}
