<?php

namespace Drupal\Core\Config { interface ConfigFactoryInterface {} }
namespace Drupal\Core\Controller { class ControllerBase {} }
namespace Drupal\Core\Entity { interface EntityTypeManagerInterface {} }
namespace Drupal\node { interface NodeInterface {} }
namespace Drupal\Core\Database {
  class Connection {
    public array $rows = [];
    public bool $failRead = FALSE;
    public function condition($operator) { return new \BulkCondition($operator); }
    public function escapeLike($text) { return addcslashes($text, '\\%_'); }
    public function select(...$args) { if ($this->failRead) throw new \RuntimeException('Fixture read failure'); return new \BulkQuery($this); }
    public function delete(...$args) { return new \BulkQuery($this, TRUE); }
    public function schema() { return new class { public function tableExists($table) { return FALSE; } }; }
    public function startTransaction() { return new class { public function rollBack() {} }; }
  }
}
namespace Drupal\advancedqueue\Plugin\AdvancedQueue\JobType { class JobTypeBase {} }
namespace Drupal\advancedqueue {
  class Job {
    public function __construct(private array $payload, private int $retries = 0) {}
    public static function create($type, $payload) { return new self($payload); }
    public function getPayload() { return $this->payload; }
    public function getNumRetries() { return $this->retries; }
  }
  class JobResult {
    public static function success($message) { return TRUE; }
    public static function failure($message) { return FALSE; }
  }
}
namespace GuzzleHttp\Exception {
  class RequestException extends \RuntimeException {
    public function getResponse() { return new class($this->getCode()) {
      public function __construct(private int $code) {}
      public function getStatusCode() { return $this->code; }
    }; }
  }
}
namespace GuzzleHttp {
  class Client {
    public static array $calls = [], $result = [];
    public static $callback = NULL;
    public static ?\Throwable $error = NULL;
    public function get($url, $options) { return $this->send($url, $options); }
    public function post($url, $options) { return $this->send($url, $options); }
    public function request($method, $url, $options) { return $this->send($url, $options); }
    private function send($url, $options) {
      self::$calls[] = [$url, $options];
      if (self::$callback) (self::$callback)();
      if (self::$error) throw self::$error;
      return new class { public function getBody() { return json_encode(Client::$result); } };
    }
  }
}
namespace Symfony\Component\HttpFoundation {
  class Request {}
  class JsonResponse {
    public function __construct(public array $data, public int $status = 200) {}
  }
}
namespace {
  use Drupal\advancedqueue\Job;
  use Drupal\ttd_topics\Service\BulkAnalysisLifecycle as Lifecycle;
  use Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkAnalysisPoller as Poller;
  use Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkApplyPostsOptimized as Apply;
  use Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkBatchSend as Send;
  use GuzzleHttp\Client;
  require dirname(__DIR__, 2) . '/src/Service/BulkAnalysisLifecycle.php';
  require dirname(__DIR__, 2) . '/src/Service/BulkAnalysisRecovery.php';
  require dirname(__DIR__, 2) . '/src/Controller/BulkAnalysisController.php';
  require dirname(__DIR__, 2) . '/src/Plugin/AdvancedQueue/JobType/TtdBulkAnalysisPoller.php';
  require dirname(__DIR__, 2) . '/src/Plugin/AdvancedQueue/JobType/TtdBulkApplyPostsOptimized.php';
  require dirname(__DIR__, 2) . '/src/Plugin/AdvancedQueue/JobType/TtdBulkBatchSend.php';

