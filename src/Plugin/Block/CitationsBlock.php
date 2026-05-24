<?php

namespace Drupal\ttd_topics\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;

/**
 * Provides a Citations dashboard widget block.
 *
 * @Block(
 *   id = "topicalboost_citations",
 *   admin_label = @Translation("TopicalBoost: Citations"),
 *   category = @Translation("TopicalBoost"),
 * )
 */
class CitationsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('ttd_topics.settings');

    if (!$config->get('citations_widget_enabled')) {
      return [];
    }

    $api_key = $config->get('topicalboost_api_key');
    if (empty($api_key)) {
      return [
        '#markup' => '<div class="topicalboost-widget-error">TopicalBoost API key is required for the Citations widget.</div>',
      ];
    }

    $limit = $config->get('citations_widget_limit') ?: 20;
    $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    return [
      '#markup' => '<div id="citations-widget" data-api-key="' . htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8') . '" data-api-endpoint="' . htmlspecialchars($api_endpoint, ENT_QUOTES, 'UTF-8') . '" data-limit="' . (int) $limit . '"></div>',
      '#attached' => [
        'library' => ['ttd_topics/citations_widget'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags(parent::getCacheTags(), ['config:ttd_topics.settings']);
  }

}
