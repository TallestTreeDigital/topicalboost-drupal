<?php

namespace Drupal\taxonomy {
  interface TermInterface {}
}

namespace {
  class IdentityField {
    public function __construct(public array $values) {}
    public function isEmpty() { return !$this->values; }
    public function getValue() { return array_map(static fn($id) => ['value' => $id], $this->values); }
  }
  class IdentityTerm implements \Drupal\taxonomy\TermInterface {
    public function __construct(public int $tid, public string $name, public array $ids, public string $vid = 'ttd_topics') {}
    public function id() { return $this->tid; }
    public function get($field) { return new IdentityField($this->ids); }
    public function set($field, $id) { $this->ids = [$id]; }
    public function save() { Drupal::$storage->terms[$this->tid] = $this; }
  }
  class IdentityStorage {
    public array $terms = [];
    public function loadByProperties($properties) {
      return array_filter($this->terms, static function ($term) use ($properties) {
        return $term->vid === $properties['vid']
          && (!isset($properties['name']) || $term->name === $properties['name'])
          && (!isset($properties['field_ttd_id']) || in_array($properties['field_ttd_id'], $term->ids, TRUE));
      });
    }
    public function resetCache($ids) {}
    public function load($id) { return $this->terms[$id] ?? NULL; }
    public function loadMultiple($ids) { return array_intersect_key($this->terms, array_flip($ids)); }
    public function create($values) { return new IdentityTerm(count($this->terms) + 1, $values['name'], [$values['field_ttd_id']]); }
  }
  class IdentityLock {
    public bool $busy = FALSE;
    public int $acquire_failures = 0;
    public int $releases = 0;
    public int $waits = 0;
    public function acquire($name, $timeout) {
      if ($this->acquire_failures > 0) { $this->acquire_failures--; return FALSE; }
      return !$this->busy;
    }
    public function wait($name, $delay) {
      $this->waits++;
      if ($this->busy) { throw new \RuntimeException('Test lock backend failure.'); }
    }
    public function release($name) { $this->releases++; }
  }
  class Drupal {
    public static IdentityStorage $storage;
    public static IdentityLock $lock;
    public static function entityTypeManager() { return new class { public function getStorage($type) { return Drupal::$storage; } }; }
    public static function lock() { return self::$lock; }
  }
  require __DIR__ . '/../../src/Service/TopicTermResolver.php';
  $assertions = 0;
  $assert = static function ($condition, $message) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new \RuntimeException($message); }
  };
  $resolve = static fn($name, $id, $save = TRUE) => \Drupal\ttd_topics\Service\TopicTermResolver::resolve($name, $id, $save);
  Drupal::$storage = new IdentityStorage();
  Drupal::$lock = new IdentityLock();
  $first = $resolve('New York', 28);
  $second = $resolve('New York', 33);
  $assert($first->id() !== $second->id(), 'Same-name IDs must have distinct terms.');
  $assert($resolve('Provider name changed', 28)->id() === $first->id(), 'Exact ID must take precedence over name.');
  $assert($first->name === 'New York', 'Exact-ID reuse must preserve the editor label.');
  $assert($resolve('New York', 34, FALSE) === NULL, 'Preview cannot reuse a mismatched ID.');
  $assert($resolve('New York', 28, FALSE)->id() === $first->id(), 'Preview can reuse an exact ID.');
  $unlinked = new IdentityTerm(3, 'Unlinked', []);
  $unlinked->save();
  $assert($resolve('Unlinked', 35, FALSE) === NULL && !$unlinked->ids, 'Preview must not claim a term.');
  $assert($resolve('Unlinked', 35)->id() === 3 && $unlinked->ids === ['35'], 'One unlinked name can be claimed.');
  (new IdentityTerm(4, 'Ambiguous', []))->save();
  (new IdentityTerm(5, 'Ambiguous', []))->save();
  $assert($resolve('Ambiguous', 36)->id() === 6, 'Ambiguous names must create a dedicated term.');
  (new IdentityTerm(7, 'Mixed', ['37', '38']))->save();
  $assert($resolve('Mixed', 37)->id() === 8, 'A corrupt multi-ID field cannot be reused.');
  $assert($resolve('', 40) === NULL && $resolve('Invalid', 0) === NULL && $resolve('Invalid', 'abc') === NULL, 'Invalid inputs must be rejected.');
  $assert(count(Drupal::$storage->terms) === 8, 'Invalid inputs must not create terms.');
  $released = Drupal::$lock->releases;
  Drupal::$lock->acquire_failures = 3;
  $assert($resolve('New York', 28)->id() === $first->id() && Drupal::$lock->waits === 3, 'Lost lock handoffs must retry until acquired.');
  $released = Drupal::$lock->releases;
  $waits = Drupal::$lock->waits;
  Drupal::$lock->busy = TRUE;
  $blocked = FALSE;
  try { $resolve('Blocked', 41); } catch (\RuntimeException $error) { $blocked = TRUE; }
  $assert($blocked && Drupal::$lock->waits === $waits + 1, 'Lock backend errors must fail closed.');
  $assert(Drupal::$lock->releases === $released, 'A failed acquire must not release another worker lock.');

  $root = __DIR__ . '/../../src/';
  foreach (['Service/TtdTopicsAnalysisService.php', 'Service/TtdSyncService.php', 'Plugin/AdvancedQueue/JobType/TtdTopicsAnalysis.php', 'Commands/FixTtdTopicsCommand.php', 'Commands/CreateTtdTopicsCommand.php'] as $path) {
    $assert(str_contains(file_get_contents($root . $path), 'TopicTermResolver::resolve'), $path . ' must use the shared resolver.');
  }
  $assert(substr_count(file_get_contents($root . 'Controller/TtdTopicsController.php'), 'TopicTermResolver::resolve') === 2, 'Both manual controller creation paths must use the resolver.');
  echo "Topic identity unit and caller checks passed: {$assertions} assertions.\n";
}
