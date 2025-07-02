<?php

namespace Drupal\ttd_topics\Commands;

use Drupal\node\NodeInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Attributes TopicalBoost to nodes.
 */
class AttributeTtdTopicsCommand extends DrushCommands {

  // Optimize batch sizes based on testing.
  // Increased from 100.
  const BATCH_SIZE = 250;
  const MEMORY_THRESHOLD = 128;
  // Slightly increased.
  const NODE_LOAD_CHUNK = 25;
  const TERM_LOAD_CHUNK = 100;

  /**
   * Processing statistics.
   *
   * @var array
   */
  private $stats = [
    'processed' => 0,
    'skipped' => 0,
    'updated' => 0,
    'errors' => 0,
  ];

  /**
   * Attribute TopicalBoost to nodes.
   *
   * @command topicalboost:attribute-topics
   * @aliases ttdat
   */
  public function attributeTopics() {
    $database = \Drupal::database();

    // Count total connections.
    $total = $database->select('ttd_entity_post_ids', 'ep')
      ->countQuery()
      ->execute()
      ->fetchField();

    if (!$total) {
      $this->logger()->warning('No connections found to process.');
      return;
    }

    if ($total > 100000 && !$this->io()->confirm("About to process {$total} relationships. Continue?")) {
      return;
    }

    $this->output()->writeln(sprintf('Processing %d connections...', $total));
    $progress_bar = new ProgressBar($this->output(), $total);
    $progress_bar->start();

    try {
      // Process in batches.
      for ($offset = 0; $offset < $total; $offset += static::BATCH_SIZE) {
        if ($this->shouldCleanMemory()) {
          $this->clearCaches();
        }

        $connections = $this->fetchConnections($database, static::BATCH_SIZE, $offset);
        if (empty($connections)) {
          break;
        }

        $this->processBatch($connections, $progress_bar);
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Error during batch processing: @message', ['@message' => $e->getMessage()]);
    }
    finally {
      $progress_bar->finish();
      $this->output()->writeln('');
      $this->output()->writeln(sprintf(
        'Processed %d connections: %d updated, %d skipped, %d errors',
        $this->stats['processed'],
        $this->stats['updated'],
        $this->stats['skipped'],
        $this->stats['errors']
      ));
    }
  }

  /**
   * Fetch a batch of connections efficiently.
   */
  protected function fetchConnections($database, $limit, $offset) {
    try {
      return $database->select('ttd_entity_post_ids', 'ep')
        ->fields('ep', ['post_id', 'entity_id'])
        ->range($offset, $limit)
        ->execute()
        ->fetchAllAssoc('post_id', \PDO::FETCH_ASSOC);
    }
    catch (\Exception $e) {
      $this->logger()->error('Database error: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Process a batch of connections with optimized memory usage.
   */
  protected function processBatch($connections, $progress_bar) {
    $database = \Drupal::database();

    try {
      // Start transaction for batch.
      $transaction = $database->startTransaction();

      // Group connections by node ID for efficient loading.
      $node_updates = [];
      $ttd_ids = [];

      foreach ($connections as $post_id => $connection) {
        $node_updates[$connection['post_id']][] = $connection['entity_id'];
        $ttd_ids[] = $connection['entity_id'];
      }

      // Pre-load all terms at once if under memory threshold.
      if (count($ttd_ids) * 0.2 < static::MEMORY_THRESHOLD) {
        $terms = $this->loadTermsForBatch(array_unique($ttd_ids));
      }
      else {
        // Load terms in chunks if dataset is large.
        $term_chunks = array_chunk(array_unique($ttd_ids), static::TERM_LOAD_CHUNK);
        $terms = [];
        foreach ($term_chunks as $term_chunk) {
          $terms += $this->loadTermsForBatch($term_chunk);
        }
      }

      // Process nodes in optimized chunks.
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $chunks = array_chunk(array_keys($node_updates), static::NODE_LOAD_CHUNK, TRUE);

      foreach ($chunks as $node_ids) {
        $nodes = $node_storage->loadMultiple($node_ids);
        foreach ($nodes as $nid => $node) {
          if (!$node instanceof NodeInterface || !$node->isPublished()) {
            $this->stats['skipped']++;
            $progress_bar->advance(count($node_updates[$nid]));
            continue;
          }

          $this->updateNodeTopics($node, $node_updates[$nid], $terms);
          $progress_bar->advance(count($node_updates[$nid]));
        }

        // Clear entity cache only, keep terms cached.
        $node_storage->resetCache($node_ids);
      }

      // Commit transaction.
      unset($transaction);
    }
    catch (\Exception $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
  }

  /**
   * Update a single node's topics efficiently.
   */
  protected function updateNodeTopics($node, $ttd_ids, $terms) {
    try {
      $existing_terms = $node->get('field_ttd_topics')->getValue();
      $existing_ids = array_column($existing_terms, 'target_id');

      // Use array operations instead of loops where possible.
      $new_term_ids = array_filter(
        array_map(function ($ttd_id) use ($terms) {
          return $terms[$ttd_id] ?? NULL;
        }, $ttd_ids)
      );

      $terms_to_add = array_diff($new_term_ids, $existing_ids);

      if (!empty($terms_to_add)) {
        $node->set('field_ttd_topics', array_merge(
          $existing_terms,
          array_map(function ($tid) {
            return ['target_id' => $tid];
          }, $terms_to_add)
        ));
        $node->save();
        $this->stats['updated']++;
      }

      $this->stats['processed'] += count($ttd_ids);
    }
    catch (\Exception $e) {
      $this->stats['errors']++;
      $this->logger()->error('Error updating node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Load terms for a batch of TTD IDs efficiently.
   */
  protected function loadTermsForBatch($ttd_ids) {
    try {
      /** @var \Drupal\taxonomy\TermInterface[] $terms */
      $terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => 'ttd_topics',
          'field_ttd_id' => $ttd_ids,
        ]);

      $result = [];
      foreach ($terms as $term) {
        // Safely access the field_ttd_id value
        if ($term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()) {
          $ttd_id = $term->get('field_ttd_id')->value;
          $result[$ttd_id] = $term->id();
        }
      }

      return $result;
    }
    catch (\Exception $e) {
      $this->logger()->error('Error loading terms: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Check if memory usage exceeds threshold.
   */
  protected function shouldCleanMemory() {
    // Convert to MB.
    $memory_usage = memory_get_usage(TRUE) / 1024 / 1024;
    return $memory_usage > static::MEMORY_THRESHOLD;
  }

  /**
   * Clear caches to free memory.
   */
  protected function clearCaches() {
    \Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::service('entity.memory_cache')->deleteAll();
    \Drupal::service('cache.entity')->deleteAll();
    \Drupal::service('cache.data')->deleteAll();
    gc_collect_cycles();
  }

}
