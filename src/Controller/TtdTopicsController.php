<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for TopicalBoost routes.
 */
class TtdTopicsController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

  /**
   * Simple WordPress-like overview of topics with post counts.
   */
  public function overviewPage() {
    $database = \Drupal::database();
    $config = \Drupal::config('ttd_topics.settings');
    $min_display_count = $config->get('post_topic_minimum_display_count') ?: 10;

    // Get search and filter parameters.
    $search = \Drupal::request()->query->get('search', '');
    $min_posts = \Drupal::request()->query->get('min_posts', '');

    // First get total count of all topics (before filtering and limiting)
    $total_count_query = $database->select('taxonomy_term_field_data', 'td');
    $total_count_query->condition('td.vid', 'ttd_topics');
    $total_count_query->condition('td.status', 1);

    // Add search filter if provided for total count too.
    if (!empty($search)) {
      $total_count_query->condition('td.name', '%' . $database->escapeLike($search) . '%', 'LIKE');
    }

    $total_topics_count = $total_count_query->countQuery()->execute()->fetchField();

    // Get basic topic data first.
    $query = $database->select('taxonomy_term_field_data', 'td');
    $query->fields('td', ['tid', 'name']);
    $query->condition('td.vid', 'ttd_topics');
    $query->condition('td.status', 1);

    // Add search filter if provided.
    if (!empty($search)) {
      $query->condition('td.name', '%' . $database->escapeLike($search) . '%', 'LIKE');
    }

    // Limit to prevent memory issues.
    $query->range(0, 200);
    $query->orderBy('td.name', 'ASC');

    $terms_data = $query->execute()->fetchAllKeyed();

    if (empty($terms_data)) {
      $no_results_text = !empty($search) ?
        $this->t('No topics found matching "@search".', ['@search' => $search]) :
        $this->t('No topics found.');

      return [
        '#markup' => '<div style="padding: 20px; text-align: center; color: #666;">' . $no_results_text . '</div>',
      ];
    }

    // Get post counts using existing function.
    $term_ids = array_keys($terms_data);
    $post_counts = ttd_topics_get_topic_node_counts($term_ids);

    // Build table rows and apply post count filter.
    $rows = [];
    $total_posts = 0;
    $filtered_count = 0;

    foreach ($terms_data as $tid => $name) {
      $count = $post_counts[$tid] ?? 0;

      // Apply post count filter if specified.
      if (!empty($min_posts) && $count < (int) $min_posts) {
        continue;
      }

      $filtered_count++;
      $total_posts += $count;

      $edit_url = Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $tid]);

      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $name,
            '#url' => $edit_url,
          ],
        ],
        'count' => $count,
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => $edit_url,
              ],
            ],
          ],
        ],
      ];
    }

    // Sort by post count.
    usort($rows, function ($a, $b) {
      return $b['count'] - $a['count'];
    });

    // Handle empty results after filtering.
    if (empty($rows)) {
      $filter_text = '';
      if (!empty($search) && !empty($min_posts)) {
        $filter_text = $this->t('No topics found matching "@search" with @min_posts+ posts.', [
          '@search' => $search,
          '@min_posts' => $min_posts,
        ]);
      }
      elseif (!empty($search)) {
        $filter_text = $this->t('No topics found matching "@search".', ['@search' => $search]);
      }
      elseif (!empty($min_posts)) {
        $filter_text = $this->t('No topics found with @min_posts+ posts.', ['@min_posts' => $min_posts]);
      }
      else {
        $filter_text = $this->t('No topics found.');
      }

      return [
        '#markup' => '<div style="padding: 20px; text-align: center; color: #666;">' . $filter_text . '</div>',
      ];
    }

    $build = [];

    // Build summary text with filter info.
    $filter_parts = [];
    if (!empty($search)) {
      $filter_parts[] = 'filtered by "' . htmlspecialchars($search, ENT_QUOTES) . '"';
    }
    if (!empty($min_posts)) {
      $filter_parts[] = 'with ' . $min_posts . '+ posts';
    }
    $filter_text = !empty($filter_parts) ? ' (' . implode(' and ', $filter_parts) . ')' : '';

    // Simple summary section.
    $display_text = '';
    if ($total_topics_count > 200 && $filtered_count == count($terms_data)) {
      // We're showing a limited set due to the 200 limit.
      $display_text = 'Showing <strong>' . count($terms_data) . '</strong> of <strong>' . $total_topics_count . '</strong> total topics with <strong>' . $total_posts . '</strong> total post references' . $filter_text;
    }
    else {
      // We're showing all available topics (after filters)
      $display_text = 'Showing <strong>' . $filtered_count . '</strong> topics with <strong>' . $total_posts . '</strong> total post references' . $filter_text;
      if ($total_topics_count != $filtered_count) {
        $display_text = 'Showing <strong>' . $filtered_count . '</strong> of <strong>' . $total_topics_count . '</strong> total topics with <strong>' . $total_posts . '</strong> total post references' . $filter_text;
      }
    }

    $build['summary'] = [
      '#markup' => '<div style="background: #e3f2fd; padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 4px solid #2196f3;">
        <h3 style="margin: 0 0 8px 0; color: #1976d2;">Topics Overview</h3>
        <p style="margin: 0; color: #424242;">' . $display_text . '</p>
      </div>',
      '#weight' => -50,
    ];

    // Simple table without complex styling.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Topic Name'),
        $this->t('Posts'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No topics found.'),
      '#attributes' => [
        'style' => 'background: white; margin-top: 10px;',
      ],
      '#weight' => 0,
    ];

    return $build;
  }

  /**
   * Provides an improved taxonomy overview page with post counts.
   */
  public function improvedOverview() {
    try {
      $database = \Drupal::database();
      $config = \Drupal::config('ttd_topics.settings');
      $min_display_count = $config->get('post_topic_minimum_display_count') ?: 10;

      // First, get all TopicalBoost terms efficiently.
      $terms_query = $database->select('taxonomy_term_field_data', 'td');
      $terms_query->fields('td', ['tid', 'name']);
      $terms_query->condition('td.vid', 'ttd_topics');
      $terms_query->condition('td.status', 1);
      $terms_query->orderBy('td.name', 'ASC');

      $terms_data = $terms_query->execute()->fetchAllKeyed();

      if (empty($terms_data)) {
        return [
          '#markup' => $this->t('No topics found.'),
        ];
      }

      // Get post counts efficiently using the existing function.
      $term_ids = array_keys($terms_data);
      $post_counts = ttd_topics_get_topic_node_counts($term_ids);

      // Get hide field values.
      $hide_query = $database->select('taxonomy_term__field_hide', 'tfh');
      $hide_query->fields('tfh', ['entity_id', 'field_hide_value']);
      $hide_query->condition('tfh.entity_id', $term_ids, 'IN');
      $hide_data = $hide_query->execute()->fetchAllKeyed();

      // Combine the data.
      $terms = [];
      foreach ($terms_data as $tid => $name) {
        $terms[] = (object) [
          'tid' => $tid,
          'name' => $name,
          'post_count' => $post_counts[$tid] ?? 0,
          'is_hidden' => $hide_data[$tid] ?? 0,
        ];
      }

      // Sort by post count descending.
      usort($terms, function ($a, $b) {
        return $b->post_count - $a->post_count;
      });

      // Build the table rows.
      $rows = [];
      $total_posts = 0;
      $total_terms = 0;
      $visible_terms = 0;

      foreach ($terms as $term) {
        $total_terms++;
        $post_count = (int) $term->post_count;
        $total_posts += $post_count;
        $is_hidden = $term->is_hidden;
        $is_below_threshold = $post_count < $min_display_count;

        if (!$is_hidden && !$is_below_threshold) {
          $visible_terms++;
        }

        $status_indicators = [];
        if ($is_hidden) {
          $status_indicators[] = '<span class="status-hidden" title="Hidden from public display">Hidden</span>';
        }
        if ($is_below_threshold) {
          $status_indicators[] = '<span class="status-below-threshold" title="Below minimum display count">Below threshold</span>';
        }

        $edit_url = Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $term->tid]);
        $view_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $term->tid]);

        $rows[] = [
          'name' => [
            'data' => [
              '#type' => 'link',
              '#title' => $term->name,
              '#url' => $edit_url,
              '#attributes' => ['class' => ['term-name']],
            ],
          ],
          'count' => [
            'data' => $post_count,
            'class' => $post_count > 0 ? ['post-count', 'has-posts'] : ['post-count', 'no-posts'],
          ],
          'status' => [
            'data' => [
              '#markup' => implode(' ', $status_indicators),
            ],
          ],
          'operations' => [
            'data' => [
              '#type' => 'operations',
              '#links' => [
                'edit' => [
                  'title' => $this->t('Edit'),
                  'url' => $edit_url,
                ],
                'view' => [
                  'title' => $this->t('View'),
                  'url' => $view_url,
                ],
              ],
            ],
          ],
        ];
      }

      $header = [
      ['data' => $this->t('Topic Name'), 'field' => 'name'],
      ['data' => $this->t('Posts'), 'field' => 'post_count', 'sort' => 'desc'],
      ['data' => $this->t('Status')],
      ['data' => $this->t('Operations')],
      ];

      $build = [];

      // Add summary stats.
      $build['summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-topics-summary']],
        'stats' => [
          '#markup' => $this->t('<div class="summary-stats">
          <div class="stat-item"><strong>@total_terms</strong> Total Topics</div>
          <div class="stat-item"><strong>@visible_terms</strong> Publicly Visible</div>
          <div class="stat-item"><strong>@total_posts</strong> Total Post References</div>
          <div class="stat-item">Minimum display count: <strong>@min_count</strong></div>
        </div>', [
          '@total_terms' => $total_terms,
          '@visible_terms' => $visible_terms,
          '@total_posts' => $total_posts,
          '@min_count' => $min_display_count,
        ]),
        ],
      ];

      // Add the table.
      $build['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No topics found.'),
        '#attributes' => ['class' => ['ttd-topics-overview']],
      ];

      // Add action links.
      $build['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['action-links']],
        'add_term' => [
          '#type' => 'link',
          '#title' => $this->t('Add new topic'),
          '#url' => Url::fromRoute('entity.taxonomy_term.add_form', ['taxonomy_vocabulary' => 'ttd_topics']),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];

      // Add CSS.
      $build['#attached']['library'][] = 'ttd_topics/ttd_topics.styles';
      $build['#attached']['library'][] = 'core/drupal.tableheader';

      // Add inline CSS since we can't guarantee the library file exists.
      $build['#attached']['html_head'][] = [
       [
         '#tag' => 'style',
         '#value' => '
.ttd-topics-summary {
  background: #f8f9fa;
  border: 1px solid #dee2e6;
  border-radius: 4px;
  padding: 15px;
  margin-bottom: 20px;
}
.summary-stats {
  display: flex;
  gap: 30px;
  flex-wrap: wrap;
}
.stat-item {
  font-size: 14px;
  color: #495057;
}
.stat-item strong {
  color: #212529;
  font-weight: 600;
}
.ttd-topics-overview th {
  background-color: #f8f9fa;
  border-bottom: 2px solid #dee2e6;
  font-weight: 600;
  color: #495057;
}
.ttd-topics-overview td {
  vertical-align: middle;
  border-bottom: 1px solid #dee2e6;
}
.ttd-topics-overview .term-name {
  font-weight: 500;
  color: #0056b3;
  text-decoration: none;
}
.ttd-topics-overview .term-name:hover {
  color: #004085;
  text-decoration: underline;
}
.post-count {
  text-align: center;
  font-weight: 500;
  padding: 4px 8px;
  border-radius: 3px;
}
.post-count.has-posts {
  background-color: #d4edda;
  color: #155724;
}
.post-count.no-posts {
  background-color: #f8d7da;
  color: #721c24;
}
.status-hidden {
  background-color: #ffeaa7;
  color: #2d3436;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 12px;
  font-weight: 500;
  margin-right: 5px;
}
.status-below-threshold {
  background-color: #fab1a0;
  color: #2d3436;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 12px;
  font-weight: 500;
}
.ttd-topics-overview tbody tr:hover {
  background-color: #f8f9fa;
}
.ttd-topics-overview tbody tr:nth-child(odd) {
  background-color: #ffffff;
}
.ttd-topics-overview tbody tr:nth-child(even) {
  background-color: #fbfbfb;
}
         ',
       ],
        'ttd-topics-overview-styles',
      ];

      return $build;

    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error in improved overview: @message', ['@message' => $e->getMessage()]);
      return [
        '#markup' => $this->t('An error occurred while loading the topics overview. Please try again.'),
      ];
    }
  }

  /**
   * Returns the number of topics that would be displayed based on minimum frequency.
   */
  public function getTopicCount(Request $request) {
    $min_frequency = $request->query->get('min_frequency', 1);

    // Validate input.
    $min_frequency = (int) $min_frequency;
    if ($min_frequency < 1) {
      $min_frequency = 1;
    }

    $database = \Drupal::database();

    // Query to count topics that appear in at least min_frequency posts.
    $query = $database->select('taxonomy_index', 'ti');
    $query->addField('ti', 'tid');
    $query->join('taxonomy_term_field_data', 'ttfd', 'ti.tid = ttfd.tid');
    $query->condition('ttfd.vid', 'ttd_topics');

    // Only count non-hidden topics.
    $query->leftJoin('taxonomy_term__field_hide', 'tfh', 'ti.tid = tfh.entity_id');
    $or_condition = $query->orConditionGroup()
      ->condition('tfh.field_hide_value', 0)
      ->isNull('tfh.field_hide_value');
    $query->condition($or_condition);

    $query->groupBy('ti.tid');
    $query->having('COUNT(ti.nid) >= :min_frequency', [':min_frequency' => $min_frequency]);

    $count_query = $database->select($query, 'subquery');
    $total_topics = $count_query->countQuery()->execute()->fetchField();

    // Get total number of topics for percentage calculation.
    $total_query = $database->select('taxonomy_term_field_data', 'ttfd');
    $total_query->condition('ttfd.vid', 'ttd_topics');
    $total_query->leftJoin('taxonomy_term__field_hide', 'tfh', 'ttfd.tid = tfh.entity_id');
    $or_condition_total = $total_query->orConditionGroup()
      ->condition('tfh.field_hide_value', 0)
      ->isNull('tfh.field_hide_value');
    $total_query->condition($or_condition_total);
    $total_all_topics = $total_query->countQuery()->execute()->fetchField();

    $percentage = $total_all_topics > 0 ? ($total_topics / $total_all_topics) * 100 : 0;

    return new JsonResponse([
      'count' => (int) $total_topics,
      'total' => (int) $total_all_topics,
      'percentage' => round($percentage, 1),
      'min_frequency' => $min_frequency,
    ]);
  }

  /**
   * Returns dynamic recommendations for minimum frequency based on site data.
   */
  public function getRecommendations(Request $request) {
    $database = \Drupal::database();
    
    // Get the frequency distribution of all topics
    $query = $database->select('taxonomy_index', 'ti');
    $query->addField('ti', 'tid');
    $query->addExpression('COUNT(ti.nid)', 'post_count');
    $query->join('taxonomy_term_field_data', 'ttfd', 'ti.tid = ttfd.tid');
    $query->condition('ttfd.vid', 'ttd_topics');
    
    // Only count non-hidden topics
    $query->leftJoin('taxonomy_term__field_hide', 'tfh', 'ti.tid = tfh.entity_id');
    $or_condition = $query->orConditionGroup()
      ->condition('tfh.field_hide_value', 0)
      ->isNull('tfh.field_hide_value');
    $query->condition($or_condition);
    
    $query->groupBy('ti.tid');
    $query->orderBy('post_count', 'DESC');
    
    $results = $query->execute()->fetchAllKeyed();
    $total_topics = count($results);
    
    if ($total_topics == 0) {
      return new JsonResponse([
        'conservative' => ['min' => 1, 'max' => 5],
        'balanced' => ['min' => 3, 'max' => 8],
        'selective' => ['min' => 5, 'max' => 15],
        'total_topics' => 0,
      ]);
    }
    
    // Sort by post count to analyze distribution
    $post_counts = array_values($results);
    sort($post_counts);
    
    // Calculate percentile-based recommendations
    $percentiles = [];
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90, 95] as $p) {
      $index = (int) (($p / 100) * ($total_topics - 1));
      $percentiles[$p] = $post_counts[$index];
    }
    
    // Define recommendation zones based on percentiles
    // Conservative: Show ~40-60% of topics (top 40-60%)
    $conservative_min = $percentiles[40];
    $conservative_max = $percentiles[60];
    
    // Balanced: Show ~20-40% of topics (top 20-40%)  
    $balanced_min = $percentiles[60];
    $balanced_max = $percentiles[80];
    
    // Selective: Show ~5-20% of topics (top 5-20%)
    $selective_min = $percentiles[80];
    $selective_max = $percentiles[95];
    
    // Ensure minimums are at least 1 and maximums are reasonable
    $conservative_min = max(1, $conservative_min);
    $conservative_max = max($conservative_min + 1, $conservative_max, 3);
    
    $balanced_min = max($conservative_max, $balanced_min, 2);
    $balanced_max = max($balanced_min + 1, $balanced_max, 5);
    
    $selective_min = max($balanced_max, $selective_min, 3);
    $selective_max = max($selective_min + 2, $selective_max, 8);
    
    return new JsonResponse([
      'conservative' => [
        'min' => (int) $conservative_min,
        'max' => (int) $conservative_max,
        'label' => 'Show More Topics',
        'description' => 'Display 40-60% of topics'
      ],
      'balanced' => [
        'min' => (int) $balanced_min,
        'max' => (int) $balanced_max,
        'label' => 'Balanced',
        'description' => 'Display 20-40% of topics'
      ],
      'selective' => [
        'min' => (int) $selective_min,
        'max' => (int) $selective_max,
        'label' => 'More Selective',
        'description' => 'Display 5-20% of topics'
      ],
      'total_topics' => $total_topics,
      'distribution' => [
        '10th_percentile' => $percentiles[10],
        '50th_percentile' => $percentiles[50],
        '90th_percentile' => $percentiles[90],
      ]
    ]);
  }

}
