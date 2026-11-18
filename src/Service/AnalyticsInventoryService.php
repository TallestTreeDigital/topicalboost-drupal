<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use GuzzleHttp\ClientInterface;

/**
 * Builds and sends the Drupal content inventory used by TTd Analytics.
 */
class AnalyticsInventoryService {

  public const QUEUE_ID = 'ttd_topics_analysis';
  public const PROBE_JOB_TYPE = 'ttd_analytics_inventory_probe';
  public const BATCH_JOB_TYPE = 'ttd_analytics_inventory_batch';
  public const NODE_JOB_TYPE = 'ttd_analytics_inventory_node';
  public const ENABLED_STATE_KEY = 'topicalboost.analytics_inventory.enabled';
  public const DISABLED_STATE_KEY = 'topicalboost.analytics_inventory.disabled';
  public const CONTENT_TYPES_STATE_KEY = 'topicalboost.analytics_inventory.content_types';
  public const LAST_PROBE_STATE_KEY = 'topicalboost.analytics_inventory.last_probe';
  public const LAST_FULL_SYNC_STATE_KEY = 'topicalboost.analytics_inventory.last_full_sync';
  public const PROBE_INTERVAL = 604800;
  public const BATCH_SIZE = 50;
  public const AUTHOR_REPLAY_QUEUE = 'ttd_analytics_author_replay';
  private const AUTHOR_REPLAY_STATE = 'topicalboost.analytics_author_replay.v1';
  private const AUTHOR_REPLAY_SWITCH = 'topicalboost.analytics_author_replay.switch';
  private const AUTHOR_REPLAY_LOCK = 'ttd_topics.analytics_author_replay';
  private const AUTHOR_REPLAY_PREFIX = 'topicalboost-drupal-authors-v1-';
  private const AUTHOR_REPLAY_BATCH_SIZE = 100;
  protected SavedDescriptionSync $savedDescriptions;
  protected KeyValueStoreInterface $replayState;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected ClientInterface $httpClient,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    KeyValueFactoryInterface $keyValue,
  ) {
    $this->savedDescriptions = new SavedDescriptionSync($this, $state, $database, $entityTypeManager, $lock);
    // Read the current checkpoint under the lock without clearing Drupal's state cache.
    $this->replayState = $keyValue->get('state');
  }

  /**
   * Queue the weekly entitlement probe when it is due.
   */
  public function queueProbeIfDue(): bool {
    // This runs on ordinary Drupal cron, including between weekly probes.
    $this->savedDescriptions->ensureQueued();
    $this->reportSavedDescriptionStatus();
    if ($this->state->get(self::DISABLED_STATE_KEY, FALSE)) {
      return FALSE;
    }
    $now = $this->time->getRequestTime();
    $last_probe = (int) $this->state->get(self::LAST_PROBE_STATE_KEY, 0);
    if ($last_probe > 0 && ($now - $last_probe) < self::PROBE_INTERVAL) {
      return FALSE;
    }

    return $this->enqueueUnique(self::PROBE_JOB_TYPE, ['trigger' => 'cron']);
  }

  /**
   * Refresh Analytics entitlement without scanning the archive.
   */
  public function probe(): array {
    if ($this->state->get(self::DISABLED_STATE_KEY, FALSE)) {
      $this->state->set(self::ENABLED_STATE_KEY, FALSE);
      return ['enabled' => FALSE, 'queued' => FALSE];
    }
    $context = $this->savedDescriptionRequestContext();
    $status = $this->request('GET', '/analytics/content-inventory/status', NULL, 30, $context);
    $enabled = !empty($status['enabled']) && !empty($status['connected']);
    $this->state->set(self::ENABLED_STATE_KEY, $enabled);
    $this->state->set(self::LAST_PROBE_STATE_KEY, $this->time->getRequestTime());

    if (!$enabled) {
      $this->state->delete(self::CONTENT_TYPES_STATE_KEY);
      $this->recordAuthorReplaySwitch(FALSE, $context);
      return ['enabled' => FALSE, 'queued' => FALSE];
    }

    $content_types = $status['analytics']['inventoryPostTypes'] ?? NULL;
    if (is_array($content_types)) {
      $this->state->set(self::CONTENT_TYPES_STATE_KEY, $this->normalizeContentTypes($content_types));
    }
    else {
      $this->state->delete(self::CONTENT_TYPES_STATE_KEY);
    }
    $this->recordAuthorReplaySwitch(($status['analytics']['authorReplay'] ?? NULL) === TRUE, $context);

    return [
      'enabled' => TRUE,
      // Analytics imports the canonical inventory from TopicalBoost. The CMS
      // relay sends edits and saved descriptions without a weekly archive scan.
      'queued' => FALSE,
      'content_types' => $this->contentTypes(),
    ];
  }

  /** Cron restores a lost wakeup. The queue item carries no progress. */
  public function queueAuthorReplayIfDue(): void {
    if (!$this->isEnabled() || !$this->lock->acquire(self::AUTHOR_REPLAY_LOCK, 30.0)) {
      return;
    }
    try {
      $context = $this->savedDescriptionRequestContext();
      $types = $this->contentTypes($this->replayState);
      if (empty($context['headers']['x-api-key']) || !$types) return;

      $scope = $this->authorReplayScope($context, $types);
      $replay = $this->replayState->get(self::AUTHOR_REPLAY_STATE, []);
      if (($replay['scope'] ?? NULL) === $scope && $replay['complete']) return;

      $switch = $this->authorReplaySwitch($context);
      $now = $this->time->getCurrentTime();
      if (empty($switch['allowed']) && ($switch['checked_at'] ?? 0) <= $now - 86400) {
        // This extra check must not change the weekly entitlement or content scope.
        $this->recordAuthorReplaySwitch(FALSE, $context);
        $status = $this->request('GET', '/analytics/content-inventory/status', NULL, 10, $context);
        $this->recordAuthorReplaySwitch(!empty($status['enabled']) && !empty($status['connected'])
          && ($status['analytics']['authorReplay'] ?? NULL) === TRUE, $context);
        $switch = $this->authorReplaySwitch($context);
      }
      if (empty($switch['allowed'])) return;

      if (($replay['scope'] ?? NULL) !== $scope) {
        $replay = [
          'scope' => $scope,
          'sync_id' => self::AUTHOR_REPLAY_PREFIX . gmdate('YmdHis', $now) . '-' . bin2hex(random_bytes(8)),
          'cursor' => 0,
          'page' => 1,
          'next_at' => 0,
          'complete' => FALSE,
        ];
        $this->replayState->set(self::AUTHOR_REPLAY_STATE, $replay);
      }
      if ($replay['next_at'] <= $now) {
        $queue = \Drupal::queue(self::AUTHOR_REPLAY_QUEUE);
        if (!$queue->numberOfItems()) $queue->createItem(NULL);
      }
    }
    finally {
      $this->lock->release(self::AUTHOR_REPLAY_LOCK);
    }
  }

  /** Send one page and return the delay before the queue worker runs again. */
  public function replayAuthors(): int {
    if (!$this->lock->acquire(self::AUTHOR_REPLAY_LOCK, 300.0)) return 60;
    try {
      $context = $this->savedDescriptionRequestContext();
      $types = $this->contentTypes($this->replayState);
      $scope = $this->authorReplayScope($context, $types);
      $replay = $this->replayState->get(self::AUTHOR_REPLAY_STATE, []);
      if (!$this->isEnabled() || !$types || empty($this->authorReplaySwitch($context)['allowed'])
        || ($replay['scope'] ?? NULL) !== $scope || $replay['complete']) return 0;

      $remaining = $replay['next_at'] - $this->time->getCurrentTime();
      if ($remaining > 0) return $remaining;

      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)->condition('status', 1)->condition('type', $types, 'IN')
        ->condition('nid', $replay['cursor'], '>')->sort('nid', 'ASC')
        ->range(0, self::AUTHOR_REPLAY_BATCH_SIZE)->execute();
      $complete = count($ids) < self::AUTHOR_REPLAY_BATCH_SIZE;
      $delivery = $this->sendInventoryPage($ids, $replay['sync_id'], $replay['page'],
        self::AUTHOR_REPLAY_BATCH_SIZE, $complete, $context, $types);
      $response = $delivery['response'];

      // A response to the old connection or scope cannot advance the new one.
      $this->configFactory->reset('ttd_topics.settings');
      if ($this->authorReplayScope($this->savedDescriptionRequestContext(), $this->contentTypes($this->replayState)) !== $scope) return 0;

      $now = $this->time->getCurrentTime();
      if (($response['deferred'] ?? NULL) === TRUE) {
        if (($response['authorReplay'] ?? NULL) === FALSE) return 0;
        $delay = max(1, (int) ($response['retryAfterSeconds'] ?? 90));
        $replay['next_at'] = $now + $delay;
        $this->replayState->set(self::AUTHOR_REPLAY_STATE, $replay);
        return $delay;
      }

      $replay['cursor'] = $ids ? (int) end($ids) : $replay['cursor'];
      $replay['page']++;
      $replay['complete'] = $complete;
      $replay['next_at'] = $now + 60;
      $this->replayState->set(self::AUTHOR_REPLAY_STATE, $replay);
      return $complete || empty($this->authorReplaySwitch($context)['allowed']) ? 0 : 60;
    }
    finally {
      $this->lock->release(self::AUTHOR_REPLAY_LOCK);
    }
  }

  private function authorReplayScope(array $context, array $types): string {
    sort($types, SORT_STRING);
    return hash('sha256', json_encode([$context['scope'], $types], JSON_THROW_ON_ERROR));
  }

  private function authorReplaySwitch(array $context): array {
    $switch = $this->replayState->get(self::AUTHOR_REPLAY_SWITCH, []);
    return ($switch['connection'] ?? NULL) === $context['scope'] ? $switch : [];
  }

  private function recordAuthorReplaySwitch(bool $allowed, array $context): void {
    $this->replayState->set(self::AUTHOR_REPLAY_SWITCH, [
      'connection' => $context['scope'],
      'allowed' => $allowed,
      'checked_at' => $this->time->getCurrentTime(),
    ]);
  }

  private function noteAuthorReplayAnswer(array $response, array $context): void {
    if (is_bool($response['authorReplay'] ?? NULL)) {
      $this->recordAuthorReplaySwitch($response['authorReplay'], $context);
    }
  }

  /**
   * Queue the first page of a full content inventory sync.
   */
  public function queueFullSync(): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }

    if (!$this->lock->acquire('ttd_topics.analytics_inventory.schedule', 30.0)) {
      return FALSE;
    }

    try {
      if ($this->hasActiveJob(self::BATCH_JOB_TYPE)) {
        return FALSE;
      }

      $sync_id = 'topicalboost-drupal-' . gmdate('YmdHis', $this->time->getRequestTime()) . '-' . substr(hash('sha256', uniqid('', TRUE)), 0, 8);
      return $this->enqueue(self::BATCH_JOB_TYPE, [
        'sync_id' => $sync_id,
        'page' => 1,
        'per_page' => self::BATCH_SIZE,
      ]);
    }
    finally {
      $this->lock->release('ttd_topics.analytics_inventory.schedule');
    }
  }

  /**
   * Queue one incremental update or deletion for a node.
   */
  public function queueNode(EntityInterface $entity, bool $deleted = FALSE): bool {
    if (!$entity instanceof NodeInterface || (!$this->isEnabled() && !$this->savedDescriptions->wasStarted())
      || !in_array($entity->bundle(), $this->contentTypes(), TRUE)) {
      return FALSE;
    }

    $node_id = (int) $entity->id();
    if ($node_id <= 0) {
      return FALSE;
    }

    $payload = [
      'node_id' => $node_id,
      'deleted' => $deleted,
    ];
    // Hold the pending row until the surrounding entity-save transaction
    // commits. Otherwise a worker could claim it and read the previous value
    // while this save deduplicates against work that is already becoming stale.
    $transaction = $this->database->startTransaction();
    $this->savedDescriptions->markDirty($node_id);
    $fragment = '"node_id":' . $node_id . ',"deleted":' . ($deleted ? 'true' : 'false');
    $pending = $this->database->select('advancedqueue', 'aq')->fields('aq', ['job_id'])
      ->condition('queue_id', self::QUEUE_ID)->condition('type', self::NODE_JOB_TYPE)
      ->condition('state', 'queued')
      ->condition('payload', '%' . $this->database->escapeLike($fragment) . '%', 'LIKE')
      ->range(0, 1)->execute()->fetchField();
    if ($pending) {
      // Lock the identified job by primary key. Locking the payload scan can
      // also block unrelated node jobs scanned before the match. Recheck its
      // current state in case a worker claimed it after the lookup.
      $pending = $this->database->select('advancedqueue', 'aq')->fields('aq', ['job_id'])
        ->condition('job_id', $pending)->condition('state', 'queued')
        ->forUpdate()->execute()->fetchField();
    }
    if ($pending) {
      // A fresh edit should not wait behind an earlier six-hour retry delay.
      $this->database->update('advancedqueue')->fields(['available' => $this->time->getCurrentTime()])
        ->condition('job_id', $pending)->condition('available', $this->time->getCurrentTime(), '>')->execute();
      return FALSE;
    }
    return $this->enqueue(self::NODE_JOB_TYPE, $payload);
  }

  /**
   * Send one full-sync page and queue the next page when needed.
   */
  public function syncBatch(string $sync_id, int $page, int $per_page): array {
    if (!$this->isEnabled()) {
      return ['disabled' => TRUE];
    }

    $page = max(1, $page);
    $per_page = min(100, max(1, $per_page));
    $lock_name = 'ttd_topics.analytics_inventory.batch.' . hash('sha256', $sync_id . '|' . $page . '|' . $per_page);
    if (!$this->lock->acquire($lock_name, 900.0)) {
      return ['locked' => TRUE];
    }

    try {
      $context = $this->savedDescriptionRequestContext();
      $types = $this->contentTypes();
      $node_ids = $types ? $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('type', $types, 'IN')
        ->sort('nid', 'ASC')
        ->range(($page - 1) * $per_page, $per_page)
        ->execute() : [];

      $complete = count($node_ids) < $per_page;
      $delivery = $this->sendInventoryPage($node_ids, $sync_id, $page, $per_page, $complete, $context);

      if (!$complete) {
        $this->enqueue(self::BATCH_JOB_TYPE, [
          'sync_id' => $sync_id,
          'page' => $page + 1,
          'per_page' => $per_page,
        ], 5);
      }
      else {
        $this->state->set(self::LAST_FULL_SYNC_STATE_KEY, [
          'sync_id' => $sync_id,
          'completed_at' => gmdate('c', $this->time->getRequestTime()),
        ]);
      }

      return [
        'page' => $page,
        'sent' => $delivery['sent'],
        'complete' => $complete,
      ];
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  private function sendInventoryPage(array $ids, string $sync_id, int $page, int $per_page, bool $complete, array $context, ?array $replay_types = NULL): array {
    $revision = $this->savedDescriptions->observationRevision();
    $tokens = $this->savedDescriptions->dirtyTokens($ids);
    $storage = $this->entityTypeManager->getStorage('node');
    if ($ids) $storage->resetCache($ids);
    $nodes = $storage->loadMultiple($ids);
    $types = $replay_types ?? $this->contentTypes();
    $posts = [];
    foreach ($ids as $id) {
      $node = $nodes[$id] ?? NULL;
      if ($node instanceof NodeInterface && $node->isPublished() && in_array($node->bundle(), $types, TRUE)) {
        $posts[] = $this->buildNodePayload($node, $revision);
      }
    }

    $sent = count($posts);
    $response = $this->request('POST', '/analytics/content-inventory', [
      'sync_id' => $sync_id,
      'batch' => ['type' => 'full', 'page' => $page, 'per_page' => $per_page, 'post_count' => $sent, 'complete' => $complete],
      'posts' => $posts,
    ], 120, $context);
    if ($replay_types === NULL || ($response['ok'] ?? NULL) !== TRUE || ($response['deferred'] ?? NULL) !== TRUE) {
      $this->confirmedWrite($response, $sent);
      $this->savedDescriptions->confirmPosts($posts, $context['scope'], $tokens);
    }
    $this->noteAuthorReplayAnswer($response, $context);
    return ['response' => $response, 'sent' => $sent];
  }

  /**
   * Send the current state of one node, or a deletion if it is out of scope.
   */
  public function syncNode(int $node_id, bool $deleted = FALSE): array {
    if (!$this->isEnabled()) {
      throw new \RuntimeException('The Analytics connection is unavailable.');
    }

    $context = $this->savedDescriptionRequestContext();
    $tokens = $this->savedDescriptions->dirtyTokens([$node_id]);
    $revision = $this->savedDescriptions->observationRevision();
    $this->entityTypeManager->getStorage('node')->resetCache([$node_id]);
    // Even a queued deletion is only a wakeup. A later CMS save is authoritative.
    $node = $this->entityTypeManager->getStorage('node')->load($node_id);
    $payload = $node instanceof NodeInterface && $this->isNodeInScope($node)
      ? $this->buildNodePayload($node, $revision)
      : NULL;

    $response = $this->request('POST', '/analytics/content-inventory', [
      'sync_id' => 'topicalboost-drupal-node-' . $node_id . '-' . $this->time->getRequestTime(),
      'batch' => [
        'type' => 'incremental',
        'page' => 1,
        'per_page' => 1,
        'post_count' => $payload ? 1 : 0,
        'complete' => TRUE,
      ],
      'posts' => $payload ? [$payload] : [],
      'deleted_post_ids' => $payload ? [] : [(string) $node_id],
    ], 120, $context);
    $this->confirmedWrite($response, $payload ? 1 : 0);
    $this->noteAuthorReplayAnswer($response, $context);
    if ($payload) $this->savedDescriptions->confirmPosts([$payload], $context['scope'], $tokens);
    else $this->savedDescriptions->confirmDeletion($node_id, $revision, $context['scope'], $tokens[(string) $node_id]);
    $this->reportSavedDescriptionStatus();

    return ['node_id' => $node_id, 'deleted' => !$payload];
  }

  /**
   * Build the connector-compatible payload for a Drupal node.
   */
  public function buildNodePayload(NodeInterface $node, ?string $revision = NULL): array {
    $url = \ttd_topics_get_node_absolute_url($node);
    $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
    $path = '/' . ltrim($path, '/');
    $authors = $this->authors($node);
    $primary_author = $authors[0] ?? $this->unbylinedAuthor();

    return [
      'post_id' => (int) $node->id(),
      'post_type' => $node->bundle(),
      'status' => $node->isPublished() ? 'publish' : 'draft',
      'title' => $node->label(),
      'slug' => trim((string) basename(rtrim($path, '/')), '/') ?: (string) $node->id(),
      'url' => $url,
      'canonical_url' => $url,
      'page_path' => $path === '/' ? '/' : rtrim($path, '/'),
      'author' => $primary_author,
      'authors' => $authors,
      'published_at' => gmdate('c', $node->getCreatedTime()),
      'published_date' => \ttd_topics_site_date($node->getCreatedTime()),
      'modified_at' => gmdate('c', $node->getChangedTime()),
      'modified_date' => \ttd_topics_site_date($node->getChangedTime()),
      'taxonomies' => $this->taxonomies($node),
      'meta' => $this->metadata($node, $authors, $revision),
    ];
  }

  public function isEnabled(): bool {
    return !$this->state->get(self::DISABLED_STATE_KEY, FALSE)
      && (bool) $this->state->get(self::ENABLED_STATE_KEY, FALSE);
  }

  public function inventoryContentTypes(): array {
    return $this->contentTypes();
  }

  public function queueDescriptionBatch(int $delay): void {
    if (!$this->hasActiveJob(SavedDescriptionSync::JOB_TYPE, NULL, TRUE)) {
      $this->enqueue(SavedDescriptionSync::JOB_TYPE, ['trigger' => 'saved-description-catchup'], $delay);
    }
  }

  /** Cron owns a separate budget, independent of the shared AdvancedQueue. */
  public function runDescriptionCron(): array {
    $this->savedDescriptions->ensureQueued();
    $result = $this->savedDescriptions->run();
    $this->reportSavedDescriptionStatus();
    return $result;
  }

  public function descriptionUpdatesPending(): bool {
    return (bool) $this->database->select('advancedqueue', 'q')->fields('q', ['job_id'])
      ->condition('queue_id', self::QUEUE_ID)->condition('type', self::NODE_JOB_TYPE)
      ->condition('state', ['queued', 'processing'], 'IN')->range(0, 1)->execute()->fetchField();
  }

  public function discardDescriptionBatches(): void {
    $this->database->delete('advancedqueue')->condition('queue_id', self::QUEUE_ID)
      ->condition('type', SavedDescriptionSync::JOB_TYPE)->condition('state', 'queued')->execute();
  }

  public function syncDescriptionCatchup(): array {
    return $this->savedDescriptions->run();
  }

  public function syncSavedDescriptionNodes(array $ids, string $sync_id, ?array &$stats = NULL, int $timeout = 120): int {
    $context = $this->savedDescriptionRequestContext();
    $tokens = $this->savedDescriptions->dirtyTokens($ids);
    $revision = $this->savedDescriptions->observationRevision();
    $storage = $this->entityTypeManager->getStorage('node');
    $storage->resetCache($ids);
    $nodes = $storage->loadMultiple($ids);
    $posts = [];
    foreach ($ids as $id) {
      $node = $nodes[$id] ?? NULL;
      if ($node instanceof NodeInterface && $this->isNodeInScope($node)) {
        $description = SavedMetatag::description($node);
        if (!$description['available']) {
          throw new \RuntimeException('A saved Metatag override could not be read.');
        }
        if ($description['value'] !== NULL) $posts[] = $this->buildNodePayload($node, $revision);
      }
    }
    $all_count = count($posts);
    $posts = $this->savedDescriptions->unconfirmedPosts($posts, $context['scope'], $tokens);
    $stats = ['sent' => count($posts), 'skipped' => $all_count - count($posts)];
    if (!$posts) {
      return 0;
    }
    $response = $this->request('POST', '/analytics/content-inventory', [
      'sync_id' => $sync_id,
      'batch' => ['type' => 'incremental', 'page' => 1, 'per_page' => count($posts), 'post_count' => count($posts), 'complete' => TRUE],
      'posts' => $posts,
    ], $timeout, $context);
    $written = $this->confirmedWrite($response, count($posts));
    $this->noteAuthorReplayAnswer($response, $context);
    $this->savedDescriptions->confirmPosts($posts, $context['scope'], $tokens);
    return $written;
  }

  /** One-way destination binding; no credential value leaves CMS storage. */
  public function savedDescriptionScope(): string {
    return $this->savedDescriptionRequestContext()['scope'];
  }

  private function savedDescriptionRequestContext(): array {
    $endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
    $key = (string) $this->configFactory->get('ttd_topics.settings')->get('topicalboost_api_key');
    $headers = \ttd_topics_api_headers($key);
    return ['endpoint' => $endpoint, 'headers' => $headers, 'scope' => hash('sha256', json_encode([
      rtrim($endpoint, '/'), hash('sha256', $key), (string) ($headers['x-tb-site-url'] ?? ''),
      (string) $this->configFactory->get('system.site')->get('uuid'),
    ], JSON_THROW_ON_ERROR))];
  }

  public function getSavedDescriptionStatus(): array {
    return $this->savedDescriptions->getStatus();
  }

  /** Background-only, optional telemetry; never a delivery acknowledgement. */
  public function reportSavedDescriptionStatus(): bool {
    $name = 'ttd_topics.description.telemetry';
    if (!$this->lock->acquire($name, 15.0)) return FALSE;
    try {
      $key = 'topicalboost.saved_description_status_attempt';
      $last = $this->database->select('key_value', 'kv')->fields('kv', ['value'])
        ->condition('collection', 'state')->condition('name', $key)->execute()->fetchField();
      if (is_resource($last)) $last = stream_get_contents($last);
      $last = is_string($last) ? @unserialize($last, ['allowed_classes' => FALSE]) : 0;
      if ((int) $last > time() - 60) return FALSE;
      $this->state->set($key, time());
      $context = $this->savedDescriptionRequestContext();
      $revision = $this->savedDescriptions->observationRevision();
      $snapshot = $this->savedDescriptions->getStatus($context['scope']);
      $snapshot['revision'] = $revision;
      $this->request('POST', '/telemetry/description-sync', $snapshot, 10, $context);
      return TRUE;
    }
    catch (\Throwable $error) {
      // Status transport must not fail a save, replay a batch or reset progress.
      return FALSE;
    }
    finally { $this->lock->release($name); }
  }

  protected function confirmedWrite(array $response, int $sent): int {
    $summary = $response['summary'] ?? [];
    $accepted = $summary['rowsAccepted'] ?? NULL;
    $written = $summary['rowsWritten'] ?? NULL;
    if (($response['ok'] ?? FALSE) !== TRUE || !empty($response['dryRun'])
      || ($response['siteMatches'] ?? FALSE) !== TRUE
      || !is_int($accepted) || $accepted !== $sent
      || !is_int($written) || $written !== $accepted) {
      throw new \RuntimeException('The Analytics inventory write was not confirmed.');
    }
    return $written;
  }

  protected function isNodeInScope(NodeInterface $node): bool {
    return $node->isPublished() && in_array($node->bundle(), $this->contentTypes(), TRUE);
  }

  protected function contentTypes(?KeyValueStoreInterface $store = NULL): array {
    $enabled = $this->normalizeContentTypes($this->configFactory->get('ttd_topics.settings')->get('enabled_content_types') ?: []);
    $analytics = $this->normalizeContentTypes(($store ?? $this->state)->get(self::CONTENT_TYPES_STATE_KEY, []));
    return $analytics ? array_values(array_intersect($enabled, $analytics)) : $enabled;
  }

  protected function normalizeContentTypes($types): array {
    $types = is_array($types) ? $types : [$types];
    return array_values(array_unique(array_filter(array_map(static function ($type) {
      $type = trim((string) $type);
      return preg_match('/^[a-z0-9_]+$/', $type) ? $type : '';
    }, $types))));
  }

  protected function authors(NodeInterface $node): array {
    // Fordham stores the public byline on referenced staff nodes and external
    // author paragraphs. The Drupal node owner is an editor, not the byline.
    $byline_fields = ['field_article_related_staff', 'field_pub_related_staff', 'field_external_author_s_'];
    $has_byline_field = FALSE;
    $byline_authors = [];
    foreach ($byline_fields as $field_name) {
      if (!$node->hasField($field_name)) {
        continue;
      }
      $has_byline_field = TRUE;
      try {
        $entities = $node->get($field_name)->referencedEntities();
      }
      catch (\Throwable $e) {
        continue;
      }
      foreach ($entities as $entity) {
        $name = $field_name === 'field_external_author_s_' && $entity->hasField('field_external_author_name')
          ? trim((string) $entity->get('field_external_author_name')->value)
          : trim((string) $entity->label());
        if ($name === '') {
          continue;
        }
        $byline_authors[] = [
          'id' => (int) $entity->id(),
          'name' => $name,
          'slug' => $this->slugify($name),
          'type' => $entity->getEntityTypeId(),
          'source' => 'drupal_field:' . $field_name,
        ];
      }
    }
    if ($has_byline_field) {
      return $byline_authors ?: [$this->unbylinedAuthor()];
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    $field_name = $config->get('author_manager_enabled') ? ($config->get('author_field_name') ?: 'uid') : 'uid';
    $entities = [];

    if ($field_name !== 'uid' && $node->hasField($field_name)) {
      try {
        $entities = $node->get($field_name)->referencedEntities();
      }
      catch (\Throwable $e) {
        $entities = [];
      }
    }

    if (empty($entities) && method_exists($node, 'getOwner') && $node->getOwner()) {
      $entities = [$node->getOwner()];
    }

    $authors = [];
    foreach ($entities as $entity) {
      $name = trim((string) $entity->label());
      if ($name === '') {
        continue;
      }
      $authors[] = [
        'id' => (int) $entity->id(),
        'name' => $name,
        'slug' => $this->slugify(method_exists($entity, 'getAccountName') ? $entity->getAccountName() : $name),
        'type' => $entity->getEntityTypeId(),
        'source' => $field_name === 'uid' ? 'drupal_user' : 'drupal_field:' . $field_name,
      ];
    }

    return $authors ?: [$this->unbylinedAuthor()];
  }

  protected function unbylinedAuthor(): array {
    return [
      'id' => 0,
      'name' => 'Unbylined',
      'slug' => 'unbylined',
      'type' => 'unbylined',
      'source' => 'none',
    ];
  }

  protected function taxonomies(NodeInterface $node): array {
    $taxonomies = [];
    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() !== 'entity_reference' || $definition->getSetting('target_type') !== 'taxonomy_term' || !$node->hasField($field_name)) {
        continue;
      }

      try {
        $terms = $node->get($field_name)->referencedEntities();
      }
      catch (\Throwable $e) {
        continue;
      }

      foreach ($terms as $term) {
        if (!$term instanceof TermInterface) {
          continue;
        }
        $key = $this->analyticsTaxonomyKey($term->bundle());
        $taxonomies[$key][] = [
          'id' => (int) $term->id(),
          'name' => $term->label(),
          'slug' => $this->slugify($term->label()),
        ];
      }
    }
    return $taxonomies;
  }

  protected function analyticsTaxonomyKey(string $vocabulary): string {
    if ($vocabulary === 'ttd_topics') {
      return 'ttd_topic';
    }
    if (str_contains($vocabulary, 'categor')) {
      return 'category';
    }
    if (str_contains($vocabulary, 'tag')) {
      return 'post_tag';
    }
    return $vocabulary;
  }

  protected function metadata(NodeInterface $node, array $authors, ?string $revision = NULL): array {
    $meta = ['_ttd_resolved_authors' => $authors];
    foreach (['field_meta_tags', 'field_metatag', 'field_yoast_seo', 'field_seo'] as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $meta[$field_name] = $node->get($field_name)->getValue();
      }
    }
    $field = SavedMetatag::fieldName($node);
    if ($field !== NULL) {
      $meta[$field] = $node->get($field)->getValue();
    }
    $description = SavedMetatag::description($node);
    if ($description['available']) {
      $meta['_ttd_saved_description'] = $description['value'];
      if ($revision !== NULL) {
        $meta['_ttd_saved_description_revision'] = $revision;
      }
    }
    return $meta;
  }

  protected function slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    return trim($value, '-') ?: 'unknown';
  }

  protected function request(string $method, string $path, ?array $body = NULL, int $timeout = 30, ?array $context = NULL): array {
    $context = $context ?? $this->savedDescriptionRequestContext();
    if (empty($context['headers']['x-api-key'])) {
      throw new \RuntimeException('TopicalBoost API key is not configured.');
    }
    $endpoint = $context['endpoint'];
    $options = ['headers' => $context['headers'], 'timeout' => $timeout];
    if ($body !== NULL) {
      $options['json'] = $body;
    }

    $response = $this->httpClient->request($method, rtrim($endpoint, '/') . $path, $options);
    return json_decode((string) $response->getBody(), TRUE) ?: [];
  }

  protected function enqueueUnique(string $type, array $payload, ?string $payload_fragment = NULL): bool {
    if ($this->hasActiveJob($type, $payload_fragment)) {
      return FALSE;
    }
    return $this->enqueue($type, $payload);
  }

  protected function enqueue(string $type, array $payload, int $delay = 0): bool {
    $queue = $this->entityTypeManager->getStorage('advancedqueue_queue')->load(self::QUEUE_ID);
    if (!$queue) {
      throw new \RuntimeException('TopicalBoost analysis queue is not available.');
    }
    $queue->enqueueJob(Job::create($type, $payload), max(0, $delay));
    return TRUE;
  }

  protected function hasActiveJob(string $type, ?string $payload_fragment = NULL, bool $queued_only = FALSE): bool {
    if (!$this->database->schema()->tableExists('advancedqueue')) {
      return FALSE;
    }

    $query = $this->database->select('advancedqueue', 'aq')
      ->condition('queue_id', self::QUEUE_ID)
      ->condition('type', $type)
      ->condition('state', $queued_only ? ['queued'] : ['queued', 'processing'], 'IN');
    if ($payload_fragment !== NULL) {
      $query->condition('payload', '%' . $this->database->escapeLike($payload_fragment) . '%', 'LIKE');
    }
    return (bool) $query->countQuery()->execute()->fetchField();
  }

}
