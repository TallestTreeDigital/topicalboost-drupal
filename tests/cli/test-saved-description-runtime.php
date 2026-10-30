<?php

use Drupal\advancedqueue\Job;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\ttd_topics\Controller\MetaGeneratorController;
use Drupal\ttd_topics\Service\AnalyticsInventoryService as Inventory;
use Drupal\ttd_topics\Service\SavedDescriptionSync as Catchup;
use Drupal\ttd_topics\Service\SavedMetatag;

if (getenv('TTD_DESCRIPTION_FIXTURE') !== 'owned-description-repair'
  || Drupal::root() !== '/app/web'
  || !str_starts_with(Drupal::database()->getConnectionOptions()['host'], 'tb-drupal-description-')
  || TOPICALBOOST_API_ENDPOINT !== 'http://127.0.0.1:18080') {
  throw new RuntimeException('This test requires the isolated Drupal fixture.');
}
$GLOBALS['checks'] = 0;
function verify_drupal_description($condition, $message) {
  if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
  $GLOBALS['checks']++;
  echo 'PASS: ' . $message . "\n";
}
function checkpoint() { Drupal::state()->resetCache(); return Drupal::state()->get(Catchup::STATE_KEY); }
function receipts() {
  return is_file('/app/receipts.jsonl') ? array_map(fn($line) => json_decode($line, TRUE), file('/app/receipts.jsonl', FILE_IGNORE_NEW_LINES)) : [];
}
function only_description_jobs() {
  Drupal::database()->delete('advancedqueue')->condition('type', Catchup::JOB_TYPE, '<>')->execute();
}
function advance_description_fixture() {
  $state = checkpoint(); $state['next_at'] = time() - 1;
  Drupal::state()->set(Catchup::STATE_KEY, $state);
  Drupal::database()->update('advancedqueue')->fields(['available' => time() - 1])
    ->condition('type', Catchup::JOB_TYPE)->condition('state', 'queued')->execute();
  $queue = Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load(Inventory::QUEUE_ID);
  $job = $queue->getBackend()->claimJob();
  verify_drupal_description($job && $job->getType() === Catchup::JOB_TYPE, 'A real AdvancedQueue job is available');
  Drupal::service('advancedqueue.processor')->processJob($job, $queue);
}

