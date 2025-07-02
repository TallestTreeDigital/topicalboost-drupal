<?php

namespace Drupal\ttd_topics\Commands;

use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Creates or updates TTD Topic terms.
 */
class CreateTtdTopicsCommand extends DrushCommands {

  // Increased batch size for better performance.
  const BATCH_SIZE = 250;
  // Memory threshold in MB before forced garbage collection.
  const MEMORY_THRESHOLD = 256;

  /**
   * Create or update TTD Topic terms.
   *
   * @command topicalboost:create-terms
   * @aliases ttct
   */
  public function createTerms() {
    $database = \Drupal::database();

    // Count total entities.
    $total_entities = $database->select('ttd_entities', 'e')
      ->countQuery()
      ->execute()
      ->fetchField();

    if (!$total_entities) {
      $this->logger()->warning('No entities found to process.');
      return;
    }

    $this->output()->writeln(sprintf('Processing %d entities...', $total_entities));
    $progress_bar = new ProgressBar($this->output(), $total_entities);
    $progress_bar->start();

    $stats = [
      'processed' => 0,
      'created' => 0,
      'updated' => 0,
      'errors' => 0,
    ];

    try {
      // Process in batches.
      for ($offset = 0; $offset < $total_entities; $offset += static::BATCH_SIZE) {
        // Check memory usage and force cleanup if needed.
        if ($this->shouldCleanMemory()) {
          $this->clearCaches();
        }

        $entities = $database->select('ttd_entities', 'e')
          ->fields('e', ['ttd_id', 'name', 'nl_name', 'kg_name', 'wb_name', 'wb_description'])
          ->range($offset, static::BATCH_SIZE)
          ->execute()
          ->fetchAll();

        $this->processBatch($entities, $stats, $progress_bar);
      }
    }
    finally {
      $progress_bar->finish();
      $this->output()->writeln('');
      $this->output()->writeln(sprintf(
        'Processed %d entities: %d created, %d updated, %d errors',
        $stats['processed'],
        $stats['created'],
        $stats['updated'],
        $stats['errors']
      ));
    }
  }

  /**
   * Process a batch of entities.
   */
  protected function processBatch($entities, &$stats, $progress_bar) {
    // Load existing terms for the batch.
    $ttd_ids = array_map(function ($entity) {
      return $entity->ttd_id;
    }, $entities);

    /** @var \Drupal\taxonomy\TermInterface[] $existing_terms */
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'ttd_topics',
        'field_ttd_id' => $ttd_ids,
      ]);

    $term_map = [];
    foreach ($existing_terms as $term) {
      // Safely access the field_ttd_id value
      if ($term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()) {
        $term_map[$term->get('field_ttd_id')->value] = $term;
      }
    }

    foreach ($entities as $entity) {
      try {
        $name = $entity->name ?? $entity->nl_name ?? $entity->kg_name ?? $entity->wb_name ?? 'Unnamed Entity';

        if (isset($term_map[$entity->ttd_id])) {
          $term = $term_map[$entity->ttd_id];
          if ($term->label() !== $name) {
            $term->setName($name);
            $term->save();
            $stats['updated']++;
          }
        }
        else {
          Term::create([
            'vid' => 'ttd_topics',
            'name' => $name,
            'field_ttd_id' => $entity->ttd_id,
            'description' => [
              'value' => $entity->wb_description ?? '',
              'format' => 'plain_text',
            ],
          ])->save();
          $stats['created']++;
        }

        $stats['processed']++;
        $progress_bar->advance();
      }
      catch (\Exception $e) {
        $stats['errors']++;
        $this->logger()->error('Error processing entity @id: @message', [
          '@id' => $entity->ttd_id,
          '@message' => $e->getMessage(),
        ]);
      }
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
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::service('entity.memory_cache')->deleteAll();
    \Drupal::service('cache.entity')->deleteAll();
    \Drupal::service('cache.data')->deleteAll();
    gc_collect_cycles();
  }

}
