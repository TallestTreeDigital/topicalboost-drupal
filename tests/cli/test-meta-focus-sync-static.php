<?php

/**
 * @file
 * Static regression check: the meta generator must reload About topics after
 * the editor saves a topic change, instead of keeping its page-load list.
 *
 * Run with: php tests/cli/test-meta-focus-sync-static.php
 */

$root = dirname(__DIR__, 2);
$editor = file_get_contents($root . '/js/post-editor.js');
$meta = file_get_contents($root . '/js/meta-generator.js');

$failures = [];

if (strpos($editor, 'function notifyTopicsChanged()') === FALSE) {
  $failures[] = 'post-editor.js must define notifyTopicsChanged().';
}

// Accept/reject, tier remove, tier update, and manual removal.
$calls = substr_count($editor, 'notifyTopicsChanged();');
if ($calls < 4) {
  $failures[] = "post-editor.js must call notifyTopicsChanged() after each saved topic change (found {$calls}, expected at least 4).";
}

if (strpos($editor, "trigger('ttd:tierUpdated'") === FALSE) {
  $failures[] = 'post-editor.js must trigger ttd:tierUpdated.';
}

if (!preg_match("/on\\('ttd:tierUpdated'[\\s\\S]{0,200}fetchKeywords\\(\\)/", $meta)) {
  $failures[] = 'meta-generator.js must reload keywords when ttd:tierUpdated fires.';
}

if (!preg_match("/No Main\\/About topics[\\s\\S]{0,400}selectedKeywords = \\[\\][\\s\\S]{0,200}prop\\('disabled', true\\)/", $meta)) {
  $failures[] = 'meta-generator.js must clear the selection and disable Generate when no About topics remain.';
}

if ($failures) {
  fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
  exit(1);
}

echo "Meta focus sync static checks passed." . PHP_EOL;