// Everything is disposable in this fresh fixture. This permits a failed test
// to be repeated while debugging without borrowing an existing site's state.
Drupal::state()->set(Inventory::ENABLED_STATE_KEY, FALSE);
Drupal::state()->delete(Catchup::STATE_KEY);
Drupal::database()->delete('key_value')->condition('collection', ['ttd_saved_description_confirmed', 'ttd_saved_description_dirty', 'ttd_description_queue'], 'IN')->execute();
Drupal::state()->delete('topicalboost.saved_description_last_success');
Drupal::state()->delete('topicalboost.saved_description_status_attempt');
Drupal::database()->delete('semaphore')->condition('name', 'ttd_topics.description.%', 'LIKE')->execute();
Drupal::database()->delete('node_field_data')->condition('nid', 1000000, '>')->execute();
foreach (Drupal::entityTypeManager()->getStorage('node')->loadMultiple() as $old) $old->delete();
Drupal::database()->delete('advancedqueue')->execute();
@unlink('/app/receipts.jsonl'); @unlink('/app/fail-once');
foreach (['article', 'legacy_story', 'unsupported_story', 'outside_story'] as $bundle) {
  if (!NodeType::load($bundle)) NodeType::create(['type' => $bundle, 'name' => $bundle])->save();
}
if (!FieldStorageConfig::loadByName('node', 'field_custom_description')) {
  FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_custom_description', 'type' => 'metatag'])->save();
  FieldConfig::create(['entity_type' => 'node', 'bundle' => 'article', 'field_name' => 'field_custom_description', 'label' => 'Saved SEO'])->save();
  FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => 'field_meta_tags', 'type' => 'string_long'])->save();
  FieldConfig::create(['entity_type' => 'node', 'bundle' => 'legacy_story', 'field_name' => 'field_meta_tags', 'label' => 'Legacy SEO'])->save();
}
Drupal::configFactory()->getEditable('ttd_topics.settings')->set('enabled_content_types', [])->set('topicalboost_api_key', 'fixture-only')->save();
$ids = [];
$original = ['description' => ' Raw &amp; [node:title] 🙂 ', 'canonical_url' => 'https://example.test/canonical/', 'og_description' => 'Social text', 'robots' => 'index, follow'];
for ($i = 0; $i < 23; $i++) {
  $bundle = $i === 21 ? 'legacy_story' : ($i === 22 ? 'unsupported_story' : 'article');
  $node = Node::create(['type' => $bundle, 'title' => 'Description fixture ' . $i, 'status' => 1,
    'created' => 1700000000 + (($i * 7) % 23) * 100]);
  if ($bundle === 'article') $node->set('field_custom_description', json_encode($i % 2 === 0 ? $original : []));
  if ($bundle === 'legacy_story') $node->set('field_meta_tags', serialize($original));
  $node->save(); $ids[] = (int) $node->id();
}
Node::create(['type' => 'article', 'title' => 'Draft must stay out', 'status' => 0])->save();
Node::create(['type' => 'outside_story', 'title' => 'Other type must stay out', 'status' => 1])->save();
Drupal::state()->set(Inventory::ENABLED_STATE_KEY, TRUE);
Drupal::state()->set(Inventory::LAST_PROBE_STATE_KEY, time());
Drupal::state()->set(Inventory::CONTENT_TYPES_STATE_KEY, ['article', 'legacy_story', 'unsupported_story']);
Drupal::moduleHandler()->loadInclude('ttd_topics', 'install');
ttd_topics_update_9112(); ttd_topics_update_9113();
verify_drupal_description(Drupal::service('plugin.manager.advancedqueue_job_type')->hasDefinition(Catchup::JOB_TYPE), 'The ordinary update hook discovers the new queue job');
ttd_topics_cron();
$state = checkpoint();
verify_drupal_description($state['status'] === 'pending' && $state['upper_id'] === NULL && !receipts(), 'Ordinary Drupal cron starts catch-up without scanning or sending the archive');
verify_drupal_description($state['next_at'] >= time() + 295, 'Initial catch-up waits at least five minutes');
only_description_jobs();
file_put_contents('/app/fail-once', '503');
advance_description_fixture();
verify_drupal_description(checkpoint()['cursor'] === 0 && checkpoint()['status'] === 'retrying', 'A real HTTP 503 leaves the cursor unchanged and schedules recovery');
verify_drupal_description((int) Drupal::database()->select('key_value', 'kv')->condition('collection', 'ttd_saved_description_confirmed')->countQuery()->execute()->fetchField() === 0, 'A failed warehouse acknowledgement creates no local confirmed-coverage marks');
advance_description_fixture();
verify_drupal_description(checkpoint()['processed'] === 10, 'Retry resumes the same ten-node page');
advance_description_fixture(); advance_description_fixture();
$complete = checkpoint();
verify_drupal_description($complete['status'] === 'complete' && $complete['processed'] === 23 && $complete['written'] === 23, 'All 23 published in-scope nodes complete in bounded pages');
$calls = receipts();
verify_drupal_description(array_map(fn($r) => count($r['body']['posts']), $calls) === [10, 10, 10, 3], 'The relay sent a retried page, then ten and three nodes');
verify_drupal_description(array_column($calls[0]['body']['posts'], 'post_id') === array_column($calls[1]['body']['posts'], 'post_id'), 'The failed page retained exactly the same node identities');
$posts = array_merge(...array_map(fn($r) => $r['body']['posts'], array_slice($calls, 1)));
$expected_ids = $ids;
usort($expected_ids, static fn($a, $b) => (Node::load($b)->getCreatedTime() <=> Node::load($a)->getCreatedTime()) ?: ($b <=> $a));
verify_drupal_description(array_column($posts, 'post_id') === $expected_ids, 'Recent created dates win over ID order, with drafts and other types excluded');
$by_id = array_column($posts, NULL, 'post_id');
verify_drupal_description($by_id[$ids[0]]['meta']['_ttd_saved_description'] === $original['description'], 'JSON Metatag text retains exact tokens, Unicode and whitespace');
verify_drupal_description($by_id[$ids[1]]['meta']['_ttd_saved_description'] === NULL, 'A read empty Metatag field is explicit null');
verify_drupal_description($by_id[$ids[21]]['meta']['_ttd_saved_description'] === $original['description'], 'Legacy serialized Metatag content reaches the same description contract');
verify_drupal_description(!array_key_exists('_ttd_saved_description', $by_id[$ids[22]]['meta']), 'A node without Metatag remains unavailable rather than falsely empty');
verify_drupal_description(!isset($by_id[$ids[0]]['meta']['_yoast_wpseo_metadesc']), 'Drupal does not invent a Yoast field');
verify_drupal_description($calls[0]['platform'] === 'drupal' && $calls[0]['authenticated'], 'Real Guzzle transport retains the Drupal platform and authentication headers');
verify_drupal_description($calls[0]['body']['batch']['type'] === 'incremental' && !isset($calls[0]['body']['deleted_post_ids']), 'Catch-up cannot run full reconciliation or deletions');

