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
    $has_been_analyzed = $node->hasField('field_ttd_last_analyzed') && !$node->get('field_ttd_last_analyzed')->isEmpty();

    // Match WordPress: globally hidden topics are omitted from the editor list.
    $topics = array_values(array_filter($node->get('field_ttd_topics')->referencedEntities(), function ($topic) {
      return !($topic->hasField('field_hide') && !$topic->get('field_hide')->isEmpty() && (bool) $topic->get('field_hide')->value);
    }));
    $manual_topic_ids = $node->hasField('field_manual_topics')
      ? array_map('intval', array_column($node->get('field_manual_topics')->getValue(), 'target_id'))
      : [];
    $rejected_topic_ids = $node->hasField('field_ttd_rejected_topics')
      ? array_map('intval', array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id'))
      : [];
    $tier_overrides = $node->hasField('field_tier_overrides')
      ? ($node->get('field_tier_overrides')->value ?? [])
      : [];
    $tier_overrides = is_array($tier_overrides) ? $tier_overrides : [];
    $salience_data = ttd_get_node_salience_data($node->id(), $node);

    // Get post counts for all topics
    $term_ids = array_map(function($topic) { return $topic->id(); }, $topics);
    $post_counts = ttd_topics_get_topic_node_counts($term_ids);

    // Classify topics into tiers
    $main_entity = NULL;
    $about_topics = [];
    $mentions_topics = [];
    $below_threshold_topics = [];

    foreach ($topics as $term) {
      $term_id = (int) $term->id();
      $ttd_id = $term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()
        ? (int) $term->get('field_ttd_id')->value
        : 0;
      $tier = 'mentions';
      if ($ttd_id && isset($tier_overrides[(string) $ttd_id])) {
        $tier = $tier_overrides[(string) $ttd_id];
      }
      elseif (isset($tier_overrides['term_' . $term_id])) {
        $tier = $tier_overrides['term_' . $term_id];
      }
      elseif ($ttd_id && !empty($salience_data[$ttd_id]['salience_category'])) {
        $tier = $salience_data[$ttd_id]['salience_category'];
      }
      if (!in_array($tier, ['mainEntity', 'about', 'mentions', 'below-threshold'], TRUE)) {
        $tier = 'mentions';
      }
      $count = $post_counts[$term_id] ?? 0;
      $is_manual = in_array($term_id, $manual_topic_ids, TRUE);
      $is_rejected = in_array($term_id, $rejected_topic_ids, TRUE);
      $demand = in_array($tier, ['mainEntity', 'about'], TRUE) ? $this->buildDemandBadgeData($term_id) : NULL;

      $topic_data = [
        'term' => $term,
        'count' => $count,
        'count_display' => $this->formatCount($count),
        'is_manual' => $is_manual,
        'is_rejected' => $is_rejected,
        'tier' => $tier,
        'demand' => $demand,
      ];

      // Classify by tier
      if ($tier === 'mainEntity') {
        $main_entity = $topic_data;
      } elseif ($tier === 'about') {
        $about_topics[] = $topic_data;
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

    // Sort like WordPress: count descending, then alphabetically.
    $sort_topics = function($a, $b) {
      if ($b['count'] !== $a['count']) {
        return $b['count'] - $a['count'];
      }
      return strcasecmp($a['term']->label(), $b['term']->label());
    };
    usort($about_topics, $sort_topics);
    usort($mentions_topics, $sort_topics);
    usort($below_threshold_topics, $sort_topics);

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
            'hasBeenAnalyzed' => $has_been_analyzed,
          ],
        ],
      ],
    ];
  }

  /**
   * Builds traffic-potential badge data matching the WordPress editor.
   */
  private function buildDemandBadgeData(int $term_id): array {
    $metrics = function_exists('ttd_get_demand_metrics') ? \ttd_get_demand_metrics($term_id) : NULL;

    if (!$metrics || !isset($metrics['keyword_difficulty'])) {
      return [
        'class' => 'ttd-kd-no-data',
        'display' => '--',
        'title' => $this->t('Demand data not yet available. Click to fetch.'),
      ];
    }

    $kd = (int) $metrics['keyword_difficulty'];
    $traffic_potential = isset($metrics['traffic_potential']) ? (int) $metrics['traffic_potential'] : 0;
    if ($traffic_potential <= 0) {
      return [
        'class' => 'ttd-kd-no-data',
        'display' => '--',
        'title' => $this->t('Traffic potential not yet available. Click to fetch.'),
      ];
    }

    $label = $this->getDifficultyLabel($kd);
    $display = $this->formatCount($traffic_potential);

    return [
      'class' => $this->getDifficultyClass($kd),
      'display' => $display,
      'title' => $this->t("Traffic Potential: @traffic\nDifficulty: @difficulty/100 (@label)\n\nClick to refresh", [
        '@traffic' => $display,
        '@difficulty' => $kd,
        '@label' => $label,
      ]),
      'keyword_difficulty' => $kd,
      'traffic_potential' => $traffic_potential,
      'search_volume' => isset($metrics['search_volume']) ? (int) $metrics['search_volume'] : 0,
    ];
  }

  /**
   * Formats counts the same way the WordPress editor badges do.
   */
  private function formatCount(int $count): string {
    if ($count >= 1000000) {
      $value = $count / 1000000;
      return rtrim(rtrim(number_format($value, $value == floor($value) ? 0 : 1), '0'), '.') . 'M';
    }
    if ($count >= 1000) {
      $value = $count / 1000;
      return rtrim(rtrim(number_format($value, $value == floor($value) ? 0 : 1), '0'), '.') . 'K';
    }
    return (string) $count;
  }

  /**
   * Maps keyword difficulty to the shared badge class.
   */
  private function getDifficultyClass(int $kd): string {
    if ($kd <= 30) {
      return 'ttd-kd-easy';
    }
    if ($kd <= 60) {
      return 'ttd-kd-medium';
    }
    if ($kd <= 80) {
      return 'ttd-kd-hard';
    }
    return 'ttd-kd-very-hard';
  }

  /**
   * Human label for keyword difficulty.
   */
  private function getDifficultyLabel(int $kd): string {
    if ($kd <= 30) {
      return (string) $this->t('Easy');
    }
    if ($kd <= 60) {
      return (string) $this->t('Medium');
    }
    if ($kd <= 80) {
      return (string) $this->t('Hard');
    }
    return (string) $this->t('Very Hard');
  }

}
