<?php

/**
 * Isolated behavior tests for sitemap filtering and deferred regeneration.
 *
 * Run from the module root:
 *   php tests/cli/test-sitemap-eligibility-behavior.php
 */

namespace Drupal\Core\Cache {
  class Cache {
    public static array $invalidated = [];

    public static function invalidateTags(array $tags): void {
      self::$invalidated[] = $tags;
    }
  }
}

namespace Drupal\taxonomy\Entity {
  class TestField {
    public $value;

    public function __construct($value) {
      $this->value = $value;
    }

    public function isEmpty(): bool {
      return $this->value === NULL || $this->value === '';
    }
  }

  class Term {
    private int $id;
    private string $bundle;
    private array $fields;

    public function __construct(int $id, string $bundle, array $fields = []) {
      $this->id = $id;
      $this->bundle = $bundle;
      $this->fields = $fields;
    }

    public function id(): int {
      return $this->id;
    }

    public function bundle(): string {
      return $this->bundle;
    }

    public function hasField(string $name): bool {
      return array_key_exists($name, $this->fields);
    }

    public function get(string $name): TestField {
      return new TestField($this->fields[$name] ?? NULL);
    }
  }
}

namespace {
  class TestConfig {
    public array $values;

    public function __construct(array $values) {
      $this->values = $values;
    }

    public function get(string $name) {
      return $this->values[$name] ?? NULL;
    }
  }

  class TestTermStorage {
    public array $terms;

    public function __construct(array $terms) {
      $this->terms = $terms;
    }

    public function loadMultiple(array $ids): array {
      return array_intersect_key($this->terms, array_flip($ids));
    }

    public function resetCache(array $ids): void {}
  }

  class TestEntityTypeManager {
    public TestTermStorage $termStorage;
    public TestTermStorage $nodeStorage;

    public function __construct(TestTermStorage $term_storage, ?TestTermStorage $node_storage = NULL) {
      $this->termStorage = $term_storage;
      $this->nodeStorage = $node_storage ?: new TestTermStorage([]);
    }

    public function getStorage(string $type): TestTermStorage {
      return $type === 'node' ? $this->nodeStorage : $this->termStorage;
    }
  }

  class TestCountQuery {
    private array $counts;
    private array $relations;
    private array $ids = [];

    public function __construct(array $counts, array $relations = []) {
      $this->counts = $counts;
      $this->relations = $relations;
    }

    public function fields(string $table, array $fields): self {
      return $this;
    }

    public function innerJoin(string $table, string $alias, string $condition): string {
      return $alias;
    }

    public function condition(string $field, $value, string $operator = '='): self {
      if ($field === 'tid') {
        $this->ids = (array) $value;
      }
      return $this;
    }

    public function groupBy(string $field): self {
      return $this;
    }

    public function addExpression(string $expression, string $alias): self {
      return $this;
    }

    public function execute(): self {
      return $this;
    }

    public function fetchAllKeyed(): array {
      return array_intersect_key($this->counts, array_flip($this->ids));
    }

    public function fetchAll(): array {
      return array_values(array_filter($this->relations, function ($row) {
        return empty($this->ids) || in_array((int) $row->tid, array_map('intval', $this->ids), TRUE);
      }));
    }
  }

  class TestDatabase {
    public array $counts;
    public array $relations;

    public function __construct(array $counts, array $relations = []) {
      $this->counts = $counts;
      $this->relations = $relations;
    }

    public function select(string $table, string $alias): TestCountQuery {
      return new TestCountQuery($this->counts, $this->relations);
    }
  }

  class TestNode {
    private int $id;

    public function __construct(int $id) {
      $this->id = $id;
    }

    public function id(): int {
      return $this->id;
    }
  }

  class TestState {
    public array $values = [];

    public function get(string $key, $default = NULL) {
      return $this->values[$key] ?? $default;
    }

    public function set(string $key, $value): void {
      $this->values[$key] = $value;
    }

    public function delete(string $key): void {
      unset($this->values[$key]);
    }
  }

