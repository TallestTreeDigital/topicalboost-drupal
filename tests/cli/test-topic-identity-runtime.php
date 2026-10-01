<?php
/**
 * Topic identity regression through real Drupal storage and caller paths.
 * Run only with scripts/benchmark-topic-identity.sh on its disposable database.
 */

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;

$assertions = 0;
$assert = static function ($condition, string $message) use (&$assertions): void {
  $assertions++;
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$connection = \Drupal::database();
$assert($connection->getConnectionOptions()['database'] === 'drupal_identity', 'Requires the disposable identity database.');
$assert(TOPICALBOOST_API_ENDPOINT === 'http://127.0.0.1:9', 'Requires the non-routable local test endpoint.');
$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$base = 991100000 + random_int(1000, 90000);
$prefix = 'TB identity ' . $base;
$ids = range($base, $base + 30);
$node = NULL;
$type = NULL;
$entity = static function (int $id, string $name): array {
  return ['id' => $id, 'name' => $name, 'createdAt' => '2026-09-30T00:00:00Z', 'updatedAt' => '2026-09-30T00:00:00Z'];
};
$get = static function (int $id) use ($storage) {
  $storage->resetCache();
  $terms = $storage->loadByProperties(['vid' => 'ttd_topics', 'field_ttd_id' => (string) $id]);
  if (count($terms) !== 1) {
    throw new RuntimeException('Entity ' . $id . ' must have exactly one term.');
  }
  return reset($terms);
};
$invoke = static function ($object, string $method, array $args) {
  $reflection = new ReflectionMethod($object, $method);
  $reflection->setAccessible(TRUE);
  return $reflection->invokeArgs($object, $args);
};

try {
  $type = NodeType::create(['type' => 'tb_identity', 'name' => 'Identity fixture']);
  $type->save();
  // Reuse the module's field installer for a newly created content type.
  \Drupal::moduleHandler()->loadInclude('ttd_topics', 'install');
  \ttd_topics_ensure_enabled_content_type_fields(['tb_identity']);
  $node = Node::create(['type' => 'tb_identity', 'title' => $prefix, 'status' => 0]);
  $node->save();
  $analysis = \Drupal::service('ttd_topics.analysis_service');
  $sync = \Drupal::service('ttd_topics.sync_service');
  $controller = new \Drupal\ttd_topics\Controller\TtdTopicsController();
  $job = \Drupal::service('plugin.manager.advancedqueue_job_type')->createInstance('ttd_topics_analysis');

  $first_name = $prefix . ' New York';
  $first_id = $invoke($analysis, 'getOrCreateTerm', [$first_name, $base, $entity($base, $first_name)]);
  $second_id = $invoke($analysis, 'getOrCreateTerm', [$first_name, $base + 1, $entity($base + 1, $first_name)]);
  $assert($first_id !== $second_id, 'Single analysis merged same-name distinct entity IDs.');
  $assert($get($base)->get('field_ttd_id')->value === (string) $base, 'First topic lost its identity.');
  $assert($get($base + 1)->toUrl()->toString() !== $get($base)->toUrl()->toString(), 'Distinct IDs share a topic URL.');

  $paths = [
    'legacy analysis job' => static fn($id) => $invoke($job, 'getOrCreateTerm', [$first_name, $id, $entity($id, $first_name)]),
    'bulk sync' => static fn($id) => $invoke($sync, 'mergeEntity', [$entity($id, $first_name)]),
    'manual creation' => static function ($id) use ($controller, $entity, $first_name, $assert) {
      $response = $controller->createTopicTerm(new Request([], [], [], [], [], [], json_encode(['topic_data' => ['ttd_id' => $id] + $entity($id, $first_name)])));
      $data = json_decode($response->getContent(), TRUE);
      $assert(!empty($data['success']), 'Manual creation failed.');
      return $data['data']['term_id'];
    },
    'lookup creation' => static function ($id) use ($controller, $connection, $first_name, $assert) {
      $connection->insert('ttd_entities')->fields(['ttd_id' => $id, 'name' => $first_name, 'createdAt' => '2026-09-30 00:00:00', 'updatedAt' => '2026-09-30 00:00:00'])->execute();
      $response = $controller->getTermByTtdId(new Request([], [], [], [], [], [], json_encode(['ttd_id' => $id])));
      $data = json_decode($response->getContent(), TRUE);
      $assert(!empty($data['success']), 'Lookup creation failed.');
      return $data['data']['term_id'];
    },
    'repair command' => static function ($id) use ($invoke, $first_name, $get) {
      $command = new \Drupal\ttd_topics\Commands\FixTtdTopicsCommand();
      $invoke($command, 'fixTtdTopic', [(object) ['ttd_id' => $id, 'name' => $first_name]]);
      return $get($id)->id();
    },
    'create command' => static function ($id) use ($invoke, $first_name, $get, $assert) {
      $command = new \Drupal\ttd_topics\Commands\CreateTtdTopicsCommand();
      $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
      $progress = new class { public function advance() {} };
      $invoke($command, 'processBatch', [[(object) ['ttd_id' => $id, 'name' => $first_name]], &$stats, $progress]);
      $assert($stats['errors'] === 0 && $stats['processed'] === 1, 'Create command failed.');
      return $get($id)->id();
    },
  ];
  $next_id = $base + 2;
  $term_ids = [(int) $first_id, (int) $second_id];
  foreach ($paths as $label => $path) {
    $id = $next_id++;
    $term_id = $path($id);
    $assert(!in_array((int) $term_id, $term_ids, TRUE), $label . ' reused another entity topic.');
    $assert($get($id)->get('field_ttd_id')->value === (string) $id, $label . ' returned the wrong identity.');
    $assert($get($base)->get('field_ttd_id')->value === (string) $base, $label . ' overwrote the first identity.');
    $term_ids[] = (int) $term_id;
    echo 'PASS ' . $label . " separates same-name IDs.\n";
  }

  $editor_term = $get($base);
  $editor_term->setName($prefix . ' Editor disambiguation');
  $editor_term->save();
  for ($round = 0; $round < 4; $round++) {
    $results = ['entities' => [$entity($base, $first_name), $entity($base + 1, $first_name)]];
    $invoke($analysis, 'saveAnalysisResults', [$node, $results]);
    $node = Node::load($node->id());
    $assigned = array_map('intval', array_column($node->get('field_ttd_topics')->getValue(), 'target_id'));
    sort($assigned);
    $expected = [(int) $first_id, (int) $second_id];
    sort($expected);
    $assert($assigned === $expected, 'Reanalysis changed the exact topic assignments.');
    $invoke($sync, 'mergeEntity', [$entity($base, $first_name)]);
    $assert($get($base)->getName() === $prefix . ' Editor disambiguation', 'Sync overwrote an editor name.');
  }
  $stats = ['processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
  $progress = new class { public function advance() {} };
  $invoke(new \Drupal\ttd_topics\Commands\CreateTtdTopicsCommand(), 'processBatch', [[(object) ['ttd_id' => $base, 'name' => $first_name]], &$stats, $progress]);
  $assert($stats['errors'] === 0 && $get($base)->getName() === $prefix . ' Editor disambiguation', 'Term rebuilding overwrote an editor name.');
  $response = $controller->createTopicTerm(new Request([], [], [], [], [], [], json_encode(['topic_data' => ['ttd_id' => $base] + $entity($base, $first_name)])));
  $data = json_decode($response->getContent(), TRUE);
  $assert($data['data']['name'] === $prefix . ' Editor disambiguation', 'Manual topic response returned a stale provider name.');

  $unclaimed = Term::create(['vid' => 'ttd_topics', 'name' => $prefix . ' Unclaimed']);
  $unclaimed->save();
  $claim_id = $next_id++;
  $preview = $invoke($analysis, 'getOrCreateTerm', [$unclaimed->getName(), $claim_id, $entity($claim_id, $unclaimed->getName()), FALSE]);
  $storage->resetCache();
  $assert($preview === NULL && Term::load($unclaimed->id())->get('field_ttd_id')->isEmpty(), 'Preview claimed an unlinked topic.');
  $claimed = $invoke($analysis, 'getOrCreateTerm', [$unclaimed->getName(), $claim_id, $entity($claim_id, $unclaimed->getName())]);
  $assert((int) $claimed === (int) $unclaimed->id(), 'A single unclaimed topic was not reused.');

  $ambiguous_name = $prefix . ' Ambiguous';
  foreach ([1, 2] as $_) {
    Term::create(['vid' => 'ttd_topics', 'name' => $ambiguous_name])->save();
  }
  $ambiguous_id = $next_id++;
  $invoke($analysis, 'getOrCreateTerm', [$ambiguous_name, $ambiguous_id, $entity($ambiguous_id, $ambiguous_name)]);
  $ambiguous = $storage->loadByProperties(['vid' => 'ttd_topics', 'name' => $ambiguous_name]);
  $assert(count($ambiguous) === 3, 'Ambiguous unclaimed names were guessed as an identity.');
  $assert(count(array_filter($ambiguous, static fn($term) => $term->get('field_ttd_id')->isEmpty())) === 2, 'Ambiguous topics were modified.');

  $race_name = $prefix . ' Concurrent';
  Term::create(['vid' => 'ttd_topics', 'name' => $race_name])->save();
  $race_ids = range($base + 20, $base + 22);
  $workers = [];
  for ($worker = 0; $worker < 6; $worker++) {
    $process = proc_open([
      'env', 'TB_IDENTITY_WORKER_ID=' . $race_ids[$worker % 3], 'TB_IDENTITY_WORKER_NAME=' . $race_name,
      '/var/www/html/vendor/bin/drush', '--uri=http://identity.test', 'php:script', __DIR__ . '/topic-identity-worker.php',
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $assert(is_resource($process), 'Could not start an identity worker.');
    fclose($pipes[0]);
    $workers[] = [$process, $pipes];
  }
  $errors = [];
  foreach ($workers as [$process, $pipes]) {
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
      $errors[] = $output;
    }
  }
  $assert(!$errors, 'Identity worker failed: ' . implode(' ', $errors));
  $storage->resetCache();
  $assert(count($storage->loadByProperties(['vid' => 'ttd_topics', 'name' => $race_name])) === 3, 'Concurrent writes created duplicate or merged topics.');
  foreach ($race_ids as $race_id) {
    $assert($get($race_id)->get('field_ttd_id')->value === (string) $race_id, 'Concurrent workers mixed entity IDs.');
  }
  echo "PASS six concurrent Drupal workers kept three same-name identities separate.\n";

  echo "PASS Drupal topic identity, URLs, editor name, preview, and repeated apply: {$assertions} assertions.\n";
}
finally {
  if ($node && $node->id()) {
    $node->delete();
  }
  $storage->resetCache();
  $terms = $storage->getQuery()->accessCheck(FALSE)->condition('vid', 'ttd_topics')->condition('name', $prefix . '%', 'LIKE')->execute();
  $storage->delete($storage->loadMultiple($terms));
  foreach (['ttd_entity_post_ids', 'ttd_entity_schema_types', 'ttd_entity_wb_categories'] as $table) {
    $connection->delete($table)->condition('entity_id', $ids, 'IN')->execute();
  }
  $connection->delete('ttd_entities')->condition('ttd_id', $ids, 'IN')->execute();
  if ($type) {
    $type->delete();
  }
  echo "Cleanup: disposable Drupal identity fixtures removed.\n";
}
