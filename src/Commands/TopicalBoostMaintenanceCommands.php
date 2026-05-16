<?php

namespace Drupal\ttd_topics\Commands;

use Drush\Drush;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Drush maintenance commands for TopicalBoost parity tasks.
 */
class TopicalBoostMaintenanceCommands extends DrushCommands {

  /**
   * Import TopicalBoost SQL data and rebuild Drupal topic terms/relationships.
   *
   * @param string $file
   *   Path to a SQL file to import.
   * @param array $options
   *   Command options.
   *
   * @command topicalboost:import-topics
   * @aliases ttd-import-topics
   * @option dry-run Validate the import plan without changing data.
   */
  public function importTopics(string $file, array $options = ['dry-run' => FALSE]): void {
    if (!is_readable($file)) {
      throw new \InvalidArgumentException("Cannot read SQL file: {$file}");
    }

    $dry_run = (bool) $options['dry-run'];
    $steps = [
      'Clear existing TopicalBoost data',
      'Import SQL file into the Drupal database',
      'Create or update TopicalBoost terms',
      'Create node/topic relationships',
      'Refresh low-count topic cache',
      'Create or verify TopicalBoost indexes',
      'Rebuild Drupal caches',
    ];

    if ($dry_run) {
      $this->output()->writeln('Dry run import plan:');
      foreach ($steps as $index => $step) {
        $this->output()->writeln(sprintf('%d. %s', $index + 1, $step));
      }
      $this->logger()->success("Dry run complete. SQL file is readable: {$file}");
      return;
    }

    if (!$this->io()->confirm('Importing topics will clear existing TopicalBoost topic data before loading the SQL file. Continue?', FALSE)) {
      $this->output()->writeln('Operation cancelled.');
      return;
    }

    $this->output()->writeln('Starting TTD topic import...');
    $this->runDrushCommand('topicalboost:clear-all-data', [], ['yes' => TRUE]);
    $this->importSqlFile($file);
    $this->runDrushCommand('topicalboost:create-terms');
    $this->runDrushCommand('topicalboost:attribute-topics');
    $this->runDrushCommand('topicalboost:update-low-count-topics');
    $this->runDrushCommand('topicalboost:create-indexes');
    $this->runDrushCommand('cache:rebuild');

    $this->logger()->success('TTD topic import completed successfully.');
  }