  class TestTime {
    public int $now = 1000;

    public function getRequestTime(): int {
      return $this->now;
    }
  }

  class TestModuleHandler {
    public bool $simpleSitemapEnabled = TRUE;

    public function moduleExists(string $module): bool {
      return $module === 'simple_sitemap' && $this->simpleSitemapEnabled;
    }
  }

  class TestQueueWorker {
    public bool $inProgress;

    public function __construct(bool $in_progress = FALSE) {
      $this->inProgress = $in_progress;
    }

    public function generationInProgress(): bool {
      return $this->inProgress;
    }
  }

  class TestGenerator3 {
    public array $calls = [];

    public function getQueueWorker(): TestQueueWorker {
      return new TestQueueWorker();
    }

    public function setVariants($variants): self {
      $this->calls[] = ['setVariants', $variants];
      return $this;
    }

    public function rebuildQueue(): self {
      $this->calls[] = ['rebuildQueue'];
      return $this;
    }

    public function generateSitemap(string $from): self {
      $this->calls[] = ['generateSitemap', $from];
      return $this;
    }
  }

  class TestGenerator4 {
    public array $calls = [];

    public function setSitemaps(): self {
      $this->calls[] = ['setSitemaps'];
      return $this;
    }

    public function rebuildQueue(): self {
      $this->calls[] = ['rebuildQueue'];
      return $this;
    }

    public function generate(string $from): self {
      $this->calls[] = ['generate', $from];
      return $this;
    }
  }

  class TestCompletingGenerator4 extends TestGenerator4 {
    private TestQueueWorker $queueWorker;

    public function __construct(TestQueueWorker $queue_worker) {
      $this->queueWorker = $queue_worker;
    }

    public function generate(string $from): self {
      parent::generate($from);
      $this->queueWorker->inProgress = FALSE;
      return $this;
    }
  }

  class TestLogger {
    public function warning(string $message, array $context = []): void {}
  }

  class Drupal {
    public static TestConfig $config;
    public static TestEntityTypeManager $entityTypeManager;
    public static TestDatabase $database;
    public static TestState $state;
    public static TestTime $time;
    public static TestModuleHandler $moduleHandler;
    public static array $services = [];

    public static function config(string $name): TestConfig {
      return self::$config;
    }

    public static function entityTypeManager(): TestEntityTypeManager {
      return self::$entityTypeManager;
    }

    public static function database(): TestDatabase {
      return self::$database;
    }

    public static function state(): TestState {
      return self::$state;
    }

    public static function time(): TestTime {
      return self::$time;
    }

    public static function moduleHandler(): TestModuleHandler {
      return self::$moduleHandler;
    }

    public static function hasService(string $name): bool {
      return array_key_exists($name, self::$services);
    }

    public static function service(string $name) {
      return self::$services[$name];
    }

    public static function logger(string $channel): TestLogger {
      return new TestLogger();
    }
  }

  $test_static = [];

  function &drupal_static(string $name, $default = NULL) {
    global $test_static;
    if (!array_key_exists($name, $test_static)) {
      $test_static[$name] = $default;
    }
    return $test_static[$name];
  }

  function drupal_static_reset(?string $name = NULL): void {
    global $test_static;
    if ($name === NULL) {
      $test_static = [];
    }
    else {
      unset($test_static[$name]);
    }
  }

  function ttd_sitemap_behavior_assert(bool $condition, string $message): void {
    if (!$condition) {
      fwrite(STDERR, "FAIL: {$message}\n");
      exit(1);
    }

    echo "PASS: {$message}\n";
  }

  require dirname(__DIR__, 2) . '/ttd_topics.module';

