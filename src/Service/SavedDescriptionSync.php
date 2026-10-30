<?php

namespace Drupal\ttd_topics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;

/** A finite, resumable catch-up using the existing Drupal inventory relay. */
final class SavedDescriptionSync {

  public const JOB_TYPE = 'ttd_saved_description_catchup';
  public const STATE_KEY = 'topicalboost.saved_description_catchup.v1';
  private const REVISION_KEY = 'topicalboost.saved_description_observation';
  private const BATCH_SIZE = 10;
  private const LEASE_SECONDS = 600;
  private const ACK_COLLECTION = 'ttd_saved_description_confirmed';
  private const DIRTY_COLLECTION = 'ttd_saved_description_dirty';
  private const SUCCESS_KEY = 'topicalboost.saved_description_last_success';
  private const MAX_SKIP_PAGES = 10;

  public function __construct(
    private AnalyticsInventoryService $gateway,
    private StateInterface $state,
    private Connection $database,
    private EntityTypeManagerInterface $entities,
    private LockBackendInterface $lock,
  ) {}

  public function wasStarted(): bool {
    return is_array($this->readState(self::STATE_KEY));
  }

  public function ensureQueued(): void {
    $name = 'ttd_topics.description.schedule';
    if (!$this->lock->acquire($name, 30.0)) {
      return;
    }
    try {
      $checkpoint = $this->readState(self::STATE_KEY);
      $scope = $this->gateway->savedDescriptionScope();
      if (is_array($checkpoint) && !isset($checkpoint['destination'])) {
        // Adopt old checkpoints once; a completed old run is not replayed.
        $adopted = $checkpoint; $adopted['destination'] = $scope;
        if (!$this->replaceState($checkpoint, $adopted)) return;
        $checkpoint = $adopted;
      }
      if (is_array($checkpoint) && $checkpoint['destination'] !== $scope) {
        $replacement = $this->newCheckpoint($scope);
        if (!$this->replaceState($checkpoint, $replacement)) return;
        $checkpoint = $replacement;
      }
      if ($checkpoint === NULL && $this->gateway->isEnabled()) {
        $checkpoint = $this->newCheckpoint($scope);
        $this->state->set(self::STATE_KEY, $checkpoint);
      }
      if (!is_array($checkpoint) || $checkpoint['status'] === 'complete'
        || ($checkpoint['lease_expires'] ?? 0) > time()) {
        return;
      }
      $this->gateway->queueDescriptionBatch(max(60, $checkpoint['next_at'] - time()));
    }
    finally {
      $this->lock->release($name);
    }
  }

  private function newCheckpoint(string $scope): array {
    return [
      'format' => 2, 'destination' => $scope, 'status' => 'pending', 'cursor' => 0, 'upper_id' => NULL,
      'cursor_created' => NULL, 'legacy_floor_id' => 0,
      'processed' => 0, 'written' => 0, 'skipped' => 0, 'attempts' => 0,
      'started_at' => time(), 'updated_at' => time(), 'next_at' => time() + random_int(300, 600),
    ];
  }

  /** Allocate before fresh node reads; metadata-only saves need their own order. */
  public function observationRevision(): string {
    $name = 'ttd_topics.description.observation';
    if (!$this->lock->acquire($name, 30.0)) {
      throw new \RuntimeException('The description observation is busy.');
    }
    try {
      $last = $this->readState(self::REVISION_KEY);
      if ($last !== NULL && (!is_string($last) || !preg_match('/^[1-9][0-9]{0,15}$/', $last))) {
        throw new \RuntimeException('The description observation is unreadable.');
      }
      $revision = (string) max((int) floor(microtime(TRUE) * 1000000), (int) $last + 1);
      $this->state->set(self::REVISION_KEY, $revision);
      return $revision;
    }
    finally {
      $this->lock->release($name);
    }
  }