$gateway = Drupal::service('ttd_topics.analytics_inventory');
$storage = Drupal::entityTypeManager()->getStorage('node');
$node = Node::load($ids[0]);
$node->set('field_custom_description', json_encode($original + ['title' => 'Saved SEO title'])); $node->save();
Drupal::database()->delete('advancedqueue')->condition('type', Inventory::NODE_JOB_TYPE, '<>')->execute();
$queue = Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load(Inventory::QUEUE_ID);
$running = $queue->getBackend()->claimJob();
verify_drupal_description($running && $running->getType() === Inventory::NODE_JOB_TYPE, 'A description save queued normal Drupal inventory sync');
$controller = (new ReflectionClass(MetaGeneratorController::class))->newInstanceWithoutConstructor();
$read = new ReflectionMethod(MetaGeneratorController::class, 'getMetatagValues'); $read->setAccessible(TRUE);
$write = new ReflectionMethod(MetaGeneratorController::class, 'setMetatagValues'); $write->setAccessible(TRUE);
$tags = $read->invoke($controller, $node);
$tags['description'] = 'A saved replacement description';
$write->invoke($controller, $node, $tags); $node->save();
verify_drupal_description(SavedMetatag::decode($node->get('field_custom_description')->value) === $tags, 'The existing CMS Save retains the replacement and unrelated JSON tags');
unset($tags['description']);
$write->invoke($controller, $node, $tags); $node->save();
$pending = (int) Drupal::database()->select('advancedqueue', 'q')->condition('type', Inventory::NODE_JOB_TYPE)->condition('state', 'queued')->countQuery()->execute()->fetchField();
verify_drupal_description($pending === 1, 'Clear during a processing job queues a follow-up instead of being swallowed');
Drupal::service('advancedqueue.processor')->processJob($running, $queue);
$last = receipts(); $last = end($last);
verify_drupal_description($last['body']['posts'][0]['meta']['_ttd_saved_description'] === NULL, 'The queued worker rereads the current CMS clear');
$storage->resetCache([$ids[0]]); $fresh = $storage->load($ids[0]);
$stored = SavedMetatag::decode($fresh->get('field_custom_description')->value);
verify_drupal_description($stored === $tags && is_array(json_decode($fresh->get('field_custom_description')->value, TRUE)), 'The existing CMS Clear preserves JSON format and unrelated canonical/social tags');

$helper_property = new ReflectionProperty(Inventory::class, 'savedDescriptions'); $helper_property->setAccessible(TRUE);
$helper = $helper_property->getValue($gateway);
// A->B->A can leave the same text with a different save epoch. A delayed A
// response must not claim that the warehouse's newer B is current A.
$scope = $gateway->savedDescriptionScope();
$node_a = SavedMetatag::decode($fresh->get('field_custom_description')->value);
$node_a['description'] = 'Epoch A'; $fresh->set('field_custom_description', json_encode($node_a)); $fresh->save();
$token_a = $helper->dirtyTokens([$ids[0]]);
$payload_a = $gateway->buildNodePayload($fresh, $helper->observationRevision());
$node_a['description'] = 'Epoch B'; $fresh->set('field_custom_description', json_encode($node_a)); $fresh->save();
$node_a['description'] = 'Epoch A'; $fresh->set('field_custom_description', json_encode($node_a)); $fresh->save();
verify_drupal_description($token_a !== $helper->dirtyTokens([$ids[0]]), 'Real node saves change the dirty epoch even when the text returns to its old value');
$helper->confirmPosts([$payload_a], $scope, $token_a);
verify_drupal_description($helper->unconfirmedPosts([$gateway->buildNodePayload($fresh, $helper->observationRevision())]) !== [],
  'A delayed acknowledgement for an older real node-save epoch cannot cover the current equal text');
