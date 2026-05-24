<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Watchlist API endpoints.
 */
class WatchlistController extends ControllerBase {

  /**
   * Helper to make authenticated API requests.
   */
  private function apiRequest(string $method, string $path, array $json = NULL, array $query = []) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return NULL;
    }

    $options = [
      'headers' => [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key,
      ],
      'timeout' => 30,
    ];

    if ($json !== NULL) {
      $options['json'] = $json;
    }
    if (!empty($query)) {
      $options['query'] = $query;
    }

    try {
      $client = \Drupal::httpClient();
      $url = TOPICALBOOST_API_ENDPOINT . $path;
      $response = $client->request($method, $url, $options);
      return json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Watchlist API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get the current watchlist.
   */
  public function get(Request $request) {
    $response = $this->apiRequest('GET', '/watchlist');

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to fetch watchlist'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $response,
    ]);
  }

  /**
   * Search for entities to add to watchlist.
   */
  public function search(Request $request) {
    $query = $request->request->get('query', '');

    if (strlen($query) < 2) {
      return new JsonResponse([
        'success' => TRUE,
        'data' => ['candidates' => []],
      ]);
    }

    $response = $this->apiRequest('GET', '/lookup', NULL, ['q' => $query]);

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Search failed'],
      ], 500);
    }

    $candidates = [];
    if (is_array($response)) {
      foreach ($response as $result) {
        if (empty($result['ttd_id'])) {
          continue;
        }
        $candidates[] = [
          'entityId' => (int) $result['ttd_id'],
          'name' => $result['name'] ?? $result['kg_name'] ?? $result['wb_name'] ?? '',
          'description' => $result['description'] ?? $result['kg_description'] ?? $result['wb_description'] ?? '',
          'source' => $result['source'] ?? 'unknown',
        ];
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['candidates' => $candidates],
    ]);
  }

  /**
   * Add an entity to the watchlist.
   */
  public function add(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $entity_id = (int) ($content['entity_id'] ?? 0);
    $label = trim($content['label'] ?? '');

    if (!$entity_id || empty($label)) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Entity ID and label are required'],
      ], 400);
    }

    $response = $this->apiRequest('POST', '/watchlist/add', [
      'entityId' => $entity_id,
      'label' => $label,
    ]);

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to add to watchlist'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $response,
    ]);
  }

  /**
   * Create a custom entity and add to watchlist.
   */
  public function createCustom(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $name = trim($content['name'] ?? '');

    if (strlen($name) < 2) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Name must be at least 2 characters'],
      ], 400);
    }

    $response = $this->apiRequest('POST', '/watchlist/create-custom', [
      'name' => $name,
    ]);

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to create custom entity'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $response,
    ]);
  }

  /**
   * Remove an entity from the watchlist.
   */
  public function remove(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $entity_id = (int) ($content['entity_id'] ?? 0);

    if (!$entity_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Entity ID is required'],
      ], 400);
    }

    $response = $this->apiRequest('DELETE', '/watchlist/' . $entity_id);

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to remove from watchlist'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['removed' => TRUE],
    ]);
  }

}
