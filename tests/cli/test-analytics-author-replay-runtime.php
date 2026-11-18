<?php

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ttd_topics\Service\AnalyticsInventoryService as Inventory;
use Drupal\user\Entity\User;

if (getenv('TTD_DESCRIPTION_FIXTURE') !== 'owned-description-repair'
  || Drupal::root() !== '/app/web'
  || !str_starts_with(Drupal::database()->getConnectionOptions()['host'], 'tb-drupal-description-')
  || TOPICALBOOST_API_ENDPOINT !== 'http://127.0.0.1:18080') {
  throw new RuntimeException('This test requires the isolated Drupal fixture.');
}

$checks = 0;
$verify = static function ($condition, string $message) use (&$checks) {
  if (!$condition) throw new RuntimeException('FAIL: ' . $message);
  $checks++;
  echo "PASS: $message\n";
};
$config = Drupal::configFactory()->getEditable('ttd_topics.settings');
$config->set('topicalboost_api_key', 'author-fixture')->set('enabled_content_types', [])->save();
$author = User::create(['name' => 'Fixture Writer', 'status' => 1]);
$author->save();
foreach (['author_story', 'author_other'] as $type) {
  NodeType::create(['type' => $type, 'name' => $type])->save();
}
$create = static function (string $type, int $status) use ($author) {
  $node = Node::create(['type' => $type, 'title' => 'Replay fixture', 'status' => $status, 'uid' => $author->id()]);
  $node->save();
  return (int) $node->id();
};
$ids = [];
for ($i = 0; $i < 102; $i++) {
  $ids[] = $create('author_story', 1);
}
$create('author_story', 0);
$create('author_other', 1);
$config->set('enabled_content_types', ['author_story', 'author_other'])->save();

$status = static function (?bool $allowed, array $types = ['author_story']) {
  $analytics = ['inventoryPostTypes' => $types];
  if ($allowed !== NULL) $analytics['authorReplay'] = $allowed;
  file_put_contents('/app/inventory-status.json', json_encode(['enabled' => TRUE, 'connected' => TRUE, 'analytics' => $analytics]));
};
$answer = static function (bool $allowed, ?int $written = NULL) {
  $response = ['ok' => TRUE, 'authorReplay' => $allowed];
  if ($written === NULL) {
    $response += ['deferred' => TRUE, 'retryAfterSeconds' => 97];
  }
  else {
    $response += ['siteMatches' => TRUE, 'summary' => ['rowsAccepted' => $written, 'rowsWritten' => $written]];
  }
  file_put_contents('/app/inventory-answer.json', json_encode($response));
};
$state = Drupal::keyValue('state');
$state_key = 'topicalboost.analytics_author_replay.v1';
$checkpoint = static fn() => $state->get($state_key);
$receipts = static fn() => array_map(static fn($line) => json_decode($line, TRUE),
  file('/app/receipts.jsonl', FILE_IGNORE_NEW_LINES));
file_put_contents('/app/receipts.jsonl', '');

$service = Drupal::service('ttd_topics.analytics_inventory');
$manager = Drupal::service('plugin.manager.queue_worker');
$queue_name = 'ttd_analytics_author_replay';
$verify($manager->hasDefinition($queue_name), 'Drupal discovers the author replay worker');
$worker = $manager->createInstance($queue_name);
$queue = Drupal::queue($queue_name);
$tick = static function () use ($queue, $worker) {
  $item = $queue->claimItem(180);
  if (!$item) throw new RuntimeException('No replay item could be claimed.');
  try {
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    return 0;
  }
  catch (DelayedRequeueException $error) {
    $queue->delayItem($item, $error->getDelay());
    return $error->getDelay();
  }
};
$due = static function () use ($state, $state_key, $checkpoint, $queue_name) {
  $state->set($state_key, array_replace($checkpoint(), ['next_at' => 0]));
  Drupal::database()->update('queue')->fields(['expire' => 0])->condition('name', $queue_name)->execute();
};

foreach ([NULL, FALSE] as $allowed) {
  $status($allowed);
  $service->probe();
  $service->queueAuthorReplayIfDue();
  $verify(!$checkpoint() && !$queue->numberOfItems(), 'A missing or off switch starts no replay');
}
$status(TRUE);
$verify($service->probe()['queued'] === FALSE, 'The weekly probe still does not queue a legacy full scan');
$service->queueAuthorReplayIfDue();
$service->queueAuthorReplayIfDue();
$first = $checkpoint();
$verify($first['page'] === 1 && $first['cursor'] === 0 && $queue->numberOfItems() === 1,
  'Cron starts one replay with a durable checkpoint');

