<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Health check diagnostic dashboard for TopicalBoost.
 */
class HealthCheckController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a HealthCheckController.
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
   * Renders the health check page.
   */
  public function page() {
    $sections = [
      'environment' => [
        'title' => 'Environment',
        'checks' => $this->checkEnvironment(),
      ],
      'api' => [
        'title' => 'API Connection',
        'checks' => $this->checkApiConnection(),
      ],
      'database' => [
        'title' => 'Database',
        'checks' => $this->checkDatabase(),
      ],
      'taxonomy' => [
        'title' => 'Taxonomy',
        'checks' => $this->checkTaxonomy(),
      ],
      'content' => [
        'title' => 'Content Coverage',
        'checks' => $this->checkContentCoverage(),
      ],
      'seo_meta' => [
        'title' => 'SEO Meta',
        'checks' => $this->checkSeoMeta(),
      ],
    ];

    // Separate issues from passing checks.
    $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];
    $issue_sections = [];
    $clean_sections = [];

    foreach ($sections as $key => $section) {
      $has_issues = FALSE;
      foreach ($section['checks'] as $check) {
        if (isset($summary[$check['status']])) {
          $summary[$check['status']]++;
        }
        if ($check['status'] !== 'pass') {
          $has_issues = TRUE;
        }
      }
      if ($has_issues) {
        $issue_sections[$key] = $section;
      }
      else {
        $clean_sections[$key] = $section;
      }
    }

    $total_issues = $summary['warn'] + $summary['fail'];

    // Build render array.
    $build = [
      '#type' => 'markup',
      '#markup' => $this->buildHealthHtml($summary, $total_issues, $issue_sections, $clean_sections),
      '#attached' => [
        'library' => ['ttd_topics/health_checks'],
      ],
    ];

    return $build;
  }

  /**
   * Builds the health check HTML output.
   */
  protected function buildHealthHtml(array $summary, int $total_issues, array $issue_sections, array $clean_sections): string {
    $html = '<div class="ttd-health-wrap">';

    // Summary bar.
    $html .= '<div class="ttd-health-summary">';
    if ($total_issues === 0) {
      $html .= '<div class="ttd-health-summary-item">';
      $html .= '<span class="ttd-health-icon ttd-health-icon--pass">&#10003;</span> ';
      $html .= 'All ' . (int) $summary['pass'] . ' checks passed';
      $html .= '</div>';
    }
    else {
      if ($summary['fail'] > 0) {
        $html .= '<div class="ttd-health-summary-item">';
        $html .= '<span class="ttd-health-summary-count ttd-health-summary-count--fail">' . (int) $summary['fail'] . '</span> ';
        $html .= ($summary['fail'] === 1 ? 'Failure' : 'Failures');
        $html .= '</div>';
      }
      if ($summary['warn'] > 0) {
        $html .= '<div class="ttd-health-summary-item">';
        $html .= '<span class="ttd-health-summary-count ttd-health-summary-count--warn">' . (int) $summary['warn'] . '</span> ';
        $html .= ($summary['warn'] === 1 ? 'Warning' : 'Warnings');
        $html .= '</div>';
      }
      $html .= '<div class="ttd-health-summary-item ttd-health-summary-item--muted">';
      $html .= '<span class="ttd-health-summary-count ttd-health-summary-count--pass">' . (int) $summary['pass'] . '</span> ';
      $html .= 'Passed';
      $html .= '</div>';
    }
    $html .= '</div>';

    // Health grid.
    $html .= '<div class="ttd-health-grid">';

    // Issue sections - fully expanded.
    foreach ($issue_sections as $section) {
      $issues = array_filter($section['checks'], function ($c) {
        return $c['status'] !== 'pass';
      });
      $pass_count = count($section['checks']) - count($issues);

      $html .= '<div class="ttd-health-section ttd-health-section--issues">';
      $html .= '<h2>' . htmlspecialchars($section['title']);
      if ($pass_count > 0) {
        $html .= ' <span class="ttd-health-section-pass-count">' . $pass_count . ' passed</span>';
      }
      $html .= '</h2>';

      $html .= '<ul class="ttd-health-checks">';
      foreach ($issues as $check) {
        $icon = $check['status'] === 'fail' ? '&#10007;' : '&#9888;';
        $html .= '<li class="ttd-health-check">';
        $html .= '<div class="ttd-health-indicator ttd-health-indicator--' . $check['status'] . '">';
        $html .= '<span class="ttd-health-icon">' . $icon . '</span>';
        $html .= '</div>';
        $html .= '<div class="ttd-health-body">';
        $html .= '<p class="ttd-health-label">' . htmlspecialchars($check['label']) . '</p>';
        $html .= '<p class="ttd-health-value">' . htmlspecialchars($check['value']) . '</p>';
        if (!empty($check['detail'])) {
          $html .= '<p class="ttd-health-detail">' . htmlspecialchars($check['detail']) . '</p>';
        }
        if (!empty($check['resolution'])) {
          $html .= '<p class="ttd-health-resolution">' . htmlspecialchars($check['resolution']) . '</p>';
        }
        $html .= '</div></li>';
      }
      $html .= '</ul>';

      // Collapsed passing checks.
      if ($pass_count > 0) {
        $html .= '<details class="ttd-health-passed-details">';
        $html .= '<summary class="ttd-health-passed-toggle">' . $pass_count . ' passing ' . ($pass_count === 1 ? 'check' : 'checks') . '</summary>';
        $html .= '<ul class="ttd-health-checks ttd-health-checks--muted">';
        foreach ($section['checks'] as $check) {
          if ($check['status'] !== 'pass') {
            continue;
          }
          $html .= '<li class="ttd-health-check">';
          $html .= '<div class="ttd-health-indicator ttd-health-indicator--pass"><span class="ttd-health-icon">&#10003;</span></div>';
          $html .= '<div class="ttd-health-body">';
          $html .= '<p class="ttd-health-label">' . htmlspecialchars($check['label']) . '</p>';
          $html .= '<p class="ttd-health-value">' . htmlspecialchars($check['value']) . '</p>';
          if (!empty($check['detail'])) {
            $html .= '<p class="ttd-health-detail">' . htmlspecialchars($check['detail']) . '</p>';
          }
          $html .= '</div></li>';
        }
        $html .= '</ul></details>';
      }

      $html .= '</div>';
    }

    // Clean sections - collapsed by default.
    foreach ($clean_sections as $section) {
      $check_count = count($section['checks']);
      $html .= '<details class="ttd-health-section ttd-health-section--clean">';
      $html .= '<summary class="ttd-health-section-header">';
      $html .= '<span class="ttd-health-icon ttd-health-icon--pass">&#10003;</span> ';
      $html .= htmlspecialchars($section['title']);
      $html .= ' <span class="ttd-health-section-pass-count">' . $check_count . '/' . $check_count . ' passed</span>';
      $html .= '</summary>';
      $html .= '<ul class="ttd-health-checks ttd-health-checks--muted">';
      foreach ($section['checks'] as $check) {
        $html .= '<li class="ttd-health-check">';
        $html .= '<div class="ttd-health-indicator ttd-health-indicator--pass"><span class="ttd-health-icon">&#10003;</span></div>';
        $html .= '<div class="ttd-health-body">';
        $html .= '<p class="ttd-health-label">' . htmlspecialchars($check['label']) . '</p>';
        $html .= '<p class="ttd-health-value">' . htmlspecialchars($check['value']) . '</p>';
        if (!empty($check['detail'])) {
          $html .= '<p class="ttd-health-detail">' . htmlspecialchars($check['detail']) . '</p>';
        }
        $html .= '</div></li>';
      }
      $html .= '</ul></details>';
    }

    $html .= '</div></div>';
    return $html;
  }

  /**
   * Environment checks.
   */
  protected function checkEnvironment(): array {
    $checks = [];

    // PHP version.
    $php = phpversion();
    $checks[] = [
      'status' => version_compare($php, '8.1', '>=') ? 'pass' : 'warn',
      'label' => 'PHP Version',
      'value' => $php,
      'detail' => version_compare($php, '8.1', '<') ? 'PHP 8.1+ recommended for Drupal 10+' : NULL,
    ];

    // Drupal version.
    $drupal = \Drupal::VERSION;
    $checks[] = [
      'status' => version_compare($drupal, '10.0', '>=') ? 'pass' : 'warn',
      'label' => 'Drupal Version',
      'value' => $drupal,
    ];

    // Module version.
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('ttd_topics');
    $checks[] = [
      'status' => 'pass',
      'label' => 'Module Version',
      'value' => $info['version'] ?? 'dev',
    ];

    // SEO module detection.
    $seo = $this->detectSeoModule();
    $checks[] = [
      'status' => $seo['slug'] === 'none' ? 'warn' : 'pass',
      'label' => 'SEO Module',
      'value' => $seo['name'],
      'detail' => $seo['slug'] === 'none' ? 'No SEO module detected. Generated meta will be saved but may not render on the frontend.' : NULL,
    ];

    // API endpoint.
    $checks[] = [
      'status' => 'pass',
      'label' => 'API Endpoint',
      'value' => defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'not set',
    ];

    return $checks;
  }

  /**
   * API connection checks.
   */
  protected function checkApiConnection(): array {
    $checks = [];
    $config = $this->config('ttd_topics.settings');

    // API key.
    $api_key = $config->get('topicalboost_api_key') ?: '';
    $checks[] = [
      'status' => !empty($api_key) ? 'pass' : 'fail',
      'label' => 'API Key',
      'value' => !empty($api_key) ? 'Set (' . substr($api_key, 0, 4) . '...)' : 'Not set',
      'detail' => empty($api_key) ? 'Module cannot function without an API key' : NULL,
    ];

    // Live API key validation.
    $sub_from_api = NULL;
    if (!empty($api_key)) {
      $endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
      try {
        $client = \Drupal::httpClient();
        $response = $client->post($endpoint . '/validate-api-key', [
          'json' => [
            'api_key' => $api_key,
            'site_url' => \Drupal::request()->getSchemeAndHttpHost(),
          ],
          'timeout' => 3,
        ]);

        $body = json_decode($response->getBody()->getContents(), TRUE);
        $valid = is_array($body) && !empty($body['valid']);
        $status_code = $response->getStatusCode();
        $sub_from_api = is_array($body) && !empty($body['subscription_status'])
          ? strtolower($body['subscription_status'])
          : NULL;

        $checks[] = [
          'status' => ($status_code === 200 && $valid) ? 'pass' : 'fail',
          'label' => 'API Key Valid (live)',
          'value' => ($status_code === 200 && $valid) ? 'Yes' : 'No (HTTP ' . $status_code . ')',
          'detail' => (!$valid && is_array($body) && !empty($body['message']))
            ? htmlspecialchars($body['message'])
            : NULL,
        ];

        if ($sub_from_api) {
          $checks[] = [
            'status' => in_array($sub_from_api, ['active', 'retainer'], TRUE) ? 'pass' : 'warn',
            'label' => 'Subscription Status',
            'value' => strtoupper($sub_from_api),
          ];
        }
      }
      catch (\Exception $e) {
        $checks[] = [
          'status' => 'warn',
          'label' => 'API Reachable',
          'value' => 'Connection failed',
          'detail' => htmlspecialchars($e->getMessage()),
          'resolution' => 'The server could not connect to the TopicalBoost API. Check that outbound HTTPS requests are allowed.',
        ];
      }
    }

    // Beta channel.
    $beta = $config->get('beta_channel');
    $checks[] = [
      'status' => 'pass',
      'label' => 'Beta Channel',
      'value' => $beta ? 'Enabled' : 'Disabled',
    ];

    return $checks;
  }

  /**
   * Database checks.
   */
  protected function checkDatabase(): array {
    $checks = [];

    // Table existence and row counts.
    $tables = [
      'ttd_entities',
      'ttd_schema_types',
      'ttd_wb_categories',
      'ttd_entity_post_ids',
      'ttd_entity_schema_types',
      'ttd_entity_wb_categories',
    ];

    $entity_post_table_exists = FALSE;
    foreach ($tables as $table) {
      $exists = $this->database->schema()->tableExists($table);
      if ($exists) {
        if ($table === 'ttd_entity_post_ids') {
          $entity_post_table_exists = TRUE;
        }
        $count = (int) $this->database->query("SELECT COUNT(*) FROM {" . $table . "}")->fetchField();
        $checks[] = [
          'status' => 'pass',
          'label' => $table,
          'value' => number_format($count) . ' rows',
        ];
      }
      else {
        $checks[] = [
          'status' => 'fail',
          'label' => $table,
          'value' => 'Table missing',
          'detail' => 'Expected table does not exist',
          'resolution' => 'Uninstall and reinstall the module to create missing database tables.',
        ];
      }
    }

    // Orphaned entity-post links.
    if ($entity_post_table_exists) {
      $orphaned = (int) $this->database->query(
        "SELECT COUNT(*) FROM {ttd_entity_post_ids} ep
         LEFT JOIN {node} n ON ep.post_id = n.nid
         WHERE n.nid IS NULL"
      )->fetchField();
      $checks[] = [
        'status' => $orphaned > 0 ? 'warn' : 'pass',
        'label' => 'Orphaned Entity-Post Links',
        'value' => number_format($orphaned),
        'detail' => $orphaned > 0 ? 'Entity rows linked to deleted nodes' : NULL,
        'resolution' => $orphaned > 0 ? 'These are harmless but waste space. They can be cleaned up manually.' : NULL,
      ];
    }

    return $checks;
  }

  /**
   * Taxonomy checks.
   */
  protected function checkTaxonomy(): array {
    $checks = [];

    // Vocabulary exists.
    $vocab = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load('ttd_topics');
    $checks[] = [
      'status' => $vocab ? 'pass' : 'fail',
      'label' => 'ttd_topics Vocabulary',
      'value' => $vocab ? 'Exists' : 'Not found',
      'detail' => !$vocab ? 'Vocabulary is missing -- module may not have installed correctly' : NULL,
    ];

    if (!$vocab) {
      return $checks;
    }

    // Term count.
    $term_count = (int) $this->database->query(
      "SELECT COUNT(*) FROM {taxonomy_term_field_data} WHERE vid = :vid",
      [':vid' => 'ttd_topics']
    )->fetchField();
    $checks[] = [
      'status' => $term_count > 0 ? 'pass' : 'warn',
      'label' => 'Total Topics',
      'value' => number_format($term_count),
      'detail' => $term_count === 0 ? 'No topics exist yet -- run an analysis' : NULL,
    ];

    // Unassigned topics (not linked to any node).
    $assigned_count = (int) $this->database->query(
      "SELECT COUNT(DISTINCT ti.tid)
       FROM {taxonomy_index} ti
       INNER JOIN {taxonomy_term_field_data} td ON ti.tid = td.tid
       WHERE td.vid = :vid",
      [':vid' => 'ttd_topics']
    )->fetchField();
    $unassigned = $term_count - $assigned_count;
    $checks[] = [
      'status' => ($unassigned > ($term_count * 0.5) && $term_count > 10) ? 'warn' : 'pass',
      'label' => 'Unassigned Topics',
      'value' => number_format($unassigned),
      'detail' => $unassigned > 0 ? 'Topics not linked to any content' : NULL,
    ];

    // Topics missing ttd_id.
    $missing_count = (int) $this->database->query(
      "SELECT COUNT(*)
       FROM {taxonomy_term_field_data} td
       LEFT JOIN {taxonomy_term__field_ttd_id} fi ON td.tid = fi.entity_id
       WHERE td.vid = :vid AND (fi.field_ttd_id_value IS NULL OR fi.field_ttd_id_value = '')",
      [':vid' => 'ttd_topics']
    )->fetchField();

    $checks[] = [
      'status' => $missing_count > 0 ? 'warn' : 'pass',
      'label' => 'Topics Missing ttd_id',
      'value' => number_format($missing_count),
      'detail' => $missing_count > 0 ? 'Topics without an API connection will not receive entity data or demand metrics' : NULL,
      'resolution' => $missing_count > 0 ? 'Re-analyze the associated content to link these topics, or delete them if unused.' : NULL,
    ];

    return $checks;
  }

  /**
   * Content coverage checks.
   */
  protected function checkContentCoverage(): array {
    $checks = [];
    $config = $this->config('ttd_topics.settings');
    $enabled_types = array_filter($config->get('enabled_content_types') ?: []);

    if (empty($enabled_types)) {
      $checks[] = [
        'status' => 'warn',
        'label' => 'Enabled Content Types',
        'value' => 'None',
        'detail' => 'No content types are enabled for TopicalBoost analysis',
      ];
      return $checks;
    }

    // Total published nodes of enabled types.
    $placeholders = implode(',', array_fill(0, count($enabled_types), ':type_' . implode(',:type_', range(0, count($enabled_types) - 1))));
    $params = [];
    foreach ($enabled_types as $i => $type) {
      $params[':type_' . $i] = $type;
    }

    $total_nodes = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node_field_data} WHERE status = 1 AND type IN (" . implode(',', array_keys($params)) . ")",
      $params
    )->fetchField();

    $checks[] = [
      'status' => 'pass',
      'label' => 'Published Content',
      'value' => number_format($total_nodes),
      'detail' => 'Enabled types: ' . implode(', ', $enabled_types),
    ];

    // Nodes with topics.
    $nodes_with_topics = (int) $this->database->query(
      "SELECT COUNT(DISTINCT nft.entity_id)
       FROM {node__field_ttd_topics} nft
       INNER JOIN {node_field_data} nfd ON nft.entity_id = nfd.nid
       WHERE nfd.status = 1 AND nfd.type IN (" . implode(',', array_keys($params)) . ")",
      $params
    )->fetchField();

    $coverage_pct = $total_nodes > 0 ? round(($nodes_with_topics / $total_nodes) * 100, 1) : 0;
    $checks[] = [
      'status' => $coverage_pct > 0 ? 'pass' : 'warn',
      'label' => 'Content With Topics',
      'value' => number_format($nodes_with_topics) . ' (' . $coverage_pct . '%)',
    ];

    // Stuck in-progress analysis.
    $stuck = (int) $this->database->query(
      "SELECT COUNT(*)
       FROM {node__field_ttd_analysis_in_progress} aip
       INNER JOIN {node_field_data} nfd ON aip.entity_id = nfd.nid
       WHERE aip.field_ttd_analysis_in_progress_value = 1"
    )->fetchField();
    $checks[] = [
      'status' => $stuck > 0 ? 'warn' : 'pass',
      'label' => 'Stuck In-Progress',
      'value' => number_format($stuck),
      'detail' => $stuck > 0 ? 'Content flagged as analysis in-progress -- may need manual clearing' : NULL,
      'resolution' => $stuck > 0 ? 'These nodes have a stale in-progress flag from an interrupted analysis. Clear the flag on each node to allow re-analysis.' : NULL,
    ];

    return $checks;
  }

  /**
   * SEO meta integration checks.
   */
  protected function checkSeoMeta(): array {
    $checks = [];

    // Count nodes with generated meta.
    $meta_title_count = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node__field_ttd_generated_meta_title}
       WHERE field_ttd_generated_meta_title_value IS NOT NULL AND field_ttd_generated_meta_title_value != ''"
    )->fetchField();
    $checks[] = [
      'status' => 'pass',
      'label' => 'Content With Generated Meta Title',
      'value' => number_format($meta_title_count),
    ];

    $meta_desc_count = (int) $this->database->query(
      "SELECT COUNT(*) FROM {node__field_ttd_generated_meta_desc}
       WHERE field_ttd_generated_meta_desc_value IS NOT NULL AND field_ttd_generated_meta_desc_value != ''"
    )->fetchField();
    $checks[] = [
      'status' => 'pass',
      'label' => 'Content With Generated Meta Description',
      'value' => number_format($meta_desc_count),
    ];

    // SEO module check.
    $seo = $this->detectSeoModule();
    if ($seo['slug'] === 'none' && $meta_title_count > 0) {
      $checks[] = [
        'status' => 'warn',
        'label' => 'Meta Rendering',
        'value' => 'No SEO module',
        'detail' => 'Generated meta is saved but no SEO module (Metatag, Yoast SEO for Drupal) is rendering them.',
        'resolution' => 'Install the Metatag module so generated titles and descriptions render in the page HTML.',
      ];
    }

    return $checks;
  }

  /**
   * Detect which SEO module is active.
   */
  protected function detectSeoModule(): array {
    $module_handler = \Drupal::moduleHandler();

    if ($module_handler->moduleExists('metatag')) {
      $info = \Drupal::service('extension.list.module')->getExtensionInfo('metatag');
      return [
        'slug' => 'metatag',
        'name' => 'Metatag ' . ($info['version'] ?? ''),
      ];
    }
    if ($module_handler->moduleExists('yoast_seo')) {
      $info = \Drupal::service('extension.list.module')->getExtensionInfo('yoast_seo');
      return [
        'slug' => 'yoast_seo',
        'name' => 'Real-time SEO for Drupal ' . ($info['version'] ?? ''),
      ];
    }

    return ['slug' => 'none', 'name' => 'None detected'];
  }

}
