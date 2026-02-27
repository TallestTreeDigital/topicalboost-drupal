<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for SEO Meta Generation API endpoints.
 */
class MetaGeneratorController extends ControllerBase {

  /**
   * Get keywords/topics for a node with demand metrics.
   *
   * Only returns "Also About" (focus topics), not "Mentions".
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with keywords array.
   */
  public function getKeywords(NodeInterface $node) {
    $keywords = [];

    if (!$node->hasField('field_ttd_topics')) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Node does not have topics field',
      ], 400);
    }

    // Get only "about" (focus) topics from ttd_entity_post_ids table.
    $database = \Drupal::database();
    $about_entity_ids = $database->select('ttd_entity_post_ids', 'ep')
      ->fields('ep', ['entity_id'])
      ->condition('ep.post_id', $node->id())
      ->condition('ep.salience_category', 'about')
      ->execute()
      ->fetchCol();

    $topics = $node->get('field_ttd_topics')->referencedEntities();

    foreach ($topics as $term) {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $ttd_id = $term->get('field_ttd_id')->value ?? NULL;

      // Only include topics that are in the "about" category.
      if (!empty($about_entity_ids) && $ttd_id && !in_array($ttd_id, $about_entity_ids)) {
        continue;
      }

      // Get cached demand metrics if available.
      $kd = $term->hasField('field_keyword_difficulty')
        ? $term->get('field_keyword_difficulty')->value
        : NULL;
      $volume = $term->hasField('field_search_volume')
        ? $term->get('field_search_volume')->value
        : NULL;

      $keywords[] = [
        'term_id' => $term->id(),
        'ttd_id' => $ttd_id,
        'name' => $term->getName(),
        'keyword_difficulty' => $kd !== NULL ? (float) $kd : NULL,
        'search_volume' => $volume !== NULL ? (int) $volume : NULL,
      ];
    }

    // Sort by keyword difficulty (easier first), then by name.
    usort($keywords, function ($a, $b) {
      // Handle null KD values - put them at the end.
      if ($a['keyword_difficulty'] === NULL && $b['keyword_difficulty'] === NULL) {
        return strcasecmp($a['name'], $b['name']);
      }
      if ($a['keyword_difficulty'] === NULL) {
        return 1;
      }
      if ($b['keyword_difficulty'] === NULL) {
        return -1;
      }

      // Sort by KD ascending (easier first).
      $kdDiff = $a['keyword_difficulty'] - $b['keyword_difficulty'];
      if ($kdDiff != 0) {
        return $kdDiff < 0 ? -1 : 1;
      }

      return strcasecmp($a['name'], $b['name']);
    });

    return new JsonResponse([
      'success' => TRUE,
      'data' => ['keywords' => $keywords],
    ]);
  }

  /**
   * Generate meta title/description options via TB API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with variations array.
   */
  public function generate(Request $request) {
    // Validate CSRF token.
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);

    $node_id = $content['node_id'] ?? NULL;
    $keywords = $content['keywords'] ?? [];

    if (!$node_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing node_id'],
      ], 400);
    }

    if (empty($keywords)) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'No keywords selected'],
      ], 400);
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
    if (!$node) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Node not found'],
      ], 404);
    }

    try {
      // Get post content preview (cleaned, first ~500 chars).
      $body = $node->hasField('body') ? $node->get('body')->value : '';
      $content_preview = $this->getCleanContentPreview($body);

      // Get custom prompts from config.
      $config = \Drupal::config('ttd_topics.settings');
      $api_params = [
        'postTitle' => $node->getTitle(),
        'contentPreview' => $content_preview,
        'selectedKeywords' => $keywords,
      ];

      $meta_seo_prompt = $config->get('meta_seo_prompt');
      if (!empty($meta_seo_prompt)) {
        $api_params['seoPrompt'] = $meta_seo_prompt;
      }

      $meta_social_prompt = $config->get('meta_social_prompt');
      if (!empty($meta_social_prompt)) {
        $api_params['socialPrompt'] = $meta_social_prompt;
      }

      // Call TB API to generate meta.
      $response = $this->callMetaGenerateApi($api_params);

      if ($response && isset($response['variations'])) {
        return new JsonResponse([
          'success' => TRUE,
          'data' => ['variations' => $response['variations']],
        ]);
      }
      else {
        return new JsonResponse([
          'success' => FALSE,
          'data' => ['message' => 'Failed to generate meta options'],
        ], 500);
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Meta generation error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Save generated meta to node.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function save(Request $request) {
    // Validate CSRF token.
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);

    $node_id = $content['node_id'] ?? NULL;
    $title = $content['meta_title'] ?? '';
    $description = $content['meta_description'] ?? '';

    if (!$node_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing node_id'],
      ], 400);
    }

    if (empty($title) || empty($description)) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Title and description are required'],
      ], 400);
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
    if (!$node) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Node not found'],
      ], 404);
    }

    try {
      $seo_module = $this->detectSeoModule();

      switch ($seo_module) {
        case 'metatag':
          // Metatag module stores in node field.
          if ($node->hasField('field_meta_tags')) {
            $meta_tags = $node->get('field_meta_tags')->value ?? [];
            if (is_string($meta_tags)) {
              $meta_tags = unserialize($meta_tags);
            }
            $meta_tags['title'] = $title;
            $meta_tags['description'] = $description;
            $node->set('field_meta_tags', serialize($meta_tags));
          }
          break;

        case 'yoast_seo':
          // Yoast/Real-time SEO for Drupal.
          if ($node->hasField('field_yoast_seo')) {
            $yoast_data = $node->get('field_yoast_seo')->value ?? '{}';
            $yoast = json_decode($yoast_data, TRUE) ?: [];
            $yoast['title'] = $title;
            $yoast['description'] = $description;
            $node->set('field_yoast_seo', json_encode($yoast));
          }
          break;

        default:
          // Fallback - store in custom fields if available.
          if ($node->hasField('field_ttd_meta_title')) {
            $node->set('field_ttd_meta_title', $title);
          }
          if ($node->hasField('field_ttd_meta_description')) {
            $node->set('field_ttd_meta_description', $description);
          }
          break;
      }

      // Also store in our own fields for reference.
      if ($node->hasField('field_ttd_generated_meta_title')) {
        $node->set('field_ttd_generated_meta_title', $title);
      }
      if ($node->hasField('field_ttd_generated_meta_desc')) {
        $node->set('field_ttd_generated_meta_desc', $description);
      }

      $node->save();

      // Return the new changed timestamp so JS can update the form's hidden field
      // This prevents "content has been modified by another user" errors
      $changed = $node->getChangedTime();

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'message' => 'Meta saved successfully',
          'seo_module' => $seo_module,
          'changed' => $changed,
        ],
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Meta save error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Call the TopicalBoost API to generate meta.
   *
   * @param array $params
   *   Request parameters.
   *
   * @return array|null
   *   API response or null on error.
   */
  private function callMetaGenerateApi(array $params) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      throw new \Exception('API key not configured');
    }

    $endpoint = TOPICALBOOST_API_ENDPOINT . '/meta/generate';

    try {
      $client = \Drupal::httpClient();
      $response = $client->post($endpoint, [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => $params,
        'timeout' => 60,
      ]);

      $body = json_decode($response->getBody()->getContents(), TRUE);
      return $body;
    }
    catch (RequestException $e) {
      \Drupal::logger('ttd_topics')->error('Meta API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Detect which SEO module is active.
   *
   * @return string
   *   The detected SEO module name.
   */
  private function detectSeoModule() {
    $module_handler = \Drupal::moduleHandler();

    if ($module_handler->moduleExists('metatag')) {
      return 'metatag';
    }

    if ($module_handler->moduleExists('yoast_seo')) {
      return 'yoast_seo';
    }

    return 'none';
  }

  /**
   * Extract a clean content preview for meta generation.
   *
   * Strips figure/figcaption elements, image tags, and common image credit
   * patterns so the LLM receives only meaningful body text.
   */
  private function getCleanContentPreview(string $content, int $max_length = 500): string {
    // Remove <figure>, <figcaption>, <caption> elements and their content.
    $content = preg_replace('/<figure[^>]*>.*?<\/figure>/si', '', $content);
    $content = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/si', '', $content);
    $content = preg_replace('/<caption[^>]*>.*?<\/caption>/si', '', $content);

    // Remove <img> tags.
    $content = preg_replace('/<img[^>]*>/si', '', $content);

    // Remove common image credit patterns.
    $content = preg_replace('/\b(Image|Photo|Picture|Photograph)\s+(courtesy|credit|by|via|source)\s*[:.]?\s*[^.]*\./si', '', $content);
    $content = preg_replace('/\bCredit\s*:\s*[^.]*\./si', '', $content);

    // Strip remaining HTML tags.
    $content = strip_tags($content);

    // Collapse whitespace.
    $content = preg_replace('/\s+/', ' ', $content);
    $content = trim($content);

    return mb_substr($content, 0, $max_length);
  }

}
