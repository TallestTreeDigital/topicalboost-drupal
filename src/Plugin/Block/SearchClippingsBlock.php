<?php

namespace Drupal\ttd_topics\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides a Top Stories dashboard widget block.
 *
 * @Block(
 *   id = "topicalboost_search_clippings",
 *   admin_label = @Translation("TopicalBoost: Top Stories"),
 *   category = @Translation("TopicalBoost"),
 * )
 */
class SearchClippingsBlock extends BlockBase {

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

    if (!$config->get('search_clippings_enabled')) {
      return [];
    }

    if (!$config->get('topicalboost_api_key')) {
      return [
        '#markup' => '<div class="topicalboost-widget-error">TopicalBoost API key is required for the Top Stories widget.</div>',
      ];
    }

    $api_endpoint = defined('TOPICALBOOST_WIDGET_URL') ? TOPICALBOOST_WIDGET_URL : (defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com');

    return [
      '#type' => 'inline_template',
      '#template' => '<script src="{{ widget_src }}" data-topicalboost-proxy-base="/api/topicalboost/widgets" data-limit="5" data-source="drupal_block"></script><div id="searchclippings-widget"></div>',
      '#context' => [
        'widget_src' => $api_endpoint . '/api/embed/widget.js',
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
