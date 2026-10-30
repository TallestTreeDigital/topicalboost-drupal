<?php

namespace Drupal\Core\Config { interface ConfigFactoryInterface {} }
namespace Drupal\Core\State { interface StateInterface {} }
namespace Drupal\Core\Entity { interface EntityTypeManagerInterface {} interface EntityInterface {} }
namespace Drupal\Core\Lock { interface LockBackendInterface {} }
namespace Drupal\Component\Datetime { interface TimeInterface {} }
namespace Drupal\node { interface NodeInterface {} }
namespace GuzzleHttp { interface ClientInterface {} }
namespace Drupal\Core\Database {
  class Connection {
    public array $rows = [];
    public function select($table, $alias) { return new \DescriptionQuery($this, 'select'); }
    public function merge($table) { return new \DescriptionQuery($this, 'merge'); }
  }
}
namespace {
  require dirname(__DIR__, 2) . '/src/Service/SavedDescriptionSync.php';
  require dirname(__DIR__, 2) . '/src/Service/AnalyticsInventoryService.php';
  use Drupal\ttd_topics\Service\SavedDescriptionSync;
  use Drupal\ttd_topics\Service\AnalyticsInventoryService;

  function ttd_topics_api_headers($key) { return ['x-api-key' => $key, 'x-tb-site-url' => 'https://fixture.test']; }
  class DescriptionQuery {
    private array $conditions = [], $values = [], $keys = [];
    public function __construct(private $db, private $mode) {}
    public function fields($first, $fields = NULL) { if ($this->mode === 'merge') $this->values = $first; return $this; }
    public function keys(array $keys) { $this->keys = $keys; return $this; }
    public function condition($name, $value, $operator = '=') { $this->conditions[] = [$name, $value, $operator]; return $this; }
    public function execute() {
      if ($this->mode === 'merge') { $this->db->rows[$this->keys['collection'] . ':' . $this->keys['name']] = $this->keys + $this->values; return 1; }
      $rows = array_filter($this->db->rows, function ($row) {
        foreach ($this->conditions as [$name, $value, $operator]) {
          if ($operator === 'IN' ? !in_array($row[$name], $value) : $row[$name] != $value) return FALSE;
        }
        return TRUE;
      });
      return new class(array_values($rows)) {
        public function __construct(private array $rows) {}
        public function fetchField() { return $this->rows[0]['value'] ?? FALSE; }
        public function fetchAllKeyed() { return array_column($this->rows, 'value', 'name'); }
      };
    }
  }
  class DescriptionConfig implements \Drupal\Core\Config\ConfigFactoryInterface {
    public string $key = 'fixture-key-before';
    public function get($name) { return new class($this, $name) {
      public function __construct(private $factory, private $name) {}
      public function get($key) { return $this->name === 'system.site' ? 'fixture-site-uuid' : $this->factory->key; }
    }; }
  }
  class DescriptionState implements \Drupal\Core\State\StateInterface { public function resetCache() {} }
  class DescriptionEntities implements \Drupal\Core\Entity\EntityTypeManagerInterface {}
  class DescriptionLock implements \Drupal\Core\Lock\LockBackendInterface {
    public function acquire($name, $timeout) { return TRUE; }
    public function release($name) {}
  }
  class DescriptionHttp implements \GuzzleHttp\ClientInterface {
    public array $last = [];
    public $afterSend = NULL;
    public function request($method, $url, $options) {
      $this->last = ['url' => $url, 'options' => $options];
      if ($this->afterSend) ($this->afterSend)();
      return new class { public function getBody() { return '{"ok":true}'; } };
    }
  }
  $checks = 0;
  function verify_order($condition, $message) {
    if (!$condition) throw new \RuntimeException('FAIL: ' . $message);
    $GLOBALS['checks']++; echo 'PASS: ' . $message . "\n";
  }
  $gateway = (new \ReflectionClass(AnalyticsInventoryService::class))->newInstanceWithoutConstructor();
  $config = new DescriptionConfig(); $http = new DescriptionHttp();
  foreach (['configFactory' => $config, 'httpClient' => $http] as $name => $value) {
    $property = new \ReflectionProperty($gateway, $name); $property->setValue($gateway, $value);
  }
  $db = new \Drupal\Core\Database\Connection();
  $helper = new SavedDescriptionSync($gateway, new DescriptionState(), $db, new DescriptionEntities(), new DescriptionLock());
  $post = static fn($value, $revision) => ['post_id' => 1, 'meta' => ['_ttd_saved_description' => $value, '_ttd_saved_description_revision' => $revision]];
  $a = $post('A', '10'); $scope = $gateway->savedDescriptionScope(); $initial = $helper->dirtyTokens([1]);
  $helper->confirmPosts([$a], $scope, $initial);
  verify_order($helper->unconfirmedPosts([$a]) === [], 'An unchanged value with its confirmed dirty token is covered');

