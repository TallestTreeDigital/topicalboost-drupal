<?php

/** Runs the real cleanup service against failure-case doubles. */

use Drupal\ttd_topics\Service\PostReconciliationService;
use Drupal\ttd_topics\Service\TtdSyncService;

require dirname(__DIR__, 2) . '/src/Service/TtdSyncService.php';
require dirname(__DIR__, 2) . '/src/Service/PostReconciliationService.php';
define('TOPICALBOOST_API_ENDPOINT', 'https://api.example.test');

class ReconcileState {
  public array $values = [];
  public function get($key) { return $this->values[$key] ?? NULL; }
  public function delete($key) { unset($this->values[$key]); }
  public function setWithExpire($key, $value, $ttl) {
    check($ttl === 600, 'Preview has a ten-minute expiry');
    $this->values[$key] = $value;
  }
}
class ReconcileUser {
  public int $uid = 1;
  public bool $allowed = TRUE;
  public function id() { return $this->uid; }
  public function hasPermission($permission) { return $this->allowed; }
}
class ReconcileConfig {
  public array $values = ['enabled_content_types' => ['article'], 'enabled_categories' => [], 'topicalboost_api_key' => 'fixture'];
  public function get($key) { return $this->values[$key] ?? NULL; }
}
class ReconcileLock {
  public bool $available = TRUE;
  public bool $released = FALSE;
  public function acquire($name, $ttl) { return $this->available; }
  public function release($name) { $this->released = TRUE; }
}
class ReconcileQuery {
  public string $table;
  public array $conditions = [];
  public function __construct($table) { $this->table = $table; }
  public function fields($alias, $fields) { return $this; }
  public function distinct() { return $this; }
  public function orderBy($field) { return $this; }
  public function countQuery() { return $this; }
  public function condition($field, $value, $operator = '=') { $this->conditions[$field] = $value; return $this; }
  public function execute() {
    if (Drupal::$databaseFailure) throw new RuntimeException('Database unavailable');
    return $this;
  }
  public function fetchField() { return Drupal::$pending; }
  public function fetchCol() {
    check(($this->conditions['n.status'] ?? NULL) === 1, 'Scope requires published nodes');
    check(($this->conditions['n.type'] ?? NULL) === array_values(array_filter(Drupal::$config->values['enabled_content_types'])), 'Scope uses configured content types');
    return Drupal::$ids;
  }
}
class ReconcileDatabase {
  public function schema() { return $this; }
  public function tableExists($table) { return TRUE; }
  public function select($table, $alias) { return new ReconcileQuery($table); }
}
class Drupal {
  public static $user;
  public static $config;
  public static $store;
  public static $state;
  public static $lock;
  public static array $ids = ['1', '2'];
  public static int $pending = 0;
  public static bool $databaseFailure = FALSE;
  public static function currentUser() { return self::$user; }
  public static function config($name) { return self::$config; }
  public static function state() { return self::$state; }
  public static function keyValueExpirable($name) { return self::$store; }
  public static function lock() { return self::$lock; }
  public static function database() { return new ReconcileDatabase(); }
}
function ttd_topics_get_site_base_url() { return 'https://site.example.test'; }
function ttd_topics_apply_bulk_category_filter($query, $types, $ids, $alias = 'n', $mode = 'include') {
  $GLOBALS['categoryCalls'][] = $ids;
  $GLOBALS['categoryModes'][] = $mode;
}
class ReconcileSync extends TtdSyncService {
  public array $calls = [];
  public $override = NULL;
  public bool $reportFails = FALSE;
  public $afterRequest = NULL;
  public function apiRequest(string $method, string $path, ?array $json = NULL, array $query = []) {
    $this->calls[] = $json;
    check($method === 'POST' && $path === '/sync/reconcile', 'Uses the existing reconciliation route');
    $result = [
      'dryRun' => $json['dryRun'], 'orphanedContents' => 3, 'orphanedRelationships' => 3,
      'orphanedEntities' => 1, 'totalSiteContents' => 5, 'reportedPostIds' => count($json['postIds']),
    ];
    if (!$json['dryRun']) $result['deleted'] = ['contents' => 3, 'relationships' => 3, 'entities' => 1];
    if ($this->afterRequest) ($this->afterRequest)();
    return $this->override === NULL ? $result : ($this->override)($result);
  }
  public function reportSiteMetrics() {
    if ($this->reportFails) throw new RuntimeException('Metrics unavailable');
    return ['success' => TRUE];
  }
}
function check($condition, $message) {
  if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
  echo "PASS: $message\n";
}
function fixture() {
  Drupal::$user = new ReconcileUser(); Drupal::$config = new ReconcileConfig();
  Drupal::$store = new ReconcileState(); Drupal::$state = new ReconcileState(); Drupal::$lock = new ReconcileLock();
  Drupal::$ids = ['1', '2']; Drupal::$pending = 0; Drupal::$databaseFailure = FALSE;
  $GLOBALS['categoryCalls'] = []; $GLOBALS['categoryModes'] = [];
  $sync = new ReconcileSync();
  return [new PostReconciliationService($sync), $sync];
}
function blocked($action, $sync, $message) {
  try { $action(); check(FALSE, $message); }
  catch (RuntimeException $e) {
    check(count(array_filter($sync->calls, fn($call) => $call['dryRun'] === FALSE)) === 0, $message);
  }
}
[$service, $sync] = fixture();
$p = $service->preview();
check(!empty($p['previewToken']) && count($sync->calls) === 1 && $sync->calls[0]['dryRun'], 'Preview only makes a dry-run request');
$r = $service->confirm($p['previewToken']);
check($r['deleted']['contents'] === 3 && $r['metricsReported'], 'Confirmation rechecks then removes records and reports metrics');
check(array_column($sync->calls, 'dryRun') === [TRUE, TRUE, FALSE], 'Destructive request follows two matching dry runs');
check(Drupal::$lock->released, 'Confirmation releases its lock');
try { $service->confirm($p['previewToken']); check(FALSE, 'Replay blocked'); }
catch (RuntimeException $e) { check(count($sync->calls) === 3, 'Used preview cannot be replayed'); }

