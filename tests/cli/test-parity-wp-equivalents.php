<?php

/**
 * Drupal CLI parity tests for remaining WordPress plugin test contracts.
 *
 * Run from the Drupal site root with:
 *   drush scr web/modules/custom/topicalboost/tests/cli/test-parity-wp-equivalents.php
 */

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\file\Entity\File;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;

$GLOBALS['assertions'] = 0;
$GLOBALS['failures'] = 0;
$GLOBALS['created_nodes'] = [];
$GLOBALS['created_terms'] = [];
$GLOBALS['created_files'] = [];
$GLOBALS['created_users'] = [];
$GLOBALS['created_roles'] = [];
$GLOBALS['created_ttd_ids'] = [];
$GLOBALS['created_schema_type_ids'] = [];
$GLOBALS['created_state_keys'] = [];

$original_config = \Drupal::config('ttd_topics.settings')->getRawData();
$suffix = time() . '_' . random_int(1000, 9999);
$base_ttd_id = 980000000 + random_int(10000, 90000);
$schema_type_id = $base_ttd_id + 9000;

function ttd_parity_wp_pass(string $message): void {
  echo "PASS: {$message}\n";
}

function ttd_parity_wp_fail(string $message): void {
  $GLOBALS['failures']++;
  echo "FAIL: {$message}\n";
}

function ttd_parity_wp_assert(bool $condition, string $message): void {
  $GLOBALS['assertions']++;
  $condition ? ttd_parity_wp_pass($message) : ttd_parity_wp_fail($message);
}

function ttd_parity_wp_require_field(string $entity_type, string $bundle, string $field_name): void {
  $exists = (bool) \Drupal\field\Entity\FieldConfig::loadByName($entity_type, $bundle, $field_name);
  ttd_parity_wp_assert($exists, "{$entity_type}.{$bundle}.{$field_name} exists");
  if (!$exists) {
    throw new RuntimeException("Missing required field {$field_name}");
  }
}

