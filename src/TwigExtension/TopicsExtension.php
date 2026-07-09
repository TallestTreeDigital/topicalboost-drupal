<?php

namespace Drupal\ttd_topics\TwigExtension;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for TopicalBoost topics display.
 */
class TopicsExtension extends AbstractExtension {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new TopicsExtension.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Connection $database, RendererInterface $renderer) {
    $this->configFactory = $config_factory;
    $this->database = $database;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('topicalboost_display', [$this, 'displayTopics'], ['is_safe' => ['html']]),
      new TwigFunction('topicalboost_data', [$this, 'getTopicsData']),
    ];
  }

  /**
   * Display TopicalBoost topics with show more/less functionality.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node entity. If null, uses the current node from route.
   * @param array $options
   *   Optional display options.
   *
   * @return string
   *   The rendered topics section.
   */
  public function displayTopics(?NodeInterface $node = NULL, array $options = []) {
    // If no node provided, try to get current node from route
    if ($node === NULL) {
      $node = \Drupal::routeMatch()->getParameter('node');
      if (!$node instanceof NodeInterface) {
        return '';
      }
    }
    $config = $this->configFactory->get('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);

    if (!in_array($node->getType(), $enabled_content_types)) {
      return '';
    }

    // Check if frontend is enabled or the user has the configured permission.
    $frontend_enabled = $config->get('enable_frontend');
    $required_permission = $config->get('required_permission') ?: 'administer topicalboost';
    $user_has_permission = \Drupal::currentUser()->hasPermission($required_permission);
    $show_topicalboost = $frontend_enabled || $user_has_permission;

    // Manual calls to topicalboost_display() should work regardless of automatic mentions setting
    // Only check frontend display permissions unless forced
    if (!$show_topicalboost && empty($options['force_display'])) {
      return '';
    }

    // Get filtered topics.
    $filtered_topics = $this->getFilteredTopicRows($node);

    if (empty($filtered_topics)) {
      return '';
    }

    // Build render array.
    $build = [
      '#theme' => 'topicalboost_display',
      '#node' => $node,
      '#filtered_topics' => $filtered_topics,
      '#topics_list_label' => $config->get('topics_list_label') ?: 'Mentions',
      '#maximum_visible_post_topics' => $config->get('maximum_visible_post_topics') ?: 5,
      '#options' => $options + [
        'frontend_filter_mode' => $config->get('frontend_filter_mode') ?: 'mentions_behind_toggle',
      ],
      '#attached' => [
        'library' => ['ttd_topics/topics_display'],
      ],
      '#cache' => [
        'tags' => ['ttd_topics:curation_scores'],
      ],
    ];

    return $this->renderer->render($build);
  }

  /**
   * Get TopicalBoost topics data as PHP array.
   *
   * @param \Drupal\node\NodeInterface|null $node
   *   The node entity. If null, uses the current node from route.
   * @param array $options
   *   Optional options.
   *
   * @return array
   *   Array of topic data with metadata.
   */
  public function getTopicsData(?NodeInterface $node = NULL, array $options = []) {
    // If no node provided, try to get current node from route
    if ($node === NULL) {
      $node = \Drupal::routeMatch()->getParameter('node');
      if (!$node instanceof NodeInterface) {
        return [];
      }
    }
    $config = $this->configFactory->get('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types'));

    if (!in_array($node->getType(), $enabled_content_types)) {
      return [];
    }

    // Check if frontend is enabled or the user has the configured permission.
    $frontend_enabled = $config->get('enable_frontend');
    $required_permission = $config->get('required_permission') ?: 'administer topicalboost';
    $user_has_permission = \Drupal::currentUser()->hasPermission($required_permission);
    $show_topicalboost = $frontend_enabled || $user_has_permission;

    // Manual calls to topicalboost_data() should work regardless of automatic mentions setting
    // Only check frontend display permissions unless forced
    if (!$show_topicalboost && empty($options['force_display'])) {
      return [];
    }

    // Get filtered topics.
    $filtered_topics = $this->getFilteredTopicRows($node);

    if (empty($filtered_topics)) {
      return [];
    }

    $display_topics = $this->splitTopicsForFrontendDisplay($filtered_topics, $config, $options);
    $topics_data = array_merge($display_topics['visible'], $display_topics['hidden']);

    return [
      'topics' => $topics_data,
      'visible_topics' => $display_topics['visible'],
      'hidden_topics' => $display_topics['hidden'],
      'label' => $config->get('topics_list_label') ?: 'Mentions',
      'max_visible' => count($display_topics['visible']),
      'configured_max_visible' => $config->get('maximum_visible_post_topics') ?: 5,
      'total_count' => count($topics_data),
      'hidden_count' => count($display_topics['hidden']),
    ];
  }

  /**
   * Gets filtered topic rows from the shared module helper.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Array of filtered topic rows.
   */
  protected function getFilteredTopicRows(NodeInterface $node) {
    if (function_exists('ttd_topics_get_filtered_topics_for_node')) {
      return ttd_topics_get_filtered_topics_for_node($node);
    }

    return $this->getFilteredTopics($node);
  }

  /**
   * Splits filtered topics into visible and hidden groups using WP behavior.
   *
   * @param array $filtered_topics
   *   Filtered topic rows from ttd_topics_get_filtered_topics_for_node().
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   TopicalBoost settings.
   * @param array $options
   *   Optional display overrides.
   *
   * @return array
   *   Visible and hidden topic data arrays.
   */
  protected function splitTopicsForFrontendDisplay(array $filtered_topics, $config, array $options = []) {
    $display_limit = (int) ($config->get('maximum_visible_post_topics') ?: 5);
    $filter_mode = $options['frontend_filter_mode'] ?? ($config->get('frontend_filter_mode') ?: 'mentions_behind_toggle');

    $topics = [];
    foreach ($filtered_topics as $topic) {
      $topic_data = $this->buildTopicData($topic);
      if ($topic_data) {
        $topics[] = $topic_data;
      }
    }

    if ($filter_mode === 'high_salience_only') {
      $topics = array_values(array_filter($topics, function ($topic) {
        return !empty($topic['is_manual']) || ($topic['salience_tier'] ?? 'mentions') !== 'mentions';
      }));

      return [
        'visible' => array_slice($topics, 0, $display_limit),
        'hidden' => array_slice($topics, $display_limit),
      ];
    }

    if ($filter_mode === 'mentions_behind_toggle') {
      $high_salience = [];
      $mentions = [];

      foreach ($topics as $topic) {
        if (empty($topic['is_manual']) && ($topic['salience_tier'] ?? 'mentions') === 'mentions') {
          $mentions[] = $topic + ['hidden' => TRUE];
        }
        else {
          $high_salience[] = $topic;
        }
      }

      $visible = array_slice($high_salience, 0, $display_limit);
      $hidden = array_merge(array_slice($high_salience, $display_limit), $mentions);

      if (empty($visible) && !empty($mentions)) {
        $visible = array_slice($mentions, 0, $display_limit);
        $visible = array_map(function ($topic) {
          $topic['hidden'] = FALSE;
          return $topic;
        }, $visible);
        $hidden = array_slice($mentions, $display_limit);
      }

      $hidden = array_map(function ($topic) {
        $topic['hidden'] = TRUE;
        return $topic;
      }, $hidden);

      return [
        'visible' => $visible,
        'hidden' => $hidden,
      ];
    }

    $visible = array_slice($topics, 0, $display_limit);
    $hidden = array_slice($topics, $display_limit);
    $hidden = array_map(function ($topic) {
      $topic['hidden'] = TRUE;
      return $topic;
    }, $hidden);

    return [
      'visible' => $visible,
      'hidden' => $hidden,
    ];
  }

  /**
   * Builds template-safe topic data from a filtered topic row.
   *
   * @param array $topic
   *   Filtered topic row.
   *
   * @return array|null
   *   Topic data array, or NULL for invalid rows.
   */
  protected function buildTopicData(array $topic) {
    $term = $topic['term'] ?? NULL;
    if (!$term) {
      return NULL;
    }

    $tier = $topic['salience_tier'] ?? ($topic['salience_category'] ?? 'mentions');

    return [
      'id' => $term->id(),
      'name' => $term->getName(),
      'label' => $term->label(),
      'url' => function_exists('ttd_topics_get_topic_url') ? \ttd_topics_get_topic_url($term) : $term->toUrl()->toString(),
      'entity' => $term,
      'count' => (int) ($topic['count'] ?? 0),
      'salience_score' => $topic['salience_score'] ?? NULL,
      'salience_category' => $tier,
      'salience_tier' => $tier,
      'is_manual' => !empty($topic['is_manual']),
      'curation' => $topic['curation'] ?? NULL,
      'hidden' => FALSE,
    ];
  }

  /**
   * Get filtered topics for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Array of filtered topic rows.
   */
  protected function getFilteredTopics(NodeInterface $node) {
    if (!$node->hasField('field_ttd_topics') || $node->get('field_ttd_topics')->isEmpty()) {
      return [];
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    $min_display_count = $config->get('post_topic_minimum_display_count') ?: 10;

    $rejected_term_ids = [];
    if ($node->hasField('field_ttd_rejected_topics')) {
      $rejected_term_ids = array_map('intval', array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id'));
    }
    $manual_term_ids = $node->hasField('field_manual_topics')
      ? array_map('intval', array_column($node->get('field_manual_topics')->getValue(), 'target_id'))
      : [];

    $term_ids = [];
    $term_entity_ids = [];
    foreach ($node->field_ttd_topics as $term_ref) {
      $term = $term_ref->entity;
      if (!$term) {
        continue;
      }
      $tid = (int) $term->id();
      $is_manual = in_array($tid, $manual_term_ids, TRUE);
      $is_hidden = $term->hasField('field_hide') && !$term->get('field_hide')->isEmpty() && (bool) $term->get('field_hide')->value;
      if ($is_hidden || (!$is_manual && in_array($tid, $rejected_term_ids, TRUE))) {
        continue;
      }

      $term_ids[] = $tid;
      if ($term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()) {
        $term_entity_ids[$tid] = (int) $term->get('field_ttd_id')->value;
      }
    }

    if (empty($term_ids)) {
      return [];
    }

    // Get the count of nodes for each term.
    $term_counts = $this->getTopicNodeCounts($term_ids);
    $curation_scores = function_exists('ttd_topics_get_curation_scores')
      ? \ttd_topics_get_curation_scores(array_values($term_entity_ids))
      : [];
    $salience_data = function_exists('ttd_get_node_salience_data')
      ? \ttd_get_node_salience_data($node->id(), $node)
      : [];

    $filtered_topics = [];
    foreach ($node->field_ttd_topics as $term_ref) {
      $term = $term_ref->entity;
      if ($term) {
        $tid = (int) $term->id();
        $is_manual = in_array($tid, $manual_term_ids, TRUE);
        $is_hidden = $term->hasField('field_hide') && !$term->get('field_hide')->isEmpty() && (bool) $term->get('field_hide')->value;
        if ($is_hidden) {
          continue;
        }

        // Skip rejected automatic topics.
        if (!$is_manual && in_array($tid, $rejected_term_ids, TRUE)) {
          continue;
        }

        $count = $term_counts[$tid] ?? 0;
        $ttd_id = (int) ($term_entity_ids[$tid] ?? 0);
        $is_forced = $term->hasField('field_force_show') && !$term->get('field_force_show')->isEmpty() && (bool) $term->get('field_force_show')->value;
        $salience_category = $salience_data[$ttd_id]['salience_category'] ?? 'mentions';
        if (!in_array($salience_category, ['mainEntity', 'about', 'mentions'], TRUE)) {
          $salience_category = 'mentions';
        }
        $is_drag_promoted = !empty($salience_data[$ttd_id]['is_user_override']) && in_array($salience_category, ['mainEntity', 'about'], TRUE);
        if (!$is_manual && !$is_forced && !$is_drag_promoted && function_exists('ttd_topics_term_should_curate') && !\ttd_topics_term_should_curate($term, $curation_scores)) {
          continue;
        }

        // Manual, force-show, and high-salience topics bypass the count floor.
        if ($is_manual || $count >= $min_display_count || $is_forced || in_array($salience_category, ['mainEntity', 'about'], TRUE)) {
          $filtered_topics[] = [
            'term' => $term,
            'url' => function_exists('ttd_topics_get_topic_url') ? \ttd_topics_get_topic_url($term) : $term->toUrl()->toString(),
            'count' => $count,
            'salience_score' => $salience_data[$ttd_id]['salience_score'] ?? NULL,
            'salience_category' => $salience_category,
            'salience_tier' => $salience_category,
            'is_manual' => $is_manual,
            'curation' => $curation_scores[$ttd_id] ?? NULL,
          ];
        }
      }
    }

    // Match WordPress ordering: mainEntity, about, mentions, then alphabetically.
    $tier_priority = ['mainEntity' => 0, 'about' => 1, 'mentions' => 2];
    usort($filtered_topics, function ($a, $b) use ($tier_priority) {
      $tier_a = $tier_priority[$a['salience_tier'] ?? 'mentions'] ?? 2;
      $tier_b = $tier_priority[$b['salience_tier'] ?? 'mentions'] ?? 2;
      if ($tier_a !== $tier_b) {
        return $tier_a <=> $tier_b;
      }

      return strcasecmp($a['term']->label(), $b['term']->label());
    });
    return $filtered_topics;
  }

  /**
   * Get node counts for topic terms.
   *
   * @param array $term_ids
   *   Array of term IDs.
   *
   * @return array
   *   Array of term ID => count pairs.
   */
  protected function getTopicNodeCounts(array $term_ids) {
    if (empty($term_ids)) {
      return [];
    }

    $query = $this->database->select('taxonomy_index', 'ti')
      ->fields('ti', ['tid'])
      ->condition('tid', $term_ids, 'IN')
      ->groupBy('tid');
    $query->addExpression('COUNT(nid)', 'count');

    $results = $query->execute()->fetchAllKeyed();

    // Ensure all term IDs are present in the result.
    $counts = [];
    foreach ($term_ids as $tid) {
      $counts[$tid] = isset($results[$tid]) ? (int) $results[$tid] : 0;
    }

    return $counts;
  }

}
