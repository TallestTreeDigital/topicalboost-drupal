<?php

namespace Drupal\node { interface NodeInterface {} }
namespace Drupal\advancedqueue {
  class Job {
    public static function create($type, $payload) {
      if (!$payload) throw new \InvalidArgumentException('AdvancedQueue requires a payload.');
      return (object) ['type' => $type, 'payload' => $payload];
    }
  }
}
namespace {
  use Drupal\ttd_topics\Service\PublishDateSync as Sync;
  require dirname(__DIR__, 2) . '/src/Service/PublishDateSync.php';
  define('TOPICALBOOST_API_ENDPOINT', 'https://api.fixture.test');
  function ttd_topics_api_headers($key) { return ['x-api-key' => $key, 'x-tb-platform' => 'drupal']; }
  class DateNode implements \Drupal\node\NodeInterface {
    public function __construct(public int $nid, public string $type = 'article', public bool $published = TRUE, public int $created = 1794882600) {}
    public function bundle() { return $this->type; }
    public function isPublished() { return $this->published; }
    public function getCreatedTime() { return $this->created; }
  }
  class DateStore {
    public array $values = [];
    public function get($name, $default = NULL) { return $this->values[$name] ?? $default; }
    public function set($name, $value) { $this->values[$name] = $value; }
  }
  class DateLock {
    public array $held = [];
    public function acquire($name, $timeout) { if (isset($this->held[$name])) return FALSE; $this->held[$name] = TRUE; return TRUE; }
    public function release($name) { unset($this->held[$name]); }
  }
  class DateStorage {
    public function __construct(private string $type) {}
    public function resetCache($ids) {}
    public function load($id) {
      if ($this->type !== 'node') return new DateQueue();
      if (in_array($id, Drupal::$unreadable, TRUE)) throw new \RuntimeException('Fixture unreadable node');
      return Drupal::$nodes[$id] ?? NULL;
    }
  }
  class DateQueue {
    public function enqueueJob($job, $delay) { Drupal::$jobs[] = $job; }
  }
  class DateQuery {
    private array $conditions = [];
    private int $limit = 1;
    public function __construct(private string $table) {}
    public function fields(...$args) { return $this; }
    public function distinct() { return $this; }
    public function orderBy(...$args) { return $this; }
    public function range($offset, $limit) { $this->limit = $limit; return $this; }
    public function condition($name, $value, $operator = '=') { $this->conditions[$name . $operator] = $value; return $this; }
    public function countQuery() { return $this; }
    public function execute() { return $this; }
    public function fetchField() { return $this->table === 'advancedqueue' ? count(Drupal::$jobs) : (Drupal::$nodes ? max(array_keys(Drupal::$nodes)) : 0); }
    public function fetchCol() {
      Drupal::$scans[] = $this->conditions;
      $ids = [];
      foreach (Drupal::$nodes as $id => $node) {
        if ($id >= $this->conditions['nid>='] && $id < $this->conditions['nid<']
          && $node->published && in_array($node->type, $this->conditions['typeIN'], TRUE)) $ids[] = $id;
      }
      rsort($ids);
      return array_slice($ids, 0, $this->limit);
    }
  }
  class DateHttp {
    public array $calls = [];
    public ?array $response = NULL;
    public bool $fail = FALSE;
    public $callback = NULL;
    public function request($method, $url, $options) {
      $this->calls[] = [$method, $url, $options];
      if ($this->callback) ($this->callback)();
      if ($this->fail) throw new \RuntimeException('Fixture outage');
      $body = $this->response ?? ['ok' => TRUE, 'received' => count($options['json']['posts']), 'matched' => count($options['json']['posts'])];
      return new class($body) {
        public function __construct(private array $body) {}
        public function getBody() { return json_encode($this->body); }
      };
    }
  }
  class Drupal {
    public static DateStore $store;
    public static DateLock $lock;
    public static DateHttp $http;
    public static array $nodes = [], $jobs = [], $scans = [], $unreadable = [], $warnings = [];
    public static array $settings = ['enabled_content_types' => ['article'], 'topicalboost_api_key' => 'fixture-key'];
    public static function config($name) { return new class($name) {
      public function __construct(private string $name) {}
      public function get($key) { return $this->name === 'system.date' ? 'America/New_York' : (Drupal::$settings[$key] ?? NULL); }
    }; }
    public static function keyValue($name) { return self::$store; }
    public static function lock() { return self::$lock; }
    public static function httpClient() { return self::$http; }
    public static function database() { return new class { public function select($table, $alias) { return new DateQuery($table); } }; }
    public static function entityTypeManager() { return new class { public function getStorage($type) { return new DateStorage($type); } }; }
    public static function logger($channel) { return new class { public function warning($message, $context = []) { Drupal::$warnings[] = strtr($message, $context); } }; }
  }
  $checks = 0;
  function check_dates($condition, $message) {
    if (!$condition) throw new \RuntimeException('FAIL: ' . $message);
    $GLOBALS['checks']++; echo 'PASS: ' . $message . "\n";
  }
  function reset_dates($count = 3) {
    Drupal::$store = new DateStore(); Drupal::$lock = new DateLock(); Drupal::$http = new DateHttp();
    Drupal::$nodes = Drupal::$jobs = Drupal::$scans = Drupal::$unreadable = Drupal::$warnings = [];
    Drupal::$settings = ['enabled_content_types' => ['article'], 'topicalboost_api_key' => 'fixture-key'];
    for ($i = 1; $i <= $count; $i++) Drupal::$nodes[$i] = new DateNode($i);
  }
  function due_dates() {
    Drupal::$jobs = [];
    $state = Drupal::$store->get(Sync::STATE); $state['next_at'] = 0; Drupal::$store->set(Sync::STATE, $state);
  }
  $sync = new Sync();
  reset_dates(205); $sync->run();
  check_dates(!Drupal::$http->calls && !Drupal::$scans, 'Starting catch-up only saves a checkpoint and delayed wakeup');
  due_dates(); $first = $sync->run();
  check_dates($first['processed'] === 100 && $first['cursor'] === 106 && $first['status'] === 'pending', 'A batch sends at most 100 newest IDs and saves its cursor');
  $payload = Drupal::$http->calls[0];
  check_dates($payload[1] === TOPICALBOOST_API_ENDPOINT . '/content/publish-dates'
    && $payload[2]['headers']['x-tb-platform'] === 'drupal', 'Dates use the shared endpoint and Drupal identity');
  check_dates($payload[2]['json']['posts'][0]['published_at'] === gmdate('Y-m-d\TH:i:s\Z', 1794882600)
    && $payload[2]['json']['posts'][0]['published_date'] === '2026-11-16', 'UTC instants and the configured article date remain separate');
  due_dates(); Drupal::$http->fail = TRUE; $sync->run();
  $failed = Drupal::$store->get(Sync::STATE);
  check_dates($failed['cursor'] === 106 && $failed['attempts'] === 1 && $failed['status'] === 'retrying', 'An API outage preserves the cursor and schedules retry');
  check_dates(count(Drupal::$warnings) === 1 && str_contains(Drupal::$warnings[0], 'attempt 1'), 'A failed batch logs a warning with its attempt number');
  due_dates(); Drupal::$http->fail = FALSE; Drupal::$http->response = ['ok' => TRUE, 'received' => 100, 'matched' => 101]; $sync->run();
  check_dates(Drupal::$store->get(Sync::STATE)['cursor'] === 106, 'An invalid API acknowledgement cannot advance the cursor');
  due_dates(); Drupal::$http->response = NULL; $sync->run(); due_dates(); $last = $sync->run();
  check_dates($last['processed'] === 205 && $last['status'] === 'complete', 'Retries resume and the finite archive completes');
  $calls = count(Drupal::$http->calls); $sync->run();
  check_dates(count(Drupal::$http->calls) === $calls && !$sync->queueIfDue(), 'Completed catch-up does not rescan on later cron runs');

