<?php

namespace Drupal\ttd_topics;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Service for generating schema.org metadata for TopicalBoost.
 */
class SchemaGenerator {

  /**
   * Node image/media fields that can provide a fallback Article image.
   */
  protected const SOURCE_IMAGE_FIELDS = [
    'field_image',
    'field_featured_image',
    'field_article_image',
    'field_article_banner',
    'field_hero_image',
    'field_media_image',
  ];

  /**
   * WordPress-compatible Article schema image formats.
   */
  protected const SCHEMA_IMAGE_SIZES = [
    '1x1' => ['field' => 'field_ttd_schema_1x1', 'width' => 675, 'height' => 675],
    '4x3' => ['field' => 'field_ttd_schema_4x3', 'width' => 900, 'height' => 675],
    '16x9' => ['field' => 'field_ttd_schema_16x9', 'width' => 1200, 'height' => 675],
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Constructs a new SchemaGenerator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   */
  public function __construct(
    Connection $database,
    AliasManagerInterface $alias_manager,
  ) {
    $this->database = $database;
    $this->aliasManager = $alias_manager;
  }

  /**
   * Format a Google Knowledge Graph / Freebase identifier for schema @id.
   */
  protected function formatKgId(?string $identifier): ?string {
    $identifier = is_string($identifier) ? trim($identifier) : '';
    if ($identifier === '') {
      return NULL;
    }

    $identifier = preg_replace('/^kg:/i', '', $identifier);
    if (preg_match('#^/?(m|g)/#i', $identifier)) {
      return 'kg:/' . ltrim($identifier, '/');
    }

    return NULL;
  }

  /**
   * Gets schema.org metadata for a node's TopicalBoost topics.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   The schema.org metadata array.
   */
  public function getNodeTopicsSchema($nid) {
    $config = \Drupal::config('ttd_topics.settings');
    $min_display_count = (int) ($config->get('post_topic_minimum_display_count') ?? 0);

    // Get site configuration - all dynamic.
    $site_config = \Drupal::config('system.site');
    $site_name = $site_config->get('name') ?: 'Your Organization';
    $site_slogan = $site_config->get('slogan') ?: 'Your organization\'s mission';

    // Get base URL dynamically.
    $base_url = \Drupal::request()->getSchemeAndHttpHost();

    // Determine logo URL with priority system.
    $logo_url = $this->getLogoUrl($base_url, $config);

    // Build sameAs array from admin configuration.
    $same_as = [];
    if ($wikipedia_url = $config->get('organization_wikipedia_url')) {
      $same_as[] = $wikipedia_url;
    }
    if ($facebook_url = $config->get('organization_facebook_url')) {
      $same_as[] = $facebook_url;
    }
    if ($twitter_url = $config->get('organization_twitter_url')) {
      $same_as[] = $twitter_url;
    }
    if ($linkedin_url = $config->get('organization_linkedin_url')) {
      $same_as[] = $linkedin_url;
    }
    if ($youtube_url = $config->get('organization_youtube_url')) {
      $same_as[] = $youtube_url;
    }

    // Create the @graph structure.
    $data = [
      '@context' => 'https://schema.org',
      '@graph' => [],
    ];

    // Add Organization schema - all dynamic.
    $organization = [
      '@type' => 'Organization',
      '@id' => $base_url . '/#organization',
      'name' => $site_name,
      'url' => $base_url,
      'logo' => [
        '@type' => 'ImageObject',
        'inLanguage' => 'en-US',
        '@id' => $logo_url . '#logo',
        'url' => $logo_url,
        'contentUrl' => $logo_url,
        'caption' => $site_name,
      ],
      'image' => [
        '@id' => $logo_url . '#logo',
      ],
    ];

    // Only add sameAs if there are configured social media links.
    if (!empty($same_as)) {
      $organization['sameAs'] = $same_as;
    }

    $data['@graph'][] = $organization;

    // Add WebSite schema - all dynamic.
    $website = [
      '@type' => 'WebSite',
      '@id' => $base_url . '/#website',
      'url' => $base_url,
    // Extract hostname dynamically.
      'name' => parse_url($base_url, PHP_URL_HOST),
      'publisher' => [
        '@id' => $base_url . '/#organization',
      ],
      'inLanguage' => 'en-US',
    ];

    // Only add description if slogan exists.
    if (!empty($site_slogan)) {
      $website['description'] = $site_slogan;
    }

    $data['@graph'][] = $website;

    // Add primary ImageObject schema - dynamic.
    $primary_image = [
      '@type' => 'ImageObject',
      'inLanguage' => 'en-US',
      '@id' => $logo_url . '#primaryimage',
      'url' => $logo_url,
      'contentUrl' => $logo_url,
    ];

    $data['@graph'][] = $primary_image;

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);

    // Create Article schema with proper schema.org properties:
    // - internal "mainEntity" and "about" tiers -> schema.org "about".
    // - "mentions": low-salience and untiered topics (what the article references).
    $article = $node instanceof NodeInterface
      ? $this->buildNodeArticleSchema($node, $base_url)
      : ['@type' => 'Article'];

    $schema_topics = $node instanceof NodeInterface
      ? $this->getSchemaTopicsForNode($node, $min_display_count)
      : ['mainEntity' => [], 'about' => [], 'mentions' => []];
    $schema_topic_ttd_ids = $this->collectTopicTtdIds($schema_topics);
    $entity_data_by_id = $this->getEntitiesDataBatch($schema_topic_ttd_ids);
    $schema_types_by_id = $this->getEntitySchemaTypesBatch($schema_topic_ttd_ids);

    $main_entity_items = $this->formatTopicsForSchema($schema_topics['mainEntity'], $base_url, $entity_data_by_id, $schema_types_by_id);
    $about_items = $this->formatTopicsForSchema($schema_topics['about'], $base_url, $entity_data_by_id, $schema_types_by_id);
    $about_items = array_merge($main_entity_items, $about_items);
    if (!empty($about_items)) {
      $article['about'] = $about_items;
    }

    $mentions_items = $this->formatTopicsForSchema($schema_topics['mentions'], $base_url, $entity_data_by_id, $schema_types_by_id);
    if (!empty($mentions_items)) {
      $article['mentions'] = $mentions_items;
    }

    // Add custom schema images to Article if available.
    $schema_images = $this->getSchemaImages($nid, $base_url);
    if (!empty($schema_images)) {
      $article['image'] = $schema_images;
    }

    if ($node) {
      $authors = $this->getCustomAuthorSchema($node, $base_url, $config);
      if (!empty($authors)) {
        $article['author'] = $authors;
      }
    }

    // Only add the Article schema if it has TopicalBoost-enhanced data.
    if (!empty($article['about']) || !empty($article['mentions']) || !empty($article['image']) || !empty($article['author'])) {
      $data['@graph'][] = $article;
    }

    return $data;
  }

