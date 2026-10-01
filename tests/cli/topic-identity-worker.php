<?php
/** Child process for the disposable Drupal identity race test. */
if (\Drupal::database()->getConnectionOptions()['database'] !== 'drupal_identity') {
  throw new RuntimeException('Requires the disposable identity database.');
}
$id = (int) getenv('TB_IDENTITY_WORKER_ID');
$name = getenv('TB_IDENTITY_WORKER_NAME');
if ($id < 991100000 || !str_starts_with($name, 'TB identity ')) {
  throw new RuntimeException('Invalid identity worker fixture.');
}
$first = NULL;
for ($round = 0; $round < 4; $round++) {
  $term = \Drupal\ttd_topics\Service\TopicTermResolver::resolve($name, $id);
  if (!$term || ($first !== NULL && $first !== $term->id())) {
    throw new RuntimeException('Concurrent resolution changed the topic ID.');
  }
  $first = $term->id();
}
echo "Worker {$id} resolved {$first}.\n";
