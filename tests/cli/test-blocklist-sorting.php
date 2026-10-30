<?php
/** Run with drush scr <this file> in a local Drupal site. */
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\Node;
use Drupal\ttd_topics\Controller\TtdTopicsController;
use Symfony\Component\HttpFoundation\Request;

$terms = [];
$nodes = [];
$checks = 0;
$prefix = 'Blocklist sort ' . bin2hex(random_bytes(4));
$assert = static function ($condition, $message) use (&$checks) {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  $checks++;
  echo "PASS: {$message}\n";
};
$stack = \Drupal::service('request_stack');
$build = static function ($sort, $order, $extra = []) use ($stack, $prefix) {
  $request = Request::create('/admin/structure/taxonomy/manage/ttd_topics/overview', 'GET', $extra + ['search' => $prefix, 'sort' => $sort, 'order' => $order]);
  $stack->push($request);
  try {
    return \Drupal::service('class_resolver')->getInstanceFromDefinition(TtdTopicsController::class)->overviewPage();
  } finally {
    $stack->pop();
  }
};
try {
  $index = \Drupal::service('ttd_topics.topic_list_index');
  $index->ensure();
  for ($batch = 0; $batch < 10 && !$index->ready(); $batch++) {
    $index->backfill();
  }
  $assert($index->ready(), 'The maintained index is ready before the paginated checks.');
  foreach (['A visible' => 0, 'B blocked' => 1, 'C visible' => 0, 'D blocked' => 1] as $name => $hidden) {
    $term = Term::create(['vid' => 'ttd_topics', 'name' => "{$prefix} {$name}", 'field_hide' => $hidden, 'status' => 1]);
    $term->save();
    $terms[] = $term;
  }
  $ids = array_map(static fn($term) => (int) $term->id(), $terms);
  $find_table = static function ($build) use (&$find_table) {
    if (($build['#type'] ?? '') === 'tableselect') {
      return $build;
    }
    foreach ($build as $key => $value) {
      if (is_array($value) && !str_starts_with((string) $key, '#')) {
        $table = $find_table($value);
        if ($table) {
          return $table;
        }
      }
    }
    return [];
  };
  $desc = $find_table($build('hidden', 'desc'));
  $asc = $find_table($build('hidden', 'asc'));
  $assert(array_map('intval', array_keys($desc['#options'])) === [$ids[1], $ids[3], $ids[0], $ids[2]], 'Blocked terms sort first, with names ordered within each group.');
  $assert(array_map('intval', array_keys($asc['#options'])) === [$ids[0], $ids[2], $ids[1], $ids[3]], 'Ascending Blocklist sort shows visible terms first.');
  $assert($desc['#options'][$ids[1]]['hidden'] === '✓' && $desc['#options'][$ids[0]]['hidden'] === '—', 'Blocklist column displays the same state used for sorting.');
  $header = (string) $desc['#header']['hidden']['data']['#markup'];
  $assert(str_contains($header, 'sort=hidden') && str_contains($header, 'order=asc') && str_contains($header, 'search='), 'Header reverses direction and preserves the search filter.');
  $names = $find_table($build('name', 'asc'));
  $assert(array_map('intval', array_keys($names['#options'])) === $ids, 'Name sorting remains available.');
  $count = $find_table($build('count', 'desc'));
  $assert(array_map('intval', array_keys($count['#options'])) === $ids, 'Posts sorting still orders equal counts by name.');
  $filtered = $find_table($build('hidden', 'desc', ['visibility' => 'hidden']));
  $assert(array_map('intval', array_keys($filtered['#options'])) === [$ids[1], $ids[3]], 'Visibility filtering still applies while sorting.');
  $filters = ['search' => $prefix, 'min_posts' => '', 'visibility' => '', 'threshold' => 10];
  $page = static function ($sort, $order, $offset, $limit) use ($index, &$filters) {
    return array_map(static fn($row) => (int) $row->tid, $index->page($filters, $sort, $order, $offset, $limit, $index->stats($filters)));
  };
  $assert($page('hidden', 'desc', 1, 2) === [$ids[3], $ids[0]], 'A page spanning both boolean groups has the correct order.');
  $assert($page('hidden', 'asc', 1, 2) === [$ids[2], $ids[1]], 'The ascending page also spans both groups correctly.');
  $assert($page('hidden', 'desc', 10, 2) === [], 'An offset beyond the final page returns no rows.');
  $assert($page('name', 'desc', 0, 2) === [$ids[3], $ids[2]], 'Descending Name sorting remains available.');
  $terms[0]->set('field_hide', 1)->save();
  $assert($page('hidden', 'desc', 0, 3) === [$ids[0], $ids[1], $ids[3]], 'Changing the boolean immediately updates the indexed order.');
  $terms[0]->set('field_hide', 0)->save();
  $terms[0]->setName("{$prefix} Z renamed")->save();
  $assert($page('hidden', 'asc', 0, 2) === [$ids[2], $ids[0]], 'Renaming a term updates the alphabetical index.');
  $node = Node::create(['type' => 'article', 'title' => 'Blocklist sort runtime fixture', 'status' => 1, 'field_ttd_topics' => [['target_id' => $ids[0]]]]);
  $node->save();
  $nodes[] = $node;
  $assert($index->stats($filters)['posts'] === 1 && $page('count', 'desc', 0, 1) === [$ids[0]], 'Node-insert hooks update Posts sorting and invalidate summary counts.');
  $filters['min_posts'] = '1';
  $assert($page('hidden', 'desc', 0, 2) === [$ids[0]], 'The minimum-posts filter applies before pagination.');
  $node->set('field_ttd_topics', [['target_id' => $ids[2]]])->save();
  $assert($page('hidden', 'desc', 0, 2) === [$ids[2]], 'Node updates refresh both removed and added topic counts.');
  $node->delete();
  $nodes = [];
  $assert($index->stats($filters)['terms'] === 0, 'Node deletion updates the indexed count immediately.');
  $filters['min_posts'] = '';
  $terms[1]->setUnpublished()->save();
  $assert($index->stats($filters)['terms'] === 3, 'Unpublished terms are excluded from the indexed list.');
  $terms[1]->delete();
  unset($terms[1]);
  $assert($page('hidden', 'desc', 0, 4) === [$ids[3], $ids[2], $ids[0]], 'Deleted terms leave the list immediately.');
  echo "{$checks} assertions passed.\n";
} finally {
  foreach ($nodes as $node) {
    $node->delete();
  }
  foreach ($terms as $term) {
    $term->delete();
  }
}
