<?php

/**
 * Compare real visible Drupal assignments with generated schema.
 * Run on an isolated database with TTD_SCHEMA_TEST_DATABASE set to its name.
 * Optional TTD_SCHEMA_TEST_SOURCE and TTD_SCHEMA_TEST_CLASS select a baseline.
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

$database = \Drupal::database();
$expected_database = getenv('TTD_SCHEMA_TEST_DATABASE');
if (!$expected_database || $database->getConnectionOptions()['database'] !== $expected_database) {
  throw new RuntimeException('Set TTD_SCHEMA_TEST_DATABASE to the isolated test database name.');
}
if ($source = getenv('TTD_SCHEMA_TEST_SOURCE')) {
  require_once $source;
}
$generator_class = getenv('TTD_SCHEMA_TEST_CLASS') ?: \Drupal\ttd_topics\SchemaGenerator::class;
$generator = new $generator_class($database, \Drupal::service('path_alias.manager'));
$fixture_config = \Drupal::configFactory()->getEditable('ttd_topics.settings');
$original_config = $fixture_config->getRawData();
$nodes = [];
$terms = [];
$entity_ids = [];
$assertions = 0;
$failures = 0;
$suffix = bin2hex(random_bytes(6));
$base_id = random_int(970100000, 979000000);
$fixture_ids = range($base_id, $base_id + 20);
if ($database->select('ttd_entities', 'e')->condition('ttd_id', $fixture_ids, 'IN')->countQuery()->execute()->fetchField()) {
  throw new RuntimeException('Fixture ID collision. Run again with a new fixture range.');
}
$assert = static function (bool $pass, string $message) use (&$assertions, &$failures): void {
  $assertions++;
  $failures += $pass ? 0 : 1;
  echo ($pass ? 'PASS: ' : 'FAIL: ') . $message . "\n";
};
$make_term = static function (string $label, ?int $id, bool $hidden = FALSE, bool $forced = FALSE) use (&$terms, $suffix): Term {
  $values = ['vid' => 'ttd_topics', 'name' => "$label $suffix", 'field_hide' => (int) $hidden, 'field_force_show' => (int) $forced];
  if ($id !== NULL) {
    $values['field_ttd_id'] = (string) $id;
  }
  $term = Term::create($values);
  $term->save();
  $terms[] = (int) $term->id();
  return $term;
};
$make_node = static function (array $topic_terms, array $manual_terms = [], array $rejected_terms = []) use (&$nodes, $suffix): Node {
  $refs = static fn(array $values) => array_map(static fn(Term $term) => ['target_id' => (int) $term->id()], $values);
  $node = Node::create([
    'type' => 'article', 'title' => "Schema fixture $suffix", 'status' => 1,
    'field_ttd_topics' => $refs($topic_terms), 'field_manual_topics' => $refs($manual_terms),
    'field_ttd_rejected_topics' => $refs($rejected_terms), 'field_ttd_last_analyzed' => time(),
  ]);
  $node->save();
  $nodes[] = (int) $node->id();
  return $node;
};
$make_entity = static function (int $id, array $values) use ($database, &$entity_ids): void {
  $entity_ids[] = $id;
  $database->insert('ttd_entities')->fields($values + [
    'ttd_id' => $id, 'createdAt' => date('Y-m-d H:i:s'), 'updatedAt' => date('Y-m-d H:i:s'), 'hide' => 0, 'count' => 1,
  ])->execute();
};
$article_from = static function (array $schema): array {
  foreach ($schema['@graph'] ?? [] as $item) {
    if (array_intersect((array) ($item['@type'] ?? []), ['Article', 'NewsArticle'])) {
      return $item;
    }
  }
  return [];
};
$report = [];

try {
  $fixture_config->set('post_topic_minimum_display_count', 2)->set('curation_scores_enabled', FALSE)
    ->set('new_topic_reanalysis_enabled', FALSE)->set('block_until_analyzed', FALSE)
    ->set('topic_url_mode', 'taxonomy_term')->save();
  $fixtures = [
    'manual_unmapped' => $make_term('Manual unmapped', NULL),
    'forced_unmapped' => $make_term('Forced unmapped', NULL, FALSE, TRUE),
    'count_unmapped' => $make_term('Count eligible unmapped', NULL),
    'missing_row' => $make_term('Missing entity row', $base_id),
    'no_external_id' => $make_term('No external ID', $base_id + 1),
    'google' => $make_term('Google control', $base_id + 2),
    'wikidata' => $make_term('Wikidata control', $base_id + 3),
    'invalid_google' => $make_term('Invalid Google with Wikidata', $base_id + 4),
    'hidden_unmapped' => $make_term('Hidden unmapped', NULL, TRUE),
    'rejected_unmapped' => $make_term('Rejected unmapped', NULL, FALSE, TRUE),
    'below_threshold' => $make_term('Below threshold unmapped', NULL),
    'hidden_mapped' => $make_term('Hidden mapped', $base_id + 5, TRUE),
    'rejected_mapped' => $make_term('Rejected mapped', $base_id + 6, FALSE, TRUE),
  ];
  $make_entity($base_id + 1, ['name' => 'Different database label']);
  $make_entity($base_id + 2, ['name' => $fixtures['google']->label(), 'mid' => '/m/schema_fixture_google']);
  $make_entity($base_id + 3, ['name' => $fixtures['wikidata']->label(), 'wb_qid' => 'Q12345']);
  $make_entity($base_id + 4, ['name' => $fixtures['invalid_google']->label(), 'mid' => 'invalid', 'wb_qid' => 'Q54321']);
  $make_entity($base_id + 5, ['name' => $fixtures['hidden_mapped']->label(), 'mid' => '/m/schema_fixture_hidden']);
  $make_entity($base_id + 6, ['name' => $fixtures['rejected_mapped']->label(), 'mid' => '/m/schema_fixture_rejected']);
  $make_node([$fixtures['count_unmapped']]);
  $node = $make_node(array_values($fixtures), [
    $fixtures['manual_unmapped'], $fixtures['hidden_unmapped'], $fixtures['google'], $fixtures['invalid_google'],
  ], [$fixtures['rejected_unmapped'], $fixtures['rejected_mapped']]);
  foreach ([$base_id => 'mainEntity', $base_id + 1 => 'about', $base_id + 3 => 'about'] as $id => $tier) {
    $database->insert('ttd_entity_post_ids')->fields([
      'entity_id' => $id, 'post_id' => (string) $node->id(), 'salience_category' => $tier, 'salience_score' => 0.2,
      'createdAt' => date('Y-m-d H:i:s'), 'updatedAt' => date('Y-m-d H:i:s'),
    ])->execute();
  }
  \ttd_topics_reset_runtime_caches();
  $visible = \ttd_topics_get_filtered_topics_for_node($node);
  $visible_ids = array_map(static fn(array $topic) => (int) $topic['term']->id(), $visible);
  $expected_visible_keys = ['manual_unmapped', 'forced_unmapped', 'count_unmapped', 'missing_row', 'no_external_id', 'google', 'wikidata', 'invalid_google'];
  $expected_visible_ids = array_map(static fn($key) => (int) $fixtures[$key]->id(), $expected_visible_keys);
  sort($visible_ids);
  sort($expected_visible_ids);
  $assert($visible_ids === $expected_visible_ids, 'Real frontend filter returns the eight eligible assignments');
  $schema = $generator->getNodeTopicsSchema($node->id());
  $article = $article_from($schema);
  $items_by_name = [];
  foreach (['about', 'mentions'] as $property) {
    foreach ($article[$property] ?? [] as $item) {
      $items_by_name[$item['name']] = $item + ['property' => $property];
    }
  }
  foreach ($expected_visible_keys as $key) {
    $term = $fixtures[$key];
    $item = $items_by_name[$term->label()] ?? [];
    $expected_id = match ($key) {
      'google' => 'kg:/m/schema_fixture_google',
      'wikidata' => 'https://www.wikidata.org/wiki/Q12345',
      'invalid_google' => 'https://www.wikidata.org/wiki/Q54321',
      default => \ttd_topics_get_topic_url($term, TRUE),
    };
    $pass = ($item['@id'] ?? NULL) === $expected_id;
    $assert($pass, "$key is present with the correct identity");
    $report[$key] = ['expected_id' => $expected_id, 'actual' => $item, 'pass' => $pass];
  }
  foreach (['hidden_unmapped', 'rejected_unmapped', 'below_threshold', 'hidden_mapped', 'rejected_mapped'] as $key) {
    $assert(!isset($items_by_name[$fixtures[$key]->label()]), "$key stays excluded from schema");
  }
  $assert(($items_by_name[$fixtures['missing_row']->label()]['property'] ?? '') === 'about', 'Missing entity row keeps its main topic in about');
  $assert(($items_by_name[$fixtures['no_external_id']->label()]['property'] ?? '') === 'about', 'Missing external ID keeps its about tier');
  $assert(($items_by_name[$fixtures['manual_unmapped']->label()]['property'] ?? '') === 'mentions', 'Unmapped manual topic defaults to mentions');
  $assert(($items_by_name[$fixtures['missing_row']->label()]['@type'] ?? '') === 'Thing', 'Missing entity row falls back to Thing');
  $assert($schema === $generator->getNodeTopicsSchema($node->id()), 'Repeated schema generation is stable');
  $assert(json_decode(json_encode($schema), TRUE) === $schema, 'Generated JSON-LD round trips');

  $reflection = new ReflectionMethod($generator_class, 'formatTopicsForSchema');
  $archive_topic = (object) [
    'tid' => $fixtures['manual_unmapped']->id(), 'term' => $fixtures['manual_unmapped'],
    'name' => $fixtures['manual_unmapped']->label(), 'field_ttd_id_value' => 0,
    'field_hide_value' => 0, 'alias' => '/taxonomy/term/' . $fixtures['manual_unmapped']->id(),
  ];
  $fixture_config->set('topic_url_mode', 'archive_query')->set('topic_archive_path', '/research')
    ->set('topic_archive_query_parameter', 'topic')->set('topic_archive_value_source', 'term_id')->save();
  $archive_items = $reflection->invoke($generator, [$archive_topic], \Drupal::request()->getSchemeAndHttpHost(), [], []);
  $assert(($archive_items[0]['@id'] ?? '') === \ttd_topics_get_topic_url($fixtures['manual_unmapped'], TRUE), 'Fallback identity follows the configured public archive link');

  $fixture_config->set('topic_url_mode', 'taxonomy_term')->save();
  $many_topics = [];
  for ($index = 0; $index < 120; $index++) {
    $many_topics[] = $make_term("Large article topic $index", NULL);
  }
  $large_node = $make_node($many_topics, $many_topics);
  \ttd_topics_reset_runtime_caches();
  \Drupal\Core\Database\Database::startLog('schema_missing_ids');
  $started = microtime(TRUE);
  $large_schema = $generator->getNodeTopicsSchema($large_node->id());
  $elapsed_ms = round((microtime(TRUE) - $started) * 1000, 2);
  $queries = \Drupal\Core\Database\Database::getLog('schema_missing_ids');
  $large_article = $article_from($large_schema);
  $large_items = array_merge($large_article['about'] ?? [], $large_article['mentions'] ?? []);
  $large_identities = array_column($large_items, '@id');
  $assert(count($large_items) === 120 && count(array_unique($large_identities)) === 120, 'Large article keeps all 120 unmapped topics with distinct identities');
  $assert(count($queries) <= 35, 'Large article schema stays within the existing 35-query budget');
  $assert($large_schema === $generator->getNodeTopicsSchema($large_node->id()), 'Large article schema is stable on repeat');
  $report['large_article'] = ['visible_topics' => 120, 'schema_topics' => count($large_items), 'unique_identities' => count(array_unique($large_identities)), 'queries' => count($queries), 'elapsed_ms' => $elapsed_ms];
}
finally {
  foreach (array_reverse($nodes) as $id) {
    if ($node = Node::load($id)) {
      $node->delete();
    }
  }
  foreach (array_reverse($terms) as $id) {
    if ($term = Term::load($id)) {
      $term->delete();
    }
  }
  $database->delete('ttd_entity_post_ids')->condition('entity_id', $fixture_ids, 'IN')->execute();
  if ($entity_ids) {
    $database->delete('ttd_entities')->condition('ttd_id', $entity_ids, 'IN')->execute();
  }
  $fixture_config->setData($original_config)->save();
  \ttd_topics_reset_runtime_caches();
}
$assert((int) $database->select('taxonomy_term_field_data', 't')->condition('tid', $terms, 'IN')->countQuery()->execute()->fetchField() === 0, 'Temporary topics are removed');
$assert((int) $database->select('node_field_data', 'n')->condition('nid', $nodes, 'IN')->countQuery()->execute()->fetchField() === 0, 'Temporary articles are removed');
echo "Assertions: $assertions; failures: $failures\n";
if ($output = getenv('TTD_SCHEMA_TEST_REPORT')) {
  file_put_contents($output, json_encode(['assertions' => $assertions, 'failures' => $failures, 'cases' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
if ($failures) {
  throw new RuntimeException("Schema runtime checks failed with $failures failures.");
}
