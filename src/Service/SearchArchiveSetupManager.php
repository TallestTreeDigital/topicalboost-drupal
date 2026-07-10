<?php

namespace Drupal\ttd_topics\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Detects and prepares Search API Views for managed topic filtering.
 */
class SearchArchiveSetupManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs the setup manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->currentUser = $current_user;
  }

  /**
   * Returns Search API page displays that can receive archive topic links.
   */
  public function getCandidates($archive_path = '') {
    if (!$this->isAvailable()) {
      return [];
    }

    $target_path = $this->normalizePath($archive_path);
    $candidates = [];
    $view_storage = $this->entityTypeManager->getStorage('view');
    $index_storage = $this->entityTypeManager->getStorage('search_api_index');

    foreach ($view_storage->loadMultiple() as $view) {
      if (!$view->status()) {
        continue;
      }

      $base_table = (string) $view->get('base_table');
      if (strpos($base_table, 'search_api_index_') !== 0) {
        continue;
      }

      $index_id = substr($base_table, strlen('search_api_index_'));
      $index = $index_storage->load($index_id);
      if (!$index || !$index->status() || !isset($index->getDatasources()['entity:node'])) {
        continue;
      }

      foreach ($view->get('display') ?: [] as $display_id => $display) {
        if (($display['display_plugin'] ?? '') !== 'page') {
          continue;
        }

        $path = $this->normalizePath($display['display_options']['path'] ?? '');
        if ($path === '' || strpos($path, '%') !== FALSE) {
          continue;
        }

        $selection = $view->id() . ':' . $display_id;
        $display_title = $display['display_title'] ?? $display_id;
        $candidates[$selection] = [
          'selection' => $selection,
          'view_id' => $view->id(),
          'view_label' => $view->label(),
          'display_id' => $display_id,
          'display_title' => $display_title,
          'path' => $path,
          'index_id' => $index_id,
          'index_label' => $index->label(),
          'matches_path' => $target_path !== '' && $path === $target_path,
        ];
      }
    }

    uasort($candidates, static function (array $a, array $b) {
      if ($a['matches_path'] !== $b['matches_path']) {
        return $a['matches_path'] ? -1 : 1;
      }
      return strnatcasecmp($a['view_label'] . $a['display_title'], $b['view_label'] . $b['display_title']);
    });

    return $candidates;
  }

  /**
   * Returns readable select options for candidate archive Views.
   */
  public function getCandidateOptions($archive_path = '') {
    $candidates = $this->getCandidates($archive_path);
    $base_labels = [];
    foreach ($candidates as $candidate) {
      $base_label = sprintf('%s (%s)', $candidate['display_title'], $candidate['path']);
      $base_labels[$base_label] = ($base_labels[$base_label] ?? 0) + 1;
    }

    $options = [];
    foreach ($candidates as $selection => $candidate) {
      $base_label = sprintf('%s (%s)', $candidate['display_title'], $candidate['path']);
      $options[$selection] = $base_labels[$base_label] === 1
        ? $base_label
        : sprintf('%s - %s [%s]', $base_label, $candidate['view_label'], $candidate['index_label']);
    }
    return $options;
  }

  /**
   * Suggests a single candidate when the archive path matches exactly.
   */
  public function suggestCandidate($archive_path) {
    $matches = array_filter($this->getCandidates($archive_path), static function (array $candidate) {
      return $candidate['matches_path'];
    });
    return count($matches) === 1 ? (string) array_key_first($matches) : '';
  }

  /**
   * Validates a managed-filter selection without changing configuration.
   */
  public function validateSelection($selection, $archive_path) {
    if (!$this->isAvailable()) {
      throw new \RuntimeException('Search API and Views must be enabled before TopicalBoost can configure archive filtering.');
    }
    if (!$this->currentUser->hasPermission('administer search_api')) {
      throw new \RuntimeException('Your account needs the Administer Search API permission to configure archive filtering.');
    }

    $candidate = $this->getCandidates($archive_path)[$selection] ?? NULL;
    if (!$candidate) {
      throw new \RuntimeException('Choose a valid Search API archive View.');
    }
    if (!$candidate['matches_path']) {
      throw new \RuntimeException(sprintf('The selected View uses %s, which does not match the configured archive path.', $candidate['path']));
    }

    return $candidate;
  }

  /**
   * Adds the topic ID field and optionally queues the selected index.
   */
  public function prepare($selection, $archive_path, $queue_reindex = FALSE) {
    $candidate = $this->validateSelection($selection, $archive_path);
    $index = $this->entityTypeManager->getStorage('search_api_index')->load($candidate['index_id']);
    if (!$index) {
      throw new \RuntimeException('The selected Search API index could not be loaded.');
    }

    $field = NULL;
    foreach ($index->getFields() as $index_field) {
      if ($index_field->getDatasourceId() === 'entity:node'
        && $index_field->getPropertyPath() === 'field_ttd_topics'
        && $index_field->getType() === 'integer') {
        $field = $index_field;
        break;
      }
    }

    $field_added = FALSE;
    if (!$field) {
      $fields_helper = \Drupal::service('search_api.fields_helper');
      $properties = $index->getPropertyDefinitions('entity:node');
      $property = $fields_helper->retrieveNestedProperty($properties, 'field_ttd_topics');
      if (!$property) {
        throw new \RuntimeException('The selected index cannot see field_ttd_topics. Enable TopicalBoost for an indexed content type first.');
      }

      $field_id = 'ttd_topic_ids';
      if ($index->getField($field_id)) {
        $field_id = $fields_helper->getNewFieldId($index, $field_id);
      }
      $field = $fields_helper->createFieldFromProperty(
        $index,
        $property,
        'entity:node',
        'field_ttd_topics',
        $field_id,
        'integer'
      );
      $field->setLabel('TopicalBoost topic IDs');
      $index->addField($field);
      $index->save();
      $field_added = TRUE;
    }

    $reindex_queued = $field_added || $queue_reindex;
    if ($reindex_queued) {
      $index->reindex();
    }

    return $candidate + [
      'field_id' => $field->getFieldIdentifier(),
      'field_added' => $field_added,
      'reindex_queued' => $reindex_queued,
    ];
  }

  /**
   * Checks whether the optional integration dependencies are available.
   */
  protected function isAvailable() {
    return $this->moduleHandler->moduleExists('search_api')
      && $this->moduleHandler->moduleExists('views')
      && $this->entityTypeManager->getDefinition('search_api_index', FALSE)
      && $this->entityTypeManager->getDefinition('view', FALSE);
  }

  /**
   * Normalizes an internal or absolute archive URL to a path.
   */
  protected function normalizePath($path) {
    $path = trim((string) $path);
    if ($path === '') {
      return '';
    }
    $parsed_path = parse_url($path, PHP_URL_PATH);
    if (!is_string($parsed_path) || $parsed_path === '') {
      return '';
    }
    return '/' . trim($parsed_path, '/');
  }

}
