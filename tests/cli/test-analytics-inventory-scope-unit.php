<?php

namespace Drupal\Core\Config { interface ConfigFactoryInterface {} }
namespace Drupal\Core\State { interface StateInterface {} }
namespace Drupal\Core\Entity { interface EntityTypeManagerInterface {} }
namespace Drupal\Core\Lock { interface LockBackendInterface {} }
namespace Drupal\Component\Datetime { interface TimeInterface {} }
namespace Drupal\ttd_topics\Service {
  class SavedDescriptionSync {
    public function observationRevision() { return '1'; }
    public function dirtyTokens($ids) { return []; }
    public function confirmPosts(...$args) {}
  }
}
namespace {
  require dirname(__DIR__, 2) . '/src/Service/AnalyticsInventoryService.php';
  use Drupal\ttd_topics\Service\AnalyticsInventoryService as Inventory;

  function ttd_topics_api_headers($key) { return ['x-api-key' => $key]; }
  class ScopeConfig implements \Drupal\Core\Config\ConfigFactoryInterface {
    public array $types = ['article', 'page'];
    public function get($name) { return new class($this) {
      public function __construct(private $config) {}
      public function get($key) { return $key === 'enabled_content_types' ? $this->config->types : 'fixture'; }
    }; }
  }
  class ScopeState implements \Drupal\Core\State\StateInterface {
    public array $values = [];
    public function get($key, $default = NULL) { return $this->values[$key] ?? $default; }
    public function set($key, $value) { $this->values[$key] = $value; }
    public function delete($key) { unset($this->values[$key]); }
  }
  class ScopeTime implements \Drupal\Component\Datetime\TimeInterface {
    public function getRequestTime() { return 100; }
  }
  class ScopeLock implements \Drupal\Core\Lock\LockBackendInterface {
    public function acquire(...$args) { return TRUE; }
    public function release(...$args) {}
  }
  class ScopeEntities implements \Drupal\Core\Entity\EntityTypeManagerInterface {
    public int $queries = 0;
    public function getStorage($type) { return $this; }
    public function resetCache($ids) {}
    public function loadMultiple($ids) { return []; }
    public function getQuery() { $this->queries++; throw new \RuntimeException('An empty scope must not query nodes.'); }
  }
  class ScopeInventory extends Inventory {
    public array $response = ['enabled' => TRUE, 'connected' => TRUE];
    public array $writes = [];
    public int $fullSyncs = 0;
    public function __construct($config, $state, $entities) {
      $this->configFactory = $config; $this->state = $state; $this->entityTypeManager = $entities;
      $this->time = new ScopeTime(); $this->lock = new ScopeLock();
      $this->savedDescriptions = new \Drupal\ttd_topics\Service\SavedDescriptionSync();
    }
    public function queueFullSync(): bool { $this->fullSyncs++; return TRUE; }
    protected function request(string $method, string $path, ?array $body = NULL, int $timeout = 30, ?array $context = NULL): array {
      if ($method === 'GET') return $this->response;
      $this->writes[] = $body;
      return ['ok' => TRUE, 'siteMatches' => TRUE, 'summary' => [
        'rowsAccepted' => count($body['posts']), 'rowsWritten' => count($body['posts']),
      ]];
    }
  }
  $checks = 0;
  function verify_scope($actual, $expected, $message) {
    if ($actual !== $expected) throw new \RuntimeException('FAIL: ' . $message . ' ' . json_encode($actual));
    $GLOBALS['checks']++; echo 'PASS: ' . $message . "\n";
  }
  $config = new ScopeConfig(); $state = new ScopeState(); $entities = new ScopeEntities();
  $service = new ScopeInventory($config, $state, $entities);
  $state->set(Inventory::CONTENT_TYPES_STATE_KEY, ['article', 'disabled_type']);
  verify_scope($service->inventoryContentTypes(), ['article'], 'Analytics cannot add a disabled content type');
  $config->types = [];
  verify_scope($service->inventoryContentTypes(), [], 'An explicitly empty TopicalBoost scope remains empty');
  $config->types = ['article', 'page'];
  $state->set(Inventory::CONTENT_TYPES_STATE_KEY, ['unrelated']);
  verify_scope($service->inventoryContentTypes(), [], 'Disjoint scopes do not fall back to article');
  $service->response['analytics']['inventoryPostTypes'] = [];
  $result = $service->probe();
  verify_scope($result['content_types'], ['article', 'page'], 'An empty probe allowlist clears the old restriction');
  $state->set(Inventory::CONTENT_TYPES_STATE_KEY, ['article']);
  unset($service->response['analytics']);
  verify_scope($service->probe()['content_types'], ['article', 'page'], 'An omitted probe allowlist clears the old restriction');
  $service->response['analytics']['inventoryPostTypes'] = ['page', 'disabled_type'];
  verify_scope($service->probe()['content_types'], ['page'], 'A fresh probe can only narrow the configured scope');
  verify_scope($service->fullSyncs, 0, 'Entitlement probes never start an archive scan');
  verify_scope($result['queued'], FALSE, 'The probe reports that no full sync was queued');
  $config->types = [];
  $batch = $service->syncBatch('fixture', 1, 50);
  verify_scope([$batch['sent'], $batch['complete'], $entities->queries], [0, TRUE, 0], 'An empty full-sync scope completes without querying nodes');
  $service->response = ['enabled' => FALSE, 'connected' => FALSE];
  verify_scope($service->probe()['enabled'], FALSE, 'A disconnected probe disables the relay');
  verify_scope($state->get(Inventory::CONTENT_TYPES_STATE_KEY), NULL, 'A disconnected probe clears its cached scope');
  echo 'Drupal inventory scope checks passed: ' . $checks . " assertions.\n";
}