  reset_dates(); $sync->run(); due_dates(); Drupal::$http->callback = static function () { Drupal::$settings['topicalboost_api_key'] = 'changed-fixture-key'; };
  check_dates($sync->run()['status'] === 'replaced' && Drupal::$store->get(Sync::STATE)['processed'] === 0, 'An old connection response cannot confirm progress for a new connection');
  Drupal::$http->callback = NULL; $sync->run();
  check_dates(Drupal::$store->get(Sync::STATE)['cursor'] === NULL, 'A new connection starts its own finite generation');
  reset_dates(); Drupal::$settings['enabled_content_types'] = []; $sync->run(); due_dates();
  check_dates($sync->run()['status'] === 'waiting' && !Drupal::$scans && !Drupal::$http->calls, 'An empty enabled scope waits without querying or sending the archive');

  reset_dates(); Drupal::$nodes[2]->published = FALSE; Drupal::$nodes[3]->type = 'page';
  $sync->run(); due_dates(); $sync->run();
  check_dates(array_column(Drupal::$http->calls[0][2]['json']['posts'], 'post_id') === [1], 'Drafts and disabled content types stay out');
  reset_dates(); $sync->run(); due_dates(); Drupal::$unreadable = [2];
  for ($attempt = 0; $attempt < 3; $attempt++) { $sync->run(); due_dates(); }
  $skipped = $sync->run();
  check_dates($skipped['status'] === 'complete' && $skipped['unreadable_ids'] === [2]
    && $skipped['written'] === 2, 'A persistently unreadable post is recorded after bounded strict retries');
  reset_dates(); $sync->run(); due_dates(); Drupal::$nodes = [500001 => new DateNode(500001, 'page')];
  $sparse = $sync->run();
  check_dates(count(Drupal::$scans) === 20 && $sparse['cursor'] > 1 && !Drupal::$http->calls, 'A sparse archive scans at most twenty bounded ID windows per run');
  echo 'Drupal publish date unit checks passed: ' . $checks . " assertions.\n";
}
