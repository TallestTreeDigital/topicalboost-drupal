<?php

namespace Drupal\ttd_topics\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Applies the managed TopicalBoost topic filter to one Search API View.
 */
class SearchArchiveQuerySubscriber implements EventSubscriberInterface {

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructs the subscriber.
   */
  public function __construct(ConfigFactoryInterface $config_factory, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Use the event name directly so Search API remains an optional dependency.
    return ['search_api.query_pre_execute' => 'onQueryPreExecute'];
  }

  /**
   * Adds the configured topic ID condition to the selected archive query.
   */
  public function onQueryPreExecute($event) {
    if (!method_exists($event, 'getQuery')) {
      return;
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    if (($config->get('topic_url_mode') ?: 'taxonomy_term') !== 'archive_query'
      || !$config->get('topic_archive_managed_filter')
      || ($config->get('topic_archive_value_source') ?: 'term_id') !== 'term_id'
      || ($config->get('topic_archive_value_template') ?: '[value]') !== '[value]') {
      return;
    }

    $selection = (string) $config->get('topic_archive_view');
    $index_id = (string) $config->get('topic_archive_index');
    $field_id = (string) $config->get('topic_archive_index_field');
    $parameter = trim((string) $config->get('topic_archive_query_parameter'));
    if ($selection === '' || $index_id === '' || $field_id === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $parameter)) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return;
    }
    $value = $request->query->get($parameter);
    if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value <= 0) {
      return;
    }

    $query = $event->getQuery();
    if ($query->hasTag('ttd_topics_archive_filter') || $query->getIndex()->id() !== $index_id) {
      return;
    }

    $view = $query->getOption('search_api_view');
    if (!$view || !isset($view->storage)) {
      return;
    }
    $current_selection = $view->storage->id() . ':' . $view->current_display;
    if ($current_selection !== $selection || !$query->getIndex()->getField($field_id)) {
      return;
    }

    $query->addCondition($field_id, (int) $value, '=');
    $query->addTag('ttd_topics_archive_filter');
    if (method_exists($query, 'addCacheContexts')) {
      $query->addCacheContexts(['url.query_args:' . $parameter]);
    }
  }

}
