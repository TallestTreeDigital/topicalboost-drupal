<?php

namespace Drupal\ttd_topics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\topicalboost\Form\GetTopicsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an 'Admin Node Topics Block' block.
 *
 * @Block(
 *   id = "admin_node_topics_block",
 *   admin_label = @Translation("TopicalBoost - Admin Node Topics Block"),
 *   category = @Translation("Custom"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", label = @Translation("Node"))
 *   }
 * )
 */
class AdminNodeTopicsBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * Constructs a new AdminNodeTopicsBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');

    if (!$node || !$node->hasField('field_ttd_topics')) {
      return [];
    }

    $config = \Drupal::config('ttd_topics.settings');
    $threshold_count = $config->get('post_topic_minimum_display_count') ?: 10;

    // Get all topics for this node
    $topics = $node->get('field_ttd_topics')->referencedEntities();
    $manual_topic_ids = array_column($node->get('field_manual_topics')->getValue(), 'target_id');
    $rejected_topic_ids = array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id');
    $tier_overrides = $node->get('field_tier_overrides')->value ?? [];

    // Get post counts for all topics
    $term_ids = array_map(function($topic) { return $topic->id(); }, $topics);
    $post_counts = ttd_topics_get_topic_node_counts($term_ids);

    // Classify topics into tiers
    $main_entity = NULL;
    $about_topics = [];
    $mentions_topics = [];
    $below_threshold_topics = [];

    foreach ($topics as $term) {
      $term_id = $term->id();
      $tier = ttd_get_topic_tier($term_id, $node->id());
      $count = $post_counts[$term_id] ?? 0;
      $is_manual = in_array($term_id, $manual_topic_ids);
      $is_rejected = in_array($term_id, $rejected_topic_ids);

      $topic_data = [
        'term' => $term,
        'count' => $count,
        'is_manual' => $is_manual,
        'is_rejected' => $is_rejected,
        'tier' => $tier,
      ];

      // Classify by tier
      if ($tier === 'mainEntity') {
        $main_entity = $topic_data;
      } elseif ($tier === 'about') {
        if ($count >= $threshold_count) {
          $about_topics[] = $topic_data;
        } else {
          $below_threshold_topics[] = $topic_data;
        }
      } elseif ($tier === 'mentions') {
        if ($count >= $threshold_count) {
          $mentions_topics[] = $topic_data;
        } else {
          $below_threshold_topics[] = $topic_data;
        }
      } elseif ($tier === 'below-threshold') {
        $below_threshold_topics[] = $topic_data;
      }
    }

    // Sort by count descending
    usort($about_topics, function($a, $b) { return $b['count'] - $a['count']; });
    usort($mentions_topics, function($a, $b) { return $b['count'] - $a['count']; });
    usort($below_threshold_topics, function($a, $b) { return $b['count'] - $a['count']; });

    return [
      '#theme' => 'ttd_admin_topics',
      '#node' => $node,
      '#main_entity' => $main_entity,
      '#about_topics' => $about_topics,
      '#mentions_topics' => $mentions_topics,
      '#below_threshold_topics' => $below_threshold_topics,
      '#threshold_count' => $threshold_count,
      '#attached' => [
        'library' => ['ttd_topics/admin_topics'],
        'drupalSettings' => [
          'ttdTopics' => [
            'nodeId' => $node->id(),
            'thresholdCount' => $threshold_count,
          ],
        ],
      ],
    ];
  }

}
