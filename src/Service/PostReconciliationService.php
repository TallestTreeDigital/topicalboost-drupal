<?php

namespace Drupal\ttd_topics\Service;

/** Manually removes API posts outside the complete published Drupal scope. */
class PostReconciliationService {

  private const PREVIEW_COLLECTION = 'ttd_topics.post_reconciliation';
  private const PREVIEW_TTL = 600;
  private const LOCK_NAME = 'ttd_topics.post_reconciliation';
  private TtdSyncService $sync;

  public function __construct(TtdSyncService $sync) {
    $this->sync = $sync;
  }

  public function preview(): array {
    $this->assertAvailable();
    $store = \Drupal::keyValueExpirable(self::PREVIEW_COLLECTION);
    $key = (string) \Drupal::currentUser()->id();
    $store->delete($key);
    $ids = $this->postIds();
    $snapshot = $this->snapshot($ids);
    $result = $this->request($ids, TRUE);
    if (!hash_equals($snapshot, $this->snapshot($this->postIds()))) {
      throw new \RuntimeException('The site changed during the preview. Preview cleanup again.');
    }
    if ($result['orphanedContents'] > 0) {
      $token = bin2hex(random_bytes(32));
      $store->setWithExpire($key, [
        'token' => $token,
        'snapshot' => $snapshot,
        'result' => $result,
      ], self::PREVIEW_TTL);
      $result['previewToken'] = $token;
    }
    return $result;
  }

  public function confirm(string $token): array {
    $this->assertAvailable();
    $lock = \Drupal::lock();
    if (!$lock->acquire(self::LOCK_NAME, 300.0)) {
      throw new \RuntimeException('Cleanup is already running. Try again after it finishes.');
    }
    try {
      $store = \Drupal::keyValueExpirable(self::PREVIEW_COLLECTION);
      $key = (string) \Drupal::currentUser()->id();
      $preview = $store->get($key);
      if (!is_array($preview) || $token === '' || !hash_equals($preview['token'], $token)) {
        throw new \RuntimeException('The cleanup preview expired or was already used. Preview cleanup again.');
      }
      // Consume before requests. An uncertain deletion needs a new preview.
      $store->delete($key);
      $ids = $this->postIds();
      if (!hash_equals($preview['snapshot'], $this->snapshot($ids))) {
        throw new \RuntimeException('The site or API connection changed. Preview cleanup again.');
      }
      $fresh = $this->request($ids, TRUE);
      if ($fresh !== $preview['result']) {
        throw new \RuntimeException('The API data changed. Preview cleanup again.');
      }
      $this->assertAvailable();
      if (!hash_equals($preview['snapshot'], $this->snapshot($this->postIds()))) {
        throw new \RuntimeException('The site changed during confirmation. Preview cleanup again.');
      }
      $result = $this->request($ids, FALSE);
      \Drupal::state()->delete('ttd_sync_status_cache');
      // Reporting is separate. Its failure must not turn successful deletion
      // into an error that invites another destructive request.
      try {
        $result['metricsReported'] = (bool) $this->sync->reportSiteMetrics();
      }
      catch (\Throwable $e) {
        $result['metricsReported'] = FALSE;
      }
      return $result;
    }
    finally {
      $lock->release(self::LOCK_NAME);
    }
  }

  private function assertAvailable(): void {
    $user = \Drupal::currentUser();
    if (!$user->id() || !$user->hasPermission('administer topicalboost configuration')) {
      throw new \RuntimeException('Only TopicalBoost administrators can clean up API posts.');
    }
    $state = \Drupal::state();
    if ($state->get(TtdSyncService::SYNC_STATE_KEY)
      || $state->get('topicalboost.bulk_analysis.request_id')
      || $state->get('topicalboost.bulk_analysis.apply_progress')) {
      throw new \RuntimeException('Wait for active sync or analysis to finish before cleanup.');
    }
    $database = \Drupal::database();
    if ($database->schema()->tableExists('advancedqueue')) {
      $pending = $database->select('advancedqueue', 'q')
        ->condition('queue_id', TtdSyncService::QUEUE_ID)
        ->condition('state', ['queued', 'processing'], 'IN')
        ->countQuery()->execute()->fetchField();
      if ($pending) {
        throw new \RuntimeException('Wait for queued TopicalBoost work to finish before cleanup.');
      }
    }
  }

