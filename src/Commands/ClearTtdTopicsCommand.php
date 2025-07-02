<?php

namespace Drupal\ttd_topics\Commands;

use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * A Drush commandfile to clear TopicalBoost relationships.
 */
class ClearTtdTopicsCommand extends DrushCommands {

  /**
   * Clear all TTD Topic term and node relationships.
   *
   * @command topicalboost:clear-relationships
   * @aliases ttdcr
   */
  public function clearRelationships() {
    // Confirmation prompt.
    if (!$this->io()->confirm('Are you sure you want to clear all TTD Topic relationships? This action cannot be undone.', FALSE)) {
      $this->output()->writeln('Operation cancelled.');
      return;
    }

    $this->output()->writeln('<info>Clearing TTD Topic relationships...</info>');

    // Get all nodes with TopicalBoost.
    $query = \Drupal::entityQuery('node')
      ->exists('field_ttd_topics');
    $nids = $query->execute();

    $total = count($nids);
    $this->output()->writeln(sprintf('Found %d nodes with TopicalBoost.', $total));

    if ($total === 0) {
      $this->output()->writeln('<info>No TTD Topic relationships found. Nothing to clear.</info>');
      return;
    }

    // Set up progress bar.
    $progress_bar = new ProgressBar($this->output(), $total);
    $progress_bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
    $progress_bar->start();

    $batch_size = 50;
    $chunks = array_chunk($nids, $batch_size);

    // Suppress warnings.
    $oldErrorReporting = error_reporting();
    error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

    foreach ($chunks as $chunk) {
      $nodes = Node::loadMultiple($chunk);
      foreach ($nodes as $node) {
        // Use error control operator to suppress warnings.
        @$node->set('field_ttd_topics', []);
        @$node->save();
        $progress_bar->advance();
      }
      \Drupal::entityTypeManager()->getStorage('node')->resetCache($chunk);
    }

    // Restore error reporting.
    error_reporting($oldErrorReporting);

    $progress_bar->finish();
    $this->output()->writeln('');
    $this->output()->writeln('<info>All TTD Topic relationships have been cleared.</info>');

    // Optionally, clear the field_ttd_last_analyzed as well.
    if ($this->io()->confirm('Do you also want to clear the field_ttd_last_analyzed for all nodes?', TRUE)) {
      $this->output()->writeln('<info>Clearing field_ttd_last_analyzed...</info>');

      $query = \Drupal::database()->update('node__field_ttd_last_analyzed')
        ->fields(['field_ttd_last_analyzed_value' => NULL]);
      $affected = $query->execute();

      $this->output()->writeln(sprintf('<info>Cleared field_ttd_last_analyzed for %d nodes.</info>', $affected));
    }

    $this->output()->writeln('<info>Operation completed successfully.</info>');
  }

