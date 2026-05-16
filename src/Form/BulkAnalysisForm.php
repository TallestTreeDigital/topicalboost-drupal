<?php

namespace Drupal\ttd_topics\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for bulk analysis functionality.
 */
class BulkAnalysisForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a BulkAnalysisForm object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'topicalboost_bulk_analysis';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Disable form caching to prevent corruption.
    $form_state->setCached(FALSE);
    $form_state->disableCache();
    $form['#cache']['max-age'] = 0;
    $form['#cache'] = ['max-age' => 0];

    $config = $this->configFactory->get('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);

    // Get all content types.
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $content_type) {
      $content_type_options[$content_type->id()] = $content_type->label();
    }

    // Check if no content types are enabled - show empty state.
    if (empty($enabled_content_types)) {
      return $this->buildEmptyStateForm();
    }

    $form['#attributes']['id'] = 'ttd-bulk-analysis-form';

    $form['tabs'] = [
      '#type' => 'markup',
      '#weight' => -100,
      '#markup' => Markup::create('<div class="ttd-ba-tabs">
        <button type="button" class="ttd-ba-tab active" data-tab="analysis">Analysis</button>
        <button type="button" class="ttd-ba-tab" data-tab="sync">
          Sync
          <span id="ttd-sync-tab-dot" class="ttd-sync-tab-dot" style="display: none;"></span>
        </button>
      </div>
      <div class="ttd-ba-tab-content active" id="ttd-tab-analysis">'),
    ];

    // Unified Date Range Section.
    $form['date_range'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-bulk-analysis-section']],
      '#weight' => -90,
    ];

    $form['date_range']['header'] = [
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

    $form['date_range']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-section-content']],
    ];

    $form['date_range']['content']['unified_component'] = [
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

    // Analysis Options.
    $form['options'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-bulk-analysis-section']],
    ];

    $form['options']['header'] = [
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

    $form['options']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-section-content']],
    ];

    $form['options']['content']['reanalyze'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Reanalyze'),
      '#description' => $this->t('Include already analyzed content if enabled'),
      '#attributes' => ['id' => 'ttd-bulk-analysis-reanalyze'],
    ];

    $form['options']['content']['include_drafts'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include Drafts'),
      '#description' => $this->t('Include draft content in analysis'),
      '#attributes' => ['id' => 'ttd-bulk-analysis-include-drafts'],
    ];

    $form['options']['content']['only_topicless'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only Content Without Topics'),
      '#description' => $this->t('Only analyze content without any topics'),
      '#attributes' => ['id' => 'ttd-bulk-analysis-only-topicless'],
    ];

    // Custom field filter.
    $custom_field_options = $this->getAnalysisCustomFieldOptions($config);
    $form['custom_field_filter'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-bulk-analysis-section', 'ttd-custom-field-filter-section']],
    ];

    $form['custom_field_filter']['header'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ttd-section-header">
        <h3 class="ttd-section-title">' . $this->t('Filter by Custom Field') . '</h3>
      </div>',
    ];

    $form['custom_field_filter']['content'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($this->buildCustomFieldFilterMarkup($custom_field_options)),
    ];

    // Content Type Selection.
    $form['content_types'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-bulk-analysis-section']],
    ];

    $form['content_types']['header'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ttd-section-header">
        <h3 class="ttd-section-title">
          <svg class="ttd-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
            <path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" />
            <path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v6a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 2a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd" />
          </svg>
          ' . $this->t('Post Types') . '
        </h3>
      </div>',
    ];

    $form['content_types']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-section-content']],
    ];

    $form['content_types']['content']['help'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ttd-filter-group">
        <div class="ttd-filter-header">
          <span class="ttd-section-label">Default from Settings • Click to Add/Remove for This Analysis</span>
          <a href="' . Url::fromRoute('topicalboost.settings_form')->toString() . '#settings" class="ttd-edit-link ttd-edit-link--pencil" title="Edit Default in Settings">
          </a>
        </div>
      </div>',
    ];

    $form['content_types']['content']['types_grid'] = [
      '#type' => 'markup',
      '#markup' => $this->buildContentTypesGrid($content_type_options, $enabled_content_types),
    ];

    // Category filter placeholder. Drupal does not have a configured category
    // filter yet, but the row is present to match the WordPress workflow.
    $form['categories'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-bulk-analysis-section', 'ttd-categories-section']],
    ];

    $form['categories']['header'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ttd-section-header">
        <h3 class="ttd-section-title">' . $this->t('Categories') . '</h3>
      </div>',
    ];

    $form['categories']['content'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($this->buildCategoriesMarkup()),
    ];

    // Selection Status.
    $form['selection_status'] = [
      '#type' => 'markup',
      '#markup' => '<div id="ttd-selection-status" class="ttd-selection-status">
        <div class="ttd-selection-info">
          <span class="ttd-selection-count">Calculating...</span>
          <span class="ttd-selection-text">content items selected for analysis</span>
        </div>
      </div>',
    ];

    // Progress Bars.
    $form['progress'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ttd-bulk-analysis-progress',
        'class' => ['ttd-progress-container'],
        'style' => 'display: none;',
      ],
    ];

    $form['progress']['section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-progress-section']],
    ];

    $form['progress']['section']['title'] = [
      '#type' => 'markup',
      '#markup' => '<h4>Analysis Progress</h4>',
    ];

    $form['progress']['section']['bar'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ttd-progress-bar">
        <div id="ttd-bulk-analysis-progress-bar" class="ttd-progress-fill"></div>
      </div>',
    ];

    $form['progress']['section']['text'] = [
      '#type' => 'markup',
      '#markup' => '<div class="ttd-progress-text">
        <span id="ttd-progress-completed">0</span> of <span id="ttd-progress-total">0</span> items processed
      </div>',
    ];

    // Message Area.
    $form['message'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ttd-bulk-analysis-message',
        'class' => ['ttd-message-container'],
        'style' => 'display: none;',
      ],
    ];

    // Action Buttons - Use proper Drupal Form API buttons.
    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ttd-bulk-analysis-actions']],
      '#weight' => 90,
    ];

    $form['actions']['analyze'] = [
      '#type' => 'button',
      '#value' => $this->t('Analyze Posts'),
      '#attributes' => [
        'id' => 'ttd-bulk-analysis-analyze-button',
        'class' => ['button', 'button--primary'],
      ],
      '#button_type' => 'button',
    ];

    $form['actions']['reset'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => [
        'id' => 'ttd-bulk-analysis-reset-button',
        'class' => ['button', 'button--danger'],
        'style' => 'display: none;',
      ],
      '#button_type' => 'button',
    ];

    $form['analysis_close'] = [
      '#type' => 'markup',
      '#weight' => 95,
      '#markup' => Markup::create('</div>'),
    ];

    $form['sync_tab'] = [
      '#type' => 'markup',
      '#weight' => 100,
      '#markup' => Markup::create($this->buildSyncTabMarkup()),
    ];

    // Attach bulk analysis library and settings.
    $form['#attached']['library'][] = 'ttd_topics/bulk_analysis';
    $form['#attached']['library'][] = 'ttd_topics/sync';

    // Add drupalSettings for the bulk analysis (similar to WordPress wp_localize_script)
    $form['#attached']['drupalSettings']['ttd_topics'] = [
      'bulk_analysis_endpoints' => [
        'count' => Url::fromRoute('topicalboost.bulk_analysis.count')->toString(),
        'initiate' => Url::fromRoute('topicalboost.bulk_analysis.initiate')->toString(),
        'progress' => Url::fromRoute('topicalboost.bulk_analysis.progress')->toString(),
        'reset' => Url::fromRoute('topicalboost.bulk_analysis.reset')->toString(),
        'poll' => Url::fromRoute('topicalboost.bulk_analysis.poll')->toString(),
        'apply_results' => Url::fromRoute('topicalboost.bulk_analysis.apply_results')->toString(),
      ],
      'nonce' => \Drupal::csrfToken()->get('ttd_bulk_analysis'),
      'enabled_content_types' => $enabled_content_types,
      'debug_mode' => \Drupal::config('ttd_topics.settings')->get('debug_mode') ?: FALSE,
    ];

    return $form;
  }

  /**
   * Builds the WP-style data sync tab for the Bulk Analysis page.
   */
  private function buildSyncTabMarkup(): string {
    return '<div class="ttd-ba-tab-content" id="ttd-tab-sync" style="display: none;">
      <div id="ttd-sync-container">
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
      </div>
    </div>';
  }

  /**
   * Build empty state form when no content types are enabled.
   */
  private function buildEmptyStateForm() {
    $form = [];
    
    $form['empty_state'] = [
      '#markup' => '<div class="ttd-topics-empty-state">
        <div class="empty-state-icon">
          <span class="ttd-icon ttd-icon-large ttd-icon-settings"></span>
        </div>
        <h3>No Content Types Enabled</h3>
        <p>To perform bulk analysis, you need to enable TopicalBoost for one or more content types that have published posts.</p>
        <div class="empty-state-action">
          <a href="' . Url::fromRoute('topicalboost.settings_form', [], ['fragment' => 'settings'])->toString() . '" class="ttd-topics-button-primary">
            <span class="ttd-icon ttd-icon-settings"></span>
            Configure Content Types
          </a>
        </div>
      </div>',
    ];

            // Attach CSS library for empty state styling
        $form['#attached']['library'][] = 'ttd_topics/ttd_topics.styles';

    return $form;
  }

  /**
   * Build the content types grid markup.
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
   * Builds custom field filter options from the configured analysis fields.
   */
  private function getAnalysisCustomFieldOptions($config): array {
    $configured_fields = array_filter($config->get('analysis_custom_fields') ?: []);
    if (empty($configured_fields)) {
      return [];
    }

    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);
    $field_manager = \Drupal::service('entity_field.manager');
    $options = [];

    foreach ($enabled_content_types as $bundle) {
      $definitions = $field_manager->getFieldDefinitions('node', $bundle);
      foreach ($configured_fields as $field_name) {
        if (isset($definitions[$field_name])) {
          $label = (string) $definitions[$field_name]->getLabel();
          $options[$field_name] = sprintf('%s - %s (%s)', $label, $field_name, $bundle);
        }
      }
    }

    foreach ($configured_fields as $field_name) {
      $options[$field_name] ??= $field_name;
    }

    asort($options);
    return $options;
  }

  /**
   * Builds the custom field filter row markup.
   */
  private function buildCustomFieldFilterMarkup(array $field_options): string {
    $disabled = empty($field_options) ? ' disabled' : '';
    $checked_disabled_class = empty($field_options) ? ' is-disabled' : '';

    $markup = '<div class="components-toggle-control' . $checked_disabled_class . '">
      <span class="components-form-toggle">
        <input class="components-form-toggle__input" id="ttd-bulk-analysis-custom-field-filter-toggle" type="checkbox" name="custom_field_filter"' . $disabled . '>
        <span class="components-form-toggle__track"></span>
        <span class="components-form-toggle__thumb"></span>
      </span>
      <label for="ttd-bulk-analysis-custom-field-filter-toggle" class="components-toggle-control__label">
        ' . $this->t('Only analyze content with selected custom field populated') . '
      </label>
    </div>';

    if (empty($field_options)) {
      return $markup . '<div id="ttd-custom-field-filter-container" class="ttd-custom-field-filter-container" style="display:none;">
        <div class="ttd-empty-message">
          <div class="ttd-empty-title">' . $this->t('No custom fields are available for filtering') . '</div>
          <div class="ttd-empty-action"><a href="' . Url::fromRoute('topicalboost.settings_form')->toString() . '#tab-content">' . $this->t('Enable custom fields in settings') . '</a></div>
        </div>
      </div>';
    }

    $markup .= '<div id="ttd-custom-field-filter-container" class="ttd-custom-field-filter-container" style="display:none;">
      <select id="ttd-bulk-analysis-custom-field-select" class="ttd-custom-field-select">';
    foreach ($field_options as $field_name => $label) {
      $markup .= '<option value="' . htmlspecialchars($field_name, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $markup .= '</select>
      <p class="description">' . $this->t('Only content with a non-empty value for this field will be analyzed.') . '</p>
    </div>';

    return $markup;
  }

  /**
   * Builds the categories row markup.
   */
  private function buildCategoriesMarkup(): string {
    return '<div class="ttd-filter-group">
      <div class="ttd-filter-header">
        <span class="ttd-section-label">' . $this->t('Default from Settings • Click × to Remove for This Analysis') . '</span>
        <a href="' . Url::fromRoute('topicalboost.settings_form')->toString() . '#tab-content" class="ttd-edit-link ttd-edit-link--pencil" title="' . $this->t('Edit Default in Settings') . '"></a>
      </div>
      <div class="ttd-categories-selection">
        <div class="ttd-empty-message">
          <div class="ttd-empty-title">' . $this->t('No categories enabled') . '</div>
          <div class="ttd-empty-action">' . $this->t('Enable categories in settings or leave empty to use all categories') . '</div>
        </div>
      </div>
    </div>';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This form is handled via AJAX/JavaScript, so no server-side submission needed
    // Do nothing to prevent any form submission behavior.
  }

}
