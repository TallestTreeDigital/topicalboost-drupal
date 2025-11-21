<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\node\Entity\Node;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for TopicalBoost coverage comparison page.
 */
class CoverageController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The cache service.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a CoverageController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('cache.default')
    );
  }

  /**
   * Display the topic coverage page.
   */
  public function display() {
    $config = $this->config('ttd_topics.settings');
    $enabled_content_types = $config->get('enabled_content_types') ?? [];

    // Get local metrics.
    $local_data = $this->getLocalMetrics($enabled_content_types);

    // Build the render array.
    $build = [
      '#theme' => 'ttd_coverage_page',
      '#local_data' => $local_data,
      '#attached' => [
        'library' => [
          'ttd_topics/coverage',
        ],
        'drupalSettings' => [
          'ttdCoverage' => [
            'ajaxUrl' => '/api/topicalboost/coverage/metrics',
            'nonce' => \Drupal::csrfToken()->get('ttd-coverage-metrics'),
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Get local metrics from the database.
   *
   * @param array $enabled_content_types
   *   Array of enabled content type machine names.
   *
   * @return array
   *   Array of local metrics.
   */
  protected function getLocalMetrics(array $enabled_content_types = []) {
    // Get total published nodes across all types (or specific types if configured).
    $node_query = $this->database->select('node_field_data', 'n')
      ->condition('n.status', 1);

    if (!empty($enabled_content_types)) {
      $node_query->condition('n.type', $enabled_content_types, 'IN');
    }

    $total_nodes = $node_query->countQuery()->execute()->fetchField();

    // Get count of nodes with at least one topic assigned.
    $nodes_with_topics_query = $this->database->select('node_field_data', 'n')
      ->condition('n.status', 1);

    if (!empty($enabled_content_types)) {
      $nodes_with_topics_query->condition('n.type', $enabled_content_types, 'IN');
    }

    $nodes_with_topics_query->innerJoin('node__field_ttd_topics', 'ft', 'n.nid = ft.entity_id');
    $nodes_with_topics = $nodes_with_topics_query->countQuery()->execute()->fetchField();

    // Get total topic relationships (assignments).
    // Use raw SQL to count distinct entity_id + target_id combinations
    // to avoid counting duplicates from multiple deltas/revisions
    $query_string = "SELECT COUNT(DISTINCT ft.entity_id, ft.field_ttd_topics_target_id)
      FROM {node__field_ttd_topics} ft
      INNER JOIN {node_field_data} n ON ft.entity_id = n.nid
      WHERE n.status = 1";

    $params = [];
    if (!empty($enabled_content_types)) {
      $placeholders = implode(',', array_fill(0, count($enabled_content_types), '?'));
      $query_string .= " AND n.type IN ($placeholders)";
      $params = $enabled_content_types;
    }

    $total_relationships = (int)$this->database->query($query_string, $params)->fetchField();

    // Get unique topics.
    $topics_query = $this->database->select('taxonomy_term_field_data', 't')
      ->condition('t.vid', 'ttd_topics');

    $total_topics = $topics_query->countQuery()->execute()->fetchField();

    // Calculate average topics per post.
    $avg_topics_per_post = $total_nodes > 0 ? round($total_relationships / $total_nodes, 2) : 0;
    $coverage_percentage = $total_nodes > 0 ? round(($nodes_with_topics / $total_nodes) * 100, 2) : 0;

    // Get per-type breakdown.
    $type_stats = $this->getContentTypeBreakdown($enabled_content_types);

    return [
      'total_nodes' => (int) $total_nodes,
      'nodes_with_topics' => (int) $nodes_with_topics,
      'nodes_without_topics' => (int) ($total_nodes - $nodes_with_topics),
      'total_relationships' => (int) $total_relationships,
      'total_topics' => (int) $total_topics,
      'avg_topics_per_post' => $avg_topics_per_post,
      'coverage_percentage' => $coverage_percentage,
      'type_stats' => $type_stats,
    ];
  }

  /**
   * Get content type breakdown.
   *
   * @param array $enabled_content_types
   *   Array of enabled content type machine names.
   *
   * @return array
   *   Array of stats per content type.
   */
  protected function getContentTypeBreakdown(array $enabled_content_types = []) {
    $type_stats = [];

    // Get all content types (or just enabled ones).
    $types_to_check = !empty($enabled_content_types) ? $enabled_content_types : [];

    if (empty($types_to_check)) {
      // If no types configured, get all types.
      $type_entities = \Drupal::entityTypeManager()
        ->getStorage('node_type')
        ->loadMultiple();
      $types_to_check = array_keys($type_entities);
    }

    foreach ($types_to_check as $type) {
      // Count total nodes of this type.
      $total = $this->database->select('node_field_data', 'n')
        ->condition('n.type', $type)
        ->condition('n.status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($total == 0) {
        continue;
      }

      // Count nodes with topics.
      $with_topics_query = $this->database->select('node_field_data', 'n')
        ->condition('n.type', $type)
        ->condition('n.status', 1);
      $with_topics_query->innerJoin('node__field_ttd_topics', 'ft', 'n.nid = ft.entity_id');
      $with_topics = $with_topics_query->countQuery()
        ->execute()
        ->fetchField();

      $coverage = $total > 0 ? round(($with_topics / $total) * 100, 2) : 0;

      $bar_class = $coverage >= 90 ? 'high' : ($coverage >= 50 ? 'medium' : 'low');
      $bar_style = 'width: ' . (int) $coverage . '%;';

      \Drupal::logger('ttd_topics')->warning("DEBUG Coverage Type: $type, Coverage: $coverage, BarClass: $bar_class, BarStyle: $bar_style");

      $type_stats[$type] = [
        'total' => (int) $total,
        'with_topics' => (int) $with_topics,
        'without_topics' => (int) ($total - $with_topics),
        'coverage_percentage' => $coverage,
        'bar_width' => (int) $coverage,
        'bar_class' => $bar_class,
        'bar_style' => $bar_style,
      ];
    }

    return $type_stats;
  }

  /**
   * Get API metrics via AJAX.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with API metrics.
   */
  public function getMetrics() {
    // Verify nonce.
    $nonce = \Drupal::request()->query->get('token');
    if (!$nonce || !\Drupal::csrfToken()->validate($nonce, 'ttd-coverage-metrics')) {
      return new JsonResponse(['error' => 'Invalid token'], 403);
    }

    // Check permission.
    if (!$this->currentUser()->hasPermission('administer topicalboost configuration')) {
      return new JsonResponse(['error' => 'Access denied'], 403);
    }

    // Check for cache bypass.
    $force_refresh = \Drupal::request()->query->get('force_refresh');
    $cache_key = 'ttd_site_metrics';

    if (!$force_refresh) {
      $cached = $this->cache->get($cache_key);
      if ($cached) {
        return new JsonResponse($cached->data);
      }
    }

    // Fetch from API.
    $api_data = $this->fetchSiteMetricsFromApi();

    if ($api_data) {
      // Cache for 10 minutes.
      $this->cache->set($cache_key, $api_data, time() + (10 * 60));
    }

    return new JsonResponse($api_data ?: ['error' => 'Failed to fetch API metrics']);
  }

  /**
   * Fetch site metrics from TopicalBoost API.
   *
   * @return array|null
   *   API response array or NULL on failure.
   */
  protected function fetchSiteMetricsFromApi() {
    $config = $this->config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    $api_base_url = TOPICALBOOST_API_ENDPOINT ?? 'https://api.topicalboost.com';

    if (!$api_key) {
      return [
        'error' => 'API key not configured',
        'cached_at' => date('c'),
      ];
    }

    try {
      $client = new Client();
      $response = $client->get($api_base_url . '/site/metrics', [
        'headers' => [
          'x-api-key' => $api_key,
        ],
        'timeout' => 30,
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $data['cached_at'] = date('c');
      return $data;
    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Failed to fetch site metrics from API: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [
        'error' => 'Failed to fetch metrics: ' . $e->getMessage(),
        'cached_at' => date('c'),
      ];
    }
  }

}
