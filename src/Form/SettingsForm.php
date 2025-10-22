<?php

namespace Drupal\ttd_topics\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure TopicalBoost settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
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
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'topicalboost_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ttd_topics.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ttd_topics.settings');

    // Check if we should show the overview instead.
    $show_overview = \Drupal::request()->query->get('view') === 'overview';

    if ($show_overview) {
      return $this->buildOverviewPage();
    }

    // Attach the necessary libraries.
    $form['#attached']['library'][] = 'core/drupal.progress';
          $form['#attached']['library'][] = 'ttd_topics/ttd_topics.styles';
      $form['#attached']['library'][] = 'ttd_topics/tabs';
      $form['#attached']['library'][] = 'ttd_topics/tabs_css';
      $form['#attached']['library'][] = 'ttd_topics/modern_forms';
      $form['#attached']['library'][] = 'ttd_topics/progress_bars';

    // Create tabbed interface container.
    $form['tabs_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-tabs-container']],
    ];

    // Tab navigation using proper HTML markup with anchors and Font Awesome icons.
    $form['tabs_container']['nav'] = [
      '#markup' => '<div class="ttd-topics-tabs-nav">
        <div class="ttd-topics-tabs-buttons">
          <a href="#analytics" class="ttd-topics-tab-button active" data-tab="tab-analytics" data-has-settings="false">
            <span class="ttd-icon ttd-icon-analytics"></span>Analytics
          </a>
          
          <a href="#settings" class="ttd-topics-tab-button" data-tab="tab-settings" data-has-settings="true">
            <span class="ttd-icon ttd-icon-settings"></span>Settings
          </a>
          <a href="#api" class="ttd-topics-tab-button" data-tab="tab-api" data-has-settings="true">
            <span class="ttd-icon ttd-icon-api"></span>API
          </a>
          <a href="#schema" class="ttd-topics-tab-button" data-tab="tab-schema" data-has-settings="true">
            <span class="ttd-icon ttd-icon-schema"></span>Schema
          </a>
        </div>
      </div>',
      '#weight' => -10,
    ];

    // Tab content container.
    $form['tabs_container']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-tab-content']],
    ];

    // Settings Tab.
    $form['tabs_container']['content']['settings'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-tab-panel'], 'id' => 'tab-settings'],
    ];

    $form['tabs_container']['content']['settings']['title'] = [
      '#markup' => '<div class="ttd-topics-section-title">
        <span class="ttd-icon ttd-icon-large ttd-icon-display"></span>Display Settings
      </div>',
    ];

    $form['tabs_container']['content']['settings']['help'] = [
      '#markup' => '<div class="ttd-topics-help-text">
        Configure how TopicalBoost displays topics on your website and customize the user experience.
      </div>',
    ];

    // Content Type Selection.
    $content_types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $content_type) {
      $content_type_options[$content_type->id()] = $content_type->label();
    }

    $form['tabs_container']['content']['settings']['enabled_content_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Enable TopicalBoost for content types'),
      '#options' => $content_type_options,
      '#default_value' => $config->get('enabled_content_types') ?: [],
      '#description' => $this->t('Select which content types should have TopicalBoost topic analysis and display.'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group', 'ttd-topics-select2'],
        'data-placeholder' => 'Select content types...',
      ],
      '#multiple' => TRUE,
      '#required' => FALSE,
    ];

          // Attach our local select2 library to the entire form.
      $form['#attached']['library'][] = 'ttd_topics/select2';
      $form['#attached']['library'][] = 'ttd_topics/topic_count_feedback';
      $form['#attached']['library'][] = 'ttd_topics/api_validation';
    
          // Only load diagnostic library if debug mode is enabled
      if ($config->get('debug_mode')) {
        // Diagnostic library removed - file was missing
    }
    
    // Pass debug mode setting to JavaScript
    $form['#attached']['drupalSettings']['ttd_topics']['debug_mode'] = $config->get('debug_mode') ?: FALSE;

    $form['tabs_container']['content']['settings']['enable_frontend'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display topics on frontend'),
      '#default_value' => $config->get('enable_frontend'),
      '#description' => $this->t('Show identified topics to visitors on article pages.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field ttd-topics-section-spaced">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['settings']['maximum_visible_post_topics'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum topics to display'),
      '#default_value' => $config->get('maximum_visible_post_topics') ?: 5,
      '#min' => 1,
      '#max' => 50,
      '#description' => $this->t('Limit the number of topics shown to avoid overwhelming readers. Recommended: 5-10.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#states' => [
        'visible' => [
          ':input[name="enable_frontend"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['tabs_container']['content']['settings']['enable_automatic_mentions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable automatic mentions'),
      '#default_value' => $config->get('enable_automatic_mentions') ?? TRUE,
      '#description' => $this->t('Controls automatic display of mentions by the module. When disabled, topics will not be automatically added to content pages by the module, but manual Twig calls like <code>{{ topicalboost_display() }}</code> and <code>{{ topicalboost_data() }}</code> will still work normally.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#states' => [
        'visible' => [
          ':input[name="enable_frontend"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Custom Implementation Guide Section
    $form['tabs_container']['content']['settings']['custom_implementation_section'] = [
      '#markup' => '<div class="ttd-topics-section-title ttd-topics-section-spaced">
        <span class="ttd-icon ttd-icon-large ttd-icon-api"></span>Custom Implementation Guide
      </div>',
    ];

    $form['tabs_container']['content']['settings']['custom_implementation_guide'] = [
      '#markup' => '<p class="description"><strong>Custom implementation:</strong> Use these Twig functions in your templates to build custom topic displays.</p>
        <ul style="margin-left: 20px; margin-top: 5px;">
          <li><strong>Twig function - topicalboost_display():</strong> Use <code>{{ topicalboost_display() }}</code> in your theme templates to render topics with built-in styling<br>
            <em>Renders the complete HTML for topic display with show more/less functionality. Results are filtered by visibility settings, rejection status, and threshold count.</em><br>
            <div style="background: #f8f9fa; border: 1px solid #e1e5e9; border-radius: 4px; padding: 10px; margin: 8px 0; font-size: 12px;">
              <strong>Function signature:</strong><br>
              <code style="color: #0066cc;">{{ topicalboost_display(node, options) }}</code><br><br>
              <strong>Usage examples:</strong><br>
              <code style="display: block; margin: 5px 0; color: #333;">
              {# Display topics for the current node #}<br>
              {{ topicalboost_display() }}<br><br>
              {# Display topics for a specific node #}<br>
              {{ topicalboost_display(node) }}<br><br>
              {# Force display even if frontend is disabled #}<br>
              {{ topicalboost_display(node, { force_display: true }) }}<br>
              </code><br>
              <strong>Return format:</strong><br>
              <code style="display: block; margin: 5px 0; color: #666;">
              Rendered HTML string (safe markup)
              </code>
            </div>
          </li>
          <li><strong>Twig function - topicalboost_data():</strong> Use <code>{{ topicalboost_data() }}</code> in your theme templates to get topic data as an array<br>
            <em>Returns topic data as a structured array for building completely custom displays. Results are filtered by visibility settings, rejection status, and threshold count.</em><br>
            <div style="background: #f8f9fa; border: 1px solid #e1e5e9; border-radius: 4px; padding: 10px; margin: 8px 0; font-size: 12px;">
              <strong>Function signature:</strong><br>
              <code style="color: #0066cc;">{% set topics = topicalboost_data(node, options) %}</code><br><br>
              <strong>Usage examples:</strong><br>
              <code style="display: block; margin: 5px 0; color: #333;">
              {# Get topics for current node #}<br>
              {% set topics = topicalboost_data() %}<br><br>
              {# Build a custom topic list #}<br>
              {% set topics = topicalboost_data() %}<br>
              {% if topics.topics|length > 0 %}<br>
              &nbsp;&nbsp;&lt;div class="my-custom-topics"&gt;<br>
              &nbsp;&nbsp;&nbsp;&nbsp;&lt;h3&gt;{{ topics.label }}&lt;/h3&gt;<br>
              &nbsp;&nbsp;&nbsp;&nbsp;{% for topic in topics.topics %}<br>
              &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&lt;a href="{{ topic.url }}"&gt;{{ topic.name }}&lt;/a&gt;<br>
              &nbsp;&nbsp;&nbsp;&nbsp;{% endfor %}<br>
              &nbsp;&nbsp;&lt;/div&gt;<br>
              {% endif %}<br>
              </code><br>
              <strong>Return format:</strong><br>
              <code style="display: block; margin: 5px 0; color: #666;">
              [<br>
              &nbsp;&nbsp;label: "Mentions",<br>
              &nbsp;&nbsp;max_visible: 5,<br>
              &nbsp;&nbsp;total_count: 8,<br>
              &nbsp;&nbsp;topics: [<br>
              &nbsp;&nbsp;&nbsp;&nbsp;{<br>
              &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;id: 123,<br>
              &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;name: "Artificial Intelligence",<br>
              &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;url: "/topics/artificial-intelligence",<br>
              &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;entity: { ... }<br>
              &nbsp;&nbsp;&nbsp;&nbsp;},<br>
              &nbsp;&nbsp;&nbsp;&nbsp;...<br>
              &nbsp;&nbsp;]<br>
              ]
              </code>
            </div>
          </li>
        </ul>',
    ];

    $form['tabs_container']['content']['settings']['topics_list_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Topics section label'),
      '#default_value' => $config->get('topics_list_label') ?: 'Mentions',
      '#description' => $this->t('The heading text displayed above the topics list (e.g., "Related Topics", "Tags", "Mentions").'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#states' => [
        'visible' => [
          ':input[name="enable_frontend"]' => ['checked' => TRUE],
          ':input[name="enable_automatic_mentions"]' => ['checked' => TRUE],
        ],
      ],
    ];



    $form['tabs_container']['content']['settings']['post_topic_minimum_display_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum post frequency'),
      '#default_value' => $config->get('post_topic_minimum_display_count') ?: 5,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Controls which topics appear on your site based on how often they\'re mentioned. Set to 5 to only show topics mentioned in at least 5 posts. Lower values (1-3) show more topics including rare ones. Higher values (10+) show only frequently discussed topics. This affects topic pages, topic lists, and frontend displays.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      // Temporarily removed states condition to fix slider loading
      // '#states' => [
      //   'visible' => [
      //     ':input[name="enabled_content_types[]"]' => ['filled' => TRUE],
      //   ],
      // ],
    ];

    // Custom Fields for Analysis Section
    $form['tabs_container']['content']['settings']['custom_fields_section'] = [
      '#markup' => '<div class="ttd-topics-section-title ttd-topics-section-spaced">
        <span class="ttd-icon ttd-icon-large ttd-icon-display"></span>Custom Fields for Analysis
      </div>',
    ];

    $form['tabs_container']['content']['settings']['custom_fields_section_help'] = [
      '#markup' => '<div class="ttd-topics-help-text">
        Select additional fields whose content should be included in topic analysis.
      </div>',
    ];

    // Get all field options for enabled content types
    $field_options = $this->getCustomFieldOptions($config);

    $form['tabs_container']['content']['settings']['analysis_custom_fields'] = [
      '#type' => 'select',
      '#title' => $this->t('Custom Fields'),
      '#options' => $field_options,
      '#default_value' => $config->get('analysis_custom_fields') ?: [],
      '#description' => $this->t('Select additional fields whose content should be included in topic analysis.'),
      '#multiple' => TRUE,
      '#attributes' => [
        'class' => ['ttd-topics-field-group', 'ttd-topics-select2'],
        'data-placeholder' => 'Select custom fields...',
        'id' => 'edit-analysis-custom-fields',
      ],
    ];

    // Field Inspector
    $form['tabs_container']['content']['settings']['field_inspector'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['field-inspector-container']],
      '#attached' => [
        'library' => ['ttd_topics/field_inspector'],
      ],
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['header'] = [
      '#markup' => '<h3>
        <span class="ttd-icon ttd-icon-search"></span>Field Inspector
        <span class="field-count-badge">0 selected</span>
      </h3>',
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['messages'] = [
      '#markup' => '<div class="field-inspector-messages"></div>',
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['search_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['field-inspector-section', 'search-section']],
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['search_section']['search_input'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search posts by title'),
      '#title_display' => 'invisible',
      '#attributes' => [
        'class' => ['field-inspector-search'],
        'placeholder' => $this->t('Search posts by title...'),
        'autocomplete' => 'off',
        'data-search-url' => '/api/topicalboost/field-inspector/search',
        'min' => 1,
      ],
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['search_section']['results'] = [
      '#markup' => '<div class="search-results-dropdown" style="display: none;"></div>',
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['field_list'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['field-inspector-section']],
    ];

    $form['tabs_container']['content']['settings']['field_inspector']['field_list']['container'] = [
      '#markup' => '<div class="field-list"></div>',
    ];

    // URL Path Configuration
    $form['tabs_container']['content']['settings']['url_section'] = [
      '#markup' => '<div class="ttd-topics-section-title ttd-topics-section-spaced">
        <span class="ttd-icon ttd-icon-large ttd-icon-settings"></span>URL Configuration
      </div>',
    ];

    // Get current Pathauto pattern and extract just the path prefix
    $pattern_storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
    $current_pattern = $pattern_storage->load('ttd_topics');
    $current_full_pattern = $current_pattern ? $current_pattern->getPattern() : '/topics/[term:name]';
    
    // Extract just the path prefix (remove [term:name] part)
    $current_path_prefix = str_replace('[term:name]', '', $current_full_pattern);
    $stored_prefix = $config->get('topic_url_path_prefix');
    $default_prefix = $stored_prefix ?: $current_path_prefix;

    $form['tabs_container']['content']['settings']['topic_url_path_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Topic URL path'),
      '#default_value' => $default_prefix,
      '#description' => $this->t('URL path prefix for topic pages.'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => '/topics/',
        'id' => 'topic-url-path-input'
      ],
    ];

    $form['tabs_container']['content']['settings']['url_preview'] = [
      '#markup' => '<div style="margin-top: 8px; padding: 12px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; color: #495057; font-family: monospace; word-wrap: break-word; overflow-wrap: break-word; white-space: normal; overflow-x: hidden;">
        <strong>Preview:</strong> ' . \Drupal::request()->getSchemeAndHttpHost() . '<span id="url-path-preview">' . $default_prefix . '</span>artificial-intelligence
      </div>',
      '#attached' => [
        'library' => ['ttd_topics/url_preview'],
      ],
    ];

    // Developer Settings Section
    $form['tabs_container']['content']['settings']['developer_section'] = [
      '#markup' => '<div class="ttd-topics-section-title ttd-topics-section-spaced">
        <span class="ttd-icon ttd-icon-large ttd-icon-settings"></span>Developer Settings
      </div>',
    ];

    $form['tabs_container']['content']['settings']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#default_value' => $config->get('debug_mode') ?: FALSE,
      '#description' => $this->t('Enable detailed console logging for debugging. Disable this in production for better performance.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    // Analytics Tab
    $form['tabs_container']['content']['analytics'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-tab-panel'], 'id' => 'tab-analytics'],
    ];

    $form['tabs_container']['content']['analytics']['title'] = [
      '#markup' => '<div class="ttd-topics-section-title">
        <span class="ttd-icon ttd-icon-large ttd-icon-coverage"></span>Topic Coverage Analytics
      </div>',
    ];

    $form['tabs_container']['content']['analytics']['help'] = [
      '#markup' => '<div class="ttd-topics-help-text">
        Comprehensive analysis of TopicalBoost content processing and topic identification across your website.
      </div>',
    ];

    // Get analytics data.
    $analytics = $this->getAnalyticsData();
    $config = $this->config('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);

    // Check if no content types are enabled - show empty state.
    if (empty($enabled_content_types)) {
      $form['tabs_container']['content']['analytics']['empty_state'] = [
        '#markup' => '<div class="ttd-topics-empty-state">
          <div class="empty-state-icon">
            <span class="ttd-icon ttd-icon-large ttd-icon-settings"></span>
          </div>
          <h3>No Content Types Enabled</h3>
          <p>To start viewing analytics, you need to enable TopicalBoost for one or more content types. Once enabled, you\'ll see comprehensive topic coverage data, content type breakdowns, and performance metrics.</p>
          <div class="empty-state-action">
            <a href="#settings" class="ttd-topics-tab-button ttd-topics-button-primary" data-tab="tab-settings">
              <span class="ttd-icon ttd-icon-settings"></span>
              Configure Content Types
            </a>
          </div>
        </div>',
      ];
    }
    else {
      // Add analytics-specific CSS
      // Analytics styles are now loaded via the progress_bars library.
      // Summary cards.
      $form['tabs_container']['content']['analytics']['summary'] = [
        '#markup' => '<div class="analytics-summary">
          <div class="analytics-card">
            <div class="analytics-number">' . number_format($analytics['total_topics']) . '</div>
            <div class="analytics-label">Total Topics</div>
          </div>
          <div class="analytics-card">
            <div class="analytics-number">' . number_format($analytics['avg_topics_per_post'], 1) . '</div>
            <div class="analytics-label">Avg Topics/Post</div>
          </div>
          <div class="analytics-card">
            <div class="analytics-number">' . number_format($analytics['coverage_percentage'], 1) . '%</div>
            <div class="analytics-label">' . number_format($analytics['posts_with_topics']) . ' / ' . number_format($analytics['total_posts']) . ' posts have topics</div>
          </div>
        </div>',
      ];

      // Detailed breakdown table.
      $table_rows = '';
      foreach ($analytics['by_content_type'] as $type => $data) {
        $coverage_class = $data['coverage_percentage'] >= 80 ? 'status-good' :
                         ($data['coverage_percentage'] >= 50 ? 'status-warning' : 'status-poor');

        // Determine progress bar color class.
        $bar_class = $data['coverage_percentage'] >= 70 ? 'high-coverage' :
                    ($data['coverage_percentage'] >= 40 ? 'medium-coverage' : 'low-coverage');

        $table_rows .= '<tr>
          <td><strong>' . ucfirst($type) . '</strong></td>
          <td>' . number_format($data['total_posts']) . '</td>
          <td>' . number_format($data['posts_with_topics']) . '</td>
          <td>
            <div class="coverage-bar">
              <div class="coverage-fill ' . $bar_class . '" style="width: ' . $data['coverage_percentage'] . '%;">
                ' . number_format($data['coverage_percentage'], 1) . '%
              </div>
            </div>
          </td>
          <td>' . number_format($data['avg_topics_per_post'], 1) . '</td>
        </tr>';
      }

      $form['tabs_container']['content']['analytics']['breakdown'] = [
        '#markup' => '<div style="margin-top: 1.5rem;">
          <h4>Content Type Breakdown</h4>
          <table class="analytics-table">
            <thead>
              <tr>
                <th>Content Type</th>
                <th>Total Posts</th>
                <th>With Topics</th>
                <th>Coverage</th>
                <th>Avg Topics</th>
              </tr>
            </thead>
            <tbody>' . $table_rows . '</tbody>
          </table>
        </div>',
      ];
    }

    // API Tab.
    $form['tabs_container']['content']['api'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-tab-panel'], 'id' => 'tab-api'],
    ];

    $form['tabs_container']['content']['api']['title'] = [
      '#markup' => '<div class="ttd-topics-section-title">
        <span class="ttd-icon ttd-icon-large ttd-icon-key"></span>API Configuration
      </div>',
    ];

    $form['tabs_container']['content']['api']['api_help'] = [
      '#markup' => '<div class="ttd-topics-help-text">
        The API key will be automatically validated when entered to ensure it\'s working correctly.
      </div>',
    ];

    // Create a container for the API key field and indicator.
    $form['tabs_container']['content']['api']['api_key_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['api-key-container']],
    ];

    $form['tabs_container']['content']['api']['api_key_container']['topicalboost_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('TopicalBoost API Key'),
      '#default_value' => $config->get('topicalboost_api_key'),
      '#description' => $this->t('Your unique API key for TopicalBoost.'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group', 'ttd-topics-api-key-field'],
        'id' => 'ttd-topics-api-key-field',
        'placeholder' => 'Enter your API key here...',
      ],
      '#field_suffix' => '<div id="api-key-validation-indicator" class="api-key-indicator"></div>',
    ];

    // Add JavaScript for API key validation.
    $form['tabs_container']['content']['api']['#attached']['library'][] = 'ttd_topics/api_validation';
    $form['tabs_container']['content']['api']['#attached']['drupalSettings']['topicalBoost']['apiEndpoint'] = TOPICALBOOST_API_ENDPOINT;

    // Schema Tab.
    $form['tabs_container']['content']['schema'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-tab-panel'], 'id' => 'tab-schema'],
    ];

    $form['tabs_container']['content']['schema']['title'] = [
      '#markup' => '<div class="ttd-topics-section-title">
        <span class="ttd-icon ttd-icon-large ttd-icon-schema"></span>Organization Schema
      </div>',
    ];

    $form['tabs_container']['content']['schema']['schema_help'] = [
      '#markup' => '<div class="ttd-topics-help-text">
        <strong>Why this matters:</strong> This information creates structured data that helps search engines understand your organization and improves how your content appears in search results and social media, in addition to schema from your topics.
      </div>',
    ];

    $form['tabs_container']['content']['schema']['social_media'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['schema']['social_media']['title'] = [
      '#markup' => '<h4><span class="ttd-icon ttd-icon-social"></span>Social Media Profiles</h4>',
    ];

    $form['tabs_container']['content']['schema']['organization_facebook_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Facebook Page'),
      '#default_value' => $config->get('organization_facebook_url'),
      '#description' => $this->t('Your organization\'s Facebook page URL'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => 'https://www.facebook.com/yourorganization',
      ],
    ];

    $form['tabs_container']['content']['schema']['organization_twitter_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Twitter/X Profile'),
      '#default_value' => $config->get('organization_twitter_url'),
      '#description' => $this->t('Your organization\'s Twitter/X profile URL'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => 'https://twitter.com/yourorganization',
      ],
    ];

    $form['tabs_container']['content']['schema']['organization_linkedin_url'] = [
      '#type' => 'url',
      '#title' => $this->t('LinkedIn Company Page'),
      '#default_value' => $config->get('organization_linkedin_url'),
      '#description' => $this->t('Your organization\'s LinkedIn company page URL'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => 'https://www.linkedin.com/company/yourorganization',
      ],
    ];

    $form['tabs_container']['content']['schema']['organization_youtube_url'] = [
      '#type' => 'url',
      '#title' => $this->t('YouTube Channel'),
      '#default_value' => $config->get('organization_youtube_url'),
      '#description' => $this->t('Your organization\'s YouTube channel URL'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => 'https://www.youtube.com/user/yourorganization',
      ],
    ];

    $form['tabs_container']['content']['schema']['other_links'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['schema']['other_links']['title'] = [
      '#markup' => '<h4><span class="ttd-icon ttd-icon-link"></span>Additional Links</h4>',
    ];

    $form['tabs_container']['content']['schema']['organization_wikipedia_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Wikipedia Page'),
      '#default_value' => $config->get('organization_wikipedia_url'),
      '#description' => $this->t('Your organization\'s Wikipedia page URL (if available)'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => 'https://en.wikipedia.org/wiki/Your_Organization',
      ],
    ];

    $form['tabs_container']['content']['schema']['branding'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['schema']['branding'] = [
      '#type' => 'fieldset',
      '#title' => '<span class="ttd-icon ttd-icon-logo"></span>' . $this->t('Logo & Branding'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    // Current logo status section.
    $auto_detected_logo = $this->getAutoDetectedLogoUrl();

    if ($auto_detected_logo) {
      $dimensions_info = $this->getImageDimensions($auto_detected_logo);

      $form['tabs_container']['content']['schema']['branding']['current_status']['preview'] = [
        '#type' => 'item',
        '#markup' => '<div style="background: #f8f9fa; padding: 16px; border-radius: 6px; border: 1px solid #e9ecef;"><div style="display: flex; align-items: flex-start; gap: 16px; margin-bottom: 12px;"><img src="' . $auto_detected_logo . '" alt="Current Logo" style="max-width: 120px; max-height: 80px; border: 1px solid #ddd; padding: 6px; background: white; flex-shrink: 0;" /><div style="flex: 1; min-width: 0;"><div style="font-size: 13px; color: #495057; margin-bottom: 4px; word-break: break-all;"><strong>Source:</strong> ' . $auto_detected_logo . '</div><div style="font-size: 13px; color: #495057;"><strong>Info:</strong> ' . $dimensions_info . '</div></div></div><div style="font-size: 12px; color: #856404; background: #fff3cd; padding: 8px 10px; border-radius: 4px; border-left: 3px solid #ffc107;"></div></div>',
      ];
    }
    else {
      $form['tabs_container']['content']['schema']['branding']['current_status']['no_logo'] = [
        '#type' => 'item',
        '#markup' => '<div style="padding: 12px 16px; border: 2px dashed #dc3545; border-radius: 6px; text-align: center; background: #f8d7da; color: #721c24;"><strong>⚠️ No logo detected.</strong> Please use the override options below.</div>',
      ];
    }

    // Upload override option.
    $form['tabs_container']['content']['schema']['branding']['organization_logo_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload Logo (Optional)'),
      '#default_value' => $config->get('organization_logo_fid') ? [$config->get('organization_logo_fid')] : NULL,
      '#description' => $this->t('Override the auto-detected logo. PNG/JPG recommended.'),
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'png jpg jpeg svg gif'
        ],
        'FileSizeLimit' => [
          'fileLimit' => 5 * 1024 * 1024, // 5MB max
        ],
      ],
      '#upload_location' => 'public://logos/',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    $actions = parent::actions($form, $form_state);

    // Hide the submit button on analytics tab only.
    $actions['submit']['#attributes']['class'][] = 'ttd-topics-submit-button';

    return $actions;
  }

  /**
   * Gets comprehensive analytics data for the dashboard.
   *
   * @return array
   *   Analytics data including totals, averages, and breakdowns.
   */
  private function getAnalyticsData() {
    $config = $this->config('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);
    
    // Check if the topics field table exists before querying
    if (!$this->database->schema()->tableExists('node__field_ttd_topics')) {
      return [
        'total_topics' => 0,
        'posts_with_topics' => 0,
        'total_posts' => 0,
        'coverage_percentage' => 0,
        'avg_topics_per_post' => 0,
        'by_content_type' => [],
      ];
    }
    
    // If no content types are enabled, return empty data
    if (empty($enabled_content_types)) {
      return [
        'total_topics' => 0,
        'posts_with_topics' => 0,
        'total_posts' => 0,
        'coverage_percentage' => 0,
        'avg_topics_per_post' => 0,
        'by_content_type' => [],
      ];
    }
    
    // Get total unique topics.
    $total_topics = $this->database->query("
            SELECT COUNT(DISTINCT field_ttd_topics_target_id)
      FROM {node__field_ttd_topics}
    ")->fetchField();

    // Get posts with topics (filtered by enabled content types)
    $posts_with_topics = $this->database->query("
      SELECT COUNT(DISTINCT t.entity_id) 
      FROM {node__field_ttd_topics} t
      INNER JOIN {node_field_data} n ON t.entity_id = n.nid
      WHERE n.status = 1 AND n.type IN (:types[])
    ", [':types[]' => $enabled_content_types])->fetchField();

    // Get total posts (filtered by enabled content types)
    $total_posts = $this->database->query("
      SELECT COUNT(*) 
      FROM {node_field_data} 
      WHERE status = 1 AND type IN (:types[])
    ", [':types[]' => $enabled_content_types])->fetchField();

    // Calculate coverage percentage.
    $coverage_percentage = $total_posts > 0 ? ($posts_with_topics / $total_posts) * 100 : 0;

    // Get average topics per post (only for posts that have topics, filtered by enabled content types)
    $avg_topics_per_post = $posts_with_topics > 0 ?
      $this->database->query("
        SELECT COUNT(*) / COUNT(DISTINCT t.entity_id) 
        FROM {node__field_ttd_topics} t
        INNER JOIN {node_field_data} n ON t.entity_id = n.nid
        WHERE n.status = 1 AND n.type IN (:types[])
      ", [':types[]' => $enabled_content_types])->fetchField() : 0;

    // Get breakdown by content type (only enabled content types)
    $content_type_data = [];
    $content_types = $enabled_content_types;

    foreach ($content_types as $type) {
      $type_total = $this->database->query("
        SELECT COUNT(*) 
        FROM {node_field_data} 
        WHERE type = :type AND status = 1
      ", [':type' => $type])->fetchField();

      $type_with_topics = $this->database->query("
        SELECT COUNT(DISTINCT n.nid) 
        FROM {node_field_data} n 
        INNER JOIN {node__field_ttd_topics} t ON n.nid = t.entity_id 
        WHERE n.type = :type AND n.status = 1
      ", [':type' => $type])->fetchField();

      $type_coverage = $type_total > 0 ? ($type_with_topics / $type_total) * 100 : 0;

      $type_avg_topics = $type_with_topics > 0 ?
        $this->database->query("
          SELECT COUNT(*) / COUNT(DISTINCT n.nid) 
          FROM {node_field_data} n 
          INNER JOIN {node__field_ttd_topics} t ON n.nid = t.entity_id 
          WHERE n.type = :type AND n.status = 1
        ", [':type' => $type])->fetchField() : 0;

      $content_type_data[$type] = [
        'total_posts' => (int) $type_total,
        'posts_with_topics' => (int) $type_with_topics,
        'coverage_percentage' => (float) $type_coverage,
        'avg_topics_per_post' => (float) $type_avg_topics,
      ];
    }

    return [
      'total_topics' => (int) $total_topics,
      'posts_with_topics' => (int) $posts_with_topics,
      'total_posts' => (int) $total_posts,
      'coverage_percentage' => (float) $coverage_percentage,
      'avg_topics_per_post' => (float) $avg_topics_per_post,
      'by_content_type' => $content_type_data,
    ];
  }

  /**
   * Fetches the total and analyzed nodes count.
   *
   * @return array
   *   An associative array containing 'total' and 'analyzed' counts.
   */
  private function getNodeCounts() {
    $total = \Drupal::entityQuery('node')->count()->execute();
    
    // Check if the field exists before querying
    $analyzed = 0;
    if ($this->database->schema()->tableExists('node__field_ttd_last_analyzed')) {
      $analyzed = \Drupal::entityQuery('node')
        ->exists('field_ttd_last_analyzed')
        ->count()
        ->execute();
    }

    return ['total' => $total, 'analyzed' => $analyzed];
  }

  /**
   * Auto-detect logo from theme and site settings.
   *
   * @return string|null
   *   The URL to the auto-detected logo or NULL if none found.
   */
  protected function getAutoDetectedLogoUrl() {
    $request = \Drupal::request();
    $base_url = $request->getSchemeAndHttpHost();

    // First, try to get logo from Drupal site logo settings.
    $site_logo = theme_get_setting('logo');
    if (!empty($site_logo['url'])) {
      return $site_logo['url'];
    }

    // Get active theme info.
    $theme_handler = \Drupal::service('theme_handler');
    $active_theme = $theme_handler->getDefault();
    $theme_path = $theme_handler->getTheme($active_theme)->getPath();

    // Common logo filenames to search for.
    $logo_filenames = [
      'logo.svg',
      'logo.png',
      'logo.jpg',
      'logo.jpeg',
      'logo.gif',
      'images/logo.svg',
      'images/logo.png',
      'images/logo.jpg',
      'images/logo.jpeg',
      'assets/logo.svg',
      'assets/logo.png',
      'img/logo.svg',
      'img/logo.png',
    ];

    // Search for logo files in active theme.
    foreach ($logo_filenames as $filename) {
      $logo_path = $theme_path . '/' . $filename;
      if (file_exists(\Drupal::root() . '/' . $logo_path)) {
        return $base_url . '/' . $logo_path;
      }
    }

    // Fallback: look for site-specific logos (like your fordhaminstitute theme)
    $site_specific_logos = [
      '/themes/fordhaminstitute/logo.png',
      '/themes/fordhaminstitute/images/logo-alt.png',
      '/themes/fordhaminstitute/2color-logo.svg',
      '/themes/fordhaminstitute/logo.svg',
    ];

    foreach ($site_specific_logos as $logo_path) {
      if (file_exists(\Drupal::root() . $logo_path)) {
        return $base_url . $logo_path;
      }
    }

    // Return null if no logo found.
    return NULL;
  }

  /**
   * Get image dimensions for display.
   *
   * @param string $image_url
   *   The URL to the image.
   *
   * @return string
   *   Formatted dimensions string.
   */
  protected function getImageDimensions($image_url) {
    try {
      // Convert URL to local file path if it's a local image.
      $request = \Drupal::request();
      $base_url = $request->getSchemeAndHttpHost();

      if (strpos($image_url, $base_url) === 0) {
        // Local image - convert URL to file path.
        $local_path = str_replace($base_url, '', $image_url);
        $file_path = \Drupal::root() . $local_path;

        if (file_exists($file_path)) {
          $file_size = filesize($file_path);
          $file_size_formatted = $this->formatBytes($file_size);

          // Check if it's an SVG file.
          if (strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'svg') {
            $dimensions = $this->getSvgDimensions($file_path);
            if ($dimensions) {
              return "Dimensions: {$dimensions['width']} × {$dimensions['height']}px | Size: {$file_size_formatted} | SVG";
            }
            else {
              return "Dimensions: Vector (SVG) | Size: {$file_size_formatted}";
            }
          }
          else {
            // Regular raster image.
            $size = getimagesize($file_path);
            if ($size) {
              $width = $size[0];
              $height = $size[1];
              return "Dimensions: {$width} × {$height}px | Size: {$file_size_formatted}";
            }
          }
        }
      }
      else {
        // External image - try to get dimensions via URL.
        if (strtolower(pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION)) === 'svg') {
          return "Dimensions: Vector (SVG)";
        }
        else {
          $size = @getimagesize($image_url);
          if ($size) {
            $width = $size[0];
            $height = $size[1];
            return "Dimensions: {$width} × {$height}px";
          }
        }
      }
    }
    catch (\Exception $e) {
      // Silently fail and return basic info.
    }

    return "Dimensions: Unable to determine";
  }

  /**
   * Extract dimensions from SVG file.
   *
   * @param string $file_path
   *   Path to the SVG file.
   *
   * @return array|null
   *   Array with width and height, or null if unable to determine.
   */
  protected function getSvgDimensions($file_path) {
    try {
      $svg_content = file_get_contents($file_path);
      if (!$svg_content) {
        return NULL;
      }

      // Try to parse as XML.
      $xml = simplexml_load_string($svg_content);
      if (!$xml) {
        return NULL;
      }

      $width = NULL;
      $height = NULL;

      // Method 1: Check for width and height attributes.
      if (isset($xml['width']) && isset($xml['height'])) {
        $width = $this->parseSvgDimension((string) $xml['width']);
        $height = $this->parseSvgDimension((string) $xml['height']);
      }

      // Method 2: Check viewBox if width/height not found.
      if (($width === NULL || $height === NULL) && isset($xml['viewBox'])) {
        $viewBox = explode(' ', trim((string) $xml['viewBox']));
        if (count($viewBox) >= 4) {
          $width = (float) $viewBox[2];
          $height = (float) $viewBox[3];
        }
      }

      if ($width !== NULL && $height !== NULL) {
        return [
          'width' => round($width),
          'height' => round($height),
        ];
      }
    }
    catch (\Exception $e) {
      // Silently fail.
    }

    return NULL;
  }

  /**
   * Parse SVG dimension value (handles px, em, etc.).
   *
   * @param string $value
   *   The dimension value from SVG.
   *
   * @return float|null
   *   Numeric value or null if unable to parse.
   */
  protected function parseSvgDimension($value) {
    // Remove units and convert to float.
    $numeric = preg_replace('/[^0-9.]/', '', $value);
    return is_numeric($numeric) ? (float) $numeric : NULL;
  }

  /**
   * Format file size in human-readable format.
   *
   * @param int $bytes
   *   The file size in bytes.
   *
   * @return string
   *   Formatted file size.
   */
  protected function formatBytes($bytes) {
    if ($bytes >= 1024 * 1024) {
      return round($bytes / (1024 * 1024), 1) . ' MB';
    }
    elseif ($bytes >= 1024) {
      return round($bytes / 1024, 1) . ' KB';
    }
    else {
      return $bytes . ' bytes';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * Manages field additions and removals when content types are enabled/disabled.
   */
  protected function manageContentTypeFields(array $old_content_types, array $new_content_types) {
    // Content types to add fields to.
    $types_to_add = array_diff($new_content_types, $old_content_types);
    
    // Content types to remove fields from.
    $types_to_remove = array_diff($old_content_types, $new_content_types);
    
    // Field definitions with storage configurations.
    $field_definitions = [
      'field_ttd_rejected_topics' => [
        'type' => 'entity_reference',
        'label' => 'Rejected Topics',
        'storage_settings' => [
          'target_type' => 'taxonomy_term',
        ],
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => ['ttd_topics' => 'ttd_topics'],
            'auto_create' => FALSE,
          ],
        ],
      ],
      'field_ttd_last_analyzed' => [
        'type' => 'datetime',
        'label' => 'TopicalBoost Last Analyzed',
        'storage_settings' => [
          'datetime_type' => 'datetime',
        ],
        'settings' => [],
      ],
      'field_ttd_topics' => [
        'type' => 'entity_reference',
        'label' => 'TopicalBoost Topics',
        'storage_settings' => [
          'target_type' => 'taxonomy_term',
        ],
        'settings' => [
          'handler' => 'default:taxonomy_term',
          'handler_settings' => [
            'target_bundles' => ['ttd_topics' => 'ttd_topics'],
            'auto_create' => FALSE,
          ],
        ],
      ],
      'field_ttd_analysis_in_progress' => [
        'type' => 'boolean',
        'label' => 'TopicalBoost Analysis In Progress',
        'storage_settings' => [],
        'settings' => [],
      ],
    ];
    
    // Ensure field storage exists for all fields before creating instances.
    foreach ($field_definitions as $field_name => $field_config) {
      $field_storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', $field_name);
      if (!$field_storage) {
        \Drupal\field\Entity\FieldStorageConfig::create([
          'field_name' => $field_name,
          'entity_type' => 'node',
          'type' => $field_config['type'],
          'cardinality' => ($field_config['type'] === 'entity_reference') ? -1 : 1,
          'settings' => $field_config['storage_settings'] ?? [],
        ])->save();
      }
    }
    
    // Add fields to new content types.
    foreach ($types_to_add as $content_type) {
      foreach ($field_definitions as $field_name => $field_config) {
        if (!\Drupal::entityTypeManager()->getStorage('node_type')->load($content_type)) {
          continue; // Skip if content type doesn't exist.
        }
        
        if (!\Drupal\field\Entity\FieldConfig::loadByName('node', $content_type, $field_name)) {
          \Drupal\field\Entity\FieldConfig::create([
            'field_name' => $field_name,
            'entity_type' => 'node',
            'bundle' => $content_type,
            'label' => $field_config['label'],
            'settings' => $field_config['settings'] ?? [],
          ])->save();
        }
      }
    }
    
    // Remove fields from disabled content types.
    foreach ($types_to_remove as $content_type) {
      foreach (array_keys($field_definitions) as $field_name) {
        $field = \Drupal\field\Entity\FieldConfig::loadByName('node', $content_type, $field_name);
        if ($field) {
          $field->delete();
        }
      }
    }
  }

  /**
   * Get field options for custom field selection.
   *
   * @param object $config
   *   The configuration object.
   *
   * @return array
   *   Array of field options keyed by field machine name.
   */
  protected function getCustomFieldOptions($config) {
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);
    $field_options = [];

    if (empty($enabled_content_types)) {
      return $field_options;
    }

    $field_collector = \Drupal::service('ttd_topics.field_collector');

    foreach ($enabled_content_types as $content_type) {
      $compatible_fields = $field_collector->getTextCompatibleFields('node', $content_type);

      foreach ($compatible_fields as $field_name => $field_definition) {
        $base_label = $field_definition->getLabel();
        $field_type = $field_definition->getType();

        // For paragraph fields, show subfield count
        if ($field_type === 'entity_reference_revisions') {
          $subfield_count = $this->countParagraphSubfieldsForContentType($field_definition, $content_type);
          if ($subfield_count > 0) {
            $base_label .= ' (' . $subfield_count . ' subfield' . ($subfield_count !== 1 ? 's' : '') . ')';
          }
        }

        $label = $base_label . ' - ' . $field_name . ' (' . $content_type . ')';
        $field_options[$field_name] = $label;
      }
    }

    // Remove duplicates and sort
    $field_options = array_unique($field_options);
    asort($field_options);

    return $field_options;
  }

  /**
   * Count text-compatible subfields in paragraph fields for a content type.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param string $content_type
   *   The content type machine name.
   *
   * @return int
   *   Number of text-compatible subfields.
   */
  protected function countParagraphSubfieldsForContentType($field_definition, string $content_type): int {
    $subfield_count = 0;

    // Get allowed paragraph bundles for this field
    $handler_settings = $field_definition->getSetting('handler_settings');
    $target_bundles = $handler_settings['target_bundles'] ?? [];

    if (empty($target_bundles)) {
      // If no specific bundles are set, get all paragraph bundles
      $paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraphs_type');
      $paragraph_types = $paragraph_storage->loadMultiple();
      $target_bundles = array_keys($paragraph_types);
    }

    $field_collector = \Drupal::service('ttd_topics.field_collector');

    // Count text-compatible fields across all allowed paragraph bundles
    foreach ($target_bundles as $bundle) {
      $compatible_fields = $field_collector->getTextCompatibleFields('paragraph', $bundle);

      foreach ($compatible_fields as $para_field_name => $para_field_definition) {
        // Skip base fields and only count custom fields
        $is_base_field = method_exists($para_field_definition, 'isBaseField') ? $para_field_definition->isBaseField() : false;
        if (!$is_base_field && strpos($para_field_name, 'field_') === 0) {
          $subfield_count++;
        }
      }
    }

    return $subfield_count;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('ttd_topics.settings');
    
    // Get old and new content types to manage field additions/removals.
    $old_content_types = array_filter($config->get('enabled_content_types') ?: []);
    $raw_new_content_types = $form_state->getValue('enabled_content_types');
    
    // Handle the case where the field might be empty or contain empty values
    $new_content_types = [];
    if (is_array($raw_new_content_types)) {
      $new_content_types = array_filter($raw_new_content_types, function($value) {
        return !empty($value);
      });
    }
    
    // Debug logging
    \Drupal::logger('ttd_topics')->info('Content types - Old: @old, Raw new: @raw_new, Filtered new: @new', [
      '@old' => implode(', ', $old_content_types),
      '@raw_new' => is_array($raw_new_content_types) ? implode(', ', $raw_new_content_types) : 'not array',
      '@new' => implode(', ', $new_content_types),
    ]);
    
    // Handle field management for content type changes.
    $this->manageContentTypeFields($old_content_types, $new_content_types);

    // Handle logo file upload.
    $logo_fid = NULL;
    $upload_values = $form_state->getValue(['branding', 'organization_logo_upload']);
    if ($upload_values) {
      $logo_fid = $upload_values[0];
      // Make the file permanent.
      if ($logo_fid) {
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($logo_fid);
        if ($file) {
          $file->setPermanent();
          $file->save();
        }
      }
    }

    // Handle URL pattern changes
    $new_path_prefix = $form_state->getValue('topic_url_path_prefix');
    $current_path_prefix = $config->get('topic_url_path_prefix');
    
    if ($new_path_prefix !== NULL && $new_path_prefix !== $current_path_prefix) {
      // Ensure the prefix starts with / and ends with /
      $new_path_prefix = '/' . trim($new_path_prefix, '/') . '/';
      
      // Create the full pattern by adding [term:name]
      $new_full_pattern = $new_path_prefix . '[term:name]';
      
      // Update the Pathauto pattern for future topics only
      $pattern_storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
      $pattern = $pattern_storage->load('ttd_topics');
      
      if ($pattern) {
        $pattern->setPattern($new_full_pattern);
        $pattern->save();
        
        \Drupal::messenger()->addMessage($this->t('Topic URL pattern updated to: @pattern. This will apply to new topics going forward. Existing topic URLs remain unchanged.', [
          '@pattern' => $new_full_pattern
        ]));
      }
    }

    $config
      ->set('enabled_content_types', $new_content_types)
      ->set('enable_frontend', $form_state->getValue('enable_frontend'))
      ->set('enable_automatic_mentions', $form_state->getValue('enable_automatic_mentions'))
      ->set('maximum_visible_post_topics', $form_state->getValue('maximum_visible_post_topics'))
      ->set('post_topic_minimum_display_count', $form_state->getValue('post_topic_minimum_display_count'))
      ->set('analysis_custom_fields', array_filter($form_state->getValue('analysis_custom_fields') ?: []))
      ->set('topics_list_label', $form_state->getValue('topics_list_label'))
      ->set('topic_url_path_prefix', $new_path_prefix)
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('topicalboost_api_key', $form_state->getValue('topicalboost_api_key'))

      ->set('organization_facebook_url', $form_state->getValue('organization_facebook_url'))
      ->set('organization_twitter_url', $form_state->getValue('organization_twitter_url'))
      ->set('organization_linkedin_url', $form_state->getValue('organization_linkedin_url'))
      ->set('organization_youtube_url', $form_state->getValue('organization_youtube_url'))
      ->set('organization_wikipedia_url', $form_state->getValue('organization_wikipedia_url'))
      ->set('organization_logo_fid', $logo_fid)
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds the WordPress-style overview page.
   */
  protected function buildOverviewPage() {
    $database = $this->database;
    $config = $this->config('ttd_topics.settings');
    $min_display_count = $config->get('post_topic_minimum_display_count') ?: 10;

    // Get basic topic data first.
    $query = $database->select('taxonomy_term_field_data', 'td');
    $query->fields('td', ['tid', 'name']);
    $query->condition('td.vid', 'ttd_topics');
    $query->condition('td.status', 1);
    // Limit to prevent memory issues.
    $query->range(0, 100);
    $query->orderBy('td.name', 'ASC');

    $terms_data = $query->execute()->fetchAllKeyed();

    if (empty($terms_data)) {
      return [
        '#markup' => $this->t('No topics found.'),
      ];
    }

    // Get post counts using existing function.
    $term_ids = array_keys($terms_data);
    $post_counts = topicalboost_get_topic_node_counts($term_ids);

    // Get hide field values.
    $hide_query = $database->select('taxonomy_term__field_hide', 'tfh');
    $hide_query->fields('tfh', ['entity_id', 'field_hide_value']);
    $hide_query->condition('tfh.entity_id', $term_ids, 'IN');
    $hide_data = $hide_query->execute()->fetchAllKeyed();

    // Build table rows.
    $rows = [];
    $total_posts = 0;
    $visible_terms = 0;

    foreach ($terms_data as $tid => $name) {
      $count = $post_counts[$tid] ?? 0;
      $is_hidden = $hide_data[$tid] ?? 0;
      $total_posts += $count;

      if (!$is_hidden && $count >= $min_display_count) {
        $visible_terms++;
      }

      $edit_url = Url::fromRoute('entity.taxonomy_term.edit_form', ['taxonomy_term' => $tid]);
      $view_url = Url::fromRoute('entity.taxonomy_term.canonical', ['taxonomy_term' => $tid]);

      $status_labels = [];
      if ($is_hidden) {
        $status_labels[] = 'Hidden';
      }
      if ($count < $min_display_count) {
        $status_labels[] = 'Below threshold';
      }

      $rows[] = [
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $name,
            '#url' => $edit_url,
          ],
        ],
        'count' => [
          'data' => $count,
          'style' => $count > 0 ? 'background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 3px; text-align: center;' : 'background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 3px; text-align: center;',
        ],
        'status' => implode(', ', $status_labels) ?: 'Active',
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

    // Sort by post count.
    usort($rows, function ($a, $b) {
      return $b['count']['data'] - $a['count']['data'];
    });

    $build = [];

    // Back link.
    $build['back_link'] = [
      '#markup' => '<p><a href="/admin/config/content/topicalboost">&larr; Back to TopicalBoost Settings</a></p>',
    ];

    // Summary.
    $build['summary'] = [
      '#markup' => '<div style="background: #f8f9fa; padding: 15px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #dee2e6;">
        <h3 style="margin-top: 0;">Topics Overview (WordPress-style)</h3>
        <div style="display: flex; gap: 30px; flex-wrap: wrap;">
          <div><strong>' . count($terms_data) . '</strong> Total Topics</div>
          <div><strong>' . $visible_terms . '</strong> Publicly Visible</div>
          <div><strong>' . $total_posts . '</strong> Total Post References</div>
          <div>Minimum display count: <strong>' . $min_display_count . '</strong></div>
        </div>
      </div>',
    ];

    // Add new topic link.
    $build['add_topic'] = [
      '#markup' => '<p><a href="/admin/structure/taxonomy/manage/ttd_topics/add" class="button button--primary">Add New Topic</a></p>',
    ];

    // Table.
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Topic Name'),
        $this->t('Posts'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No topics found.'),
      '#attributes' => ['style' => 'margin-top: 20px;'],
    ];

    return $build;
  }

}
