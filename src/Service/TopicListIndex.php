<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/** Maintains a rebuildable, indexed representation of the admin topic list. */
class TopicListIndex {
  const TABLE = 'ttd_topic_list_index';
  const TAG = 'ttd_topic_list_index';
  const STATE = 'ttd_topic_list_index_build';
  const JOB = 'ttd_topic_list_index_backfill';
  const BATCH_SIZE = 2000;
  private ?bool $available = NULL;

  public function __construct(private Connection $database, private StateInterface $state, private CacheBackendInterface $cache) {}

  public static function schema(): array {
    return [
      'description' => 'Rebuildable topic list index. Taxonomy fields and taxonomy_index remain authoritative.',
      'fields' => [
        'term_id' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
        'name' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE, 'default' => ''],
        'is_hidden' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 0],
        'post_count' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE, 'default' => 0],
        'count_rank' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
        'active' => ['type' => 'int', 'size' => 'tiny', 'not null' => TRUE, 'default' => 1],
      ],
      'primary key' => ['term_id'],
      // term_id already makes each tuple unique. Use full-name keys: Drupal's
      // MySQL driver truncates ordinary varchar indexes to 191 characters,
      // which prevents them from satisfying ORDER BY name for long names.
      'unique keys' => [
        'hidden_name' => ['active', 'is_hidden', 'name', 'term_id', 'post_count'],
        'name_order' => ['active', 'name', 'term_id', 'is_hidden', 'post_count'],
        'count_order' => ['active', 'post_count', 'name', 'term_id', 'is_hidden'],
        'count_reverse' => ['active', 'count_rank', 'name', 'term_id', 'post_count', 'is_hidden'],
      ],
      'indexes' => [
        'hidden_count' => ['active', 'is_hidden', 'post_count'],
      ],
    ];
  }

  public function ensure(): bool {
    if ($this->available !== NULL) {
      return $this->available;
    }
    $schema = $this->database->schema();
    if (!$schema->tableExists(self::TABLE)) {
      $schema->createTable(self::TABLE, self::schema());
    }
    if ($this->state->get(self::STATE) === NULL) {
      $this->state->set(self::STATE, ['cursor' => 0, 'ready' => FALSE]);
      $this->schedule();
    }
    return $this->available = TRUE;
  }

  public function ready(): bool {
    return $this->ensure() && !empty($this->state->get(self::STATE, [])['ready']);
  }

  public function schedule(bool $next = FALSE): void {
    $queue = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load(TtdSyncService::QUEUE_ID);
    if (!$queue) {
      return;
    }
    if (!$next && $this->database->select('advancedqueue', 'aq')->condition('queue_id', TtdSyncService::QUEUE_ID)->condition('type', self::JOB)->condition('state', ['queued', 'processing'], 'IN')->countQuery()->execute()->fetchField()) {
      return;
    }
    $queue->enqueueJob(Job::create(self::JOB, ['cursor' => (int) ($this->state->get(self::STATE, [])['cursor'] ?? 0)]));
  }

  private function sourceQuery(): \Drupal\Core\Database\Query\SelectInterface {
    $query = $this->database->select('taxonomy_term_field_data', 'td');
    $query->addField('td', 'tid', 'term_id');
    $query->addField('td', 'name');
    $query->condition('td.vid', 'ttd_topics');
    $query->condition('td.default_langcode', 1);
    $query->leftJoin('taxonomy_index', 'ti', 'td.tid = ti.tid');
    $query->leftJoin('taxonomy_term__field_hide', 'tfh', 'td.tid = tfh.entity_id AND td.langcode = tfh.langcode AND tfh.deleted = 0 AND tfh.delta = 0');
    $query->addExpression('COALESCE(tfh.field_hide_value, 0)', 'is_hidden');
    $query->addExpression('COUNT(ti.nid)', 'post_count');
    $query->addExpression('-COUNT(ti.nid)', 'count_rank');
    $query->addExpression('td.status', 'active');
    foreach (['td.tid', 'td.name', 'td.status', 'tfh.field_hide_value'] as $field) {
      $query->groupBy($field);
    }
    return $query;
  }

  public function backfill(): bool {
    $this->ensure();
    $state = $this->state->get(self::STATE);
    if (!empty($state['ready'])) {
      return FALSE;
    }
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::STATE, 300)) {
      return FALSE;
    }
    try {
      $ids = $this->database->select('taxonomy_term_field_data', 'td')->fields('td', ['tid'])->condition('vid', 'ttd_topics')->condition('default_langcode', 1)->condition('tid', (int) $state['cursor'], '>')->orderBy('tid')->range(0, self::BATCH_SIZE)->execute()->fetchCol();
      if (!$ids) {
        $state['ready'] = TRUE;
      } else {
        $upper = max(array_map('intval', $ids));
        $query = $this->sourceQuery()->condition('td.tid', (int) $state['cursor'], '>')->condition('td.tid', $upper, '<=');
        // Ignore an existing row: change hooks may have written newer data or a deletion tombstone.
        $driver = $this->database->driver();
        if ($driver === 'mysql') {
          $sql = 'INSERT IGNORE INTO {' . self::TABLE . '} (term_id,name,is_hidden,post_count,count_rank,active) ' . $query;
        } else {
          $sql = 'INSERT INTO {' . self::TABLE . '} (term_id,name,is_hidden,post_count,count_rank,active) ' . $query . ' ON CONFLICT (term_id) DO NOTHING';
        }
        $this->database->query($sql, $query->arguments());
        $state['cursor'] = $upper;
      }
      $this->state->set(self::STATE, $state);
      Cache::invalidateTags([self::TAG]);
      return TRUE;
    } finally {
      $lock->release(self::STATE);
    }
  }

  public function syncTerm(int $term_id): void {
    // Ordinary content writes must not create schema or start an initial backfill.
    if ($this->state->get(self::STATE) === NULL) {
      return;
    }
    $row = $this->sourceQuery()->condition('td.tid', $term_id)->execute()->fetchAssoc();
    if (!$row) {
      $row = ['term_id' => $term_id, 'name' => '', 'is_hidden' => 0, 'post_count' => 0, 'count_rank' => 0, 'active' => 0];
    }
    $this->database->merge(self::TABLE)->key('term_id', $term_id)->fields($row)->execute();
    Cache::invalidateTags([self::TAG]);
  }

  public function entityChanged(EntityInterface $entity): void {
    if ($entity instanceof TermInterface && $entity->bundle() === 'ttd_topics') {
      $this->syncTerm((int) $entity->id());
    } elseif ($entity instanceof NodeInterface) {
      $ids = [];
      foreach ([$entity, $entity->original ?? NULL] as $node) {
        if ($node && $node->hasField('field_ttd_topics')) {
          foreach ($node->get('field_ttd_topics')->getValue() as $item) {
            $ids[(int) $item['target_id']] = (int) $item['target_id'];
          }
        }
      }
      foreach ($ids as $id) {
        $this->syncTerm($id);
      }
    }
  }

  private function filtered(array $filters): \Drupal\Core\Database\Query\SelectInterface {
    $query = $this->database->select(self::TABLE, 'idx')->condition('active', 1);
    if ($filters['search'] !== '') {
      $query->condition('name', '%' . $this->database->escapeLike($filters['search']) . '%', 'LIKE');
    }
    if ($filters['min_posts'] !== '') {
      $query->condition('post_count', (int) $filters['min_posts'], '>=');
    }
    if ($filters['visibility'] === 'hidden') {
      $query->condition('is_hidden', 1);
    } elseif (in_array($filters['visibility'], ['visible', 'below'], TRUE)) {
      $query->condition('is_hidden', 0);
      $query->condition('post_count', $filters['threshold'], $filters['visibility'] === 'visible' ? '>=' : '<');
    }
    return $query;
  }

  public function stats(array $filters): array {
    $cid = self::TAG . ':' . hash('sha256', serialize($filters));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $query = $this->filtered($filters)->fields('idx', ['is_hidden'])->groupBy('is_hidden');
    $query->addExpression('COUNT(*)', 'terms');
    $query->addExpression('COALESCE(SUM(post_count), 0)', 'posts');
    $stats = ['counts' => [0 => 0, 1 => 0], 'terms' => 0, 'posts' => 0];
    foreach ($query->execute() as $row) {
      $stats['counts'][(int) $row->is_hidden] = (int) $row->terms;
      $stats['terms'] += (int) $row->terms;
      $stats['posts'] += (int) $row->posts;
    }
    $this->cache->set($cid, $stats, time() + 300, [self::TAG]);
    return $stats;
  }

  public function page(array $filters, string $sort, string $order, int $offset, int $limit, array $stats): array {
    $rows = [];
    if ($sort === 'hidden') {
      $first = $order === 'desc' ? 1 : 0;
      foreach ([$first, 1 - $first] as $hidden) {
        if ($offset >= $stats['counts'][$hidden]) {
          $offset -= $stats['counts'][$hidden];
          continue;
        }
        $query = $this->filtered($filters)->fields('idx', ['term_id', 'name', 'is_hidden', 'post_count'])->condition('is_hidden', $hidden)->orderBy('name')->orderBy('term_id')->range($offset, $limit);
        $part = $query->execute()->fetchAll();
        $rows = array_merge($rows, $part);
        $limit -= count($part);
        $offset = 0;
        if ($limit <= 0) {
          break;
        }
      }
    } else {
      $query = $this->filtered($filters)->fields('idx', ['term_id', 'name', 'is_hidden', 'post_count']);
      if ($sort === 'name') {
        $query->orderBy('name', strtoupper($order))->orderBy('term_id', strtoupper($order));
      } else {
        $query->orderBy($order === 'desc' ? 'count_rank' : 'post_count')->orderBy('name')->orderBy('term_id');
      }
      $rows = $query->range($offset, $limit)->execute()->fetchAll();
    }
    foreach ($rows as $row) {
      $row->tid = $row->term_id;
    }
    return $rows;
  }
}
