<?php
/** Run with drush php:script. Requires the isolated local API endpoint. */
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\HttpFoundation\Request;
if (TOPICALBOOST_API_ENDPOINT !== 'http://host.docker.internal:33752') {
  throw new RuntimeException('Run this test only in the isolated category review site.');
}
$config = \Drupal::configFactory()->getEditable('ttd_topics.settings');
$original = $config->getRawData();
$nodes = [];
$terms = [];
$fields = [];
$bundle = 'tb_category_test_' . random_int(1000, 9999);
$failures = 0;
$assertions = 0;
$assert = function ($condition, $label) use (&$failures, &$assertions) {
  $assertions++;
  echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
  if (!$condition) { $failures++; }
};
try {
  NodeType::create(['type' => $bundle, 'name' => 'Category test'])->save();
  Vocabulary::create(['vid' => $bundle, 'name' => 'Category test'])->save();
  foreach (['field_tb_category_primary', 'field_tb_category_secondary'] as $name) {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => 'entity_reference', 'cardinality' => -1, 'settings' => ['target_type' => 'taxonomy_term']])->save();
      $fields[] = $name;
    }
    FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle, 'label' => 'Categories', 'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => [$bundle => $bundle]]]])->save();
  }
  $config->set('enabled_content_types', [$bundle])->set('enabled_categories', [])->set('block_until_analyzed', FALSE)->save();
  \Drupal::moduleHandler()->loadInclude('ttd_topics', 'install');
  ttd_topics_ensure_enabled_content_type_fields([$bundle]);
  foreach (['Friday Facts', 'News'] as $name) {
    $term = Term::create(['vid' => $bundle, 'name' => $name]); $term->save(); $terms[] = $term;
  }
  foreach ([[$terms[0]->id()], [$terms[1]->id()], [$terms[0]->id(), $terms[1]->id()], []] as $ids) {
    $node = Node::create(['type' => $bundle, 'title' => 'Category fixture', 'status' => 0, 'field_tb_category_primary' => array_map(fn($id) => ['target_id' => $id], $ids)]);
    $node->save(); $nodes[] = $node;
  }
  $secondary = Node::create(['type' => $bundle, 'title' => 'Secondary category fixture', 'status' => 0, 'field_tb_category_primary' => [['target_id' => $terms[1]->id()]], 'field_tb_category_secondary' => [['target_id' => $terms[0]->id()]]]);
  $secondary->save(); $nodes[] = $secondary;
  $config->set('enabled_categories', [(int) $terms[0]->id()])->clear('category_filter_mode')->save();
  $assert(ttd_topics_category_filter_mode() === 'include', 'Existing configuration defaults to Include');
  $all_ids = array_map(fn($node) => (int) $node->id(), $nodes);
  foreach (['include' => [TRUE, FALSE, TRUE, FALSE, TRUE], 'exclude' => [FALSE, TRUE, FALSE, TRUE, FALSE]] as $mode => $expected) {
    $config->set('category_filter_mode', $mode)->save();
    foreach ($nodes as $i => $node) { $assert(ttd_topics_node_matches_category_filter($node) === $expected[$i], "$mode automatic eligibility fixture $i"); }
    $query = \Drupal::database()->select('node_field_data', 'n')->fields('n', ['nid'])->condition('n.nid', $all_ids, 'IN');
    ttd_topics_apply_bulk_category_filter($query, [$bundle], [(int) $terms[0]->id()], 'n', $mode);
    $actual = array_map('intval', $query->execute()->fetchCol());
    $wanted = [];
    foreach ($all_ids as $i => $id) { if ($expected[$i]) { $wanted[] = $id; } }
    sort($actual); sort($wanted);
    $assert($actual === $wanted, "$mode bulk SQL handles mixed categories, empty categories and multiple fields");
    $controller = \Drupal\ttd_topics\Controller\BulkAnalysisController::create(\Drupal::getContainer());
    $request = new Request([], [], [], [], [], [], json_encode(['content_types' => [$bundle], 'include_drafts' => TRUE, 'reanalyze' => TRUE, 'categories' => $mode === 'exclude' ? [] : [(int) $terms[0]->id()]]));
    $data = json_decode($controller->getNodeCount($request)->getContent(), TRUE);
    $assert((int) ($data['data']['count'] ?? -1) === count($wanted), "$mode bulk count endpoint agrees with actual eligibility: " . json_encode($data));
    $batch = \Drupal::service('plugin.manager.advancedqueue_job_type')->createInstance('ttd_bulk_batch_send');
    $batch_method = new ReflectionMethod($batch, 'getNodesData');
    $batch_method->setAccessible(TRUE);
    $batch_filters = ['content_types' => [$bundle], 'start_date' => '', 'end_date' => '', 'include_drafts' => TRUE, 'only_topicless' => FALSE, 'reanalyze' => TRUE, 'custom_field_filter' => FALSE, 'custom_field' => '', 'categories' => [(int) $terms[0]->id()], 'category_filter_mode' => $mode];
    $batch_ids = array_map('intval', array_keys($batch_method->invoke($batch, $batch_filters, 1, 35)));
    sort($batch_ids);
    $assert($batch_ids === $wanted, "$mode queued batch selects exactly the same node IDs as the count endpoint");
  }
  foreach (['include', 'exclude'] as $mode) {
    $config->set('category_filter_mode', $mode)->set('enabled_categories', [])->save();
    foreach ($nodes as $node) { $assert(ttd_topics_node_matches_category_filter($node), "$mode with no categories allows content"); }
  }
  $config->set('category_filter_mode', 'exclude')->set('enabled_categories', [(int) $terms[0]->id()])->set('block_until_analyzed', TRUE)->save();
  $nodes[2]->setPublished()->save();
  $assert($nodes[2]->isPublished(), 'Excluded mixed-category content can publish without being held for analysis');
  $assert(!(bool) $nodes[2]->get('field_ttd_analysis_in_progress')->value, 'Excluded publication does not mark analysis in progress');
  $nodes[2]->set('field_ttd_analysis_in_progress', TRUE)->save();
  $job_type = \Drupal::service('plugin.manager.advancedqueue_job_type')->createInstance('ttd_topics_analysis');
  $job_type->process(\Drupal\advancedqueue\Job::create('ttd_topics_analysis', ['node_id' => $nodes[2]->id()]));
  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $node_storage->resetCache([$nodes[2]->id()]);
  $assert(!(bool) $node_storage->load($nodes[2]->id())->get('field_ttd_analysis_in_progress')->value, 'Previously queued excluded content clears the analysis flag');
  $account_switcher = \Drupal::service('account_switcher');
  $account_switcher->switchTo(\Drupal\user\Entity\User::load(1));
  try {
    $controller = \Drupal\ttd_topics\Controller\TtdTopicsController::create(\Drupal::getContainer());
    $response = $controller->runAnalysis($nodes[2], \Symfony\Component\HttpFoundation\Request::create('/', 'POST'));
    $data = json_decode($response->getContent(), TRUE);
    $assert($response->getStatusCode() === 200 && $data['success'] === FALSE && $data['message'] === 'Content is excluded by the category rule.', 'Editor analysis endpoint rejects excluded content before starting an API request');
    $rejected = FALSE;
    try { \Drupal::service('ttd_topics.analysis_service')->startSingleAnalysis($nodes[2]); }
    catch (\RuntimeException $e) { $rejected = $e->getMessage() === 'Content is excluded by the category rule.'; }
    $assert($rejected, 'Direct single-analysis service cannot bypass the exclusion rule');
  } finally {
    $account_switcher->switchBack();
  }
} finally {
  foreach ($nodes as $node) { $node->delete(); }
  foreach ($terms as $term) { $term->delete(); }
  if ($type = NodeType::load($bundle)) { $type->delete(); }
  if ($vocabulary = Vocabulary::load($bundle)) { $vocabulary->delete(); }
  foreach ($fields as $name) { if ($field = FieldStorageConfig::loadByName('node', $name)) { $field->delete(); } }
  $config->setData($original)->save();
}
echo "$assertions assertions, $failures failures\n";
if ($failures) { throw new RuntimeException('Category filter tests failed.'); }
