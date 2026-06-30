<?php

namespace Drupal\ttd_topics\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands for backfilling local curation overrides.
 */
class SyncLocalCurationOverridesCommand extends DrushCommands {

  /**
   * Push local hidden and force-shown topics into TopicalBoost.
   *
   * @command topicalboost:sync-local-curation-overrides
   * @aliases ttd-sync-local-curation
   * @option dry-run List local overrides without changing API state.
   */
  public function syncLocalCurationOverrides(array $options = ['dry-run' => FALSE]): void {
    $dry_run = !empty($options['dry-run']);
    $this->output()->writeln('<info>Syncing local curation overrides to TopicalBoost...</info>');

    /** @var \Drupal\ttd_topics\Service\TtdSyncService $sync_service */
    $sync_service = \Drupal::service('ttd_topics.sync_service');
    $stats = $sync_service->syncLocalCurationOverrides($dry_run);

    $this->output()->writeln('Local hidden topics: ' . (int) ($stats['hidden'] ?? 0));
    $this->output()->writeln('Local force-shown topics: ' . (int) ($stats['force_show'] ?? 0));

    if ($dry_run) {
      foreach (($stats['samples']['hidden'] ?? []) as $row) {
        $this->output()->writeln(sprintf('  Hidden: %s #%d', $row['entity_name'], $row['entity_id']));
      }
      foreach (($stats['samples']['force_show'] ?? []) as $row) {
        $this->output()->writeln(sprintf('  Force Show: %s #%d', $row['entity_name'], $row['entity_id']));
      }
      $this->logger()->success('Dry run complete. No API changes made.');
      return;
    }

    $this->output()->writeln('Sent: ' . (int) ($stats['sent'] ?? 0));
    $this->output()->writeln('Failed: ' . (int) ($stats['failed'] ?? 0));

    if (!empty($stats['failed'])) {
      $this->logger()->warning('Local curation override backfill completed with failures.');
      return;
    }

    $this->logger()->success('Local curation override backfill complete.');
  }

}
