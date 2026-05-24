<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Queue status page showing Advanced Queue job status.
 */
class QueueStatusController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a QueueStatusController.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Renders the queue status page.
   */
  public function page(Request $request) {
    $current_status = $request->query->get('status', 'all');
    $current_page = max(1, (int) $request->query->get('page', 1));
    $per_page = 50;

    // Get status counts.
    $status_counts = ['all' => 0];
    $count_results = $this->database->query(
      "SELECT state, COUNT(*) as cnt FROM {advancedqueue} WHERE queue_id = :queue GROUP BY state",
      [':queue' => 'ttd_topics_analysis']
    )->fetchAll();

    $state_labels = [
      'queued' => 'Queued',
      'processing' => 'Processing',
      'success' => 'Completed',
      'failure' => 'Failed',
    ];

    foreach ($count_results as $row) {
      $label = $state_labels[$row->state] ?? ucfirst($row->state);
      $status_counts[$row->state] = (int) $row->cnt;
      $status_counts['all'] += (int) $row->cnt;
    }

    // Query jobs with filtering.
    $query = $this->database->select('advancedqueue', 'aq')
      ->fields('aq')
      ->condition('aq.queue_id', 'ttd_topics_analysis')
      ->orderBy('aq.job_id', 'DESC');

    if ($current_status !== 'all' && array_key_exists($current_status, $state_labels)) {
      $query->condition('aq.state', $current_status);
    }

    // Pagination.
    $total_count = $status_counts[$current_status] ?? $status_counts['all'];
    $total_pages = max(1, ceil($total_count / $per_page));
    $offset = ($current_page - 1) * $per_page;
    $query->range($offset, $per_page);

    $jobs = $query->execute()->fetchAll();

    // Build HTML.
    $html = '<div class="ttd-queue-status-wrap">';
    $html .= '<p class="description">Shows analysis jobs from the Advanced Queue.</p>';

    if (empty($status_counts['all'])) {
      $html .= '<p>No jobs currently in the queue.</p>';
      $html .= '</div>';
      return [
        '#type' => 'markup',
        '#markup' => $html,
        '#attached' => ['library' => ['ttd_topics/queue_status']],
      ];
    }

    // Status filters.
    $html .= '<div class="ttd-queue-filters">';
    $html .= '<ul class="ttd-queue-filter-list">';
    $all_filters = array_merge(['all' => 'All'], $state_labels);
    $filter_items = [];
    foreach ($all_filters as $key => $label) {
      $count = $status_counts[$key] ?? 0;
      if ($key !== 'all' && $count === 0) {
        continue;
      }
      $active = ($current_status === $key) ? ' class="is-active"' : '';
      $url = '?status=' . ($key === 'all' ? 'all' : $key);
      $filter_items[] = '<li><a href="' . $url . '"' . $active . '>' . $label . ' <span class="count">(' . $count . ')</span></a></li>';
    }
    $html .= implode(' <li class="separator">|</li> ', $filter_items);
    $html .= '</ul></div>';

    // Jobs table.
    $html .= '<table class="ttd-queue-table">';
    $html .= '<thead><tr>';
    $html .= '<th>Type</th><th>Status</th><th>Content</th><th>Scheduled</th><th>Processed</th>';
    $html .= '</tr></thead><tbody>';

    foreach ($jobs as $job) {
      $payload = json_decode($job->payload, TRUE) ?: [];
      $state_class = 'status-' . $job->state;
      $state_label = $state_labels[$job->state] ?? ucfirst($job->state);

      // Get node info from payload.
      $node_info = 'N/A';
      $nid = $payload['nid'] ?? $payload['post_id'] ?? NULL;
      if ($nid) {
        $node = $this->database->query(
          "SELECT nid, title FROM {node_field_data} WHERE nid = :nid",
          [':nid' => $nid]
        )->fetchObject();
        if ($node) {
          $edit_url = \Drupal\Core\Url::fromRoute('entity.node.edit_form', ['node' => $node->nid])->toString();
          $node_info = '<a href="' . htmlspecialchars($edit_url) . '" target="_blank">'
            . htmlspecialchars($node->title)
            . ' <small>(ID: ' . (int) $node->nid . ')</small></a>';
        }
        else {
          $node_info = 'Node ID: ' . (int) $nid . ' (deleted)';
        }
      }

      $available = $job->available ? date('Y-m-d H:i:s', $job->available) : '&mdash;';
      $processed = $job->processed ? date('Y-m-d H:i:s', $job->processed) : '&mdash;';

      $html .= '<tr>';
      $html .= '<td>' . htmlspecialchars($job->type) . '</td>';
      $html .= '<td><span class="ttd-queue-status ' . $state_class . '">' . $state_label . '</span></td>';
      $html .= '<td>' . $node_info . '</td>';
      $html .= '<td>' . $available . '</td>';
      $html .= '<td>' . $processed . '</td>';
      $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    // Pagination.
    if ($total_pages > 1) {
      $html .= '<div class="ttd-queue-pagination">';
      for ($i = 1; $i <= $total_pages; $i++) {
        $active = ($i === $current_page) ? ' class="is-active"' : '';
        $html .= '<a href="?status=' . htmlspecialchars($current_status) . '&page=' . $i . '"' . $active . '>' . $i . '</a> ';
      }
      $html .= '</div>';
    }

    $html .= '</div>';

    return [
      '#type' => 'markup',
      '#markup' => $html,
      '#attached' => ['library' => ['ttd_topics/queue_status']],
    ];
  }

}
