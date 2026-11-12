<?php

/**
 * Real Drupal/Scheduler/AdvancedQueue -> HTTP API -> database/export regression.
 * Run with drush php:script in the disposable canonical URL fixture only.
 */

use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\Event\AdvancedQueueEvents;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Symfony\Component\Process\Process;

if (TOPICALBOOST_API_ENDPOINT !== 'http://api:3000/api/topicalboost'
  || \Drupal::request()->getHost() !== 'drupal.example.test') {
  throw new RuntimeException('Run only in the isolated canonical URL fixture.');
}
$assertions = 0;
$assert = static function ($ok, $label) use (&$assertions) {
  if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
  $assertions++;
  echo "PASS: {$label}\n";
};
$http = static function ($method, $path, $body = NULL, $headers = []) {
  $options = ['headers' => $headers, 'timeout' => 20];
  if ($body !== NULL) { $options['json'] = $body; }
  $response = \Drupal::httpClient()->request($method, 'http://api:3000' . $path, $options);
  return json_decode((string) $response->getBody(), TRUE);
};
\Drupal::configFactory()->getEditable('ttd_topics.settings')
  ->set('enabled_content_types', ['article'])->set('block_until_analyzed', FALSE)
  ->set('topicalboost_api_key', 'e2e-drupal-key')
  ->set('site_base_url', 'https://drupal.example.test')->save();
\Drupal::state()->set('topicalboost.analytics_inventory.disabled', TRUE);
\Drupal::moduleHandler()->loadInclude('ttd_topics', 'install');
ttd_topics_ensure_enabled_content_type_fields(['article']);
$type = NodeType::load('article');
$type->setThirdPartySetting('scheduler', 'publish_enable', TRUE)->save();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$queue = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load('ttd_topics_analysis');
$processor = \Drupal::service('advancedqueue.processor');
$db = \Drupal::database();
$pending = static fn($id) => (int) $db->select('advancedqueue', 'q')->condition('type', 'ttd_content_url_sync')
  ->condition('payload', json_encode(['node_id' => (int) $id]))->condition('state', 'queued')->countQuery()->execute()->fetchField();
$drain = static function () use ($queue, $processor, $assert) {
  for ($i = 0; $i < 30 && $job = $queue->getBackend()->claimJob(); $i++) {
    if ($job->getType() !== 'ttd_content_url_sync') {
      throw new RuntimeException('Unexpected job in the URL-only fixture: ' . $job->getType());
    }
    $result = $processor->processJob($job, $queue);
    if ($job->getType() === 'ttd_content_url_sync' && $result->getState() !== Job::STATE_SUCCESS) {
      throw new RuntimeException($result->getMessage());
    }
  }
};
$assert(\Drupal::service('plugin.manager.advancedqueue_job_type')->hasDefinition('ttd_content_url_sync'), 'Installed Drupal discovers the new AdvancedQueue worker');
$node = Node::create(['type' => 'article', 'title' => 'Canonical scheduled fixture', 'status' => 0,
  'field_ttd_last_analyzed' => gmdate('Y-m-d\TH:i:s'), 'publish_on' => time() - 60]);
$node->save();
$id = (int) $node->id();
$initial = $http('POST', '/fixture/seed', ['platform' => 'drupal', 'postId' => $id, 'url' => 'https://drupal.example.test/node/' . $id]);
$row = static fn() => $http('GET', '/fixture/row/drupal/' . $id);
$assert(!$pending($id) && !$node->isPublished(), 'Scheduled analyzed nodes wait for publication');
\Drupal::service('scheduler.manager')->publish();
\Drupal::entityTypeManager()->getStorage('node')->resetCache([$id]);
$node = Node::load($id);
$assert($node->isPublished() && $pending($id) === 1, 'Real Scheduler publication queues a deferred metadata job');
$alias = PathAlias::create(['path' => '/node/' . $id, 'alias' => '/article/original-' . $id, 'langcode' => 'en']);
$alias->save();
$assert($pending($id) === 1, 'A Pathauto-style alias save coalesces pending work');
$drain();
$first = $row();
$assert($first['id'] === $initial['id'] && $first['url'] === 'https://drupal.example.test/article/original-' . $id && $first['status'] === 'publish', 'Real worker sends the post-save alias to the same API/database row');
$assert(in_array($initial['url'], $first['urlAliases'], TRUE), 'Publication retains the earlier node URL alias');
$alias->setAlias('/article/changed-' . $id)->save();
$drain();
$second = $row();
$assert($second['url'] === 'https://drupal.example.test/article/changed-' . $id && in_array($first['url'], $second['urlAliases'], TRUE), 'A later path-alias edit clears cached lookups and reaches the API');
$alias->delete();
$drain();
$assert($row()['url'] === $initial['url'] && in_array($second['url'], $row()['urlAliases'], TRUE), 'Deleting an alias restores the canonical node path and retains history');
$other = Node::create(['type' => 'article', 'title' => 'Alias reassignment fixture', 'status' => 0,
  'field_ttd_last_analyzed' => gmdate('Y-m-d\TH:i:s')]);
