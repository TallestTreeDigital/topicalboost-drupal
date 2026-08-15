<?php

/**
 * End-to-end publish-transition regression for the content URL update guard.
 *
 * Run from the Drupal site root with:
 *   drush scr web/modules/custom/ttd_topics/tests/cli/test-publish-url-update-guard.php
 */

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$assertions = 0;
$failures = 0;
$created_node_ids = [];
$settings = \Drupal::config('ttd_topics.settings');
$original_config = $settings->getRawData();
$container = \Drupal::getContainer();
$original_analysis_service = $container->get('ttd_topics.analysis_service');
$recording_analysis_service = new class {
  public array $updatedNodeIds = [];

  public function updateContentUrl(NodeInterface $node): void {
    $this->updatedNodeIds[] = (int) $node->id();
  }
};

$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
  $assertions++;
  if ($condition) {
    echo "PASS: {$message}\n";
    return;
  }

  $failures++;
  echo "FAIL: {$message}\n";
};

echo "\n=== TopicalBoost Drupal Publish URL Update Guard Test ===\n\n";

try {
  $container->set('ttd_topics.analysis_service', $recording_analysis_service);
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('enabled_content_types', ['article'])
    ->set('block_until_analyzed', FALSE)
    ->save();

  $unanalyzed = Node::create([
    'type' => 'article',
    'title' => 'TopicalBoost URL guard unanalyzed ' . time(),
    'status' => 0,
  ]);
  if ($unanalyzed->hasField('field_ttd_analysis_in_progress')) {
    $unanalyzed->set('field_ttd_analysis_in_progress', TRUE);
  }
  $unanalyzed->save();
  $created_node_ids[] = (int) $unanalyzed->id();

  $unanalyzed->setPublished()->save();
  $assert(
    !in_array((int) $unanalyzed->id(), $recording_analysis_service->updatedNodeIds, TRUE),
    'Unanalyzed publish does not call PATCH /content/url'
  );

  $analyzed = Node::create([
    'type' => 'article',
    'title' => 'TopicalBoost URL guard analyzed ' . time(),
    'status' => 0,
    'field_ttd_last_analyzed' => \Drupal::time()->getRequestTime(),
  ]);
  $analyzed->save();
  $created_node_ids[] = (int) $analyzed->id();

  $analyzed->setPublished()->save();
  $assert(
    in_array((int) $analyzed->id(), $recording_analysis_service->updatedNodeIds, TRUE),
    'Analyzed publish still calls PATCH /content/url'
  );
}
finally {
  $container->set('ttd_topics.analysis_service', $original_analysis_service);
  \Drupal::configFactory()->getEditable('ttd_topics.settings')->setData($original_config)->save();

  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nodes = $storage->loadMultiple(array_unique($created_node_ids));
  if ($nodes) {
    $storage->delete($nodes);
  }
}

echo "\nAssertions: {$assertions}; Failures: {$failures}\n";
if ($failures > 0) {
  throw new RuntimeException('Drupal publish URL update guard regression failed');
}

echo "Drupal Publish URL Update Guard Test: PASSED\n";