  /**
   * Builds the page-level article schema fields to match the WordPress output.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being rendered.
   * @param string $base_url
   *   The site base URL.
   *
   * @return array
   *   Base Article/NewsArticle schema.
   */
  protected function buildNodeArticleSchema(NodeInterface $node, string $base_url): array {
    try {
      $node_url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Exception $e) {
      $node_url = $base_url . '/node/' . $node->id();
    }

    $article = [
      '@type' => 'NewsArticle',
      '@id' => $node_url . '#article',
      'isPartOf' => [
        '@id' => $node_url,
      ],
      'headline' => $node->label(),
      'datePublished' => date('c', $node->getCreatedTime()),
      'dateModified' => date('c', $node->getChangedTime()),
      'mainEntityOfPage' => [
        '@id' => $node_url,
      ],
      'publisher' => [
        '@id' => $base_url . '/#organization',
      ],
      'inLanguage' => 'en-US',
    ];

    $word_count = $this->getNodeWordCount($node);
    if ($word_count > 0) {
      $article['wordCount'] = $word_count;
    }

    return $article;
  }

  /**
   * Gets an approximate frontend word count for article schema.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node being rendered.
   *
   * @return int
   *   Word count.
   */
  protected function getNodeWordCount(NodeInterface $node): int {
    $text = '';

    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = $node->get('body')->first();
      $text .= ' ' . (string) ($body->value ?? '');
      $text .= ' ' . (string) ($body->summary ?? '');
    }

    $text = trim(strip_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($text === '') {
      return 0;
    }

    return str_word_count($text);
  }

  /**
   * Gets schema.org metadata for a TopicalBoost topic archive page.
   */
  public function getTopicArchiveSchema(TermInterface $term): array {
    $base_url = \Drupal::request()->getSchemeAndHttpHost();
    $term_url = $term->toUrl('canonical', ['absolute' => TRUE])->toString();
    $main_entity = [
      '@type' => 'Thing',
      '@id' => $term_url . '#mainEntity',
      'name' => $term->label(),
      'url' => $term_url,
    ];

    $ttd_id = $term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()
      ? $term->get('field_ttd_id')->value
      : NULL;
    if ($ttd_id) {
      $entity = $this->getEntityData($ttd_id);
      if ($entity) {
        $schema_types = $this->getEntitySchemaTypes($ttd_id) ?: ['Thing'];
        $config = \Drupal::config('ttd_topics.settings');
        if ($config->get('disable_event_temporal_properties')) {
          $schema_types = $this->replaceEventSchemaType($schema_types);
        }
        $main_entity['@type'] = count($schema_types) > 1 ? $schema_types : $schema_types[0];
        $main_entity = $this->formatEntityData($main_entity, $entity, $schema_types[0]);
      }
    }

    return [
      '@context' => 'https://schema.org',
      '@graph' => [
        [
          '@type' => 'CollectionPage',
          '@id' => $term_url . '#webpage',
          'url' => $term_url,
          'name' => $term->label(),
          'isPartOf' => [
            '@id' => $base_url . '/#website',
          ],
          'mainEntity' => $main_entity,
        ],
      ],
    ];
  }

  /**
   * Builds custom author Person schema for a node.
   */
  protected function getCustomAuthorSchema(NodeInterface $node, string $base_url, $config): array {
    if (!$config->get('author_manager_enabled') || !$config->get('custom_author_schema_enabled')) {
      return [];
    }

    $field_name = $config->get('author_field_name') ?: 'uid';
    $entities = [];
    if ($field_name === 'uid') {
      $entities = [$node->getOwner()];
    }
    elseif ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
      $entities = $node->get($field_name)->referencedEntities();
    }

