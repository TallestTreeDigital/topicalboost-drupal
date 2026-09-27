<?php

namespace Drupal\ttd_topics\Service;

use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected ClientInterface $httpClient,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
  ) {}

  /**
   * Queue the weekly entitlement probe when it is due.
   */
  public function queueProbeIfDue(): bool {
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
   * Probe the TopicalBoost API and queue a full sync when entitled.
   */
  public function probe(): array {
    if ($this->state->get(self::DISABLED_STATE_KEY, FALSE)) {
      $this->state->set(self::ENABLED_STATE_KEY, FALSE);
      return ['enabled' => FALSE, 'queued' => FALSE];
    }
    $status = $this->request('GET', '/analytics/content-inventory/status');
    $enabled = !empty($status['enabled']) && !empty($status['connected']);
    $this->state->set(self::ENABLED_STATE_KEY, $enabled);
    $this->state->set(self::LAST_PROBE_STATE_KEY, $this->time->getRequestTime());

    if (!$enabled) {
      $this->state->delete(self::CONTENT_TYPES_STATE_KEY);
      return ['enabled' => FALSE, 'queued' => FALSE];
    }

    $content_types = $status['analytics']['inventoryPostTypes'] ?? [];
    $content_types = $this->normalizeContentTypes($content_types);
    if (!empty($content_types)) {
      $this->state->set(self::CONTENT_TYPES_STATE_KEY, $content_types);
    }

    return [
      'enabled' => TRUE,
      'queued' => $this->queueFullSync(),
      'content_types' => $this->contentTypes(),
    ];
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
    if (!$entity instanceof NodeInterface || !$this->isEnabled()
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
    // A deletion must follow an update that might already be processing. Only
    // deduplicate it against another deletion for the same node.
    $fragment = '"node_id":' . $node_id;
    if ($deleted) {
      $fragment .= ',"deleted":true';
    }
    return $this->enqueueUnique(self::NODE_JOB_TYPE, $payload, $fragment);
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
      $node_ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->condition('type', $this->contentTypes(), 'IN')
        ->sort('nid', 'ASC')
        ->range(($page - 1) * $per_page, $per_page)
        ->execute();

      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);
      $posts = [];
      foreach ($node_ids as $node_id) {
        $node = $nodes[$node_id] ?? NULL;
        if ($node instanceof NodeInterface && $this->isNodeInScope($node)) {
          $posts[] = $this->buildNodePayload($node);
        }
      }

      $complete = count($node_ids) < $per_page;
      $this->request('POST', '/analytics/content-inventory', [
        'sync_id' => $sync_id,
        'batch' => [
          'type' => 'full',
          'page' => $page,
          'per_page' => $per_page,
          'post_count' => count($posts),
          'complete' => $complete,
        ],
        'posts' => $posts,
      ], 120);

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
        'sent' => count($posts),
        'complete' => $complete,
      ];
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Send the current state of one node, or a deletion if it is out of scope.
   */
  public function syncNode(int $node_id, bool $deleted = FALSE): array {
    if (!$this->isEnabled()) {
      return ['disabled' => TRUE];
    }

    $node = $deleted ? NULL : $this->entityTypeManager->getStorage('node')->load($node_id);
    $payload = $node instanceof NodeInterface && $this->isNodeInScope($node)
      ? $this->buildNodePayload($node)
      : NULL;

    $this->request('POST', '/analytics/content-inventory', [
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
    ], 120);

    return ['node_id' => $node_id, 'deleted' => !$payload];
  }

  /**
   * Build the connector-compatible payload for a Drupal node.
   */
  public function buildNodePayload(NodeInterface $node): array {
    $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
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
      'modified_at' => gmdate('c', $node->getChangedTime()),
      'taxonomies' => $this->taxonomies($node),
      'meta' => $this->metadata($node, $authors),
    ];
  }

  public function isEnabled(): bool {
    return !$this->state->get(self::DISABLED_STATE_KEY, FALSE)
      && (bool) $this->state->get(self::ENABLED_STATE_KEY, FALSE);
  }

  protected function isNodeInScope(NodeInterface $node): bool {
    return $node->isPublished() && in_array($node->bundle(), $this->contentTypes(), TRUE);
  }

  protected function contentTypes(): array {
    $types = $this->state->get(self::CONTENT_TYPES_STATE_KEY, []);
    $types = $this->normalizeContentTypes($types);
    if (empty($types)) {
      $types = $this->normalizeContentTypes($this->configFactory->get('ttd_topics.settings')->get('enabled_content_types') ?: []);
    }
    return $types ?: ['article'];
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

  protected function metadata(NodeInterface $node, array $authors): array {
    $meta = ['_ttd_resolved_authors' => $authors];
    foreach (['field_meta_tags', 'field_metatag', 'field_yoast_seo', 'field_seo'] as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $meta[$field_name] = $node->get($field_name)->getValue();
      }
    }
    return $meta;
  }

  protected function slugify(string $value): string {
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    return trim($value, '-') ?: 'unknown';
  }

  protected function request(string $method, string $path, ?array $body = NULL, int $timeout = 30): array {
    $config = $this->configFactory->get('ttd_topics.settings');
    $api_key = (string) $config->get('topicalboost_api_key');
    if ($api_key === '') {
      throw new \RuntimeException('TopicalBoost API key is not configured.');
    }

    $endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
    $options = [
      'headers' => \ttd_topics_api_headers($api_key),
      'timeout' => $timeout,
    ];
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

  protected function hasActiveJob(string $type, ?string $payload_fragment = NULL): bool {
    if (!$this->database->schema()->tableExists('advancedqueue')) {
      return FALSE;
    }

    $query = $this->database->select('advancedqueue', 'aq')
      ->condition('queue_id', self::QUEUE_ID)
      ->condition('type', $type)
      ->condition('state', ['queued', 'processing'], 'IN');
    if ($payload_fragment !== NULL) {
      $query->condition('payload', '%' . $this->database->escapeLike($payload_fragment) . '%', 'LIKE');
    }
    return (bool) $query->countQuery()->execute()->fetchField();
  }

}
