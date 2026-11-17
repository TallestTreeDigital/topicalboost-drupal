<?php

use Drupal\advancedqueue\Job;
use Drupal\ttd_topics\Service\BulkAnalysisLifecycle as Lifecycle;

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
Drupal::state()->set(Lifecycle::REQUEST, 12);
Drupal::state()->get(Lifecycle::REQUEST);
Drupal::keyValue('state')->set(Lifecycle::REQUEST, 123);
$verify(!Lifecycle::current(12) && Lifecycle::current('123'), 'Worker guards bypass cached Drupal state after request replacement');

Drupal::state()->set(Lifecycle::REQUEST, 12);
$queue = Drupal::entityTypeManager()->getStorage('advancedqueue_queue')->load('ttd_topics_analysis');
$jobs = [];
foreach ([12, '12', 123] as $id) {
  $job = Job::create('ttd_bulk_batch_send', ['request_id' => $id, 'page' => 1]);
  $queue->enqueueJob($job);
  $jobs[] = $job;
}
$removed = Lifecycle::stop(12, 'cancelled', 'Fixture cancellation');
$verify($removed === 2 && !Lifecycle::current(12), 'Real AdvancedQueue cleanup deletes only the exact request');
$remaining = Drupal::database()->select('advancedqueue', 'q')->fields('q', ['job_id'])
  ->condition('job_id', array_map(static fn($job) => $job->getId(), $jobs), 'IN')->execute()->fetchCol();
$verify(array_map('intval', $remaining) === [(int) $jobs[2]->getId()], 'Request 123 survives cancellation of request 12');
$verify(Lifecycle::terminalStatus(12)['status'] === 'cancelled', 'The terminal marker persists in Drupal storage');
echo 'Drupal bulk lifecycle runtime checks passed: ' . $checks . " assertions.\n";
