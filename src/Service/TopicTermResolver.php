<?php

namespace Drupal\ttd_topics\Service;

use Drupal\taxonomy\TermInterface;

/**
 * Resolves topic terms by entity ID without conflating same-name subjects.
 */
final class TopicTermResolver {

  /**
   * Reuses an exact ID, claims one unambiguous unlinked term, or creates one.
   *
   * Existing term names belong to editors. Analysis and sync do not rename them.
   * Read-only resolution never claims an unlinked term or creates a term.
   */
  public static function resolve(string $name, $ttd_id, bool $save = TRUE, string $description = ''): ?TermInterface {
    $ttd_id = (string) $ttd_id;
    $name = trim($name);
    if ($name === '' || !ctype_digit($ttd_id) || (int) $ttd_id <= 0) {
      return NULL;
    }
    $name = mb_substr($name, 0, 255);
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $lock = \Drupal::lock();
    // A common lock covers different IDs competing for the same unlinked name.
    $lock_name = 'ttd_topics:entity_term_claim';
    $deadline = microtime(TRUE) + 10.0;
    while (!$lock->acquire($lock_name, 30.0)) {
      if (microtime(TRUE) >= $deadline) {
        throw new \RuntimeException('Could not acquire the TopicalBoost topic identity lock.');
      }
      // Availability is only a hint. Another waiter can win the next acquire.
      $lock->wait($lock_name, 1);
    }

    try {
      $terms = $storage->loadByProperties(['vid' => 'ttd_topics', 'field_ttd_id' => $ttd_id]);
      if ($terms) {
        // Discard objects cached before a different worker acquired this lock.
        $storage->resetCache(array_keys($terms));
        $terms = $storage->loadMultiple(array_keys($terms));
        ksort($terms, SORT_NUMERIC);
        foreach ($terms as $term) {
          $values = array_column($term->get('field_ttd_id')->getValue(), 'value');
          if ($values === [$ttd_id]) {
            return $term;
          }
        }
      }
      if (!$save) {
        return NULL;
      }

      $terms = $storage->loadByProperties(['vid' => 'ttd_topics', 'name' => $name]);
      if (count($terms) === 1) {
        $storage->resetCache(array_keys($terms));
        $term = $storage->load(key($terms));
        if ($term && $term->get('field_ttd_id')->isEmpty()) {
          $term->set('field_ttd_id', $ttd_id);
          $term->save();
          return $term;
        }
      }

      // A claimed or ambiguous name is not evidence of identity.
      $term = $storage->create([
        'vid' => 'ttd_topics',
        'name' => $name,
        'field_ttd_id' => $ttd_id,
        'description' => ['value' => $description, 'format' => 'plain_text'],
      ]);
      $term->save();
      if (!$term->id()) {
        throw new \RuntimeException('The TopicalBoost topic was not saved.');
      }
      return $term;
    }
    finally {
      $lock->release($lock_name);
    }
  }

}
