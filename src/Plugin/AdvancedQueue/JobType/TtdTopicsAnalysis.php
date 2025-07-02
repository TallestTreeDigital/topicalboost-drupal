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
    $is_eligible = $node->isPublished() || $force_analysis;
    $is_analyzed = !$node->get('field_ttd_last_analyzed')->isEmpty();

    if (!$is_eligible || ($is_analyzed && !$force_analysis)) {
      return JobResult::success('Node not eligible for analysis or already analyzed');
    }

    try {
      // Set analysis in progress.
      $node->set('field_ttd_analysis_in_progress', TRUE);
      $node->save();

      $this->performSingleAnalysis($node);

      // Clear analysis in progress.
      $node->set('field_ttd_analysis_in_progress', FALSE);
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
  private function performSingleAnalysis(NodeInterface $node) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = TOPICALBOOST_API_ENDPOINT;

    $client = new Client();

    try {
      // Step 1: Initiate analysis.
      $response = $client->post($api_base_url . '/analyze/single', [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'customer_id' => $node->id(),
          'url' => $node->toUrl()->setAbsolute()->toString(),
          'title' => $node->getTitle(),
          'text' => $node->get('body')->value,
        ],
      ]);

      $result = json_decode($response->getBody(), TRUE);
      $request_id = $result['request_id'];

      // Step 2: Poll for analysis completion.
      $completed = FALSE;
      $max_attempts = 30;
      $attempt = 0;

      while (!$completed && $attempt < $max_attempts) {
        // Wait for 10 seconds between polls.
        sleep(10);
        $poll_response = $client->get($api_base_url . '/poll/analysis', [
          'headers' => ['x-api-key' => $api_key],
          'query' => ['request_id' => $request_id],
        ]);

        $poll_result = json_decode($poll_response->getBody(), TRUE);
        if ($poll_result['ready']) {
          $completed = TRUE;
        }

        $attempt++;
      }

      if (!$completed) {
        throw new \Exception('Analysis timed out');
      }

      // Step 3: Get analysis results.
      $results_response = $client->get($api_base_url . '/result/entities', [
        'headers' => ['x-api-key' => $api_key],
        'query' => ['request_id' => $request_id],
      ]);

      $analysis_results = json_decode($results_response->getBody(), TRUE);

      // Process and save the results.
      $this->saveAnalysisResults($node, $analysis_results);

      // Update the ttd_last_analyzed field.
      $node->set('field_ttd_last_analyzed', \Drupal::time()->getRequestTime());
      $node->save();

    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error during TopicalBoost analysis: @error', ['@error' => $e->getMessage()]);
      throw $e;
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
        $topicalboost[] = [
          'target_id' => $this->getOrCreateTerm($name, $entity['id'], $entity),
        ];
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
      'field_ttd_id' => $ttd_id,
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
          $term->set('field_ttd_id', $ttd_id);
        $term->save();
      }
      return $term->id();
    }

    // If still not found, create a new term.
    try {
      $term = Term::create([
        'vid' => 'ttd_topics',
        'name' => $name,
        'field_ttd_id' => $ttd_id,
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

}
