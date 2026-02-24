<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for error telemetry endpoints.
 */
class TelemetryController extends ControllerBase {

  /**
   * Receive JS error reports from the frontend.
   */
  public function receiveJsErrors(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $errors = $content['errors'] ?? [];

    if (empty($errors)) {
      return new JsonResponse(['success' => TRUE]);
    }

    $telemetry = \Drupal::service('ttd_topics.error_telemetry');

    foreach ($errors as $error) {
      $telemetry->bufferJsError($error);
    }

    return new JsonResponse(['success' => TRUE]);
  }

}