function ttd_parity_wp_insert_entity(int $ttd_id, string $name, int $schema_type_id, array $extra = []): void {
  $database = \Drupal::database();
  $now = date('Y-m-d H:i:s');

  $database->merge('ttd_entities')
    ->key('ttd_id', $ttd_id)
    ->fields($extra + [
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

  $existing = $database->select('ttd_entity_schema_types', 'est')
    ->condition('entity_id', $ttd_id)
    ->condition('schema_type_id', $schema_type_id)
    ->countQuery()
    ->execute()
    ->fetchField();
  if (!$existing) {
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

function ttd_parity_wp_create_topic(string $name, int $ttd_id, array $values = []): Term {
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

function ttd_parity_wp_create_node(string $title, array $term_ids = [], array $manual_ids = []): Node {
  $node = Node::create([
    'type' => 'article',
    'title' => $title,
    'body' => [
      'value' => '<p>TopicalBoost parity fixture content.</p>',
      'summary' => 'TopicalBoost parity summary.',
      'format' => 'basic_html',
    ],
    'status' => 1,
  ]);
  $node->set('field_ttd_topics', array_values(array_map(static fn($tid) => ['target_id' => $tid], $term_ids)));
  $node->set('field_manual_topics', array_values(array_map(static fn($tid) => ['target_id' => $tid], $manual_ids)));
  $node->set('field_ttd_rejected_topics', []);
  $node->set('field_tier_overrides', ['value' => []]);
  $node->save();
  $GLOBALS['created_nodes'][] = (int) $node->id();
  return $node;
}

function ttd_parity_wp_set_salience(Node $node, int $ttd_id, ?string $tier, ?float $score): void {
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

function ttd_parity_wp_topic_names(Node $node): array {
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $node = Node::load($node->id());
  $names = array_map(static fn($item) => $item['term']->label(), ttd_topics_get_filtered_topics_for_node($node));
  sort($names, SORT_NATURAL | SORT_FLAG_CASE);
  return $names;
}

function ttd_parity_wp_node_topic_ids(Node $node, string $field_name = 'field_ttd_topics'): array {
  \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
  $node = Node::load($node->id());
  return array_map('intval', array_column($node->get($field_name)->getValue(), 'target_id'));
}

function ttd_parity_wp_invoke_private(object|string $object_or_class, string $method_name, array $arguments): mixed {
  $reflection = is_object($object_or_class)
    ? new ReflectionClass($object_or_class)
    : new ReflectionClass($object_or_class);
  $instance = is_object($object_or_class)
    ? $object_or_class
    : $reflection->newInstanceWithoutConstructor();
  $method = $reflection->getMethod($method_name);
  $method->setAccessible(TRUE);
  return $method->invokeArgs($instance, $arguments);
}

function ttd_parity_wp_custom_field_filtered_count(object $filter_owner, Node $node, array $filters): int {
  $query = \Drupal::database()->select('node_field_data', 'n');
  $query->fields('n', ['nid']);
  $query->condition('n.nid', (int) $node->id());
  $query->condition('n.type', $node->bundle());
  $query->condition('n.status', 1);

  ttd_parity_wp_invoke_private($filter_owner, 'applyCustomFieldFilter', [$query, $filters]);

  return (int) $query->countQuery()->execute()->fetchField();
}

function ttd_parity_wp_meta_preview(string $content): string {
  return ttd_parity_wp_invoke_private(new \Drupal\ttd_topics\Controller\MetaGeneratorController(), 'getCleanContentPreview', [$content]);
}

function ttd_parity_wp_schema_article(array $schema): array {
  foreach (($schema['@graph'] ?? []) as $item) {
    $type = $item['@type'] ?? NULL;
    $types = is_array($type) ? $type : [$type];
    if (array_intersect($types, ['Article', 'NewsArticle'])) {
      return $item;
    }
  }
  return [];
}

function ttd_parity_wp_schema_names($items): array {
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

function ttd_parity_wp_create_image_file(string $filename, int $width = 1200, int $height = 900): ?File {
  if (!function_exists('imagecreatetruecolor')) {
    ttd_parity_wp_pass('GD image extension unavailable; schema source image generation test skipped');
    return NULL;
  }

  $directory = 'public://topicalboost-parity-featured';
  \Drupal::service('file_system')->prepareDirectory(
    $directory,
    \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS
  );

  $uri = $directory . '/' . $filename;
  $path = \Drupal::service('file_system')->realpath($directory) . '/' . $filename;
  $image = imagecreatetruecolor($width, $height);
  $bg = imagecolorallocate($image, 32, 96, 160);
  imagefill($image, 0, 0, $bg);
  imagejpeg($image, $path, 90);
  imagedestroy($image);

  $file = File::create([
    'uri' => $uri,
    'filename' => $filename,
    'status' => 1,
  ]);
  $file->save();
  $GLOBALS['created_files'][] = (int) $file->id();
  return $file;
}

echo "\n=== TopicalBoost Drupal WordPress-Equivalent Parity Tests ===\n\n";

try {
  ttd_parity_wp_require_field('node', 'article', 'field_ttd_topics');
  ttd_parity_wp_require_field('node', 'article', 'field_manual_topics');
  ttd_parity_wp_require_field('node', 'article', 'field_ttd_rejected_topics');
  ttd_parity_wp_require_field('node', 'article', 'field_ttd_last_analyzed');
  ttd_parity_wp_require_field('taxonomy_term', 'ttd_topics', 'field_ttd_id');
  ttd_parity_wp_require_field('taxonomy_term', 'ttd_topics', 'field_hide');
  ttd_parity_wp_require_field('taxonomy_term', 'ttd_topics', 'field_force_show');

  $menu_links = \Drupal\Component\Serialization\Yaml::decode(file_get_contents(DRUPAL_ROOT . '/modules/custom/topicalboost/ttd_topics.links.menu.yml')) ?: [];
  $config_overview_links = array_filter($menu_links, static function (array $definition, string $machine_name): bool {
    return str_starts_with($machine_name, 'topicalboost.')
      && ($definition['parent'] ?? '') === 'system.admin_config_content';
  }, ARRAY_FILTER_USE_BOTH);
  ttd_parity_wp_assert(count($config_overview_links) === 1, 'Configuration overview exposes one TopicalBoost entry');
  ttd_parity_wp_assert(isset($config_overview_links['topicalboost.settings_form']), 'Single Configuration overview entry routes to TopicalBoost settings');

  $post_editor_js = file_get_contents(DRUPAL_ROOT . '/modules/custom/topicalboost/js/post-editor.js');
  $admin_topics_css = file_get_contents(DRUPAL_ROOT . '/modules/custom/topicalboost/css/admin-topics.css');
  $admin_topics_template = file_get_contents(DRUPAL_ROOT . '/modules/custom/topicalboost/templates/ttd-admin-topics.html.twig');
  ttd_parity_wp_assert(strpos($post_editor_js, 'flashFullWarning') !== FALSE, 'Editor drag limits flash a FULL warning like WordPress');
  ttd_parity_wp_assert(strpos($post_editor_js, 'dropEffect = \'none\'') !== FALSE, 'Editor drag limits reject over-capacity drops');
  ttd_parity_wp_assert(strpos($admin_topics_css, 'ttd-warning-flash') !== FALSE, 'Editor warning flash styling exists');
  ttd_parity_wp_assert(strpos($admin_topics_template, '>4 max<') !== FALSE, 'Editor About limit copy matches WordPress');

  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('post_topic_minimum_display_count', 2)
    ->set('include_excerpt', TRUE)
    ->set('analysis_custom_fields', [])
    ->save();

  // Content-preview and filter_text equivalents.
  ttd_parity_wp_assert(strpos(ttd_parity_wp_meta_preview('<p>This is a test article about Drupal modules.</p>'), 'Drupal modules') !== FALSE, 'Meta preview extracts basic content');
  ttd_parity_wp_assert(strpos(ttd_parity_wp_meta_preview('<h1>Title</h1><p>Body text with <strong>bold</strong> and <a href="#">links</a>.</p>'), '<') === FALSE, 'Meta preview strips HTML tags');
  ttd_parity_wp_assert(strpos(ttd_parity_wp_meta_preview('[gallery ids="1,2,3"] Some real content here. [caption]Image caption[/caption]'), '[gallery') === FALSE, 'Meta preview strips shortcodes');
  ttd_parity_wp_assert(ttd_parity_wp_meta_preview('[gallery ids="1,2,3"][embed]https://example.com[/embed]') === '', 'Meta preview returns empty for shortcode-only content');
  ttd_parity_wp_assert(strpos(ttd_parity_wp_meta_preview('<p>Article text.</p><figure><img src="test.jpg"><figcaption>Photo credit</figcaption></figure><p>More text.</p>'), 'Photo credit') === FALSE, 'Meta preview strips figure captions');
  ttd_parity_wp_assert(strpos(ttd_parity_wp_meta_preview('<p>Good content here. Photo courtesy of AP News. More good content.</p>'), 'Photo courtesy') === FALSE, 'Meta preview strips image credit patterns');
  ttd_parity_wp_assert(strlen(ttd_parity_wp_meta_preview('<p>' . str_repeat('Word ', 2000) . '</p>')) <= 5000, 'Meta preview truncates at WordPress 5000-character limit');
  ttd_parity_wp_assert(strpos(ttd_parity_wp_meta_preview("<p>Lots   of    spaces\n\n\nand   newlines   here.</p>"), '  ') === FALSE, 'Meta preview collapses whitespace');

  $filtered_text = ttd_topics_filter_text('<h1>Title</h1><p>First paragraph.</p><p>Second paragraph.</p><ul><li>Item 1</li><li>Item 2</li></ul>');
  ttd_parity_wp_assert(strpos($filtered_text, "Title\n\nFirst paragraph.\n\nSecond paragraph.") !== FALSE, 'Analysis text filter preserves paragraph boundaries');
  ttd_parity_wp_assert(strpos($filtered_text, "Item 1\nItem 2") !== FALSE, 'Analysis text filter preserves list item line breaks');
  ttd_parity_wp_assert(strpos(ttd_topics_filter_text('Intro text<p>Paragraph content.</p>'), 'Intro textParagraph') === FALSE, 'Analysis text filter prevents adjacent text from running together');
  ttd_parity_wp_assert(strpos(ttd_topics_filter_text('<p>Cats &amp; Dogs &mdash; Friends</p>'), 'Cats & Dogs') !== FALSE, 'Analysis text filter decodes HTML entities');

  // Analysis collection includes configured custom fields and skips internal fields.
  $collector_node = ttd_parity_wp_create_node("TB Parity Collector {$suffix}");
  $collector_node->set('body', [
    'value' => '<p>Main body content.</p>',
    'summary' => 'Summary excerpt content.',
    'format' => 'basic_html',
  ]);
  $collector_node->save();
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('include_excerpt', TRUE)
    ->set('analysis_custom_fields', ['field_ttd_topics'])
    ->save();
  $collected = \Drupal::service('ttd_topics.field_collector')->collect($collector_node);
  ttd_parity_wp_assert(strpos($collected, 'Main body content') !== FALSE, 'Field collector includes body content');
  ttd_parity_wp_assert(strpos($collected, 'Summary excerpt content') !== FALSE, 'Field collector includes excerpt when configured');
  ttd_parity_wp_assert(strpos($collected, 'field_ttd_topics') === FALSE, 'Field collector does not leak internal field machine names');

  // Mock analysis application, hook/event parity, manual-topic preservation, and promoted manual topics.
  $manual_ttd_id = $base_ttd_id + 1;
  $api_ttd_id = $base_ttd_id + 2;
  $promoted_ttd_id = $base_ttd_id + 3;
  $stale_ttd_id = $base_ttd_id + 4;
  foreach ([
    $manual_ttd_id => "TB Parity Manual Survives {$suffix}",
    $api_ttd_id => "TB Parity API Topic {$suffix}",
    $promoted_ttd_id => "TB Parity Promoted Manual {$suffix}",
    $stale_ttd_id => "TB Parity Stale Topic {$suffix}",
  ] as $ttd_id => $name) {
    ttd_parity_wp_insert_entity($ttd_id, $name, $schema_type_id);
  }

  $manual_term = ttd_parity_wp_create_topic("TB Parity Manual Survives {$suffix}", $manual_ttd_id);
  $promoted_manual_term = ttd_parity_wp_create_topic("TB Parity Promoted Manual {$suffix}", $promoted_ttd_id);
  $stale_term = ttd_parity_wp_create_topic("TB Parity Stale Topic {$suffix}", $stale_ttd_id);
  $analysis_node = ttd_parity_wp_create_node(
    "TB Parity Analysis Apply {$suffix}",
    [(int) $manual_term->id(), (int) $promoted_manual_term->id(), (int) $stale_term->id()],
    [(int) $manual_term->id(), (int) $promoted_manual_term->id()]
  );

  $event_seen = ['called' => FALSE, 'node_id' => NULL, 'topic_count' => 0];
  \Drupal::service('event_dispatcher')->addListener('ttd_topics.analysis_complete', static function ($event) use (&$event_seen) {
    $event_seen['called'] = TRUE;
    $event_seen['node_id'] = (int) $event->getNode()->id();
    $event_seen['topic_count'] = count($event->getAppliedTopicIds());
  });

  $analysis_results = [
    'entities' => [
      [
        'id' => $api_ttd_id,
        'name' => "TB Parity API Topic {$suffix}",
        'Contents' => [['customer_id' => $analysis_node->id(), 'salience_score' => 0.14, 'llm_tier' => 'mainEntity']],
      ],
      [
        'id' => $promoted_ttd_id,
        'name' => "TB Parity Promoted Manual {$suffix}",
        'Contents' => [['customer_id' => $analysis_node->id(), 'salience_score' => 0.08, 'llm_tier' => 'about']],
      ],
    ],
  ];
  ttd_parity_wp_invoke_private(\Drupal::service('ttd_topics.analysis_service'), 'saveAnalysisResults', [$analysis_node, $analysis_results, TRUE]);
  $topic_ids_after_analysis = ttd_parity_wp_node_topic_ids($analysis_node);
  $manual_ids_after_analysis = ttd_parity_wp_node_topic_ids($analysis_node, 'field_manual_topics');
  ttd_parity_wp_assert(in_array((int) $manual_term->id(), $topic_ids_after_analysis, TRUE), 'Single reanalysis preserves manual topics');
  ttd_parity_wp_assert(!in_array((int) $stale_term->id(), $topic_ids_after_analysis, TRUE), 'Single reanalysis removes stale API topics');
  ttd_parity_wp_assert(!in_array((int) $promoted_manual_term->id(), $manual_ids_after_analysis, TRUE), 'Single reanalysis removes promoted API topic from manual list');
  ttd_parity_wp_assert($event_seen['called'] && $event_seen['node_id'] === (int) $analysis_node->id() && $event_seen['topic_count'] >= 2, 'Analysis-complete event fires with node and applied topics');

  // Bulk apply and sync re-apply preserve manual topics and are idempotent.
  $bulk_node = ttd_parity_wp_create_node(
    "TB Parity Bulk Apply {$suffix}",
    [(int) $manual_term->id(), (int) $stale_term->id()],
    [(int) $manual_term->id()]
  );
  $bulk_job = new \Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkApplyPostsOptimized([], 'ttd_bulk_apply_posts_optimized', []);
  ttd_parity_wp_invoke_private($bulk_job, 'applyEntitiesToNode', [$bulk_node, [
    ['id' => $api_ttd_id, 'name' => "TB Parity API Topic {$suffix}", 'Contents' => [['customer_id' => $bulk_node->id(), 'salience_score' => 0.12, 'llm_tier' => 'mainEntity']]],
  ]]);
  $bulk_node->save();
  $bulk_topic_ids = ttd_parity_wp_node_topic_ids($bulk_node);
  ttd_parity_wp_assert(in_array((int) $manual_term->id(), $bulk_topic_ids, TRUE), 'Bulk apply preserves manual topics');
  ttd_parity_wp_assert(!in_array((int) $stale_term->id(), $bulk_topic_ids, TRUE), 'Bulk apply removes stale API topics');
  $bulk_topic_ids_before = $bulk_topic_ids;
  ttd_parity_wp_invoke_private($bulk_job, 'applyEntitiesToNode', [$bulk_node, [
    ['id' => $api_ttd_id, 'name' => "TB Parity API Topic {$suffix}", 'Contents' => [['customer_id' => $bulk_node->id(), 'salience_score' => 0.12, 'llm_tier' => 'mainEntity']]],
  ]]);
  $bulk_node->save();
  $bulk_topic_ids_after = ttd_parity_wp_node_topic_ids($bulk_node);
  sort($bulk_topic_ids_before);
  sort($bulk_topic_ids_after);
  ttd_parity_wp_assert($bulk_topic_ids_before === $bulk_topic_ids_after, 'Bulk apply is idempotent for repeated result application');

  $sync_service = \Drupal::service('ttd_topics.sync_service');
  $sync_node = ttd_parity_wp_create_node(
    "TB Parity Sync Apply {$suffix}",
    [(int) $manual_term->id()],
    [(int) $manual_term->id()]
  );
  $sync_entities = [
    $api_ttd_id => ['id' => $api_ttd_id, 'name' => "TB Parity API Topic {$suffix}", 'Contents' => [['customer_id' => $sync_node->id(), 'salience_score' => 0.12, 'llm_tier' => 'mainEntity']]],
  ];
  ttd_parity_wp_invoke_private($sync_service, 'applyEntitiesToNode', [$sync_node, [$api_ttd_id], $sync_entities]);
  $sync_topic_ids = ttd_parity_wp_node_topic_ids($sync_node);
  ttd_parity_wp_assert(in_array((int) $manual_term->id(), $sync_topic_ids, TRUE), 'Sync apply preserves manual topics');
  ttd_parity_wp_assert(count($sync_topic_ids) === 2, 'Sync apply stores exactly manual plus API topics');
  $deleted_node_id = (int) $sync_node->id();
  $GLOBALS['created_nodes'] = array_values(array_diff($GLOBALS['created_nodes'], [$deleted_node_id]));
  $sync_node->delete();
  ttd_parity_wp_assert(Node::load($deleted_node_id) === NULL, 'Deleted-node sync scenario has no node to mutate');

  // Bulk pagination and sync queue scheduling equivalents.
  class TtdParityBulkController extends \Drupal\ttd_topics\Controller\BulkAnalysisController {
    protected function callBulkInitiateApi(string $api_base_url, string $api_key, int $content_count): array {
      return ['request_id' => 'tb_parity_bulk_' . $content_count];
    }
  }

  $bulk_posts = [];
  for ($i = 1; $i <= 40; $i++) {
    $bulk_posts[] = ttd_parity_wp_create_node("TB Parity Bulk Page {$i} {$suffix}");
  }
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('batch_size', 35)
    ->set('topicalboost_api_key', 'test-key')
    ->save();
  \Drupal::state()->delete('topicalboost.bulk_analysis.request_id');
  $GLOBALS['created_state_keys'][] = 'topicalboost.bulk_analysis.request_id';
  $bulk_controller = new TtdParityBulkController(\Drupal::database(), \Drupal::configFactory(), \Drupal::entityTypeManager());
  $bulk_filter_controller = new \Drupal\ttd_topics\Controller\BulkAnalysisController(\Drupal::database(), \Drupal::configFactory(), \Drupal::entityTypeManager());
  $custom_field_filters = [
    'custom_field_filter' => TRUE,
    'custom_field' => 'field_ttd_topics',
  ];
  ttd_parity_wp_assert(
    ttd_parity_wp_custom_field_filtered_count($bulk_filter_controller, $bulk_node, $custom_field_filters) === 1,
    'Bulk count custom-field filter counts multi-value field nodes once'
  );
  $bulk_batch_send = new \Drupal\ttd_topics\Plugin\AdvancedQueue\JobType\TtdBulkBatchSend([], 'ttd_bulk_batch_send', []);
  ttd_parity_wp_assert(
    ttd_parity_wp_custom_field_filtered_count($bulk_batch_send, $bulk_node, $custom_field_filters) === 1,
    'Bulk send custom-field filter counts multi-value field nodes once'
  );
  $bulk_response = $bulk_controller->initiateAnalysis(new Request([], [], [], [], [], [], json_encode([
    'content_types' => ['article'],
    'start_date' => date('Y-m-d', strtotime('-1 day')),
    'end_date' => date('Y-m-d', strtotime('+1 day')),
    'include_drafts' => FALSE,
    'reanalyze' => TRUE,
  ])));
  $bulk_data = json_decode($bulk_response->getContent(), TRUE);
  ttd_parity_wp_assert(($bulk_data['success'] ?? FALSE) === TRUE, 'Bulk analysis initiation succeeds with mocked API');
  ttd_parity_wp_assert((int) ($bulk_data['data']['page_count'] ?? 0) >= 2, 'Bulk analysis schedules multiple pages when content exceeds batch size');
  $bulk_request_id = $bulk_data['data']['request_id'] ?? '';
  $batch_count = \Drupal::database()->select('advancedqueue', 'aq')
    ->condition('queue_id', 'ttd_topics_analysis')
    ->condition('type', 'ttd_bulk_batch_send')
    ->condition('payload', '%' . $bulk_request_id . '%', 'LIKE')
    ->countQuery()
    ->execute()
    ->fetchField();
  ttd_parity_wp_assert((int) $batch_count === (int) $bulk_data['data']['page_count'], 'Bulk analysis queued one batch job per page');

  class TtdParitySyncService extends \Drupal\ttd_topics\Service\TtdSyncService {
    public function apiRequest(string $method, string $path, ?array $json = NULL, array $query = []) {
      if ($method === 'POST' && $path === '/sync/start') {
        return ['request_id' => 'tb_parity_sync'];
      }
      if ($method === 'GET' && $path === '/result/hidden-entities') {
        return ['hidden' => []];
      }
      return NULL;
    }
  }
  class TtdParityHiddenSyncService extends \Drupal\ttd_topics\Service\TtdSyncService {
    public array $hiddenIds = [];

    public function apiRequest(string $method, string $path, ?array $json = NULL, array $query = []) {
      if ($method === 'GET' && $path === '/result/hidden-entities') {
        return [
          'hidden' => array_map(static fn($entity_id) => ['entity_id' => $entity_id], $this->hiddenIds),
        ];
      }
      return NULL;
    }
  }
  \Drupal::state()->delete(\Drupal\ttd_topics\Service\TtdSyncService::SYNC_STATE_KEY);
  $GLOBALS['created_state_keys'][] = \Drupal\ttd_topics\Service\TtdSyncService::SYNC_STATE_KEY;
  $GLOBALS['created_state_keys'][] = \Drupal\ttd_topics\Service\TtdSyncService::LAST_SYNC_KEY;
  $parity_sync = new TtdParitySyncService();
  $sync_state = $parity_sync->startSync([
    'local' => ['postCount' => 40, 'topicCount' => 0, 'relationshipCount' => 0],
    'api' => ['posts' => 40, 'topics' => 205, 'relationships' => 40],
  ]);
  ttd_parity_wp_assert(($sync_state['topic_pages'] ?? 0) === 9, 'Sync schedules topic pull pages in small timeout-safe chunks');
  ttd_parity_wp_assert(($sync_state['rel_pages'] ?? 0) === 2, 'Sync schedules relationship pull pages using configured batch size');
  ttd_parity_wp_assert(\Drupal::state()->get(\Drupal\ttd_topics\Service\TtdSyncService::SYNC_STATE_KEY)['status'] === 'running', 'Active sync tracking state is stored');
  for ($i = 0; $i < (int) ($sync_state['total_jobs'] ?? 0); $i++) {
    ttd_parity_wp_invoke_private($parity_sync, 'recordPullResult', [[]]);
  }
  ttd_parity_wp_assert(\Drupal::state()->get(\Drupal\ttd_topics\Service\TtdSyncService::SYNC_STATE_KEY) === NULL, 'Sync finalizes after the last pull job completes');
  ttd_parity_wp_assert(is_string(\Drupal::state()->get(\Drupal\ttd_topics\Service\TtdSyncService::LAST_SYNC_KEY)), 'Sync completion stores the last sync timestamp without progress polling');

  $manual_hidden_ttd_id = $base_ttd_id + 30;
  $api_hidden_ttd_id = $base_ttd_id + 31;
  $manual_hidden_term = ttd_parity_wp_create_topic("TB Parity Manual Hidden {$suffix}", $manual_hidden_ttd_id, ['hide' => TRUE]);
  $api_hidden_term = ttd_parity_wp_create_topic("TB Parity API Hidden {$suffix}", $api_hidden_ttd_id);
  $hidden_sync = new TtdParityHiddenSyncService();
  \Drupal::state()->delete(\Drupal\ttd_topics\Service\TtdSyncService::API_HIDDEN_STATE_KEY);
  $GLOBALS['created_state_keys'][] = \Drupal\ttd_topics\Service\TtdSyncService::API_HIDDEN_STATE_KEY;
  $GLOBALS['created_state_keys'][] = 'ttd_hidden_entities_sync_last_run';
  $hidden_sync->hiddenIds = [$manual_hidden_ttd_id, $api_hidden_ttd_id];
  $hidden_sync->syncHiddenEntities();
  $api_owned_hidden_ids = array_map('intval', \Drupal::state()->get(\Drupal\ttd_topics\Service\TtdSyncService::API_HIDDEN_STATE_KEY, []));
  ttd_parity_wp_assert(!in_array($manual_hidden_ttd_id, $api_owned_hidden_ids, TRUE), 'Hidden-entity sync does not claim manually hidden topics');
  ttd_parity_wp_assert(in_array($api_hidden_ttd_id, $api_owned_hidden_ids, TRUE), 'Hidden-entity sync tracks topics it hides itself');
  $hidden_sync->hiddenIds = [];
  $hidden_sync->syncHiddenEntities();
  \Drupal::entityTypeManager()->getStorage('taxonomy_term')->resetCache([(int) $manual_hidden_term->id(), (int) $api_hidden_term->id()]);
  $manual_hidden_term = Term::load($manual_hidden_term->id());
  $api_hidden_term = Term::load($api_hidden_term->id());
  ttd_parity_wp_assert((bool) $manual_hidden_term->get('field_hide')->value === TRUE, 'Manual hidden topic stays hidden when the API stops hiding it');
  ttd_parity_wp_assert((bool) $api_hidden_term->get('field_hide')->value === FALSE, 'API-owned hidden topic is unhidden when the API stops hiding it');

  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('enable_frontend', TRUE)
    ->set('post_topic_minimum_display_count', 0)
    ->save();
  $hidden_access = ttd_topics_entity_access($manual_hidden_term, 'view', new \Drupal\Core\Session\AnonymousUserSession());
  ttd_parity_wp_assert($hidden_access->isForbidden(), 'Hidden ttd_topics term view access is denied for anonymous users');

  // SearchClippings/Citations role-access equivalent via Drupal permission configuration.
  $permission = 'administer topicalboost';
  $admin_role = Role::load('administrator');
  if ($admin_role && !$admin_role->hasPermission($permission)) {
    $admin_role->grantPermission($permission)->save();
  }
  $editor_role_id = 'tb_parity_editor_' . substr($suffix, -4);
  $subscriber_role_id = 'tb_parity_subscriber_' . substr($suffix, -4);
  foreach ([$editor_role_id, $subscriber_role_id] as $role_id) {
    $role = Role::create(['id' => $role_id, 'label' => $role_id]);
    if ($role_id === $editor_role_id) {
      $role->grantPermission($permission);
    }
    $role->save();
    $GLOBALS['created_roles'][] = $role_id;
  }
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('required_permission', $permission)
    ->save();
  $editor_user = User::create([
    'name' => 'tb_parity_editor_' . $suffix,
    'mail' => 'tb-parity-editor-' . $suffix . '@example.test',
    'status' => 1,
    'roles' => [$editor_role_id],
  ]);
  $editor_user->save();
  $GLOBALS['created_users'][] = (int) $editor_user->id();
  $subscriber_user = User::create([
    'name' => 'tb_parity_subscriber_' . $suffix,
    'mail' => 'tb-parity-subscriber-' . $suffix . '@example.test',
    'status' => 1,
    'roles' => [$subscriber_role_id],
  ]);
  $subscriber_user->save();
  $GLOBALS['created_users'][] = (int) $subscriber_user->id();
  ttd_parity_wp_assert(ttd_topics_account_has_required_permission($editor_user), 'Configured Drupal widget permission grants access');
  ttd_parity_wp_assert(!ttd_topics_account_has_required_permission($subscriber_user), 'User without configured Drupal widget permission is denied');
  \Drupal::configFactory()->getEditable('ttd_topics.settings')->set('required_permission', '')->save();
  ttd_parity_wp_assert(ttd_topics_get_required_permission() === 'administer topicalboost', 'Empty Drupal widget permission falls back to admin permission');

  // Schema integrity, batch isolation, and schema image generation.
  $schema_node_a = ttd_parity_wp_create_node("TB Parity Schema A {$suffix}");
  $schema_node_b = ttd_parity_wp_create_node("TB Parity Schema B {$suffix}");
  $schema_topic_a = ttd_parity_wp_create_topic("TB Parity Schema Topic A {$suffix}", $base_ttd_id + 20);
  $schema_topic_b = ttd_parity_wp_create_topic("TB Parity Schema Topic B {$suffix}", $base_ttd_id + 21);
  ttd_parity_wp_insert_entity($base_ttd_id + 20, $schema_topic_a->label(), $schema_type_id);
  ttd_parity_wp_insert_entity($base_ttd_id + 21, $schema_topic_b->label(), $schema_type_id);
  $schema_node_a->set('field_ttd_topics', [['target_id' => (int) $schema_topic_a->id()]]);
  $schema_node_b->set('field_ttd_topics', [['target_id' => (int) $schema_topic_b->id()]]);
  $schema_node_a->save();
  $schema_node_b->save();
  ttd_parity_wp_set_salience($schema_node_a, $base_ttd_id + 20, 'mainEntity', 0.15);
  ttd_parity_wp_set_salience($schema_node_b, $base_ttd_id + 21, 'mainEntity', 0.14);
  $schema_a = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($schema_node_a->id());
  $schema_b = \Drupal::service('ttd_topics.schema_generator')->getNodeTopicsSchema($schema_node_b->id());
  $article_a = ttd_parity_wp_schema_article($schema_a);
  $article_b = ttd_parity_wp_schema_article($schema_b);
  ttd_parity_wp_assert(($schema_a['@context'] ?? '') === 'https://schema.org', 'Schema uses schema.org context');
  ttd_parity_wp_assert(($article_a['@type'] ?? '') !== '', 'Schema contains Article graph item');
  ttd_parity_wp_assert(($article_a['mainEntity']['name'] ?? NULL) === $schema_topic_a->label(), 'Schema A has its own mainEntity');
  ttd_parity_wp_assert(($article_b['mainEntity']['name'] ?? NULL) === $schema_topic_b->label(), 'Schema B has its own mainEntity without batch cross-contamination');

  $image_file = ttd_parity_wp_create_image_file("tb-parity-featured-{$suffix}.jpg");
  if ($image_file && $schema_node_a->hasField('field_image')) {
    $schema_node_a->set('field_image', ['target_id' => $image_file->id(), 'alt' => 'Featured parity image']);
    $schema_node_a->save();
    $schema_images_controller = new \Drupal\ttd_topics\Controller\SchemaImagesController();
    $image_response = $schema_images_controller->generate(new Request([], [
      'nid' => (int) $schema_node_a->id(),
      'focal_x' => 0.5,
      'focal_y' => 0.5,
    ]));
    $image_data = json_decode($image_response->getContent(), TRUE);
    $generated_sizes = [];
    foreach (($image_data['images'] ?? []) as $image) {
      if (!empty($image['success'])) {
        $generated_sizes[] = ($image['width'] ?? 0) . 'x' . ($image['height'] ?? 0);
      }
    }
    sort($generated_sizes);
    $expected_sizes = ['1200x675', '675x675', '900x675'];
    sort($expected_sizes);
    ttd_parity_wp_assert($generated_sizes === $expected_sizes, 'Schema image generator creates WordPress-equivalent featured image ratios');
  }
  else {
    ttd_parity_wp_pass('Article field_image missing; featured-image schema generation test skipped');
  }
}
finally {
  \Drupal::configFactory()->getEditable('ttd_topics.settings')->setData($original_config)->save();

  foreach (array_unique($GLOBALS['created_state_keys']) as $key) {
    \Drupal::state()->delete($key);
  }
  foreach ([
    'topicalboost.bulk_analysis.request_id',
    'topicalboost.bulk_analysis.filters',
    'topicalboost.bulk_analysis.content_count',
    'topicalboost.bulk_analysis.apply_progress',
    'topicalboost.bulk_analysis.completed_at',
    'ttd_sync_status_cache',
  ] as $key) {
    \Drupal::state()->delete($key);
  }

  if (\Drupal::database()->schema()->tableExists('advancedqueue')) {
    \Drupal::database()->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', [
        'ttd_bulk_batch_send',
        'ttd_bulk_analysis_poller',
        'ttd_bulk_apply_posts_optimized',
        'ttd_sync_pull',
        'ttd_sync_hidden_entities',
      ], 'IN')
      ->execute();
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
  $fixture_tids = \Drupal::database()->select('taxonomy_term_field_data', 't')
    ->fields('t', ['tid'])
    ->condition('vid', 'ttd_topics')
    ->condition('name', 'TB Parity %', 'LIKE')
    ->execute()
    ->fetchCol();
  if (!empty($fixture_tids)) {
    $term_storage->delete($term_storage->loadMultiple($fixture_tids));
  }

  $file_storage = \Drupal::entityTypeManager()->getStorage('file');
  foreach (array_reverse(array_unique($GLOBALS['created_files'])) as $fid) {
    if ($file = $file_storage->load($fid)) {
      $file->delete();
    }
  }

  $user_storage = \Drupal::entityTypeManager()->getStorage('user');
  foreach (array_unique($GLOBALS['created_users']) as $uid) {
    if ($user = $user_storage->load($uid)) {
      $user->delete();
    }
  }

  foreach (array_unique($GLOBALS['created_roles']) as $role_id) {
    if ($role = Role::load($role_id)) {
      $role->delete();
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
  $database->delete('ttd_entities')
    ->condition('name', 'TB Parity %', 'LIKE')
    ->execute();
  if (!empty($GLOBALS['created_schema_type_ids'])) {
    $database->delete('ttd_schema_types')
      ->condition('ttd_id', array_unique($GLOBALS['created_schema_type_ids']), 'IN')
      ->execute();
  }
}

echo "\nAssertions: {$GLOBALS['assertions']}; Failures: {$GLOBALS['failures']}\n";
if ($GLOBALS['failures'] > 0) {
  throw new RuntimeException("TopicalBoost Drupal WP-equivalent parity tests failed with {$GLOBALS['failures']} failure(s).");
}

echo "TopicalBoost Drupal WP-equivalent parity tests passed.\n";
