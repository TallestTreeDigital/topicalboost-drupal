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
 * Job type for applying bulk analysis entities to nodes.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_bulk_apply_entities",
 *   label = @Translation("TopicalBoost Bulk Apply Entities"),
 *   max_retries = 3,
 *   retry_delay = 30
 * )
 */
class TtdBulkApplyEntities extends JobTypeBase {

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

      $response = $client->get($api_base_url . '/result/entities', [
        'headers' => ['x-api-key' => $api_key],
        'query' => [
          'request_id' => $request_id,
          'page' => $page,
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody(), TRUE);

      if (isset($result['entities']) && !empty($result['entities'])) {
        $processed_count = $this->processEntities($result['entities']);

        // Update progress.
        $apply_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', []);
        $apply_progress['entities']['completed'] += $processed_count;
        \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $apply_progress);

        // Check if there are more pages.
        if (isset($result['has_next_page']) && $result['has_next_page']) {
          // Schedule next page.
          $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
          $queue = $queue_storage->load('ttd_topics_analysis');

          $next_job = Job::create('ttd_bulk_apply_entities', [
            'request_id' => $request_id,
            'page' => $page + 1,
          ]);
          $queue->enqueueJob($next_job);
        }
        else {
          // Mark entities phase as complete.
          $apply_progress['stage'] = 'complete';
          $apply_progress['entities']['current_page'] = $page;
          \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $apply_progress);
          \Drupal::state()->set('topicalboost.bulk_analysis.completed_at', time());
        }

        return JobResult::success('Processed entities page ' . $page . ' with ' . $processed_count . ' entities');
      }
      else {
        // No entities found, check if this should end the process.
        $entity_page_count = \Drupal::state()->get('topicalboost.bulk_analysis.entity_page_count', 1);
        if ($page >= $entity_page_count) {
          // Mark as complete even if no entities on final page.
          $apply_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', []);
          $apply_progress['stage'] = 'complete';
          \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $apply_progress);
          \Drupal::state()->set('topicalboost.bulk_analysis.completed_at', time());
        }