$other->save();
$http('POST', '/fixture/seed', ['platform' => 'drupal', 'postId' => (int) $other->id(), 'url' => 'https://drupal.example.test/node/' . $other->id()]);
$other->setPublished()->save();
$drain();
$moved = PathAlias::create(['path' => '/node/' . $id, 'alias' => '/article/reassigned-' . $id, 'langcode' => 'en']);
$moved->save();
$drain();
$moved->setPath('/node/' . $other->id())->save();
$assert($pending($id) === 1 && $pending($other->id()) === 1, 'Reassigning an alias queues both the old and new node IDs');
$drain();
$assert($row()['url'] === $initial['url'] && $http('GET', '/fixture/row/drupal/' . $other->id())['url'] === 'https://drupal.example.test/article/reassigned-' . $id, 'Both API records follow the actual alias reassignment');
$edited = FALSE;
\Drupal::service('event_dispatcher')->addListener(AdvancedQueueEvents::POST_PROCESS, static function ($event) use ($id, &$edited, $pending, $assert) {
  if ($edited || $event->getJob()->getType() !== 'ttd_content_url_sync') { return; }
  $edited = TRUE;
  PathAlias::create(['path' => '/node/' . $id, 'alias' => '/article/during-processing-' . $id, 'langcode' => 'en'])->save();
  $assert($pending($id) === 1, 'A real processing job allows a queued follow-up');
});
$node->setCreatedTime($node->getCreatedTime() + 1)->save();
$drain();
$assert($edited && $row()['url'] === 'https://drupal.example.test/article/during-processing-' . $id, 'An edit during processing reaches the current API URL');
// An actual 404 from the API must retry rather than report a successful sync.
$missing = Node::create(['type' => 'article', 'title' => 'Missing API record fixture', 'status' => 1,
  'field_ttd_last_analyzed' => gmdate('Y-m-d\TH:i:s')]);
$missing->save();
$job = $queue->getBackend()->claimJob();
$assert($job && $job->getType() === 'ttd_content_url_sync', 'A published analyzed insert queues the real worker');
$failure = $processor->processJob($job, $queue);
$retry = $db->select('advancedqueue', 'q')->fields('q', ['state', 'num_retries', 'available'])->condition('job_id', $job->getId())->execute()->fetchAssoc();
$assert($failure->getState() === Job::STATE_FAILURE && $retry['state'] === 'queued' && (int) $retry['num_retries'] === 1, 'A real API failure is retained for AdvancedQueue retry');
$http('POST', '/fixture/seed', ['platform' => 'drupal', 'postId' => (int) $missing->id(), 'url' => 'https://drupal.example.test/stale-missing']);
$db->update('advancedqueue')->fields(['available' => 0])->condition('job_id', $job->getId())->execute();
$drain();
$assert($http('GET', '/fixture/row/drupal/' . $missing->id())['url'] === 'https://drupal.example.test/node/' . $missing->id(), 'The actual retry succeeds after the API record is available');
$stale = 'https://drupal.example.test/stale-record';
$http('POST', '/fixture/seed', ['platform' => 'drupal', 'postId' => $id, 'url' => $stale, 'status' => 'publish']);
$command = static function ($args) {
  $process = new Process(array_merge(['/srv/drupal/vendor/bin/drush', '--root=/srv/drupal/web', '--uri=https://drupal.example.test', 'topicalboost:sync-content-urls'], $args));
  $process->setTimeout(30)->mustRun();
  return $process->getOutput();
};
$output = $command(['--node-ids=' . $id, '--dry-run']);
$assert($row()['url'] === $stale && strpos($output, 'repaired: 0') !== FALSE, 'Real Drush dry run performs no API repair');
$output = $command(['--node-ids=' . $id]);
$assert(strpos($output, 'repaired: 1') !== FALSE && $row()['url'] === 'https://drupal.example.test/article/during-processing-' . $id, 'Real Drush reconciliation repairs the reviewed CMS ID');
$output = $command(['--node-ids=' . $id]);
$assert(strpos($output, 'unchanged: 1') !== FALSE, 'A repeated real repair is idempotent');
$output = $command(['--limit=1', '--offset=0', '--dry-run']);
$assert(strpos($output, 'Candidates: 1') !== FALSE, 'The real Drupal archive query honors its scan bound');
$inventory = $http('GET', '/internal/analytics/content-inventory?domain=drupal.example.test', NULL, ['Authorization' => 'Bearer e2e-inventory-token']);
$exported = array_values(array_filter($inventory['rows'], static fn($entry) => $entry['post_id'] === (string) $id))[0] ?? [];
$assert(($exported['url'] ?? '') === $row()['url'] && in_array($stale, $exported['url_aliases'] ?? [], TRUE), 'Authenticated Analytics export reads the current Drupal URL and aliases from the real database');
\Drupal::configFactory()->getEditable('system.date')->set('timezone.default', 'America/New_York')->save();
$node = Node::load($id);
$node->setCreatedTime(strtotime('2026-09-02T03:41:00Z'))->save();
$drain();
$evening = $row();
$assert($evening['publishedAt'] === '2026-09-02T03:41:00.000Z' && substr($evening['publishedDate'], 0, 10) === '2026-09-01', 'URL worker retains the released UTC time and article calendar day');
echo "Drupal URL sync E2E passed. Assertions: {$assertions}. Node ID: {$id}.\n";
