<?php

namespace Drupal\ttd_topics\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Render\Markup;
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
    $form['#attached']['library'][] = 'ttd_topics/settings';
    $form['#attached']['library'][] = 'ttd_topics/progress_bars';
    $form['#attached']['library'][] = 'ttd_topics/coverage';
    $form['#attached']['library'][] = 'ttd_topics/code_examples';
    $form['#attached']['library'][] = 'ttd_topics/bulk_analysis';

    // Page header with title.
    $form['page_header'] = [
      '#markup' => '<div class="ttd-page-header"><h1>TopicalBoost Settings</h1></div>',
      '#weight' => -20,
    ];

    // Wrap the entire form.
    $form['#attributes']['class'][] = 'ttd-settings-wrap';

    // Create tabbed interface container.
    $form['tabs_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-layout']],
    ];

    // Sidebar navigation.
    $form['tabs_container']['nav'] = [
      '#markup' => '<nav class="ttd-settings-nav">
        <div class="ttd-nav-group-label">General</div>
        <div class="ttd-nav-item active" data-tab="tab-setup">Setup</div>
        <div class="ttd-nav-item" data-tab="tab-content">Content</div>
        <div class="ttd-nav-group-label">Display</div>
        <div class="ttd-nav-item" data-tab="tab-topiclist">Topic List</div>
        <div class="ttd-nav-item" data-tab="tab-behavior">Behavior</div>
        <div class="ttd-nav-group-label">Analysis</div>
        <div class="ttd-nav-item" data-tab="tab-watchlist" data-has-settings="false">Watchlist</div>
        <div class="ttd-nav-group-label">Advanced</div>
        <div class="ttd-nav-item" data-tab="tab-widgets">Widgets</div>
        <div class="ttd-nav-item" data-tab="tab-schema">Schema &amp; URL</div>
        <div class="ttd-nav-item" data-tab="tab-authors">Authors</div>
        <div class="ttd-nav-item" data-tab="tab-developer">Developer</div>
        <div class="ttd-nav-group-label">Info</div>
        <div class="ttd-nav-item" data-tab="tab-changelog" data-has-settings="false">Changelog</div>
      </nav>',
      '#weight' => -10,
    ];

    // Tab content container.
    $form['tabs_container']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-content']],
    ];

    $form['tabs_container']['content']['settings_search'] = [
      '#markup' => Markup::create('<div class="ttd-settings-content-header">
        <div class="ttd-settings-search-bar">
          <svg class="ttd-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4.5-4.5"></path></svg>
          <input type="text" id="ttd-settings-search" placeholder="' . $this->t('Search settings...') . '" autocomplete="off" aria-label="' . $this->t('Search settings') . '" />
          <button type="button" id="ttd-settings-search-clear" class="ttd-search-clear" style="display:none;" aria-label="' . $this->t('Clear settings search') . '">&times;</button>
        </div>
      </div>
      <div class="ttd-search-no-results">' . $this->t('No settings found matching your search.') . '</div>'),
      '#weight' => -20,
    ];

    // =========================================================================
    // Setup Tab
    // =========================================================================
    $form['tabs_container']['content']['setup'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel', 'active'], 'id' => 'tab-setup'],
    ];

    $form['tabs_container']['content']['setup']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Setup</h2>',
    ];

    // API Key (moved from API tab).
    $form['tabs_container']['content']['setup']['api_key_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['api-key-container']],
    ];

    $form['tabs_container']['content']['setup']['api_key_container']['topicalboost_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
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
    $form['tabs_container']['content']['setup']['#attached']['library'][] = 'ttd_topics/api_validation';
    $form['tabs_container']['content']['setup']['#attached']['drupalSettings']['topicalBoost']['apiEndpoint'] = TOPICALBOOST_API_ENDPOINT;

    $form['tabs_container']['content']['setup']['enable_frontend'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Frontend Display'),
      '#default_value' => $config->get('enable_frontend'),
      '#description' => $this->t('Show topics on your website.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['setup']['enable_meta_generator'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('SEO Meta Generator'),
      '#default_value' => $config->get('enable_meta_generator') ?? FALSE,
      '#description' => $this->t('Integrates with Yoast, Rank Math, AIOSEO, SEOPress.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#weight' => 10,
    ];

    $form['tabs_container']['content']['setup']['meta_generator_heading'] = [
      '#type' => 'markup',
      '#markup' => '<h3 class="ttd-section-title ttd-meta-generator-settings-title">' . $this->t('Meta Generator Settings') . '</h3>',
      '#weight' => 11,
    ];

    $default_meta_seo_prompt = "- Natural keyword integration (no keyword stuffing)\n- E-E-A-T tone (authoritative, trustworthy)\n- Compelling and click-worthy\n- Each variation should have a different angle/approach";
    $default_meta_social_prompt = "- Create curiosity gaps that make people want to click\n- Use emotional hooks (surprise, urgency, FOMO)\n- No keyword stuffing -- this is social, not search\n- Each variation should use a different hook style";

    $title_case_value = $config->get('meta_title_case_format') ?: 'title';

    $form['tabs_container']['content']['setup']['meta_title_case_format'] = [
      '#type' => 'radios',
      '#title' => $this->t('Title Casing'),
      '#options' => [
        'title' => $this->t('Title Case'),
        'sentence' => $this->t('Sentence case'),
      ],
      '#default_value' => $title_case_value,
      '#description' => $this->t('Controls whether generated meta titles use Title Case or Sentence case'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-button-group-radios']],
      '#weight' => 12,
      '#states' => [
        'visible' => [
          ':input[name="enable_meta_generator"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['tabs_container']['content']['setup']['meta_seo_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SEO Prompt'),
      '#default_value' => $config->get('meta_seo_prompt') ?: $default_meta_seo_prompt,
      '#description' => $this->t('Instructions for generating SEO meta titles and descriptions. Character limits and output format are handled automatically.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#rows' => 6,
      '#weight' => 13,
      '#states' => [
        'visible' => [
          ':input[name="enable_meta_generator"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['tabs_container']['content']['setup']['meta_social_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Social Prompt'),
      '#default_value' => $config->get('meta_social_prompt') ?: $default_meta_social_prompt,
      '#description' => $this->t('Instructions for generating social media og:title and og:description. These appear in link cards on Facebook, Twitter/X, LinkedIn, etc.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#rows' => 6,
      '#weight' => 14,
      '#states' => [
        'visible' => [
          ':input[name="enable_meta_generator"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // =========================================================================
    // Content Tab
    // =========================================================================
    $form['tabs_container']['content']['content_tab'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-content'],
    ];

    $form['tabs_container']['content']['content_tab']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Content</h2>',
    ];

    // Content Type Selection.
    $content_types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $content_type) {
      $content_type_options[$content_type->id()] = $content_type->label();
    }

    $form['tabs_container']['content']['content_tab']['enabled_content_types'] = [
      '#type' => 'select',
      '#title' => $this->t('Post Types'),
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
    // Pass debug mode setting to JavaScript
    $form['#attached']['drupalSettings']['ttd_topics']['debug_mode'] = $config->get('debug_mode') ?: FALSE;

    $form['tabs_container']['content']['content_tab']['include_excerpt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include Post Excerpt'),
      '#default_value' => $config->get('include_excerpt') ?: FALSE,
      '#description' => $this->t('Include excerpt content in topic analysis.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    // Get all field options for enabled content types
    $field_options = $this->getCustomFieldOptions($config);

    $form['tabs_container']['content']['content_tab']['analysis_custom_fields'] = [
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

    $form['tabs_container']['content']['content_tab']['new_topic_reanalysis_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Re-analyze on New Topic'),
      '#default_value' => $config->get('new_topic_reanalysis_enabled') ?: FALSE,
      '#description' => $this->t('Re-analyze recent posts when a new topic is discovered.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['content_tab']['new_topic_reanalysis_lookback_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Re-analysis Lookback'),
      '#default_value' => $config->get('new_topic_reanalysis_lookback_days') ?: 30,
      '#min' => 7,
      '#max' => 365,
      '#description' => $this->t('How far back to look for posts to re-analyze (7-365).'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['content_tab']['auto_sync_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-Sync'),
      '#default_value' => $config->get('auto_sync_enabled') ?: FALSE,
      '#description' => $this->t('When enabled, the module checks every 15 minutes and automatically pulls missing topics and relationships from the API in the background.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    // =========================================================================
    // Topic List Tab
    // =========================================================================
    $form['tabs_container']['content']['topiclist'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-topiclist'],
    ];

    $form['tabs_container']['content']['topiclist']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Topic List</h2>',
    ];

    $form['tabs_container']['content']['topiclist']['taxonomy_label_singular'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Taxonomy Label (Singular)'),
      '#default_value' => $config->get('taxonomy_label_singular') ?: 'Topic',
      '#description' => $this->t('Singular label used for topics throughout the interface (e.g., "Topic", "Tag", "Subject").'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['topiclist']['taxonomy_label_plural'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Taxonomy Label (Plural)'),
      '#default_value' => $config->get('taxonomy_label_plural') ?: 'Topics',
      '#description' => $this->t('Plural label used for topics throughout the interface (e.g., "Topics", "Tags", "Subjects").'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['topiclist']['topics_list_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label Above Topics List'),
      '#default_value' => $config->get('topics_list_label') ?: 'Topics on this page',
      '#description' => $this->t('The heading text displayed above the topics list (e.g., "Related Topics", "Tags", "Mentions").'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['topiclist']['maximum_visible_post_topics'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Visible Topics'),
      '#default_value' => $config->get('maximum_visible_post_topics') ?: 5,
      '#min' => 1,
      '#max' => 20,
      '#description' => $this->t('Recommended: 5-10.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['topiclist']['frontend_filter_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Filter Mode'),
      '#options' => [
        'all' => $this->t('Show all topics'),
        'mentions_behind_toggle' => $this->t('Hide mentions behind "Show More"'),
        'high_salience_only' => $this->t('Show only main & about topics'),
      ],
      '#default_value' => $config->get('frontend_filter_mode') ?: 'mentions_behind_toggle',
      '#description' => $this->t('Controls which topics are visible on the frontend.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['topiclist']['post_topic_minimum_display_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Display Count'),
      '#default_value' => $config->get('post_topic_minimum_display_count') ?: 10,
      '#min' => 0,
      '#max' => 100,
      '#description' => $this->t('Topics appearing in fewer posts are soft-hidden. High-salience topics always display.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['topiclist']['curation_scores_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Apply Curation Scores'),
      '#default_value' => $config->get('curation_scores_enabled') ?: FALSE,
      '#description' => $this->t('Use synced TopicalBoost curation scores to suppress off-topic entities from public topic lists, topic pages, and schema output. Default off; enable only after reviewing scores for this site.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    // =========================================================================
    // Behavior Tab
    // =========================================================================
    $form['tabs_container']['content']['behavior'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-behavior'],
    ];

    $form['tabs_container']['content']['behavior']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Behavior</h2>',
    ];

    $form['tabs_container']['content']['behavior']['enable_automatic_mentions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-append Topics'),
      '#default_value' => $config->get('enable_automatic_mentions') ?? TRUE,
      '#description' => $this->t('Automatically append topics to content.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['behavior']['auto_link_topics'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-link Topics'),
      '#default_value' => $config->get('auto_link_topics') ?: FALSE,
      '#description' => $this->t('Automatically link the first occurrence of each about/mainEntity topic name in the content body to its topic page.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['behavior']['skeleton_style'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skeleton Style'),
      '#default_value' => $config->get('skeleton_style') ?: FALSE,
      '#description' => $this->t('Enable minimal/skeleton styling for TopicalBoost frontend elements, allowing your theme to control most of the appearance.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    // =========================================================================
    // Analytics Tab
    // =========================================================================
    $form['tabs_container']['content']['analytics'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-analytics'],
      '#access' => FALSE,
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
          <p>To start viewing analytics, you need to enable TopicalBoost for one or more content types. Once enabled, you\'ll see comprehensive topic coverage data, content type breakdowns, and API comparison metrics.</p>
          <div class="empty-state-action">
            <a href="#content" class="ttd-nav-item ttd-topics-button-primary" data-tab="tab-content">
              Configure Content Types
            </a>
          </div>
        </div>',
      ];
    }
    else {
      // Add consolidated API Coverage Comparison Section
      $form['tabs_container']['content']['analytics']['coverage_section'] = $this->buildCoverageComparison($analytics);

      // Attach drupalSettings for coverage metrics
      $form['#attached']['drupalSettings']['ttdCoverage'] = [
        'ajaxUrl' => '/api/topicalboost/coverage/metrics',
      ];
    }

    // =========================================================================
    // Schema & URL Tab
    // =========================================================================
    $form['tabs_container']['content']['schema'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-schema'],
    ];

    $form['tabs_container']['content']['schema']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Schema &amp; URL</h2>',
    ];

    // URL Path Configuration
    $pattern_storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
    $current_pattern = $pattern_storage->load('ttd_topics');
    $current_full_pattern = $current_pattern ? $current_pattern->getPattern() : '/topics/[term:name]';
    $current_path_prefix = str_replace('[term:name]', '', $current_full_pattern);
    $stored_prefix = $config->get('topic_url_path_prefix');
    $default_prefix = $stored_prefix ?: $current_path_prefix;
    $search_api_enabled = $this->isSearchApiEnabled();
    $topic_url_mode_default = $config->get('topic_url_mode') ?: 'taxonomy_term';
    if (!$search_api_enabled && $topic_url_mode_default === 'archive_query') {
      $topic_url_mode_default = 'taxonomy_term';
    }
    $topic_url_mode_options = [
      'taxonomy_term' => $this->t('Taxonomy term pages'),
    ];
    if ($search_api_enabled) {
      $topic_url_mode_options['archive_query'] = $this->t('Existing Search API/archive page');
    }
    $archive_states = [
      'visible' => [
        ':input[name="topic_url_mode"]' => ['value' => 'archive_query'],
      ],
    ];
    $taxonomy_states = [
      'visible' => [
        ':input[name="topic_url_mode"]' => ['value' => 'taxonomy_term'],
      ],
    ];

    $form['tabs_container']['content']['schema']['topic_url_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Topic Link Destination'),
      '#options' => $topic_url_mode_options,
      '#default_value' => $topic_url_mode_default,
      '#description' => $this->t('Most sites should use taxonomy term pages. Choose the archive option only when this site already routes topic filtering through Search API, Facets, or another listing page.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-button-group-radios']],
    ];

    if (!$search_api_enabled) {
      $form['tabs_container']['content']['schema']['topic_archive_unavailable'] = [
        '#type' => 'item',
        '#title' => $this->t('Search/archive topic links'),
        '#markup' => '<p>' . $this->t('Search/archive topic links are available after the Search API module is installed and enabled. Until then, TopicalBoost uses normal taxonomy term links.') . '</p>',
        '#attributes' => ['class' => ['ttd-topics-field-group']],
      ];
    }

    $form['tabs_container']['content']['schema']['topic_archive_help'] = [
      '#type' => 'item',
      '#title' => $this->t('Archive topic link setup'),
      '#markup' => '<p>' . $this->t('Use this only for sites that already have a working archive/search page for topic filtering. TopicalBoost builds the link URL; Drupal still owns the Search API index, archive View, and Facets or exposed-filter logic that make that URL return the right articles.') . '</p>',
      '#states' => $archive_states,
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $form['tabs_container']['content']['schema']['topic_archive_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Archive Path'),
      '#default_value' => $config->get('topic_archive_path') ?: '',
      '#description' => $this->t('Internal path or absolute URL for the archive/listing page, such as /issues or /search. Existing query parameters are preserved.'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => '/search',
      ],
      '#states' => $archive_states,
    ];

    $form['tabs_container']['content']['schema']['topic_archive_query_parameter'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Topic Query Parameter'),
      '#default_value' => $config->get('topic_archive_query_parameter') ?: 'topic',
      '#description' => $this->t('Parameter the archive page expects, for example topic or f[0].'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => 'topic',
      ],
      '#states' => $archive_states,
    ];

    $form['tabs_container']['content']['schema']['topic_archive_value_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Topic Query Value'),
      '#options' => [
        'term_id' => $this->t('Drupal term ID'),
        'ttd_id' => $this->t('TopicalBoost topic ID'),
        'term_uuid' => $this->t('Drupal term UUID'),
        'term_slug' => $this->t('Term label slug'),
      ],
      '#default_value' => $config->get('topic_archive_value_source') ?: 'term_id',
      '#description' => $this->t('Match this to the indexed field used by the archive/search page. Term ID is the safest default for normal Drupal entity reference indexes.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#states' => $archive_states,
    ];

    $form['tabs_container']['content']['schema']['topic_archive_value_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Topic Query Value Pattern'),
      '#default_value' => $config->get('topic_archive_value_template') ?: '[value]',
      '#description' => $this->t('Usually [value]. For Facets-style values, include the facet field prefix, such as field_ttd_topics:[value]. Available tokens: [value], [term_id], [ttd_id], [uuid], [slug], [name].'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => '[value]',
      ],
      '#states' => $archive_states,
    ];

    $setup_manager = \Drupal::service('ttd_topics.search_archive_setup');
    $archive_view_options = $search_api_enabled
      ? $setup_manager->getCandidateOptions($config->get('topic_archive_path') ?: '')
      : [];
    $archive_view_default = (string) $config->get('topic_archive_view');
    if ($archive_view_default === '') {
      $archive_view_default = $setup_manager->suggestCandidate($config->get('topic_archive_path') ?: '');
    }

    $form['tabs_container']['content']['schema']['topic_archive_managed_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let TopicalBoost configure archive filtering'),
      '#default_value' => (bool) $config->get('topic_archive_managed_filter'),
      '#description' => $this->t('TopicalBoost will add its missing topic ID field to the selected Search API index, apply a hidden URL filter to that View, and queue the index for reindexing. It does not create or display a facet.'),
      '#disabled' => empty($archive_view_options),
      '#states' => $archive_states,
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    $managed_filter_states = [
      'visible' => [
        ':input[name="topic_url_mode"]' => ['value' => 'archive_query'],
        ':input[name="topic_archive_managed_filter"]' => ['checked' => TRUE],
      ],
    ];
    $form['tabs_container']['content']['schema']['topic_archive_view'] = [
      '#type' => 'select',
      '#title' => $this->t('Archive Search API View'),
      '#options' => ['' => $this->t('- Select the existing archive View -')] + $archive_view_options,
      '#default_value' => $archive_view_default,
      '#description' => $this->t('Choose the existing Search API page display that uses the archive path above. TopicalBoost changes only the query when its topic parameter is present.'),
      '#states' => $managed_filter_states,
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    if ($search_api_enabled && empty($archive_view_options)) {
      $form['tabs_container']['content']['schema']['topic_archive_managed_unavailable'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $this->t('TopicalBoost could not find an enabled Search API page View. Create or enable the archive View first, then return here for automatic filtering.') . '</p>',
        '#states' => $archive_states,
        '#attributes' => ['class' => ['ttd-topics-field-group']],
      ];
    }

    $form['tabs_container']['content']['schema']['topic_archive_status'] = $this->buildTopicArchiveStatus($config);
    $form['tabs_container']['content']['schema']['topic_archive_status']['#states'] = $archive_states;

    $form['tabs_container']['content']['schema']['topic_url_path_prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rewrite Slug'),
      '#default_value' => $default_prefix,
      '#description' => $this->t('URL path prefix for topic pages.'),
      '#attributes' => [
        'class' => ['ttd-topics-field-group'],
        'placeholder' => '/topics/',
        'id' => 'topic-url-path-input'
      ],
      '#states' => $taxonomy_states,
    ];

    $form['tabs_container']['content']['schema']['url_preview'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-url-preview']],
      'content' => [
        '#markup' => 'Preview: <span class="ttd-url-display">' . \Drupal::request()->getSchemeAndHttpHost() . '<span id="url-path-preview" class="ttd-url-slug">' . $default_prefix . '</span>artificial-intelligence</span>',
      ],
      '#attached' => [
        'library' => ['ttd_topics/url_preview'],
      ],
      '#states' => $taxonomy_states,
    ];

    $form['tabs_container']['content']['schema']['required_permission'] = [
      '#type' => 'select',
      '#title' => $this->t('Required Capability'),
      '#options' => $this->getPermissionOptions(),
      '#default_value' => $config->get('required_permission') ?: 'administer topicalboost',
      '#description' => $this->t('The Drupal permission a user needs to manage TopicalBoost topics.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
    ];

    // Preserve organization profile settings for existing sites. These are not
    // shown on the settings page because the WordPress settings page has no
    // matching controls.
    $form['tabs_container']['content']['schema']['organization_facebook_url'] = [
      '#type' => 'hidden',
      '#default_value' => $config->get('organization_facebook_url'),
    ];

    $form['tabs_container']['content']['schema']['organization_twitter_url'] = [
      '#type' => 'hidden',
      '#default_value' => $config->get('organization_twitter_url'),
    ];

    $form['tabs_container']['content']['schema']['organization_linkedin_url'] = [
      '#type' => 'hidden',
      '#default_value' => $config->get('organization_linkedin_url'),
    ];

    $form['tabs_container']['content']['schema']['organization_youtube_url'] = [
      '#type' => 'hidden',
      '#default_value' => $config->get('organization_youtube_url'),
    ];

    $form['tabs_container']['content']['schema']['organization_wikipedia_url'] = [
      '#type' => 'hidden',
      '#default_value' => $config->get('organization_wikipedia_url'),
    ];

    $form['tabs_container']['content']['schema']['block_until_analyzed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Block Until Analyzed'),
      '#default_value' => $config->get('block_until_analyzed') ?: FALSE,
      '#description' => $this->t('Hold content until topic analysis is complete.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['schema']['disable_event_temporal_properties'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Output events as Things'),
      '#default_value' => $config->get('disable_event_temporal_properties') ?: FALSE,
      '#description' => $this->t('When enabled, Event entities will be output as generic "Thing" type in schema markup, without temporal properties (startDate, endDate, location). Useful if event data is outdated or incorrect.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['schema']['hide_seo_module_ui'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Hide SEO Plugin UI'),
      '#default_value' => $config->get('hide_seo_module_ui') ?: FALSE,
      '#description' => $this->t('Hide other SEO plugin metaboxes and columns.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['schema']['branding'] = [
      '#type' => 'hidden',
      '#default_value' => $config->get('organization_logo_fid') ?: '',
    ];

    // =========================================================================
    // Authors Tab
    // =========================================================================
    $author_helper = \Drupal::service('ttd_topics.author_manager_helper');
    $author_rows = $author_helper->getContentTypeRows();
    $author_content_type_options = [];
    foreach ($author_rows as $row) {
      $author_content_type_options[$row['id']] = $row['label'] . ' (' . number_format($row['count']) . ')';
    }
    $author_settings = [
      'author_name_field' => $config->get('author_name_field') ?: '',
      'author_image_field' => $config->get('author_image_field') ?: '',
      'author_description_field' => $config->get('author_description_field') ?: '',
    ];
    $selected_author_field = $config->get('author_field_name') ?: 'uid';

    $form['#attached']['library'][] = 'ttd_topics/author_manager';
    $form['#attached']['drupalSettings']['ttd_topics']['authorFieldMappingUrl'] = Url::fromRoute('topicalboost.api.author_field_mapping')->toString();

    $form['tabs_container']['content']['authors'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-authors'],
    ];

    $form['tabs_container']['content']['authors']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Custom Author Manager</h2>
        <p class="description">Manage Drupal author visibility, replace default authors with custom author data from users, taxonomy terms, or content references, and generate proper Person schema for SEO.</p>',
    ];

    $form['tabs_container']['content']['authors']['author_manager_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable custom author management'),
      '#default_value' => $config->get('author_manager_enabled') ?: FALSE,
      '#description' => $this->t('Controls author visibility, custom author schema, and author support features.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<table class="form-table ttd-author-manager-table"><tr><th scope="row">' . $this->t('Enable Author Manager') . '</th><td><div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#wrapper_attributes' => ['class' => ['ttd-author-manager-enable']],
    ];
    $form['tabs_container']['content']['authors']['author_manager_enabled_close'] = [
      '#markup' => '</td></tr></table>',
    ];

    $form['tabs_container']['content']['authors']['settings'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ttd-author-manager-settings',
        'style' => ($config->get('author_manager_enabled') ?: FALSE) ? '' : 'display:none;',
      ],
    ];

    $hide_author_types = array_filter($config->get('author_hide_content_types') ?: []);
    $form['tabs_container']['content']['authors']['settings']['visibility_intro'] = [
      '#markup' => '<h3>Hide Drupal Authors</h3>
        <p class="description">Hide Drupal\'s default author controls and byline output for selected content types. The underlying node author data stays unchanged.</p>
        <table class="form-table ttd-author-manager-table">',
    ];

    $form['tabs_container']['content']['authors']['settings']['author_hide_content_types'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    if ($author_rows) {
      foreach ($author_rows as $row) {
        $form['tabs_container']['content']['authors']['settings']['author_hide_content_types'][$row['id']] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Hide Drupal author for @label', ['@label' => $row['label']]),
          '#default_value' => in_array($row['id'], $hide_author_types, TRUE),
          '#prefix' => '<tr><th scope="row">' . htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') . '</th><td><div class="ttd-topics-toggle-field ttd-author-toggle-row">',
          '#suffix' => '<span class="description ttd-author-count">(' . number_format($row['count']) . ' posts)</span></div></td></tr>',
        ];
      }
    }
    else {
      $form['tabs_container']['content']['authors']['settings']['visibility_empty'] = [
        '#markup' => '<tr><td colspan="2"><p class="description">No content types found.</p></td></tr>',
      ];
    }
    $form['tabs_container']['content']['authors']['settings']['visibility_close'] = [
      '#markup' => '</table>',
    ];

    $form['tabs_container']['content']['authors']['settings']['schema_title'] = [
      '#markup' => '<h3>Custom Author Schema</h3>
        <p class="description">Configure custom author fields used in TopicalBoost schema output. This replaces the default Drupal author in structured data with your custom author source.</p>
        <table class="form-table ttd-author-manager-table">',
    ];

    $form['tabs_container']['content']['authors']['settings']['custom_author_schema_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace Drupal author schema with custom author data'),
      '#default_value' => $config->get('custom_author_schema_enabled') ?: FALSE,
      '#description' => $this->t('Adds schema.org Person authors to TopicalBoost JSON-LD.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<tr><th scope="row">' . $this->t('Enable Custom Author Schema') . '</th><td><div class="ttd-topics-toggle-field ttd-author-toggle-row">',
      '#suffix' => '</div></td></tr>',
    ];

    $form['tabs_container']['content']['authors']['settings']['author_field_name'] = [
      '#type' => 'select',
      '#title' => $this->t('Author Field'),
      '#title_display' => 'invisible',
      '#options' => $author_helper->getAuthorFieldOptions(array_filter($config->get('enabled_content_types') ?: [])),
      '#default_value' => $selected_author_field,
      '#description' => $this->t('Entity reference field that contains your authors.'),
      '#attributes' => ['class' => ['ttd-topics-field-group'], 'id' => 'ttd-author-field-name'],
      '#prefix' => '<tr><th scope="row"><label for="ttd-author-field-name">' . $this->t('Author Field') . '</label></th><td>',
      '#suffix' => '</td></tr></table>',
    ];

    $form['tabs_container']['content']['authors']['settings']['mapping'] = $author_helper->buildMappingForm(
      $selected_author_field,
      $author_settings,
      array_filter($config->get('enabled_content_types') ?: [])
    );

    // =========================================================================
    // Widgets Tab
    // =========================================================================
    $form['tabs_container']['content']['widgets'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-widgets'],
    ];

    $form['tabs_container']['content']['widgets']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Widgets</h2>',
    ];

    $form['tabs_container']['content']['widgets']['search_clippings_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Top Stories'),
      '#default_value' => $config->get('search_clippings_enabled') ?: FALSE,
      '#description' => $this->t('Tracks your content appearances in Google Top Stories.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['widgets']['citations_section'] = [
      '#markup' => '',
    ];

    $form['tabs_container']['content']['widgets']['citations_widget_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Citations'),
      '#default_value' => $config->get('citations_widget_enabled') ?: FALSE,
      '#description' => $this->t('Tracks how often your content is cited across the web.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
    ];

    $form['tabs_container']['content']['widgets']['citations_widget_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Citations Limit'),
      '#default_value' => $config->get('citations_widget_limit') ?: 20,
      '#min' => 5,
      '#max' => 100,
      '#description' => $this->t('Maximum number of citations to display in the widget.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#states' => [
        'visible' => [
          ':input[name="citations_widget_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['tabs_container']['content']['widgets']['search_countries_section'] = [
      '#markup' => '<h4>Monitored Countries</h4>',
    ];

    // Build country options
    $country_options = [
      'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada',
      'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France', 'ES' => 'Spain',
      'IT' => 'Italy', 'NL' => 'Netherlands', 'BR' => 'Brazil', 'IN' => 'India',
      'JP' => 'Japan', 'MX' => 'Mexico', 'SE' => 'Sweden', 'NO' => 'Norway',
      'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'BE' => 'Belgium',
      'AT' => 'Austria', 'CH' => 'Switzerland', 'IE' => 'Ireland', 'NZ' => 'New Zealand',
      'SG' => 'Singapore', 'ZA' => 'South Africa', 'PT' => 'Portugal',
      'AR' => 'Argentina', 'CL' => 'Chile', 'CO' => 'Colombia',
      'KR' => 'South Korea', 'TW' => 'Taiwan', 'HK' => 'Hong Kong',
      'PH' => 'Philippines', 'TH' => 'Thailand', 'MY' => 'Malaysia',
      'ID' => 'Indonesia', 'VN' => 'Vietnam', 'IL' => 'Israel',
      'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia',
      'TR' => 'Turkey', 'RU' => 'Russia', 'UA' => 'Ukraine',
      'CZ' => 'Czech Republic', 'RO' => 'Romania', 'HU' => 'Hungary',
      'GR' => 'Greece', 'HR' => 'Croatia', 'BG' => 'Bulgaria',
    ];
    asort($country_options);

    $form['tabs_container']['content']['widgets']['search_countries'] = [
      '#type' => 'select',
      '#title' => $this->t('Monitored Countries'),
      '#options' => $country_options,
      '#default_value' => $config->get('search_countries') ?: ['US'],
      '#description' => $this->t('Select countries for search demand data. Changes are pushed to the API on save.'),
      '#multiple' => TRUE,
      '#attributes' => [
        'class' => ['ttd-topics-field-group', 'ttd-topics-select2'],
        'data-placeholder' => 'Select countries...',
      ],
    ];

    // =========================================================================
    // Developer Tab
    // =========================================================================
    $form['tabs_container']['content']['developer'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-developer'],
    ];

    $form['tabs_container']['content']['developer']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Developer</h2>',
    ];

    $form['tabs_container']['content']['developer']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug Mode'),
      '#default_value' => $config->get('debug_mode') ?: FALSE,
      '#description' => $this->t('Enable detailed console logging for debugging. Disable this in production for better performance.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#weight' => 0,
    ];

    $form['tabs_container']['content']['developer']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $config->get('batch_size') ?: 35,
      '#min' => 5,
      '#max' => 100,
      '#description' => $this->t('Number of posts to send per batch during bulk analysis. Lower values are safer for servers with limited resources. Default: 35.'),
      '#attributes' => ['class' => ['ttd-topics-field-group']],
      '#weight' => 4,
    ];

    $form['tabs_container']['content']['developer']['beta_channel'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Plugin Updates'),
      '#default_value' => $config->get('beta_channel') ?: FALSE,
      '#description' => $this->t('Get early access to new module features and UI changes.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#weight' => 1,
    ];

    $form['tabs_container']['content']['developer']['use_beta_api'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Beta Analysis'),
      '#default_value' => $config->get('use_beta_api') ?: FALSE,
      '#description' => $this->t('Routes analysis requests to the beta server for testing improvements.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#weight' => 2,
    ];

    $form['tabs_container']['content']['developer']['error_telemetry_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Error Telemetry'),
      '#default_value' => $config->get('error_telemetry_enabled') ?? TRUE,
      '#description' => $this->t('Send anonymous error reports to help improve TopicalBoost. No personal or content data is included.'),
      '#attributes' => ['class' => ['ttd-topics-field-group', 'ttd-topics-toggle']],
      '#prefix' => '<div class="ttd-topics-toggle-field">',
      '#suffix' => '</div>',
      '#weight' => 3,
    ];

    $form['tabs_container']['content']['developer']['alternative_display'] = [
      '#markup' => Markup::create('<div class="form-item ttd-alternative-display"><label class="form-item__label">' . $this->t('Alternative Display') . '</label><div><p class="description">' . $this->t('Alternative methods for displaying topics when auto-append does not work with your theme.') . '</p><strong>Twig</strong><pre class="ttd-code-example"><code>{{ topicalboost_display() }}</code></pre><strong>Data</strong><pre class="ttd-code-example"><code>{{ topicalboost_data() }}</code></pre></div></div>'),
      '#weight' => 5,
    ];

    // =========================================================================
    // Bulk Analysis Tab
    // =========================================================================
    $form['tabs_container']['content']['bulk_analysis'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-bulk-analysis'],
      '#prefix' => '<div id="ttd-bulk-analysis-form">',
      '#suffix' => '</div>',
      '#access' => FALSE,
    ];

    // Check if content types are enabled for bulk analysis
    $bulk_enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);
    if (empty($bulk_enabled_content_types)) {
      // Show empty state
      $form['tabs_container']['content']['bulk_analysis']['empty_state'] = [
        '#markup' => '<div class="ttd-topics-empty-state">
          <div class="empty-state-icon">
            <span class="ttd-icon ttd-icon-large ttd-icon-settings"></span>
          </div>
          <h3>No Content Types Enabled</h3>
          <p>To perform bulk analysis, you need to enable TopicalBoost for one or more content types that have published posts.</p>
          <div class="empty-state-action">
            <a href="#content" class="ttd-nav-item ttd-topics-button-primary" data-tab="tab-content">
              Configure Content Types
            </a>
          </div>
        </div>',
      ];
    } else {
      // Build bulk analysis content inline
      $bulk_content_types = \Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple();
      $bulk_content_type_options = [];
      foreach ($bulk_content_types as $content_type) {
        $bulk_content_type_options[$content_type->id()] = $content_type->label();
      }

      // Date Range Section
      $form['tabs_container']['content']['bulk_analysis']['date_range'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-bulk-analysis-section']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['date_range']['header'] = [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-section-header">
          <h3 class="ttd-section-title">
            <svg class="ttd-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd" />
            </svg>
            ' . $this->t('Date Range') . '
          </h3>
        </div>',
      ];

      $form['tabs_container']['content']['bulk_analysis']['date_range']['content'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-section-content']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['date_range']['content']['unified_component'] = [
        '#type' => 'markup',
        '#markup' => Markup::create('
        <div class="ttd-unified-date-range">
          <div class="ttd-date-range-buttons">
            <span class="button ttd-date-range-btn" data-days="all">All Time</span>
            <span class="button ttd-date-range-btn" data-days="7">Last Week</span>
            <span class="button ttd-date-range-btn active" data-days="30">Last Month</span>
            <span class="button ttd-date-range-btn" data-days="90">Last 3M</span>
            <span class="button ttd-date-range-btn" data-days="180">Last 6M</span>
            <span class="button ttd-date-range-btn" data-days="365">Last 12M</span>
          </div>
          <div class="ttd-custom-date-range">
            <span class="description">Or select custom range:</span>
            <div class="modern-date-range">
              <div class="date-input-group">
                <label for="ttd-bulk-analysis-start-date" class="date-label">From</label>
                <input type="date"
                       id="ttd-bulk-analysis-start-date"
                       name="start_date"
                       class="modern-date-input"
                       value="' . date('Y-m-d', strtotime('-30 days')) . '">
              </div>
              <div class="date-separator">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
              </div>
              <div class="date-input-group">
                <label for="ttd-bulk-analysis-end-date" class="date-label">To</label>
                <input type="date"
                       id="ttd-bulk-analysis-end-date"
                       name="end_date"
                       class="modern-date-input"
                       value="' . date('Y-m-d') . '">
              </div>
            </div>
          </div>
        </div>'),
      ];

      // Analysis Options
      $form['tabs_container']['content']['bulk_analysis']['options'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-bulk-analysis-section']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['options']['header'] = [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-section-header">
          <h3 class="ttd-section-title">
            <svg class="ttd-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
            </svg>
            ' . $this->t('Analysis Options') . '
          </h3>
        </div>',
      ];

      $form['tabs_container']['content']['bulk_analysis']['options']['content'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-section-content']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['options']['content']['reanalyze'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Reanalyze'),
        '#description' => $this->t('Include already analyzed content if enabled'),
      ];

      $form['tabs_container']['content']['bulk_analysis']['options']['content']['include_drafts'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Include Drafts'),
        '#description' => $this->t('Include draft content in analysis'),
      ];

      // Content Type Selection
      $form['tabs_container']['content']['bulk_analysis']['content_types'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-bulk-analysis-section']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['content_types']['header'] = [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-section-header">
          <h3 class="ttd-section-title">
            <svg class="ttd-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
              <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" />
              <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v6a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 2a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd" />
            </svg>
            ' . $this->t('Content Types') . '
          </h3>
        </div>',
      ];

      $form['tabs_container']['content']['bulk_analysis']['content_types']['content'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-section-content']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['content_types']['content']['help'] = [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-filter-group">
          <div class="ttd-filter-header">
            <span class="ttd-section-label">Default from Settings • Click to Add/Remove for This Analysis</span>
            <a href="' . Url::fromRoute('topicalboost.settings_form')->toString() . '#content" class="ttd-edit-link ttd-edit-link--pencil" title="Edit Default in Settings">
            </a>
          </div>
        </div>',
      ];

      $form['tabs_container']['content']['bulk_analysis']['content_types']['content']['types_grid'] = [
        '#type' => 'markup',
        '#markup' => $this->buildContentTypesGrid($bulk_content_type_options, $bulk_enabled_content_types),
      ];

      // Selection Status
      $form['tabs_container']['content']['bulk_analysis']['selection_status'] = [
        '#type' => 'markup',
        '#markup' => '<div id="ttd-selection-status" class="ttd-selection-status">
          <div class="ttd-selection-info">
            <span class="ttd-selection-count">Calculating...</span>
            <span class="ttd-selection-text">content items selected for analysis</span>
          </div>
        </div>',
      ];

      // Progress Bars
      $form['tabs_container']['content']['bulk_analysis']['progress'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'ttd-bulk-analysis-progress',
          'class' => ['ttd-progress-container'],
          'style' => 'display: none;',
        ],
      ];

      $form['tabs_container']['content']['bulk_analysis']['progress']['section'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-progress-section']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['progress']['section']['title'] = [
        '#type' => 'markup',
        '#markup' => '<h4>Analysis Progress</h4>',
      ];

      $form['tabs_container']['content']['bulk_analysis']['progress']['section']['bar'] = [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-progress-bar">
          <div id="ttd-bulk-analysis-progress-bar" class="ttd-progress-fill"></div>
        </div>',
      ];

      $form['tabs_container']['content']['bulk_analysis']['progress']['section']['text'] = [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-progress-text">
          <span id="ttd-progress-completed">0</span> of <span id="ttd-progress-total">0</span> items processed
        </div>',
      ];

      // Message Area
      $form['tabs_container']['content']['bulk_analysis']['message'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'ttd-bulk-analysis-message',
          'class' => ['ttd-message-container'],
          'style' => 'display: none;',
        ],
      ];

      // Action Buttons
      $form['tabs_container']['content']['bulk_analysis']['actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ttd-bulk-analysis-actions']],
      ];

      $form['tabs_container']['content']['bulk_analysis']['actions']['analyze'] = [
        '#type' => 'button',
        '#value' => $this->t('Analyze Content'),
        '#attributes' => [
          'id' => 'ttd-bulk-analysis-analyze-button',
          'class' => ['button', 'button--primary'],
        ],
        '#button_type' => 'button',
      ];

      $form['tabs_container']['content']['bulk_analysis']['actions']['reset'] = [
        '#type' => 'button',
        '#value' => $this->t('Cancel Analysis'),
        '#attributes' => [
          'id' => 'ttd-bulk-analysis-reset-button',
          'class' => ['button', 'button--danger'],
          'style' => 'display: none;',
        ],
        '#button_type' => 'button',
      ];

      // Add drupalSettings for bulk analysis
      $form['#attached']['drupalSettings']['ttd_topics']['bulk_analysis_endpoints'] = [
        'count' => Url::fromRoute('topicalboost.bulk_analysis.count')->toString(),
        'initiate' => Url::fromRoute('topicalboost.bulk_analysis.initiate')->toString(),
        'progress' => Url::fromRoute('topicalboost.bulk_analysis.progress')->toString(),
        'reset' => Url::fromRoute('topicalboost.bulk_analysis.reset')->toString(),
        'poll' => Url::fromRoute('topicalboost.bulk_analysis.poll')->toString(),
        'apply_results' => Url::fromRoute('topicalboost.bulk_analysis.apply_results')->toString(),
      ];
      $form['#attached']['drupalSettings']['ttd_topics']['nonce'] = \Drupal::csrfToken()->get('ttd_bulk_analysis');
      $form['#attached']['drupalSettings']['ttd_topics']['enabled_content_types'] = $bulk_enabled_content_types;
    }

    // =========================================================================
    // Watchlist Tab
    // =========================================================================
    $form['tabs_container']['content']['watchlist'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-watchlist'],
    ];

    $form['tabs_container']['content']['watchlist']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Entity Watchlist</h2>',
    ];

    $form['tabs_container']['content']['watchlist']['description'] = [
      '#markup' => '<p class="description">' . $this->t('Entities on the watchlist are always checked during analysis. Use this for niche topics your publication frequently covers that analysis might otherwise miss.') . '</p>',
    ];

    $form['tabs_container']['content']['watchlist']['search_container'] = [
      '#markup' => Markup::create('<div class="ttd-watchlist-search-wrapper">
        <label for="ttd-watchlist-search">' . $this->t('Add Entity') . '</label>
        <input type="text" id="ttd-watchlist-search" class="form-text" placeholder="' . $this->t('Search for an entity...') . '" autocomplete="off" />
        <div class="ttd-watchlist-spinner" id="ttd-watchlist-spinner"></div>
        <div class="ttd-watchlist-results" id="ttd-watchlist-results" style="display:none;"></div>
      </div>
      <div id="ttd-watchlist-feedback"></div>'),
    ];

    $form['tabs_container']['content']['watchlist']['items_container'] = [
      '#markup' => Markup::create('<div class="ttd-watchlist-items-wrapper">
        <h4>' . $this->t('Watchlist (<span id="ttd-watchlist-count"><span>0</span></span>/50)') . '</h4>
        <div class="ttd-watchlist-items" id="ttd-watchlist-items">
          <p class="ttd-watchlist-empty">Loading watchlist...</p>
        </div>
      </div>'),
    ];

    $form['#attached']['library'][] = 'ttd_topics/watchlist';

    // =========================================================================
    // Sync Tab
    // =========================================================================
    $form['tabs_container']['content']['sync'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-sync'],
      '#access' => FALSE,
    ];

    $form['tabs_container']['content']['sync']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Sync</h2>',
    ];

    $form['tabs_container']['content']['sync']['content'] = [
      '#markup' => '<div id="ttd-sync-container">
        <div id="ttd-sync-status-label" style="display:none;">
          <span class="ttd-sync-dot"></span>
          <span class="ttd-sync-label">Checking...</span>
        </div>

        <div class="ttd-sync-cards">
          <div class="ttd-sync-card">
            <div class="ttd-sync-card-header">
              <span class="ttd-sync-card-title">' . $this->t('Topics') . '</span>
              <span class="ttd-sync-card-icon" id="sync-status-topics"></span>
            </div>
            <div class="ttd-sync-card-counts">
              <div class="ttd-sync-card-count">
                <span class="ttd-sync-card-number" id="sync-site-topics">&mdash;</span>
                <span class="ttd-sync-card-label">' . $this->t('Site') . '</span>
              </div>
              <div class="ttd-sync-card-count">
                <span class="ttd-sync-card-number" id="sync-api-topics">&mdash;</span>
                <span class="ttd-sync-card-label">' . $this->t('API') . '</span>
              </div>
            </div>
            <div class="ttd-sync-card-hint" id="sync-hint-topics" style="display:none;"></div>
          </div>

          <div class="ttd-sync-card">
            <div class="ttd-sync-card-header">
              <span class="ttd-sync-card-title">' . $this->t('Relationships') . '</span>
              <span class="ttd-sync-card-icon" id="sync-status-rels"></span>
            </div>
            <div class="ttd-sync-card-counts">
              <div class="ttd-sync-card-count">
                <span class="ttd-sync-card-number" id="sync-site-rels">&mdash;</span>
                <span class="ttd-sync-card-label">' . $this->t('Site') . '</span>
              </div>
              <div class="ttd-sync-card-count">
                <span class="ttd-sync-card-number" id="sync-api-rels">&mdash;</span>
                <span class="ttd-sync-card-label">' . $this->t('API') . '</span>
              </div>
            </div>
            <div class="ttd-sync-card-hint" id="sync-hint-rels" style="display:none;"></div>
          </div>
        </div>

        <div id="ttd-sync-progress" style="display:none;">
          <div class="ttd-sync-progress-bar">
            <div class="ttd-sync-progress-fill" id="sync-progress-fill" style="width:0%;"></div>
          </div>
          <div class="ttd-sync-progress-info">
            <span id="sync-progress-text">0%</span>
            <span id="sync-progress-stage"></span>
          </div>
        </div>

        <div id="ttd-sync-result" style="display:none;"></div>
        <div id="ttd-sync-summary" style="display:none;"></div>

        <div class="ttd-sync-actions">
          <button type="button" class="button button--primary" id="ttd-sync-btn" style="display:none;">' . $this->t('Sync Now') . '</button>
          <button type="button" class="button" id="ttd-sync-cancel" style="display:none;">' . $this->t('Cancel') . '</button>
        </div>
      </div>',
    ];

    $form['#attached']['library'][] = 'ttd_topics/sync';

    // =========================================================================
    // Troubleshoot Tab
    // =========================================================================
    $form['tabs_container']['content']['troubleshoot'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-troubleshoot'],
      '#access' => FALSE,
    ];

    $form['tabs_container']['content']['troubleshoot']['panel_title'] = [
      '#markup' => '<h2 class="ttd-panel-title">Troubleshoot</h2>',
    ];

    $form['tabs_container']['content']['troubleshoot']['content'] = [
      '#markup' => '<div id="ttd-troubleshoot-container">
        <p class="description">' . $this->t('Manage stuck posts and clear analysis flags.') . '</p>
        <p><a href="' . Url::fromRoute('topicalboost.troubleshoot')->toString() . '" class="button">' . $this->t('Open Troubleshoot Page') . '</a></p>
      </div>',
    ];

    // Changelog panel.
    $form['tabs_container']['content']['changelog'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-settings-panel'], 'id' => 'tab-changelog'],
    ];
    $form['tabs_container']['content']['changelog']['content'] = [
      '#markup' => '<h2>Changelog</h2><div id="ttd-changelog-container"><p class="ttd-changelog-loading">Loading changelog...</p></div>',
    ];
    $form['#attached']['library'][] = 'ttd_topics/changelog';

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build the content types grid markup for bulk analysis.
   *
   * @param array $content_type_options
   *   Array of content type options.
   * @param array $enabled_content_types
   *   Array of enabled content types.
   *
   * @return string
   *   HTML markup for the grid.
   */
  private function buildContentTypesGrid($content_type_options, $enabled_content_types) {
    // Get post counts for each content type
    $post_counts = [];
    foreach ($content_type_options as $content_type => $label) {
      $count = $this->database->query("
        SELECT COUNT(*)
        FROM {node_field_data}
        WHERE type = :type AND status = 1
      ", [':type' => $content_type])->fetchField();
      $post_counts[$content_type] = (int) $count;
    }

    $markup = '<div class="ttd-content-type-grid">';

    foreach ($content_type_options as $content_type => $label) {
      $is_enabled = in_array($content_type, $enabled_content_types);
      $enabled_class = $is_enabled ? 'ttd-enabled' : '';
      $disabled_attr = $is_enabled ? '' : 'disabled';
      $post_count = $post_counts[$content_type];
      $count_class = $post_count > 0 ? 'has-posts' : 'no-posts';

      $markup .= '<div class="ttd-content-type-card ' . $enabled_class . '" data-content-type="' . $content_type . '" tabindex="0">
        <div class="ttd-content-type-checkbox"></div>
        <div class="ttd-content-type-info">
          <div class="ttd-content-type-name">' . htmlspecialchars($label) . ' <span class="post-count ' . $count_class . '">(' . number_format($post_count) . ')</span></div>
        </div>
        <input type="hidden" name="ttd_bulk_analysis_content_types[]" value="' . htmlspecialchars($content_type) . '" class="ttd-content-type-input" ' . $disabled_attr . '>
      </div>';
    }

    $markup .= '</div>';
    return $markup;
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
        'total_relationships' => 0,
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
        'total_relationships' => 0,
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

    // Get average topics per post
    $relationships_count = $this->database->query("
      SELECT COUNT(DISTINCT t.entity_id, t.field_ttd_topics_target_id)
      FROM {node__field_ttd_topics} t
      INNER JOIN {node_field_data} n ON t.entity_id = n.nid
      WHERE n.status = 1 AND n.type IN (:types[])
    ", [':types[]' => $enabled_content_types])->fetchField();

    $avg_topics_per_post = $total_posts > 0 ? round($relationships_count / $total_posts, 2) : 0;

    // Get total relationships (topic assignments)
    $total_relationships = $this->database->query("
      SELECT COUNT(*)
      FROM {node__field_ttd_topics} t
      INNER JOIN {node_field_data} n ON t.entity_id = n.nid
      WHERE n.status = 1 AND n.type IN (:types[])
    ", [':types[]' => $enabled_content_types])->fetchField();

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
      'total_relationships' => (int) $total_relationships,
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
    // Use Drupal's proper base URL handling (works behind proxies, in subdirs, etc.)
    $base_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBasePath();

    // First, try to get logo from Drupal site logo settings.
    $site_logo = theme_get_setting('logo');
    if (!empty($site_logo['url'])) {
      $logo_url = $site_logo['url'];

      // Already a full URL (http://, https://, or protocol-relative //).
      if (preg_match('#^(https?:)?//#i', $logo_url)) {
        return $logo_url;
      }

      // Relative path - ensure it starts with /.
      if (strpos($logo_url, '/') !== 0) {
        $logo_url = '/' . $logo_url;
      }

      // Verify file exists before returning.
      $local_path = \Drupal::root() . $logo_url;
      if (file_exists($local_path)) {
        return $base_url . $logo_url;
      }
      // File doesn't exist, continue to other detection methods.
    }

    // Get active theme info.
    $theme_handler = \Drupal::service('theme_handler');
    $active_theme = $theme_handler->getDefault();

    try {
      $theme_path = $theme_handler->getTheme($active_theme)->getPath();
    }
    catch (\Exception $e) {
      // Theme not found, can't detect logo from theme.
      return NULL;
    }

    // Common logo filenames to search for (ordered by preference).
    $logo_filenames = [
      'logo.svg',
      'logo.png',
      'logo.jpg',
      'logo.jpeg',
      'logo.gif',
      'images/logo.svg',
      'images/logo.png',
      'images/logo.jpg',
      'assets/logo.svg',
      'assets/logo.png',
      'img/logo.svg',
      'img/logo.png',
    ];

    // Search for logo files in active theme.
    foreach ($logo_filenames as $filename) {
      $logo_path = '/' . $theme_path . '/' . $filename;
      if (file_exists(\Drupal::root() . $logo_path)) {
        return $base_url . $logo_path;
      }
    }

    // Also check the default theme if different from active (admin vs frontend).
    $default_theme = \Drupal::config('system.theme')->get('default');
    if ($default_theme && $default_theme !== $active_theme) {
      try {
        $default_theme_path = $theme_handler->getTheme($default_theme)->getPath();
        foreach ($logo_filenames as $filename) {
          $logo_path = '/' . $default_theme_path . '/' . $filename;
          if (file_exists(\Drupal::root() . $logo_path)) {
            return $base_url . $logo_path;
          }
        }
      }
      catch (\Exception $e) {
        // Default theme not found, skip.
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

    if (($form_state->getValue('topic_url_mode') ?: 'taxonomy_term') !== 'archive_query'
      || !$form_state->getValue('topic_archive_managed_filter')) {
      return;
    }

    if (($form_state->getValue('topic_archive_value_source') ?: 'term_id') !== 'term_id') {
      $form_state->setErrorByName('topic_archive_value_source', $this->t('Automatic archive filtering currently requires Drupal term IDs.'));
    }
    if (($form_state->getValue('topic_archive_value_template') ?: '[value]') !== '[value]') {
      $form_state->setErrorByName('topic_archive_value_template', $this->t('Automatic archive filtering currently requires the [value] pattern.'));
    }

    $parameter = trim((string) $form_state->getValue('topic_archive_query_parameter'), "?&= \t\n\r\0\x0B");
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $parameter)) {
      $form_state->setErrorByName('topic_archive_query_parameter', $this->t('Automatic archive filtering requires a simple parameter such as ttd_topic.'));
    }

    try {
      \Drupal::service('ttd_topics.search_archive_setup')->validateSelection(
        (string) $form_state->getValue('topic_archive_view'),
        (string) $form_state->getValue('topic_archive_path')
      );
    }
    catch (\RuntimeException $e) {
      $form_state->setErrorByName('topic_archive_view', $e->getMessage());
    }
  }

  /**
   * Builds the list of selectable Drupal permissions.
   *
   * @return array
   *   Permission machine names keyed to readable labels.
   */
  protected function getPermissionOptions() {
    $permissions = \Drupal::service('user.permissions')->getPermissions();
    $options = [];

    foreach ($permissions as $permission => $definition) {
      $title = isset($definition['title']) ? strip_tags((string) $definition['title']) : $permission;
      $options[$permission] = $title . ' (' . $permission . ')';
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);

    $preferred = [
      'administer topicalboost' => $this->t('Administer TopicalBoost (administer topicalboost)'),
      'administer topicalboost configuration' => $this->t('Administer TopicalBoost configuration (administer topicalboost configuration)'),
      'administer nodes' => $this->t('Administer nodes (administer nodes)'),
    ];

    return $preferred + $options;
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
      'field_ttd_generated_meta_title' => [
        'type' => 'string',
        'label' => 'TopicalBoost Generated Meta Title',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_ttd_generated_meta_desc' => [
        'type' => 'string_long',
        'label' => 'TopicalBoost Generated Meta Description',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_ttd_generated_og_title' => [
        'type' => 'string',
        'label' => 'TopicalBoost Generated OG Title',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_ttd_generated_og_desc' => [
        'type' => 'string_long',
        'label' => 'TopicalBoost Generated OG Description',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_tier_overrides' => [
        'type' => 'map',
        'label' => 'Topic Tier Overrides',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_manual_topics' => [
        'type' => 'entity_reference',
        'label' => 'Manual Topics',
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
      'field_ttd_schema_16x9' => [
        'type' => 'image',
        'label' => 'Schema Image (16:9)',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_ttd_schema_4x3' => [
        'type' => 'image',
        'label' => 'Schema Image (4:3)',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_ttd_schema_1x1' => [
        'type' => 'image',
        'label' => 'Schema Image (1:1)',
        'storage_settings' => [],
        'settings' => [],
      ],
      'field_ttd_schema_focal_point' => [
        'type' => 'string',
        'label' => 'Schema Image Focal Point',
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
    $new_api_key = trim((string) $form_state->getValue('topicalboost_api_key'));
    $new_api_key_hash = $new_api_key !== '' ? hash('sha256', $new_api_key) : '';
    $saved_validation_hash = (string) $config->get('api_key_validation_hash');
    $validation_matches_saved_key = $new_api_key_hash !== ''
      && $saved_validation_hash !== ''
      && hash_equals($saved_validation_hash, $new_api_key_hash);
    $api_key_validated = $validation_matches_saved_key ? (bool) $config->get('api_key_validated') : FALSE;
    $api_key_validation_hash = $validation_matches_saved_key ? $saved_validation_hash : '';
    $subscription_status = $validation_matches_saved_key ? ($config->get('subscription_status') ?: '') : '';
    $domain_mismatch = $validation_matches_saved_key ? ($config->get('domain_mismatch') ?: []) : [];
    $new_topic_url_mode = $form_state->getValue('topic_url_mode') ?: 'taxonomy_term';
    if (!$this->isSearchApiEnabled() || !in_array($new_topic_url_mode, ['taxonomy_term', 'archive_query'], TRUE)) {
      $new_topic_url_mode = 'taxonomy_term';
    }
    $new_archive_path = trim((string) $form_state->getValue('topic_archive_path'));
    $new_archive_query_parameter = trim((string) ($form_state->getValue('topic_archive_query_parameter') ?: 'topic'), "?&= \t\n\r\0\x0B");
    if ($new_archive_query_parameter === '') {
      $new_archive_query_parameter = 'topic';
    }
    $new_archive_value_source = $form_state->getValue('topic_archive_value_source') ?: 'term_id';
    if (!in_array($new_archive_value_source, ['term_id', 'ttd_id', 'term_uuid', 'term_slug'], TRUE)) {
      $new_archive_value_source = 'term_id';
    }
    $new_archive_value_template = trim((string) ($form_state->getValue('topic_archive_value_template') ?: '[value]'));
    if ($new_archive_value_template === '') {
      $new_archive_value_template = '[value]';
    }
    $new_archive_managed_filter = $new_topic_url_mode === 'archive_query'
      && (bool) $form_state->getValue('topic_archive_managed_filter');
    $new_archive_view = $new_archive_managed_filter
      ? (string) $form_state->getValue('topic_archive_view')
      : '';
    $new_archive_index = '';
    $new_archive_index_field = '';
    
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

    if ($new_archive_managed_filter) {
      try {
        $queue_reindex = !$config->get('topic_archive_managed_filter')
          || $config->get('topic_archive_view') !== $new_archive_view
          || $config->get('topic_archive_path') !== $new_archive_path;
        $setup = \Drupal::service('ttd_topics.search_archive_setup')->prepare(
          $new_archive_view,
          $new_archive_path,
          $queue_reindex
        );
        $new_archive_index = $setup['index_id'];
        $new_archive_index_field = $setup['field_id'];
        if ($setup['field_added'] || $setup['reindex_queued']) {
          \Drupal::messenger()->addStatus($this->t('TopicalBoost connected @view to the @index Search API index and queued reindexing. The hidden topic filter will work as cron indexes the content.', [
            '@view' => $setup['view_label'],
            '@index' => $setup['index_label'],
          ]));
        }
      }
      catch (\RuntimeException $e) {
        $new_archive_managed_filter = FALSE;
        $new_archive_view = '';
        \Drupal::messenger()->addError($this->t('TopicalBoost could not configure archive filtering: @message', [
          '@message' => $e->getMessage(),
        ]));
      }
    }

    // Handle logo file upload.
    $logo_fid = $config->get('organization_logo_fid');
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
    if ($new_path_prefix === NULL) {
      $new_path_prefix = $current_path_prefix ?: 'topics';
    }
    
    if ($new_topic_url_mode === 'taxonomy_term' && $new_path_prefix !== NULL && $new_path_prefix !== $current_path_prefix) {
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

    // Push search_countries to API if changed.
    $new_search_countries = array_filter($form_state->getValue('search_countries') ?: []);
    $old_search_countries = array_filter($config->get('search_countries') ?: []);
    if ($new_search_countries != $old_search_countries) {
      $api_key = $form_state->getValue('topicalboost_api_key') ?: $config->get('topicalboost_api_key');
      if ($api_key) {
        try {
          $client = \Drupal::httpClient();
          $client->put(TOPICALBOOST_API_ENDPOINT . '/site-settings/countries', [
            'headers' => [
              'Content-Type' => 'application/json',
              'x-api-key' => $api_key,
            ],
            'json' => ['countries' => array_values($new_search_countries)],
            'timeout' => 15,
          ]);
        }
        catch (\Exception $e) {
          \Drupal::logger('topicalboost')->error('Failed to push search countries to API: @message', ['@message' => $e->getMessage()]);
        }
      }
    }

    $config
      ->set('enabled_content_types', $new_content_types)
      ->set('enable_frontend', $form_state->getValue('enable_frontend'))
      ->set('enable_automatic_mentions', $form_state->getValue('enable_automatic_mentions'))
      ->set('enable_meta_generator', $form_state->getValue('enable_meta_generator'))
      ->set('maximum_visible_post_topics', $form_state->getValue('maximum_visible_post_topics'))
      ->set('post_topic_minimum_display_count', $form_state->getValue('post_topic_minimum_display_count'))
      ->set('curation_scores_enabled', (bool) $form_state->getValue('curation_scores_enabled'))
      ->set('analysis_custom_fields', array_filter($form_state->getValue('analysis_custom_fields') ?: []))
      ->set('topics_list_label', $form_state->getValue('topics_list_label'))
      ->set('topic_url_path_prefix', $new_path_prefix)
      ->set('topic_url_mode', $new_topic_url_mode)
      ->set('topic_archive_path', $new_archive_path)
      ->set('topic_archive_query_parameter', $new_archive_query_parameter)
      ->set('topic_archive_value_source', $new_archive_value_source)
      ->set('topic_archive_value_template', $new_archive_value_template)
      ->set('topic_archive_managed_filter', $new_archive_managed_filter)
      ->set('topic_archive_view', $new_archive_view)
      ->set('topic_archive_index', $new_archive_index)
      ->set('topic_archive_index_field', $new_archive_index_field)
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('topicalboost_api_key', $new_api_key)
      ->set('api_key_validated', $api_key_validated)
      ->set('api_key_validation_hash', $api_key_validation_hash)
      ->set('subscription_status', $subscription_status)
      ->set('domain_mismatch', $domain_mismatch)
      ->set('organization_facebook_url', $form_state->getValue('organization_facebook_url'))
      ->set('organization_twitter_url', $form_state->getValue('organization_twitter_url'))
      ->set('organization_linkedin_url', $form_state->getValue('organization_linkedin_url'))
      ->set('organization_youtube_url', $form_state->getValue('organization_youtube_url'))
      ->set('organization_wikipedia_url', $form_state->getValue('organization_wikipedia_url'))
      ->set('organization_logo_fid', $logo_fid)
      // New settings
      ->set('frontend_filter_mode', $form_state->getValue('frontend_filter_mode') ?: 'mentions_behind_toggle')
      ->set('frontend_sort_order', 'alphabetical')
      ->set('auto_link_topics', $form_state->getValue('auto_link_topics'))
      ->set('skeleton_style', $form_state->getValue('skeleton_style'))
      ->set('taxonomy_label_singular', $form_state->getValue('taxonomy_label_singular'))
      ->set('taxonomy_label_plural', $form_state->getValue('taxonomy_label_plural'))
      ->set('include_excerpt', $form_state->getValue('include_excerpt'))
      ->set('batch_size', (int) $form_state->getValue('batch_size'))
      ->set('beta_channel', $form_state->getValue('beta_channel'))
      ->set('use_beta_api', $form_state->getValue('use_beta_api'))
      ->set('error_telemetry_enabled', $form_state->getValue('error_telemetry_enabled'))
      ->set('disable_event_temporal_properties', $form_state->getValue('disable_event_temporal_properties'))
      ->set('hide_seo_module_ui', $form_state->getValue('hide_seo_module_ui'))
      ->set('block_until_analyzed', $form_state->getValue('block_until_analyzed'))
      ->set('required_permission', $form_state->getValue('required_permission') ?: 'administer topicalboost')
      ->set('new_topic_reanalysis_enabled', $form_state->getValue('new_topic_reanalysis_enabled'))
      ->set('new_topic_reanalysis_lookback_days', (int) $form_state->getValue('new_topic_reanalysis_lookback_days'))
      ->set('search_clippings_enabled', $form_state->getValue('search_clippings_enabled'))
      ->set('citations_widget_enabled', $form_state->getValue('citations_widget_enabled'))
      ->set('citations_widget_limit', (int) $form_state->getValue('citations_widget_limit'))
      ->set('meta_seo_prompt', $form_state->getValue('meta_seo_prompt'))
      ->set('meta_social_prompt', $form_state->getValue('meta_social_prompt'))
      ->set('meta_title_case_format', $form_state->getValue('meta_title_case_format') ?: 'title')
      ->set('auto_sync_enabled', $form_state->getValue('auto_sync_enabled'))
      ->set('search_countries', array_values($new_search_countries))
      ->set('author_manager_enabled', $form_state->getValue('author_manager_enabled'))
      ->set('author_hide_content_types', array_keys(array_filter($form_state->getValue('author_hide_content_types') ?: [])))
      ->set('author_force_show_content_types', array_keys(array_filter($form_state->getValue('author_force_show_content_types') ?: [])))
      ->set('custom_author_schema_enabled', $form_state->getValue('custom_author_schema_enabled'))
      ->set('author_field_name', $form_state->getValue('author_field_name') ?: 'uid')
      ->set('author_name_field', $form_state->getValue('author_name_field') ?: 'display_name')
      ->set('author_image_field', $form_state->getValue('author_image_field') ?: '')
      ->set('author_description_field', $form_state->getValue('author_description_field') ?: '')
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Builds archive-query setup guidance for topic links.
   */
  protected function buildTopicArchiveStatus($config) {
    $archive_path = trim((string) $config->get('topic_archive_path'));
    $query_parameter = trim((string) ($config->get('topic_archive_query_parameter') ?: 'topic'));
    $value_source = $config->get('topic_archive_value_source') ?: 'term_id';
    $value_template = $config->get('topic_archive_value_template') ?: '[value]';
    $managed_filter = (bool) $config->get('topic_archive_managed_filter');

    $topicalboost_items = [
      $this->t('Generates article topic links from the archive URL settings below.'),
      $this->t('Keeps normal taxonomy term links as the default for sites that do not use a Search API/archive topic setup.'),
    ];
    if ($managed_filter) {
      $topicalboost_items[] = $this->t('Adds the missing topic ID field to the selected Search API index and applies the URL filter only to that archive View.');
      $topicalboost_items[] = $this->t('Queues reindexing through Search API without creating a facet or changing the archive layout.');
      $drupal_items = [
        $this->t('Keep the selected Search API archive View enabled.'),
        $this->t('Run cron so Search API can process the queued reindex.'),
        $this->t('Do not place a visible topic facet/block on the page unless readers should see it.'),
      ];
    }
    else {
      $topicalboost_items[] = $this->t('Does not change Search API indexes, archive Views, or Facets unless automatic filtering is enabled.');
      $drupal_items = [
        $this->t('Index the TopicalBoost topic field used by article content.'),
        $this->t('Make the archive page read the configured URL parameter and filter against that indexed field.'),
        $this->t('Do not place a visible topic facet/block on the page unless readers should see it. Keep the URL filter behavior enabled so topic links still work.'),
      ];
    }

    $verify_items = [];

    if ($archive_path === '') {
      $verify_items[] = $this->t('Add the Search API/archive page path, such as /search or /news-archive.');
    }
    elseif (!preg_match('/^https?:\/\//i', $archive_path) && strpos($archive_path, '/') !== 0) {
      $verify_items[] = $this->t('Archive paths should start with / unless you are entering a full https:// URL.');
    }
    else {
      $verify_items[] = $this->t('Archive path: @path', ['@path' => $archive_path]);
    }

    if ($query_parameter === '') {
      $verify_items[] = $this->t('Add the URL parameter your archive page reads for topic filtering.');
    }
    else {
      $verify_items[] = $this->t('URL parameter Drupal must read: ?@parameter=...', [
        '@parameter' => $query_parameter,
      ]);
    }

    $verify_items[] = $this->t('Query value source: @value_source', [
      '@value_source' => $this->getTopicArchiveValueSourceLabel($value_source),
    ]);
    $verify_items[] = $this->t('Query value pattern: @pattern', [
      '@pattern' => $value_template,
    ]);
    if ($managed_filter) {
      $verify_items[] = $this->t('Automatic filtering: connected to @view using index field @field.', [
        '@view' => $config->get('topic_archive_view') ?: $this->t('not selected'),
        '@field' => $config->get('topic_archive_index_field') ?: $this->t('not configured'),
      ]);
    }

    $sample_term = $this->getTopicArchiveSampleTerm();
    if ($sample_term && function_exists('ttd_topics_get_topic_url')) {
      $sample_url = \ttd_topics_get_topic_url($sample_term);
      $verify_items[] = Markup::create($this->t('Sample generated link: @topic to ', [
        '@topic' => $sample_term->label(),
      ]) . '<a href="' . htmlspecialchars($sample_url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($sample_url, ENT_QUOTES, 'UTF-8') . '</a>');
    }
    else {
      $verify_items[] = $this->t('After at least one TopicalBoost topic exists, this page will show a sample generated archive link.');
    }

    $topicalboost_list = $this->buildTopicArchiveListMarkup($topicalboost_items);
    $drupal_list = $this->buildTopicArchiveListMarkup($drupal_items);
    $verify_list = $this->buildTopicArchiveListMarkup($verify_items);
    $admin_links = $this->buildTopicArchiveAdminLinks($config);

    $markup = '<div class="ttd-topic-archive-guide">';
    $markup .= '<div class="ttd-topic-archive-guide__intro"><strong>' . $this->t('Optional Search API/archive setup') . '</strong><span>' . $this->t('Only use this when topic links should open an existing archive/search page instead of Drupal taxonomy term pages. Automatic filtering can connect the selected Search API View without displaying a facet.') . '</span></div>';
    $markup .= '<div class="ttd-topic-archive-guide__grid">';
    $markup .= '<section><h4>' . $this->t('What TopicalBoost does') . '</h4><ul>' . $topicalboost_list . '</ul></section>';
    $markup .= '<section><h4>' . $this->t('What Drupal must already do') . '</h4><ul>' . $drupal_list . '</ul></section>';
    $markup .= '</div>';
    $markup .= '<section class="ttd-topic-archive-guide__verify"><h4>' . $this->t('Verify this setup') . '</h4><ul>' . $verify_list . '</ul>' . $admin_links . '</section>';
    $markup .= '</div>';

    return [
      '#markup' => Markup::create($markup),
    ];
  }

  /**
   * Builds a list of already-sanitized TranslatableMarkup/Markup items.
   */
  protected function buildTopicArchiveListMarkup(array $items) {
    $markup = '';
    foreach ($items as $item) {
      $markup .= '<li>' . (string) $item . '</li>';
    }

    return $markup;
  }

  /**
   * Checks whether Search API is enabled for archive topic links.
   */
  protected function isSearchApiEnabled() {
    return \Drupal::moduleHandler()->moduleExists('search_api');
  }

  /**
   * Builds helpful admin links for the Search API/archive setup.
   */
  protected function buildTopicArchiveAdminLinks($config) {
    $links = [];

    $search_api_url = $this->getFirstRouteUrlIfAvailable(['entity.search_api_index.collection', 'search_api.overview']);
    if ($search_api_url) {
      $links[] = [
        'label' => $this->t('Choose/check the Search API index'),
        'url' => $search_api_url,
        'description' => $this->t('Make sure the archive index includes the TopicalBoost topic reference field.'),
      ];
    }

    foreach (array_slice($this->getEnabledContentTypeIds($config), 0, 3) as $content_type) {
      $url = $this->getRouteUrlIfAvailable('entity.node.field_ui_fields', ['node_type' => $content_type]);
      if ($url) {
        $links[] = [
          'label' => $this->t('Check @type topic field', ['@type' => ucfirst(str_replace('_', ' ', $content_type))]),
          'url' => $url,
          'description' => $this->t('Confirm which TopicalBoost topic field is attached to this content type.'),
        ];
      }
    }

    $views_url = $this->getRouteUrlIfAvailable('entity.view.collection');
    if ($views_url) {
      $links[] = [
        'label' => $this->t('Find/edit the archive View'),
        'url' => $views_url,
        'description' => $this->t('Use the public archive/search View that should receive topic-link traffic.'),
      ];
    }

    $facets_url = $this->getRouteUrlIfAvailable('entity.facets_facet.collection');
    if ($facets_url) {
      $links[] = [
        'label' => $this->t('Check Facets URL setup'),
        'url' => $facets_url,
        'description' => $this->t('Use this only if the archive uses Facets-style values such as f[0]=field_ttd_topics:123. The facet block does not need to be displayed.'),
      ];
    }

    if (empty($links)) {
      return '';
    }

    $items = '';
    foreach ($links as $link) {
      $items .= '<li><a href="' . htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') . '">' . (string) $link['label'] . '</a><span>' . (string) $link['description'] . '</span></li>';
    }

    return '<div class="ttd-topic-archive-admin-links"><h4>' . $this->t('Useful admin links') . '</h4><ol>' . $items . '</ol></div>';
  }

  /**
   * Returns the first available route URL from a list of route names.
   */
  protected function getFirstRouteUrlIfAvailable(array $route_names, array $parameters = []) {
    foreach ($route_names as $route_name) {
      $url = $this->getRouteUrlIfAvailable($route_name, $parameters);
      if ($url) {
        return $url;
      }
    }

    return NULL;
  }

  /**
   * Normalizes enabled content type config stored as either keys or values.
   */
  protected function getEnabledContentTypeIds($config) {
    $raw_content_types = $config->get('enabled_content_types') ?: [];
    $content_types = [];

    foreach ($raw_content_types as $key => $value) {
      $content_type = is_string($value) && $value !== '' ? $value : (is_string($key) ? $key : '');
      if ($content_type !== '' && $content_type !== '0') {
        $content_types[$content_type] = $content_type;
      }
    }

    return array_values($content_types);
  }

  /**
   * Returns a route URL if the target route exists on this site.
   */
  protected function getRouteUrlIfAvailable($route_name, array $parameters = []) {
    try {
      \Drupal::service('router.route_provider')->getRouteByName($route_name);
      return Url::fromRoute($route_name, $parameters)->toString();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Loads one local TopicalBoost topic for archive-link examples.
   */
  protected function getTopicArchiveSampleTerm() {
    try {
      if ($this->database->schema()->tableExists('node__field_ttd_topics')) {
        $query = $this->database->select('taxonomy_term_field_data', 'td');
        $query->innerJoin('node__field_ttd_topics', 'nt', 'nt.field_ttd_topics_target_id = td.tid');
        $tid = $query
          ->distinct()
          ->fields('td', ['tid'])
          ->condition('td.vid', 'ttd_topics')
          ->condition('td.status', 1)
          ->condition('td.name', '', '<>')
          ->condition('td.name', '&%', 'NOT LIKE')
          ->orderBy('td.name', 'ASC')
          ->range(0, 1)
          ->execute()
          ->fetchField();

        if ($tid) {
          return \Drupal\taxonomy\Entity\Term::load($tid);
        }
      }

      $tid = $this->database->select('taxonomy_term_field_data', 'td')
        ->fields('td', ['tid'])
        ->condition('td.vid', 'ttd_topics')
        ->condition('td.status', 1)
        ->condition('td.name', '', '<>')
        ->condition('td.name', '&%', 'NOT LIKE')
        ->orderBy('td.name', 'ASC')
        ->range(0, 1)
        ->execute()
        ->fetchField();

      return $tid ? \Drupal\taxonomy\Entity\Term::load($tid) : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Gets a human-readable label for the archive query value source.
   */
  protected function getTopicArchiveValueSourceLabel($source) {
    $labels = [
      'term_id' => $this->t('Drupal term ID'),
      'ttd_id' => $this->t('TopicalBoost topic ID'),
      'term_uuid' => $this->t('Drupal term UUID'),
      'term_slug' => $this->t('Term label slug'),
    ];

    return $labels[$source] ?? $labels['term_id'];
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

  /**
   * Build the API coverage comparison section.
   *
   * @param array $analytics
   *   Analytics data from getAnalyticsData().
   *
   * @return array
   *   Render array for the coverage comparison section.
   */
  protected function buildCoverageComparison(array $analytics) {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-coverage-section']],
    ];

    $build['title'] = [
      '#markup' => '<div class="ttd-topics-section-title">
        <span class="ttd-icon ttd-icon-large ttd-icon-coverage"></span>Topic Coverage Analytics
      </div>',
    ];

    // API error message container
    $build['api_error'] = [
      '#markup' => '<div id="ttd-api-error" class="ttd-api-error-message" style="display: none;"></div>',
    ];


    // Stats overview boxes
    $build['stats'] = [
      '#markup' => '<div class="ttd-stats-overview">
        <div class="ttd-stats-box">
          <span class="ttd-stats-value">' . number_format($analytics['total_posts']) . '</span>
          <span class="ttd-stats-label">Total Posts</span>
        </div>
        <div class="ttd-stats-box">
          <span class="ttd-stats-value">' . number_format($analytics['total_topics']) . '</span>
          <span class="ttd-stats-label">Unique Topics</span>
        </div>
        <div class="ttd-stats-box">
          <span class="ttd-stats-value">' . number_format($analytics['avg_topics_per_post'], 1) . '</span>
          <span class="ttd-stats-label">Avg Topics/Post</span>
        </div>
        <div class="ttd-stats-box">
          <span class="ttd-stats-value">' . number_format($analytics['coverage_percentage'], 1) . '%</span>
          <span class="ttd-stats-label">Coverage Rate</span>
        </div>
      </div>',
    ];

    // Content Type Coverage Breakdown
    $table_rows = '';
    foreach ($analytics['by_content_type'] as $type => $data) {
      $bar_class = $data['coverage_percentage'] >= 70 ? 'ttd-coverage-high' :
                  ($data['coverage_percentage'] >= 40 ? 'ttd-coverage-medium' : 'ttd-coverage-low');

      $table_rows .= '<tr class="ttd-type-row">
        <td class="ttd-type-name">' . ucfirst($type) . '</td>
        <td class="ttd-type-total">' . number_format($data['total_posts']) . '</td>
        <td class="ttd-type-with-topics">' . number_format($data['posts_with_topics']) . '</td>
        <td class="ttd-type-coverage">
          <div class="ttd-coverage-bar-container">
            <div class="ttd-coverage-bar ' . $bar_class . '" style="width: ' . $data['coverage_percentage'] . '%"></div>
          </div>
          <span class="ttd-coverage-text">' . number_format($data['coverage_percentage'], 1) . '%</span>
        </td>
      </tr>';
    }

    $build['type_breakdown'] = [
      '#markup' => Markup::create('<div class="ttd-type-coverage-wrapper">
        <h3><span class="ttd-icon ttd-icon-large ttd-icon-analytics"></span>Coverage by Content Type</h3>
        <table class="ttd-type-coverage-table">
          <thead>
            <tr>
              <th>Content Type</th>
              <th>Total Posts</th>
              <th>With Topics</th>
              <th>Coverage</th>
            </tr>
          </thead>
          <tbody>' . $table_rows . '</tbody>
        </table>
      </div>'),
    ];

    // Comparison table
    $build['comparison_table'] = [
      '#markup' => Markup::create('<div class="ttd-comparison-table-wrapper">
        <h3><span class="ttd-icon ttd-icon-large ttd-icon-api"></span>API Coverage Comparison</h3>
        <table class="ttd-comparison-table">
          <thead>
            <tr>
              <th>Metric</th>
              <th>Local vs API</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr data-metric="posts">
              <td class="ttd-metric-name">Posts</td>
              <td class="ttd-comparison-values">
                <span class="ttd-local-val" data-value="' . $analytics['total_posts'] . '">' . number_format($analytics['total_posts']) . '</span>
                <span class="ttd-sep"> / </span>
                <span class="ttd-api-val" id="api-posts-val">-</span>
              </td>
              <td class="ttd-status-cell" id="status-posts">-</td>
            </tr>
            <tr data-metric="topics">
              <td class="ttd-metric-name">Topics</td>
              <td class="ttd-comparison-values">
                <span class="ttd-local-val" data-value="' . $analytics['total_topics'] . '">' . number_format($analytics['total_topics']) . '</span>
                <span class="ttd-sep"> / </span>
                <span class="ttd-api-val" id="api-topics-val">-</span>
              </td>
              <td class="ttd-status-cell" id="status-topics">-</td>
            </tr>
            <tr data-metric="relationships">
              <td class="ttd-metric-name">Relationships</td>
              <td class="ttd-comparison-values">
                <span class="ttd-local-val" data-value="' . $analytics['total_relationships'] . '">' . number_format($analytics['total_relationships']) . '</span>
                <span class="ttd-sep"> / </span>
                <span class="ttd-api-val" id="api-relationships-val">-</span>
              </td>
              <td class="ttd-status-cell" id="status-relationships">-</td>
            </tr>
            <tr data-metric="avg_relationships">
              <td class="ttd-metric-name">Avg Topics/Post</td>
              <td class="ttd-comparison-values">
                <span class="ttd-local-val" data-value="' . $analytics['avg_topics_per_post'] . '">' . number_format($analytics['avg_topics_per_post'], 2) . '</span>
                <span class="ttd-sep"> / </span>
                <span class="ttd-api-val" id="api-avg-val">-</span>
              </td>
              <td class="ttd-status-cell" id="status-avg">-</td>
            </tr>
          </tbody>
        </table>
      </div>'),
    ];


    return $build;
  }

}