  /**
   * Create or verify indexes used by TopicalBoost import and lookup tables.
   *
   * @command topicalboost:create-indexes
   * @aliases ttd-create-indexes
   */
  public function createIndexes(): void {
    $database = \Drupal::database();
    $schema = $database->schema();
    \Drupal::moduleHandler()->loadInclude('ttd_topics', 'install');
    $table_specs = function_exists('ttd_topics_schema') ? ttd_topics_schema() : [];
    $table_specs['taxonomy_term__field_ttd_id'] = [
      'fields' => [
        'bundle' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
        ],
        'field_ttd_id_value' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
        ],
      ],
    ];

    $indexes = [
      'ttd_entities' => [
        'ttd_entities_name' => [['name', 191]],
        'ttd_entities_mid' => [['mid', 191]],
        'ttd_entities_nl_name' => [['nl_name', 191]],
        'ttd_entities_kg_name' => [['kg_name', 191]],
        'ttd_entities_wb_name' => [['wb_name', 191]],
        'ttd_entities_wb_qid' => [['wb_qid', 191]],
        'ttd_entities_hide' => ['hide'],
      ],
      'ttd_entity_post_ids' => [
        'ttd_epi_entity_id' => ['entity_id'],
        'ttd_epi_post_id' => [['post_id', 191]],
        'ttd_epi_entity_post' => ['entity_id', ['post_id', 191]],
      ],
      'ttd_entity_schema_types' => [
        'ttd_est_entity_id' => ['entity_id'],
        'ttd_est_schema_type_id' => ['schema_type_id'],
        'ttd_est_entity_schema' => ['entity_id', 'schema_type_id'],
      ],
      'ttd_entity_wb_categories' => [
        'ttd_ewc_entity_category' => ['entity_id', 'wb_category_id'],
      ],
      'ttd_schema_types' => [
        'ttd_schema_types_name' => [['name', 191]],
      ],
      'ttd_wb_categories' => [
        'ttd_wb_categories_name' => [['name', 191]],
      ],
      'taxonomy_term__field_ttd_id' => [
        'ttd_term_ttd_id_value' => [['field_ttd_id_value', 191]],
        'ttd_term_bundle_ttd_id' => ['bundle', ['field_ttd_id_value', 191]],
      ],
    ];

    $created = 0;
    $verified = 0;
    $skipped = 0;

    foreach ($indexes as $table => $table_indexes) {
      if (!$schema->tableExists($table)) {
        $this->output()->writeln("Skipped missing table: {$table}");
        $skipped += count($table_indexes);
        continue;
      }

      foreach ($table_indexes as $index_name => $fields) {
        if (empty($table_specs[$table])) {
          $this->output()->writeln("Skipped {$index_name}; no schema spec found for {$table}.");
          $skipped++;
          continue;
        }

        if ($schema->indexExists($table, $index_name)) {
          $this->output()->writeln("Index {$index_name} already exists on {$table}.");
          $verified++;
          continue;
        }

        try {
          $schema->addIndex($table, $index_name, $fields, $table_specs[$table]);
          $this->output()->writeln("Created index {$index_name} on {$table}.");
          $created++;
        }
        catch (\Throwable $e) {
          $this->logger()->warning('Failed to create index @index on @table: @message', [
            '@index' => $index_name,
            '@table' => $table,
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    $this->logger()->success("Index verification complete. Created: {$created}. Existing: {$verified}. Skipped: {$skipped}.");
  }

  /**
   * Delete legacy nodes with the ttd_topic content type.
   *
   * @param array $options
   *   Command options.
   *
   * @command topicalboost:cleanup-posts
   * @aliases ttd-cleanup-posts
   * @option dry-run Count matching nodes without deleting.
   */
  public function cleanupPosts(array $options = ['dry-run' => FALSE]): void {
    $dry_run = (bool) $options['dry-run'];
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ttd_topic')
      ->execute();
    $total = count($nids);

    if ($total === 0) {
      $this->logger()->success('No ttd_topic nodes found to delete.');
      return;
    }

    if ($dry_run) {
      $this->logger()->success("Dry run complete. ttd_topic nodes that would be deleted: {$total}.");
      return;
    }

    if (!$this->io()->confirm("Delete {$total} legacy ttd_topic nodes and their field data?", FALSE)) {
      $this->output()->writeln('Operation cancelled.');
      return;
    }

    $progress = new ProgressBar($this->output(), $total);
    $progress->start();
    $deleted = 0;

    foreach (array_chunk($nids, 100) as $chunk) {
      $nodes = $node_storage->loadMultiple($chunk);
      $node_storage->delete($nodes);
      $deleted += count($nodes);
      $progress->advance(count($nodes));
      $node_storage->resetCache($chunk);
    }

    $progress->finish();
    $this->output()->writeln('');
    $this->logger()->success("Successfully deleted {$deleted} ttd_topic nodes and their associated data.");
  }

  /**
   * Delete all terms in the TopicalBoost topic vocabulary.
   *
   * @param array $options
   *   Command options.
   *
   * @command topicalboost:delete-ttd-topics
   * @aliases ttd-delete-topics
   * @option dry-run Count matching terms without deleting.
   */
  public function deleteTtdTopics(array $options = ['dry-run' => FALSE]): void {
    $dry_run = (bool) $options['dry-run'];
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $term_ids = $term_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'ttd_topics')
      ->execute();
    $total = count($term_ids);

    if ($total === 0) {
      $this->logger()->success('No ttd_topics terms found.');
      return;
    }

    if ($dry_run) {
      $this->logger()->success("Dry run complete. ttd_topics terms that would be deleted: {$total}.");
      return;
    }

    if (!$this->io()->confirm("Delete {$total} TopicalBoost topic terms and their relationships?", FALSE)) {
      $this->output()->writeln('Operation cancelled.');
      return;
    }

    $progress = new ProgressBar($this->output(), $total);
    $progress->start();
    $deleted = 0;

    foreach (array_chunk($term_ids, 250) as $chunk) {
      $terms = $term_storage->loadMultiple($chunk);
      $term_storage->delete($terms);
      $deleted += count($terms);
      $progress->advance(count($terms));
      $term_storage->resetCache($chunk);
    }

    \Drupal::state()->delete('ttd_low_count_topic_term_ids');
    \Drupal::state()->delete('ttd_low_count_topic_term_ids_updated');
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['taxonomy_term_list:ttd_topics']);

    $progress->finish();
    $this->output()->writeln('');
    $this->logger()->success("All ttd_topics terms and their relationships have been deleted. Total: {$deleted}.");
  }

  /**
   * Toggle the TopicalBoost debug mode setting.
   *
   * @command topicalboost:toggle-debug-mode
   * @aliases ttd-toggle-debug
   */
  public function toggleDebugMode(): void {
    $config = \Drupal::configFactory()->getEditable('ttd_topics.settings');
    $enabled = !(bool) $config->get('debug_mode');
    $config->set('debug_mode', $enabled)->save();

    $this->logger()->success(sprintf(
      'Debug mode has been %s.',
      $enabled ? 'enabled' : 'disabled'
    ));
  }

  /**
   * Refresh cached topic IDs that should be hidden by count threshold.
   *
   * @command topicalboost:update-low-count-topics
   * @aliases ttd-update-low-count
   */
  public function updateLowCountTopics(): void {
    $database = \Drupal::database();
    $min_count = (int) \Drupal::config('ttd_topics.settings')
      ->get('post_topic_minimum_display_count');
    $min_count = max(1, $min_count);
    $schema = $database->schema();

    $query = $database->select('taxonomy_term_field_data', 'td');
    $query->addField('td', 'tid');
    $query->leftJoin('taxonomy_index', 'ti', 'ti.tid = td.tid');
    $query->condition('td.vid', 'ttd_topics');
    $query->condition('td.status', 1);
    $query->groupBy('td.tid');

    if ($schema->tableExists('taxonomy_term__field_force_show')) {
      $query->leftJoin('taxonomy_term__field_force_show', 'tfs', 'tfs.entity_id = td.tid AND tfs.deleted = 0');
      $query->having('COALESCE(MAX(tfs.field_force_show_value), 0) != 1');
    }

    $low_count_condition = 'COUNT(ti.nid) < :min_count';
    if ($schema->tableExists('taxonomy_term__field_hide')) {
      $query->leftJoin('taxonomy_term__field_hide', 'tfh', 'tfh.entity_id = td.tid AND tfh.deleted = 0');
      $low_count_condition = '(COALESCE(MAX(tfh.field_hide_value), 0) = 1 OR ' . $low_count_condition . ')';
    }
    $query->having($low_count_condition, [
      ':min_count' => $min_count,
    ]);

    $term_ids = array_map('intval', $query->execute()->fetchCol());
    \Drupal::state()->set('ttd_low_count_topic_term_ids', $term_ids);
    \Drupal::state()->set('ttd_low_count_topic_term_ids_updated', \Drupal::time()->getRequestTime());

    $total = count($term_ids);
    if ($total > 0) {
      $progress = new ProgressBar($this->output(), $total);
      $progress->start();
      $progress->advance($total);
      $progress->finish();
      $this->output()->writeln('');
    }
    else {
      $this->output()->writeln('No low count topics to update.');
    }

    $this->logger()->success("Finished updating low count topic term IDs. Total: {$total}.");
  }

  /**
   * Update ttd_entities.wb_qid from a CSV mapping of mid to wb_qid.
   *
   * @param string $file
   *   Path to a CSV file with mid and wb_qid columns.
   * @param array $options
   *   Command options.
   *
   * @command topicalboost:update-wb-qid
   * @aliases ttd-update-wb-qid
   * @option dry-run Count affected rows without updating.
   */
  public function updateWbQid($file, array $options = ['dry-run' => FALSE]): void {
    if (!is_readable($file)) {
      $this->logger()->error("Cannot read CSV file: {$file}");
      return;
    }

    $mapping = $this->readWbQidMapping($file);
    if (empty($mapping)) {
      $this->logger()->warning('No valid mid/wb_qid mappings found in CSV.');
      return;
    }

    $database = \Drupal::database();
    if (!$database->schema()->tableExists('ttd_entities')) {
      $this->logger()->error('The ttd_entities table does not exist.');
      return;
    }

    $dry_run = (bool) $options['dry-run'];
    $total = count($mapping);
    $affected = 0;

    $this->output()->writeln(sprintf(
      'Loaded %d mappings. Starting %supdate...',
      $total,
      $dry_run ? 'dry-run ' : ''
    ));

    $progress = new ProgressBar($this->output(), $total);
    $progress->start();

    foreach (array_chunk($mapping, 500, TRUE) as $chunk) {
      if ($dry_run) {
        $query = $database->select('ttd_entities', 'e');
        $query->condition('mid', array_keys($chunk), 'IN');
        $query->isNotNull('wb_qid');
        $query->condition('wb_qid', 'Q%', 'NOT LIKE');
        $affected += (int) $query->countQuery()->execute()->fetchField();
      }
      else {
        foreach ($chunk as $mid => $wb_qid) {
          $affected += (int) $database->update('ttd_entities')
            ->fields(['wb_qid' => $wb_qid])
            ->condition('mid', $mid)
            ->isNotNull('wb_qid')
            ->condition('wb_qid', 'Q%', 'NOT LIKE')
            ->execute();
        }
      }

      $progress->advance(count($chunk));
    }

    $progress->finish();
    $this->output()->writeln('');

    if ($dry_run) {
      $this->logger()->success("Dry run complete. Rows that would be updated: {$affected}. No database changes made.");
    }
    else {
      $this->logger()->success("wb_qid update complete. Rows updated: {$affected}.");
    }
  }

  /**
   * Reads a CSV mapping keyed by mid.
   *
   * @param string $file
   *   CSV file path.
   *
   * @return array
   *   Mapping of mid => wb_qid.
   */
  protected function readWbQidMapping(string $file): array {
    $handle = fopen($file, 'r');
    if (!$handle) {
      return [];
    }

    $header = fgetcsv($handle);
    if ($header === FALSE) {
      fclose($handle);
      return [];
    }

    $header = array_map(function ($value) {
      return strtolower(trim((string) $value));
    }, $header);
    $mid_index = array_search('mid', $header, TRUE);
    $wb_qid_index = array_search('wb_qid', $header, TRUE);
    if ($mid_index === FALSE || $wb_qid_index === FALSE) {
      fclose($handle);
      $this->logger()->error("CSV header must contain 'mid' and 'wb_qid' columns.");
      return [];
    }

    $mapping = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
      $mid = trim((string) ($row[$mid_index] ?? ''));
      $wb_qid = trim((string) ($row[$wb_qid_index] ?? ''));
      if ($mid !== '' && $wb_qid !== '') {
        $mapping[$mid] = $wb_qid;
      }
    }

    fclose($handle);
    return $mapping;
  }

  /**
   * Run another Drush command for the current site.
   */
  protected function runDrushCommand(string $command, array $args = [], array $options = []): void {
    $process = $this->processManager()->drush(
      Drush::aliasManager()->getSelf(),
      $command,
      $args,
      Drush::redispatchOptions() + $options
    );
    $process->setTimeout(NULL);
    $process->mustRun($process->showRealtime());
  }

  /**
   * Import a SQL file using Drupal's current database credentials.
   */
  protected function importSqlFile(string $file): void {
    $connect_process = $this->processManager()->drush(
      Drush::aliasManager()->getSelf(),
      'sql:connect',
      [],
      Drush::redispatchOptions() + ['show-passwords' => TRUE]
    );
    $connect_process->mustRun();
    $connect = trim($connect_process->getOutput());
    if ($connect === '') {
      throw new \RuntimeException('Unable to build SQL connection command.');
    }

    $import_process = $this->processManager()->shell($connect . ' < ' . escapeshellarg($file));
    $import_process->setTimeout(NULL);
    $import_process->mustRun($import_process->showRealtime());
  }

}
