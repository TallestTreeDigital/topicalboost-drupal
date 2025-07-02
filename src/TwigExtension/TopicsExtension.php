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
  public function displayTopics(NodeInterface $node = NULL, array $options = []) {
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

    // Check if frontend is enabled or user is logged in.
    $frontend_enabled = $config->get('enable_frontend');
    $user_logged_in = \Drupal::currentUser()->isAuthenticated();
    $show_topicalboost = $frontend_enabled || $user_logged_in;

    // Manual calls to topicalboost_display() should work regardless of automatic mentions setting
    // Only check frontend display permissions unless forced
    if (!$show_topicalboost && empty($options['force_display'])) {
      return '';
    }

    // Get filtered topics.
    $filtered_topics = $this->getFilteredTopics($node);

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
      '#options' => $options,
      '#attached' => [
        'library' => ['ttd_topics/topics_display'],
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
  public function getTopicsData(NodeInterface $node = NULL, array $options = []) {
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

    // Check if frontend is enabled or user is logged in.
    $frontend_enabled = $config->get('enable_frontend');
    $user_logged_in = \Drupal::currentUser()->isAuthenticated();
    $show_topicalboost = $frontend_enabled || $user_logged_in;

    // Manual calls to topicalboost_data() should work regardless of automatic mentions setting
    // Only check frontend display permissions unless forced
    if (!$show_topicalboost && empty($options['force_display'])) {
      return [];
    }

    // Get filtered topics.
    $filtered_topics = $this->getFilteredTopics($node);

    if (empty($filtered_topics)) {
      return [];
    }

    // Build topic data array.
    $topics_data = [];
    foreach ($filtered_topics as $topic) {
      $topics_data[] = [
        'id' => $topic->id(),
        'name' => $topic->getName(),
        'label' => $topic->label(),
        'url' => $topic->toUrl()->toString(),
        'entity' => $topic,
      ];
    }

    return [
      'topics' => $topics_data,
      'label' => $config->get('topics_list_label') ?: 'Mentions',
              'max_visible' => $config->get('maximum_visible_post_topics') ?: 5,
      'total_count' => count($topics_data),
    ];
  }

  /**
   * Get filtered topics for a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   *
   * @return array
   *   Array of filtered topic entities.
   */
  protected function getFilteredTopics(NodeInterface $node) {
    if (!$node->hasField('field_ttd_topics') || $node->get('field_ttd_topics')->isEmpty()) {
      return [];
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    $min_display_count = $config->get('post_topic_minimum_display_count') ?: 10;

    $rejected_term_ids = [];
    if ($node->hasField('field_ttd_rejected_topics')) {
      $rejected_term_ids = array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id');
    }

    $term_ids = [];
    foreach ($node->field_ttd_topics as $term_ref) {
      $term = $term_ref->entity;
      if ($term && !$term->get('field_hide')->value && !in_array($term->id(), $rejected_term_ids)) {
        $term_ids[] = $term->id();
      }
    }

    if (empty($term_ids)) {
      return [];
    }

    // Get the count of nodes for each term.
    $term_counts = $this->getTopicNodeCounts($term_ids);

    $filtered_topics = [];
    foreach ($node->field_ttd_topics as $term_ref) {
      $term = $term_ref->entity;
      if ($term && !$term->get('field_hide')->value) {
        $tid = $term->id();

        // Skip rejected topics.
        if (in_array($tid, $rejected_term_ids)) {
          continue;
        }

        $count = $term_counts[$tid] ?? 0;

        // Only include terms associated with at least the minimum display count.
        if ($count >= $min_display_count) {
          $filtered_topics[] = [
            'term' => $term,
            'count' => $count,
          ];
        }
      }
    }

    // Sort filtered topics by count in descending order.
    usort($filtered_topics, function ($a, $b) {
      return $b['count'] - $a['count'];
    });

    // Extract just the term objects for the template.
    return array_column($filtered_topics, 'term');
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
