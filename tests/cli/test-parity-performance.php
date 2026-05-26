<?php

/**
 * Drupal CLI parity tests for WordPress performance/query-count contracts.
 *
 * Run from the Drupal site root with:
 *   drush scr web/modules/custom/topicalboost/tests/cli/test-parity-performance.php
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Database\Database;

$GLOBALS['assertions'] = 0;
$GLOBALS['failures'] = 0;
$GLOBALS['created_nodes'] = [];
$GLOBALS['created_terms'] = [];
$GLOBALS['created_ttd_ids'] = [];
$GLOBALS['created_schema_type_ids'] = [];

$original_config = \Drupal::config('ttd_topics.settings')->getRawData();
$suffix = time() . '_' . random_int(1000, 9999);
$base_ttd_id = 990000000 + random_int(10000, 90000);
$schema_type_names = ['Thing', 'Person', 'Organization', 'Place', 'CreativeWork', 'Product'];
$topic_count = 120;

function ttd_perf_pass(string $message): void {
  echo "PASS: {$message}\n";
}

function ttd_perf_fail(string $message): void {
  $GLOBALS['failures']++;
  echo "FAIL: {$message}\n";
}

function ttd_perf_assert(bool $condition, string $message): void {
  $GLOBALS['assertions']++;
  $condition ? ttd_perf_pass($message) : ttd_perf_fail($message);
}

function ttd_perf_insert_entity(int $ttd_id, string $name, int $schema_type_id, string $schema_type_name): void {
  $database = \Drupal::database();
  $now = date('Y-m-d H:i:s');

  $database->merge('ttd_entities')
    ->key('ttd_id', $ttd_id)
    ->fields([
      'createdAt' => $now,
      'updatedAt' => $now,
      'count' => 1,
      'hide' => 0,
      'name' => $name,
      'nl_name' => $name,
      'kg_description' => "Performance parity fixture for {$name}",
      'mid' => '/m/topicalboost_perf_' . $ttd_id,
    ])
    ->execute();

  $database->merge('ttd_schema_types')
    ->key('ttd_id', $schema_type_id)
    ->fields([
      'name' => $schema_type_name,
      'createdAt' => $now,
      'updatedAt' => $now,
    ])
    ->execute();

  $exists = $database->select('ttd_entity_schema_types', 'est')
    ->condition('entity_id', $ttd_id)
    ->condition('schema_type_id', $schema_type_id)
    ->countQuery()
    ->execute()
    ->fetchField();
  if (!$exists) {
    $database->insert('ttd_entity_schema_types')
      ->fields([
        'entity_id' => $ttd_id,
        'schema_type_id' => $schema_type_id,
        'createdAt' => $now,
        'updatedAt' => $now,
      ])
      ->execute();
  }

  $GLOBALS['created_ttd_ids'][] = $ttd_id;
  $GLOBALS['created_schema_type_ids'][] = $schema_type_id;
}

function ttd_perf_create_topic(string $name, int $ttd_id): Term {
  $term = Term::create([
    'vid' => 'ttd_topics',
    'name' => $name,
    'field_ttd_id' => (string) $ttd_id,
    'field_hide' => 0,
    'field_force_show' => 1,
  ]);
  $term->save();
  $GLOBALS['created_terms'][] = (int) $term->id();
  return $term;
}

function ttd_perf_create_node(string $title, array $term_ids): Node {
  $body = str_repeat('<p>TopicalBoost performance parity content about Drupal, WordPress, schema, analysis, and topic display.</p>', 80);
  $node = Node::create([
    'type' => 'article',
    'title' => $title,
    'body' => [
      'value' => $body,
      'format' => 'basic_html',
    ],
    'status' => 1,
  ]);
  $node->set('field_ttd_topics', array_values(array_map(static fn($tid) => ['target_id' => $tid], $term_ids)));
  $node->set('field_manual_topics', []);
  $node->set('field_ttd_rejected_topics', []);
  $node->set('field_tier_overrides', ['value' => []]);
  $node->save();
  $GLOBALS['created_nodes'][] = (int) $node->id();
  return $node;
}

function ttd_perf_set_salience(Node $node, int $ttd_id, string $tier, float $score): void {
  \Drupal::database()->merge('ttd_entity_post_ids')
    ->keys([
      'entity_id' => $ttd_id,
      'post_id' => (string) $node->id(),
    ])
    ->fields([
      'createdAt' => date('Y-m-d H:i:s'),
      'updatedAt' => date('Y-m-d H:i:s'),
      'salience_score' => $score,
      'salience_category' => $tier,
    ])
    ->execute();
}

function ttd_perf_schema_article(array $schema): array {
  foreach (($schema['@graph'] ?? []) as $item) {
    $type = $item['@type'] ?? NULL;
    $types = is_array($type) ? $type : [$type];
    if (array_intersect($types, ['Article', 'NewsArticle'])) {
      return $item;
    }
  }
  return [];
}

function ttd_perf_schema_topic_names(array $article): array {
  $names = [];
  foreach (['mainEntity', 'about', 'mentions'] as $key) {
    $items = $article[$key] ?? [];
    if (isset($items['name'])) {
      $names[] = $items['name'];
      continue;
    }
    foreach ((array) $items as $item) {
      if (is_array($item) && isset($item['name'])) {
        $names[] = $item['name'];
      }
    }
  }
  sort($names, SORT_NATURAL | SORT_FLAG_CASE);
  return $names;
}

function ttd_perf_query_count(array $queries, string $needle): int {
  $count = 0;
  foreach ($queries as $query) {
    $sql = is_array($query) ? ($query['query'] ?? reset($query)) : (string) $query;
    if (stripos((string) $sql, $needle) !== FALSE) {
      $count++;
    }
  }
  return $count;
}

function ttd_perf_schema_hot_query_count(array $queries): int {
  $tables = [
    'taxonomy_index',
    'path_alias',
    'ttd_entity_post_ids',
    'ttd_entities',
    'ttd_entity_schema_types',
    'ttd_schema_types',
  ];
  $count = 0;
  foreach ($queries as $query) {
    $sql = is_array($query) ? ($query['query'] ?? reset($query)) : (string) $query;
    $sql = preg_replace('/\s+/', ' ', trim((string) $sql));
    if (stripos($sql, 'information_schema') !== FALSE) {
      continue;
    }
    foreach ($tables as $table) {
      if (stripos($sql, $table) !== FALSE) {
        $count++;
        break;
      }
    }
  }

  return $count;
}

function ttd_perf_measure_schema(Node $node, string $label): array {
  $log_key = 'ttd_perf_' . preg_replace('/[^a-z0-9_]+/i', '_', $label) . '_' . random_int(1000, 9999);
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  Database::startLog($log_key);
  $start = microtime(TRUE);
  $schema = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($node->id());
  $elapsed_ms = (microtime(TRUE) - $start) * 1000;
  $queries = Database::getLog($log_key);

  return [
    'schema' => $schema,
    'elapsed_ms' => $elapsed_ms,
    'queries' => $queries,
  ];
}

echo "\n=== TopicalBoost Drupal Performance Parity Tests ===\n\n";

try {
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('post_topic_minimum_display_count', 0)
    ->save();

  $term_ids = [];
  $ttd_ids = [];
  for ($i = 0; $i < $topic_count; $i++) {
    $ttd_id = $base_ttd_id + $i;
    $schema_type_id = $base_ttd_id + 5000 + $i;
    $name = "TB Parity Perf Topic {$i} {$suffix}";
    $schema_type_name = $schema_type_names[$i % count($schema_type_names)];
    ttd_perf_insert_entity($ttd_id, $name, $schema_type_id, $schema_type_name);
    $term = ttd_perf_create_topic($name, $ttd_id);
    $term_ids[] = (int) $term->id();
    $ttd_ids[] = $ttd_id;
  }

  $node = ttd_perf_create_node("TB Parity Performance {$suffix}", $term_ids);
  foreach ($ttd_ids as $index => $ttd_id) {
    $tier = $index === 0 ? 'mainEntity' : ($index <= 4 ? 'about' : 'mentions');
    $score = max(0.001, 0.9 - ($index / 200));
    ttd_perf_set_salience($node, $ttd_id, $tier, $score);
  }

  $cold = ttd_perf_measure_schema($node, 'cold');
  $cold_article = ttd_perf_schema_article($cold['schema']);
  $cold_names = ttd_perf_schema_topic_names($cold_article);

  ttd_perf_assert(!empty($cold_article), 'Schema generation returns an Article graph item for a large topic set');
  ttd_perf_assert(count($cold_names) === $topic_count, "Schema includes every visible topic on a {$topic_count}-topic node");
  ttd_perf_assert(json_encode($cold['schema']) !== FALSE, 'Schema JSON-LD encodes successfully');

  $schema_types = [];
  foreach (['mainEntity', 'about', 'mentions'] as $key) {
    $items = $cold_article[$key] ?? [];
    if (isset($items['@type'])) {
      $items = [$items];
    }
    foreach ((array) $items as $item) {
      if (is_array($item) && isset($item['@type'])) {
        foreach ((array) $item['@type'] as $type) {
          $schema_types[$type] = TRUE;
        }
      }
    }
  }
  ttd_perf_assert(count($schema_types) >= 5, 'Large-post schema has diverse entity schema types');

  $run_names = [$cold_names];
  for ($i = 1; $i <= 2; $i++) {
    $repeat = ttd_perf_measure_schema($node, 'consistency_' . $i);
    $run_names[] = ttd_perf_schema_topic_names(ttd_perf_schema_article($repeat['schema']));
  }
  ttd_perf_assert($run_names[0] === $run_names[1] && $run_names[1] === $run_names[2], 'Repeated large-post schema generations return identical topic sets');

  $expected_chunks = (int) ceil($topic_count / 100);
  $entity_queries = ttd_perf_query_count($cold['queries'], 'ttd_entities');
  $schema_relation_queries = ttd_perf_query_count($cold['queries'], 'ttd_entity_schema_types');
  ttd_perf_assert($entity_queries <= $expected_chunks + 1, "Entity data queries are batched ({$entity_queries}, max " . ($expected_chunks + 1) . ')');
  ttd_perf_assert($schema_relation_queries <= $expected_chunks + 1, "Schema-type relation queries are batched ({$schema_relation_queries}, max " . ($expected_chunks + 1) . ')');
  $cold_hot_query_count = ttd_perf_schema_hot_query_count($cold['queries']);
  ttd_perf_assert($cold_hot_query_count <= 35, "Schema hot-path query count stays under the WordPress regression ceiling ({$cold_hot_query_count}, max 35)");
  ttd_perf_assert($cold['elapsed_ms'] <= 350, 'Cold schema/topic generation stays within the WordPress cold-cache budget');

  $warm_times = [];
  $warm_query_counts = [];
  for ($i = 0; $i < 5; $i++) {
    $warm = ttd_perf_measure_schema($node, 'warm_' . $i);
    $warm_times[] = $warm['elapsed_ms'];
    $warm_query_counts[] = count($warm['queries']);
  }
  sort($warm_times);
  $median_warm = $warm_times[(int) floor(count($warm_times) / 2)];
  ttd_perf_assert($median_warm <= 120, 'Warm schema/topic generation stays within the WordPress warm-cache budget');
  ttd_perf_assert(max($warm_query_counts) <= 35, 'Warm schema generation stays below query-count ceiling');

  $index_specs = [
    'ttd_entities' => ['PRIMARY'],
    'ttd_entity_post_ids' => ['ttd_epi_post_id', 'post_id'],
    'ttd_entity_schema_types' => ['entity_id'],
    'ttd_schema_types' => ['PRIMARY'],
  ];
  $schema = \Drupal::database()->schema();
  foreach ($index_specs as $table => $indexes) {
    if ($table === 'ttd_entity_post_ids') {
      $exists = FALSE;
      foreach ($indexes as $index_name) {
        if ($schema->indexExists($table, $index_name)) {
          $exists = TRUE;
          break;
        }
      }
      ttd_perf_assert($exists, "{$table}.post_id index exists for relationship lookups");
      continue;
    }

    foreach ($indexes as $index_name) {
      $exists = $index_name === 'PRIMARY'
        ? $schema->tableExists($table)
        : $schema->indexExists($table, $index_name);
      ttd_perf_assert($exists, "{$table}.{$index_name} index exists for schema hot paths");
    }
  }
}
finally {
  \Drupal::configFactory()->getEditable('ttd_topics.settings')->setData($original_config)->save();

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  foreach (array_reverse(array_unique($GLOBALS['created_nodes'])) as $nid) {
    if ($node = $node_storage->load($nid)) {
      $node->delete();
    }
  }

  $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  foreach (array_reverse(array_unique($GLOBALS['created_terms'])) as $tid) {
    if ($term = $term_storage->load($tid)) {
      $term->delete();
    }
  }

  $database = \Drupal::database();
  if (!empty($GLOBALS['created_ttd_ids'])) {
    $database->delete('ttd_entity_post_ids')
      ->condition('entity_id', array_unique($GLOBALS['created_ttd_ids']), 'IN')
      ->execute();
    $database->delete('ttd_entity_schema_types')
      ->condition('entity_id', array_unique($GLOBALS['created_ttd_ids']), 'IN')
      ->execute();
    $database->delete('ttd_entities')
      ->condition('ttd_id', array_unique($GLOBALS['created_ttd_ids']), 'IN')
      ->execute();
  }
  if (!empty($GLOBALS['created_schema_type_ids'])) {
    $database->delete('ttd_schema_types')
      ->condition('ttd_id', array_unique($GLOBALS['created_schema_type_ids']), 'IN')
      ->execute();
  }
  $database->delete('ttd_entities')
    ->condition('name', 'TB Parity Perf %', 'LIKE')
    ->execute();
  $fixture_tids = $database->select('taxonomy_term_field_data', 't')
    ->fields('t', ['tid'])
    ->condition('vid', 'ttd_topics')
    ->condition('name', 'TB Parity Perf %', 'LIKE')
    ->execute()
    ->fetchCol();
  if (!empty($fixture_tids)) {
    $term_storage->delete($term_storage->loadMultiple($fixture_tids));
  }
}

echo "\nAssertions: {$GLOBALS['assertions']}; Failures: {$GLOBALS['failures']}\n";
if ($GLOBALS['failures'] > 0) {
  throw new RuntimeException("TopicalBoost Drupal performance parity tests failed with {$GLOBALS['failures']} failure(s).");
}

echo "TopicalBoost Drupal performance parity tests passed.\n";
