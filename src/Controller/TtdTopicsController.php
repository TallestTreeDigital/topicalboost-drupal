<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for TopicalBoost routes.
 */
class TtdTopicsController extends ControllerBase {

  /**
   * Fallback cooldown for temporary demand metrics API outages.
   */
  private const DEMAND_METRICS_COOLDOWN_SECONDS = 120;

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
   * Resolve a Drupal topic term to a TopicalBoost entity payload.
   */
  private function getEditorialSignalTopic($term_id = NULL, $ttd_id = NULL) {
    $term = NULL;

    if ($term_id) {
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
    }

    if (!$term && $ttd_id) {
      $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'ttd_topics',
        'field_ttd_id' => (string) $ttd_id,
      ]);
      if (!empty($terms)) {
        $term = reset($terms);
      }
    }

    $entity_id = $ttd_id ? (int) $ttd_id : 0;
    if ($term && $term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()) {
      $entity_id = (int) $term->get('field_ttd_id')->value;
    }

    if (!$entity_id) {
      return NULL;
    }

    return [
      'entityId' => $entity_id,
      'entityName' => $term ? $term->label() : NULL,
      'termId' => $term ? (int) $term->id() : NULL,
    ];
  }

  /**
   * Fire-and-forget-ish editorial telemetry. Never block editor actions.
   */
  private function recordEditorialSignal($action, array $topic, array $context = []) {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    if (empty($api_key) || empty($topic['entityId'])) {
      return;
    }

    $metadata = array_filter(array_merge([
      'termId' => $topic['termId'] ?? NULL,
    ], $context['metadata'] ?? []), static function ($value) {
      return $value !== NULL;
    });

    $payload = array_filter([
      'action' => $action,
      'postId' => isset($context['postId']) ? (string) $context['postId'] : NULL,
      'entityId' => (int) $topic['entityId'],
      'entityName' => $topic['entityName'] ?? NULL,
      'fromTier' => $context['fromTier'] ?? NULL,
      'toTier' => $context['toTier'] ?? NULL,
      'actorId' => (string) \Drupal::currentUser()->id(),
      'metadata' => !empty($metadata) ? $metadata : NULL,
    ], static function ($value) {
      return $value !== NULL;
    });

    try {
      $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
      \Drupal::httpClient()->request('POST', $api_endpoint . '/telemetry/editorial-signals', [
        'headers' => [
          'content-type' => 'application/json',
          'x-api-key' => $api_key,
          'x-tb-platform' => 'drupal',
        ],
        'json' => $payload,
        'timeout' => 1,
        'connect_timeout' => 0.5,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->debug('Editorial signal telemetry failed: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * Simple WordPress-like overview of topics with post counts.
   */
  public function overviewPage() {
    $database = \Drupal::database();
    $config = \Drupal::config('ttd_topics.settings');
    $min_display_count = $config->get('post_topic_minimum_display_count') ?: 10;

    // Get search, filter, and sort parameters.
    $request = \Drupal::request();
    $search = $request->query->get('search', '');
    $min_posts = $request->query->get('min_posts', '');
    $visibility = $request->query->get('visibility', '');
    $sort_by = $request->query->get('sort', 'count');
    $sort_order = $request->query->get('order', 'desc');

    // Validate sort params.
    if (!in_array($sort_by, ['name', 'count'], TRUE)) {
      $sort_by = 'count';
    }
    if (!in_array($sort_order, ['asc', 'desc'], TRUE)) {
      $sort_order = 'desc';
    }

    // First get total count of all topics (before filtering and limiting)
    $total_count_query = $database->select('taxonomy_term_field_data', 'td');
    $total_count_query->condition('td.vid', 'ttd_topics');
    $total_count_query->condition('td.status', 1);

    // Add search filter if provided for total count too.
    if (!empty($search)) {
      $total_count_query->condition('td.name', '%' . $database->escapeLike($search) . '%', 'LIKE');
    }

    $total_topics_count = $total_count_query->countQuery()->execute()->fetchField();

    // Get topic data with post counts via JOIN for proper SQL sorting.
    $query = $database->select('taxonomy_term_field_data', 'td');
    $query->fields('td', ['tid', 'name']);
    $query->condition('td.vid', 'ttd_topics');
    $query->condition('td.status', 1);

    // LEFT JOIN taxonomy_index to get post counts in the query.
    $query->leftJoin('taxonomy_index', 'ti', 'td.tid = ti.tid');
    $query->addExpression('COUNT(ti.nid)', 'post_count');

    // LEFT JOIN field_hide to get hidden status.
    $query->leftJoin('taxonomy_term__field_hide', 'tfh', 'td.tid = tfh.entity_id');
    $query->addExpression('COALESCE(tfh.field_hide_value, 0)', 'is_hidden');

    $query->groupBy('td.tid');
    $query->groupBy('td.name');
    $query->groupBy('tfh.field_hide_value');

    // Add search filter if provided.
    if (!empty($search)) {
      $query->condition('td.name', '%' . $database->escapeLike($search) . '%', 'LIKE');
    }

    // Apply min_posts filter via HAVING.
    if (!empty($min_posts)) {
      $query->havingCondition('COUNT(ti.nid)', (int) $min_posts, '>=');
    }

    // Apply visibility filter.
    if ($visibility === 'hidden') {
      $query->condition('tfh.field_hide_value', 1);
    }
    elseif ($visibility === 'visible') {
      $or = $query->orConditionGroup()
        ->condition('tfh.field_hide_value', 0)
        ->isNull('tfh.field_hide_value');
      $query->condition($or);
      $query->havingCondition('COUNT(ti.nid)', $min_display_count, '>=');
    }
    elseif ($visibility === 'below') {
      $or = $query->orConditionGroup()
        ->condition('tfh.field_hide_value', 0)
        ->isNull('tfh.field_hide_value');
      $query->condition($or);
      $query->havingCondition('COUNT(ti.nid)', $min_display_count, '<');
    }

    // Apply sort in SQL.
    if ($sort_by === 'name') {
      $query->orderBy('td.name', strtoupper($sort_order));
    }
    else {
      $query->orderBy('post_count', strtoupper($sort_order));
      $query->orderBy('td.name', 'ASC');
    }

    $all_results = $query->execute()->fetchAll();

    if (empty($all_results)) {
      $no_results_text = !empty($search) ?
        $this->t('No topics found matching "@search".', ['@search' => $search]) :
        $this->t('No topics found.');

      return [
        '#markup' => '<div style="padding: 20px; text-align: center; color: #666;">' . $no_results_text . '</div>',
      ];
    }

    // Calculate totals across ALL results (not just current page).
    $filtered_count = count($all_results);
    $total_posts = 0;
    foreach ($all_results as $row) {
      $total_posts += (int) $row->post_count;
    }

    // Set up pagination.
    $items_per_page = 50;
    $pager_manager = \Drupal::service('pager.manager');
    $pager = $pager_manager->createPager($filtered_count, $items_per_page, 1);
    $current_page = $pager->getCurrentPage();
    $page_results = array_slice($all_results, $current_page * $items_per_page, $items_per_page);

    // Build table rows from current page.
    $rows = [];

    foreach ($page_results as $row) {
      $count = (int) $row->post_count;
      $is_hidden = (int) $row->is_hidden;
      $is_below_threshold = $count < $min_display_count;

      $edit_url = Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $row->tid]);
      $toggle_url = Url::fromRoute('topicalboost.api.toggle_topic_visibility', ['taxonomy_term' => $row->tid]);

      // Build name cell with inline badges.
      $name_markup = '<a href="' . $edit_url->toString() . '" class="ttd-topic-name">' . htmlspecialchars($row->name, ENT_QUOTES) . '</a>';

      // Hidden badge is clickable to unhide; visible topics above threshold get a hover-only "Hide" link.
      if ($is_hidden) {
        $name_markup .= ' <a href="' . $toggle_url->toString() . '" class="ttd-status-badge ttd-status-badge--hidden ttd-toggle-visibility" title="Click to unhide">Hidden</a>';
      }
      elseif (!$is_below_threshold) {
        $name_markup .= ' <a href="' . $toggle_url->toString() . '" class="ttd-status-badge ttd-status-badge--hide-action ttd-toggle-visibility" title="Hide from public display">Hide</a>';
      }
      if ($is_below_threshold) {
        $name_markup .= ' <span class="ttd-status-badge ttd-status-badge--below" title="Below minimum display count of ' . $min_display_count . '">Below threshold</span>';
      }

      $rows[(int) $row->tid] = [
        'name' => [
          'data' => ['#markup' => $name_markup],
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
    if (!empty($visibility)) {
      $visibility_labels = ['hidden' => 'hidden only', 'visible' => 'visible only', 'below' => 'below threshold'];
      $filter_parts[] = $visibility_labels[$visibility] ?? $visibility;
    }
    $filter_text = !empty($filter_parts) ? ' (' . implode(' and ', $filter_parts) . ')' : '';

    // Summary with page range and totals across all pages.
    $page_start = $current_page * $items_per_page + 1;
    $page_end = min(($current_page + 1) * $items_per_page, $filtered_count);
    $display_text = '';
    if ($filtered_count > $items_per_page) {
      $display_text = 'Showing <strong>' . $page_start . '-' . $page_end . '</strong> of <strong>' . $filtered_count . '</strong> topics with <strong>' . $total_posts . '</strong> total post references' . $filter_text;
    }
    else {
      $display_text = 'Showing <strong>' . $filtered_count . '</strong> topics with <strong>' . $total_posts . '</strong> total post references' . $filter_text;
    }
    if ($total_topics_count != $filtered_count) {
      $display_text .= ' (<strong>' . $total_topics_count . '</strong> total topics)';
    }

    $build['#attached']['library'][] = 'ttd_topics/overview';
    $build['#attached']['drupalSettings']['topicalboostCsrfToken'] = \Drupal::csrfToken()->get('rest');

    $build['summary'] = [
      '#markup' => '<div class="ttd-topics-summary">
        <h3 class="ttd-topics-summary__title">Topics Overview</h3>
        <p class="ttd-topics-summary__text">' . $display_text . '</p>
      </div>',
      '#weight' => -50,
    ];

    // Build sortable header links.
    $base_query = [];
    if (!empty($search)) {
      $base_query['search'] = $search;
    }
    if (!empty($min_posts)) {
      $base_query['min_posts'] = $min_posts;
    }
    if (!empty($visibility)) {
      $base_query['visibility'] = $visibility;
    }

    // Name header: toggle order if already sorting by name, else default to ASC.
    $name_order = ($sort_by === 'name' && $sort_order === 'asc') ? 'desc' : 'asc';
    $name_arrow = '';
    if ($sort_by === 'name') {
      $name_arrow = $sort_order === 'asc' ? ' &#9650;' : ' &#9660;';
    }
    $name_sort_url = Url::fromRoute('<current>', [], ['query' => $base_query + ['sort' => 'name', 'order' => $name_order]]);

    // Count header: toggle order if already sorting by count, else default to DESC.
    $count_order = ($sort_by === 'count' && $sort_order === 'desc') ? 'asc' : 'desc';
    $count_arrow = '';
    if ($sort_by === 'count') {
      $count_arrow = $sort_order === 'asc' ? ' &#9650;' : ' &#9660;';
    }
    $count_sort_url = Url::fromRoute('<current>', [], ['query' => $base_query + ['sort' => 'count', 'order' => $count_order]]);

    $header = [
      'name' => [
        'data' => [
          '#markup' => '<a href="' . $name_sort_url->toString() . '" class="ttd-sort-link' . ($sort_by === 'name' ? ' is-active' : '') . '">Topic Name' . $name_arrow . '</a>',
        ],
      ],
      'count' => [
        'data' => [
          '#markup' => '<a href="' . $count_sort_url->toString() . '" class="ttd-sort-link' . ($sort_by === 'count' ? ' is-active' : '') . '">Posts' . $count_arrow . '</a>',
        ],
      ],
      'operations' => $this->t('Operations'),
    ];

    $build['bulk_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-bulk-actions', 'container-inline']],
      '#weight' => -10,
      'action' => [
        '#type' => 'select',
        '#title' => $this->t('Bulk action'),
        '#title_display' => 'invisible',
        '#options' => [
          '' => $this->t('- Action -'),
          'ttd_hide' => $this->t('Hide'),
          'ttd_unhide' => $this->t('Unhide'),
        ],
        '#parents' => ['ttd_topics_bulk_action'],
      ],
      'apply' => [
        '#type' => 'submit',
        '#value' => $this->t('Apply'),
        '#validate' => ['ttd_topics_bulk_terms_validate'],
        '#submit' => ['ttd_topics_bulk_terms_submit'],
      ],
    ];

    $build['table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $rows,
      '#empty' => $this->t('No topics found.'),
      '#attributes' => [
        'class' => ['ttd-topics-overview'],
      ],
      '#js_select' => TRUE,
      '#parents' => ['ttd_topics_bulk_terms'],
      '#weight' => 0,
    ];

    // Add pager if more than one page.
    if ($filtered_count > $items_per_page) {
      $build['pager'] = [
        '#type' => 'pager',
        '#element' => 1,
        '#weight' => 10,
      ];
    }

    return $build;
  }

  /**
   * Toggle the hidden state of a topic term.
   */
  public function toggleTopicVisibility($taxonomy_term) {
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($taxonomy_term);

    if (!$term || $term->bundle() !== 'ttd_topics') {
      return new JsonResponse(['success' => FALSE, 'message' => 'Term not found'], 404);
    }

    if (!$term->hasField('field_hide')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'field_hide not available'], 400);
    }

    $current = (bool) $term->get('field_hide')->value;
    $term->set('field_hide', !$current);
    $term->save();

    $topic = $this->getEditorialSignalTopic($term->id());
    if ($topic) {
      $this->recordEditorialSignal(!$current ? 'force_hide' : 'force_unhide', $topic);
    }

    return new JsonResponse([
      'success' => TRUE,
      'hidden' => !$current,
      'message' => !$current ? 'Topic hidden' : 'Topic visible',
    ]);
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
      $state = \Drupal::state();
      $state_key = 'ttd_topics.single_analysis_request.' . $node_entity->id();
      $analysis_request = $state->get($state_key);

      if ($analysis_in_progress) {
        $analysis_service = \Drupal::service('ttd_topics.analysis_service');
        $recovery = $analysis_service->recoverSingleAnalysis(
          $node_entity,
          is_array($analysis_request) ? $analysis_request : NULL
        );

        if (($recovery['status'] ?? '') === 'completed') {
          return new JsonResponse([
            'completed' => TRUE,
            'in_progress' => FALSE,
            'has_topics' => !$node_entity->get('field_ttd_topics')->isEmpty(),
            'error' => FALSE,
            'message' => $this->t('Analysis completed successfully.'),
            'reload' => TRUE,
          ]);
        }

        if (($recovery['status'] ?? '') === 'timeout') {
          return new JsonResponse([
            'completed' => FALSE,
            'in_progress' => FALSE,
            'has_topics' => !$node_entity->get('field_ttd_topics')->isEmpty(),
            'error' => TRUE,
            'message' => $this->t('Analysis timed out. You can retry.'),
            'reload' => FALSE,
          ]);
        }

        $analysis_in_progress = $recovery['in_progress'] ?? $analysis_in_progress;
      }

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
   * Search for posts to pin on a topic term page.
   */
  public function searchPinnedPosts(Request $request) {
    $query = $request->query->get('q', '');
    $term_id = $request->query->get('term_id', 0);

    if (strlen($query) < 2) {
      return new JsonResponse(['results' => []]);
    }

    $database = \Drupal::database();
    $config = \Drupal::config('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);

    if (empty($enabled_content_types)) {
      return new JsonResponse(['results' => []]);
    }

    // Search published nodes by title.
    $node_query = $database->select('node_field_data', 'n');
    $node_query->fields('n', ['nid', 'title', 'type', 'created']);
    $node_query->condition('n.status', 1);
    $node_query->condition('n.type', $enabled_content_types, 'IN');
    $node_query->condition('n.title', '%' . $database->escapeLike($query) . '%', 'LIKE');
    $node_query->orderBy('n.created', 'DESC');
    $node_query->range(0, 20);

    $results = $node_query->execute()->fetchAll();

    // Get already-pinned node IDs for this term to mark them.
    $pinned_nids = [];
    if ($term_id) {
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
      if ($term && $term->hasField('field_pinned_posts')) {
        foreach ($term->get('field_pinned_posts') as $ref) {
          if ($ref->target_id) {
            $pinned_nids[] = (int) $ref->target_id;
          }
        }
      }
    }

    $formatted = [];
    foreach ($results as $row) {
      $formatted[] = [
        'nid' => (int) $row->nid,
        'title' => $row->title,
        'type' => $row->type,
        'date' => date('M j, Y', $row->created),
        'pinned' => in_array((int) $row->nid, $pinned_nids),
      ];
    }

    return new JsonResponse(['results' => $formatted]);
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
    $term_query->fields('ttfd', ['tid', 'name', 'description__value']);
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
    $term_query->orderBy('post_count', 'DESC');
    $term_query->range(0, 10);

    $results = $term_query->execute()->fetchAll();

    $existing_tids = [];
    if ($node_id) {
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
      if ($node && $node->hasField('field_ttd_topics')) {
        $existing_tids = array_column($node->get('field_ttd_topics')->getValue(), 'target_id');
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
        'description' => $result->description__value ?? '',
        'count' => (int) $result->post_count,
        'relevance' => $relevance,
        'source' => 'local',
        'in_post' => in_array($result->tid, $existing_tids),
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

    if (strlen($query) < 3) {
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
        'headers' => \ttd_topics_api_headers($api_key),
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $api_results = [];
      if (is_array($data)) {
        if (isset($data['results']) && is_array($data['results'])) {
          $api_results = $data['results'];
        }
        elseif (isset($data[0]) && is_array($data[0])) {
          $api_results = $data;
        }
      }

      $database = \Drupal::database();
      $formatted_results = [];

      $mids = [];
      $wb_qids = [];
      $ttd_ids = [];
      foreach ($api_results as $result) {
        if (!empty($result['mid'])) {
          $mids[] = (string) $result['mid'];
        }
        if (!empty($result['wb_qid'])) {
          $wb_qids[] = (string) $result['wb_qid'];
        }
        if (!empty($result['ttd_id'])) {
          $ttd_ids[] = (string) $result['ttd_id'];
        }
      }
      $mids = array_values(array_unique($mids));
      $wb_qids = array_values(array_unique($wb_qids));
      $ttd_ids = array_values(array_unique($ttd_ids));

      $entity_ttd_by_mid = [];
      $entity_ttd_by_wb_qid = [];
      if ($mids || $wb_qids) {
        $entity_query = $database->select('ttd_entities', 'te');
        $entity_query->fields('te', ['ttd_id', 'mid', 'wb_qid']);
        $or = $entity_query->orConditionGroup();
        if ($mids) {
          $or->condition('te.mid', $mids, 'IN');
        }
        if ($wb_qids) {
          $or->condition('te.wb_qid', $wb_qids, 'IN');
        }
        $entity_query->condition($or);

        foreach ($entity_query->execute()->fetchAll() as $row) {
          if (!empty($row->mid)) {
            $entity_ttd_by_mid[(string) $row->mid] = (string) $row->ttd_id;
          }
          if (!empty($row->wb_qid)) {
            $entity_ttd_by_wb_qid[(string) $row->wb_qid] = (string) $row->ttd_id;
          }
          if (!empty($row->ttd_id)) {
            $ttd_ids[] = (string) $row->ttd_id;
          }
        }
        $ttd_ids = array_values(array_unique($ttd_ids));
      }

      $term_id_by_ttd = [];
      if ($ttd_ids && $database->schema()->tableExists('taxonomy_term__field_ttd_id')) {
        $term_query = $database->select('taxonomy_term__field_ttd_id', 'ttid');
        $term_query->fields('ttid', ['entity_id', 'field_ttd_id_value']);
        $term_query->condition('ttid.field_ttd_id_value', $ttd_ids, 'IN');
        foreach ($term_query->execute()->fetchAll() as $row) {
          $term_id_by_ttd[(string) $row->field_ttd_id_value] = (int) $row->entity_id;
        }
      }

      $existing_tids = [];
      if ($node_id) {
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
        if ($node && $node->hasField('field_ttd_topics')) {
          $existing_tids = array_map('intval', array_column($node->get('field_ttd_topics')->getValue(), 'target_id'));
        }
      }

      foreach ($api_results as $result) {
        $exists = FALSE;
        $in_post = FALSE;
        $term_id = NULL;
        $ttd_id = !empty($result['ttd_id']) ? (string) $result['ttd_id'] : NULL;
        $mid = !empty($result['mid']) ? (string) $result['mid'] : '';
        $wb_qid = !empty($result['wb_qid']) ? (string) $result['wb_qid'] : '';

        if ($mid !== '' && isset($entity_ttd_by_mid[$mid])) {
          $exists = TRUE;
          $ttd_id = $ttd_id ?: $entity_ttd_by_mid[$mid];
        }
        elseif ($wb_qid !== '' && isset($entity_ttd_by_wb_qid[$wb_qid])) {
          $exists = TRUE;
          $ttd_id = $ttd_id ?: $entity_ttd_by_wb_qid[$wb_qid];
        }

        if ($ttd_id && isset($term_id_by_ttd[(string) $ttd_id])) {
          $term_id = $term_id_by_ttd[(string) $ttd_id];
          if ($term_id) {
            $exists = TRUE;
          }
        }

        if ($term_id) {
          $in_post = in_array((int) $term_id, $existing_tids, TRUE);
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
      $previous_tier = $tier_overrides[$override_key] ?? NULL;
      $topic = $this->getEditorialSignalTopic($term_id, $ttd_id);
      $auto_rechecked = FALSE;

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

      // Match WordPress: promoting a rejected topic to Main/About accepts it.
      if (in_array($new_tier, ['mainEntity', 'about'], TRUE) && $node->hasField('field_ttd_rejected_topics')) {
        $promoted_term_id = $term_id;
        if (!$promoted_term_id && $ttd_id) {
          $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
            'vid' => 'ttd_topics',
            'field_ttd_id' => (string) $ttd_id,
          ]);
          if (!empty($terms)) {
            $promoted_term_id = reset($terms)->id();
          }
        }

        if ($promoted_term_id) {
          $rejected_tids = array_map('intval', array_column($node->get('field_ttd_rejected_topics')->getValue(), 'target_id'));
          $auto_rechecked = in_array((int) $promoted_term_id, $rejected_tids, TRUE);
          $rejected_tids = array_values(array_diff($rejected_tids, [(int) $promoted_term_id]));
          $node->set('field_ttd_rejected_topics', array_map(static fn($tid) => ['target_id' => $tid], $rejected_tids));
        }
      }

      // Save to node
      $node->set('field_tier_overrides', ['value' => $tier_overrides]);
      $node->save();

      if ($topic && $previous_tier !== $new_tier) {
        $this->recordEditorialSignal('tier_change', $topic, [
          'postId' => $node_id,
          'fromTier' => $previous_tier,
          'toTier' => $new_tier,
        ]);
      }
      if ($topic && $auto_rechecked) {
        $this->recordEditorialSignal('recheck', $topic, [
          'postId' => $node_id,
          'metadata' => ['reason' => 'promoted_to_focus_tier'],
        ]);
      }

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
      $previous_tier = $tier_overrides[$override_key] ?? NULL;
      $topic = $this->getEditorialSignalTopic($term_id, $ttd_id);

      // Remove override
      if (isset($tier_overrides[$override_key])) {
        unset($tier_overrides[$override_key]);
      }

      // Save to node
      $node->set('field_tier_overrides', ['value' => $tier_overrides]);
      $node->save();

      if ($topic && $previous_tier) {
        $this->recordEditorialSignal('tier_change', $topic, [
          'postId' => $node_id,
          'fromTier' => $previous_tier,
          'toTier' => 'system',
          'metadata' => ['reason' => 'removed_tier_override'],
        ]);
      }

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
        $rejected_tids = array_map('intval', array_column($rejected_topics, 'target_id'));
        $topic_id_int = (int) $topic_id;
        $was_rejected = in_array($topic_id_int, $rejected_tids, TRUE);
        $signal_action = NULL;

        if (!$is_accepted && !in_array($topic_id_int, $rejected_tids, TRUE)) {
          // Reject topic
          $rejected_tids[] = $topic_id_int;
          $node->set('field_ttd_rejected_topics', $rejected_tids);
          $signal_action = 'uncheck';
        } elseif ($is_accepted && $was_rejected) {
          // Un-reject topic
          $rejected_tids = array_filter($rejected_tids, function($tid) use ($topic_id_int) {
            return (int) $tid !== $topic_id_int;
          });
          $node->set('field_ttd_rejected_topics', array_values($rejected_tids));
          $signal_action = 'recheck';
        }
      }

      $node->save();

      if (!empty($signal_action)) {
        $topic = $this->getEditorialSignalTopic($topic_id);
        if ($topic) {
          $this->recordEditorialSignal($signal_action, $topic, ['postId' => $node_id]);
        }
      }

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
   * Queue analysis for a single node from the editor.
   */
  public function runAnalysis(NodeInterface $node, Request $request) {
    try {
      $analysis_service = \Drupal::service('ttd_topics.analysis_service');
      $existing_request = $analysis_service->getSingleAnalysisRequest($node);

      if ($node->hasField('field_ttd_analysis_in_progress') && $node->get('field_ttd_analysis_in_progress')->value && !empty($existing_request['request_id'])) {
        $recovery = $analysis_service->recoverSingleAnalysis($node, $existing_request);
        if (($recovery['status'] ?? '') === 'completed') {
          return new JsonResponse([
            'success' => TRUE,
            'data' => [
              'message' => 'Analysis completed.',
              'node_id' => (int) $node->id(),
              'request_id' => $existing_request['request_id'],
              'changed' => $node->getChangedTime(),
              'completed' => TRUE,
              'reload' => TRUE,
            ],
          ]);
        }

        if (($recovery['status'] ?? '') === 'pending') {
          return new JsonResponse([
            'success' => TRUE,
            'data' => [
              'message' => 'Analysis is already in progress.',
              'node_id' => (int) $node->id(),
              'request_id' => $existing_request['request_id'],
              'changed' => $node->getChangedTime(),
            ],
          ]);
        }
      }
      elseif ($node->hasField('field_ttd_analysis_in_progress') && $node->get('field_ttd_analysis_in_progress')->value) {
        $recovery = $analysis_service->recoverSingleAnalysis($node, $existing_request);
        if (($recovery['status'] ?? '') === 'pending') {
          return new JsonResponse([
            'success' => TRUE,
            'data' => [
              'message' => 'Analysis is already in progress.',
              'node_id' => (int) $node->id(),
              'changed' => $node->getChangedTime(),
            ],
          ]);
        }
      }

      $analysis_service->markSingleAnalysisStarted($node);
      $request_id = $analysis_service->startSingleAnalysis($node);
      $analysis_service->markSingleAnalysisStarted($node, $request_id);

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'message' => 'Analysis started.',
          'node_id' => (int) $node->id(),
          'request_id' => $request_id,
          'changed' => $node->getChangedTime(),
        ],
      ]);
    }
    catch (\Exception $e) {
      if (isset($analysis_service)) {
        $analysis_service->clearSingleAnalysisState($node);
      }
      elseif ($node->hasField('field_ttd_analysis_in_progress')) {
        $node->set('field_ttd_analysis_in_progress', FALSE);
        $node->save();
      }

      \Drupal::logger('topicalboost')->error('Error starting analysis for node @nid: @message', [
        '@nid' => $node->id(),
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Error starting TopicalBoost analysis: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Helper to fetch demand metrics data.
   */
  private function getDemandMetricsData($term_id = NULL, $keyword = NULL, $force_refresh = 0) {
    $state = \Drupal::state();
    $cache_key = 'ttd_demand_' . ($term_id ?? md5($keyword));
    $cache_duration = 7 * 24 * 60 * 60; // 7 days
    $cached = $state->get($cache_key);
    $canonical_cached = NULL;
    if ($term_id && function_exists('ttd_get_demand_metrics')) {
      $canonical_cached = ttd_get_demand_metrics((int) $term_id);
    }
    elseif ($keyword && function_exists('ttd_get_demand_metrics')) {
      $canonical_cached = ttd_get_demand_metrics($keyword);
    }

    $request_cached_data = (!empty($cached['data']) && is_array($cached['data'])) ? $cached['data'] : NULL;
    $has_valid_request_cache = $this->hasValidDemandMetricsCache($request_cached_data);
    $has_valid_canonical_cache = $this->hasValidDemandMetricsCache($canonical_cached);

    // Check cache unless force refresh.
    if (!$force_refresh) {
      if ($has_valid_request_cache && isset($cached['timestamp']) && (time() - $cached['timestamp']) < $cache_duration) {
        return $request_cached_data;
      }
      if ($has_valid_canonical_cache) {
        return $canonical_cached;
      }
    }

    $cooldown_until = (int) $state->get('ttd_demand_metrics_api_cooldown_until', 0);
    if ($cooldown_until > time()) {
      $retry_after = max(1, $cooldown_until - time());
      if ($has_valid_canonical_cache) {
        return $this->markDemandMetricsUnavailable($canonical_cached, $retry_after);
      }
      if ($has_valid_request_cache) {
        return $this->markDemandMetricsUnavailable($request_cached_data, $retry_after);
      }
      return [
        'api_unavailable' => TRUE,
        'cooldown' => TRUE,
        'retry_after_seconds' => $retry_after,
      ];
    }

    $ttd_id = NULL;

    // Get keyword and TopicalBoost entity ID from term if available.
    if ($term_id) {
      $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($term_id);
      if ($term) {
        if (!$keyword) {
          $keyword = $term->getName();
        }
        if ($term->hasField('field_ttd_id') && !$term->get('field_ttd_id')->isEmpty()) {
          $ttd_id = (int) $term->get('field_ttd_id')->value;
        }
      }
    }

    if (!$ttd_id && !$keyword) {
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
      $path = $ttd_id
        ? '/demand/entity/' . rawurlencode((string) $ttd_id)
        : '/demand/keyword/' . rawurlencode($keyword);
      $response = $client->request('GET', TOPICALBOOST_API_ENDPOINT . $path, [
        'headers' => [
          'X-API-Key' => $api_key,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 8,
        'connect_timeout' => 3,
      ]);

      $data = json_decode($response->getBody(), TRUE);
      $metrics = [
        'keyword' => $data['keyword'] ?? $keyword,
        'keyword_difficulty' => $data['keyword_difficulty'] ?? 0,
        'search_volume' => $data['search_volume'] ?? 0,
        'traffic_potential' => $data['traffic_potential'] ?? 0,
        'traffic_potential_value' => $data['traffic_potential_value'] ?? 0,
      ];

      // Cache the result for this request path.
      $state->set($cache_key, [
        'timestamp' => time(),
        'data' => $metrics,
      ]);

      // Also persist to the canonical demand cache used during page rendering.
      // Without this, a badge fetched by clicking in the editor disappears on
      // refresh because ttd_render_kd_badge() reads ttd_get_demand_metrics().
      if ($term_id && function_exists('ttd_store_demand_metrics')) {
        ttd_store_demand_metrics((int) $term_id, $metrics);
      }
      elseif ($keyword && function_exists('ttd_store_demand_metrics')) {
        ttd_store_demand_metrics($keyword, $metrics);
      }

      return $metrics;

    } catch (\Exception $e) {
      $retry_after = $this->getDemandMetricsRetryAfter($e);
      if ($retry_after !== NULL) {
        $state->set('ttd_demand_metrics_api_cooldown_until', time() + $retry_after);
        \Drupal::logger('ttd_topics')->notice('Demand metrics temporarily unavailable; retry after @seconds seconds.', [
          '@seconds' => $retry_after,
        ]);
        if ($has_valid_canonical_cache) {
          return $this->markDemandMetricsUnavailable($canonical_cached, $retry_after);
        }
        if ($has_valid_request_cache) {
          return $this->markDemandMetricsUnavailable($request_cached_data, $retry_after);
        }
        return [
          'api_unavailable' => TRUE,
          'cooldown' => TRUE,
          'retry_after_seconds' => $retry_after,
        ];
      }

      \Drupal::logger('ttd_topics')->error('API demand metrics error: @message', ['@message' => $e->getMessage()]);
      if ($has_valid_request_cache) {
        return $request_cached_data;
      }
      if ($has_valid_canonical_cache) {
        return $canonical_cached;
      }
      return NULL;
    }
  }

  /**
   * Determines whether a cached demand metric entry is usable.
   */
  private function hasValidDemandMetricsCache($metrics): bool {
    if (!is_array($metrics) || !array_key_exists('traffic_potential', $metrics)) {
      return FALSE;
    }

    // Match WordPress: old/anomalous cache with traffic_potential=0 but
    // positive search_volume should be refetched instead of trusted.
    return !((int) ($metrics['traffic_potential'] ?? 0) === 0
      && !empty($metrics['search_volume'])
      && (int) $metrics['search_volume'] > 0);
  }

  /**
   * Annotates cached demand metrics returned during an API cooldown.
   */
  private function markDemandMetricsUnavailable(array $metrics, int $retry_after): array {
    $metrics['stale'] = TRUE;
    $metrics['api_unavailable'] = TRUE;
    $metrics['cooldown'] = TRUE;
    $metrics['retry_after_seconds'] = $retry_after;
    return $metrics;
  }

  /**
   * Extracts retry-after seconds from the expected demand metrics 503 response.
   */
  private function getDemandMetricsRetryAfter(\Exception $e): ?int {
    if (!$e instanceof \GuzzleHttp\Exception\RequestException || !$e->hasResponse()) {
      return NULL;
    }

    $response = $e->getResponse();
    if ((int) $response->getStatusCode() !== 503) {
      return NULL;
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)
      || ($data['error'] ?? '') !== 'External API error'
      || ($data['message'] ?? '') !== 'SEO metrics temporarily unavailable') {
      return NULL;
    }

    return max(1, (int) ($data['retry_after_seconds'] ?? self::DEMAND_METRICS_COOLDOWN_SECONDS));
  }

  /**
   * Renders the New Topics page.
   */
  public function newTopicsPage() {
    $html = '<div class="ttd-new-topics-wrap">';
    $html .= '<p class="ttd-new-topics-description">Recently discovered topics from content analysis. Set visibility overrides before they appear on the frontend.</p>';

    $html .= '<div class="ttd-new-topics-filters">';
    $html .= '<button class="ttd-new-topics-filter-btn" data-days="7">7 days</button>';
    $html .= '<button class="ttd-new-topics-filter-btn" data-days="14">14 days</button>';
    $html .= '<button class="ttd-new-topics-filter-btn is-active" data-days="30">30 days</button>';
    $html .= '<button class="ttd-new-topics-filter-btn" data-days="90">90 days</button>';
    $html .= '</div>';

    $html .= '<div class="ttd-new-topics-loading" style="display:none;">Loading topics...</div>';
    $html .= '<div class="ttd-new-topics-empty" style="display:none;">No new topics found in this time period.</div>';

    $html .= '<table class="ttd-new-topics-table" style="display:none;">';
    $html .= '<thead><tr><th>Name</th><th>Created</th><th>Post Count</th><th>Schema Types</th><th>Hide</th><th>Force Show</th></tr></thead>';
    $html .= '<tbody id="ttd-new-topics-body"></tbody>';
    $html .= '</table>';
    $html .= '<div class="ttd-new-topics-pagination" style="display:none;"></div>';

    $html .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => Markup::create($html),
      '#attached' => [
        'library' => ['ttd_topics/new_topics'],
      ],
    ];
  }

  /**
   * AJAX endpoint: Get new topics filtered by date range.
   */
  public function getNewTopics(Request $request) {
    $days = (int) $request->query->get('days', 30);
    if ($days < 1) {
      $days = 30;
    }
    $page = max(1, (int) $request->query->get('page', 1));
    $per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
    $offset = ($page - 1) * $per_page;

    $database = \Drupal::database();
    $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

    try {
      $total_query = $database->select('ttd_entities', 'e');
      $total_query->condition('e.createdAt', $cutoff, '>=');
      $total = (int) $total_query->countQuery()->execute()->fetchField();

      $query = $database->select('ttd_entities', 'e');
      $query->fields('e', ['ttd_id', 'name', 'createdAt']);
      $query->condition('e.createdAt', $cutoff, '>=');
      $query->leftJoin('ttd_entity_post_ids', 'ep', 'e.ttd_id = ep.entity_id');
      $query->addExpression('COUNT(DISTINCT ep.post_id)', 'post_count');
      $query->groupBy('e.ttd_id');
      $query->groupBy('e.name');
      $query->groupBy('e.createdAt');
      $query->orderBy('e.createdAt', 'DESC');
      $query->range($offset, $per_page);

      $results = $query->execute()->fetchAll();

      if (empty($results)) {
        return new JsonResponse([
          'topics' => [],
          'pagination' => [
            'total' => $total,
            'total_pages' => 0,
            'current_page' => $page,
          ],
        ]);
      }

      $ttd_ids = array_map(static function ($row) {
        return (string) $row->ttd_id;
      }, $results);

      $term_map = [];
      if (!empty($ttd_ids)) {
        $term_rows = $database->select('taxonomy_term__field_ttd_id', 'fi')
          ->fields('fi', ['entity_id', 'field_ttd_id_value'])
          ->condition('fi.field_ttd_id_value', $ttd_ids, 'IN')
          ->execute()
          ->fetchAll();

        foreach ($term_rows as $term_row) {
          $term_map[(int) $term_row->field_ttd_id_value] = [
            'tid' => (int) $term_row->entity_id,
            'is_hidden' => FALSE,
            'force_show' => FALSE,
          ];
        }
      }

      $tids = array_filter(array_column($term_map, 'tid'));
      if (!empty($tids)) {
        $hide_rows = $database->select('taxonomy_term__field_hide', 'tfh')
          ->fields('tfh', ['entity_id', 'field_hide_value'])
          ->condition('tfh.entity_id', $tids, 'IN')
          ->execute()
          ->fetchAllKeyed();

        $force_rows = $database->select('taxonomy_term__field_force_show', 'tfs')
          ->fields('tfs', ['entity_id', 'field_force_show_value'])
          ->condition('tfs.entity_id', $tids, 'IN')
          ->execute()
          ->fetchAllKeyed();

        foreach ($term_map as $ttd_id => $term_data) {
          $tid = $term_data['tid'];
          $term_map[$ttd_id]['is_hidden'] = !empty($hide_rows[$tid]);
          $term_map[$ttd_id]['force_show'] = !empty($force_rows[$tid]);
        }
      }

      $schema_type_map = [];
      if (!empty($ttd_ids)) {
        $schema_rows = $database->query(
          "SELECT est.entity_id, st.name
           FROM {ttd_entity_schema_types} est
           INNER JOIN {ttd_schema_types} st ON est.schema_type_id = st.ttd_id
           WHERE est.entity_id IN (:ids[])",
          [':ids[]' => $ttd_ids]
        )->fetchAll();

        foreach ($schema_rows as $schema_row) {
          $schema_type_map[(int) $schema_row->entity_id][] = $schema_row->name;
        }
      }

      $topics = [];
      foreach ($results as $row) {
        $ttd_id = (int) $row->ttd_id;
        $term_data = $term_map[$ttd_id] ?? NULL;

        $topics[] = [
          'tid' => $term_data['tid'] ?? NULL,
          'ttd_id' => $ttd_id,
          'name' => $row->name,
          'created' => date('M j, Y', strtotime($row->createdAt)),
          'post_count' => (int) $row->post_count,
          'schema_types' => $schema_type_map[$ttd_id] ?? [],
          'is_hidden' => (bool) ($term_data['is_hidden'] ?? FALSE),
          'force_show' => (bool) ($term_data['force_show'] ?? FALSE),
        ];
      }

      return new JsonResponse([
        'topics' => $topics,
        'pagination' => [
          'total' => $total,
          'total_pages' => (int) ceil($total / $per_page),
          'current_page' => $page,
        ],
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('New topics endpoint error: @message', [
        '@message' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'topics' => [],
        'pagination' => [
          'total' => 0,
          'total_pages' => 0,
          'current_page' => $page,
        ],
        'error' => 'Unable to load new topics.',
      ], 500);
    }
  }

  /**
   * AJAX endpoint: Toggle force_show on a topic term.
   */
  public function toggleForceShow($taxonomy_term, Request $request) {
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($taxonomy_term);

    if (!$term || $term->bundle() !== 'ttd_topics') {
      return new JsonResponse(['success' => FALSE, 'message' => 'Term not found'], 404);
    }

    if (!$term->hasField('field_force_show')) {
      return new JsonResponse(['success' => FALSE, 'message' => 'field_force_show not available'], 400);
    }

    $content = json_decode($request->getContent(), TRUE);
    $force_show = !empty($content['force_show']);
    $term->set('field_force_show', $force_show);
    $term->save();

    $topic = $this->getEditorialSignalTopic($term->id());
    if ($topic) {
      $this->recordEditorialSignal($force_show ? 'force_show' : 'force_unshow', $topic);
    }

    return new JsonResponse([
      'success' => TRUE,
      'force_show' => $force_show,
    ]);
  }

  /**
   * Proxy changelog request to the TopicalBoost API.
   */
  public function getChangelog() {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return new JsonResponse(['error' => 'API key not configured'], 400);
    }

    try {
      $client = \Drupal::httpClient();
      $response = $client->request('GET', TOPICALBOOST_API_ENDPOINT . '/changelog', [
        'query' => [
          'product' => 'drupal',
          'limit' => 20,
        ],
        'headers' => [
          'x-api-key' => $api_key,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 10,
      ]);

      $data = json_decode($response->getBody(), TRUE);
      return new JsonResponse($data);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Unable to fetch changelog'], 500);
    }
  }

  /**
   * Create a topic taxonomy term from entity data.
   *
   * Accepts entity data (name, ttd_id, kg_name, wb_name, etc.), upserts into
   * ttd_entities table, then creates or reuses a taxonomy term linked to it.
   */
  public function createTopicTerm(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $topic_data = $content['topic_data'] ?? NULL;
    $node_id = (int) ($content['post_id'] ?? 0);
    $add_to_post = !empty($content['add_to_post']);

    if (!$topic_data) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing topic data'],
      ], 400);
    }

    // Resolve name.
    $name = $topic_data['name'] ?? $topic_data['kg_name'] ?? $topic_data['wb_name'] ?? NULL;
    $ttd_id = (int) ($topic_data['ttd_id'] ?? 0);

    if (!$name || !$ttd_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing required topic data (name and ttd_id)'],
      ], 400);
    }

    $database = \Drupal::database();

    try {
      // Upsert into ttd_entities table.
      $existing = $database->select('ttd_entities', 'e')
        ->fields('e', ['ttd_id'])
        ->condition('e.ttd_id', $ttd_id)
        ->execute()
        ->fetchField();

      if (!$existing) {
        $fields = [
          'ttd_id' => $ttd_id,
          'name' => $topic_data['name'] ?? NULL,
          'mid' => $topic_data['mid'] ?? NULL,
          'kg_name' => $topic_data['kg_name'] ?? NULL,
          'kg_image' => $topic_data['kg_image'] ?? NULL,
          'nl_name' => $topic_data['nl_name'] ?? NULL,
          'nl_type' => $topic_data['nl_type'] ?? NULL,
          'wb_qid' => $topic_data['wb_qid'] ?? NULL,
          'wb_name' => $topic_data['wb_name'] ?? NULL,
          'wb_description' => $topic_data['wb_description'] ?? NULL,
          'wb_image' => $topic_data['wb_image'] ?? NULL,
          'wikipedia_url' => $topic_data['wikipedia_url'] ?? NULL,
        ];
        $database->insert('ttd_entities')->fields($fields)->execute();
      }

      // Find or create taxonomy term.
      $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

      // Look up by field_ttd_id.
      $terms = $term_storage->loadByProperties([
        'vid' => 'ttd_topics',
        'field_ttd_id' => (string) $ttd_id,
      ]);

      if (!empty($terms)) {
        $term = reset($terms);
        $term_id = $term->id();
      }
      else {
        // Check by name to avoid duplicates.
        $terms_by_name = $term_storage->loadByProperties([
          'vid' => 'ttd_topics',
          'name' => $name,
        ]);

        if (!empty($terms_by_name)) {
          $term = reset($terms_by_name);
          $term_id = $term->id();
          // Link it if not already.
          if ($term->hasField('field_ttd_id') && empty($term->get('field_ttd_id')->value)) {
            $term->set('field_ttd_id', (string) $ttd_id);
            $term->save();
          }
        }
        else {
          // Create new term.
          $term = $term_storage->create([
            'vid' => 'ttd_topics',
            'name' => $name,
            'description' => ['value' => $topic_data['wb_description'] ?? '', 'format' => 'plain_text'],
            'field_ttd_id' => (string) $ttd_id,
          ]);
          $term->save();
          $term_id = $term->id();
        }
      }

      // Optionally add to node.
      if ($add_to_post && $node_id) {
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($node_id);
        if ($node && $node->hasField('field_ttd_topics')) {
          $current_values = $node->get('field_ttd_topics')->getValue();
          $already_assigned = FALSE;
          foreach ($current_values as $val) {
            if ((int) $val['target_id'] === (int) $term_id) {
              $already_assigned = TRUE;
              break;
            }
          }
          if (!$already_assigned) {
            $current_values[] = ['target_id' => $term_id];
            $node->set('field_ttd_topics', $current_values);
            $node->save();
          }
        }
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'term_id' => $term_id,
          'ttd_id' => $ttd_id,
          'name' => $name,
        ],
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Create topic term error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => $e->getMessage()],
      ], 500);
    }
  }

  /**
   * Look up a taxonomy term by TTD ID, auto-create if missing.
   */
  public function getTermByTtdId(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $ttd_id = (int) ($content['ttd_id'] ?? 0);

    if (!$ttd_id) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Missing TTD ID'],
      ], 400);
    }

    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

    // Look up by field_ttd_id.
    $terms = $term_storage->loadByProperties([
      'vid' => 'ttd_topics',
      'field_ttd_id' => (string) $ttd_id,
    ]);

    if (!empty($terms)) {
      $term = reset($terms);
      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'term_id' => $term->id(),
          'name' => $term->getName(),
        ],
      ]);
    }

    // Not found — auto-create from entity record.
    $database = \Drupal::database();
    try {
      $entity = $database->select('ttd_entities', 'e')
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
        'data' => ['message' => 'Term not found for TTD ID: ' . $ttd_id],
      ], 404);
    }

    $name = $entity['kg_name'] ?: ($entity['wb_name'] ?: ($entity['name'] ?: 'Unnamed Entity'));
    $description = $entity['wb_description'] ?? '';

    try {
      // Check if a term with this name already exists.
      $existing = $term_storage->loadByProperties([
        'vid' => 'ttd_topics',
        'name' => $name,
      ]);

      if (!empty($existing)) {
        $term = reset($existing);
        // Link it.
        if ($term->hasField('field_ttd_id')) {
          $term->set('field_ttd_id', (string) $ttd_id);
          $term->save();
        }
      }
      else {
        $term = $term_storage->create([
          'vid' => 'ttd_topics',
          'name' => $name,
          'description' => ['value' => $description, 'format' => 'plain_text'],
          'field_ttd_id' => (string) $ttd_id,
        ]);
        $term->save();
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'term_id' => $term->id(),
          'name' => $term->getName(),
        ],
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
   * Get meta generation settings from the API.
   */
  public function getMetaSettings() {
    $config = \Drupal::config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');

    if (empty($api_key)) {
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'API key not configured'],
      ], 400);
    }

    try {
      $client = \Drupal::httpClient();
      $response = $client->request('GET', TOPICALBOOST_API_ENDPOINT . '/meta/settings', [
        'headers' => [
          'x-api-key' => $api_key,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 15,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      return new JsonResponse([
        'success' => TRUE,
        'data' => $data,
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Meta settings API error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'data' => ['message' => 'Failed to fetch meta settings from API'],
      ], 500);
    }
  }

}