unset($node_a['description']); $fresh->set('field_custom_description', json_encode($node_a)); $fresh->save();
$gateway->syncNode($ids[0]);
verify_drupal_description($helper->unconfirmedPosts([$gateway->buildNodePayload($fresh, $helper->observationRevision())]) === [],
  'A fresh authenticated delivery can confirm the current real clear epoch');

$future = (string) ((int) floor(microtime(TRUE) * 1000000) + 1000000000);
Drupal::state()->set('topicalboost.saved_description_observation', $future);
verify_drupal_description((int) $helper->observationRevision() === (int) $future + 1, 'The real shared counter remains monotonic if the clock goes backward');
$replace = new ReflectionMethod(Catchup::class, 'replaceState'); $replace->setAccessible(TRUE);
$replacement = $complete; $replacement['lease_token'] = 'new-owner';
Drupal::state()->set(Catchup::STATE_KEY, $replacement);
verify_drupal_description(!$replace->invoke($helper, $complete, array_merge($complete, ['cursor' => 0])) && checkpoint()['lease_token'] === 'new-owner', 'Atomic state comparison rejects an obsolete worker checkpoint');
Drupal::state()->set(Catchup::STATE_KEY, $complete);
$gateway->queueProbeIfDue();
verify_drupal_description(!(bool) Drupal::database()->select('advancedqueue', 'q')->condition('type', Catchup::JOB_TYPE)->condition('state', 'queued')->countQuery()->execute()->fetchField(), 'Later cron checks do not restart a completed catch-up');
verify_drupal_description(!isset(SavedMetatag::decode($fresh->get('field_custom_description')->value)['description']), 'Inventory synchronization did not write a generated description into the CMS');

Drupal::database()->delete('advancedqueue')->execute();
$queue->enqueueJob(Job::create(Inventory::NODE_JOB_TYPE, ['node_id' => $ids[0] * 10, 'deleted' => FALSE]));
verify_drupal_description($gateway->queueNode($fresh), 'A queued node ID with the same numeric prefix cannot swallow another node update');
verify_drupal_description(!$gateway->queueNode($fresh), 'An exact duplicate pending update is deduplicated');
$transaction = Drupal::database()->startTransaction();
$gateway->queueNode($fresh);
$options = Drupal::database()->getConnectionOptions();
$other = new PDO('mysql:host=' . $options['host'] . ';dbname=' . $options['database'], $options['username'], $options['password']);
$other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$other->exec('SET innodb_lock_wait_timeout = 1');
$unrelated_id = Drupal::database()->select('advancedqueue', 'q')->fields('q', ['job_id'])
  ->condition('payload', '%"node_id":' . ($ids[0] * 10) . ',%', 'LIKE')->execute()->fetchField();
$unrelated_claim = $other->prepare('UPDATE advancedqueue SET available = ? WHERE job_id = ?');
$unrelated_claim->execute([time() + 60, $unrelated_id]);
verify_drupal_description($unrelated_claim->rowCount() === 1, 'A pending node save does not lock an unrelated earlier queue row');
$blocked = FALSE;
try {
  $claim = $other->prepare('UPDATE advancedqueue SET state = ?, expires = ? WHERE type = ? AND payload LIKE ? AND state = ?');
  $claim->execute(['processing', time() + 600, Inventory::NODE_JOB_TYPE, '%"node_id":' . $ids[0] . ',%', 'queued']);
}
catch (PDOException $error) {
  $blocked = (int) ($error->errorInfo[1] ?? 0) === 1205;
}
verify_drupal_description($blocked, 'A concurrent queue claimant waits for the surrounding CMS save to commit');
unset($transaction, $other, $options);
Drupal::database()->delete('advancedqueue')->execute();
$gateway->queueNode($fresh); $retry = $queue->getBackend()->claimJob();
$retry->setNumRetries(6); file_put_contents('/app/fail-once', '503');
Drupal::service('advancedqueue.processor')->processJob($retry, $queue);
verify_drupal_description($retry->getState() === Job::STATE_QUEUED && $retry->getNumRetries() === 7
  && $retry->getAvailableTime() >= time() + 21595, 'A normal edit survives more than five HTTP failures with six-hour backoff');
