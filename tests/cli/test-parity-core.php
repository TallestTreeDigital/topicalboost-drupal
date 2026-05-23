<?php

/**
 * Drupal CLI parity tests for core TopicalBoost topic behavior.
 *
 * Run from the Drupal site root with:
 *   drush scr web/modules/custom/topicalboost/tests/cli/test-parity-core.php
 *
 * This mirrors the important WordPress CLI tests without calling the API:
 * threshold filtering, manual topics, force-show, rejected/hidden topics,
 * tier overrides, and schema output.
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\file\Entity\File;

$GLOBALS['assertions'] = 0;
$GLOBALS['failures'] = 0;
$GLOBALS['created_nodes'] = [];
$GLOBALS['created_terms'] = [];
$GLOBALS['created_files'] = [];
$GLOBALS['created_ttd_ids'] = [];
$GLOBALS['created_schema_type_ids'] = [];
$original_threshold = \Drupal::config('ttd_topics.settings')->get('post_topic_minimum_display_count');
$suffix = time() . '_' . random_int(1000, 9999);
$base_ttd_id = 970000000 + random_int(10000, 90000);
$schema_type_id = $base_ttd_id + 9000;

function ttd_parity_pass(string $message): void {
  echo "PASS: {$message}\n";
}

function ttd_parity_fail(string $message): void {
  global $failures;
  $failures++;
  echo "FAIL: {$message}\n";
}

function ttd_parity_assert(bool $condition, string $message): void {
  global $assertions;
  $GLOBALS['assertions']++;
  if ($condition) {
    ttd_parity_pass($message);
  }
  else {
    ttd_parity_fail($message);
  }
}

function ttd_parity_require_field(string $entity_type, string $bundle, string $field_name): void {
  $exists = (bool) \Drupal\field\Entity\FieldConfig::loadByName($entity_type, $bundle, $field_name);
  ttd_parity_assert($exists, "{$entity_type}.{$bundle}.{$field_name} exists");
  if (!$exists) {
    throw new RuntimeException("Missing required field {$field_name}");
  }
}

function ttd_parity_insert_entity(int $ttd_id, string $name, int $schema_type_id): void {
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
      'kg_description' => "Parity fixture for {$name}",
      'mid' => '/m/topicalboost_parity_' . $ttd_id,
    ])
    ->execute();

  $database->merge('ttd_schema_types')
    ->key('ttd_id', $schema_type_id)
    ->fields([
      'name' => 'Thing',
      'createdAt' => $now,
      'updatedAt' => $now,
    ])
    ->execute();

  $database->insert('ttd_entity_schema_types')
    ->fields([
      'entity_id' => $ttd_id,
      'schema_type_id' => $schema_type_id,
      'createdAt' => $now,
      'updatedAt' => $now,
    ])
    ->execute();

  $GLOBALS['created_ttd_ids'][] = $ttd_id;
  $GLOBALS['created_schema_type_ids'][] = $schema_type_id;
}

function ttd_parity_create_topic(string $name, int $ttd_id, array $values = []): Term {
  $term = Term::create([
    'vid' => 'ttd_topics',
    'name' => $name,
    'field_ttd_id' => (string) $ttd_id,
    'field_hide' => !empty($values['hide']) ? 1 : 0,
    'field_force_show' => !empty($values['force_show']) ? 1 : 0,
  ]);
  $term->save();
  $GLOBALS['created_terms'][] = (int) $term->id();

  return $term;
}

function ttd_parity_create_node(string $title, array $term_ids, array $manual_ids = [], array $rejected_ids = [], array $overrides = []): Node {
  $node = Node::create([
    'type' => 'article',
    'title' => $title,
    'body' => [
      'value' => '<p>TopicalBoost parity fixture content.</p>',
      'format' => 'basic_html',
    ],
    'status' => 1,
  ]);
  $node->set('field_ttd_topics', array_values(array_map(static fn($tid) => ['target_id' => $tid], $term_ids)));
  $node->set('field_manual_topics', array_values(array_map(static fn($tid) => ['target_id' => $tid], $manual_ids)));
  $node->set('field_ttd_rejected_topics', array_values(array_map(static fn($tid) => ['target_id' => $tid], $rejected_ids)));
  $node->set('field_tier_overrides', ['value' => $overrides]);
  $node->save();
  $GLOBALS['created_nodes'][] = (int) $node->id();

  return $node;
}

function ttd_parity_set_salience(Node $node, int $ttd_id, ?string $tier, ?float $score): void {
  \Drupal::database()->insert('ttd_entity_post_ids')
    ->fields([
      'entity_id' => $ttd_id,
      'post_id' => (string) $node->id(),
      'createdAt' => date('Y-m-d H:i:s'),
      'updatedAt' => date('Y-m-d H:i:s'),
      'salience_score' => $score,
      'salience_category' => $tier,
    ])
    ->execute();
}

function ttd_parity_topic_names(array $filtered_topics): array {
  $names = array_map(static fn($item) => $item['term']->label(), $filtered_topics);
  sort($names, SORT_NATURAL | SORT_FLAG_CASE);
  return $names;
}

function ttd_parity_schema_article(array $schema): array {
  foreach (($schema['@graph'] ?? []) as $item) {
    $type = $item['@type'] ?? NULL;
    $types = is_array($type) ? $type : [$type];
    if (array_intersect($types, ['Article', 'NewsArticle'])) {
      return $item;
    }
  }
  return [];
}

function ttd_parity_schema_names($items): array {
  if (empty($items)) {
    return [];
  }
  if (isset($items['name'])) {
    return [$items['name']];
  }
  $names = [];
  foreach ((array) $items as $item) {
    if (is_array($item) && isset($item['name'])) {
      $names[] = $item['name'];
    }
  }
  sort($names, SORT_NATURAL | SORT_FLAG_CASE);
  return $names;
}

function ttd_parity_schema_names_in_order($items): array {
  if (empty($items)) {
    return [];
  }
  if (isset($items['name'])) {
    return [$items['name']];
  }
  $names = [];
  foreach ((array) $items as $item) {
    if (is_array($item) && isset($item['name'])) {
      $names[] = $item['name'];
    }
  }
  return $names;
}

function ttd_parity_schema_images(array $article): array {
  $images = $article['image'] ?? [];
  if (empty($images)) {
    return [];
  }
  if (isset($images['url'])) {
    return [$images];
  }
  return array_values(array_filter((array) $images, 'is_array'));
}

function ttd_parity_create_schema_image_file(string $filename): File {
  $directory = 'public://topicalboost-parity-schema';
  \Drupal::service('file_system')->prepareDirectory(
    $directory,
    \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS
  );

  $uri = $directory . '/' . $filename;
  $png_1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=');
  file_put_contents($uri, $png_1x1);

  $file = File::create([
    'uri' => $uri,
    'filename' => $filename,
    'status' => 1,
  ]);
  $file->save();
  $GLOBALS['created_files'][] = (int) $file->id();

  return $file;
}

function ttd_parity_extract_meta_preview(string $content): string {
  $controller = new \Drupal\ttd_topics\Controller\MetaGeneratorController();
  $reflection = new ReflectionClass($controller);
  $method = $reflection->getMethod('getCleanContentPreview');
  $method->setAccessible(TRUE);
  return $method->invoke($controller, $content);
}

function ttd_parity_invoke_private_method(string $class, string $method_name, array $arguments): mixed {
  $reflection = new ReflectionClass($class);
  $instance = $reflection->newInstanceWithoutConstructor();
  $method = $reflection->getMethod($method_name);
  $method->setAccessible(TRUE);
  return $method->invokeArgs($instance, $arguments);
}

function ttd_parity_update_topic_tier(Node $node, Term $term, string $new_tier): array {
  $term_id = (int) $term->id();
  $ttd_id = (int) $term->get('field_ttd_id')->value;

  \Drupal::state()->set('ttd_demand_' . $term_id, [
    'timestamp' => time(),
    'data' => [
      'keyword_difficulty' => 0,
      'traffic_potential' => 0,
    ],
  ]);

  $request = new \Symfony\Component\HttpFoundation\Request(
    [],
    [],
    [],
    [],
    [],
    [],
    json_encode([
      'node_id' => (int) $node->id(),
      'term_id' => $term_id,
      'ttd_id' => $ttd_id,
      'new_tier' => $new_tier,
    ])
  );

  $response = (new \Drupal\ttd_topics\Controller\TtdTopicsController())->updateTopicTier($request);
  $data = json_decode($response->getContent(), TRUE);
  ttd_parity_assert(($data['success'] ?? FALSE) === TRUE, "Controller accepts tier update to {$new_tier}");

  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  return $data ?: [];
}

function ttd_parity_rejected_term_ids(Node $node): array {
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $node = Node::load($node->id());
  return array_map('intval', array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id'));
}

echo "\n=== TopicalBoost Drupal Core Parity Tests ===\n\n";

try {
  ttd_parity_require_field('node', 'article', 'field_ttd_topics');
  ttd_parity_require_field('node', 'article', 'field_manual_topics');
  ttd_parity_require_field('node', 'article', 'field_ttd_rejected_topics');
  ttd_parity_require_field('node', 'article', 'field_tier_overrides');
  ttd_parity_require_field('node', 'article', 'field_ttd_schema_16x9');
  ttd_parity_require_field('node', 'article', 'field_ttd_schema_4x3');
  ttd_parity_require_field('node', 'article', 'field_ttd_schema_1x1');
  ttd_parity_require_field('node', 'article', 'field_ttd_schema_focal_point');
  ttd_parity_require_field('taxonomy_term', 'ttd_topics', 'field_ttd_id');
  ttd_parity_require_field('taxonomy_term', 'ttd_topics', 'field_hide');
  ttd_parity_require_field('taxonomy_term', 'ttd_topics', 'field_force_show');

  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('post_topic_minimum_display_count', 2)
    ->save();

  $topics = [];
  $fixtures = [
    'regular_low' => ['Regular Low', []],
    'regular_above' => ['Regular Above', []],
    'manual_low' => ['Manual Low', []],
    'force_low' => ['Force Low', ['force_show' => TRUE]],
    'about_low' => ['About Low', []],
    'hidden_about' => ['Hidden About', ['hide' => TRUE]],
    'rejected_about' => ['Rejected About', []],
    'override_main' => ['Override Main', []],
  ];

  $i = 0;
  foreach ($fixtures as $key => [$label, $values]) {
    $ttd_id = $base_ttd_id + $i++;
    $name = "TB Parity {$label} {$suffix}";
    ttd_parity_insert_entity($ttd_id, $name, $schema_type_id);
    $topics[$key] = [
      'name' => $name,
      'ttd_id' => $ttd_id,
      'term' => ttd_parity_create_topic($name, $ttd_id, $values),
    ];
  }

  // Make one topic meet threshold by attaching it to another published node.
  ttd_parity_create_node(
    "TB Parity Count Helper {$suffix}",
    [(int) $topics['regular_above']['term']->id()]
  );

  $main_node = ttd_parity_create_node(
    "TB Parity Main {$suffix}",
    array_map(static fn($topic) => (int) $topic['term']->id(), $topics),
    [(int) $topics['manual_low']['term']->id()],
    [(int) $topics['rejected_about']['term']->id()],
    [(string) $topics['override_main']['ttd_id'] => 'mainEntity']
  );

  ttd_parity_set_salience($main_node, $topics['regular_low']['ttd_id'], 'mentions', 0.01);
  ttd_parity_set_salience($main_node, $topics['regular_above']['ttd_id'], 'mentions', 0.02);
  ttd_parity_set_salience($main_node, $topics['manual_low']['ttd_id'], 'mentions', 0.01);
  ttd_parity_set_salience($main_node, $topics['force_low']['ttd_id'], 'mentions', 0.01);
  ttd_parity_set_salience($main_node, $topics['about_low']['ttd_id'], 'about', 0.12);
  ttd_parity_set_salience($main_node, $topics['hidden_about']['ttd_id'], 'about', 0.15);
  ttd_parity_set_salience($main_node, $topics['rejected_about']['ttd_id'], 'about', 0.13);
  ttd_parity_set_salience($main_node, $topics['override_main']['ttd_id'], 'mentions', 0.01);

  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$main_node->id()]);
  $main_node = Node::load($main_node->id());

  ttd_parity_assert($main_node->hasField('field_ttd_topics'), 'Fixture node has field_ttd_topics');
  ttd_parity_assert($main_node->get('field_ttd_topics')->count() === count($topics), 'Fixture node stores all topic references');
  if ($main_node->get('field_ttd_topics')->count() !== count($topics)) {
    echo "Stored topic ref count: " . $main_node->get('field_ttd_topics')->count() . "\n";
  }

  $visible_names = ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($main_node));
  $expected_visible = [
    $topics['about_low']['name'],
    $topics['force_low']['name'],
    $topics['manual_low']['name'],
    $topics['override_main']['name'],
    $topics['regular_above']['name'],
  ];
  sort($expected_visible, SORT_NATURAL | SORT_FLAG_CASE);

  if ($visible_names !== $expected_visible) {
    echo "Expected visible: " . implode(', ', $expected_visible) . "\n";
    echo "Actual visible:   " . implode(', ', $visible_names) . "\n";
  }
  ttd_parity_assert($visible_names === $expected_visible, 'Frontend filtering matches WordPress visibility rules');
  ttd_parity_assert(!in_array($topics['regular_low']['name'], $visible_names, TRUE), 'Below-threshold regular mention is hidden');
  ttd_parity_assert(!in_array($topics['hidden_about']['name'], $visible_names, TRUE), 'Hidden topic is excluded even when high-salience');
  ttd_parity_assert(!in_array($topics['rejected_about']['name'], $visible_names, TRUE), 'Rejected topic is excluded even when high-salience');
  ttd_parity_assert(ttd_get_topic_tier((int) $topics['override_main']['term']->id(), (int) $main_node->id()) === 'mainEntity', 'Tier override wins over API salience');

  $schema = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($main_node->id());
  $article = ttd_parity_schema_article($schema);

  if (empty($article)) {
    echo "Schema graph item types: ";
    echo implode(', ', array_map(static function ($item) {
      $type = $item['@type'] ?? '(none)';
      return is_array($type) ? implode('|', $type) : $type;
    }, $schema['@graph'] ?? []));
    echo "\n";
  }
  ttd_parity_assert(!empty($article), 'Schema contains Article graph item');
  ttd_parity_assert(($article['mainEntity']['name'] ?? NULL) === $topics['override_main']['name'], 'Schema mainEntity uses overridden topic');

  $schema_names = array_merge(
    ttd_parity_schema_names($article['about'] ?? []),
    ttd_parity_schema_names($article['mentions'] ?? [])
  );

  foreach ([$topics['manual_low'], $topics['force_low'], $topics['about_low'], $topics['regular_above']] as $topic) {
    ttd_parity_assert(in_array($topic['name'], $schema_names, TRUE), "Schema includes visible topic {$topic['name']}");
  }

  foreach ([$topics['regular_low'], $topics['hidden_about'], $topics['rejected_about']] as $topic) {
    ttd_parity_assert(!in_array($topic['name'], $schema_names, TRUE), "Schema excludes non-visible topic {$topic['name']}");
  }

  $order_high_ttd_id = $base_ttd_id + $i++;
  $order_high_name = "TB Parity About High Score {$suffix}";
  ttd_parity_insert_entity($order_high_ttd_id, $order_high_name, $schema_type_id);
  $order_high_term = ttd_parity_create_topic($order_high_name, $order_high_ttd_id);

  $order_low_ttd_id = $base_ttd_id + $i++;
  $order_low_name = "TB Parity About Low Score High Count {$suffix}";
  ttd_parity_insert_entity($order_low_ttd_id, $order_low_name, $schema_type_id);
  $order_low_term = ttd_parity_create_topic($order_low_name, $order_low_ttd_id);

  ttd_parity_create_node(
    "TB Parity Schema Order Count Helper {$suffix}",
    [(int) $order_low_term->id()]
  );
  $order_node = ttd_parity_create_node(
    "TB Parity Schema Order {$suffix}",
    [(int) $order_low_term->id(), (int) $order_high_term->id()]
  );
  ttd_parity_set_salience($order_node, $order_high_ttd_id, 'about', 0.20);
  ttd_parity_set_salience($order_node, $order_low_ttd_id, 'about', 0.06);

  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$order_node->id()]);
  $order_node = Node::load($order_node->id());
  $frontend_about_order = array_values(array_map(
    static fn($topic_data) => $topic_data['term']->label(),
    array_filter(
      ttd_topics_get_filtered_topics_for_node($order_node),
      static fn($topic_data) => ($topic_data['salience_category'] ?? 'mentions') === 'about'
    )
  ));
  $order_schema = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($order_node->id());
  $order_article = ttd_parity_schema_article($order_schema);
  $schema_about_order = ttd_parity_schema_names_in_order($order_article['about'] ?? []);
  if ($schema_about_order !== $frontend_about_order) {
    echo "Expected schema about order: " . implode(', ', $frontend_about_order) . "\n";
    echo "Actual schema about order:   " . implode(', ', $schema_about_order) . "\n";
  }
  ttd_parity_assert($schema_about_order === $frontend_about_order, 'Schema about order follows frontend topic order like WordPress');

  $computed_tiers = ttd_topics_compute_tiers([
    ['entity_id' => 1, 'salience_score' => 0.45, 'llm_tier' => NULL],
    ['entity_id' => 2, 'salience_score' => 0.05, 'llm_tier' => NULL],
    ['entity_id' => 3, 'salience_score' => 0.04, 'llm_tier' => NULL],
  ]);
  ttd_parity_assert(($computed_tiers[1] ?? NULL) === 'mainEntity', 'Salience tier computation matches WordPress mainEntity threshold');
  ttd_parity_assert(($computed_tiers[2] ?? NULL) === 'about', 'Salience tier computation matches WordPress about boundary');
  ttd_parity_assert(($computed_tiers[3] ?? NULL) === 'mentions', 'Salience tier computation demotes low salience to mentions');

  $llm_tiers = ttd_topics_compute_tiers([
    ['entity_id' => 10, 'salience_score' => 0.01, 'llm_tier' => 'mainEntity'],
    ['entity_id' => 11, 'salience_score' => 0.50, 'llm_tier' => 'mainEntity'],
    ['entity_id' => 12, 'salience_score' => 0.40, 'llm_tier' => 'about'],
    ['entity_id' => 13, 'salience_score' => 0.30, 'llm_tier' => 'about'],
    ['entity_id' => 14, 'salience_score' => 0.20, 'llm_tier' => 'about'],
    ['entity_id' => 15, 'salience_score' => 0.10, 'llm_tier' => 'about'],
    ['entity_id' => 16, 'salience_score' => 0.09, 'llm_tier' => 'about'],
  ]);
  ttd_parity_assert(($llm_tiers[10] ?? NULL) === 'mainEntity', 'LLM tier computation keeps first mainEntity');
  ttd_parity_assert(($llm_tiers[11] ?? NULL) === 'about', 'LLM tier computation demotes extra mainEntity to about');
  ttd_parity_assert(count(array_filter($llm_tiers, static fn($tier) => $tier === 'about')) === 4, 'LLM tier computation caps about topics at four');
  ttd_parity_assert(($llm_tiers[16] ?? NULL) === 'mentions', 'LLM tier computation demotes overflow about to mentions');

  $override_tiers = ttd_topics_compute_tiers([
    ['entity_id' => 400, 'salience_score' => 0.50, 'llm_tier' => NULL],
    ['entity_id' => 401, 'salience_score' => 0.02, 'llm_tier' => NULL],
    ['entity_id' => 402, 'salience_score' => 0.01, 'llm_tier' => NULL],
  ], [401 => 'mainEntity']);
  ttd_parity_assert(($override_tiers[401] ?? NULL) === 'mainEntity', 'Salience override promotes low-salience topic to mainEntity');
  ttd_parity_assert(($override_tiers[400] ?? NULL) === 'about', 'Salience natural mainEntity demotes when override takes main slot');
  ttd_parity_assert(($override_tiers[402] ?? NULL) === 'mentions', 'Salience override does not disturb remaining mentions');

  $llm_override_tiers = ttd_topics_compute_tiers([
    ['entity_id' => 500, 'salience_score' => 0.00, 'llm_tier' => 'mainEntity'],
    ['entity_id' => 501, 'salience_score' => 0.00, 'llm_tier' => 'mentions'],
    ['entity_id' => 502, 'salience_score' => 0.00, 'llm_tier' => 'about'],
  ], [501 => 'mainEntity']);
  ttd_parity_assert(($llm_override_tiers[501] ?? NULL) === 'mainEntity', 'LLM override promotes mentions topic to mainEntity');
  ttd_parity_assert(($llm_override_tiers[500] ?? NULL) === 'about', 'LLM natural mainEntity demotes when override takes main slot');
  ttd_parity_assert(($llm_override_tiers[502] ?? NULL) === 'about', 'LLM about topic remains about after override');

  $old_api_tiers = ttd_topics_compute_tiers([
    ['entity_id' => 600, 'salience_score' => 0.20],
    ['entity_id' => 601, 'salience_score' => 0.08],
    ['entity_id' => 602, 'salience_score' => 0.02],
  ]);
  ttd_parity_assert(($old_api_tiers[600] ?? NULL) === 'mainEntity', 'Old API rows without llm_tier use salience mainEntity fallback');
  ttd_parity_assert(($old_api_tiers[601] ?? NULL) === 'about', 'Old API rows without llm_tier use salience about fallback');
  ttd_parity_assert(($old_api_tiers[602] ?? NULL) === 'mentions', 'Old API rows without llm_tier use salience mentions fallback');

  $mixed_tiers = ttd_topics_compute_tiers([
    ['entity_id' => 700, 'salience_score' => 0.001, 'llm_tier' => 'mainEntity'],
    ['entity_id' => 701, 'salience_score' => 0.50, 'llm_tier' => NULL],
    ['entity_id' => 702, 'salience_score' => 0.30],
  ]);
  ttd_parity_assert(($mixed_tiers[700] ?? NULL) === 'mainEntity', 'Mixed LLM rows keep explicit mainEntity');
  ttd_parity_assert(($mixed_tiers[701] ?? NULL) === 'mentions', 'Mixed LLM rows treat null llm_tier as mentions');
  ttd_parity_assert(($mixed_tiers[702] ?? NULL) === 'mentions', 'Mixed LLM rows treat missing llm_tier as mentions');
  ttd_parity_assert(ttd_topics_compute_tiers([]) === [], 'Empty tier computation returns empty map');

  $stale_cleared = ttd_topics_clear_stale_tier_overrides([
    482 => 'mentions',
    100 => 'about',
  ], [
    482 => ['salience_score' => 0.001, 'llm_tier' => 'mainEntity'],
    100 => ['salience_score' => 0.05, 'llm_tier' => 'about'],
    200 => ['salience_score' => 0.03, 'llm_tier' => 'mentions'],
  ]);
  ttd_parity_assert(!isset($stale_cleared[482]) && !isset($stale_cleared[100]), 'Fresh LLM tiers clear stale matching overrides');

  $stale_preserved = ttd_topics_clear_stale_tier_overrides([
    482 => 'mainEntity',
    100 => 'about',
  ], [
    482 => ['salience_score' => 0.50, 'llm_tier' => NULL],
    100 => ['salience_score' => 0.08, 'llm_tier' => NULL],
  ]);
  ttd_parity_assert(($stale_preserved[482] ?? NULL) === 'mainEntity' && ($stale_preserved[100] ?? NULL) === 'about', 'NLP salience rows preserve existing overrides');

  $stale_partial = ttd_topics_clear_stale_tier_overrides([
    482 => 'mentions',
    999 => 'about',
  ], [
    482 => ['salience_score' => 0.001, 'llm_tier' => 'mainEntity'],
    100 => ['salience_score' => 0.05, 'llm_tier' => 'about'],
  ]);
  ttd_parity_assert(!isset($stale_partial[482]) && ($stale_partial[999] ?? NULL) === 'about', 'Fresh LLM tiers only clear overrides for entities in the new result map');
  ttd_parity_assert(ttd_topics_clear_stale_tier_overrides([], [482 => ['llm_tier' => 'mainEntity']]) === [], 'Empty stale override map remains empty');

  $computed_topics = [];
  foreach ([
    'computed_main' => ['Computed Main', 0.12],
    'computed_about' => ['Computed About', 0.05],
    'computed_mention' => ['Computed Mention', 0.04],
  ] as $key => [$label, $score]) {
    $ttd_id = $base_ttd_id + $i++;
    $name = "TB Parity {$label} {$suffix}";
    ttd_parity_insert_entity($ttd_id, $name, $schema_type_id);
    $computed_topics[$key] = [
      'name' => $name,
      'ttd_id' => $ttd_id,
      'term' => ttd_parity_create_topic($name, $ttd_id),
      'score' => $score,
    ];
  }

  $computed_node = ttd_parity_create_node(
    "TB Parity Computed Tiers {$suffix}",
    array_map(static fn($topic) => (int) $topic['term']->id(), $computed_topics)
  );
  foreach ($computed_topics as $topic) {
    ttd_parity_set_salience($computed_node, $topic['ttd_id'], NULL, $topic['score']);
  }

  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$computed_node->id()]);
  $computed_node = Node::load($computed_node->id());
  ttd_parity_assert(ttd_get_topic_tier((int) $computed_topics['computed_main']['term']->id(), (int) $computed_node->id()) === 'mainEntity', 'Drupal computes mainEntity from salience-only rows like WordPress');
  ttd_parity_assert(ttd_get_topic_tier((int) $computed_topics['computed_about']['term']->id(), (int) $computed_node->id()) === 'about', 'Drupal computes about from salience-only rows like WordPress');
  ttd_parity_assert(ttd_get_topic_tier((int) $computed_topics['computed_mention']['term']->id(), (int) $computed_node->id()) === 'mentions', 'Drupal computes mentions from salience-only rows like WordPress');

  $computed_visible_names = ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($computed_node));
  ttd_parity_assert(in_array($computed_topics['computed_main']['name'], $computed_visible_names, TRUE), 'Computed mainEntity bypasses frontend count threshold');
  ttd_parity_assert(in_array($computed_topics['computed_about']['name'], $computed_visible_names, TRUE), 'Computed about bypasses frontend count threshold');
  ttd_parity_assert(!in_array($computed_topics['computed_mention']['name'], $computed_visible_names, TRUE), 'Computed mention still respects frontend count threshold');

  $computed_node->set('field_tier_overrides', ['value' => [(string) $computed_topics['computed_about']['ttd_id'] => 'mentions']]);
  $computed_node->save();
  $computed_visible_names = ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($computed_node));
  ttd_parity_assert(!in_array($computed_topics['computed_about']['name'], $computed_visible_names, TRUE), 'Override to mentions makes below-threshold API-about topic hidden');

  $computed_node->set('field_ttd_rejected_topics', [['target_id' => (int) $computed_topics['computed_about']['term']->id()]]);
  $computed_node->set('field_tier_overrides', ['value' => [(string) $computed_topics['computed_about']['ttd_id'] => 'about']]);
  $computed_node->save();
  $computed_visible_names = ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($computed_node));
  ttd_parity_assert(!in_array($computed_topics['computed_about']['name'], $computed_visible_names, TRUE), 'Rejected topic stays hidden even with about override');

  $computed_node->set('field_ttd_rejected_topics', []);
  $computed_node->set('field_tier_overrides', ['value' => [(string) $computed_topics['computed_mention']['ttd_id'] => 'about']]);
  $computed_node->save();
  $computed_visible_names = ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($computed_node));
  ttd_parity_assert(in_array($computed_topics['computed_mention']['name'], $computed_visible_names, TRUE), 'Dragging below-threshold mention to about makes it visible');

  $computed_node->set('field_tier_overrides', ['value' => []]);
  $computed_node->save();
  $computed_visible_names = ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($computed_node));
  ttd_parity_assert(!in_array($computed_topics['computed_mention']['name'], $computed_visible_names, TRUE), 'Removing override returns below-threshold mention to hidden');

  $count_ttd_id = $base_ttd_id + $i++;
  $count_topic_name = "TB Parity Count Accuracy {$suffix}";
  ttd_parity_insert_entity($count_ttd_id, $count_topic_name, $schema_type_id);
  $count_term = ttd_parity_create_topic($count_topic_name, $count_ttd_id);
  $count_node_a = ttd_parity_create_node("TB Parity Count A {$suffix}", [(int) $count_term->id()]);
  ttd_parity_assert((ttd_topics_get_topic_node_counts([(int) $count_term->id()])[(int) $count_term->id()] ?? NULL) === 1, 'Topic node count is 1 after first assignment');
  ttd_parity_assert(!in_array($count_topic_name, ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($count_node_a)), TRUE), 'Count topic hidden when count is below threshold');

  $count_node_b = ttd_parity_create_node("TB Parity Count B {$suffix}", [(int) $count_term->id()]);
  ttd_parity_assert((ttd_topics_get_topic_node_counts([(int) $count_term->id()])[(int) $count_term->id()] ?? NULL) === 2, 'Topic node count reaches threshold after second assignment');
  ttd_parity_assert(in_array($count_topic_name, ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($count_node_a)), TRUE), 'Count topic visible when count reaches threshold');
  ttd_parity_assert(in_array($count_topic_name, ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($count_node_b)), TRUE), 'Count topic visible on second node at threshold');

  $count_node_c = ttd_parity_create_node("TB Parity Count C {$suffix}", [(int) $count_term->id()]);
  ttd_parity_assert((ttd_topics_get_topic_node_counts([(int) $count_term->id()])[(int) $count_term->id()] ?? NULL) === 3, 'Topic node count increments across three assigned nodes');
  $GLOBALS['created_nodes'] = array_values(array_diff($GLOBALS['created_nodes'], [(int) $count_node_a->id()]));
  $count_node_a->delete();
  ttd_parity_assert((ttd_topics_get_topic_node_counts([(int) $count_term->id()])[(int) $count_term->id()] ?? NULL) === 2, 'Topic node count decrements after deleting one node');
  $GLOBALS['created_nodes'] = array_values(array_diff($GLOBALS['created_nodes'], [(int) $count_node_b->id()]));
  $count_node_b->delete();
  ttd_parity_assert((ttd_topics_get_topic_node_counts([(int) $count_term->id()])[(int) $count_term->id()] ?? NULL) === 1, 'Topic node count decrements below threshold after deleting second node');
  ttd_parity_assert(!in_array($count_topic_name, ttd_parity_topic_names(ttd_topics_get_filtered_topics_for_node($count_node_c)), TRUE), 'Count topic hides again after count drops below threshold');

  $promote_term = $computed_topics['computed_about']['term'];
  $other_rejected_term = $computed_topics['computed_mention']['term'];
  $computed_node->set('field_ttd_rejected_topics', [['target_id' => (int) $promote_term->id()]]);
  $computed_node->set('field_tier_overrides', ['value' => []]);
  $computed_node->save();
  ttd_parity_update_topic_tier($computed_node, $promote_term, 'mainEntity');
  ttd_parity_assert(!in_array((int) $promote_term->id(), ttd_parity_rejected_term_ids($computed_node), TRUE), 'Promoting rejected topic to mainEntity auto-unrejects it');

  $computed_node->set('field_ttd_rejected_topics', [['target_id' => (int) $promote_term->id()]]);
  $computed_node->save();
  ttd_parity_update_topic_tier($computed_node, $promote_term, 'about');
  ttd_parity_assert(!in_array((int) $promote_term->id(), ttd_parity_rejected_term_ids($computed_node), TRUE), 'Promoting rejected topic to about auto-unrejects it');

  $computed_node->set('field_ttd_rejected_topics', [['target_id' => (int) $promote_term->id()]]);
  $computed_node->save();
  ttd_parity_update_topic_tier($computed_node, $promote_term, 'mentions');
  ttd_parity_assert(in_array((int) $promote_term->id(), ttd_parity_rejected_term_ids($computed_node), TRUE), 'Moving rejected topic to mentions leaves it rejected');

  $computed_node->set('field_ttd_rejected_topics', [
    ['target_id' => (int) $promote_term->id()],
    ['target_id' => (int) $other_rejected_term->id()],
  ]);
  $computed_node->save();
  ttd_parity_update_topic_tier($computed_node, $promote_term, 'mainEntity');
  $rejected_after_promote = ttd_parity_rejected_term_ids($computed_node);
  ttd_parity_assert(!in_array((int) $promote_term->id(), $rejected_after_promote, TRUE) && in_array((int) $other_rejected_term->id(), $rejected_after_promote, TRUE), 'Auto-unreject removes only the promoted topic');

  $computed_node->set('field_ttd_rejected_topics', []);
  $computed_node->set('field_tier_overrides', ['value' => []]);
  $computed_node->save();

  $computed_schema = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($computed_node->id());
  $computed_article = ttd_parity_schema_article($computed_schema);
  ttd_parity_assert(($computed_article['mainEntity']['name'] ?? NULL) === $computed_topics['computed_main']['name'], 'Schema mainEntity uses computed salience tier');
  ttd_parity_assert(in_array($computed_topics['computed_about']['name'], ttd_parity_schema_names($computed_article['about'] ?? []), TRUE), 'Schema about uses computed salience tier');

  $schema_files = [
    '16x9' => ttd_parity_create_schema_image_file("tb-parity-16x9-{$suffix}.png"),
    '4x3' => ttd_parity_create_schema_image_file("tb-parity-4x3-{$suffix}.png"),
    '1x1' => ttd_parity_create_schema_image_file("tb-parity-1x1-{$suffix}.png"),
  ];
  $computed_node->set('field_ttd_schema_16x9', ['target_id' => $schema_files['16x9']->id(), 'alt' => 'TopicalBoost parity 16x9']);
  $computed_node->set('field_ttd_schema_4x3', ['target_id' => $schema_files['4x3']->id(), 'alt' => 'TopicalBoost parity 4x3']);
  $computed_node->set('field_ttd_schema_1x1', ['target_id' => $schema_files['1x1']->id(), 'alt' => 'TopicalBoost parity 1x1']);
  $computed_node->save();

  $image_schema = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($computed_node->id());
  $image_article = ttd_parity_schema_article($image_schema);
  $schema_images = ttd_parity_schema_images($image_article);
  $image_sizes = array_map(static fn($image) => ($image['width'] ?? 0) . 'x' . ($image['height'] ?? 0), $schema_images);
  sort($image_sizes);
  $expected_image_sizes = ['1200x675', '675x675', '900x675'];
  sort($expected_image_sizes);
  ttd_parity_assert(count($schema_images) === 3, 'Schema includes three custom image ratios like WordPress');
  ttd_parity_assert($image_sizes === $expected_image_sizes, 'Schema custom image ratios match WordPress dimensions');

  $preview_source = '<p>' . str_repeat('Meaningful Drupal parity content ', 240) . '</p><figure><img src="x.jpg"><figcaption>Photo credit should disappear.</figcaption></figure><p>Photo courtesy of API News. More body text.</p>';
  $preview = ttd_parity_extract_meta_preview($preview_source);
  ttd_parity_assert(strlen($preview) > 500, 'Meta generator content preview matches WordPress long-preview behavior');
  ttd_parity_assert(strlen($preview) <= 5000, 'Meta generator content preview caps at WordPress 5000 character limit');
  ttd_parity_assert(strpos($preview, 'Photo credit should disappear') === FALSE, 'Meta generator strips figure captions like WordPress');
  ttd_parity_assert(strpos($preview, 'Photo courtesy') === FALSE, 'Meta generator strips common image credit copy like WordPress');

  ttd_store_demand_metrics((int) $topics['about_low']['term']->id(), [
    'keyword_difficulty' => 22,
    'search_volume' => 12000,
    'traffic_potential' => 1400000,
  ]);
  $badge = ttd_render_kd_badge((int) $topics['about_low']['term']->id(), (int) $main_node->id());
  ttd_parity_assert(strpos($badge, '1.4M') !== FALSE, 'Demand badge uses traffic_potential like WordPress');

  $metric_term_id = (int) $topics['manual_low']['term']->id();
  \Drupal::state()->delete(ttd_get_demand_cache_key($metric_term_id));
  ttd_parity_invoke_private_method(
    \Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdTopicsAnalysis::class,
    'storeDemandMetricsForTerm',
    [$metric_term_id, ['keyword_difficulty' => 31, 'search_volume' => 1200, 'traffic_potential' => 9900]]
  );
  $single_metrics = ttd_get_demand_metrics($metric_term_id);
  ttd_parity_assert(($single_metrics['traffic_potential'] ?? NULL) === 9900, 'Single-analysis job stores traffic_potential like WordPress');

  \Drupal::state()->delete(ttd_get_demand_cache_key($metric_term_id));
  ttd_parity_invoke_private_method(
    \Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkApplyPostsOptimized::class,
    'storeDemandMetricsForTerm',
    [$metric_term_id, ['keyword_difficulty' => 41, 'search_volume' => 2200, 'traffic_potential' => 19900]]
  );
  $bulk_metrics = ttd_get_demand_metrics($metric_term_id);
  ttd_parity_assert(($bulk_metrics['traffic_potential'] ?? NULL) === 19900, 'Bulk-analysis apply job stores traffic_potential like WordPress');

  $allowlist_ttd_id = $base_ttd_id + $i++;
  $GLOBALS['created_ttd_ids'][] = $allowlist_ttd_id;
  ttd_parity_invoke_private_method(
    \Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkApplyPostsOptimized::class,
    'storeEntityMetadata',
    [[
      'id' => $allowlist_ttd_id,
      'name' => "TB Parity Allowlist {$suffix}",
      'mid' => '/m/topicalboost_parity_allowlist',
      'nl_name' => 'Allowlist NL Name',
      'kg_description' => 'Allowed description field',
      'unexpected_api_field' => 'must not be written',
    ]]
  );
  $allowlist_row = \Drupal::database()->select('ttd_entities', 'e')
    ->fields('e')
    ->condition('ttd_id', $allowlist_ttd_id)
    ->execute()
    ->fetchAssoc();
  ttd_parity_assert(($allowlist_row['mid'] ?? NULL) === '/m/topicalboost_parity_allowlist', 'Entity allowlist stores known metadata fields like WordPress');
  ttd_parity_assert(($allowlist_row['kg_description'] ?? NULL) === 'Allowed description field', 'Entity allowlist stores known description metadata');
  ttd_parity_assert(!array_key_exists('unexpected_api_field', $allowlist_row ?: []), 'Entity allowlist rejects unknown API fields like WordPress');
}
finally {
  if ($original_threshold === NULL) {
    \Drupal::configFactory()->getEditable('ttd_topics.settings')
      ->clear('post_topic_minimum_display_count')
      ->save();
  }
  else {
    \Drupal::configFactory()->getEditable('ttd_topics.settings')
      ->set('post_topic_minimum_display_count', $original_threshold)
      ->save();
  }

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

  $file_storage = \Drupal::entityTypeManager()->getStorage('file');
  foreach (array_reverse(array_unique($GLOBALS['created_files'])) as $fid) {
    if ($file = $file_storage->load($fid)) {
      $file->delete();
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
}

echo "\nAssertions: {$GLOBALS['assertions']}; Failures: {$GLOBALS['failures']}\n";
if ($GLOBALS['failures'] > 0) {
  throw new RuntimeException("TopicalBoost Drupal parity tests failed with {$GLOBALS['failures']} failure(s).");
}

echo "TopicalBoost Drupal core parity tests passed.\n";
