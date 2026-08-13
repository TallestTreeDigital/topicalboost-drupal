<?php

namespace Drupal\ttd_topics\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Schedules sitemap regeneration after TopicalBoost settings change.
 */
class TopicSitemapRefreshSubscriber implements EventSubscriberInterface {

  /**
   * Schedules a refresh after the module settings are saved.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'ttd_topics.settings'
      && function_exists('ttd_topics_schedule_sitemap_refresh')) {
      \ttd_topics_schedule_sitemap_refresh();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

}
