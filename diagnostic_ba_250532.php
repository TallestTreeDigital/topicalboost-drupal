<?php

/**
 * Diagnostic for BA Request #250532 discrepancy
 *
 * This script compares what the API returned vs what was imported locally
 */

namespace Drupal\ttd_topics;

// Find Drupal root
$drupal_root = dirname(__FILE__) . '/web';
if (!file_exists($drupal_root)) {
  $drupal_root = dirname(__FILE__);
}

$autoloader = require_once dirname(__FILE__) . '/vendor/autoload.php';
$kernel = \Drupal\Core\DrupalKernel::createFromRequest(\Symfony\Component\HttpFoundation\Request::createFromGlobals());
$kernel->boot();

$database = \Drupal::database();
$config = \Drupal::config('ttd_topics.settings');
$api_key = $config->get('topicalboost_api_key');
$api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

echo "=== BA REQUEST #250532 DIAGNOSTIC ===\n\n";

// Get counts from database
$entity_count = $database->select('ttd_entities')->countQuery()->execute()->fetchField();
$relationship_count = $database->select('ttd_entity_post_ids')->countQuery()->execute()->fetchField();
$term_count = $database->select('taxonomy_term_data')->condition('vid', 'ttd_topics')->countQuery()->execute()->fetchField();

echo "LOCAL DATABASE:\n";
echo "  ttd_entities: $entity_count\n";
echo "  ttd_entity_post_ids: $relationship_count\n";
echo "  taxonomy_term (ttd_topics vocab): $term_count\n\n";

// The key issue: is the API still returning data for request #250532?
echo "CHECKING API FOR REQUEST #250532:\n";

try {
  $client = new \GuzzleHttp\Client();

  // First, get page 1 to see what's available
  $response = $client->get($api_base_url . '/result/entities', [
    'headers' => ['x-api-key' => $api_key],
    'query' => ['request_id' => '250532', 'page' => 1],
    'timeout' => 30,
  ]);

  $page1 = json_decode($response->getBody(), TRUE);
  $page1_count = count($page1['entities'] ?? []);
  $has_next = $page1['has_next_page'] ?? FALSE;

  echo "  Page 1: $page1_count entities (has_next_page: " . ($has_next ? 'true' : 'false') . ")\n";

  // Count total API entities
  $total_api_count = 0;
  $page = 1;
  $total_pages = 0;

  while ($page <= 100 && ($page == 1 || $has_next)) { // Safety limit
    $response = $client->get($api_base_url . '/result/entities', [
      'headers' => ['x-api-key' => $api_key],
      'query' => ['request_id' => '250532', 'page' => $page],
      'timeout' => 30,
    ]);

    $result = json_decode($response->getBody(), TRUE);
    $count = count($result['entities'] ?? []);
    $total_api_count += $count;
    $total_pages++;

    echo "  Page $page: $count entities\n";

    if (!isset($result['has_next_page']) || !$result['has_next_page']) {
      $has_next = FALSE;
    } else {
      $page++;
    }
  }

  echo "\n  Total API entities across all pages: $total_api_count\n";
  echo "  Total pages: $total_pages\n";

  echo "\nCOMPARISON:\n";
  echo "  API entities: $total_api_count\n";
  echo "  Local entities: $entity_count\n";
  echo "  Difference: " . ($total_api_count - $entity_count) . "\n";
  echo "  Local percentage: " . round(($entity_count / $total_api_count) * 100, 1) . "%\n";

  echo "\nAPI Relationships vs Local:\n";
  echo "  API provides relationship data in Contents array\n";

  // Sample API response structure
  if (!empty($page1['entities'])) {
    $sample = reset($page1['entities']);
    echo "\n  Sample entity structure:\n";
    echo "    ttd_id: " . ($sample['ttd_id'] ?? 'N/A') . "\n";
    echo "    name: " . ($sample['name'] ?? 'N/A') . "\n";
    echo "    Contents (array): " . (isset($sample['Contents']) ? count($sample['Contents']) . ' items' : 'MISSING') . "\n";
    echo "    SchemaTypes: " . (isset($sample['SchemaTypes']) ? count($sample['SchemaTypes']) . ' items' : 'MISSING') . "\n";
    echo "    WBCategories: " . (isset($sample['WBCategories']) ? count($sample['WBCategories']) . ' items' : 'MISSING') . "\n";
  }

  // Check for entities without Contents
  echo "\n\nCHECKING FOR DATA ISSUES:\n";

  $entities_without_contents = 0;
  $entities_with_empty_contents = 0;
  $total_content_items = 0;

  $page = 1;
  while ($page <= 100) {
    $response = $client->get($api_base_url . '/result/entities', [
      'headers' => ['x-api-key' => $api_key],
      'query' => ['request_id' => '250532', 'page' => $page],
      'timeout' => 30,
    ]);

    $result = json_decode($response->getBody(), TRUE);

    foreach ($result['entities'] ?? [] as $entity) {
      if (!isset($entity['Contents'])) {
        $entities_without_contents++;
      } elseif (empty($entity['Contents'])) {
        $entities_with_empty_contents++;
      } else {
        $total_content_items += count($entity['Contents']);
      }
    }

    if (!isset($result['has_next_page']) || !$result['has_next_page']) {
      break;
    }
    $page++;
  }

  echo "  Entities without 'Contents' key: $entities_without_contents\n";
  echo "  Entities with empty 'Contents': $entities_with_empty_contents\n";
  echo "  Total content items in all entities: $total_content_items\n";
  echo "  Avg contents per entity: " . round($total_content_items / max(1, $total_api_count - $entities_without_contents - $entities_with_empty_contents), 2) . "\n";

}
catch (\Exception $e) {
  echo "ERROR: " . $e->getMessage() . "\n";
  echo "\nPossible issues:\n";
  echo "1. API key is invalid or missing\n";
  echo "2. Request #250532 has expired/been deleted from API\n";
  echo "3. Network connectivity issue\n";
}

