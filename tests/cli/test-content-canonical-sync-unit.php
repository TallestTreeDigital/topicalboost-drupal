<?php

/**
 * Standalone URL sync regression with recording Drupal and HTTP adapters.
 * Run: php tests/cli/test-content-canonical-sync-unit.php
 */

namespace Drupal\Core\Entity { interface EntityInterface {} }
namespace Drupal\node { interface NodeInterface extends \Drupal\Core\Entity\EntityInterface {} }
namespace Drupal\path_alias { interface PathAliasInterface extends \Drupal\Core\Entity\EntityInterface {} }
namespace Drupal\advancedqueue\Plugin\AdvancedQueue\JobType { class JobTypeBase {} }
namespace Drupal\advancedqueue {
  class Job {
    public function __construct(public string $type, public array $payload, public string $state = 'queued') {}
    public static function create($type, $payload): self { return new self($type, $payload); }
    public function getPayload(): array { return $this->payload; }
  }
  class JobResult {
    public static function success($message): bool { return TRUE; }
    public static function failure($message): bool { return FALSE; }
  }
}
namespace GuzzleHttp {
  class Client {
    public static array $calls = [];
    public static array $results = [];
    public function patch($url, $options) {
      self::$calls[] = ['url' => $url, 'options' => $options];
      $result = array_shift(self::$results) ?? ['urlChanged' => TRUE, 'metadataChanged' => FALSE];
      if ($result instanceof \Throwable) { throw $result; }
      return new class($result) {
        public function __construct(private $result) {}
        public function getBody(): string { return json_encode($this->result); }
      };
    }
  }
}
namespace Drush\Commands {
  class DrushCommands {
    public array $lines = [];
    public function output(): self { return $this; }
    public function logger(): self { return $this; }
    public function writeln($line): void { $this->lines[] = $line; }
    public function warning($line): void {}
  }
}
namespace {
  class Field {
    public function __construct(public $value) {}
    public function isEmpty(): bool { return $this->value === NULL; }
  }
  class Node implements \Drupal\node\NodeInterface {
    public $original = NULL;
    public function __construct(public int $nid, public bool $published = TRUE, public bool $analyzed = TRUE, public string $type = 'article', public string $path = '/old/story') {}
    public function id(): int { return $this->nid; }
    public function bundle(): string { return $this->type; }
    public function isPublished(): bool { return $this->published; }
    public function hasField($name): bool { return $name === 'field_ttd_last_analyzed'; }
    public function get($name): Field { return new Field($this->analyzed ? 100 : NULL); }
    public function getCreatedTime(): int { return 1788220800; }
    public function getChangedTime(): int { return 1788307200; }
    public function label(): string { return 'Example article'; }
    public function toUrl(): object {
      return new class($this->path) {
        private bool $absolute = FALSE;
        public function __construct(private string $path) {}
        public function setAbsolute(): self { $this->absolute = TRUE; return $this; }
        public function toString(): string { return ($this->absolute ? 'http://default' : '') . $this->path; }
      };
    }
  }
  class Alias implements \Drupal\path_alias\PathAliasInterface {
    public $original = NULL;
    public function __construct(private string $path) {}
    public function getPath(): string { return $this->path; }
  }
  class Config {
    public array $values = ['enabled_content_types' => ['article'], 'site_base_url' => 'https://example.org', 'topicalboost_api_key' => 'test-only'];
    public function get($key) { return $this->values[$key] ?? NULL; }
  }
  class Lock {
    public array $held = [];
    public function acquire($name, $ttl): bool {
      if (isset($this->held[$name])) { return FALSE; }
      $this->held[$name] = TRUE;
      return TRUE;
    }
    public function release($name): void { unset($this->held[$name]); }
  }
  class Query {
    public array $conditions = [];
    public array $window = [0, 1000];
    public function __construct(private bool $jobs = FALSE) {}
    public function condition($key, $value, $operator = '='): self { $this->conditions[$key] = $value; return $this; }
    public function exists($key): self { $this->conditions[$key] = TRUE; return $this; }
    public function accessCheck($value): self { return $this; }
    public function sort($key, $order): self { return $this; }
    public function range($offset, $limit): self { $this->window = [$offset, $limit]; return $this; }
    public function countQuery(): self { return $this; }
    public function execute() {
      if ($this->jobs) { return $this; }
      $nodes = array_filter(Drupal::$nodes, fn($node) => $node->published && $node->analyzed && in_array($node->type, $this->conditions['type'], TRUE));
      ksort($nodes);
      return array_slice(array_keys($nodes), ...$this->window);
    }
    public function fetchField(): int {
      return count(array_filter(Drupal::$jobs, fn($job) => $job->type === $this->conditions['type']
        && $job->state === $this->conditions['state'] && json_encode($job->payload) === $this->conditions['payload']));
    }
  }
  class Storage {
    public function __construct(private string $type) {}
    public function load($id) {
      if ($this->type === 'node') { return Drupal::$nodes[$id] ?? NULL; }
      return new class {
        public function enqueueJob($job): void { Drupal::$jobs[] = $job; }
      };
    }
    public function resetCache($ids): void {}
    public function getQuery(): Query { return Drupal::$lastQuery = new Query(); }
  }
  class Drupal {
    public static array $confirmed = [];
    public static function keyValue($collection): object {
      return new class {
        public function get($key) { return Drupal::$confirmed[$key] ?? NULL; }
        public function set($key, $value): void { Drupal::$confirmed[$key] = $value; }
        public function delete($key): void { unset(Drupal::$confirmed[$key]); }
      };
    }
    public static Config $config;
    public static Lock $lock;
    public static array $nodes = [];
    public static array $jobs = [];
    public static array $services = [];
    public static ?Query $lastQuery = NULL;
    public static function config($name): Config { return self::$config; }
    public static function lock(): Lock { return self::$lock; }
    public static function service($name) {
      if ($name === 'extension.list.module') {
        return new class { public function getExtensionInfo($name): array { return ['version' => 'test']; } };
      }
      return self::$services[$name];
    }
    public static function database(): object {
      return new class { public function select($table, $alias): Query { return new Query(TRUE); } };
    }
    public static function entityTypeManager(): object {
      return new class { public function getStorage($type): Storage { return new Storage($type); } };
    }
    public static function logger($name): object { return new class { public function warning($message, $context = []): void {} }; }
  }
  function drupal_static_reset($name): void {}
  require getenv('TTD_CANONICAL_MODULE_PATH') ?: dirname(__DIR__, 2) . '/ttd_topics.module';
  require dirname(__DIR__, 2) . '/src/Service/TtdTopicsAnalysisService.php';
  require dirname(__DIR__, 2) . '/src/Service/AnalyticsInventoryService.php';
  require dirname(__DIR__, 2) . '/src/Plugin/AdvancedQueue/JobType/TtdContentUrlSync.php';
  require dirname(__DIR__, 2) . '/src/Commands/TopicalBoostMaintenanceCommands.php';

