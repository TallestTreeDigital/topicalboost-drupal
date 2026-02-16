<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

  /**
   * Check analysis status for a node.
   *
   * @param int $node
   *   The node ID to check.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with analysis status.
   */
  public function checkAnalysisStatus($node) {
    try {
      // Load the node.
      $node_entity = \Drupal::entityTypeManager()->getStorage('node')->load($node);

      if (!$node_entity) {
        return new JsonResponse([
          'error' => TRUE,
          'message' => $this->t('Node not found.'),
        ], 404);
      }

      // Check if user has access to view this node.
      if (!$node_entity->access('view')) {
        return new JsonResponse([
          'error' => TRUE,
          'message' => $this->t('Access denied.'),
        ], 403);
      }

      // Check analysis status.
      $analysis_in_progress = $node_entity->get('field_ttd_analysis_in_progress')->value;
      $has_topics = !$node_entity->get('field_ttd_topics')->isEmpty();

      // Determine completion status.
      $completed = !$analysis_in_progress && $has_topics;
      $error = FALSE;
      $message = '';

      if ($completed) {
        $message = $this->t('Analysis completed successfully.');
      } elseif (!$analysis_in_progress && !$has_topics) {
        // Not in progress but no topics - might be an error or very new
        $error = TRUE;
        $message = $this->t('Analysis completed but no topics were found.');
      } else {
        $message = $this->t('Analysis still in progress...');
      }

      return new JsonResponse([
        'completed' => $completed,
        'in_progress' => $analysis_in_progress,
        'has_topics' => $has_topics,
        'error' => $error,
        'message' => $message,
        'reload' => $completed, // Suggest page reload when complete
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error checking analysis status for node @nid: @error', [
        '@nid' => $node,
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => TRUE,
        'message' => $this->t('An error occurred while checking analysis status.'),
      ], 500);
    }
  }

  /**
   * Redirect old bulk analysis URL to settings page with bulk-analysis tab hash.
   */
  public function redirectToSettings() {
    $url = Url::fromRoute('topicalboost.settings_form', [], ['fragment' => 'bulk-analysis']);
    return new RedirectResponse($url->toString(), 301);
  }

  /**
   * Search for topics in local database.
   */
  public function searchTopics(Request $request) {
    $query = $request->query->get('q', '');
    $node_id = $request->query->get('node_id', 0);

    if (strlen($query) < 2) {
      return new JsonResponse(['results' => []]);
    }

    $database = \Drupal::database();

    // Build query to search taxonomy terms - search for full phrase like WordPress does
    $term_query = $database->select('taxonomy_term_field_data', 'ttfd');
    $term_query->fields('ttfd', ['tid', 'name']);
    $term_query->condition('ttfd.vid', 'ttd_topics');
    $term_query->condition('ttfd.status', 1);

    // Search for the full query string as a phrase (case-insensitive)
    $search_pattern = '%' . $database->escapeLike(strtolower($query)) . '%';
    $term_query->where('LOWER(ttfd.name) LIKE :pattern', [':pattern' => $search_pattern]);

    // Get TTD IDs for terms
    $term_query->leftJoin('taxonomy_term__field_ttd_id', 'ttid', 'ttfd.tid = ttid.entity_id');
    $term_query->addField('ttid', 'field_ttd_id_value', 'ttd_id');

    // Get node count for each term
    $term_query->leftJoin('taxonomy_index', 'ti', 'ttfd.tid = ti.tid');
    $term_query->addExpression('COUNT(DISTINCT ti.nid)', 'post_count');
    $term_query->groupBy('ttfd.tid');
    $term_query->groupBy('ttfd.name');
    $term_query->groupBy('ttid.field_ttd_id_value');

    // Limit results
    $term_query->range(0, 10);

    $results = $term_query->execute()->fetchAll();

    // Filter out topics already on the node
    if ($node_id) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
      if ($node && $node->hasField('field_ttd_topics')) {
        $existing_tids = array_column($node->get('field_ttd_topics')->getValue(), 'target_id');
        $results = array_filter($results, function($result) use ($existing_tids) {
          return !in_array($result->tid, $existing_tids);
        });
      }
    }

    // Format results
    $formatted_results = [];
    foreach ($results as $result) {
      // Calculate relevance score
      $exact_match = strcasecmp($result->name, $query) === 0;
      $relevance = $exact_match ? 2 : 1;

      $formatted_results[] = [
        'term_id' => (int) $result->tid,
        'ttd_id' => $result->ttd_id,
        'name' => $result->name,
        'count' => (int) $result->post_count,
        'relevance' => $relevance,
        'source' => 'local',
      ];
    }

    // Sort by relevance, then by count
    usort($formatted_results, function($a, $b) {
      if ($b['relevance'] !== $a['relevance']) {
        return $b['relevance'] - $a['relevance'];
      }
      return $b['count'] - $a['count'];
    });

    return new JsonResponse(['results' => $formatted_results]);
  }

  /**
   * Lookup topics from external API.
   */
  public function lookupApi(Request $request) {
    $query = $request->query->get('q', '');
    $node_id = $request->query->get('node_id', 0);

    if (strlen($query) < 2) {
      return new JsonResponse(['results' => []]);
    }

    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return new JsonResponse(['error' => 'API key not configured'], 400);
    }

    try {
      $client = \Drupal::httpClient();
      $response = $client->request('GET', TOPICALBOOST_API_ENDPOINT . '/lookup', [
        'query' => [
          'q' => $query,
        ],
        'headers' => [
          'X-API-Key' => $api_key,
          'Content-Type' => 'application/json',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $api_results = $data['results'] ?? [];

      $database = \Drupal::database();
      $formatted_results = [];

      foreach ($api_results as $result) {
        $exists = FALSE;
        $in_post = FALSE;
        $term_id = NULL;
        $ttd_id = $result['ttd_id'] ?? NULL;

        // Check if exists in ttd_entities
        if (!empty($result['mid']) || !empty($result['wb_qid'])) {
          $entity_query = $database->select('ttd_entities', 'te');
          $entity_query->fields('te', ['ttd_id']);
          if (!empty($result['mid'])) {
            $entity_query->condition('te.mid', $result['mid']);
          } elseif (!empty($result['wb_qid'])) {
            $entity_query->condition('te.wb_qid', $result['wb_qid']);
          }
          $entity_result = $entity_query->execute()->fetchField();
          if ($entity_result) {
            $exists = TRUE;
            $ttd_id = $entity_result;
          }
        }

        // Check if exists in taxonomy by ttd_id
        if ($ttd_id) {
          $term_query = $database->select('taxonomy_term__field_ttd_id', 'ttid');
          $term_query->fields('ttid', ['entity_id']);
          $term_query->condition('ttid.field_ttd_id_value', $ttd_id);
          $term_id = $term_query->execute()->fetchField();
          if ($term_id) {
            $exists = TRUE;
          }
        }

        // Check if in current post
        if ($node_id && $term_id) {
          $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
          if ($node && $node->hasField('field_ttd_topics')) {
            $existing_tids = array_column($node->get('field_ttd_topics')->getValue(), 'target_id');
            $in_post = in_array($term_id, $existing_tids);
          }
        }

        $formatted_results[] = [
          'ttd_id' => $ttd_id,
          'term_id' => $term_id,
          'name' => $result['name'] ?? $result['wb_name'] ?? '',
          'description' => $result['wb_description'] ?? '',
          'exists' => $exists,
          'in_post' => $in_post,
          'source' => 'api',
        ];
      }

      return new JsonResponse(['results' => $formatted_results]);

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('API lookup error: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'API request failed'], 500);
    }
  }

  /**
   * Update topic tier override.
   */
  public function updateTopicTier(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $node_id = $data['node_id'] ?? NULL;
    $ttd_id = $data['ttd_id'] ?? NULL;
    $term_id = $data['term_id'] ?? NULL;
    $new_tier = $data['new_tier'] ?? NULL;

    if (!$node_id || (!$ttd_id && !$term_id) || !$new_tier) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing required parameters'], 400);
    }

    try {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
      if (!$node) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Node not found'], 404);
      }

      // Get current tier_overrides
      $tier_overrides = $node->get('field_tier_overrides')->value ?? [];
      if (!is_array($tier_overrides)) {
        $tier_overrides = [];
      }

      // Build override key (prefer ttd_id, fall back to term_id)
      $override_key = $ttd_id ? (string) $ttd_id : 'term_' . $term_id;

      // If setting mainEntity, remove any existing mainEntity override
      if ($new_tier === 'mainEntity') {
        foreach ($tier_overrides as $key => $tier) {
          if ($tier === 'mainEntity') {
            unset($tier_overrides[$key]);
          }
        }
      }

      // Set new tier
      $tier_overrides[$override_key] = $new_tier;

      // Save to node
      $node->set('field_tier_overrides', $tier_overrides);
      $node->save();

      // Fetch demand metrics if tier is mainEntity or about
      $demand_metrics = NULL;
      if (in_array($new_tier, ['mainEntity', 'about']) && $term_id) {
        $demand_metrics = $this->getDemandMetricsData($term_id);
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'tier' => $new_tier,
          'demand_metrics' => $demand_metrics,
          'term_id_for_fetch' => $term_id,
        ],
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error updating topic tier: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Failed to update tier'], 500);
    }
  }

  /**
   * Remove topic tier override.
   */
  public function removeTopicTierOverride(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $node_id = $data['node_id'] ?? NULL;
    $ttd_id = $data['ttd_id'] ?? NULL;
    $term_id = $data['term_id'] ?? NULL;

    if (!$node_id || (!$ttd_id && !$term_id)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing required parameters'], 400);
    }

    try {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
      if (!$node) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Node not found'], 404);
      }

      // Get current tier_overrides
      $tier_overrides = $node->get('field_tier_overrides')->value ?? [];
      if (!is_array($tier_overrides)) {
        $tier_overrides = [];
      }

      // Build override key
      $override_key = $ttd_id ? (string) $ttd_id : 'term_' . $term_id;

      // Remove override
      if (isset($tier_overrides[$override_key])) {
        unset($tier_overrides[$override_key]);
      }

      // Save to node
      $node->set('field_tier_overrides', $tier_overrides);
      $node->save();

      return new JsonResponse(['success' => TRUE]);

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error removing topic tier override: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Failed to remove override'], 500);
    }
  }

  /**
   * Update topics (add/remove manual topics, accept/reject).
   */
  public function updateTopics(Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    $node_id = $data['node_id'] ?? NULL;
    $topic_id = $data['topic_id'] ?? NULL;
    $add_manual = $data['add_manual'] ?? FALSE;
    $remove_manual = $data['remove_manual'] ?? FALSE;
    $is_accepted = $data['is_accepted'] ?? NULL;

    if (!$node_id || !$topic_id) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Missing required parameters'], 400);
    }

    try {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
      if (!$node) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Node not found'], 404);
      }

      // Handle add manual topic
      if ($add_manual) {
        $manual_topics = $node->get('field_manual_topics')->getValue();
        $manual_tids = array_column($manual_topics, 'target_id');
        if (!in_array($topic_id, $manual_tids)) {
          $manual_tids[] = $topic_id;
          $node->set('field_manual_topics', $manual_tids);
        }
      }

      // Handle remove manual topic
      if ($remove_manual) {
        $manual_topics = $node->get('field_manual_topics')->getValue();
        $manual_tids = array_column($manual_topics, 'target_id');
        $manual_tids = array_filter($manual_tids, function($tid) use ($topic_id) {
          return $tid != $topic_id;
        });
        $node->set('field_manual_topics', array_values($manual_tids));
      }

      // Handle accept/reject
      if ($is_accepted !== NULL) {
        $rejected_topics = $node->get('field_ttd_rejected_topics')->getValue();
        $rejected_tids = array_column($rejected_topics, 'target_id');

        if (!$is_accepted && !in_array($topic_id, $rejected_tids)) {
          // Reject topic
          $rejected_tids[] = $topic_id;
          $node->set('field_ttd_rejected_topics', $rejected_tids);
        } elseif ($is_accepted && in_array($topic_id, $rejected_tids)) {
          // Un-reject topic
          $rejected_tids = array_filter($rejected_tids, function($tid) use ($topic_id) {
            return $tid != $topic_id;
          });
          $node->set('field_ttd_rejected_topics', array_values($rejected_tids));
        }
      }

      $node->save();

      return new JsonResponse(['success' => TRUE]);

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error updating topics: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'message' => 'Failed to update topics'], 500);
    }
  }

  /**
   * Get demand metrics for a term.
   */
  public function getDemandMetrics(Request $request) {
    $term_id = $request->query->get('term_id');
    $keyword = $request->query->get('keyword');
    $force_refresh = $request->query->get('force_refresh', 0);

    if (!$term_id && !$keyword) {
      return new JsonResponse(['error' => 'Missing term_id or keyword'], 400);
    }

    try {
      $data = $this->getDemandMetricsData($term_id, $keyword, $force_refresh);
      return new JsonResponse(['success' => TRUE, 'data' => $data]);
    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error getting demand metrics: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Failed to fetch demand metrics'], 500);
    }
  }

  /**
   * Helper to fetch demand metrics data.
   */
  private function getDemandMetricsData($term_id = NULL, $keyword = NULL, $force_refresh = 0) {
    $state = \Drupal::state();
    $cache_key = 'ttd_demand_' . ($term_id ?? md5($keyword));
    $cache_duration = 7 * 24 * 60 * 60; // 7 days

    // Check cache unless force refresh
    if (!$force_refresh) {
      $cached = $state->get($cache_key);
      if ($cached && isset($cached['timestamp']) && (time() - $cached['timestamp']) < $cache_duration) {
        return $cached['data'];
      }
    }

    // Get keyword from term if not provided
    if (!$keyword && $term_id) {
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
      if ($term) {
        $keyword = $term->getName();
      }
    }

    if (!$keyword) {
      return NULL;
    }

    // Call TopicalBoost API
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return NULL;
    }

    try {
      $client = \Drupal::httpClient();
      $response = $client->request('GET', TOPICALBOOST_API_ENDPOINT . '/demand', [
        'query' => [
          'keyword' => $keyword,
        ],
        'headers' => [
          'X-API-Key' => $api_key,
          'Content-Type' => 'application/json',
        ],
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $metrics = [
        'keyword_difficulty' => $data['keyword_difficulty'] ?? 0,
        'traffic_potential' => $data['traffic_potential'] ?? 0,
      ];

      // Cache the result
      $state->set($cache_key, [
        'timestamp' => time(),
        'data' => $metrics,
      ]);

      return $metrics;

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('API demand metrics error: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}
