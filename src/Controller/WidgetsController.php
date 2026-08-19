<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin page that renders TopicalBoost widgets inline.
 */
class WidgetsController extends ControllerBase {

  /**
   * Checks access using the configured TopicalBoost management permission.
   */
  public function access(AccountInterface $account) {
    $config = $this->config('ttd_topics.settings');
    $permission = $config->get('required_permission') ?: 'administer topicalboost';

    return AccessResult::allowedIfHasPermission($account, $permission)
      ->addCacheableDependency($config);
  }

  /**
   * Renders the widgets page.
   */
  public function page() {
    $config = $this->config('ttd_topics.settings');
    $api_key_configured = (bool) $config->get('topicalboost_api_key');
    $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';
    // Widget URL must be browser-accessible (HTTPS). Falls back to API endpoint.
    $widget_url = defined('TOPICALBOOST_WIDGET_URL') ? TOPICALBOOST_WIDGET_URL : $api_endpoint;
    $citations_limit = $config->get('citations_widget_limit') ?: 20;
    $debug = defined('TOPICALBOOST_DEBUG') && TOPICALBOOST_DEBUG ? 'true' : 'false';

    if (!$api_key_configured) {
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
              data-topicalboost-proxy-base="{{ proxy_base }}"
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
              data-topicalboost-proxy-base="{{ proxy_base }}"
              data-limit="{{ citations_limit }}"
              data-source="drupal_widgets_page"
              data-debug="{{ debug }}"></script>
            <div id="topicalboost-citations"></div>
          </div>
        </div>',
      '#context' => [
        'proxy_base' => '/api/topicalboost/widgets',
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

  /**
   * Proxies widget data without exposing the configured site API key.
   */
  public function proxy(Request $request, $resource) {
    $config = $this->config('ttd_topics.settings');
    $api_key = (string) $config->get('topicalboost_api_key');
    if ($api_key === '') {
      return new JsonResponse(['success' => FALSE, 'error' => 'API key is not configured'], 503);
    }

    $paths = [
      'appearances' => '/api/embed/topicalboost/appearances',
      'citations' => '/api/embed/topicalboost/citations',
      'citers' => '/api/embed/topicalboost/citations/citers',
    ];
    if (!isset($paths[$resource])) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Unknown widget resource'], 404);
    }

    $allowed_query = [
      'appearances' => ['limit', 'offset', 'sortBy', 'keyword', 'url', 'competitor'],
      'citations' => ['limit', 'offset', 'sortBy', 'minDomainRating', 'minCitationCount', 'targetUrl', 'sourceDomain', 'dateFrom', 'dateTo'],
      'citers' => [],
    ];
    $query = array_intersect_key($request->query->all(), array_flip($allowed_query[$resource]));
    $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    try {
      $response = \Drupal::httpClient()->request('GET', rtrim($api_endpoint, '/') . $paths[$resource], [
        'headers' => [
          'Accept' => 'application/json',
          'Origin' => $request->getSchemeAndHttpHost(),
          'Referer' => $request->getUri(),
          'x-topicalboost-api-key' => $api_key,
        ],
        'query' => $query,
        'http_errors' => FALSE,
        'timeout' => 15,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      if (!is_array($data)) {
        return new JsonResponse(['success' => FALSE, 'error' => 'Invalid widget API response'], 502);
      }
      $result = new JsonResponse($data, $response->getStatusCode());
      $result->headers->set('Cache-Control', 'private, no-store');
      return $result;
    }
    catch (\Throwable $error) {
      $this->getLogger('ttd_topics')->error('Widget proxy request failed: @message', ['@message' => $error->getMessage()]);
      return new JsonResponse(['success' => FALSE, 'error' => 'Widget data is temporarily unavailable'], 502);
    }
  }

}
