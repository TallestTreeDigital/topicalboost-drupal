<?php

namespace Drupal\ttd_topics\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * A Drush commandfile to fix TopicalBoost terms.
 */
class FixTtdTopicsCommand extends DrushCommands {

  /**
   * Fix TTD Topic terms by setting the correct ttd_id.
   *
   * @command topicalboost:fix-terms
   * @aliases ttf
   */
  public function fixTerms() {
    // Get the database connection.
    $database = \Drupal::database();

    // Count total entities.
    $total_entities = $database->select('ttd_entities', 'e')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->output()->writeln("Total entities to process: $total_entities");

    // Set up progress bar.
    $progress_bar = new ProgressBar($this->output(), $total_entities);
    $progress_bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
    $progress_bar->start();

    // Process entities in batches.
    $batch_size = 1000;
    $offset = 0;

    while (TRUE) {
      $query = $database->select('ttd_entities', 'e')
        ->fields('e')
        ->range($offset, $batch_size);
      $entities = $query->execute()->fetchAll();

      if (empty($entities)) {
        break;
      }

      foreach ($entities as $entity) {
        try {
          $this->fixTtdTopic($entity);
          $progress_bar->advance();
        }
        catch (\Exception $e) {
          $this->output()->writeln("\n<error>Error processing entity {$entity->ttd_id}: {$e->getMessage()}</error>");
        }
      }

      $offset += $batch_size;
    }

    $progress_bar->finish();
    $this->output()->writeln("\nFinished fixing TTD Topic terms.");
  }

  /**
   * Fix a TTD Topic term by setting the correct ttd_id.
   */
  private function fixTtdTopic($entity) {
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => 'ttd_topics',
        'name' => $entity->name ?? $entity->nl_name ?? $entity->kg_name ?? $entity->wb_name ?? 'Unnamed Entity',
      ]);

    if (!empty($existing_terms)) {
      $term = reset($existing_terms);
      // Update term with the correct ttd_id.
              $term->set('field_ttd_id', $entity->ttd_id);
      $term->save();
    }
  }

}
