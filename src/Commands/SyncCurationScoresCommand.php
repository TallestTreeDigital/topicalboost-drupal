<?php

namespace Drupal\ttd_topics\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands for synchronizing TopicalBoost curation scores.
 */
class SyncCurationScoresCommand extends DrushCommands {

  /**
   * Fetch curation scores from the API and cache render decisions locally.
   *
   * @command topicalboost:sync-curation-scores
   * @aliases ttd-sync-curation
   */
  public function syncCurationScores(): void {
    $this->output()->writeln('<info>Syncing curation scores from TopicalBoost...</info>');

    /** @var \Drupal\ttd_topics\Service\TtdSyncService $sync_service */
    $sync_service = \Drupal::service('ttd_topics.sync_service');
    $stats = $sync_service->syncCurationScores();

    $this->output()->writeln('Scores: ' . (int) ($stats['scores'] ?? 0));
    $this->output()->writeln('Updated: ' . (int) ($stats['updated'] ?? 0));
    $this->output()->writeln('Unchanged: ' . (int) ($stats['unchanged'] ?? 0));
    $this->output()->writeln('Removed stale: ' . (int) ($stats['removed'] ?? 0));
    $this->logger()->success('Curation scores sync complete.');
  }

}