  /** Read all published IDs directly, without access filters or pagination. */
  private function postIds(): array {
    $types = $this->enabledTypes();
    $query = \Drupal::database()->select('node_field_data', 'n')
      ->fields('n', ['nid'])->distinct()
      ->condition('n.type', $types, 'IN')
      ->condition('n.status', 1);
    \ttd_topics_apply_bulk_category_filter($query, $types, $this->enabledCategories(), 'n', $this->categoryMode());
    $ids = $query->orderBy('n.nid')->execute()->fetchCol();
    if (!$ids) {
      throw new \RuntimeException('Cleanup is blocked because no published posts match the current content filters.');
    }
    foreach ($ids as $id) {
      if (!ctype_digit((string) $id) || (int) $id < 1) {
        throw new \RuntimeException('The post list is invalid. No cleanup was performed.');
      }
    }
    return array_map('strval', $ids);
  }

  private function enabledTypes(): array {
    $types = \Drupal::config('ttd_topics.settings')->get('enabled_content_types');
    if (!is_array($types) || !$types) {
      throw new \RuntimeException('Check enabled content types before cleanup.');
    }
    $types = array_values(array_filter($types));
    foreach ($types as $type) {
      if (!is_string($type) || !preg_match('/^[a-z0-9_]+$/D', $type)) {
        throw new \RuntimeException('Invalid content types. Check settings before cleanup.');
      }
    }
    if (!$types) {
      throw new \RuntimeException('Check enabled content types before cleanup.');
    }
    sort($types);
    return array_values(array_unique($types));
  }

  private function snapshot(array $ids): string {
    return hash('sha256', json_encode([
      \ttd_topics_get_site_base_url(), TOPICALBOOST_API_ENDPOINT,
      (string) \Drupal::config('ttd_topics.settings')->get('topicalboost_api_key'),
      $this->enabledTypes(), $this->enabledCategories(), $this->categoryMode(), $ids,
    ], JSON_THROW_ON_ERROR));
  }

  private function enabledCategories(): array {
    $categories = \Drupal::config('ttd_topics.settings')->get('enabled_categories') ?? [];
    if (!is_array($categories)) {
      throw new \RuntimeException('Invalid categories. Check settings before cleanup.');
    }
    foreach ($categories as $id) {
      if (!ctype_digit((string) $id) || (int) $id < 1) {
        throw new \RuntimeException('Invalid categories. Check settings before cleanup.');
      }
    }
    $categories = array_values(array_unique(array_map('intval', $categories)));
    sort($categories);
    return $categories;
  }

  private function categoryMode(): string {
    return \Drupal::config('ttd_topics.settings')->get('category_filter_mode') === 'exclude' ? 'exclude' : 'include';
  }

  private function request(array $ids, bool $dry_run): array {
    $response = $this->sync->apiRequest('POST', '/sync/reconcile', [
      'postIds' => $ids, 'dryRun' => $dry_run,
    ]);
    if (!is_array($response) || !empty($response['syncDisabled']) || !empty($response['sync_disabled'])
      || ($response['dryRun'] ?? NULL) !== $dry_run) {
      throw new \RuntimeException('The API did not confirm cleanup. Preview again to check its current state.');
    }
    $result = ['dryRun' => $dry_run];
    foreach (['orphanedContents', 'orphanedRelationships', 'orphanedEntities', 'totalSiteContents', 'reportedPostIds'] as $field) {
      if (!isset($response[$field]) || !is_int($response[$field]) || $response[$field] < 0) {
        throw new \RuntimeException('The API returned an invalid cleanup result. Preview again.');
      }
      $result[$field] = $response[$field];
    }
    if ($result['reportedPostIds'] !== count($ids) || $result['orphanedContents'] > $result['totalSiteContents']) {
      throw new \RuntimeException('The API did not verify the complete post list. No further cleanup was attempted.');
    }
    if (!$dry_run) {
      foreach (['contents', 'relationships', 'entities'] as $field) {
        if (!isset($response['deleted'][$field]) || !is_int($response['deleted'][$field]) || $response['deleted'][$field] < 0) {
          throw new \RuntimeException('The API did not confirm removal counts. Preview again to check its current state.');
        }
      }
      $result['deleted'] = $response['deleted'];
    }
    return $result;
  }

}