  class BulkStore {
    public array $values = [];
    public function get($key, $default = NULL) { return $this->values[$key] ?? $default; }
    public function set($key, $value) { $this->values[$key] = $value; }
    public function delete($key) { unset($this->values[$key]); }
    public function resetCache() {}
  }
  class BulkConfig implements \Drupal\Core\Config\ConfigFactoryInterface {
    public static string $key = 'fixture-key';
    public function get($key) { return $key === 'ttd_topics.settings' ? $this : ($key === 'topicalboost_api_key' ? self::$key : FALSE); }
  }
  class BulkEntities implements \Drupal\Core\Entity\EntityTypeManagerInterface {
    public array $jobs = [];
    public array $nodes = [];
    public function getStorage($type) { return $this; }
    public function load($id) { return $this->nodes[$id] ?? $this; }
    public function enqueueJob($job, $delay = 0) { $this->jobs[] = $job; }
  }
  class BulkNode implements \Drupal\node\NodeInterface {
    public array $values = ['field_ttd_topics' => [['target_id' => 99]]];
    public array $manual = [];
    public int $saves = 0;
    public function id() { return 1; }
    public function hasField($name) { return TRUE; }
    public function set($name, $value) { $this->values[$name] = $value; }
    public function save() { $this->saves++; }
  }
  class BulkCondition {
    public array $conditions = [];
    public function __construct(private string $operator = 'AND') {}
    public function condition($name, $value = NULL, $operator = '=') { $this->conditions[] = [$name, $value, $operator]; return $this; }
    public function matches($row): bool {
      $results = [];
      foreach ($this->conditions as [$name, $value, $operator]) {
        if ($name instanceof self) { $results[] = $name->matches($row); continue; }
        $actual = $row[$name] ?? NULL;
        if ($operator === 'LIKE') {
          $fragment = stripcslashes(trim($value, '%'));
          $results[] = str_contains((string) $actual, $fragment);
        }
        else $results[] = $operator === 'IN' ? in_array($actual, $value, TRUE) : $actual === $value;
      }
      return $this->operator === 'OR' ? in_array(TRUE, $results, TRUE) : !in_array(FALSE, $results, TRUE);
    }
  }
  class BulkQuery extends BulkCondition {
    public function __construct(private $db, private bool $delete = FALSE) { parent::__construct(); }
    public function execute() {
      if (!$this->delete) return $this;
      verify_bulk(!Lifecycle::current(12), 'The terminal marker is visible before queue deletion');
      $before = count($this->db->rows);
      $this->db->rows = array_values(array_filter($this->db->rows, fn($row) => !$this->matches($row)));
      return $before - count($this->db->rows);
    }
    public function __call($method, $args) { return $this; }
    public function fetchField() { return 0; }
  }
  class Drupal {
    public static BulkStore $store;
    public static \Drupal\Core\Database\Connection $db;
    public static BulkEntities $entities;
    public static function keyValue($name) { return self::$store; }
    public static function state() { return self::$store; }
    public static function database() { return self::$db; }
    public static function config($name) { return new BulkConfig(); }
    public static function httpClient() { return new Client(); }
    public static function lock() { return new class {
      public function acquire($name, $timeout) { return TRUE; }
      public function release($name) {}
    }; }
    public static function entityTypeManager() { return self::$entities; }
    public static function time() { return new class { public function getRequestTime() { return 123; } }; }
    public static function logger($name) { return new class { public function __call($name, $args) {} }; }
  }
  function ttd_topics_api_headers($key) { return ['x-api-key' => $key, 'x-tb-platform' => 'drupal']; }
  function ttd_topics_merge_analysis_topic_ids_with_manual($node, $api_ids, $entity_ids) { return array_merge($api_ids, $node->manual); }
  $checks = 0;
  function verify_bulk($condition, $message) {
    if (!$condition) throw new \RuntimeException('FAIL: ' . $message);
    $GLOBALS['checks']++; echo 'PASS: ' . $message . "\n";
  }
  function reset_bulk() {
    Drupal::$store = new BulkStore(); Drupal::$db = new \Drupal\Core\Database\Connection(); Drupal::$entities = new BulkEntities();
    Drupal::$store->set(Lifecycle::REQUEST, 12);
    Client::$calls = []; Client::$callback = NULL; Client::$error = NULL; Client::$result = [];
  }
  $job = new Job(['request_id' => 12, 'page' => 1, 'page_count' => 1, 'filters' => []]);
  reset_bulk();
  verify_bulk(Lifecycle::current('12'), 'Numeric and string request IDs identify the same active request');
  Drupal::$store->set(Lifecycle::REQUEST, 123);
  foreach ([new Poller(), new Apply(), new Send()] as $worker) verify_bulk($worker->process($job), 'A replaced request exits its worker successfully');
  verify_bulk(!Client::$calls, 'Replaced requests do not make API calls');