echo "\n\nLOCAL TOPIC ANALYSIS:\n";

// Get all local topics
$topics = $database->select('taxonomy_term_data', 't')
  ->fields('t')
  ->condition('vid', 'ttd_topics')
  ->execute()
  ->fetchAllAssoc('tid');

echo "  Total local taxonomy terms: " . count($topics) . "\n";

// Check how many have field_ttd_id set
$with_ttd_id = 0;
foreach ($topics as $topic) {
  // Check if this term has field_ttd_id
  $ttd_id_query = $database->select('taxonomy_term__field_ttd_id', 'ft')
    ->condition('entity_id', $topic->tid)
    ->countQuery()
    ->execute()
    ->fetchField();

  if ($ttd_id_query > 0) {
    $with_ttd_id++;
  }
}

echo "  Terms with field_ttd_id: $with_ttd_id\n";

// Get nodes that should have been analyzed
$node_count = $database->select('node_field_data', 'n')
  ->condition('n.status', 1)
  ->countQuery()
  ->execute()
  ->fetchField();

$enabled_types = $config->get('enabled_content_types') ?? [];
if (!empty($enabled_types)) {
  $node_count = $database->select('node_field_data', 'n')
    ->condition('n.status', 1)
    ->condition('n.type', $enabled_types, 'IN')
    ->countQuery()
    ->execute()
    ->fetchField();
  echo "  Published nodes (enabled types): $node_count\n";
  echo "  Enabled content types: " . implode(', ', $enabled_types) . "\n";
} else {
  echo "  Published nodes (all types): $node_count\n";
  echo "  Enabled content types: NONE CONFIGURED\n";
}

// Check how many nodes have topics assigned
$nodes_with_topics = $database->select('node_field_data', 'n')
  ->condition('n.status', 1);
$nodes_with_topics->innerJoin('node__field_ttd_topics', 'ft', 'n.nid = ft.entity_id');
$nodes_with_topics_count = $nodes_with_topics->countQuery()->execute()->fetchField();

echo "  Nodes with assigned topics: $nodes_with_topics_count\n";
echo "  Nodes without topics: " . ($node_count - $nodes_with_topics_count) . "\n";

echo "\n=== END DIAGNOSTIC ===\n";