    $authors = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof EntityInterface) {
        continue;
      }

      $name = $this->getMappedAuthorValue($entity, $config->get('author_name_field') ?: 'display_name');
      if ($name === '') {
        $name = $entity->label();
      }
      if ($name === '') {
        continue;
      }

      $author = [
        '@type' => 'Person',
        '@id' => $base_url . '#/schema/Person/' . $entity->getEntityTypeId() . '-' . $entity->id(),
        'name' => $name,
      ];

      if ($url = $this->getEntityUrl($entity)) {
        $author['url'] = $url;
      }
      if ($image = $this->getMappedAuthorImage($entity, $config->get('author_image_field') ?: '')) {
        $author['image'] = $image;
      }
      if ($description = $this->getMappedAuthorValue($entity, $config->get('author_description_field') ?: '')) {
        $author['description'] = $description;
      }

      $authors[] = array_filter($author);
    }

    return $authors;
  }

  /**
   * Gets a mapped scalar author value from an entity.
   */
  protected function getMappedAuthorValue(EntityInterface $entity, string $field_name): string {
    if ($field_name === '') {
      return '';
    }
    if ($field_name === 'display_name' || $field_name === 'title' || $field_name === 'name' || $field_name === 'label') {
      return trim((string) $entity->label());
    }
    if ($field_name === 'account_name' && method_exists($entity, 'getAccountName')) {
      return trim((string) $entity->getAccountName());
    }
    if ($field_name === 'mail' && method_exists($entity, 'getEmail')) {
      return trim((string) $entity->getEmail());
    }
    if (!method_exists($entity, 'hasField') || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $item = $entity->get($field_name)->first();
    $value = $item->value ?? $item->summary ?? '';
    return trim(strip_tags((string) $value));
  }

  /**
   * Gets a mapped author image URL from an entity image field.
   */
  protected function getMappedAuthorImage(EntityInterface $entity, string $field_name): string {
    if ($field_name === '' || !method_exists($entity, 'hasField') || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $file = $entity->get($field_name)->entity;
    if (!$file || !method_exists($file, 'getFileUri')) {
      return '';
    }

    return \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
  }

  /**
   * Gets an absolute canonical URL for an entity when available.
   */
  protected function getEntityUrl(EntityInterface $entity): string {
    try {
      if ($entity->hasLinkTemplate('canonical')) {
        return $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
    }
    catch (\Exception $e) {
      return '';
    }
    return '';
  }

  /**
   * Gets rejected topics for a node.
   */
  protected function getRejectedTopics($nid) {
    // First get the rejected term IDs.
    $rejected_tids = [];
    $result = $this->database->select('node__field_ttd_rejected_topics', 'rt')
      ->fields('rt', ['field_ttd_rejected_topics_target_id'])
      ->condition('entity_id', $nid)
      ->execute();
    foreach ($result as $record) {
      $rejected_tids[] = $record->field_ttd_rejected_topics_target_id;
    }

    // Then get the TTD IDs for those terms.
    if (!empty($rejected_tids)) {
          $result = $this->database->select('taxonomy_term__field_ttd_id', 'ttd')
      ->fields('ttd', ['field_ttd_id_value'])
        ->condition('entity_id', $rejected_tids, 'IN')
        ->execute();
      return $result->fetchCol();
    }

    return [];
  }

  /**
   * Gets valid schema topics for a node, grouped by WordPress-compatible tier.
   *
   * WordPress treats untiered topics as mentions and lets manual, forced, and
   * high-salience topics bypass the display threshold. Schema output should
   * follow the same rules so visible topics and structured data stay aligned.
   */
  protected function getSchemaTopicsForNode(NodeInterface $node, int $min_display_count): array {
    $grouped = [
      'mainEntity' => [],
      'about' => [],
      'mentions' => [],
    ];

    if (!$node->hasField('field_ttd_topics') || $node->get('field_ttd_topics')->isEmpty()) {
      return $grouped;
    }

    if (function_exists('ttd_topics_get_filtered_topics_for_node')) {
      $filtered_topics = \ttd_topics_get_filtered_topics_for_node($node);
      $term_ids = [];
      foreach ($filtered_topics as $topic_data) {
        if (!empty($topic_data['term']) && $topic_data['term'] instanceof TermInterface) {
          $term_ids[] = (int) $topic_data['term']->id();
        }
      }
      $term_aliases = $this->getTermAliasesBatch(array_values(array_unique($term_ids)));

      foreach ($filtered_topics as $topic_data) {
        $term = $topic_data['term'] ?? NULL;
        if (!$term instanceof TermInterface) {
          continue;
        }

        $term_id = (int) $term->id();
        $ttd_id = $term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()
          ? (int) $term->get('field_ttd_id')->value
          : 0;
        if (!$ttd_id) {
          continue;
        }

        $tier = $topic_data['salience_tier'] ?? ($topic_data['salience_category'] ?? 'mentions');
        if (!in_array($tier, ['mainEntity', 'about', 'mentions'], TRUE)) {
          $tier = 'mentions';
        }

        $grouped[$tier][] = (object) [
          'tid' => $term_id,
          'name' => $term->label(),
          'field_ttd_id_value' => $ttd_id,
          'field_hide_value' => 0,
          'alias' => $term_aliases[$term_id] ?? '/taxonomy/term/' . $term_id,
          'post_count' => (int) ($topic_data['count'] ?? 0),
        ];
      }

      if (count($grouped['mainEntity']) > 1) {
        $extra_main_entities = array_splice($grouped['mainEntity'], 1);
        $grouped['about'] = array_merge($grouped['about'], $extra_main_entities);
      }

      return $grouped;
    }

    $topics = $node->get('field_ttd_topics')->referencedEntities();
    $term_ids = array_map(static fn($term) => (int) $term->id(), $topics);
    $term_counts = function_exists('ttd_topics_get_topic_node_counts')
      ? \ttd_topics_get_topic_node_counts($term_ids)
      : [];
    $term_aliases = $this->getTermAliasesBatch($term_ids);
    $salience_data = function_exists('ttd_get_node_salience_data')
      ? \ttd_get_node_salience_data($node->id(), $node)
      : [];
    $manual_term_ids = $node->hasField('field_manual_topics')
      ? array_map('intval', array_column($node->get('field_manual_topics')->getValue(), 'target_id'))
      : [];
    $rejected_term_ids = $node->hasField('field_ttd_rejected_topics')
      ? array_map('intval', array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id'))
      : [];

    foreach ($topics as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      $term_id = (int) $term->id();
      $is_manual = in_array($term_id, $manual_term_ids, TRUE);
      $is_hidden = $term->hasField('field_hide') && !$term->get('field_hide')->isEmpty() && (bool) $term->get('field_hide')->value;
      if ($is_hidden || (!$is_manual && in_array($term_id, $rejected_term_ids, TRUE))) {
        continue;
      }

      $ttd_id = $term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()
        ? (int) $term->get('field_ttd_id')->value
        : 0;
      if (!$ttd_id) {
        continue;
      }

      $count = (int) ($term_counts[$term_id] ?? 0);
      $tier = $salience_data[$ttd_id]['salience_category'] ?? 'mentions';
      if (!in_array($tier, ['mainEntity', 'about', 'mentions'], TRUE)) {
        $tier = 'mentions';
      }

      $is_forced = $term->hasField('field_force_show') && !$term->get('field_force_show')->isEmpty() && (bool) $term->get('field_force_show')->value;
      $is_high_salience = in_array($tier, ['mainEntity', 'about'], TRUE);
      $is_drag_promoted = !empty($salience_data[$ttd_id]['is_user_override']) && $is_high_salience;
      if (!$is_manual && !$is_forced && !$is_drag_promoted && function_exists('ttd_topics_term_should_curate') && !\ttd_topics_term_should_curate($term)) {
        continue;
      }

      if (!$is_manual && !$is_forced && !$is_high_salience && $count < $min_display_count) {
        continue;
      }

      $alias = $term_aliases[$term_id] ?? '/taxonomy/term/' . $term_id;
      $topic = (object) [
        'tid' => $term_id,
        'name' => $term->label(),
        'field_ttd_id_value' => $ttd_id,
        'field_hide_value' => 0,
        'alias' => $alias,
        'post_count' => $count,
      ];

      $grouped[$tier][] = $topic;
    }

    if (count($grouped['mainEntity']) > 1) {
      $extra_main_entities = array_splice($grouped['mainEntity'], 1);
      $grouped['about'] = array_merge($grouped['about'], $extra_main_entities);
    }

    return $grouped;
  }

  /**
   * Gets topics for a node filtered by salience category.
   *
   * @param int $nid
   *   The node ID.
   * @param string $salience_category
   *   The salience category to filter by ('about' or 'mentions').
   * @param array $rejected_ttd_ids
   *   Array of rejected TTD IDs to exclude.
   *
   * @return array
   *   Array of topic objects.
   */
  protected function getTopicsBySalience($nid, $salience_category, array $rejected_ttd_ids = []) {
    $query = $this->database->select('node__field_ttd_topics', 'ti');
    $query->join('taxonomy_term_field_data', 't', 't.tid = ti.field_ttd_topics_target_id');
    $query->leftJoin('taxonomy_term__field_ttd_id', 'ttd', 'ttd.entity_id = t.tid');
    $query->leftJoin('taxonomy_term__field_hide', 'h', 'h.entity_id = t.tid');
    $query->leftJoin('path_alias', 'pa', "pa.path = CONCAT('/taxonomy/term/', t.tid) AND pa.status = 1");
    if ($this->database->schema()->tableExists('ttd_entity_aliases')) {
      $query->leftJoin('ttd_entity_aliases', 'ea', 'ea.alias_entity_id = ttd.field_ttd_id_value');
    }

    // Join with ttd_entity_post_ids to filter by salience category.
    $query->join('ttd_entity_post_ids', 'ep', 'ep.entity_id = ttd.field_ttd_id_value AND ep.post_id = ti.entity_id');

    // Add subquery to count posts per term.
    $subquery = $this->database->select('node__field_ttd_topics', 'ti2')
      ->fields('ti2', ['field_ttd_topics_target_id'])
      ->groupBy('field_ttd_topics_target_id');
    $subquery->addExpression('COUNT(DISTINCT ti2.entity_id)', 'post_count');

    $query->leftJoin($subquery, 'pc', 'pc.field_ttd_topics_target_id = t.tid');

    $query->fields('t', ['tid', 'name'])
      ->fields('ttd', ['field_ttd_id_value'])
      ->fields('h', ['field_hide_value'])
      ->fields('pa', ['alias'])
      ->fields('pc', ['post_count'])
      ->condition('ti.entity_id', $nid)
      ->condition('t.vid', 'ttd_topics')
      ->condition('ti.deleted', 0)
      ->condition('ep.salience_category', $salience_category);

    if ($this->database->schema()->tableExists('ttd_entity_aliases')) {
      $query->isNull('ea.alias_entity_id');
    }

    // Exclude rejected topics.
    if (!empty($rejected_ttd_ids)) {
      $rejected_ttd_ids = array_values(array_unique(array_map('strval', $rejected_ttd_ids)));
      $query->condition('ttd.field_ttd_id_value', $rejected_ttd_ids, 'NOT IN');
    }

    $query->orderBy('pc.post_count', 'DESC');

    return $query->execute()->fetchAll();
  }

  /**
   * Formats topics array into schema.org items.
   *
   * @param array $topics
   *   Array of topic objects from getTopicsBySalience().
   * @param string $base_url
   *   The base URL for generating topic URLs.
   *
   * @return array
   *   Array of formatted schema.org items.
   */
  protected function formatTopicsForSchema(array $topics, $base_url, ?array $entity_data_by_id = NULL, ?array $schema_types_by_id = NULL) {
    $items = [];

    foreach ($topics as $topic) {
      // Skip hidden topics.
      if (!empty($topic->field_hide_value)) {
        continue;
      }

      $ttd_id = (int) $topic->field_ttd_id_value;
      $entity = $entity_data_by_id !== NULL
        ? ($entity_data_by_id[$ttd_id] ?? NULL)
        : $this->getEntityData($ttd_id);
      if (!$entity) {
        continue;
      }

      $schema_types = $schema_types_by_id !== NULL
        ? ($schema_types_by_id[$ttd_id] ?? [])
        : $this->getEntitySchemaTypes($ttd_id);
      if (empty($schema_types)) {
        $schema_types = ['Thing'];
      }

      // Check if events should be output as Things.
      $config = \Drupal::config('ttd_topics.settings');
      if ($config->get('disable_event_temporal_properties')) {
        $schema_types = $this->replaceEventSchemaType($schema_types);
      }

      $output_data = [
        '@type' => count($schema_types) > 1 ? $schema_types : $schema_types[0],
        'name' => $topic->name,
        'url' => !empty($entity['official_website']) ? $entity['official_website'] : $base_url . $topic->alias,
      ];

      if (!empty($entity['mid'])) {
        $kg_id = $this->formatKgId($entity['mid']);
        if ($kg_id) {
          $output_data['@id'] = $kg_id;
        }
      }
      elseif (!empty($entity['wb_qid'])) {
        $output_data['@id'] = 'https://www.wikidata.org/wiki/' . $entity['wb_qid'];
      }

      // Format the entity data (skip temporal properties for demoted Events).
      $effective_type = $schema_types[0];
      $output_data = $this->formatEntityData($output_data, $entity, $effective_type);

      $items[] = $output_data;
    }

    return $items;
  }

  /**
   * Replaces Event with Thing without emitting duplicate schema types.
   */
  protected function replaceEventSchemaType(array $schema_types): array {
    $schema_types = array_map(
      static fn($type) => $type === 'Event' ? 'Thing' : $type,
      $schema_types,
    );

    return array_values(array_unique($schema_types));
  }

  /**
   * Collects unique TTD entity IDs from grouped schema topics.
   */
  protected function collectTopicTtdIds(array $grouped_topics): array {
    $ids = [];
    foreach ($grouped_topics as $topics) {
      foreach ($topics as $topic) {
        if (!empty($topic->field_ttd_id_value)) {
          $ids[] = (int) $topic->field_ttd_id_value;
        }
      }
    }

    return array_values(array_unique($ids));
  }

  /**
   * Loads taxonomy term aliases for schema topics in one query.
   */
  protected function getTermAliasesBatch(array $term_ids): array {
    if (empty($term_ids)) {
      return [];
    }

    $paths_by_tid = [];
    foreach (array_unique(array_map('intval', $term_ids)) as $term_id) {
      $paths_by_tid[$term_id] = '/taxonomy/term/' . $term_id;
    }

    $aliases = [];
    foreach ($paths_by_tid as $term_id => $path) {
      $aliases[$term_id] = $path;
    }

    if (!$this->database->schema()->tableExists('path_alias')) {
      return $aliases;
    }

    $query = $this->database->select('path_alias', 'pa')
      ->fields('pa', ['path', 'alias'])
      ->condition('path', array_values($paths_by_tid), 'IN')
      ->condition('status', 1);

    $path_to_tid = array_flip($paths_by_tid);
    foreach ($query->execute() as $record) {
      if (isset($path_to_tid[$record->path])) {
        $aliases[(int) $path_to_tid[$record->path]] = $record->alias;
      }
    }

    return $aliases;
  }

  /**
   * Loads entity rows for schema topics in one query.
   */
  protected function getEntitiesDataBatch(array $ttd_ids): array {
    if (empty($ttd_ids)) {
      return [];
    }

    $entities = [];
    $query = $this->database->select('ttd_entities', 'e')
      ->fields('e')
      ->condition('ttd_id', $ttd_ids, 'IN');

    foreach ($query->execute() as $record) {
      $entities[(int) $record->ttd_id] = (array) $record;
    }

    return $entities;
  }

  /**
   * Loads schema type names for schema topics in one query.
   */
  protected function getEntitySchemaTypesBatch(array $ttd_ids): array {
    if (empty($ttd_ids)) {
      return [];
    }

    $types = [];
    $query = $this->database->select('ttd_entity_schema_types', 'est');
    $query->join('ttd_schema_types', 'st', 'st.ttd_id = est.schema_type_id');
    $query->fields('est', ['entity_id'])
      ->fields('st', ['name'])
      ->condition('est.entity_id', $ttd_ids, 'IN');

    foreach ($query->execute() as $record) {
      $types[(int) $record->entity_id][] = $record->name;
    }

    return $types;
  }

  /**
   * Gets entity data from ttd_entities table.
   */
  protected function getEntityData($ttd_id) {
    $query = $this->database->select('ttd_entities', 'e')
      ->fields('e')
      ->condition('ttd_id', $ttd_id);

    return $query->execute()->fetchAssoc();
  }

  /**
   * Gets schema types for an entity.
   */
  protected function getEntitySchemaTypes($ttd_id) {
    $query = $this->database->select('ttd_entity_schema_types', 'est');
    $query->join('ttd_schema_types', 'st', 'st.ttd_id = est.schema_type_id');
    $query->fields('st', ['name'])
      ->condition('est.entity_id', $ttd_id);

    $types = [];
    $result = $query->execute();
    foreach ($result as $record) {
      $types[] = $record->name;
    }

    return $types;
  }

  /**
   * Formats entity data into schema.org structure.
   */
  protected function formatEntityData($output_data, $entity, $schema_type) {
    // Name - handle all possible fallbacks in correct order.
    if (!empty($entity['name'])) {
      $output_data['name'] = $entity['name'];
    }
    elseif (!empty($entity['nl_name'])) {
      $output_data['name'] = $entity['nl_name'];
    }
    elseif (!empty($entity['kg_name'])) {
      $output_data['name'] = $entity['kg_name'];
    }
    elseif (!empty($entity['wb_name']) && $entity['wb_name'] !== 'No Label Defined') {
      $output_data['name'] = $entity['wb_name'];
    }

    // Description.
    if (!empty($entity['kg_description'])) {
      $output_data['description'] = $entity['kg_description'];
    }
    elseif (isset($entity['wb_description'])) {
      $output_data['description'] = $entity['wb_description'];
    }

    // URL.
    if (isset($entity['official_website'])) {
      $output_data['url'] = $entity['official_website'];
    }

    // Image.
    if (isset($entity['kg_image'])) {
      $output_data['image'] = $entity['kg_image'];
    }
    elseif (isset($entity['wb_image'])) {
      $output_data['image'] = $entity['wb_image'];
    }
    elseif (isset($entity['wb_logo_image'])) {
      $output_data['image'] = $entity['wb_logo_image'];
    }

    // Add schema.org properties based on schema type.
    $this->addSchemaTypeProperties($output_data, $entity, $schema_type);

    // Add sameAs links.
    $same_as_links = $this->getSameAsLinks($entity);
    if (!empty($same_as_links)) {
      $output_data['sameAs'] = $same_as_links;
    }

    return $output_data;
  }

  /**
   * Adds schema type specific properties.
   */
  protected function addSchemaTypeProperties(&$output_data, $entity, $schema_type) {
    // Date published.
    if ($this->hasDatePublished($schema_type) && isset($entity['publication_date'])) {
      $output_data['datePublished'] = $this->formatDate($entity['publication_date']);
    }

    // Duration.
    if ($this->hasDuration($schema_type) && isset($entity['duration'])) {
      $output_data['duration'] = 'PT' . str_replace('+', '', $entity['duration']) . 'M';
    }

    // Location.
    if ($this->hasLocation($schema_type) && isset($entity['country'])) {
      $output_data['location'] = [
        '@type' => 'Country',
        'name' => $entity['country'],
      ];
    }

    // Add missing properties from WordPress version:
    // Start time.
    if ($this->hasStartTime($schema_type) && isset($entity['start_time'])) {
      $output_data['startDate'] = $this->formatDate($entity['start_time']);
    }

    // End time.
    if ($this->hasEndTime($schema_type) && isset($entity['end_time'])) {
      $output_data['endDate'] = $this->formatDate($entity['end_time']);
    }

    // Founding date.
    if ($this->hasFoundingDate($schema_type) && isset($entity['inception'])) {
      $output_data['foundingDate'] = $this->formatDate($entity['inception']);
    }

    // Birth date.
    if ($this->hasBirthDate($schema_type) && isset($entity['date_of_birth'])) {
      $output_data['birthDate'] = $this->formatDate($entity['date_of_birth']);
    }

    // Content rating.
    if ($this->hasContentRating($schema_type) && isset($entity['mpa_film_rating'])) {
      $output_data['contentRating'] = $entity['mpa_film_rating'];
    }

    // Is part of.
    if ($this->hasIsPartOf($schema_type)) {
      $output_data['isPartOf'] = [];
      if (isset($entity['series'])) {
        $output_data['isPartOf'][] = [
          '@type' => 'CreativeWorkSeries',
          'name' => $entity['series'],
        ];
      }
      if (isset($entity['season'])) {
        $output_data['isPartOf'][] = [
          '@type' => 'CreativeWorkSeason',
          'name' => $entity['season'],
        ];
      }
    }

    // Genre.
    if ($this->hasGenre($schema_type) && isset($entity['genre'])) {
      $output_data['genre'] = explode(';', $entity['genre']);
    }

    // Producer.
    if ($this->hasProducer($schema_type) && isset($entity['producer'])) {
      $output_data['producer'] = array_map(function ($producer) {
        return [
          '@type' => 'Person',
          'name' => $producer,
        ];
      }, explode(';', $entity['producer']));
    }

    // Director.
    if ($this->hasDirector($schema_type) && isset($entity['director'])) {
      $output_data['director'] = array_map(function ($director) {
        return [
          '@type' => 'Person',
          'name' => $director,
        ];
      }, explode(';', $entity['director']));
    }

    // Author.
    if ($this->hasAuthor($schema_type) && isset($entity['screenwriter'])) {
      $output_data['author'] = array_map(function ($author) {
        return [
          '@type' => 'Person',
          'name' => $author,
        ];
      }, explode(';', $entity['screenwriter']));
    }

    // Actor.
    if ($this->hasActor($schema_type) && isset($entity['cast_member'])) {
      $output_data['actor'] = array_map(function ($actor) {
        return [
          '@type' => 'Person',
          'name' => $actor,
        ];
      }, explode(';', $entity['cast_member']));
    }

    // Add containedInPlace.
    if ($this->hasContainedInPlace($schema_type) && isset($entity['country'])) {
      $output_data['containedInPlace'] = [
        '@type' => 'Country',
        'name' => $entity['country'],
      ];
    }

    // Add locationCreated.
    if ($this->hasLocationCreated($schema_type) && isset($entity['country'])) {
      $output_data['locationCreated'] = [
        '@type' => 'Country',
        'name' => $entity['country'],
      ];
    }

    // Characters.
    if ($this->hasCharacters($schema_type) && isset($entity['characters'])) {
      $output_data['characters'] = array_map(function ($character) {
        return [
          '@type' => 'Person',
          'name' => $character,
        ];
      }, explode(';', $entity['characters']));
    }

    // Composer.
    if ($this->hasComposer($schema_type) && isset($entity['composer'])) {
      $output_data['composer'] = array_map(function ($composer) {
        return [
          '@type' => 'Person',
          'name' => $composer,
        ];
      }, explode(';', $entity['composer']));
    }

    // Add creator.
    if ($this->hasCreator($schema_type) && isset($entity['creator'])) {
      $output_data['creator'] = array_map(function ($creator) {
        return [
          '@type' => 'Person',
          'name' => $creator,
        ];
      }, explode(';', $entity['creator']));
    }
  }

  /**
   * Gets sameAs links for entity.
   */
  protected function getSameAsLinks($entity) {
    $links = [];

    $this->addEntityIdentifierLinks($links, $entity);
    foreach ($this->getAliasEntitiesForSchema($entity) as $alias_entity) {
      $this->addEntityIdentifierLinks($links, $alias_entity);
    }

    // Add missing links.
    if (!empty($entity['rotten_tomatoes_id'])) {
      $links[] = 'https://www.rottentomatoes.com/' . $entity['rotten_tomatoes_id'];
    }
    if (!empty($entity['wikipedia_url'])) {
      $links[] = $entity['wikipedia_url'];
    }
    if (!empty($entity['twitter_username'])) {
      $links[] = 'https://twitter.com/' . ltrim($entity['twitter_username'], '@');
    }
    if (!empty($entity['facebook_id'])) {
      $links[] = 'https://www.facebook.com/' . $entity['facebook_id'];
    }
    if (!empty($entity['imdb_id'])) {
      $links[] = 'https://www.imdb.com/title/' . $entity['imdb_id'];
    }
    if (!empty($entity['goodreads_work_id'])) {
      $links[] = 'https://www.goodreads.com/work/show/' . $entity['goodreads_work_id'];
    }
    if (!empty($entity['allmusic_album_id'])) {
      $links[] = 'https://www.allmusic.com/album/' . $entity['allmusic_album_id'];
    }
    if (!empty($entity['spotify_album_id'])) {
      $links[] = 'https://open.spotify.com/album/' . $entity['spotify_album_id'];
    }

    return array_values(array_unique(array_filter($links)));
  }

  /**
   * Adds KG/Freebase/Wikidata links for an entity row to a sameAs list.
   */
  protected function addEntityIdentifierLinks(array &$links, array $entity): void {
    if (!empty($entity['mid'])) {
      $links[] = 'https://www.google.com/search?kgmid=' . $entity['mid'];
    }
    if (!empty($entity['freebase_id'])) {
      $links[] = 'https://www.google.com/search?kgmid=' . $entity['freebase_id'];
    }
    if (!empty($entity['google_knowledge_graph_id'])) {
      $links[] = 'https://www.google.com/search?kgmid=' . $entity['google_knowledge_graph_id'];
    }
    if (!empty($entity['wb_qid'])) {
      $links[] = 'https://www.wikidata.org/wiki/' . $entity['wb_qid'];
    }
  }

  /**
   * Loads local alias entity rows so canonical schema can preserve duplicate IDs.
   */
  protected function getAliasEntitiesForSchema(array $entity): array {
    if (!$this->database->schema()->tableExists('ttd_entity_aliases')) {
      return [];
    }

    $canonical_id = (int) ($entity['ttd_id'] ?? $entity['id'] ?? 0);
    if ($canonical_id <= 0) {
      return [];
    }

    $query = $this->database->select('ttd_entity_aliases', 'ea');
    $query->join('ttd_entities', 'e', 'e.ttd_id = ea.alias_entity_id');
    $query->fields('e')
      ->condition('ea.canonical_entity_id', $canonical_id);

    $aliases = [];
    foreach ($query->execute() as $record) {
      $aliases[] = (array) $record;
    }

    return $aliases;
  }

  /**
   * Helper function to check if schema type has datePublished.
   */
  protected function hasDatePublished($schema_type) {
    return in_array($schema_type, [
      'Legislation',
      'Movie',
      'TVSeries',
      'Book',
      'MusicGroup',
    ]);
  }

  /**
   * Helper function to check if schema type has duration.
   */
  protected function hasDuration($schema_type) {
    return in_array($schema_type, ['Event', 'Movie']);
  }

  /**
   * Helper function to check if schema type has location.
   */
  protected function hasLocation($schema_type) {
    return in_array($schema_type, [
      'Event',
      'Organization',
      'EducationalOrganization',
      'NGO',
      'Corporation',
      'NewsMediaOrganization',
      'GovernmentOrganization',
      'SportsTeam',
    ]);
  }

  /**
   * Helper function to check if schema type has start time.
   */
  protected function hasStartTime($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries']);
  }

  /**
   * Helper function to check if schema type has end time.
   */
  protected function hasEndTime($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries']);
  }

  /**
   * Helper function to check if schema type has founding date.
   */
  protected function hasFoundingDate($schema_type) {
    return in_array($schema_type, [
      'Organization', 'EducationalOrganization', 'NGO', 'Corporation',
      'NewsMediaOrganization', 'GovernmentOrganization', 'SportsTeam',
    ]);
  }

  /**
   * Helper function to check if schema type has birth date.
   */
  protected function hasBirthDate($schema_type) {
    return $schema_type === 'Person';
  }

  /**
   * Helper function to check if schema type has content rating.
   */
  protected function hasContentRating($schema_type) {
    return in_array($schema_type, ['Book', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has is part of.
   */
  protected function hasIsPartOf($schema_type) {
    return in_array($schema_type, [
      'Book',
      'TVSeries',
      'Legislation',
      'Movie',
      'CreativeWorkSeries',
      'CreativeWorkSeason',
    ]);
  }

  /**
   * Helper function to check if schema type has genre.
   */
  protected function hasGenre($schema_type) {
    return in_array($schema_type, [
      'MusicGroup',
      'TVSeries',
      'Book',
      'Legislation',
      'Movie',
      'CreativeWork',
    ]);
  }

  /**
   * Helper function to check if schema type has producer.
   */
  protected function hasProducer($schema_type) {
    return in_array($schema_type, ['Book', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has director.
   */
  protected function hasDirector($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has author.
   */
  protected function hasAuthor($schema_type) {
    return in_array($schema_type, ['Book', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has actor.
   */
  protected function hasActor($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has containedInPlace.
   */
  protected function hasContainedInPlace($schema_type) {
    return in_array($schema_type, ['City', 'State', 'Place']);
  }

  /**
   * Helper function to check if schema type has locationCreated.
   */
  protected function hasLocationCreated($schema_type) {
    return in_array($schema_type, ['Legislation', 'MusicGroup', 'TVSeries', 'Book']);
  }

  /**
   * Helper function to check if schema type has characters.
   */
  protected function hasCharacters($schema_type) {
    return in_array($schema_type, ['Movie', 'TVSeries', 'Book']);
  }

  /**
   * Helper function to check if schema type has composer.
   */
  protected function hasComposer($schema_type) {
    return in_array($schema_type, ['Movie', 'TVSeries', 'MusicGroup']);
  }

  /**
   * Helper function to check if schema type has creator.
   */
  protected function hasCreator($schema_type) {
    return in_array($schema_type, ['Book', 'Movie', 'TVSeries']);
  }

  /**
   * Get logo URL: uploaded file or auto-detect.
   *
   * @param string $base_url
   *   The base URL of the site.
   * @param \Drupal\Core\Config\Config $config
   *   The module configuration.
   *
   * @return string
   *   The URL to the logo.
   */
  protected function getLogoUrl($base_url, $config) {
    // Priority 1: Uploaded custom logo.
    $logo_fid = $config->get('organization_logo_fid');
    if (!empty($logo_fid)) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($logo_fid);
      if ($file) {
        return file_create_url($file->getFileUri());
      }
    }

    // Priority 2: Auto-detect as fallback.
    return $this->getAutoDetectedLogoUrl($base_url);
  }

  /**
   * Auto-detect logo from theme and site settings.
   *
   * @param string $base_url
   *   The base URL of the site.
   *
   * @return string
   *   The URL to the auto-detected logo.
   */
  protected function getAutoDetectedLogoUrl($base_url) {
    // First, try to get logo from Drupal site logo settings.
    $site_logo = theme_get_setting('logo');
    if (!empty($site_logo['url'])) {
      $logo_url = $site_logo['url'];

      // Already a full URL (http://, https://, or protocol-relative //).
      if (preg_match('#^(https?:)?//#i', $logo_url)) {
        return $logo_url;
      }

      // Relative path - ensure it starts with /.
      if (strpos($logo_url, '/') !== 0) {
        $logo_url = '/' . $logo_url;
      }

      // Verify file exists before returning.
      $local_path = \Drupal::root() . $logo_url;
      if (file_exists($local_path)) {
        return $base_url . $logo_url;
      }
      // File doesn't exist, continue to other detection methods.
    }

    // Get active theme info.
    $theme_handler = \Drupal::service('theme_handler');
    $active_theme = $theme_handler->getDefault();

    try {
      $theme_path = $theme_handler->getTheme($active_theme)->getPath();
    }
    catch (\Exception $e) {
      // Theme not found, return null.
      return NULL;
    }

    // Common logo filenames to search for (ordered by preference).
    $logo_filenames = [
      'logo.svg',
      'logo.png',
      'logo.jpg',
      'logo.jpeg',
      'logo.gif',
      'images/logo.svg',
      'images/logo.png',
      'images/logo.jpg',
      'assets/logo.svg',
      'assets/logo.png',
      'img/logo.svg',
      'img/logo.png',
    ];

    // Search for logo files in active theme.
    foreach ($logo_filenames as $filename) {
      $logo_path = '/' . $theme_path . '/' . $filename;
      if (file_exists(\Drupal::root() . $logo_path)) {
        return $base_url . $logo_path;
      }
    }

    // Also check the default/frontend theme if different from active admin theme.
    $default_theme = \Drupal::config('system.theme')->get('default');
    if ($default_theme && $default_theme !== $active_theme) {
      try {
        $default_theme_path = $theme_handler->getTheme($default_theme)->getPath();
        foreach ($logo_filenames as $filename) {
          $logo_path = '/' . $default_theme_path . '/' . $filename;
          if (file_exists(\Drupal::root() . $logo_path)) {
            return $base_url . $logo_path;
          }
        }
      }
      catch (\Exception $e) {
        // Default theme not found, skip.
      }
    }

    // Return null if no logo found.
    return NULL;
  }

  /**
   * Formats a date string to ISO 8601.
   */
  protected function formatDate($date) {
    return date('c', strtotime($date));
  }

  /**
   * Gets schema images for a node as ImageObject array.
   *
   * @param int $nid
   *   The node ID.
   * @param string $base_url
   *   The site base URL.
   *
   * @return array
   *   Array of ImageObject schema items, or empty if no custom images.
   */
  protected function getSchemaImages($nid, $base_url) {
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      return [];
    }

    $images = [];
    foreach (self::SCHEMA_IMAGE_SIZES as $ratio => $info) {
      if (!$node->hasField($info['field']) || $node->get($info['field'])->isEmpty()) {
        continue;
      }
      $file = $node->get($info['field'])->entity;
      if (!$file) {
        continue;
      }

      $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
      $images[] = [
        '@type' => 'ImageObject',
        '@id' => $url,
        'url' => $url,
        'contentUrl' => $url,
        'width' => $info['width'],
        'height' => $info['height'],
        'caption' => $node->getTitle(),
      ];
    }

    if (empty($images)) {
      $images = $this->getSourceImageSchemaObjects($node);
    }

    return $images;
  }

  /**
   * Builds ImageObjects from the node's featured/source image.
   *
   * This matches WordPress behavior: generated schema crops are preferred, but
   * existing featured images can provide 1:1, 4:3, and 16:9 Article image
   * schema for legacy content that has not generated TopicalBoost schema image
   * fields yet.
   */
  protected function getSourceImageSchemaObjects(NodeInterface $node): array {
    $file = $this->getSourceImageFileFromNode($node);
    if (!$file) {
      return [];
    }

    $source_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    if (!$source_path || !file_exists($source_path)) {
      return [];
    }

    $source_image = \Drupal::service('image.factory')->get($source_path);
    if (!$source_image->isValid()) {
      return [];
    }

    $source_width = (int) $source_image->getWidth();
    $source_height = (int) $source_image->getHeight();
    if ($source_width < 675 || $source_height < 675) {
      return [$this->buildSourceImageObject($node, $file, $source_width, $source_height)];
    }

    $images = [];
    foreach (self::SCHEMA_IMAGE_SIZES as $ratio => $info) {
      $url = $this->ensureSourceSchemaCrop($node, $file, $source_path, $ratio, (int) $info['width'], (int) $info['height']);
      if (!$url) {
        continue;
      }

      $images[] = [
        '@type' => 'ImageObject',
        '@id' => $url,
        'url' => $url,
        'contentUrl' => $url,
        'width' => (int) $info['width'],
        'height' => (int) $info['height'],
        'caption' => $node->getTitle(),
      ];
    }

    return !empty($images) ? $images : [$this->buildSourceImageObject($node, $file, $source_width, $source_height)];
  }

  /**
   * Builds an ImageObject for the original source image.
   */
  protected function buildSourceImageObject(NodeInterface $node, FileInterface $file, int $width = 0, int $height = 0): array {
    $url = \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri());
    $image = [
      '@type' => 'ImageObject',
      '@id' => $url,
      'url' => $url,
      'contentUrl' => $url,
      'caption' => $node->getTitle(),
    ];

    if ($width > 0 && $height > 0) {
      $image['width'] = $width;
      $image['height'] = $height;
    }

    return $image;
  }

  /**
   * Ensures a WordPress-compatible schema crop exists for a source image.
   */
  protected function ensureSourceSchemaCrop(NodeInterface $node, FileInterface $file, string $source_path, string $ratio, int $dest_width, int $dest_height): string {
    $directory = 'public://schema-images/' . $node->id();
    $file_system = \Drupal::service('file_system');
    $file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    $real_dir = $file_system->realpath($directory);
    if (!$real_dir) {
      return '';
    }

    $source_mtime = filemtime($source_path) ?: time();
    $filename = 'featured-' . $file->id() . '-' . $source_mtime . '-' . $ratio . '.jpg';
    $dest_uri = $directory . '/' . $filename;
    $dest_path = $real_dir . '/' . $filename;
    if (!file_exists($dest_path)) {
      $image = \Drupal::service('image.factory')->get($source_path);
      if (!$image->isValid()) {
        return '';
      }

      $crop = $this->calculateSourceImageCrop((int) $image->getWidth(), (int) $image->getHeight(), $dest_width, $dest_height);
      $image->crop($crop['x'], $crop['y'], $crop['width'], $crop['height']);
      $image->resize($dest_width, $dest_height);
      if (!$image->save($dest_path)) {
        return '';
      }
    }

    return \Drupal::service('file_url_generator')->generateAbsoluteString($dest_uri);
  }

  /**
   * Calculates a centered crop region for a target aspect ratio.
   */
  protected function calculateSourceImageCrop(int $src_width, int $src_height, int $dest_width, int $dest_height): array {
    $source_ratio = $src_width / $src_height;
    $target_ratio = $dest_width / $dest_height;

    if ($source_ratio > $target_ratio) {
      $crop_height = $src_height;
      $crop_width = (int) round($src_height * $target_ratio);
      $crop_x = (int) round(($src_width - $crop_width) / 2);
      $crop_y = 0;
    }
    else {
      $crop_width = $src_width;
      $crop_height = (int) round($src_width / $target_ratio);
      $crop_x = 0;
      $crop_y = (int) round(($src_height - $crop_height) / 2);
    }

    return [
      'x' => max(0, $crop_x),
      'y' => max(0, $crop_y),
      'width' => min($src_width, $crop_width),
      'height' => min($src_height, $crop_height),
    ];
  }

  /**
   * Resolves the best available source image file from node image/media fields.
   */
  protected function getSourceImageFileFromNode(NodeInterface $node): ?FileInterface {
    foreach (self::SOURCE_IMAGE_FIELDS as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }

      $referenced = $node->get($field_name)->entity;
      if (!$referenced) {
        continue;
      }

      if ($referenced instanceof FileInterface) {
        return $referenced;
      }

      if (method_exists($referenced, 'getEntityTypeId') && $referenced->getEntityTypeId() === 'media') {
        $media_source = $referenced->getSource();
        $source_field = $media_source->getConfiguration()['source_field'] ?? 'field_media_image';
        if ($referenced->hasField($source_field) && !$referenced->get($source_field)->isEmpty()) {
          $file = $referenced->get($source_field)->entity;
          if ($file instanceof FileInterface) {
            return $file;
          }
        }
      }
    }

    return NULL;
  }

}