  ttd_sitemap_behavior_assert(
    ttd_topics_should_exclude_public_topic(FALSE, FALSE, TRUE, TRUE, FALSE),
    'Below-threshold Mention is excluded'
  );
  ttd_sitemap_behavior_assert(
    !ttd_topics_should_exclude_public_topic(FALSE, FALSE, TRUE, TRUE, TRUE),
    'Rendered Manual, Main, or About topic bypasses the threshold'
  );
  ttd_sitemap_behavior_assert(
    !ttd_topics_should_exclude_public_topic(FALSE, FALSE, FALSE, TRUE, TRUE),
    'Manual or promoted rendering bypasses curation suppression'
  );
  ttd_sitemap_behavior_assert(
    ttd_topics_should_exclude_public_topic(FALSE, TRUE, TRUE, TRUE, TRUE),
    'Hidden topic remains excluded'
  );
  ttd_sitemap_behavior_assert(
    !ttd_topics_should_exclude_public_topic(TRUE, FALSE, FALSE, TRUE, FALSE),
    'Force-show bypasses threshold and curation'
  );

  $terms = [
    1 => new \Drupal\taxonomy\Entity\Term(1, 'ttd_topics', ['field_hide' => 0, 'field_force_show' => 0, 'field_ttd_id' => 101]),
    2 => new \Drupal\taxonomy\Entity\Term(2, 'ttd_topics', ['field_hide' => 0, 'field_force_show' => 0, 'field_ttd_id' => 102]),
    3 => new \Drupal\taxonomy\Entity\Term(3, 'ttd_topics', ['field_hide' => 0, 'field_force_show' => 1, 'field_ttd_id' => 103]),
    4 => new \Drupal\taxonomy\Entity\Term(4, 'ttd_topics', ['field_hide' => 1, 'field_force_show' => 1, 'field_ttd_id' => 104]),
    5 => new \Drupal\taxonomy\Entity\Term(5, 'tags'),
  ];

  Drupal::$config = new TestConfig([
    'enable_frontend' => TRUE,
    'post_topic_minimum_display_count' => 2,
    'curation_scores_enabled' => FALSE,
  ]);
  Drupal::$entityTypeManager = new TestEntityTypeManager(new TestTermStorage($terms));
  Drupal::$database = new TestDatabase([1 => 1, 2 => 2, 3 => 1, 4 => 10, 5 => 1]);
  Drupal::$state = new TestState();
  Drupal::$time = new TestTime();
  Drupal::$moduleHandler = new TestModuleHandler();