  reset_bulk();
  foreach ([12, '12', 123] as $id) Drupal::$db->rows[] = ['queue_id' => 'ttd_topics_analysis', 'type' => 'ttd_bulk_batch_send', 'state' => 'queued', 'payload' => json_encode(['request_id' => $id, 'page' => 1])];
  Drupal::$db->rows[] = ['queue_id' => 'ttd_topics_analysis', 'type' => 'ttd_bulk_batch_send', 'state' => 'processing', 'payload' => '{"request_id":12}'];
  verify_bulk(Lifecycle::stop(12, 'cancelled', 'Cancelled') === 2, 'Cleanup matches numeric and string JSON IDs exactly');
  verify_bulk(count(Drupal::$db->rows) === 2 && !Lifecycle::current(12), 'Cleanup preserves other requests and leaves claimed jobs for the processor');

  reset_bulk(); Client::$result = ['terminal' => TRUE, 'status' => 'failed', 'ready' => TRUE, 'message' => 'Analysis failed'];
  verify_bulk((new Poller())->process($job) && !Lifecycle::current(12), 'A terminal API failure stops polling even when ready is set');
  verify_bulk(!Drupal::$entities->jobs, 'A terminal API failure does not apply partial results or schedule another poll');

  foreach ([new Poller(), new Apply()] as $worker) {
    reset_bulk(); Client::$result = ['ready' => TRUE, 'posts' => [['customer_id' => 1]], 'entities' => [['id' => 1]]];
    Client::$callback = static function () { Lifecycle::stop(12, 'cancelled', 'Cancelled during response'); };
    verify_bulk($worker->process($job) && !Drupal::$entities->jobs, 'Cancellation during an API call prevents result work and follow-up jobs');
  }
  reset_bulk(); Client::$result = ['ready' => FALSE, 'message' => 'Working'];
  verify_bulk((new Poller())->process($job) && count(Drupal::$entities->jobs) === 1, 'An active request still schedules its next poll');
  verify_bulk(Client::$calls[0][1]['headers']['x-tb-platform'] === 'drupal', 'Polling retains Drupal identity headers');

  foreach ([[], [77]] as $manual) {
    reset_bulk();
    $node = new BulkNode(); $node->manual = $manual; Drupal::$entities->nodes[1] = $node;
    Client::$result = ['posts' => [['customer_id' => 1, 'entity_ids' => []]], 'entities' => []];
    verify_bulk((new Apply())->process($job) && $node->saves === 1
      && $node->values['field_ttd_topics'] === array_map(static fn($id) => ['target_id' => $id], $manual),
      'An empty analysis removes stale API topics and keeps manual topics');
  }

  reset_bulk(); Drupal::$db->failRead = TRUE;
  verify_bulk(!(new Send())->process($job) && Lifecycle::current(12), 'A transient upload preparation error remains retryable');
  verify_bulk((new Send())->process(new Job($job->getPayload(), 3)) && !Lifecycle::current(12), 'An exhausted upload stops the request instead of polling forever');

  $request = new \Symfony\Component\HttpFoundation\Request();
  $controller = new \Drupal\ttd_topics\Controller\BulkAnalysisController(Drupal::$db, new BulkConfig(), Drupal::$entities);
  reset_bulk(); Client::$result = ['request_id' => 12];
  $response = $controller->resetAnalysis($request);
  verify_bulk($response->status === 200 && !Drupal::$store->get(Lifecycle::REQUEST), 'Confirmed API cancellation clears the active local request');
  verify_bulk(str_ends_with(Client::$calls[0][0], '/analyze/bulk/cancel') && Client::$calls[0][1]['json'] === ['request_id' => 12], 'Cancellation sends the numeric request ID to the shared API');