  public function run(): array {
    $name = 'ttd_topics.description.worker';
    if (!$this->lock->acquire($name, self::LEASE_SECONDS)) {
      return ['status' => 'locked'];
    }
    $owned = NULL;
    try {
      $checkpoint = $this->readState(self::STATE_KEY);
      if (!is_array($checkpoint) || $checkpoint['status'] === 'complete') {
        return ['status' => 'complete'];
      }
      if (($checkpoint['format'] ?? 1) < 2) {
        $upgraded = $checkpoint + ['started_at' => time(), 'skipped' => 0];
        $upgraded['format'] = 2;
        $upgraded['legacy_floor_id'] = (int) ($checkpoint['cursor'] ?? 0);
        $upgraded['cursor_created'] = NULL;
        $upgraded['cursor'] = 0;
        if (!$this->replaceState($checkpoint, $upgraded)) return ['status' => 'replaced'];
        $checkpoint = $upgraded;
      }
      if ($checkpoint['next_at'] > time()) {
        return ['status' => 'pending'];
      }
      $owned = $checkpoint;
      $owned['updated_at'] = time();
      $owned['lease_token'] = bin2hex(random_bytes(16));
      $owned['lease_expires'] = time() + self::LEASE_SECONDS;
      if (!$this->replaceState($checkpoint, $owned)) {
        return ['status' => 'replaced'];
      }
      if (!$this->gateway->isEnabled()) {
        throw new \RuntimeException('The Analytics connection is unavailable.');
      }
      if ($owned['upper_id'] === NULL) {
        $next = $owned;
        $next['upper_id'] = (int) $this->database->query('SELECT MAX(nid) FROM {node}')->fetchField();
        if (!$this->replaceState($owned, $next)) {
          return ['status' => 'replaced'];
        }
        $owned = $next;
      }
      // Skip at most 100 already-confirmed candidates per turn. Both candidate
      // reads and acknowledgement reads are bounded; no warehouse lookups.
      $next = $owned;
      $finished = FALSE;
      for ($page = 0; $page < self::MAX_SKIP_PAGES; $page++) {
        $candidates = $this->recentPage($next);
        $ids = array_map('intval', array_column($candidates, 'nid'));
        if (!$ids) { $finished = TRUE; break; }
        $stats = [];
        $written = $this->gateway->syncSavedDescriptionNodes($ids,
          'topicalboost-drupal-descriptions-' . $owned['upper_id'] . '-' . ($next['cursor_created'] ?? 'start') . '-' . $next['cursor'], $stats);
        $last = end($candidates);
        $next['cursor'] = (int) $last['nid'];
        $next['cursor_created'] = (int) $last['created'];
        $next['processed'] += count($ids);
        $next['written'] += $written;
        $next['skipped'] = ($next['skipped'] ?? 0) + ($stats['skipped'] ?? 0);
        if (count($ids) < self::BATCH_SIZE) { $finished = TRUE; break; }
        // A turn delivers at most ten items, with at least a minute afterward.
        if (($stats['sent'] ?? 0) > 0) break;
      }
      $next['updated_at'] = time();
      $next['attempts'] = 0;
      $next['status'] = $finished ? 'complete' : 'pending';
      $next['next_at'] = time() + 60;
      if ($next['status'] === 'complete') {
        $next['completed_at'] = time();
      }
      unset($next['lease_token'], $next['lease_expires']);
      return $this->replaceState($owned, $next) ? $next : ['status' => 'replaced'];
    }
    catch (\Throwable $error) {
      if ($owned !== NULL) {
        $next = $owned;
        $next['updated_at'] = time();
        $next['attempts'] = min(1000000, $next['attempts'] + 1);
        $delays = [60, 300, 900, 3600, 21600];
        $next['next_at'] = time() + $delays[min($next['attempts'] - 1, count($delays) - 1)];
        $next['status'] = 'retrying';
        unset($next['lease_token'], $next['lease_expires']);
        if ($this->replaceState($owned, $next)) {
          return ['status' => 'retrying'];
        }
      }
      throw new \RuntimeException('Description catch-up will retry without advancing progress.');
    }
    finally {
      $this->lock->release($name);
      $this->ensureQueued();
      $this->gateway->reportSavedDescriptionStatus();
    }
  }

  /** Indexed date/nid keysets per content type avoid a multi-type archive sort. */
  private function recentPage(array $checkpoint): array {
    if (!$this->database->schema()->indexExists('node_field_data', 'ttd_description_recent')) {
      throw new \RuntimeException('The description index requires the normal Drupal module update.');
    }
    $candidates = [];
    foreach ($this->gateway->inventoryContentTypes() as $type) {
      $query = $this->database->select('node_field_data', 'n')->fields('n', ['nid', 'created'])
        ->condition('type', $type)->condition('status', 1)->condition('default_langcode', 1)
        ->condition('nid', (int) ($checkpoint['legacy_floor_id'] ?? 0), '>')
        ->condition('nid', (int) $checkpoint['upper_id'], '<=');
      if ($checkpoint['cursor_created'] !== NULL) {
        $earlier = $query->orConditionGroup()->condition('created', (int) $checkpoint['cursor_created'], '<')
          ->condition($query->andConditionGroup()->condition('created', (int) $checkpoint['cursor_created'])
            ->condition('nid', (int) $checkpoint['cursor'], '<'));
        $query->condition($earlier);
      }
      foreach ($query->orderBy('created', 'DESC')->orderBy('nid', 'DESC')->range(0, self::BATCH_SIZE)->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $candidates[] = $row;
      }
    }
    usort($candidates, static fn($a, $b) => ((int) $b['created'] <=> (int) $a['created']) ?: ((int) $b['nid'] <=> (int) $a['nid']));
    return array_slice($candidates, 0, self::BATCH_SIZE);
  }