foreach (['expired', 'wrong token', 'other user', 'content changed', 'API key changed', 'types changed', 'categories changed', 'mode changed', 'active sync', 'active bulk', 'active apply', 'queued work', 'lock held', 'empty list', 'permission revoked'] as $case) {
  [$service, $sync] = fixture(); $p = $service->preview(); $token = $p['previewToken'];
  switch ($case) {
    case 'expired': Drupal::$store->values = []; break;
    case 'wrong token': $token = 'wrong'; break;
    case 'other user': Drupal::$user->uid = 2; break;
    case 'content changed': Drupal::$ids = ['1']; break;
    case 'API key changed': Drupal::$config->values['topicalboost_api_key'] = 'changed'; break;
    case 'types changed': Drupal::$config->values['enabled_content_types'] = ['article', 'page']; break;
    case 'categories changed': Drupal::$config->values['enabled_categories'] = [7]; break;
    case 'mode changed': Drupal::$config->values['category_filter_mode'] = 'exclude'; break;
    case 'active sync': Drupal::$state->values['ttd_active_sync'] = ['active' => TRUE]; break;
    case 'active bulk': Drupal::$state->values['topicalboost.bulk_analysis.request_id'] = 12; break;
    case 'active apply': Drupal::$state->values['topicalboost.bulk_analysis.apply_progress'] = ['active' => TRUE]; break;
    case 'queued work': Drupal::$pending = 1; break;
    case 'lock held': Drupal::$lock->available = FALSE; break;
    case 'empty list': Drupal::$ids = []; break;
    case 'permission revoked': Drupal::$user->allowed = FALSE; break;
  }
  blocked(fn() => $service->confirm($token), $sync, "Confirmation blocks $case");
}
foreach (['no types', 'invalid types', 'invalid categories', 'empty IDs', 'invalid IDs', 'database failure', 'anonymous'] as $case) {
  [$service, $sync] = fixture();
  switch ($case) {
    case 'no types': Drupal::$config->values['enabled_content_types'] = []; break;
    case 'invalid types': Drupal::$config->values['enabled_content_types'] = 'article'; break;
    case 'invalid categories': Drupal::$config->values['enabled_categories'] = ['bad']; break;
    case 'empty IDs': Drupal::$ids = []; break;
    case 'invalid IDs': Drupal::$ids = ['bad']; break;
    case 'database failure': Drupal::$databaseFailure = TRUE; break;
    case 'anonymous': Drupal::$user->uid = 0; break;
  }
  blocked(fn() => $service->preview(), $sync, "Preview blocks $case");
  check(!$sync->calls, 'Unsafe scope makes no API request');
}
foreach (['disabled', 'missing result', 'invalid count', 'incomplete IDs', 'wrong dry-run'] as $case) {
  [$service, $sync] = fixture();
  $sync->override = function ($r) use ($case) {
    if ($case === 'disabled') $r['syncDisabled'] = TRUE;
    if ($case === 'missing result') return NULL;
    if ($case === 'invalid count') $r['orphanedContents'] = -1;
    if ($case === 'incomplete IDs') $r['reportedPostIds'] = 1;
    if ($case === 'wrong dry-run') $r['dryRun'] = FALSE;
    return $r;
  };
  blocked(fn() => $service->preview(), $sync, "Preview rejects $case");
}
[$service, $sync] = fixture(); $p = $service->preview();
$sync->override = function ($r) { $r['orphanedContents'] = 4; return $r; };
blocked(fn() => $service->confirm($p['previewToken']), $sync, 'Changed API counts block deletion');
[$service, $sync] = fixture(); $p = $service->preview();
$sync->afterRequest = function () { Drupal::$ids = ['1']; };
blocked(fn() => $service->confirm($p['previewToken']), $sync, 'Content changed during recheck blocks deletion');
[$service, $sync] = fixture();
$sync->override = function ($r) { $r['orphanedContents'] = 0; return $r; };
check(!isset($service->preview()['previewToken']), 'No stale posts means no destructive preview token');
[$service, $sync] = fixture(); Drupal::$config->values['enabled_categories'] = [7];
$service->preview(); check($GLOBALS['categoryCalls'] === [[7], [7]], 'Both scope reads use the configured category filter');
[$service, $sync] = fixture(); $p = $service->preview(); $sync->reportFails = TRUE;
$r = $service->confirm($p['previewToken']);
check($r['deleted']['contents'] === 3 && !$r['metricsReported'], 'Metrics failure preserves confirmed deletion success');
[$service, $sync] = fixture(); $p = $service->preview();
$sync->override = fn($r) => $r['dryRun'] ? $r : NULL;
try { $service->confirm($p['previewToken']); check(FALSE, 'Uncertain deletion rejected'); }
catch (RuntimeException $e) { check(Drupal::$lock->released, 'Uncertain deletion releases lock'); }
$before = count($sync->calls);
try { $service->confirm($p['previewToken']); check(FALSE, 'Uncertain deletion cannot replay'); }
catch (RuntimeException $e) { check(count($sync->calls) === $before, 'Uncertain deletion consumes preview before remote request'); }

[$service, $sync] = fixture(); Drupal::$config->values['category_filter_mode'] = 'exclude';
$service->preview(); check($GLOBALS['categoryModes'] === ['exclude', 'exclude'], 'Cleanup passes exclusion mode to both complete scope reads');