$answer(TRUE);
$verify($tick() === 97 && $queue->numberOfItems() === 1, 'A deferral keeps the same queue item for the requested delay');
$first_send = $receipts()[0];
$deferred = $checkpoint();
$verify(array_diff_assoc($deferred, $first) === ['next_at' => $deferred['next_at']]
  && $deferred['next_at'] >= time() + 96 && !$queue->claimItem(180),
  'A deferral changes only next_at and leaves the page unacknowledged');
$body = $first_send['body'];
$verify(str_starts_with($body['sync_id'], 'topicalboost-drupal-authors-v1-')
  && $body['batch']['per_page'] === 100 && array_column($body['posts'], 'post_id') === array_slice($ids, 0, 100),
  'The Drupal replay selects the first 100 published in-scope nodes in ID order');
$verify($first_send['platform'] === 'drupal' && $first_send['authenticated']
  && $body['posts'][0]['author']['name'] === 'Fixture Writer'
  && $body['posts'][0]['authors'] === [$body['posts'][0]['author']],
  'A generic Drupal site sends its resolved article authors and keeps its Drupal identity');

$queue->deleteQueue();
$service->queueAuthorReplayIfDue();
$verify(!$queue->numberOfItems(), 'A lost wakeup stays quiet until the deferral ends');
$due();
$service->queueAuthorReplayIfDue();
$answer(FALSE);
$verify($tick() === 0 && !$queue->numberOfItems() && $checkpoint()['cursor'] === 0
  && $receipts()[1]['body']['batch'] === $body['batch'], 'Switch-off stops the deferred page without advancing it');
$service->queueAuthorReplayIfDue();
$verify(!$queue->numberOfItems(), 'Cron does not restart a switched-off replay');

$answer(TRUE, 1);
$service->syncNode($ids[0]);
$service->queueAuthorReplayIfDue();
$verify($queue->numberOfItems() === 1 && $checkpoint()['sync_id'] === $first['sync_id'],
  'A single-node delivery can turn the switch back on for the same replay');
file_put_contents('/app/fail-once', '503');
$before = $checkpoint();
try {
  $tick();
  throw new RuntimeException('A failed write must surface to the worker.');
}
catch (\GuzzleHttp\Exception\ServerException $error) {
  $verify($checkpoint() === $before, 'An HTTP error keeps the unacknowledged page');
}
$due();
$answer(TRUE, 100);
$verify($tick() === 60 && $checkpoint()['page'] === 2 && $checkpoint()['cursor'] === $ids[99],
  'An acknowledged page saves its last node ID and paces the continuation');
$again = $receipts()[4]['body'];
$verify($again['sync_id'] === $body['sync_id'] && $again['batch'] === $body['batch']
  && array_column($again['posts'], 'post_id') === array_slice($ids, 0, 100), 'Deferral and failure repeat the same page');

Node::load($ids[0])->delete();
$queue->deleteQueue();
$due();
$service->queueAuthorReplayIfDue();
$answer(TRUE, 2);
$verify($tick() === 0 && $checkpoint()['complete'] && !$queue->numberOfItems(),
  'The recovered final page completes the archive and leaves no queue item');
$page_two = $receipts()[5]['body'];
$verify($page_two['batch']['complete'] && array_column($page_two['posts'], 'post_id') === array_slice($ids, 100),
  'Deleting an earlier node does not shift the next page');
$service->probe();
$service->queueAuthorReplayIfDue();
$verify(!$queue->numberOfItems(), 'The next weekly probe does not restart a completed replay');

$status(TRUE, ['author_story', 'author_other']);
$service->probe();
$service->queueAuthorReplayIfDue();
$second = $checkpoint();
$verify($second['sync_id'] !== $first['sync_id'] && $second['cursor'] === 0 && $queue->numberOfItems() === 1,
  'Changing the effective content types starts a new archive');
$config->set('enabled_content_types', ['author_other', 'author_story'])->save();
$service->queueAuthorReplayIfDue();
$verify($checkpoint() === $second, 'Reordering the content types keeps the same archive');
$config->set('topicalboost_api_key', 'another-author-fixture')->save();
$status(FALSE);
$verify($tick() === 0 && count($receipts()) === 6, 'A worker from the old connection cannot send to the new one');
$service->queueAuthorReplayIfDue();
$verify(!$queue->numberOfItems(), 'A new connection does not inherit the old switch');
$status(TRUE, ['author_story', 'author_other']);
$service->queueAuthorReplayIfDue();
$verify(!$queue->numberOfItems(), 'Cron checks an off switch at most once a day');
$switch_key = 'topicalboost.analytics_author_replay.switch';
$state->set($switch_key, array_replace($state->get($switch_key), ['checked_at' => time() - 86401]));
$service->queueAuthorReplayIfDue();
$verify($checkpoint()['sync_id'] !== $second['sync_id'] && $queue->numberOfItems() === 1,
  'A day later cron starts the new connection after its switch comes on');

echo "Drupal author replay runtime checks passed: $checks assertions.\n";
