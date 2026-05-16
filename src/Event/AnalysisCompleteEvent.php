<?php

namespace Drupal\ttd_topics\Event;

use Drupal\node\NodeInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event fired after TopicalBoost analysis results are applied to a node.
 */
class AnalysisCompleteEvent extends Event {

  /**
   * Constructs the event.
   */
  public function __construct(
    protected NodeInterface $node,
    protected array $appliedTopicIds,
  ) {}

  /**
   * Gets the analyzed node.
   */
  public function getNode(): NodeInterface {
    return $this->node;
  }

  /**
   * Gets applied topic term IDs.
   */
  public function getAppliedTopicIds(): array {
    return $this->appliedTopicIds;
  }

}
