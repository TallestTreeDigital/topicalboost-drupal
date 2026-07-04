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
   * Only returns "About" focus topics, not "Mentions".
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

    $topics = $node->get('field_ttd_topics')->referencedEntities();
    $term_ids = array_map(static fn($term) => (int) $term->id(), $topics);
    $term_counts = function_exists('ttd_topics_get_topic_node_counts')
      ? \ttd_topics_get_topic_node_counts($term_ids)
      : [];
    $threshold_count = (int) (\Drupal::config('ttd_topics.settings')->get('post_topic_minimum_display_count') ?? 10);
    $manual_term_ids = $node->hasField('field_manual_topics')
      ? array_map('intval', array_column($node->get('field_manual_topics')->getValue(), 'target_id'))
      : [];
    $rejected_term_ids = $node->hasField('field_ttd_rejected_topics')
      ? array_map('intval', array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id'))
      : [];

    foreach ($topics as $term) {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $term_id = (int) $term->id();
      $is_manual = in_array($term_id, $manual_term_ids, TRUE);
      $is_hidden = $term->hasField('field_hide') && !$term->get('field_hide')->isEmpty() && (bool) $term->get('field_hide')->value;
      if ($is_hidden || (!$is_manual && in_array($term_id, $rejected_term_ids, TRUE))) {
        continue;
      }

      $ttd_id = $term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()
        ? (int) $term->get('field_ttd_id')->value
        : NULL;
      $tier = function_exists('ttd_get_topic_tier') ? \ttd_get_topic_tier($term_id, $node->id()) : 'mentions';

      // Only include main/about topics for SEO meta generation.
      if (!in_array($tier, ['mainEntity', 'about'], TRUE)) {
        continue;
      }

      $is_forced = $term->hasField('field_force_show') && !$term->get('field_force_show')->isEmpty() && (bool) $term->get('field_force_show')->value;
      $count = (int) ($term_counts[$term_id] ?? 0);
      if ($tier === 'about' && !$is_manual && !$is_forced && $count < $threshold_count) {
        continue;
      }

      $metrics = function_exists('ttd_get_demand_metrics') ? \ttd_get_demand_metrics($term_id) : NULL;
      $kd = $metrics['keyword_difficulty'] ?? NULL;
      $traffic_potential = $metrics['traffic_potential'] ?? NULL;
      $volume = $metrics['search_volume'] ?? NULL;

      $keywords[] = [
        'term_id' => $term_id,
        'ttd_id' => $ttd_id,
        'name' => $term->getName(),
        'keyword_difficulty' => $kd !== NULL ? (float) $kd : NULL,
        'traffic_potential' => $traffic_potential !== NULL ? (int) $traffic_potential : NULL,
        'search_volume' => $volume !== NULL ? (int) $volume : NULL,
        'tier' => $tier,
        'tier_priority' => $tier === 'mainEntity' ? 0 : 1,
      ];
    }

    // Sort by tier first, then opportunity score like WordPress.
    usort($keywords, function ($a, $b) {
      if ($a['tier_priority'] !== $b['tier_priority']) {
        return $a['tier_priority'] - $b['tier_priority'];
      }

      $a_volume = $a['traffic_potential'] ?? 0;
      $b_volume = $b['traffic_potential'] ?? 0;
      $a_kd = $a['keyword_difficulty'] ?? 50;
      $b_kd = $b['keyword_difficulty'] ?? 50;
      $a_score = $a_volume / ($a_kd + 10);
      $b_score = $b_volume / ($b_kd + 10);

      if ($a_score !== $b_score) {
        return $b_score <=> $a_score;
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
      // Get post content preview (cleaned, first ~5000 chars).
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
          if ($this->getMetatagFieldName($node) !== NULL) {
            $this->applyMetatagSeoValues($node, $title, $description);
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
   * Gets the Metatag field name for a node, regardless of local machine name.
   */
  private function getMetatagFieldName(NodeInterface $node): ?string {
    foreach (['field_meta_tags', 'field_metatag'] as $field_name) {
      if ($node->hasField($field_name)) {
        return $field_name;
      }
    }

    foreach ($node->getFieldDefinitions() as $field_name => $definition) {
      if ($definition->getType() === 'metatag') {
        return $field_name;
      }
    }

    return NULL;
  }

  /**
   * Loads serialized Metatag field values from a node.
   */
  private function getMetatagValues(NodeInterface $node): array {
    $field_name = $this->getMetatagFieldName($node);
    if ($field_name === NULL) {
      return [];
    }

    $value = $node->get($field_name)->value ?? [];
    if (is_array($value)) {
      return $value;
    }

    if (is_string($value) && $value !== '') {
      $decoded = @unserialize($value, ['allowed_classes' => FALSE]);
      return is_array($decoded) ? $decoded : [];
    }

    return [];
  }

  /**
   * Saves Metatag field values to a node when the field exists.
   */
  private function setMetatagValues(NodeInterface $node, array $meta_tags): void {
    $field_name = $this->getMetatagFieldName($node);
    if ($field_name !== NULL) {
      $node->set($field_name, serialize($meta_tags));
    }
  }

  /**
   * Gets the canonical URL for a node.
   */
  private function getNodeCanonicalUrl(NodeInterface $node): string {
    try {
      return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Exception $e) {
      return '';
    }
  }

  /**
   * Applies SEO values plus canonical/social fallbacks to Metatag.
   */
  private function applyMetatagSeoValues(NodeInterface $node, string $title, string $description): void {
    $meta_tags = $this->getMetatagValues($node);
    $canonical_url = $this->getNodeCanonicalUrl($node);

    $meta_tags['title'] = $title;
    $meta_tags['description'] = $description;

    if ($canonical_url !== '') {
      $meta_tags['canonical_url'] = $canonical_url;
      $meta_tags['og_url'] = $canonical_url;
      $meta_tags['twitter_cards_page_url'] = $canonical_url;
    }

    $meta_tags['og_title'] = $meta_tags['og_title'] ?? $title;
    $meta_tags['og_description'] = $meta_tags['og_description'] ?? $description;
    $meta_tags['twitter_cards_title'] = $meta_tags['twitter_cards_title'] ?? $meta_tags['og_title'];
    $meta_tags['twitter_cards_description'] = $meta_tags['twitter_cards_description'] ?? $meta_tags['og_description'];

    $this->setMetatagValues($node, $meta_tags);
  }

  /**
   * Applies social values to Open Graph and Twitter Card Metatag fields.
   */
  private function applyMetatagSocialValues(NodeInterface $node, string $title, string $description): void {
    $meta_tags = $this->getMetatagValues($node);
    $canonical_url = $this->getNodeCanonicalUrl($node);

    $meta_tags['og_title'] = $title;
    $meta_tags['og_description'] = $description;
    $meta_tags['twitter_cards_title'] = $title;
    $meta_tags['twitter_cards_description'] = $description;

    if ($canonical_url !== '') {
      $meta_tags['og_url'] = $canonical_url;
      $meta_tags['twitter_cards_page_url'] = $canonical_url;
      $meta_tags['canonical_url'] = $meta_tags['canonical_url'] ?? $canonical_url;
    }

    $this->setMetatagValues($node, $meta_tags);
  }

  /**
   * Extract a clean content preview for meta generation.
   *
   * Strips figure/figcaption elements, image tags, and common image credit
   * patterns so the LLM receives only meaningful body text.
   */
  private function getCleanContentPreview(string $content, int $max_length = 5000): string {
    // Remove <figure>, <figcaption>, <caption> elements and their content.
    $content = preg_replace('/<figure[^>]*>.*?<\/figure>/si', '', $content);
    $content = preg_replace('/<figcaption[^>]*>.*?<\/figcaption>/si', '', $content);
    $content = preg_replace('/<caption[^>]*>.*?<\/caption>/si', '', $content);

    // Remove <img> tags.
    $content = preg_replace('/<img[^>]*>/si', '', $content);

    // Remove common image credit patterns.
    $content = preg_replace('/\b(Image|Photo|Picture|Photograph)\s+(courtesy|credit|by|via|source)\s*[:.]?\s*[^.]*\./si', '', $content);
    $content = preg_replace('/\bCredit\s*:\s*[^.]*\./si', '', $content);

    $content = function_exists('ttd_topics_filter_text')
      ? \ttd_topics_filter_text($content)
      : trim(preg_replace('/\s+/', ' ', strip_tags($content)));

    return mb_substr($content, 0, $max_length);
  }

  /**
   * Generate social meta (OG) title/description via TB API.
   */
  public function generateSocial(Request $request) {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    $node_id = $content['node_id'] ?? NULL;

    if (!$node_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing node_id'],
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
      $body = $node->hasField('body') ? $node->get('body')->value : '';
      $content_preview = $this->getCleanContentPreview($body);

      if (empty($content_preview)) {
        $content_preview = $node->getTitle();
      }

      if (empty($content_preview)) {
        return new JsonResponse([
          'success' => FALSE,
          'data' => ['message' => 'Post has no content or title to generate meta from'],
        ], 400);
      }

      $config = \Drupal::config('ttd_topics.settings');
      $api_key = $config->get('topicalboost_api_key');

      if (empty($api_key)) {
        throw new \Exception('API key not configured');
      }

      $api_params = [
        'postTitle' => $node->getTitle(),
        'contentPreview' => $content_preview,
      ];

      $client = \Drupal::httpClient();
      $response = $client->post(TOPICALBOOST_API_ENDPOINT . '/meta/generate-social', [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => $api_params,
        'timeout' => 60,
      ]);

      $result = json_decode($response->getBody()->getContents(), TRUE);

      if ($result && isset($result['variations'])) {
        return new JsonResponse([
          'success' => TRUE,
          'data' => ['variations' => $result['variations']],
        ]);
      }

      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to generate social meta options'],
      ], 500);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Social meta generation error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Save social meta (OG tags) to node.
   */
  public function saveSocial(Request $request) {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    $node_id = $content['node_id'] ?? NULL;
    $title = trim($content['meta_title'] ?? '');
    $description = trim($content['meta_description'] ?? '');

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

      // Save to SEO module's OG fields if available.
      if ($seo_module === 'metatag' && $this->getMetatagFieldName($node) !== NULL) {
        $this->applyMetatagSocialValues($node, $title, $description);
      }

      // Store in our own fields for reference.
      if ($node->hasField('field_ttd_generated_og_title')) {
        $node->set('field_ttd_generated_og_title', $title);
      }
      if ($node->hasField('field_ttd_generated_og_desc')) {
        $node->set('field_ttd_generated_og_desc', $description);
      }

      $node->save();
      $changed = $node->getChangedTime();

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'message' => 'Social meta saved successfully',
          'seo_module' => $seo_module,
          'changed' => $changed,
        ],
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Social meta save error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Clear social meta from node.
   */
  public function clearSocial(Request $request) {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    $node_id = $content['node_id'] ?? NULL;

    if (!$node_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing node_id'],
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

      if ($node->hasField('field_ttd_generated_og_title')) {
        $node->set('field_ttd_generated_og_title', NULL);
      }
      if ($node->hasField('field_ttd_generated_og_desc')) {
        $node->set('field_ttd_generated_og_desc', NULL);
      }

      if ($seo_module === 'metatag' && $this->getMetatagFieldName($node) !== NULL) {
        $meta_tags = $this->getMetatagValues($node);
        unset(
          $meta_tags['og_title'],
          $meta_tags['og_description'],
          $meta_tags['twitter_cards_title'],
          $meta_tags['twitter_cards_description']
        );

        $fallback_title = $node->hasField('field_ttd_generated_meta_title')
          ? (string) $node->get('field_ttd_generated_meta_title')->value
          : '';
        $fallback_description = $node->hasField('field_ttd_generated_meta_desc')
          ? (string) $node->get('field_ttd_generated_meta_desc')->value
          : '';

        $this->setMetatagValues($node, $meta_tags);
        if ($fallback_title !== '' && $fallback_description !== '') {
          $this->applyMetatagSeoValues($node, $fallback_title, $fallback_description);
        }
      }

      $node->save();

      return new JsonResponse([
        'success' => TRUE,
        'data' => ['message' => 'Social meta cleared'],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Discard pending (unsaved) meta.
   */
  public function discardPending(Request $request) {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    $node_id = $content['node_id'] ?? NULL;

    if (!$node_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing node_id'],
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
      if ($node->hasField('field_ttd_pending_meta_title')) {
        $node->set('field_ttd_pending_meta_title', NULL);
      }
      if ($node->hasField('field_ttd_pending_meta_desc')) {
        $node->set('field_ttd_pending_meta_desc', NULL);
      }

      $node->save();

      return new JsonResponse([
        'success' => TRUE,
        'data' => ['message' => 'Pending meta discarded'],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Clear all generated meta from node.
   */
  public function clearMeta(Request $request) {
    $token = $request->headers->get('X-CSRF-Token');
    if (!$token || !\Drupal::csrfToken()->validate($token, 'ttd_meta_generator')) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Invalid CSRF token'],
      ], 403);
    }

    $content = json_decode($request->getContent(), TRUE);
    $node_id = $content['node_id'] ?? NULL;

    if (!$node_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing node_id'],
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

      // Clear SEO module fields.
      if ($seo_module === 'metatag' && $this->getMetatagFieldName($node) !== NULL) {
        $meta_tags = $this->getMetatagValues($node);
        unset(
          $meta_tags['title'],
          $meta_tags['description'],
          $meta_tags['canonical_url'],
          $meta_tags['og_title'],
          $meta_tags['og_description'],
          $meta_tags['og_url'],
          $meta_tags['twitter_cards_title'],
          $meta_tags['twitter_cards_description'],
          $meta_tags['twitter_cards_page_url']
        );
        $this->setMetatagValues($node, $meta_tags);
      }

      // Clear our reference fields.
      $clear_fields = [
        'field_ttd_generated_meta_title',
        'field_ttd_generated_meta_desc',
        'field_ttd_pending_meta_title',
        'field_ttd_pending_meta_desc',
        'field_ttd_meta_title',
        'field_ttd_meta_description',
        'field_ttd_generated_og_title',
        'field_ttd_generated_og_desc',
      ];

      foreach ($clear_fields as $field_name) {
        if ($node->hasField($field_name)) {
          $node->set($field_name, NULL);
        }
      }

      $node->save();

      return new JsonResponse([
        'success' => TRUE,
        'data' => ['message' => 'Meta cleared'],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

}