  $helper->markDirty(1); $b_token = $helper->dirtyTokens([1]);
  // The warehouse wrote B, but its HTTP response was lost. There is no local ack.
  $helper->markDirty(1); $new_a_token = $helper->dirtyTokens([1]);
  $helper->confirmPosts([$a], $scope, $initial);
  verify_order($helper->unconfirmedPosts([$post('A', '40')]) !== [], 'A late old A acknowledgement cannot cover A after B was written and its response lost');
  $helper->confirmPosts([$post('B', '30')], $scope, $b_token);
  verify_order($helper->unconfirmedPosts([$post('A', '40')]) !== [], 'A late B acknowledgement also cannot cover the newer dirty epoch');
  $helper->confirmPosts([$post('A', '40')], $scope, $new_a_token);
  verify_order($helper->unconfirmedPosts([$post('A', '40')]) === [], 'A confirmed current A observation can cover the current dirty epoch');
  $captured = $helper->dirtyTokens([1]); $helper->markDirty(1);
  verify_order($helper->unconfirmedPosts([$post('A', '40')], $scope, $captured) !== [], 'A node save after payload-token capture invalidates an otherwise equal coverage hash');
  $tokens = $helper->dirtyTokens([1]); $helper->confirmPosts([$post(NULL, '50')], $scope, $tokens);
  verify_order($helper->unconfirmedPosts([$post(NULL, '50')]) === [], 'A confirmed current clear is covered by its own epoch');

  $context_method = new \ReflectionMethod($gateway, 'savedDescriptionRequestContext');
  $request_method = new \ReflectionMethod($gateway, 'request');
  $context = $context_method->invoke($gateway); $config->key = 'fixture-key-after';
  $request_method->invoke($gateway, 'POST', '/analytics/content-inventory', ['posts' => [$a]], 5, $context);
  verify_order($http->last['options']['headers']['x-api-key'] === 'fixture-key-before', 'An in-flight inventory request retains its captured credential header');
  $helper->confirmPosts([$post('A', '60')], $context['scope'], $tokens);
  verify_order($helper->unconfirmedPosts([$post('A', '60')]) !== [], 'An old destination response cannot confirm coverage for the new connection');
  verify_order($helper->getStatus()['lastSuccessAt'] === NULL, 'A new destination does not inherit an old confirmed-success timestamp');

  $context = $context_method->invoke($gateway);
  $http->afterSend = static function () use ($config) { $config->key = 'fixture-key-third'; };
  $request_method->invoke($gateway, 'POST', '/telemetry/description-sync', ['revision' => '70'], 5, $context);
  verify_order($http->last['options']['headers']['x-api-key'] === 'fixture-key-after', 'Status transport also freezes its header when configuration changes during the response');
  $scope = $gateway->savedDescriptionScope();
  $helper->confirmPosts([$post('A', '80')], $scope, $tokens);
  $helper->confirmDeletion(1, '90', $context['scope'], $tokens['1']);
  verify_order($helper->unconfirmedPosts([$post('A', '80')]) === [], 'A deletion acknowledgement for a previous destination cannot invalidate the current confirmation');
  echo 'Saved-description ordering and identity unit checks passed: ' . $checks . " assertions.\n";
}