verify_drupal_description(!$gateway->queueNode($fresh), 'A new edit reuses its pending retry');
$woken = $queue->getBackend()->claimJob();
verify_drupal_description($woken && $woken->getId() === $retry->getId(), 'The new edit wakes a delayed retry immediately');
Drupal::service('advancedqueue.processor')->processJob($woken, $queue);
verify_drupal_description($woken->getState() === Job::STATE_SUCCESS, 'The woken retry rereads and delivers current CMS state');

only_description_jobs();
$interrupted = $complete;
$interrupted['status'] = 'pending'; $interrupted['cursor'] = 0; $interrupted['cursor_created'] = NULL; $interrupted['processed'] = 0; $interrupted['written'] = 0;
$interrupted['next_at'] = time() - 1; $interrupted['lease_token'] = 'crashed-worker'; $interrupted['lease_expires'] = time() - 1;
Drupal::state()->set(Catchup::STATE_KEY, $interrupted);
Drupal::database()->insert('semaphore')->fields(['name' => 'ttd_topics.description.worker', 'value' => 'dead-worker', 'expire' => microtime(TRUE) - 1])->execute();
$gateway->queueProbeIfDue(); advance_description_fixture();
verify_drupal_description(checkpoint()['processed'] > 0, 'Cron recovers an expired worker lease and a lost continuation without a command');
$before = count(receipts()); Drupal::state()->set(Catchup::STATE_KEY, $complete);
advance_description_fixture();
verify_drupal_description(count(receipts()) === $before && checkpoint()['status'] === 'complete', 'A duplicate late delivery cannot restart a completed catch-up');
// Already confirmed live values and clears are skipped, not sent again.
$pending_posts = $helper->unconfirmedPosts([$gateway->buildNodePayload($fresh, $helper->observationRevision())]);
verify_drupal_description($pending_posts === [], 'A confirmed live clear counts as covered in later catch-up');
$helper->confirmPosts([['post_id' => $ids[0], 'meta' => ['_ttd_saved_description' => 'Old replay', '_ttd_saved_description_revision' => '1']]]);
verify_drupal_description($helper->unconfirmedPosts([$gateway->buildNodePayload($fresh, $helper->observationRevision())]) === [], 'An older acknowledgement cannot replace the newer local confirmation');
$before = count(receipts());
$recheck = $complete; $recheck['status'] = 'pending'; $recheck['cursor'] = 0; $recheck['cursor_created'] = NULL;
$recheck['processed'] = 0; $recheck['written'] = 0; $recheck['skipped'] = 0; $recheck['next_at'] = time() - 1;
Drupal::state()->set(Catchup::STATE_KEY, $recheck);
for ($turn = 0; $turn < 5 && checkpoint()['status'] !== 'complete'; $turn++) {
  $gateway->queueProbeIfDue(); advance_description_fixture();
}
verify_drupal_description(checkpoint()['status'] === 'complete' && checkpoint()['processed'] === 23
  && checkpoint()['skipped'] === 22 && checkpoint()['written'] === 1, 'Covered nodes are skipped locally; unsupported metadata stays distinct from a confirmed clear');
$new_calls = array_slice(receipts(), $before);
verify_drupal_description(count($new_calls) === 1 && array_column($new_calls[0]['body']['posts'], 'post_id') === [$ids[22]], 'Catch-up does not replay twenty-two already confirmed description values');

// A legacy ascending checkpoint keeps its already-confirmed low-ID prefix.
$legacy = ['status' => 'pending', 'cursor' => $ids[9], 'upper_id' => $complete['upper_id'],
  'processed' => 10, 'written' => 10, 'attempts' => 0, 'next_at' => time() - 1];
Drupal::state()->set(Catchup::STATE_KEY, $legacy); $gateway->queueProbeIfDue(); advance_description_fixture();
for ($turn = 0; $turn < 5 && checkpoint()['status'] !== 'complete'; $turn++) advance_description_fixture();
verify_drupal_description(checkpoint()['format'] === 2 && checkpoint()['legacy_floor_id'] === $ids[9]
  && checkpoint()['processed'] === 23 && checkpoint()['status'] === 'complete', 'An old partial cursor upgrades once without replaying its confirmed low-ID prefix');