  $links = [
    'low' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'bundle' => 'ttd_topics', 'id' => 1]],
    'enough' => ['meta' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'id' => 2]]],
    'forced' => ['meta' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'id' => 3]]],
    'hidden' => ['meta' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'id' => 4]]],
    'other-taxonomy' => ['meta' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'id' => 5]]],
    'node' => ['meta' => ['entity_info' => ['entity_type' => 'node', 'id' => 10]]],
  ];

  ttd_topics_simple_sitemap_links_alter($links, 'default');
  ttd_sitemap_behavior_assert(!isset($links['low']), 'Below-threshold topic is removed');
  ttd_sitemap_behavior_assert(isset($links['enough']), 'Topic meeting the threshold remains');
  ttd_sitemap_behavior_assert(isset($links['forced']), 'Force-show topic bypasses the threshold');
  ttd_sitemap_behavior_assert(!isset($links['hidden']), 'Explicitly hidden topic remains excluded even when force-show is set');
  ttd_sitemap_behavior_assert(isset($links['other-taxonomy']) && isset($links['node']), 'Unrelated sitemap links remain unchanged');

  Drupal::$config->values['enable_frontend'] = FALSE;
  $disabled_links = [
    'topic' => ['meta' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'id' => 2]]],
    'other-taxonomy' => ['meta' => ['entity_info' => ['entity_type' => 'taxonomy_term', 'id' => 5]]],
  ];
  ttd_topics_simple_sitemap_links_alter($disabled_links, 'default');
  ttd_sitemap_behavior_assert(!isset($disabled_links['topic']), 'Frontend-disabled topic is removed');
  ttd_sitemap_behavior_assert(isset($disabled_links['other-taxonomy']), 'Frontend setting does not affect other vocabularies');

  Drupal::$database = new TestDatabase(
    [1 => 1, 2 => 2],
    [(object) ['nid' => 10, 'tid' => 1], (object) ['nid' => 20, 'tid' => 2]]
  );
  Drupal::$entityTypeManager = new TestEntityTypeManager(
    new TestTermStorage($terms),
    new TestTermStorage([10 => new TestNode(10), 20 => new TestNode(20)])
  );
  $rendered_ids = ttd_topics_get_rendered_topic_ids_for_terms([1, 2], static function (TestNode $node) use ($terms) {
    return $node->id() === 10 ? [['term' => $terms[1]]] : [['term' => $terms[5]]];
  });
  ttd_sitemap_behavior_assert($rendered_ids === [1], 'Batched renderer keeps only candidates exposed on related nodes');

  ttd_topics_schedule_sitemap_refresh();
  $first_due = Drupal::$state->get(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE);
  ttd_topics_schedule_sitemap_refresh();
  ttd_sitemap_behavior_assert($first_due === 1060, 'First mutation schedules a 60-second refresh');
  ttd_sitemap_behavior_assert(Drupal::$state->get(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE) === $first_due, 'Repeated mutations reuse the first refresh marker');

  $generator3 = new TestGenerator3();
  Drupal::$services['simple_sitemap.generator'] = $generator3;
  Drupal::$time->now = 1060;
  ttd_sitemap_behavior_assert(ttd_topics_maybe_refresh_simple_sitemap(), 'Due refresh runs through the Simple XML Sitemap 3.x API');
  ttd_sitemap_behavior_assert(in_array(['generateSitemap', 'backend'], $generator3->calls, TRUE), '3.x generation uses bounded backend mode');
  ttd_sitemap_behavior_assert(Drupal::$state->get(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE) === NULL, 'Successful 3.x generation clears the marker');

  $generator4 = new TestGenerator4();
  Drupal::$services['simple_sitemap.generator'] = $generator4;
  Drupal::$state->set(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE, 1060);
  ttd_sitemap_behavior_assert(ttd_topics_maybe_refresh_simple_sitemap(), 'Due refresh runs through the Simple XML Sitemap 4.x API');
  ttd_sitemap_behavior_assert(in_array(['setSitemaps'], $generator4->calls, TRUE), '4.x selects all sitemap entities through setSitemaps');
  ttd_sitemap_behavior_assert(in_array(['generate', 'backend'], $generator4->calls, TRUE), '4.x generation uses bounded backend mode');

  $queue_worker = new TestQueueWorker(TRUE);
  $incomplete_generator = new TestGenerator4();
  Drupal::$services['simple_sitemap.generator'] = $incomplete_generator;
  Drupal::$services['simple_sitemap.queue_worker'] = $queue_worker;
  Drupal::$state->set(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE, 1060);
  ttd_sitemap_behavior_assert(!ttd_topics_maybe_refresh_simple_sitemap(), 'Incomplete 4.x generation remains scheduled');
  ttd_sitemap_behavior_assert(!in_array(['rebuildQueue'], $incomplete_generator->calls, TRUE), 'In-progress 4.x queue is not rebuilt');
  ttd_sitemap_behavior_assert(Drupal::$state->get(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE) === 1120, 'Incomplete generation schedules the next bounded pass');

  Drupal::$time->now = 1120;
  $completing_generator = new TestCompletingGenerator4($queue_worker);
  Drupal::$services['simple_sitemap.generator'] = $completing_generator;
  ttd_sitemap_behavior_assert(ttd_topics_maybe_refresh_simple_sitemap(), 'A later bounded pass completes the existing 4.x queue');
  ttd_sitemap_behavior_assert(!in_array(['rebuildQueue'], $completing_generator->calls, TRUE), 'Completing pass preserves the existing queue');
  ttd_sitemap_behavior_assert(Drupal::$state->get(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE) === NULL, 'Completed generation clears the refresh marker');
  unset(Drupal::$services['simple_sitemap.queue_worker']);

  echo "Sitemap eligibility behavior checks passed.\n";
}
