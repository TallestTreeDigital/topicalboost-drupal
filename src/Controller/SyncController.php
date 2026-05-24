<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Sync API endpoints.
 *
 * Compares local site metrics vs API counts and orchestrates data sync.
 */
class SyncController extends ControllerBase {

  /**
   * Check sync status - compare local vs API counts.
   */
  public function check(Request $request) {
    $result = \Drupal::service('ttd_topics.sync_service')->checkSyncStatus();
    if (!$result) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Could not check sync status'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $result,
    ]);
  }

  /**
   * Start sync operation.
   *
   * In Drupal, we use Advanced Queue instead of Action Scheduler.
   * The sync is initiated and jobs are queued.
   */
  public function start(Request $request) {
    try {
      $sync_state = \Drupal::service('ttd_topics.sync_service')->startSync();
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Failed to start sync: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to start sync'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $sync_state,
    ]);
  }

  /**
   * Get sync progress.
   */
  public function progress(Request $request) {
    $progress = \Drupal::service('ttd_topics.sync_service')->getProgress();

    return new JsonResponse([
      'success' => TRUE,
      'data' => $progress,
    ]);
  }

  /**
   * Cancel sync operation.
   */
  public function cancel(Request $request) {
    \Drupal::service('ttd_topics.sync_service')->cancelSync();

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['message' => 'Sync cancelled'],
    ]);
  }

}
