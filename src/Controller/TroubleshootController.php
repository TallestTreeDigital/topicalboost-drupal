<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for troubleshooting endpoints.
 *
 * Handles stuck posts, rejected topics, entity info, and search countries.
 */
class TroubleshootController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a TroubleshootController.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

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
      \Drupal::logger('ttd_topics')->error('Troubleshoot API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Renders the troubleshoot page.
   */
  public function page() {
    $stuck_posts = $this->getStuckPosts();
    $recently_retried = $this->getRecentlyRetriedPosts();
    $rejected_posts = $this->getRejectedPosts();

    $build = [
      '#theme' => 'ttd_troubleshoot_page',
      '#stuck_posts' => $stuck_posts,
      '#recently_retried' => $recently_retried,
      '#rejected_posts' => $rejected_posts,
      '#attached' => [
        'library' => ['ttd_topics/troubleshoot'],
      ],
    ];

    return $build;
  }

  /**
   * Get nodes with analysis in progress (stuck).
   */
  private function getStuckPosts() {
    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title', 'type', 'status', 'changed']);
    $query->join('node__field_ttd_analysis_in_progress', 'aip', 'n.nid = aip.entity_id');
    $query->condition('aip.field_ttd_analysis_in_progress_value', 1);
    $query->orderBy('n.changed', 'DESC');
    $query->range(0, 50);

    $results = $query->execute()->fetchAll();

    // Add last_retry info.
    foreach ($results as &$post) {
      $last_retry = $this->database->select('node__field_ttd_last_retry', 'lr')
        ->fields('lr', ['field_ttd_last_retry_value'])
        ->condition('lr.entity_id', $post->nid)
        ->execute()
        ->fetchField();
      $post->last_retry = $last_retry ?: NULL;
    }

    return $results;
  }

  /**
   * Get recently retried posts (retried in last 24 hours).
   */
  private function getRecentlyRetriedPosts() {
    $cutoff = date('Y-m-d\TH:i:s', strtotime('-24 hours'));

    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->fields('n', ['nid', 'title', 'type', 'status']);
      $query->join('node__field_ttd_last_retry', 'lr', 'n.nid = lr.entity_id');
      $query->fields('lr', ['field_ttd_last_retry_value']);
      $query->condition('lr.field_ttd_last_retry_value', $cutoff, '>=');
      $query->orderBy('lr.field_ttd_last_retry_value', 'DESC');
      $query->range(0, 20);

      return $query->execute()->fetchAll();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Get posts with all topics rejected.
   */
  private function getRejectedPosts() {
    try {
      $query = $this->database->select('node_field_data', 'n');
      $query->fields('n', ['nid', 'title', 'type', 'status', 'changed']);
      $query->join('node__field_ttd_rejected_topics', 'rt', 'n.nid = rt.entity_id');
      $query->condition('rt.field_ttd_rejected_topics_value', '', '<>');
      $query->orderBy('n.changed', 'DESC');
      $query->range(0, 50);

      $results = $query->execute()->fetchAll();

      foreach ($results as &$post) {
        $rejected = $this->database->select('node__field_ttd_rejected_topics', 'rt')
          ->fields('rt', ['field_ttd_rejected_topics_value'])
          ->condition('rt.entity_id', $post->nid)
          ->execute()
          ->fetchField();
        $post->rejected_topics = $rejected ?: '';
      }

      return $results;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Clear analysis flags for stuck posts.
   */
  public function clearAnalysisFlags(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $include_recent = !empty($content['include_recent']);

    try {
      $query = $this->database->select('node__field_ttd_analysis_in_progress', 'aip');
      $query->fields('aip', ['entity_id']);
      $query->condition('aip.field_ttd_analysis_in_progress_value', 1);

      if (!$include_recent) {
        // Only clear flags older than 1 hour.
        $query->join('node_field_data', 'n', 'aip.entity_id = n.nid');
        $cutoff = \Drupal::time()->getRequestTime() - 3600;
        $query->condition('n.changed', $cutoff, '<');
      }

      $nids = $query->execute()->fetchCol();
      $cleared = 0;

      foreach ($nids as $nid) {
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
        if ($node && $node->hasField('field_ttd_analysis_in_progress')) {
          $node->set('field_ttd_analysis_in_progress', FALSE);
          $node->save();
          $cleared++;
        }
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => ['message' => 'Analysis flags cleared', 'cleared' => $cleared],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Database error: ' . $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Handle stuck posts - clear flag and optionally reanalyze.
   */
  public function handleStuckPosts(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $nids = array_map('intval', $content['post_ids'] ?? []);
    $operation = $content['operation'] ?? '';

    if (empty($nids) || !in_array($operation, ['clear', 'reanalyze'])) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid parameters'],
      ], 400);
    }

    $node_storage = \Drupal::entityTypeManager()->getStorage('node');

    foreach ($nids as $nid) {
      $node = $node_storage->load($nid);
      if (!$node || !$node->hasField('field_ttd_analysis_in_progress')) {
        continue;
      }

      $node->set('field_ttd_analysis_in_progress', FALSE);
      $node->save();

      if ($operation === 'reanalyze') {
        // Check 1-hour cooldown.
        $last_retry = $node->hasField('field_ttd_last_retry')
          ? $node->get('field_ttd_last_retry')->value
          : NULL;

        $can_retry = !$last_retry || strtotime($last_retry) <= strtotime('-1 hour');

        if ($can_retry) {
          if ($node->hasField('field_ttd_last_retry')) {
            $node->set('field_ttd_last_retry', date('Y-m-d\TH:i:s'));
            $node->save();
          }

          // Schedule reanalysis.
          $analysis_service = \Drupal::service('ttd_topics.analysis_service');
          $analysis_service->performSingleAnalysis($node, TRUE);
        }
      }
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['message' => 'Posts processed successfully'],
    ]);
  }

  /**
   * Clear polling flag for a node.
   */
  public function clearPollingFlag(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $nid = (int) ($content['node_id'] ?? 0);

    if ($nid) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
      if ($node && $node->hasField('field_ttd_analysis_needs_polling')) {
        $node->set('field_ttd_analysis_needs_polling', FALSE);
        $node->save();
      }
    }

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Clear rejected topics for a post.
   */
  public function clearRejectedTopics(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $nid = (int) ($content['post_id'] ?? 0);

    if (!$nid) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid post ID'],
      ], 400);
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if ($node && $node->hasField('field_ttd_rejected_topics')) {
      $node->set('field_ttd_rejected_topics', NULL);
      $node->save();
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['message' => 'Rejected topics cleared'],
    ]);
  }

  /**
   * Get entity info by TTD ID.
   */
  public function getEntityInfo(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $ttd_id = (int) ($content['ttd_id'] ?? 0);
    $term_id = (int) ($content['term_id'] ?? 0);

    if (!$ttd_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing entity ID'],
      ], 400);
    }

    // Look up entity data from ttd_entities table.
    try {
      $entity = $this->database->select('ttd_entities', 'e')
        ->fields('e')
        ->condition('e.ttd_id', $ttd_id)
        ->execute()
        ->fetchAssoc();
    }
    catch (\Exception $e) {
      $entity = NULL;
    }

    if (!$entity) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Entity not found'],
      ], 404);
    }

    // Pick best name/description/image.
    $name = $entity['kg_name'] ?: ($entity['wb_name'] && $entity['wb_name'] !== 'No Label Defined' ? $entity['wb_name'] : ($entity['nl_name'] ?: 'Unknown'));
    $description = $entity['kg_description'] ?: ($entity['wb_description'] ?? '');
    $image = $entity['kg_image'] ?: ($entity['wb_image'] ?: ($entity['wb_logo_image'] ?? ''));
    $type = $entity['nl_type'] ?? '';
    $wikipedia_url = $entity['wikipedia_url'] ?? '';

    // Get term info if provided.
    $term_slug = '';
    $posts_url = '';
    if ($term_id) {
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
      if ($term) {
        $term_slug = $term->get('path')->alias ?? '';
        $posts_url = '/admin/content?field_ttd_topics_target_id=' . $term_id;
      }
    }

    // Count posts for this entity.
    $post_count = 0;
    try {
      $post_count = (int) $this->database->select('ttd_entity_post_ids', 'ep')
        ->condition('ep.entity_id', $ttd_id)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      // Table may not exist.
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => [
        'ttd_id' => $ttd_id,
        'name' => $name,
        'description' => $description,
        'image' => $image,
        'type' => $type,
        'wikipedia_url' => $wikipedia_url,
        'post_count' => $post_count,
        'term_slug' => $term_slug,
        'posts_url' => $posts_url,
      ],
    ]);
  }

  /**
   * Get search countries from API.
   */
  public function getSearchCountries(Request $request) {
    $response = $this->apiRequest('GET', '/site-settings/countries');

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to fetch search countries from API'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $response,
    ]);
  }

  /**
   * Save search countries to API.
   */
  public function saveSearchCountries(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $countries = $content['countries'] ?? [];

    if (empty($countries)) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'At least one country is required'],
      ], 400);
    }

    // Sanitize.
    $countries = array_map(function ($c) {
      return substr(trim($c), 0, 10);
    }, $countries);

    $response = $this->apiRequest('PUT', '/site-settings/countries', [
      'countries' => $countries,
    ]);

    if ($response === NULL) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to save search countries to API'],
      ], 500);
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $response,
    ]);
  }

}
