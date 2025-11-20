<?php

/**
 * Reprocess BA Request #250532 - reimport all entities
 *
 * Usage:
 *   php reprocess_ba_250532.php
 *
 * Or if running from a different directory:
 *   php /path/to/ttd_topics/reprocess_ba_250532.php
 *
 * This script will:
 * 1. Bootstrap Drupal
 * 2. Clear the request state
 * 3. Clear any queued jobs
 * 4. Restart the apply phase to reimport everything from the API
 */

// Find Drupal root
$drupal_root = __DIR__;

// Try to find the actual Drupal root if we're in a subdirectory
if (!file_exists($drupal_root . '/vendor/autoload.php')) {
  // Try parent directories
  for ($i = 0; $i < 10; $i++) {
    $drupal_root = dirname($drupal_root);
    if (file_exists($drupal_root . '/vendor/autoload.php')) {
      break;
    }
  }
}

if (!file_exists($drupal_root . '/vendor/autoload.php')) {
  die("ERROR: Could not find Drupal root with vendor/autoload.php\n");
}

// Load local config if available (for API endpoint override)
$local_config = __DIR__ . '/local-config.php';
if (file_exists($local_config) && filesize($local_config) > 0) {
  require_once $local_config;
}

// Bootstrap Drupal
$autoloader = require_once $drupal_root . '/vendor/autoload.php';

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals());
$kernel->boot();

$database = \Drupal::database();
$request_id = '250532';

echo "=== REPROCESSING BA REQUEST #$request_id ===\n\n";

// Step 1: Get current counts
echo "STEP 1: Current data counts\n";
echo "----------------------------\n";

$current_entities = $database->select('ttd_entities')->countQuery()->execute()->fetchField();
$current_relationships = $database->select('ttd_entity_post_ids')->countQuery()->execute()->fetchField();
$current_terms = $database->select('taxonomy_term_data')->condition('vid', 'ttd_topics')->countQuery()->execute()->fetchField();

echo "Current entities: $current_entities\n";
echo "Current relationships: $current_relationships\n";
echo "Current taxonomy terms: $current_terms\n\n";

// Step 2: Clear request state
echo "STEP 2: Clearing request state\n";
echo "------------------------------\n";

\Drupal::state()->delete('topicalboost.bulk_analysis.request_id');
\Drupal::state()->delete('topicalboost.bulk_analysis.filters');
\Drupal::state()->delete('topicalboost.bulk_analysis.content_count');
\Drupal::state()->delete('topicalboost.bulk_analysis.apply_progress');
\Drupal::state()->delete('topicalboost.bulk_analysis.completed_at');
\Drupal::state()->delete('topicalboost.bulk_analysis.customer_id_page_count');
\Drupal::state()->delete('topicalboost.bulk_analysis.entity_page_count');

echo "State cleared\n\n";

// Step 3: Clear queued jobs for this request
echo "STEP 3: Clearing queued jobs\n";
echo "----------------------------\n";

$cleared = $database->delete('advancedqueue')
  ->condition('queue_id', 'ttd_topics_analysis')
  ->condition('type', ['ttd_bulk_apply_customer_ids', 'ttd_bulk_apply_entities'], 'IN')
  ->condition('payload', '%' . $request_id . '%', 'LIKE')
  ->execute();

echo "Cleared $cleared queued jobs\n\n";

// Step 4: Set up fresh request state for reimport
echo "STEP 4: Initializing fresh reimport\n";
echo "------------------------------------\n";

\Drupal::state()->set('topicalboost.bulk_analysis.request_id', $request_id);
\Drupal::state()->set('topicalboost.bulk_analysis.apply_progress', [
  'stage' => 'entities',
  'customer_ids' => ['completed' => 0, 'total' => 0, 'current_page' => 1],
  'entities' => ['completed' => 0, 'total' => 0, 'current_page' => 1],
]);

echo "Request state initialized\n";
echo "  request_id: $request_id\n";
echo "  stage: entities (skipping customer_ids phase)\n\n";

// Step 5: Schedule the entity reimport job
echo "STEP 5: Scheduling entity reimport job\n";
echo "--------------------------------------\n";

try {
  $queue_storage = \Drupal::entityTypeManager()->getStorage('advancedqueue_queue');
  $queue = $queue_storage->load('ttd_topics_analysis');

  if (!$queue) {
    throw new \Exception('Queue "ttd_topics_analysis" not found');
  }

  $job = \Drupal\advancedqueue\Entity\Job::create('ttd_bulk_apply_entities', [
    'request_id' => $request_id,
    'page' => 1,
  ]);

  $queue->enqueueJob($job);

  echo "Job scheduled successfully\n";
  echo "  Job type: ttd_bulk_apply_entities\n";
  echo "  Request ID: $request_id\n";
  echo "  Page: 1\n\n";

}
catch (\Exception $e) {
  echo "ERROR scheduling job: " . $e->getMessage() . "\n";
  exit(1);
}

// Step 6: Log the action
echo "STEP 6: Logging action\n";
echo "----------------------\n";

\Drupal::logger('ttd_topics')->info(
  'Manual reprocess started for request @request_id - queued entity reimport starting from page 1',
  ['@request_id' => $request_id]
);

echo "Action logged\n\n";

echo "=== REPROCESS INITIATED ===\n\n";
echo "The system will now:\n";
echo "1. Fetch all entities from /result/entities?request_id=$request_id\n";
echo "2. Create/update taxonomy terms for each entity\n";
echo "3. Assign topics to nodes as specified in the API response\n";
echo "4. Create schema type and category relationships\n\n";
echo "Check the queue status at: /admin/config/content/topicalboost/bulk-analysis\n";
