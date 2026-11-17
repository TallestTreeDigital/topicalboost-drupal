<?php

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ttd_topics\Controller\BulkAnalysisController;
use Drupal\ttd_topics\Service\BulkAnalysisLifecycle as Lifecycle;
use Drupal\ttd_topics\Service\BulkAnalysisRecovery as Recovery;
use Drupal\ttd_topics\Service\PublishDateSync as Dates;
use Symfony\Component\HttpFoundation\Request;

if (getenv('TTD_DESCRIPTION_FIXTURE') !== 'owned-description-repair'
  || Drupal::root() !== '/app/web'
  || !str_starts_with(Drupal::database()->getConnectionOptions()['host'], 'tb-drupal-description-')
  || TOPICALBOOST_API_ENDPOINT !== 'http://127.0.0.1:18080') {
  throw new RuntimeException('This test requires the isolated Drupal fixture.');
}
$checks = 0;
$verify = static function ($condition, $message) use (&$checks) {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  $checks++; echo 'PASS: ' . $message . "\n";
};
$count_probes = static fn() => is_file('/app/recovery-receipts.jsonl') ? count(file('/app/recovery-receipts.jsonl')) : 0;
$read_dates = static fn() => is_file('/app/date-receipts.jsonl')
  ? array_map(static fn($line) => json_decode($line, TRUE), file('/app/date-receipts.jsonl', FILE_IGNORE_NEW_LINES)) : [];
$state = Drupal::keyValue('state');
$state->delete(Lifecycle::REQUEST); $state->delete(Lifecycle::TERMINAL); $state->delete(Recovery::EMPTY_STATE);
@unlink('/app/recovery-receipts.jsonl'); @unlink('/app/active-request.json');
$recovery = new Recovery();
$verify($recovery->recover() === NULL && $recovery->recover() === NULL && $count_probes() === 1,
  'Real HTTP recovery caches a confirmed empty result');
$state->delete(Recovery::EMPTY_STATE);
file_put_contents('/app/active-request.json', json_encode(['request_id' => 9099, 'content_count' => 105, 'created_at' => '2026-11-17T12:00:00Z']));
$state->set('topicalboost.bulk_analysis.apply_progress', ['stage' => 'complete']);
$verify($recovery->recover() === 9099 && Lifecycle::current(9099), 'Real Guzzle recovery restores the request in Drupal storage');
$pending = static fn() => (int) Drupal::database()->select('advancedqueue', 'q')
  ->condition('queue_id', 'ttd_topics_analysis')->condition('type', 'ttd_bulk_analysis_poller')
  ->condition(Lifecycle::requestCondition(9099))->condition('state', 'queued')->countQuery()->execute()->fetchField();
$verify($pending() === 1 && $state->get('topicalboost.bulk_analysis.apply_progress') === NULL
  && $state->get('topicalboost.bulk_analysis.content_count') === 105, 'Recovery restores counts and queues one native AdvancedQueue poller');
$recovery->recover();
$verify($pending() === 1 && $count_probes() === 2, 'A tracked recovered request makes no second probe or poller');
$controller = new BulkAnalysisController(Drupal::database(), Drupal::configFactory(), Drupal::entityTypeManager());
$response = json_decode($controller->pollAnalysis(Request::create('/api/topicalboost/bulk/poll'))->getContent(), TRUE);
$verify($response['success'] && $response['data']['request_id'] === 9099, 'The editor poll returns the recovered server request');
$response = json_decode($controller->initiateAnalysis(Request::create('/api/topicalboost/bulk/initiate', 'POST'))->getContent(), TRUE);
$verify(!$response['success'] && Lifecycle::current(9099), 'Initiation refuses to replace the recovered active request');
Lifecycle::stop(9099, 'cancelled', 'Fixture cancellation'); $state->delete(Lifecycle::REQUEST);
$state->delete(Recovery::EMPTY_STATE);
$verify($recovery->recover() === NULL && !$state->get(Lifecycle::REQUEST), 'Real recovery never revives a locally cancelled request');
@unlink('/app/active-request.json');

