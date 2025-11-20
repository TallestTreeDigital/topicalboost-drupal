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
 * Optimized job type for applying bulk analysis results using /result/posts endpoint.
 * More efficient than separate customer_ids and entities calls.
 *
 * @AdvancedQueueJobType(
 *   id = "ttd_bulk_apply_posts_optimized",
 *   label = @Translation("TopicalBoost Bulk Apply Posts (Optimized)"),
 *   max_retries = 3,
 *   retry_delay = 30
 * )
 */
class TtdBulkApplyPostsOptimized extends JobTypeBase {

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

      // Single API call gets posts + entities together using v2 endpoint
      $response = $client->get($api_base_url . '/v2/result/posts', [
        'headers' => ['x-api-key' => $api_key],
        'query' => [
          'request_id' => $request_id,
          'page' => $page,
        ],
        'timeout' => 30,
      ]);

      $result = json_decode($response->getBody(), TRUE);

      if (isset($result['posts']) && !empty($result['posts'])) {
        $posts = $result['posts'];
        $entities = $result['entities'] ?? [];

        // Process entities first (store metadata)
        foreach ($entities as $entity) {
          $this->storeEntityMetadata($entity);
        }

        // Process posts (assign topics to nodes)
        $processed_count = $this->processPosts($posts, $entities);

        // Update progress
        $apply_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', [
          'stage' => 'posts',
          'posts' => ['completed' => 0, 'total' => 0, 'current_page' => 1],
        ]);

        $apply_progress['posts']['completed'] = $page;
        $apply_progress['posts']['total'] = $result['page_count'] ?? $page;
        $apply_progress['posts']['current_page'] = $page;
        \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $apply_progress);

        // Check if there are more pages
        if (isset($result['has_next_page']) && $result['has_next_page']) {
          // Schedule next page
          $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
          $queue = $queue_storage->load('ttd_topics_analysis');

          $next_job = Job::create('ttd_bulk_apply_posts_optimized', [
            'request_id' => $request_id,
            'page' => $page + 1,
          ]);
          $queue->enqueueJob($next_job);
        } else {
          // Mark as complete
          $apply_progress['stage'] = 'complete';
          \Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', $apply_progress);
          \Drupal::state()->set('topicalboost.bulk_analysis.completed_at', time());
        }

        return JobResult::success('Processed posts page ' . $page . ' with ' . $processed_count . ' posts');
      } else {
        return JobResult::success('No posts found for page ' . $page);
      }

    } catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Error applying posts for page @page: @message', [
        '@page' => $page,
        '@message' => $e->getMessage(),
      ]);
      return JobResult::failure('Error applying posts for page ' . $page . ': ' . $e->getMessage());
    }
  }

  /**
   * Process posts and assign entities to nodes.
   */
  private function processPosts($posts, $entities) {
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $processed_count = 0;

    \Drupal::logger('ttd_topics')->info('processPosts: Starting with @count posts', [
      '@count' => count($posts),
    ]);

    foreach ($posts as $post) {
      $customer_id = $post['customer_id'] ?? NULL;
      $entity_ids = $post['entity_ids'] ?? [];

      if ($customer_id && !empty($entity_ids)) {
        $node = $node_storage->load($customer_id);

        if ($node && $node instanceof NodeInterface) {
          // Check if field exists
          if (!$node->hasField('field_ttd_topics')) {
            \Drupal::logger('ttd_topics')->error(
              'Field field_ttd_topics not found on node @nid (type: @type)',
              ['@nid' => $customer_id, '@type' => $node->getType()]
            );
            continue;
          }

          // Mark node as analyzed
          $node->set('field_ttd_last_analyzed', \Drupal::time()->getRequestTime());

          // Collect entities for this post
          $post_entities = [];
          foreach ($entity_ids as $entity_id) {
            if (isset($entities[$entity_id])) {
              $post_entities[] = $entities[$entity_id];
            }
          }

          // Apply entities to node
          $this->applyEntitiesToNode($node, $post_entities);

          try {
            $node->save();
            $processed_count++;
          } catch (\Exception $e) {
            \Drupal::logger('ttd_topics')->error(
              'Failed to save node @nid: @error',
              ['@nid' => $customer_id, '@error' => $e->getMessage()]
            );
          }
        }
      }
    }

    \Drupal::logger('ttd_topics')->info('processPosts: Processed @count posts', [
      '@count' => $processed_count,
    ]);

    return $processed_count;
  }

  /**
   * Apply entities to a specific node.
   */
  private function applyEntitiesToNode(NodeInterface $node, $entities) {
    $topicalboost = [];
    $failed_entities = [];
    $existing_terms = [];

    // Get already-assigned term IDs to avoid duplicates
    foreach ($node->field_ttd_topics->getValue() as $item) {
      $existing_terms[] = $item['target_id'];
    }

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
          // Only add if not already assigned to this node
          if (!in_array($term_id, $existing_terms)) {
            $topicalboost[] = ['target_id' => $term_id];
            $existing_terms[] = $term_id;
          }
        } else {
          \Drupal::logger('ttd_topics')->warning(
            'Failed to create/find term for entity @ttd_id: @name',
            ['@ttd_id' => $ttd_id, '@name' => $name]
          );
          $failed_entities[] = $ttd_id;
        }
      } catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->error(
          'Error processing entity @ttd_id (@name): @error',
          ['@ttd_id' => $ttd_id, '@name' => $name, '@error' => $e->getMessage()]
        );
        $failed_entities[] = $ttd_id;
      }
    }

    if (!empty($topicalboost)) {
      foreach ($topicalboost as $topic) {
        $node->field_ttd_topics->appendItem($topic);
      }
    }
  }

  /**
   * Store entity metadata in database.
   */
  private function storeEntityMetadata($entity_data) {
    $database = \Drupal::database();
    $ttd_id = $entity_data['id'] ?? NULL;

    if (!$ttd_id) {
      return;
    }

    // Check if entity already exists
    $existing = $database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', $ttd_id)
      ->execute()
      ->fetchAssoc();

    if (!$existing) {
      try {
        $name = $entity_data['name'] ?? $entity_data['nl_name'] ?? $entity_data['kg_name'] ?? $entity_data['wb_name'] ?? 'Unknown';

        $entity_fields = [
          'ttd_id' => $ttd_id,
          'name' => $name,
          'createdAt' => $this->convertToMysqlDatetime($entity_data['createdAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
          'updatedAt' => $this->convertToMysqlDatetime($entity_data['updatedAt'] ?? NULL) ?: date('Y-m-d H:i:s'),
        ];

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

        // Handle related data
        $this->handleRelatedData($ttd_id, 'schema_types', $entity_data['SchemaTypes'] ?? []);
        $this->handleRelatedData($ttd_id, 'wb_categories', $entity_data['WBCategories'] ?? []);

      } catch (\Exception $e) {
        \Drupal::logger('ttd_topics')->error('Error storing entity @ttd_id: @message', [
          '@ttd_id' => $ttd_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Store entity-post relationships from Contents array
    if (isset($entity_data['Contents']) && is_array($entity_data['Contents'])) {
      foreach ($entity_data['Contents'] as $content) {
        $customer_id = $content['customer_id'] ?? NULL;
        if ($customer_id) {
          $existing_rel = \Drupal::database()->select('ttd_entity_post_ids', 'tep')
            ->fields('tep')
            ->condition('entity_id', $ttd_id)
            ->condition('post_id', $customer_id)
            ->execute()
            ->fetchAssoc();

          if (!$existing_rel) {
            try {
              \Drupal::database()->insert('ttd_entity_post_ids')
                ->fields([
                  'entity_id' => $ttd_id,
                  'post_id' => (string) $customer_id,
                  'createdAt' => date('Y-m-d H:i:s'),
                  'updatedAt' => date('Y-m-d H:i:s'),
                ])
                ->execute();
            } catch (\Exception $e) {
              // Continue on error
            }
          }
        }
      }
    }
  }

  /**
   * Get or create a taxonomy term.
   * Each unique TTD ID gets its own term, identified by field_ttd_id.
   * Multiple terms can have the same name - they're distinguished by TTD ID.
   */
  private function getOrCreateTerm($name, $ttd_id, $entity_data) {
    if (empty($name)) {
      return NULL;
    }

    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    // Always check by TTD ID first - this is the unique identifier
    $terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'field_ttd_id' => $ttd_id,
    ]);

    if (!empty($terms)) {
      return reset($terms)->id();
    }

    // Create new term for this TTD ID
    // Drupal allows multiple terms with the same name; they're unique by term ID
    try {
      $term = Term::create([
        'vid' => 'ttd_topics',
        'name' => $name,
        'field_ttd_id' => $ttd_id,
      ]);
      $term->save();
      return $term->id();
    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error(
        'Failed to create taxonomy term for TTD ID @ttd_id (name: @name): @error',
        ['@ttd_id' => $ttd_id, '@name' => $name, '@error' => $e->getMessage()]
      );
      return NULL;
    }
  }

  /**
   * Handle related data (schema types and wikibase categories).
   */
  private function handleRelatedData($ttd_id, $type, $data) {
    if (empty($data) || !is_array($data)) {
      return;
    }

    $database = \Drupal::database();

    if ($type === 'schema_types') {
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
            } catch (\Exception $e) {
              // Continue on error
            }
          }

          $existing_rel = $database->select('ttd_entity_schema_types', 'test')
            ->fields('test')
            ->condition('entity_id', $ttd_id)
            ->condition('schema_type_id', $item['id'])
            ->execute()
            ->fetchAssoc();

          if (!$existing_rel) {
            try {
              $database->insert('ttd_entity_schema_types')
                ->fields([
                  'entity_id' => $ttd_id,
                  'schema_type_id' => $item['id'],
                  'createdAt' => date('Y-m-d H:i:s'),
                  'updatedAt' => date('Y-m-d H:i:s'),
                ])
                ->execute();
            } catch (\Exception $e) {
              // Continue on error
            }
          }
        }
      }
    } elseif ($type === 'wb_categories') {
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
            } catch (\Exception $e) {
              // Continue on error
            }
          }

          $existing_rel = $database->select('ttd_entity_wb_categories', 'tewc')
            ->fields('tewc')
            ->condition('entity_id', $ttd_id)
            ->condition('wb_category_id', $item['id'])
            ->execute()
            ->fetchAssoc();

          if (!$existing_rel) {
            try {
              $database->insert('ttd_entity_wb_categories')
                ->fields([
                  'entity_id' => $ttd_id,
                  'wb_category_id' => $item['id'],
                  'createdAt' => date('Y-m-d H:i:s'),
                  'updatedAt' => date('Y-m-d H:i:s'),
                ])
                ->execute();
            } catch (\Exception $e) {
              // Continue on error
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
      $date = new \DateTime($datetime);
      return $date->format('Y-m-d H:i:s');
    } catch (\Exception $e) {
      return NULL;
    }
  }

}
