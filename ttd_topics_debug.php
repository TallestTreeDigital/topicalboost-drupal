<?php

/**
 * Debug script to analyze BA request #250532 discrepancies
 *
 * Run with: ddev exec php ttd_topics_debug.php
 */

// Bootstrap Drupal
define('DRUPAL_ROOT', __DIR__);
require_once DRUPAL_ROOT . '/web/index.php';

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once DRUPAL_ROOT . '/web/autoload.php';
$kernel = DrupalKernel::createFromRequest(Request::createFromGlobals());
$kernel->boot();

$database = \Drupal::database();
$config = \Drupal::config('ttd_topics.settings');
$api_key = $config->get('topicalboost_api_key');
$api_base_url = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

echo "=== TTD Topics Debug Report for Request #250532 ===\n\n";

// 1. Check local data
echo "LOCAL DATA STATUS:\n";
echo "-------------------\n";

$total_entities = $database->select('ttd_entities', 'te')
  ->countQuery()
  ->execute()
  ->fetchField();

$total_relationships = $database->select('ttd_entity_post_ids', 'tep')
  ->countQuery()
  ->execute()
  ->fetchField();

$total_terms = $database->select('taxonomy_term_data', 'ttd')
  ->condition('vid', 'ttd_topics')
  ->countQuery()
  ->execute()
  ->fetchField();

echo "Total entities in ttd_entities: $total_entities\n";
echo "Total relationships in ttd_entity_post_ids: $total_relationships\n";
echo "Total taxonomy terms in ttd_topics: $total_terms\n\n";

// 2. Check what request #250532 status is
echo "REQUEST #250532 STATUS:\n";
echo "----------------------\n";

$request_state = \Drupal::state()->get('topicalboost.bulk_analysis.request_id');
$apply_progress = \Drupal::state()->get('topicalboost.bulk_analysis.apply_progress', []);

echo "Request ID in state: " . ($request_state ?: 'NOT SET') . "\n";
echo "Apply progress state:\n";
echo json_encode($apply_progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

// 3. Fetch current API response for page 1 of entities
echo "API ENTITIES RESPONSE (Page 1):\n";
echo "------------------------------\n";

try {
  $client = new \GuzzleHttp\Client();
  $response = $client->get($api_base_url . '/result/entities', [
    'headers' => ['x-api-key' => $api_key],
    'query' => [
      'request_id' => '250532',
      'page' => 1,
    ],
    'timeout' => 30,
  ]);

  $result = json_decode($response->getBody(), TRUE);

  echo "API Status: " . $response->getStatusCode() . "\n";
  echo "Entities on page 1: " . count($result['entities'] ?? []) . "\n";
  echo "Has next page: " . (isset($result['has_next_page']) && $result['has_next_page'] ? 'YES' : 'NO') . "\n";
  echo "Total entities in request (from API): " . ($result['total_count'] ?? 'UNKNOWN') . "\n\n";

  // Analyze first 5 entities
  echo "SAMPLE ENTITIES (first 5):\n";
  echo "------------------------\n";

  if (isset($result['entities']) && !empty($result['entities'])) {
    $sample = array_slice($result['entities'], 0, 5);
    foreach ($sample as $idx => $entity) {
      echo "\nEntity " . ($idx + 1) . ":\n";
      echo "  ttd_id: " . ($entity['ttd_id'] ?? 'MISSING') . "\n";
      echo "  name: " . ($entity['name'] ?? 'MISSING') . "\n";
      echo "  Contents count: " . (isset($entity['Contents']) ? count($entity['Contents']) : '0') . "\n";

      if (isset($entity['Contents']) && !empty($entity['Contents'])) {
        echo "  Sample Contents:\n";
        foreach (array_slice($entity['Contents'], 0, 2) as $c_idx => $content) {
          echo "    - customer_id: " . ($content['customer_id'] ?? 'MISSING') . "\n";
        }
      } else {
        echo "  Contents: EMPTY or missing\n";
      }

      echo "  SchemaTypes: " . (isset($entity['SchemaTypes']) && !empty($entity['SchemaTypes']) ? count($entity['SchemaTypes']) . ' items' : 'EMPTY or missing') . "\n";
      echo "  WBCategories: " . (isset($entity['WBCategories']) && !empty($entity['WBCategories']) ? count($entity['WBCategories']) . ' items' : 'EMPTY or missing') . "\n";
    }
  }

  // Count entities WITHOUT Contents
  $entities_without_contents = 0;
  $entities_with_empty_contents = 0;
  $entities_with_valid_contents = 0;

  foreach ($result['entities'] ?? [] as $entity) {
    if (!isset($entity['Contents'])) {
      $entities_without_contents++;
    } elseif (empty($entity['Contents'])) {
      $entities_with_empty_contents++;
    } else {
      $entities_with_valid_contents++;
    }
  }

  echo "\n\nENTITY CONTENTS ANALYSIS (Page 1):\n";
  echo "-----------------------------------\n";
  echo "Entities without Contents key: $entities_without_contents\n";
  echo "Entities with empty Contents: $entities_with_empty_contents\n";
  echo "Entities with valid Contents: $entities_with_valid_contents\n";

}
catch (\Exception $e) {
  echo "ERROR fetching from API: " . $e->getMessage() . "\n";
  echo "Make sure:\n";
  echo "1. API endpoint is correct: $api_base_url\n";
  echo "2. API key is set: " . ($api_key ? '***' : 'NOT SET') . "\n";
  echo "3. Request #250532 still exists in API\n";
}

echo "\n\nPAGINATION ANALYSIS:\n";
echo "-------------------\n";

// Fetch all pages to count total
try {
  $client = new \GuzzleHttp\Client();
  $total_pages = 0;
  $all_entities_count = 0;
  $page = 1;
  $has_next = TRUE;

  while ($has_next && $page <= 50) { // Safety limit
    $response = $client->get($api_base_url . '/result/entities', [
      'headers' => ['x-api-key' => $api_key],
      'query' => [
        'request_id' => '250532',
        'page' => $page,
      ],
      'timeout' => 30,
    ]);

    $result = json_decode($response->getBody(), TRUE);
    $entity_count = count($result['entities'] ?? []);
    $all_entities_count += $entity_count;
    $total_pages++;

    echo "Page $page: $entity_count entities\n";

    if (!isset($result['has_next_page']) || !$result['has_next_page']) {
      $has_next = FALSE;
    } else {
      $page++;
    }
  }

  echo "\nTotal pages: $total_pages\n";
  echo "Total API entities: $all_entities_count\n";
}
catch (\Exception $e) {
  echo "Error during pagination count: " . $e->getMessage() . "\n";
}

echo "\n\nCOMPARISON:\n";
echo "-----------\n";
echo "API total entities: $all_entities_count\n";
echo "Local imported entities: $total_entities\n";
echo "Difference: " . ($all_entities_count - $total_entities) . " entities\n";
echo "Local percentage: " . round(($total_entities / $all_entities_count) * 100, 1) . "%\n";

echo "\n=== END DEBUG REPORT ===\n";