$legacy_complete = $legacy; $legacy_complete['status'] = 'complete';
Drupal::state()->set(Catchup::STATE_KEY, $legacy_complete); $gateway->queueProbeIfDue();
verify_drupal_description(checkpoint()['status'] === 'complete' && checkpoint()['processed'] === $legacy_complete['processed']
  && checkpoint()['destination'] === $gateway->savedDescriptionScope(), 'A completed legacy catch-up adopts its current destination without replay');
Drupal::state()->set(Catchup::STATE_KEY, $complete);

// A connection change cannot inherit a completed run or old acknowledgements.
$settings = Drupal::configFactory()->getEditable('ttd_topics.settings');
$settings->set('topicalboost_api_key', 'changed-fixture-destination')->save();
$gateway->queueProbeIfDue(); $changed = checkpoint();
verify_drupal_description($changed['status'] === 'pending' && $changed['processed'] === 0 && $changed['cursor_created'] === NULL
  && $changed['destination'] === $gateway->savedDescriptionScope(), 'A changed destination resets bounded catch-up once');
$gateway->queueProbeIfDue();
verify_drupal_description(checkpoint() === $changed && $gateway->getSavedDescriptionStatus()['lastSuccessAt'] === NULL,
  'Repeated bootstrap preserves the new checkpoint and does not claim an old destination success');
$settings->set('topicalboost_api_key', 'fixture-only')->save(); Drupal::state()->set(Catchup::STATE_KEY, $complete);

// Native queue processing prefers incoming updates and retains a durable FIFO slot.
Drupal::database()->delete('advancedqueue')->execute();
Drupal::database()->delete('key_value')->condition('collection', 'ttd_description_queue')->execute();
$background = Job::create(Catchup::JOB_TYPE, ['trigger' => 'priority-fixture']); $queue->enqueueJob($background);
for ($n = 0; $n < 8; $n++) $queue->enqueueJob(Job::create(Inventory::NODE_JOB_TYPE, ['node_id' => $ids[$n], 'deleted' => FALSE]));
$claims = [];
for ($n = 0; $n < 4; $n++) {
  $backend = Drupal::service('plugin.manager.advancedqueue_backend')->createInstance('ttd_description_priority', ['_entity_id' => Inventory::QUEUE_ID]);
  $job = $backend->claimJob(); $claims[] = $job->getType();
}
verify_drupal_description($claims === [Inventory::NODE_JOB_TYPE, Inventory::NODE_JOB_TYPE, Inventory::NODE_JOB_TYPE, Catchup::JOB_TYPE], 'Live changes go first; the fourth claim preserves FIFO history progress across backend restarts');
$claimed_ids = Drupal::database()->select('advancedqueue', 'q')->fields('q', ['job_id'])->condition('state', 'processing')->execute()->fetchCol();
verify_drupal_description(count(array_unique($claimed_ids)) === 4, 'Each claimed queue row has one owner and preserves native processing leases');
// The claim counter and chosen row stay owned until their short transaction commits.
$claim_transaction = Drupal::database()->startTransaction();
$backend->claimJob();
$options = Drupal::database()->getConnectionOptions();
$other = new PDO('mysql:host=' . $options['host'] . ';dbname=' . $options['database'], $options['username'], $options['password']);
$other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $other->exec('SET innodb_lock_wait_timeout = 1');
$counter_blocked = FALSE;
try {
  $query = $other->prepare('SELECT value FROM key_value WHERE collection = ? AND name = ? FOR UPDATE');
  $query->execute(['ttd_description_queue', Inventory::QUEUE_ID]);
}
catch (PDOException $error) { $counter_blocked = (int) ($error->errorInfo[1] ?? 0) === 1205; }
verify_drupal_description($counter_blocked, 'Concurrent claims serialize their durable fairness counter until the owner commits');
unset($claim_transaction, $other, $options);
Drupal\Core\Database\Database::startLog('description_status_fixture');
$snapshot = $gateway->getSavedDescriptionStatus();
verify_drupal_description($snapshot['status'] === 'complete' && $snapshot['lastSuccessAt'] !== NULL && $snapshot['nextAttemptAt'] === NULL,
  'Cheap status retains last confirmed live success and terminal completion without an archive count');
$status_queries = Drupal\Core\Database\Database::getLog('description_status_fixture');
verify_drupal_description(!array_filter($status_queries, static fn($query) => str_contains($query['query'], 'node_field_data') || str_contains($query['query'], 'COUNT(')), 'Status reads do not count or scan the content archive');

