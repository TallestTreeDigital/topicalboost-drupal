<?php
/** Run only in the isolated Drupal/API/PostgreSQL fixture. */
use Drupal\ttd_topics\Service\ContentUrlRepairService as Repair;
use Drupal\advancedqueue\Job;
use Drupal\node\Entity\Node;
if (TOPICALBOOST_API_ENDPOINT !== 'http://api:3000/api/topicalboost' || \Drupal::request()->getHost() !== 'drupal.example.test') throw new RuntimeException('Isolated fixture required.');
$checks = 0;
$assert = static function ($value, $label) use (&$checks) {
  if (!$value) throw new RuntimeException('FAIL: ' . $label);
  $checks++; echo "PASS: $label\n";
};
$http = static function ($method, $path, $body = NULL) {
  $options = ['timeout' => 20]; if ($body !== NULL) $options['json'] = $body;
  return json_decode((string) \Drupal::httpClient()->request($method, 'http://api:3000' . $path, $options)->getBody(), TRUE);
};
$state_store = \Drupal::state();
$state_store->set('ttd_topics.content_url_repair.disabled', FALSE);
$state_store->delete('ttd_topics.content_url_repair.enabled');
$state_store->delete(Repair::STATE);
$repair = \Drupal::service('ttd_topics.content_url_repair');
$assert(!$repair->queueIfDue(), 'Configured sites without opt-in queue no archive job');
$batch_log_before = @file_get_contents('/evidence/api-batches.jsonl');
$repair->run();
$assert($state_store->get(Repair::STATE, []) === [] && @file_get_contents('/evidence/api-batches.jsonl') === $batch_log_before, 'A leftover archive callback without opt-in performs no scan or HTTP request');
$state_store->set('ttd_topics.content_url_repair.enabled', 'false');
$assert(!$repair->queueIfDue(), 'Only explicit opt-in enables archive repair');
$state_store->set('ttd_topics.content_url_repair.enabled', TRUE);
$state_store->set('ttd_topics.content_url_repair.disabled', TRUE);
$state_store->delete(Repair::STATE);
$ids = [];
for ($i = 0; $i < 50; $i++) {
  $node = Node::create(['type' => 'article', 'title' => 'Archive fixture ' . $i, 'status' => 1,
    'field_ttd_last_analyzed' => gmdate('Y-m-d\TH:i:s')]); $node->save();
  $ids[] = (int) $node->id();
  $http('POST', '/fixture/seed', ['platform' => 'drupal', 'postId' => $node->id(), 'url' => 'https://drupal.example.test/old/' . $node->id(), 'status' => 'publish']);
}
$missing = Node::create(['type' => 'article', 'title' => 'Missing API fixture', 'status' => 1,
  'field_ttd_last_analyzed' => gmdate('Y-m-d\TH:i:s')]); $missing->save();