  reset_bulk(); Client::$error = new \RuntimeException('Fixture outage');
  $response = $controller->resetAnalysis($request);
  verify_bulk($response->status === 503 && Drupal::$store->get(Lifecycle::REQUEST) === 12 && !Lifecycle::current(12), 'An uncertain server cancellation preserves its retry target and stops local work');
  verify_bulk($controller->pollAnalysis($request)->data['data']['analysis_status']['terminal'], 'The editor receives a terminal state from background cancellation');
  Client::$error = NULL; Client::$result = ['request_id' => 12];
  verify_bulk($controller->resetAnalysis($request)->status === 200, 'A failed cancellation can be retried');

  $recovery = new \Drupal\ttd_topics\Service\BulkAnalysisRecovery();
  reset_bulk();
  verify_bulk($recovery->recover() === 12 && !Client::$calls, 'Tracking an existing request does not probe the API');
  Drupal::$store->delete(Lifecycle::REQUEST);
  Client::$result = ['request_id' => 99, 'content_count' => 250, 'created_at' => '2026-11-17T12:00:00Z'];
  Drupal::$store->set('topicalboost.bulk_analysis.apply_progress', ['stage' => 'complete']);
  verify_bulk($recovery->recover() === 99 && Lifecycle::current(99), 'A lost request ID is restored from the API');
  verify_bulk(Drupal::$store->get('topicalboost.bulk_analysis.content_count') === 250
    && Drupal::$store->get('topicalboost.bulk_analysis.apply_progress') === NULL
    && count(Drupal::$entities->jobs) === 1, 'Recovery restores counts, discards unrelated progress and queues the background poller');
  $recovery->recover();
  verify_bulk(count(Client::$calls) === 1 && count(Drupal::$entities->jobs) === 1, 'Repeated recovery does not duplicate API reads or pollers');

  reset_bulk(); Drupal::$store->delete(Lifecycle::REQUEST); Client::$result = ['request_id' => NULL];
  $recovery->recover(); $recovery->recover();
  verify_bulk(count(Client::$calls) === 1, 'Confirmed empty results are cached for idle polling');
  BulkConfig::$key = 'other-fixture-key'; $recovery->recover();
  verify_bulk(count(Client::$calls) === 2, 'A connection change invalidates the negative cache');
  BulkConfig::$key = 'fixture-key';
  reset_bulk(); Drupal::$store->delete(Lifecycle::REQUEST);
  Drupal::$store->set(Lifecycle::TERMINAL, ['request_id' => 99, 'status' => 'cancelled']);
  Client::$result = ['request_id' => 99, 'content_count' => 10];
  verify_bulk($recovery->recover() === NULL && !Drupal::$entities->jobs, 'Recovery never revives an explicitly stopped request');
  foreach ([[], ['request_id' => 99, 'content_count' => 0], ['request_id' => '99', 'content_count' => 10]] as $invalid) {
    reset_bulk(); Drupal::$store->delete(Lifecycle::REQUEST); Client::$result = $invalid;
    $failed = FALSE;
    try { $recovery->recover(); } catch (\RuntimeException $e) { $failed = TRUE; }
    verify_bulk($failed && Drupal::$store->get(\Drupal\ttd_topics\Service\BulkAnalysisRecovery::EMPTY_STATE) === NULL,
      'Malformed recovery responses fail without claiming there is no active request');
  }
  reset_bulk(); Drupal::$store->delete(Lifecycle::REQUEST); Client::$error = new \RuntimeException('Fixture outage');
  $failed = FALSE;
  try { $recovery->recover(); } catch (\RuntimeException $e) { $failed = TRUE; }
  verify_bulk($failed && !Drupal::$entities->jobs && Drupal::$store->get(\Drupal\ttd_topics\Service\BulkAnalysisRecovery::EMPTY_STATE) === NULL,
    'An API outage remains retryable and cannot be cached as an empty result');
  echo 'Drupal bulk lifecycle checks passed: ' . $checks . " assertions.\n";
}