// Isolate the added indexed token write, then measure current real saves.
// This is a warmed sequential fixture measurement, not visitor latency or an
// old/new whole-site comparison.
$dirty_times = []; $save_times = [];
for ($n = 0; $n < 50; $n++) { $start = microtime(TRUE); $helper->markDirty($ids[0]); $dirty_times[] = (microtime(TRUE) - $start) * 1000; }
for ($n = 0; $n < 32; $n++) {
  $timed = Node::load($ids[$n % 8]); $tags = SavedMetatag::decode($timed->get('field_custom_description')->value);
  $tags['description'] = 'Timed synthetic save ' . $n;
  $timed->set('field_custom_description', json_encode($tags));
  $start = microtime(TRUE); $timed->save(); $save_times[] = (microtime(TRUE) - $start) * 1000;
}
echo 'DESCRIPTION_SAVE_TIMING=' . json_encode(['tokenWriteMs' => $dirty_times, 'nodeSaveMs' => $save_times,
  'scope' => 'Owned fresh Drupal fixture, warm sequential writes across eight nodes. No visitor HTTP or production before/after claim.']) . "\n";

if (getenv('TTD_DESCRIPTION_SCALE_CHECK') === '1') {
// A large disposable core table proves that the update builds a usable index.
// DDL is measured here only; production locking depends on its DB/version/traffic.
$db = Drupal::database();
$columns = $db->query('SHOW COLUMNS FROM {node_field_data}')->fetchCol();
$expressions = [];
foreach ($columns as $column) {
  $expressions[] = match ($column) {
    'nid', 'vid' => '1000000 + s.seq',
    'created' => '1700000000 + s.seq',
    'title' => "CONCAT('Synthetic large archive ', s.seq)",
    default => 't.`' . $column . '`',
  };
}
$db->query('INSERT INTO {node_field_data} (`' . implode('`,`', $columns) . '`) SELECT ' . implode(',', $expressions)
  . ' FROM {node_field_data} t CROSS JOIN seq_1_to_250000 s WHERE t.nid = :nid AND t.default_langcode = 1', [':nid' => $ids[0]]);
$db->query('ANALYZE TABLE {node_field_data}')->fetchAll();
$db->schema()->dropIndex('node_field_data', 'ttd_description_recent');
$start = microtime(TRUE); ttd_topics_update_9113(); $index_ms = (microtime(TRUE) - $start) * 1000;
$sql = 'SELECT nid,created FROM {node_field_data} WHERE type = :type AND status = 1 AND default_langcode = 1 AND nid > 0 AND nid <= 1250000 AND (created < 1700249999 OR (created = 1700249999 AND nid < 1249999)) ORDER BY created DESC,nid DESC LIMIT 10';
$plan = $db->query('EXPLAIN ' . $sql, [':type' => 'article'])->fetchAll(PDO::FETCH_ASSOC);
verify_drupal_description($plan[0]['key'] === 'ttd_description_recent' && !str_contains($plan[0]['Extra'], 'filesort'), 'A 250k-row recent page uses the covering date/nid index without an archive filesort');
$latencies = [];
for ($n = 0; $n < 12; $n++) { $start = microtime(TRUE); $rows = $db->query($sql, [':type' => 'article'])->fetchAll(PDO::FETCH_ASSOC); $latencies[] = (microtime(TRUE) - $start) * 1000; }
verify_drupal_description(count($rows) === 10 && (int) $rows[0]['nid'] === 1249998, 'The bounded date/nid page returns the expected next ten candidates');
echo 'DESCRIPTION_INDEX=' . json_encode(['syntheticRows' => 250000, 'indexBuildMs' => $index_ms, 'pageMs' => $latencies, 'plan' => $plan, 'limits' => 'Owned fresh MariaDB fixture only; no production DDL or zero-lock guarantee.']) . "\n";
}
echo json_encode(['complete' => TRUE, 'assertions' => $GLOBALS['checks'], 'nodes' => 23, 'drupal' => Drupal::VERSION, 'php' => PHP_VERSION,
  'metatag' => Drupal::service('extension.list.module')->getExtensionInfo('metatag')['version'], 'transport' => 'real Guzzle HTTP to isolated fixture API; no customer or warehouse write']), "\n";