foreach ([0,1] as $status) Node::create(['type' => 'article', 'title' => 'Excluded unanalyzed fixture', 'status' => $status])->save();
$db = \Drupal::database();
$db->delete('advancedqueue')->condition('type', ['ttd_content_url_sync', Repair::JOB], 'IN')->execute();
$repair = \Drupal::service('ttd_topics.content_url_repair');
$processor = \Drupal::service('advancedqueue.processor');
$assert(\Drupal::service('plugin.manager.advancedqueue_job_type')->hasDefinition(Repair::JOB), 'Installed Drupal discovers the archive repair job type');
$assert(!$repair->queueIfDue(), 'Paused archive repair queues no work');
$state_store->set('ttd_topics.content_url_repair.disabled', FALSE);
$assert($repair->queueIfDue() && !$repair->queueIfDue(), 'Cron scheduling creates one AdvancedQueue job');
$queue = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load(Repair::QUEUE);
// Accelerate only fixture clocks. Production callbacks retain the minute delay.
$run = static function () use ($db, $queue, $processor) {
  $db->update('advancedqueue')->fields(['available' => time() - 1])->condition('type', Repair::JOB)->condition('state','queued')->execute();
  $job = $queue->getBackend()->claimJob();
  if (!$job || $job->getType() !== Repair::JOB) throw new RuntimeException('Unexpected fixture job.');
  $result = $processor->processJob($job, $queue);
  if ($result->getState() !== Job::STATE_SUCCESS) throw new RuntimeException($result->getMessage());
};
$http('POST', '/fixture/fail-next/drupal', []);
$run(); $state = $state_store->get(Repair::STATE);
$assert($state['status'] === 'retrying' && $state['cursor'] === 0 && $state['failed_batches'] === 1, 'An injected HTTP 503 retains progress for retry instead of completing the pass');
$assert($state['next_at'] >= time() + 55, 'Failed batches wait before retry');
$repair->run();
$assert($state_store->get(Repair::STATE)['requests'] === $state['requests'], 'Early callbacks do not bypass backoff');
$state['next_at'] = 0; $state_store->set(Repair::STATE, $state);
$started = microtime(TRUE); $run(); $first = $state_store->get(Repair::STATE);
$assert($first['cursor'] > 0 && $first['batches'] === 1 && $first['requests'] === 2, 'Real AdvancedQueue confirms one bounded batch after recovery');
$assert($first['next_at'] >= time() + 55, 'Successful batches wait a minute before continuing');
$state_store->delete('ttd_topics.content_url_repair.enabled');
$batch_log_before = @file_get_contents('/evidence/api-batches.jsonl');
$run();
$assert(!$repair->queueIfDue() && $state_store->get(Repair::STATE) === $first && @file_get_contents('/evidence/api-batches.jsonl') === $batch_log_before, 'Revoking opt-in stops queued work and preserves the confirmed checkpoint');
$state_store->set('ttd_topics.content_url_repair.enabled', TRUE);
$db->delete('advancedqueue')->condition('type', Repair::JOB)->condition('state','queued')->execute();
$assert($repair->queueIfDue() && !$repair->queueIfDue(), 'Cron recovers a lost continuation without duplicate jobs');
$new = Node::create(['type'=>'article','title'=>'Created after archive boundary','status'=>1]); $new->save();
for ($i = 0; $i < 8; $i++) {
  $state = $state_store->get(Repair::STATE); if ($state['status'] === 'complete') break;
  $state['next_at'] = 0; $state_store->set(Repair::STATE, $state); $run();
}
$state = $state_store->get(Repair::STATE);
$assert($state['status'] === 'complete' && $state['completed_at'] && $state['cursor'] <= $state['end'] && $new->id() > $state['end'], 'The fixed archive pass reaches completion despite new nodes');
$assert($state['repaired'] >= 50 && $state['skipped'] >= 1, 'Bounded batches repair stale rows and report missing rows');
foreach ($ids as $id) {
  $row = $http('GET', '/fixture/row/drupal/' . $id);
  if ($row['url'] !== \ttd_topics_get_node_absolute_url(Node::load($id)) || !in_array('https://drupal.example.test/old/' . $id, $row['urlAliases'], TRUE)) throw new RuntimeException('Archive row or alias mismatch.');
}
$assert(TRUE, 'Every stale node URL is repaired with its previous URL retained');
$assert(!$http('GET', '/fixture/row/drupal/' . $missing->id()), 'Missing content does not create a new API row');
$before = $state['requests']; $repair->run();
$assert(!$repair->queueIfDue() && $state_store->get(Repair::STATE)['requests'] === $before, 'Completed passes do not restart or send requests');
$node = Node::load($ids[0]); $node->setTitle('Text-only archive edit')->save();
$count = (int) $db->select('advancedqueue','q')->condition('type','ttd_content_url_sync')->condition('state','queued')->countQuery()->execute()->fetchField();
$assert($count === 0, 'Text-only saves after confirmation queue no metadata HTTP work');
$state_store->delete('ttd_topics.content_url_repair.enabled');
$node->setCreatedTime($node->getCreatedTime() + 1)->save();
$count = (int) $db->select('advancedqueue','q')->condition('type','ttd_content_url_sync')->condition('state','queued')->countQuery()->execute()->fetchField();
$assert($count === 1, 'Later publication metadata changes still queue a refresh');
file_put_contents('/evidence/drupal-auto-measurements.json', json_encode(['checks'=>$checks,'state'=>$state,
  'elapsed_active_test_seconds'=>round(microtime(TRUE)-$started,3),'fixture_delays_accelerated'=>TRUE], JSON_PRETTY_PRINT));
echo "Drupal automatic repair passed $checks assertions.\n";
