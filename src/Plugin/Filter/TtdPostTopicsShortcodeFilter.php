<?php

namespace Drupal\ttd_topics\Plugin\Filter;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\node\NodeInterface;

/**
 * Replaces the TopicalBoost post topics shortcode.
 *
 * @Filter(
 *   id = "topicalboost_post_topics_shortcode",
 *   title = @Translation("TopicalBoost post topics shortcode"),
 *   description = @Translation("Replaces [ttd_shortcode_post_topics] with the TopicalBoost topics display on node pages."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE
 * )
 */
class TtdPostTopicsShortcodeFilter extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    if (strpos($text, '[ttd_shortcode_post_topics]') === FALSE) {
      return new FilterProcessResult($text);
    }

    $node = \Drupal::routeMatch()->getParameter('node');
    $replacement = '';
    $build = [];

    if ($node instanceof NodeInterface) {
      $build = ttd_topics_build_frontend_topics_display($node);
      if (!empty($build)) {
        $replacement = (string) \Drupal::service('renderer')->renderPlain($build);
      }
    }

    $result = new FilterProcessResult(str_replace('[ttd_shortcode_post_topics]', $replacement, $text));
    $result->addCacheContexts(['route', 'user.permissions']);

    if (!empty($build)) {
      if (!empty($build['#attached'])) {
        $result->setAttachments($build['#attached']);
      }
      $result->addCacheableDependency(BubbleableMetadata::createFromRenderArray($build));
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Use [ttd_shortcode_post_topics] on node pages to render the TopicalBoost topics display.');
  }

}
