<?php

/**
 * Static regression checks for the non-blocking demand metrics contract.
 *
 * Run from the module root with:
 * php tests/cli/test-demand-metrics-async-static.php
 */

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/src/Controller/TtdTopicsController.php');
$editor = file_get_contents($root . '/js/post-editor.js');
$info = file_get_contents($root . '/ttd_topics.info.yml');
$failures = [];

function demand_metrics_assert_contains($haystack, $needle, $message) {
  global $failures;
  if (strpos($haystack, $needle) === FALSE) {
    $failures[] = $message;
  }
}

demand_metrics_assert_contains(
  $controller,
  "!empty(\$data['pending']) ? 202 : 200",
  'The Drupal AJAX bridge must preserve HTTP 202 for pending metrics.'
);
demand_metrics_assert_contains(
  $controller,
  "'X-TB-Demand-Metrics-Async' => '1'",
  'Drupal must explicitly opt into the asynchronous API response contract.'
);
demand_metrics_assert_contains(
  $controller,
  "if (\$is_pending)",
  'The API queue acknowledgement must be handled before metric persistence.'
);
demand_metrics_assert_contains(
  $controller,
  "'pending' => TRUE",
  'Pending demand metrics must be returned explicitly.'
);
demand_metrics_assert_contains(
  $controller,
  "|| !empty(\$metrics['pending'])",
  'Pending payloads must never qualify as Drupal demand cache entries.'
);
demand_metrics_assert_contains(
  $controller,
  '!$request_cache_stale',
  'Stale Drupal request cache entries must trigger a background refresh.'
);
demand_metrics_assert_contains(
  $controller,
  '!$canonical_cache_stale',
  'Stale canonical Drupal cache entries must trigger a background refresh.'
);

$pending_position = strpos($controller, 'if ($is_pending)');
$cache_position = strpos($controller, '$state->set($cache_key', $pending_position ?: 0);
if ($pending_position === FALSE || $cache_position === FALSE || $pending_position > $cache_position) {
  $failures[] = 'Pending handling must occur before the request-path cache write.';
}

demand_metrics_assert_contains(
  $editor,
  'const maxPolls = options.maxPolls || 10;',
  'Drupal editor polling must have the reduced ten-poll ceiling.'
);
demand_metrics_assert_contains(
  $editor,
  'return Math.min(8, Math.max(serverDelay, 2 + pollAttempt));',
  'Drupal editor polling must use bounded backoff.'
);
demand_metrics_assert_contains(
  $editor,
  'force_refresh: pollAttempt > 0 ? 1 : 0',
  'Follow-up Drupal polls must bypass local caches.'
);
demand_metrics_assert_contains(
  $editor,
  'data.pending',
  'The Drupal editor must understand pending responses.'
);
demand_metrics_assert_contains(
  $editor,
  'Estimated traffic opportunity:',
  'Customer-facing Drupal demand labels must use provider-neutral wording.'
);
demand_metrics_assert_contains(
  $editor,
  ".ttd-kd-badge.ttd-kd-no-data, .ttd-kd-badge.ttd-kd-stale",
  'Drupal must automatically refresh both missing and stale demand metrics.'
);
demand_metrics_assert_contains(
  $editor,
  "if (!\$badge.hasClass('ttd-kd-stale'))",
  'Drupal must preserve stale values while their refresh is pending.'
);
demand_metrics_assert_contains(
  $info,
  'version: 2.0.19',
  'Drupal module version must be bumped for the async demand release.'
);

$public_files = [
  $root . '/src/Controller/TtdTopicsController.php',
  $root . '/src/Plugin/Block/AdminNodeTopicsBlock.php',
  $root . '/js/post-editor.js',
  $root . '/js/meta-generator.js',
  $root . '/ttd_topics.module',
  $root . '/ttd_topics.info.yml',
];
foreach ($public_files as $file) {
  $contents = file_get_contents($file);
  if (stripos($contents, 'ahrefs') !== FALSE) {
    $failures[] = basename($file) . ' exposes the demand provider name.';
  }
}

if ($failures) {
  fwrite(STDERR, "Drupal demand metrics async checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Drupal demand metrics async static checks passed.\n";