  /** Called inside the node-save transaction, including same-value saves. */
  public function markDirty(int $id): void {
    $this->database->merge('key_value')->keys(['collection' => self::DIRTY_COLLECTION, 'name' => (string) $id])
      ->fields(['value' => serialize(bin2hex(random_bytes(16)))])->execute();
  }

  /** Capture before fresh node reads; absent tokens represent pre-repair nodes. */
  public function dirtyTokens(array $ids): array {
    $ids = array_map('strval', $ids);
    if (!$ids) return [];
    $rows = $this->database->select('key_value', 'kv')->fields('kv', ['name', 'value'])
      ->condition('collection', self::DIRTY_COLLECTION)->condition('name', $ids, 'IN')->execute()->fetchAllKeyed();
    $tokens = [];
    foreach ($ids as $id) {
      $raw = $rows[$id] ?? NULL;
      if (is_resource($raw)) $raw = stream_get_contents($raw);
      $value = is_string($raw) ? @unserialize($raw, ['allowed_classes' => FALSE]) : NULL;
      if (array_key_exists($id, $rows) && (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/', $value))) {
        throw new \RuntimeException('The description change token is unreadable.');
      }
      $tokens[$id] = is_string($value) ? $value : '0';
    }
    return $tokens;
  }

  /** Exact confirmed clears are covered; malformed or unsupported reads are not. */
  private function descriptionHash(array $post, string $scope): ?string {
    $meta = $post['meta'] ?? [];
    if (!array_key_exists('_ttd_saved_description', $meta)) return NULL;
    $value = $meta['_ttd_saved_description'];
    if ($value !== NULL && !is_string($value)) return NULL;
    return hash('sha256', $scope . ':' . json_encode($value, JSON_THROW_ON_ERROR));
  }

  /** One indexed local read for an entire bounded candidate page. */
  public function unconfirmedPosts(array $posts, ?string $scope = NULL, ?array $tokens = NULL): array {
    if (!$posts) return [];
    $scope = $scope ?? $this->gateway->savedDescriptionScope();
    $ids = array_map(static fn($post) => (string) (int) $post['post_id'], $posts);
    $tokens = $tokens ?? $this->dirtyTokens($ids);
    $current_tokens = $this->dirtyTokens($ids);
    $rows = $this->database->select('key_value', 'kv')->fields('kv', ['name', 'value'])
      ->condition('collection', self::ACK_COLLECTION)->condition('name', $ids, 'IN')->execute()->fetchAllKeyed();
    $pending = [];
    foreach ($posts as $post) {
      $hash = $this->descriptionHash($post, $scope);
      $raw = $rows[(string) (int) $post['post_id']] ?? NULL;
      if (is_resource($raw)) $raw = stream_get_contents($raw);
      $mark = is_string($raw) ? @unserialize($raw, ['allowed_classes' => FALSE]) : NULL;
      $id = (string) (int) $post['post_id'];
      if ($scope === $this->gateway->savedDescriptionScope() && $hash !== NULL && is_array($mark)
        && ($mark['hash'] ?? NULL) === $hash && ($mark['dirty'] ?? NULL) === ($tokens[$id] ?? NULL)
        && ($tokens[$id] ?? NULL) === ($current_tokens[$id] ?? NULL)) continue;
      $pending[] = $post;
    }
    return $pending;
  }

  /** Called only after the relay confirms its real warehouse write. */
  public function confirmPosts(array $posts, ?string $scope = NULL, ?array $tokens = NULL): void {
    $scope = $scope ?? $this->gateway->savedDescriptionScope();
    if ($scope !== $this->gateway->savedDescriptionScope()) return;
    $ids = array_map(static fn($post) => (string) (int) $post['post_id'], $posts);
    $tokens = $tokens ?? $this->dirtyTokens($ids);
    $current_tokens = $this->dirtyTokens($ids);
    $latest = NULL;
    foreach ($posts as $post) {
      $hash = $this->descriptionHash($post, $scope);
      $revision = $post['meta']['_ttd_saved_description_revision'] ?? NULL;
      if ($hash === NULL || !is_string($revision) || !preg_match('/^[1-9][0-9]{0,15}$/', $revision)) continue;
      $id = (string) (int) $post['post_id'];
      if (!isset($tokens[$id]) || $tokens[$id] !== ($current_tokens[$id] ?? NULL)) continue;
      $record = ['hash' => $hash, 'revision' => $revision, 'at' => time(), 'destination' => $scope, 'dirty' => $tokens[$id]];
      $this->writeNewerValue(self::ACK_COLLECTION, (string) (int) $post['post_id'], $record);
      $latest = $record;
    }
    if ($latest) $this->writeNewerValue('state', self::SUCCESS_KEY, $latest);
  }

  public function confirmDeletion(int $id, string $revision, string $scope, string $token): void {
    if ($scope !== $this->gateway->savedDescriptionScope() || ($this->dirtyTokens([$id])[(string) $id] ?? NULL) !== $token) return;
    $this->writeNewerValue(self::ACK_COLLECTION, (string) $id, ['hash' => NULL, 'revision' => $revision, 'at' => time(), 'destination' => $scope, 'dirty' => $token]);
  }

  /** A late acknowledgement must not replace a newer local confirmation. */
  private function writeNewerValue(string $collection, string $name, array $record): void {
    $lock = 'ttd_topics.description.confirm.' . hash('sha256', $collection . ':' . $name);
    if (!$this->lock->acquire($lock, 30.0)) throw new \RuntimeException('Description confirmation is busy.');
    try {
      $last = $this->readValue($collection, $name);
      if (is_array($last) && (int) ($last['revision'] ?? 0) > (int) $record['revision']) return;
      $this->database->merge('key_value')->keys(['collection' => $collection, 'name' => $name])
        ->fields(['value' => serialize($record)])->execute();
      if ($collection === 'state') $this->state->resetCache();
    }
    finally { $this->lock->release($lock); }
  }

  /** Cheap current checkpoint only. No archive counts or warehouse reads. */
  public function getStatus(?string $scope = NULL): array {
    $scope = $scope ?? $this->gateway->savedDescriptionScope();
    $checkpoint = $this->readState(self::STATE_KEY) ?: [];
    $success = $this->readState(self::SUCCESS_KEY) ?: [];
    if (($checkpoint['destination'] ?? $scope) !== $scope) $checkpoint = [];
    if (($success['destination'] ?? NULL) !== $scope) $success = [];
    $status = $checkpoint['status'] ?? 'not_started';
    if (($checkpoint['lease_expires'] ?? 0) > time()) $status = 'running';
    $iso = static fn($time) => $time ? gmdate('c', (int) $time) : NULL;
    return [
      'version' => 1, 'revision' => (string) ($this->readState(self::REVISION_KEY) ?: '0'),
      'status' => $status, 'processed' => (int) ($checkpoint['processed'] ?? 0),
      'written' => (int) ($checkpoint['written'] ?? 0), 'skipped' => (int) ($checkpoint['skipped'] ?? 0),
      'startedAt' => $iso($checkpoint['started_at'] ?? NULL),
      'updatedAt' => $iso(max((int) ($checkpoint['updated_at'] ?? 0), (int) ($success['at'] ?? 0))),
      'lastSuccessAt' => $iso($success['at'] ?? NULL),
      'nextAttemptAt' => $iso(in_array($status, ['pending', 'retrying', 'waiting'], TRUE) ? ($checkpoint['next_at'] ?? NULL) : NULL),
      'completedAt' => $iso($checkpoint['completed_at'] ?? NULL),
    ];
  }

  private function readState(string $key) { return $this->readValue('state', $key); }

  /** Bypass the process-local State cache when coordinating queue workers. */
  private function readValue(string $collection, string $key) {
    $raw = $this->database->select('key_value', 'kv')->fields('kv', ['value'])
      ->condition('collection', $collection)->condition('name', $key)->execute()->fetchField();
    if ($raw === FALSE) {
      return NULL;
    }
    if (is_resource($raw)) {
      $raw = stream_get_contents($raw);
    }
    $value = @unserialize($raw, ['allowed_classes' => FALSE]);
    if ($value === FALSE && $raw !== serialize(FALSE)) {
      throw new \RuntimeException('The description checkpoint is unreadable.');
    }
    return $value;
  }

  private function replaceState(array $expected, array $next): bool {
    $changed = $this->database->update('key_value')->fields(['value' => serialize($next)])
      ->condition('collection', 'state')->condition('name', self::STATE_KEY)
      ->condition('value', serialize($expected))->execute();
    $this->state->resetCache();
    return $changed === 1;
  }

}