  /**
   * Clear all TTD data including tables, terms, and relationships.
   *
   * @command topicalboost:clear-all-data
   * @aliases ttd-clear-all
   * @option dry-run Show what would be deleted without actually deleting anything
   */
  public function clearAllData($options = ['dry-run' => FALSE]) {
    $isDryRun = $options['dry-run'];

    if ($isDryRun) {
      $this->output()->writeln('<comment>DRY RUN MODE - No data will be deleted</comment>');
    }
    else {
      // Confirmation prompt.
      if (!$this->io()->confirm('Are you sure you want to clear ALL TTD data? This will delete:
- All TTD topic terms
- All TTD tables
- All node relationships
- All TTD configuration data
This action cannot be undone.', FALSE)) {
        $this->output()->writeln('Operation cancelled.');
        return;
      }
    }

    $this->output()->writeln('<info>Starting complete TTD data cleanup...</info>');

    // Step 1: Clear node relationships.
    $this->clearNodeRelationshipsInternal($isDryRun);

    // Step 2: Delete all TTD topic terms.
    $this->deleteTtdTopicTerms($isDryRun);

    // Step 3: Clear TTD tables.
    $this->clearTtdTables($isDryRun);

    // Step 4: Clear TTD configuration (skipping config reset)
    $this->clearTtdConfiguration($isDryRun, FALSE);

    // Step 5: Clear caches.
    if (!$isDryRun) {
      $this->clearCaches();
    }
    else {
      $this->output()->writeln('<comment>[DRY RUN] Would clear caches</comment>');
    }

    if ($isDryRun) {
      $this->output()->writeln('<comment>DRY RUN completed - no data was actually deleted!</comment>');
    }
    else {
      $this->output()->writeln('<info>Complete TTD data cleanup finished successfully!</info>');
    }
  }

  /**
   * Internal method to clear node relationships.
   */
  protected function clearNodeRelationshipsInternal($isDryRun = FALSE) {
    $this->output()->writeln('<info>Clearing node relationships...</info>');

    // Count field_ttd_topics.
    $count = \Drupal::database()->select('node__field_ttd_topics', 'n')->countQuery()->execute()->fetchField();
    if ($isDryRun) {
      $this->output()->writeln("<comment>[DRY RUN] Would clear {$count} TTD topic relationships from nodes.</comment>");
    }
    else {
      $cleared = \Drupal::database()->delete('node__field_ttd_topics')->execute();
      $this->output()->writeln("Cleared {$cleared} TTD topic relationships from nodes.");
    }

    // Count field_ttd_last_analyzed.
    $count = \Drupal::database()->select('node__field_ttd_last_analyzed', 'n')
      ->condition('field_ttd_last_analyzed_value', NULL, 'IS NOT NULL')
      ->countQuery()->execute()->fetchField();
    if ($isDryRun) {
      $this->output()->writeln("<comment>[DRY RUN] Would clear TTD last analyzed timestamps for {$count} nodes.</comment>");
    }
    else {
      // Delete the rows instead of setting to NULL since the column doesn't allow NULL.
      $cleared = \Drupal::database()->delete('node__field_ttd_last_analyzed')
        ->condition('field_ttd_last_analyzed_value', NULL, 'IS NOT NULL')
        ->execute();
      $this->output()->writeln("Cleared TTD last analyzed timestamps for {$cleared} nodes.");
    }

    // Clear field_ttd_rejected_topics if it exists.
    if (\Drupal::database()->schema()->tableExists('node__field_ttd_rejected_topics')) {
      $count = \Drupal::database()->select('node__field_ttd_rejected_topics', 'n')->countQuery()->execute()->fetchField();
      if ($isDryRun) {
        $this->output()->writeln("<comment>[DRY RUN] Would clear {$count} rejected topic entries.</comment>");
      }
      else {
        $cleared = \Drupal::database()->delete('node__field_ttd_rejected_topics')->execute();
        $this->output()->writeln("Cleared {$cleared} rejected topic entries.");
      }
    }

    // Clear field_ttd_analysis_in_progress if it exists.
    if (\Drupal::database()->schema()->tableExists('node__field_ttd_analysis_in_progress')) {
      $count = \Drupal::database()->select('node__field_ttd_analysis_in_progress', 'n')
        ->condition('field_ttd_analysis_in_progress_value', 0, '!=')
        ->countQuery()->execute()->fetchField();
      if ($isDryRun) {
        $this->output()->writeln("<comment>[DRY RUN] Would clear analysis in progress flags for {$count} nodes.</comment>");
      }
      else {
        // Set to 0 (false) instead of deleting since boolean fields typically don't allow NULL.
        $cleared = \Drupal::database()->update('node__field_ttd_analysis_in_progress')
          ->fields(['field_ttd_analysis_in_progress_value' => 0])
          ->condition('field_ttd_analysis_in_progress_value', 0, '!=')
          ->execute();
        $this->output()->writeln("Cleared analysis in progress flags for {$cleared} nodes.");
      }
    }
  }

  /**
   * Delete all TTD topic terms.
   */
  protected function deleteTtdTopicTerms($isDryRun = FALSE) {
    $this->output()->writeln('<info>Deleting TTD topic terms...</info>');

    // Get count first.
    $count = \Drupal::database()->select('taxonomy_term_field_data', 't')
      ->condition('vid', 'ttd_topics')
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($count == 0) {
      $this->output()->writeln('No TTD topic terms found.');
      return;
    }

    $this->output()->writeln("Found {$count} TTD topic terms to delete.");

    if ($isDryRun) {
      $this->output()->writeln("<comment>[DRY RUN] Would delete {$count} terms from taxonomy_term_field_data.</comment>");
      $this->output()->writeln("<comment>[DRY RUN] Would delete {$count} terms from taxonomy_term_data.</comment>");
      $this->output()->writeln("<comment>[DRY RUN] Would clean up orphaned term data.</comment>");
    }
    else {
      // Delete from taxonomy_term_field_data.
      $deleted = \Drupal::database()->delete('taxonomy_term_field_data')
        ->condition('vid', 'ttd_topics')
        ->execute();
      $this->output()->writeln("Deleted {$deleted} terms from taxonomy_term_field_data.");

      // Delete from taxonomy_term_data.
      $deleted = \Drupal::database()->delete('taxonomy_term_data')
        ->condition('vid', 'ttd_topics')
        ->execute();
      $this->output()->writeln("Deleted {$deleted} terms from taxonomy_term_data.");

      // Clean up any orphaned term data.
      $this->cleanupOrphanedTermData($isDryRun);
    }
  }

  /**
   * Clear TTD tables.
   */
  protected function clearTtdTables($isDryRun = FALSE) {
    $this->output()->writeln('<info>Clearing TTD tables...</info>');

    // Get all tables that start with 'ttd'.
    $tables = \Drupal::database()->query("SHOW TABLES LIKE 'ttd%'")->fetchCol();

    if (empty($tables)) {
      $this->output()->writeln('No TTD tables found.');
      return;
    }

    foreach ($tables as $table) {
      try {
        if ($isDryRun) {
          // Get row count for dry run.
          $count = \Drupal::database()->select($table, 't')->countQuery()->execute()->fetchField();
          $this->output()->writeln("<comment>[DRY RUN] Would clear table {$table} ({$count} rows)</comment>");
        }
        else {
          \Drupal::database()->truncate($table)->execute();
          $this->output()->writeln("Cleared table: {$table}");
        }
      }
      catch (\Exception $e) {
        if ($isDryRun) {
          $this->output()->writeln("<comment>[DRY RUN] Would attempt to clear table {$table} (error: " . $e->getMessage() . ")</comment>");
        }
        else {
          $this->output()->writeln("Failed to clear table {$table}: " . $e->getMessage());
        }
      }
    }
  }

  /**
   * Clear TTD configuration.
   */
  protected function clearTtdConfiguration($isDryRun = FALSE, $resetConfig = TRUE) {
    $this->output()->writeln('<info>Clearing TTD configuration...</info>');

    // Clear queue items.
    if (\Drupal::database()->schema()->tableExists('advancedqueue')) {
      $count = \Drupal::database()->select('advancedqueue', 'aq')
        ->condition('queue_id', 'ttd_topics_analysis')
        ->countQuery()->execute()->fetchField();

      if ($isDryRun) {
        $this->output()->writeln("<comment>[DRY RUN] Would clear {$count} queue items.</comment>");
      }
      else {
        $cleared = \Drupal::database()->delete('advancedqueue')
          ->condition('queue_id', 'ttd_topics_analysis')
          ->execute();
        $this->output()->writeln("Cleared {$cleared} queue items.");
      }
    }

    // Reset TTD settings to defaults (optional)
    if ($resetConfig) {
      if ($isDryRun) {
        $this->output()->writeln('<comment>[DRY RUN] Would reset TTD configuration to defaults.</comment>');
      }
      else {
        $config = \Drupal::configFactory()->getEditable('ttd_topics.settings');
        $config->set('topicalboost_api_key', '');
        $config->save();
        $this->output()->writeln('Reset TTD configuration to defaults.');
      }
    }
    else {
      if ($isDryRun) {
        $this->output()->writeln('<comment>[DRY RUN] Skipping TTD configuration reset.</comment>');
      }
      else {
        $this->output()->writeln('Skipping TTD configuration reset.');
      }
    }
  }

  /**
   * Clean up orphaned term data.
   */
  protected function cleanupOrphanedTermData($isDryRun = FALSE) {
    // Clean up taxonomy_term__field_ttd_id.
    if (\Drupal::database()->schema()->tableExists('taxonomy_term__field_ttd_id')) {
      $count = \Drupal::database()->select('taxonomy_term__field_ttd_id', 't')
        ->condition('bundle', 'ttd_topics')
        ->countQuery()->execute()->fetchField();

      if ($isDryRun) {
        if ($count > 0) {
          $this->output()->writeln("<comment>[DRY RUN] Would clean up {$count} orphaned TTD ID field entries.</comment>");
        }
      }
      else {
        $deleted = \Drupal::database()->delete('taxonomy_term__field_ttd_id')
          ->condition('bundle', 'ttd_topics')
          ->execute();
        if ($deleted > 0) {
          $this->output()->writeln("Cleaned up {$deleted} orphaned TTD ID field entries.");
        }
      }
    }

    // Clean up taxonomy_term__field_hide.
    if (\Drupal::database()->schema()->tableExists('taxonomy_term__field_hide')) {
      $count = \Drupal::database()->select('taxonomy_term__field_hide', 't')
        ->condition('bundle', 'ttd_topics')
        ->countQuery()->execute()->fetchField();

      if ($isDryRun) {
        if ($count > 0) {
          $this->output()->writeln("<comment>[DRY RUN] Would clean up {$count} orphaned hide field entries.</comment>");
        }
      }
      else {
        $deleted = \Drupal::database()->delete('taxonomy_term__field_hide')
          ->condition('bundle', 'ttd_topics')
          ->execute();
        if ($deleted > 0) {
          $this->output()->writeln("Cleaned up {$deleted} orphaned hide field entries.");
        }
      }
    }

    // Clean up path aliases for TopicalBoost.
    if (\Drupal::database()->schema()->tableExists('path_alias')) {
      $count = \Drupal::database()->select('path_alias', 'pa')
        ->condition('path', '/taxonomy/term/%', 'LIKE')
        ->condition('alias', '/tags/%', 'LIKE')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($isDryRun) {
        if ($count > 0) {
          $this->output()->writeln("<comment>[DRY RUN] Would clean up {$count} orphaned path aliases for TopicalBoost.</comment>");
        }
      }
      else {
        // Delete path aliases that start with /tags/ (TopicalBoost pattern)
        $deleted = \Drupal::database()->delete('path_alias')
          ->condition('path', '/taxonomy/term/%', 'LIKE')
          ->condition('alias', '/tags/%', 'LIKE')
          ->execute();
        if ($deleted > 0) {
          $this->output()->writeln("Cleaned up {$deleted} orphaned path aliases for TopicalBoost.");
        }
      }
    }

    // Clean up term relationships - skip this as the query syntax might not work in all cases.
    if (!$isDryRun) {
      try {
        $deleted = \Drupal::database()->query("
          DELETE tr FROM taxonomy_term_relationship tr
          LEFT JOIN taxonomy_term_data td ON tr.tid = td.tid
          WHERE td.tid IS NULL
        ")->rowCount();

        if ($deleted > 0) {
          $this->output()->writeln("Cleaned up {$deleted} orphaned term relationships.");
        }
      }
      catch (\Exception $e) {
        $this->output()->writeln("Could not clean up term relationships: " . $e->getMessage());
      }
    }
    else {
      $this->output()->writeln("<comment>[DRY RUN] Would attempt to clean up orphaned term relationships.</comment>");
    }
  }

  /**
   * Clear caches.
   */
  protected function clearCaches() {
    $this->output()->writeln('<info>Clearing caches...</info>');

    \Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache();
    \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->resetCache();
    \Drupal::entityTypeManager()->getStorage('node')->resetCache();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
    \Drupal::service('cache.entity')->deleteAll();
    \Drupal::service('cache.data')->deleteAll();

    // Clear pathauto cache if available.
    if (\Drupal::hasService('pathauto.alias_cleaner')) {
      \Drupal::service('cache.default')->deleteAll();
    }

    $this->output()->writeln('Caches cleared.');
  }

}
