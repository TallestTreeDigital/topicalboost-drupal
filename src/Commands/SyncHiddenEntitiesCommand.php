<?php

namespace Drupal\ttd_topics\Commands;

use Drush\Commands\DrushCommands;

/**
 * Drush commands for synchronizing hidden TopicalBoost entities.
 */
class SyncHiddenEntitiesCommand extends DrushCommands {

  /**
   * Fetch hidden entities from the API and apply them locally.
   *
   * @command topicalboost:sync-hidden-entities
   * @aliases ttd-sync-hidden
   */
  public function syncHiddenEntities(): void {
    $this->output()->writeln('<info>Syncing hidden entities from TopicalBoost admin...</info>');

    /** @var \Drupal\ttd_topics\Service\TtdSyncService $sync_service */
    $sync_service = \Drupal::service('ttd_topics.sync_service');
    $stats = $sync_service->syncHiddenEntities();

    $this->output()->writeln('Hidden: ' . (int) ($stats['hidden'] ?? 0));
    $this->output()->writeln('Unhidden: ' . (int) ($stats['unhidden'] ?? 0));
    $this->output()->writeln('Already hidden: ' . (int) ($stats['already_hidden'] ?? 0));
    $this->output()->writeln('Not found locally: ' . (int) ($stats['not_found'] ?? 0));
    $this->logger()->success('Hidden entities sync complete.');
  }

}