// Build fixture nodes before enabling their analysis type.
Drupal::configFactory()->getEditable('ttd_topics.settings')->set('enabled_content_types', [])->save();
if (!NodeType::load('date_story')) NodeType::create(['type' => 'date_story', 'name' => 'Date fixture'])->save();
$ids = [];
for ($i = 0; $i < 105; $i++) {
  $node = Node::create(['type' => 'date_story', 'title' => 'Date fixture ' . $i, 'status' => 1, 'created' => 1794882600]);
  $node->save(); $ids[] = (int) $node->id();
}
Node::create(['type' => 'date_story', 'title' => 'Draft date fixture', 'status' => 0])->save();
Drupal::configFactory()->getEditable('ttd_topics.settings')->set('enabled_content_types', ['date_story'])->save();
Drupal::configFactory()->getEditable('system.date')->set('timezone.default', 'America/New_York')->save();
$state->delete(Dates::STATE); @unlink('/app/date-receipts.jsonl');
$dates = Drupal::service('ttd_topics.publish_dates');
$verify(Drupal::service('plugin.manager.advancedqueue_job_type')->hasDefinition(Dates::JOB), 'Drupal discovers the publish-date queue job');
$verify($dates->queueIfDue(), 'Ordinary scheduling creates the separate catch-up queue');
$queue = Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load(Dates::QUEUE);
Drupal::database()->update('advancedqueue')->fields(['available' => time() - 1])->condition('queue_id', Dates::QUEUE)->execute();
$job = $queue->getBackend()->claimJob();
$verify($job && $job->getType() === Dates::JOB, 'A native queue claim returns the publish-date job');
Drupal::service('advancedqueue.processor')->processJob($job, $queue);
$verify($state->get(Dates::STATE)['upper_id'] === NULL && !$read_dates(), 'The first native worker saves a delayed checkpoint without scanning');
$due = static function () use ($state) {
  $checkpoint = $state->get(Dates::STATE); $checkpoint['next_at'] = 0; $state->set(Dates::STATE, $checkpoint);
};
$due(); file_put_contents('/app/dates-fail-once', '503');
$dates->run(); $failed = $state->get(Dates::STATE);
$verify($failed['status'] === 'retrying' && $failed['processed'] === 0 && $failed['cursor'] === $failed['upper_id'] + 1,
  'A real HTTP 503 keeps the archive cursor and schedules recovery');
$due(); $first = $dates->run(); $receipt = $read_dates(); $body = end($receipt)['body'];
$verify($first['processed'] === 100 && $first['status'] === 'pending' && count($body['posts']) === 100,
  'Real SQL keyset paging and HTTP transport deliver one 100-post batch');
$verify($body['posts'][0]['published_at'] === '2026-11-17T02:30:00Z' && $body['posts'][0]['published_date'] === '2026-11-16',
  'Drupal sends UTC timestamps and configured article dates across the day boundary');
$verify(end($receipt)['authenticated'] && end($receipt)['platform'] === 'drupal', 'Publish-date delivery retains Drupal authentication and identity');
$due(); $complete = $dates->run();
$verify($complete['status'] === 'complete' && $complete['processed'] === 105 && $complete['written'] === 105,
  'The bounded archive finishes exactly once without sending drafts or other types');
$successful = array_filter($read_dates(), static fn($row) => $row['status'] === 200);
$sent = [];
foreach ($successful as $row) foreach ($row['body']['posts'] as $post) $sent[] = $post['post_id'];
sort($sent); sort($ids);
$verify($sent === $ids, 'Every enabled published node appears once in confirmed batches');
$before = count($read_dates()); $dates->run();
$verify(count($read_dates()) === $before && !$dates->queueIfDue(), 'Completed generations neither scan again nor queue another batch');
echo 'Drupal recovery and publish-date runtime checks passed: ' . $checks . " assertions.\n";
