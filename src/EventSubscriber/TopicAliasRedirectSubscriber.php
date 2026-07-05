<?php

namespace Drupal\ttd_topics\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Redirects local alias topic pages to their canonical topic term.
 */
class TopicAliasRedirectSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new TopicAliasRedirectSubscriber.
   */
  public function __construct(
    protected Connection $database,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Redirect alias topic archives to the canonical topic archive.
   */
  public function onKernelRequest(RequestEvent $event): void {
    $is_main = method_exists($event, 'isMainRequest') ? $event->isMainRequest() : $event->isMasterRequest();
    if (!$is_main || !$this->database->schema()->tableExists('ttd_entity_aliases')) {
      return;
    }

    $term = \Drupal::routeMatch()->getParameter('taxonomy_term');
    if (!$term instanceof TermInterface || $term->bundle() !== 'ttd_topics' || !$term->hasField('field_ttd_id')) {
      return;
    }

    $alias_id = $term->get('field_ttd_id')->isEmpty() ? 0 : (int) $term->get('field_ttd_id')->value;
    if ($alias_id <= 0) {
      return;
    }

    $canonical_id = (int) $this->database->select('ttd_entity_aliases', 'ea')
      ->fields('ea', ['canonical_entity_id'])
      ->condition('alias_entity_id', $alias_id)
      ->execute()
      ->fetchField();
    if ($canonical_id <= 0 || $canonical_id === $alias_id) {
      return;
    }

    $canonical_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'ttd_topics',
      'field_ttd_id' => (string) $canonical_id,
    ]);
    if (empty($canonical_terms)) {
      return;
    }

    $canonical_term = reset($canonical_terms);
    if (!$canonical_term instanceof TermInterface || (int) $canonical_term->id() === (int) $term->id()) {
      return;
    }

    $event->setResponse(new RedirectResponse($canonical_term->toUrl('canonical', ['absolute' => TRUE])->toString(), 301));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 30],
    ];
  }

}
