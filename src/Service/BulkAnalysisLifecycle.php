<?php

namespace Drupal\ttd_topics\Service;

/** Guards workers against cancelled, failed, and replaced bulk requests. */
final class BulkAnalysisLifecycle {
  public const REQUEST = 'topicalboost.bulk_analysis.request_id';
  public const TERMINAL = 'topicalboost.bulk_analysis.terminal';

  public static function current($request_id): bool {
    // StateInterface caches values in long-running queue workers.
    $store = \Drupal::keyValue('state');
    $active = $store->get(self::REQUEST);
    $terminal = $store->get(self::TERMINAL, []);
    return $request_id && (string) $active === (string) $request_id
      && (string) ($terminal['request_id'] ?? '') !== (string) $request_id;
  }

  public static function terminalStatus($request_id): ?array {
    $terminal = \Drupal::keyValue('state')->get(self::TERMINAL, []);
    return (string) ($terminal['request_id'] ?? '') === (string) $request_id ? $terminal : NULL;
  }

  public static function stop($request_id, string $status, string $message): int {
    if ((string) \Drupal::keyValue('state')->get(self::REQUEST) !== (string) $request_id) return 0;
    // Publish before cleanup so workers returning from an API call stop too.
    \Drupal::state()->set(self::TERMINAL, [
      'request_id' => $request_id, 'status' => $status, 'terminal' => TRUE, 'message' => $message,
    ]);
    $query = \Drupal::database()->delete('advancedqueue')
      ->condition('queue_id', 'ttd_topics_analysis')
      ->condition('type', ['ttd_bulk_batch_send', 'ttd_bulk_analysis_poller', 'ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities', 'ttd_bulk_apply_posts_optimized'], 'IN')
      ->condition('state', 'queued');
    $query->condition(self::requestCondition($request_id));
    return (int) $query->execute();
  }

  /** Match a complete JSON value so request 12 cannot match request 123. */
  public static function requestCondition($request_id) {
    $ids = [(string) $request_id];
    if (ctype_digit((string) $request_id)) $ids[] = (int) $request_id;
    $condition = \Drupal::database()->condition('OR');
    foreach ($ids as $id) {
      foreach ([',', '}'] as $end) {
        $fragment = '"request_id":' . json_encode($id) . $end;
        $condition->condition('payload', '%' . \Drupal::database()->escapeLike($fragment) . '%', 'LIKE');
      }
    }
    return $condition;
  }
}
