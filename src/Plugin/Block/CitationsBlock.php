<?php

namespace Drupal\ttd_topics\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;

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
  protected function blockAccess(AccountInterface $account) {
    $config = \Drupal::config('ttd_topics.settings');
    $permission = $config->get('required_permission') ?: 'administer topicalboost';

    return AccessResult::allowedIfHasPermission($account, $permission)
      ->addCacheableDependency($config);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('ttd_topics.settings');

    if (!$config->get('citations_widget_enabled')) {
      return [];
    }

    if (!$config->get('topicalboost_api_key')) {
      return [
        '#markup' => '<div class="topicalboost-widget-error">TopicalBoost API key is required for the Citations widget.</div>',
      ];
    }

    $limit = $config->get('citations_widget_limit') ?: 20;
    $api_endpoint = defined('TOPICALBOOST_WIDGET_URL') ? TOPICALBOOST_WIDGET_URL : (defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com');

    return [
      '#type' => 'inline_template',
      '#template' => '<script src="{{ widget_src }}" data-topicalboost-proxy-base="/api/topicalboost/widgets" data-limit="{{ limit }}" data-source="drupal_block"></script><div id="topicalboost-citations"></div>',
      '#context' => [
        'widget_src' => $api_endpoint . '/api/embed/citations-widget.js',
        'limit' => (int) $limit,
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
