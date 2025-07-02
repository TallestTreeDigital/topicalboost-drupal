<?php

namespace Drupal\ttd_topics\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Drush command to hide TopicalBoost from a CSV file.
 */
class HideTtdTopicsCommand extends DrushCommands {

  /**
   * Hide TopicalBoost listed in a CSV file.
   *
   * @param string $file
   *   Path to the CSV file containing topic names to hide.
   *
   * @command topicalboost:hide-from-csv
   * @aliases ttdhide
   * @usage drush topicalboost:hide-from-csv hide.csv
   */
  public function hideFromCsv($file) {
    if (!file_exists($file)) {
      $this->logger()->error("File not found: $file");
      return;
    }

    // Read CSV file and split by commas.
    $content = file_get_contents($file);
    $topics = array_map('trim', explode(',', $content));
    $total = count($topics);

    $this->output()->writeln("<info>Found $total topics to process</info>");

    // Set up progress bar.
    $progress = new ProgressBar($this->output(), $total);
    $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
    $progress->start();

    $stats = [
      'processed' => 0,
      'hidden' => 0,
      'not_found' => 0,
      'already_hidden' => 0,
      'errors' => 0,
    ];

    foreach ($topics as $topic_name) {
      try {
        // Skip empty lines.
        if (empty($topic_name)) {
          continue;
        }

        $terms = \Drupal::entityTypeManager()
          ->getStorage('taxonomy_term')
          ->loadByProperties([
            'vid' => 'ttd_topics',
            'name' => trim($topic_name),
          ]);

        if (empty($terms)) {
          $stats['not_found']++;
          $this->logger()->warning("Topic not found: $topic_name");
        }
        else {
          foreach ($terms as $term) {
            if ($term->get('field_hide')->value) {
              $stats['already_hidden']++;
            }
            else {
              $term->set('field_hide', TRUE);
              $term->save();
              $stats['hidden']++;
            }
          }
        }

        $stats['processed']++;
        $progress->advance();

      }
      catch (\Exception $e) {
        $stats['errors']++;
        $this->logger()->error("Error processing topic '$topic_name': " . $e->getMessage());
      }
    }

    $progress->finish();
    $this->output()->writeln('');
    $this->output()->writeln("<info>Processing complete!</info>");
    $this->output()->writeln("Processed: {$stats['processed']}");
    $this->output()->writeln("Hidden: {$stats['hidden']}");
    $this->output()->writeln("Already hidden: {$stats['already_hidden']}");
    $this->output()->writeln("Not found: {$stats['not_found']}");
    $this->output()->writeln("Errors: {$stats['errors']}");
  }

}