  $assertions = 0;
  $assert = static function ($condition, $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) { throw new \RuntimeException('FAIL: ' . $message); }
    echo 'PASS: ' . $message . "\n";
  };
  Drupal::$config = new Config();
  Drupal::$lock = new Lock();
  $service = new \Drupal\ttd_topics\Service\TtdTopicsAnalysisService();
  Drupal::$services['ttd_topics.analysis_service'] = $service;
  Drupal::$services['ttd_topics.topic_list_index'] = new class {
    public function entityChanged($entity): void {}
  };
  Drupal::$services['path_alias.manager'] = new class {
    public array $cleared = [];
    public function cacheClear($path): void { $this->cleared[] = $path; }
  };
  Drupal::$services['ttd_topics.analytics_inventory'] = new class {
    public array $ids = [];
    public function queueNode($node, $deleted = FALSE): void { $this->ids[] = $node->id(); }
  };
  $node = new Node(42, FALSE);
  Drupal::$nodes[42] = $node;
  ttd_topics_entity_insert($node);
  ttd_topics_entity_update($node);
  $assert(!Drupal::$jobs && !\GuzzleHttp\Client::$calls, 'Scheduled or draft content does not sync');
  $node->original = clone $node;
  $node->published = TRUE;
  ttd_topics_entity_update($node);
  $assert(count(Drupal::$jobs) === 1 && !\GuzzleHttp\Client::$calls, 'Publication queues a deferred metadata update');
  $node->path = '/new-category/current-slug';
  ttd_topics_entity_update($node);
  $assert(count(Drupal::$jobs) === 1, 'Published slug and category edits reuse the pending update');
  $job = new \Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdContentUrlSync();
  $assert($job->process(Drupal::$jobs[0]), 'Deferred update succeeds');
  $body = \GuzzleHttp\Client::$calls[0]['options']['json'];
  $assert($body['customer_id'] === 42 && $body['url'] === 'https://example.org/new-category/current-slug', 'Worker sends the latest alias using stable node ID');
  $assert($body['status'] === 'publish' && $body['publishedAt'] === '2026-09-01T00:00:00Z', 'Publication metadata matches the shared API contract');
  $assert(\GuzzleHttp\Client::$calls[0]['options']['headers']['x-tb-platform'] === 'drupal', 'Request retains Drupal platform identity');
  $assert(in_array('/node/42', Drupal::$services['path_alias.manager']->cleared, TRUE), 'A reused worker clears stale alias lookup caches');
  $inventory = new class extends \Drupal\ttd_topics\Service\AnalyticsInventoryService {
    public function __construct() {}
    protected function authors(\Drupal\node\NodeInterface $node): array { return [['name' => 'Author']]; }
    protected function taxonomies(\Drupal\node\NodeInterface $node): array { return []; }
    protected function metadata(\Drupal\node\NodeInterface $node, array $authors, ?string $revision = NULL): array { return []; }
  };
  $payload = $inventory->buildNodePayload($node);
  $assert($payload['url'] === $body['url'] && $payload['canonical_url'] === $body['url'], 'Analytics inventory uses the same public URL without the CLI default host');
  Drupal::$jobs[0]->state = 'processing';
  $node->path = '/later-change';
  ttd_topics_entity_update($node);
  $assert(count(Drupal::$jobs) === 2, 'A save during processing queues a follow-up update');
  $node->analyzed = FALSE;
  $assert(!$service->queueContentUrlUpdate($node), 'Unanalyzed content does not queue missing API rows');
  $node->analyzed = TRUE;
  $node->type = 'page';
  $assert(!$service->queueContentUrlUpdate($node), 'Disabled content types do not queue updates');
  $node->type = 'article';

  Drupal::$jobs = [];
  Drupal::$confirmed = [];
  ttd_topics_entity_insert($node);
  $assert(count(Drupal::$jobs) === 1, 'Published analyzed inserts also queue a metadata update');
  Drupal::$jobs = [];
  $alias = new Alias('/node/42');
  ttd_topics_entity_insert($alias);
  ttd_topics_entity_update($alias);
  ttd_topics_entity_delete($alias);
  $assert(count(Drupal::$jobs) === 1, 'Direct alias insert, update, and delete all queue the same node');
  Drupal::$jobs = [];
  Drupal::$nodes[43] = new Node(43);
  $alias->original = new Alias('/node/43');
  ttd_topics_entity_update($alias);
  $assert(array_column(array_map(fn($entry) => $entry->payload, Drupal::$jobs), 'node_id') === [42, 43], 'Moving an alias refreshes both affected nodes');
  Drupal::$jobs = [];
  ttd_topics_entity_update(new Alias('/taxonomy/term/42'));
  $assert(!Drupal::$jobs, 'Unrelated entity aliases do not queue content updates');
  Drupal::$jobs[] = \Drupal\advancedqueue\Job::create('ttd_content_url_sync', ['node_id' => 420]);
  $assert($service->queueContentUrlUpdate($node), 'Deduplication does not confuse node 42 with node 420');

  \GuzzleHttp\Client::$results = [new \RuntimeException('test API failure')];
  $assert(!$job->process(\Drupal\advancedqueue\Job::create('ttd_content_url_sync', ['node_id' => 42])), 'API failure returns a retryable job failure');
  $assert($job->process(\Drupal\advancedqueue\Job::create('ttd_content_url_sync', ['node_id' => 999])), 'A deleted node is skipped successfully');
  Drupal::$lock->held['ttd_topics.content_url.sync.42'] = TRUE;
  $assert(!$job->process(\Drupal\advancedqueue\Job::create('ttd_content_url_sync', ['node_id' => 42])), 'Concurrent updates for one node retry instead of racing');
  Drupal::$lock->held = [];

  $command = new \Drupal\ttd_topics\Commands\TopicalBoostMaintenanceCommands();
  $node->path = '/new-category/current-slug';
  \GuzzleHttp\Client::$calls = [];
  $command->syncContentUrls(['node-ids' => '42,42,999', 'dry-run' => TRUE]);
  $assert(!\GuzzleHttp\Client::$calls && in_array('42 https://example.org/new-category/current-slug', $command->lines, TRUE), 'Dry run prints unique node IDs and URLs without an API write');
  $assert(in_array('skipped: 1', $command->lines, TRUE) && in_array('repaired: 0', $command->lines, TRUE), 'Dry run reports skips without claiming repairs');
  Drupal::$nodes[44] = new Node(44);
  Drupal::$nodes[45] = new Node(45);
  Drupal::$nodes[46] = new Node(46);
  Drupal::$nodes[47] = new Node(47, TRUE, TRUE, 'article', '/?p=47');
  \GuzzleHttp\Client::$results = [
    ['urlChanged' => TRUE], ['metadataChanged' => TRUE], ['urlChanged' => FALSE, 'metadataChanged' => FALSE], new \RuntimeException('test API failure'),
  ];
  $command->lines = [];
  $failed = FALSE;
  try { $command->syncContentUrls(['node-ids' => '42,43,44,45,47,999']); }
  catch (\RuntimeException $e) { $failed = TRUE; }
  $assert($failed && in_array('repaired: 1', $command->lines, TRUE) && in_array('metadata_updated: 1', $command->lines, TRUE)
    && in_array('unchanged: 1', $command->lines, TRUE) && in_array('skipped: 2', $command->lines, TRUE) && in_array('failed: 1', $command->lines, TRUE), 'Archive repair reports all five outcomes and fails on API errors');
  $assert(count(\GuzzleHttp\Client::$calls) === 4, 'Identity-bearing query URLs and missing nodes are skipped');
  $command->lines = [];
  $command->syncContentUrls(['limit' => 2000, 'offset' => 1, 'dry-run' => TRUE]);
  $assert(Drupal::$lastQuery->window === [1, 1000] && isset(Drupal::$lastQuery->conditions['field_ttd_last_analyzed']), 'Archive scans are bounded, ordered, and limited to analyzed published content');
  $command->lines = [];
  $command->syncContentUrls(['node-ids' => '42,43', 'limit' => 1, 'dry-run' => TRUE]);
  $assert(in_array('Candidates: 1 (dry run)', $command->lines, TRUE), 'Explicit node lists also respect the limit');
  Drupal::$config->values['enabled_content_types'] = [];
  $command->lines = [];
  $command->syncContentUrls(['dry-run' => TRUE]);
  $assert(in_array('Candidates: 0 (dry run)', $command->lines, TRUE), 'An empty enabled scope scans no nodes');

  echo "Drupal canonical URL sync checks passed. Assertions: {$assertions}.\n";
}
