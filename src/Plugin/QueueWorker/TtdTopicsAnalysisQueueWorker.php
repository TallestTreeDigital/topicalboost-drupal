<?php

namespace Drupal\ttd_topics\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\QueueWorkerInterface;

/**
 * Processes tasks for TopicalBoost Analysis.
 *
 * @QueueWorker(
 *   id = "ttd_topics_analysis_queue",
 *   title = @Translation("TopicalBoost Analysis Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class TtdTopicsAnalysisQueueWorker extends QueueWorkerBase implements QueueWorkerInterface {

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Extract the node ID from the $data object.
    $item_id = $data->id();

    // Run drush command to analyze the node.
    exec('drush topicalboost:analyze_nodes ' . $item_id);
  }

}
