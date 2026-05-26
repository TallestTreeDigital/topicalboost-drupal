<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Admin page that renders TopicalBoost widgets inline.
 */
class WidgetsController extends ControllerBase {

  /**
   * Renders the widgets page.
   */
  public function page() {
    $config = $this->config('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key') ?: '';
    $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
    // Widget URL must be browser-accessible (HTTPS). Falls back to API endpoint.
    $widget_url = defined('TOPICALBOOST_WIDGET_URL') ? TOPICALBOOST_WIDGET_URL : $api_endpoint;
    $citations_limit = $config->get('citations_widget_limit') ?: 20;
    $debug = defined('TOPICALBOOST_DEBUG') && TOPICALBOOST_DEBUG ? 'true' : 'false';

    if (empty($api_key)) {
      return [
        '#type' => 'markup',
        '#markup' => '<div class="ttd-widgets-page"><div class="ttd-widgets-notice">API key is not configured. <a href="/admin/config/content/topicalboost">Set it in TopicalBoost settings</a>.</div></div>',
        '#attached' => [
          'library' => ['ttd_topics/widgets_page'],
        ],
      ];
    }

    // Use inline_template so script tags are not stripped by Drupal's XSS filter.
    // Script before container div, matching WP embed pattern.
    return [
      '#type' => 'inline_template',
      '#template' => '
        <div class="ttd-widgets-page">
          <div class="ttd-widget-col">
            <h2>Top Stories</h2>
            <script src="{{ clippings_src }}"
              data-topicalboost-api-key="{{ api_key }}"
              data-limit="5"
              data-source="drupal_widgets_page"
              data-use-cloudflare-images="true"
              data-thumbnail-size="120x80"
              data-debug="{{ debug }}"></script>
            <div id="searchclippings-widget"></div>
          </div>
          <div class="ttd-widget-col">
            <h2>Citations</h2>
            <script src="{{ citations_src }}"
              data-topicalboost-api-key="{{ api_key }}"
              data-limit="{{ citations_limit }}"
              data-source="drupal_widgets_page"
              data-debug="{{ debug }}"></script>
            <div id="topicalboost-citations"></div>
          </div>
        </div>',
      '#context' => [
        'api_key' => $api_key,
        'citations_src' => $widget_url . '/api/embed/citations-widget.js',
        'clippings_src' => $widget_url . '/api/embed/widget.js',
        'citations_limit' => (int) $citations_limit,
        'debug' => $debug,
      ],
      '#attached' => [
        'library' => ['ttd_topics/widgets_page'],
      ],
    ];
  }

}