        return JobResult::success('No entities found for page ' . $page);
      }

    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error applying entities for page @page: @message', [
        '@page' => $page,
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Error applying entities for page ' . $page . ': ' . $e->getMessage());
    }
  }

  /**
   * Process entities and apply them to nodes.
   */
  private function processEntities($entities) {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    // Group entities by customer_id (node ID)
    $entities_by_node = [];
    foreach ($entities as $entity) {
      // Extract customer_ids from Contents array.
      if (isset($entity['Contents']) && is_array($entity['Contents'])) {
        foreach ($entity['Contents'] as $content) {
          $customer_id = $content['customer_id'] ?? NULL;
          if ($customer_id) {
            if (!isset($entities_by_node[$customer_id])) {
              $entities_by_node[$customer_id] = [];
            }
            $entities_by_node[$customer_id][] = $entity;
          }
        }
      }
    }

    // Process each node.
    $failed_nodes = [];
    foreach ($entities_by_node as $node_id => $node_entities) {
      $node = $node_storage->load($node_id);

      if ($node && $node instanceof NodeInterface) {
        // Check if field exists before applying entities
        if (!$node->hasField('field_ttd_topics')) {
          \Drupal::logger('ttd_topics')->error(
            'Field field_ttd_topics not found on node @nid (type: @type)',
            ['@nid' => $node_id, '@type' => $node->getType()]
          );
          $failed_nodes[] = $node_id;
          continue;
        }

        $this->applyEntitiesToNode($node, $node_entities);

        // Note: field_ttd_last_analyzed is now set in the customer IDs phase
        // so we don't need to set it here again.
        try {
          $node->save();
        }
        catch (\Exception $e) {
          \Drupal::logger('ttd_topics')->error(
            'Failed to save node @nid: @error',
            ['@nid' => $node_id, '@error' => $e->getMessage()]
          );
          $failed_nodes[] = $node_id;
        }
      }
      else {
        \Drupal::logger('ttd_topics')->warning(
          'Node @nid not found or not a valid node',
          ['@nid' => $node_id]
        );
        $failed_nodes[] = $node_id;
      }
    }

    // Log summary of failed nodes
    if (!empty($failed_nodes)) {
      \Drupal::logger('ttd_topics')->warning(
        'Failed to apply entities to @count nodes: @nodes',
        ['@count' => count($failed_nodes), '@nodes' => implode(', ', array_slice($failed_nodes, 0, 10))]
      );
    }

    // Return the actual count of unique entities processed, not entity-node associations.
    return count($entities);
  }

  /**
   * Apply entities to a specific node.
   */
  private function applyEntitiesToNode(NodeInterface $node, $entities) {
    $topicalboost = [];
    $failed_entities = [];

    foreach ($entities as $entity) {
      $name = $entity['name'] ?? $entity['nl_name'] ?? $entity['kg_name'] ?? $entity['wb_name'] ?? NULL;
      $ttd_id = $entity['id'] ?? NULL;

      if (empty($name) || empty($ttd_id)) {
        $failed_entities[] = $ttd_id ?? 'unknown';
        continue;
      }

      try {
        $term_id = $this->getOrCreateTerm($name, $ttd_id, $entity);
        if ($term_id) {
          $topicalboost[] = ['target_id' => $term_id];
        }
        else {
          \Drupal::logger('ttd_topics')->warning(
            'Failed to create/find term for entity @ttd_id: @name',
            ['@ttd_id' => $ttd_id, '@name' => $name]
          );
          $failed_entities[] = $ttd_id;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->error(
          'Error processing entity @ttd_id (@name): @error',
          ['@ttd_id' => $ttd_id, '@name' => $name, '@error' => $e->getMessage()]
        );
        $failed_entities[] = $ttd_id;
      }
    }

    // Log failed entities for this node
    if (!empty($failed_entities)) {
      \Drupal::logger('ttd_topics')->warning(
        'Failed to process @count entities for node @nid: @entities',
        ['@count' => count($failed_entities), '@nid' => $node->id(), '@entities' => implode(', ', array_slice($failed_entities, 0, 5))]
      );
    }

    if (!empty($topicalboost)) {
      $node->set('field_ttd_topics', $topicalboost);
    }
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
      // Insert the entity into ttd_entities table with all available data.
      try {
        $entity_fields = [
          'ttd_id' => $ttd_id,
          'name' => $name,
          'createdAt' => $this->convertToMysqlDatetime($entity_data['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
          'updatedAt' => $this->convertToMysqlDatetime($entity_data['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
        ];

        // Add all available entity data fields.
        $available_fields = [
          'mid', 'nl_name', 'nl_type', 'wikipedia_url', 'kg_name', 'kg_image',
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

        foreach ($available_fields as $field) {
          if (isset($entity_data[$field])) {
            $entity_fields[$field] = $entity_data[$field];
          }
        }

        $database->insert('ttd_entities')
          ->fields($entity_fields)
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
      // Update existing entity if needed.
      $update_fields = [];
      foreach ($entity_data as $key => $value) {
        if (isset($existing_entity[$key]) && $existing_entity[$key] != $value) {
          // Convert datetime fields to MySQL format.
          if (in_array($key, ['createdAt', 'updatedAt'])) {
            $update_fields[$key] = $this->convertToMysqlDatetime($value);
          }
          else {
            $update_fields[$key] = $value;
          }
        }
      }
      if (!empty($update_fields)) {
        try {
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

    // Store entity-node relationships from Contents array.
    if (isset($entity_data['Contents']) && is_array($entity_data['Contents'])) {
      foreach ($entity_data['Contents'] as $content) {
        $customer_id = $content['customer_id'] ?? NULL;
        if ($customer_id) {
          // Check if relationship already exists.
          $existing = $database->select('ttd_entity_post_ids', 'tep')
            ->fields('tep')
            ->condition('entity_id', $ttd_id)
            ->condition('post_id', $customer_id)
            ->execute()
            ->fetchAssoc();

          if (!$existing) {
            try {
              $database->insert('ttd_entity_post_ids')
                ->fields([
                  'entity_id' => $ttd_id,
                  'post_id' => (string) $customer_id,
                  'createdAt' => date('Y-m-d H:i:s'),
                  'updatedAt' => date('Y-m-d H:i:s'),
                ])
                ->execute();
            }
            catch (\Exception $e) {
              \Drupal::logger('ttd_topics')->error('Error inserting entity-post relationship: @message', [
                '@message' => $e->getMessage(),
              ]);
            }
          }
        }
      }
    }

    // Handle related data (schema_types and wb_categories)
    $this->handleRelatedData($ttd_id, 'schema_types', $entity_data['SchemaTypes'] ?? []);
    $this->handleRelatedData($ttd_id, 'wb_categories', $entity_data['WBCategories'] ?? []);

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

    // Create a new term.
    $term = Term::create([
      'vid' => 'ttd_topics',
      'name' => $name,
      'field_ttd_id' => $ttd_id,
    ]);
    $term->save();

    return $term->id();
  }

  /**
   * Handle related data for entities.
   */
  private function handleRelatedData($ttd_id, $type, $data) {
    if (empty($data) || !is_array($data)) {
      return;
    }

    $database = \Drupal::database();

    if ($type === 'schema_types') {
      // First insert into ttd_schema_types table.
      foreach ($data as $item) {
        if (isset($item['id'])) {
          $existing = $database->select('ttd_schema_types', 'tst')
            ->fields('tst')
            ->condition('ttd_id', $item['id'])
            ->execute()
            ->fetchAssoc();

          if (!$existing) {
            try {
              $database->insert('ttd_schema_types')
                ->fields([
                  'ttd_id' => $item['id'],
                  'name' => $item['name'] ?? 'Unknown',
                  'createdAt' => $this->convertToMysqlDatetime($item['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
                  'updatedAt' => $this->convertToMysqlDatetime($item['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
                ])
                ->execute();
            }
            catch (\Exception $e) {
              \Drupal::logger('ttd_topics')->error('Error inserting schema type @id: @message', [
                '@id' => $item['id'],
                '@message' => $e->getMessage(),
              ]);
            }
          }

          // Then insert relationship.
          $existing_relation = $database->select('ttd_entity_schema_types', 'test')
            ->fields('test')
            ->condition('entity_id', $ttd_id)
            ->condition('schema_type_id', $item['id'])
            ->execute()
            ->fetchAssoc();

          if (!$existing_relation) {
            try {
              $database->insert('ttd_entity_schema_types')
                ->fields([
                  'entity_id' => $ttd_id,
                  'schema_type_id' => $item['id'],
                  'createdAt' => date('Y-m-d H:i:s'),
                  'updatedAt' => date('Y-m-d H:i:s'),
                ])
                ->execute();
            }
            catch (\Exception $e) {
              \Drupal::logger('ttd_topics')->error('Error inserting entity-schema relationship: @message', [
                '@message' => $e->getMessage(),
              ]);
            }
          }
        }
      }
    }
    elseif ($type === 'wb_categories') {
      // First insert into ttd_wb_categories table.
      foreach ($data as $item) {
        if (isset($item['id'])) {
          $existing = $database->select('ttd_wb_categories', 'twc')
            ->fields('twc')
            ->condition('ttd_id', $item['id'])
            ->execute()
            ->fetchAssoc();

          if (!$existing) {
            try {
              $database->insert('ttd_wb_categories')
                ->fields([
                  'ttd_id' => $item['id'],
                  'qid' => $item['qid'] ?? 'Unknown',
                  'name' => $item['name'] ?? 'Unknown',
                  'createdAt' => $this->convertToMysqlDatetime($item['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
                  'updatedAt' => $this->convertToMysqlDatetime($item['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
                ])
                ->execute();
            }
            catch (\Exception $e) {
              \Drupal::logger('ttd_topics')->error('Error inserting wb category @id: @message', [
                '@id' => $item['id'],
                '@message' => $e->getMessage(),
              ]);
            }
          }

          // Then insert relationship.
          $existing_relation = $database->select('ttd_entity_wb_categories', 'tewc')
            ->fields('tewc')
            ->condition('entity_id', $ttd_id)
            ->condition('wb_category_id', $item['id'])
            ->execute()
            ->fetchAssoc();

          if (!$existing_relation) {
            try {
              $database->insert('ttd_entity_wb_categories')
                ->fields([
                  'entity_id' => $ttd_id,
                  'wb_category_id' => $item['id'],
                  'createdAt' => date('Y-m-d H:i:s'),
                  'updatedAt' => date('Y-m-d H:i:s'),
                ])
                ->execute();
            }
            catch (\Exception $e) {
              \Drupal::logger('ttd_topics')->error('Error inserting entity-wb category relationship: @message', [
                '@message' => $e->getMessage(),
              ]);
            }
          }
        }
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
