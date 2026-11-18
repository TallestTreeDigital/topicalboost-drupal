<?php

namespace Drupal\ttd_topics\Plugin\QueueWorker;

use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;

/**
 * Sends a page of the Analytics author replay.
 *
 * @QueueWorker(
 *   id = "ttd_analytics_author_replay",
 *   title = @Translation("TopicalBoost Analytics author replay"),
 *   cron = {"time" = 180}
 * )
 */
final class AnalyticsAuthorReplay extends QueueWorkerBase {

  public function processItem($data) {
    $delay = \Drupal::service('ttd_topics.analytics_inventory')->replayAuthors();
    if ($delay > 0) {
      throw new DelayedRequeueException($delay);
    }
  }

}
