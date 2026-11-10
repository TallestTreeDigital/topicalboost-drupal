<?php

namespace Drupal\Core\State { interface StateInterface {} }
namespace Drupal\Core\Entity { interface EntityTypeManagerInterface {} }
namespace Drupal\Core\Lock { interface LockBackendInterface {} }
namespace Drupal\Core\Database {
  class Connection {
    public array $checkpoint;
    public function select($table, $alias) { return new \BudgetQuery($this, $table); }
    public function update($table) { return new \BudgetQuery($this, $table, TRUE); }
  }
}
namespace Drupal\ttd_topics\Service {
  function time() { return (int) $GLOBALS['budget_clock']; }
  function microtime($float) { return $GLOBALS['budget_clock']; }
  class AnalyticsInventoryService {
    public array $timeouts = [];
    public function savedDescriptionScope() { return 'fixture'; }
    public function isEnabled() { return TRUE; }
    public function queueDescriptionBatch($delay) {}
    public function discardDescriptionBatches() {}
    public function reportSavedDescriptionStatus() {}
    public function syncSavedDescriptionNodes($ids, $sync_id, &$stats, $timeout) {
      $this->timeouts[] = $timeout;
      $GLOBALS['budget_clock'] += min(9, $timeout);
      if ($timeout < 9) throw new \RuntimeException('Fixture request deadline');
      $stats = ['sent' => count($ids), 'skipped' => 0];
      return count($ids);
    }
  }
}
namespace {
  require dirname(__DIR__, 2) . '/src/Service/SavedDescriptionSync.php';
  use Drupal\ttd_topics\Service\SavedDescriptionSync;

  class BudgetQuery {
    private array $conditions = [], $values = [];
    private int $limit = 10;
    public function __construct(private $db, private $table, private bool $update = FALSE) {}
    public function fields($alias, $fields = NULL) { if ($this->update) $this->values = $alias; return $this; }
    public function condition($key, $value, $operator = '=') { $this->conditions[$key] = $value; return $this; }
    public function orderBy($key) { return $this; }
    public function range($offset, $limit) { $this->limit = $limit; return $this; }
    public function execute() {
      if ($this->update) {
        if ($this->conditions['value'] !== serialize($this->db->checkpoint)) return 0;
        $this->db->checkpoint = unserialize($this->values['value']);
        return 1;
      }
      if ($this->table === SavedDescriptionSync::CANDIDATE_TABLE) {
        $cursor = $this->conditions['nid'];
        $rows = $cursor < 25 ? array_map(static fn($id) => ['nid' => $id], range($cursor + 1, min(25, $cursor + $this->limit))) : [];
        return new class($rows) {
          public function __construct(private array $rows) {}
          public function fetchAll($mode) { return $this->rows; }
        };
      }
      $raw = ($this->conditions['name'] ?? '') === SavedDescriptionSync::STATE_KEY ? serialize($this->db->checkpoint) : FALSE;
      return new class($raw) {
        public function __construct(private $raw) {}
        public function fetchField() { return $this->raw; }
      };
    }
  }
  $GLOBALS['budget_clock'] = 1000.0;
  $db = new \Drupal\Core\Database\Connection();
  $db->checkpoint = ['format' => 3, 'destination' => 'fixture', 'status' => 'pending', 'cursor' => 0,
    'cursor_created' => NULL, 'upper_id' => 25, 'processed' => 0, 'written' => 0, 'skipped' => 0,
    'attempts' => 0, 'next_at' => 0];
  $gateway = new \Drupal\ttd_topics\Service\AnalyticsInventoryService();
  $state = new class implements \Drupal\Core\State\StateInterface { public function resetCache() {} };
  $entities = new class implements \Drupal\Core\Entity\EntityTypeManagerInterface {};
  $lock = new class implements \Drupal\Core\Lock\LockBackendInterface {
    public function acquire($name, $seconds) { return TRUE; }
    public function release($name) {}
  };
  $worker = new SavedDescriptionSync($gateway, $state, $db, $entities, $lock);
  $worker->run();
  if ($gateway->timeouts !== [20, 11, 2] || $GLOBALS['budget_clock'] !== 1020.0
    || $db->checkpoint['processed'] !== 20 || $db->checkpoint['cursor'] !== 20 || $db->checkpoint['status'] !== 'retrying') {
    throw new \RuntimeException('The cron budget or per-page durable checkpoint was lost.');
  }
  $GLOBALS['budget_clock'] = (float) $db->checkpoint['next_at'];
  $worker->run();
  if ($db->checkpoint['status'] !== 'complete' || $db->checkpoint['processed'] !== 25 || $db->checkpoint['written'] !== 25) {
    throw new \RuntimeException('The next cron run did not resume only the unfinished page.');
  }
  echo "Saved-description cron budget, HTTP deadlines and durable partial progress passed.\n";
}
