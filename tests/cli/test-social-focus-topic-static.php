<?php

/**
 * @file
 * Static regression check: the Social tab must offer a focus topic, send it to
 * the API, and select it again on the next visit (TAL-186).
 *
 * Run with: php tests/cli/test-social-focus-topic-static.php
 */

$root = dirname(__DIR__, 2);
$meta = file_get_contents($root . '/js/meta-generator.js');
$controller = file_get_contents($root . '/src/Controller/MetaGeneratorController.php');
$module = file_get_contents($root . '/ttd_topics.module');

$failures = [];

$start = strpos($meta, "'#ttd-social-meta-generator-container'");
$social_init = $start === FALSE ? '' : substr($meta, $start, 1200);
if ($social_init === '') {
  $failures[] = 'The Social generator init was not found in meta-generator.js.';
}
else {
  if (strpos($social_init, 'hideKeywords: true') !== FALSE) {
    $failures[] = 'The Social tab must show the focus topic chips.';
  }
  if (strpos($social_init, 'selectedKeyword: config.socialFocusTopic') === FALSE) {
    $failures[] = 'The Social tab must preselect the saved focus topic.';
  }
}

if (!preg_match("/focus_topic:\\s*focusTopic/", $meta)) {
  $failures[] = 'Social generation must send the selected focus topic.';
}
if (strpos($meta, 'this.options.selectedKeyword') === FALSE || strpos($meta, 'this.options.defaultKeyword()') === FALSE) {
  $failures[] = 'meta-generator.js must preselect the saved topic, then the default topic.';
}
if (!preg_match("/seoMetaGenerator = generator;/", $meta) || !preg_match("/defaultKeyword: function\\(\\)[\\s\\S]{0,200}seoMetaGenerator\\.state\\.selectedKeywords/", $meta)) {
  $failures[] = 'Without a saved topic, the Social tab must start from the SEO tab pick.';
}
if (!preg_match("/minKeywords === 0\\)[\\s\\S]{0,400}prop\\('disabled', false\\)/", $meta)) {
  $failures[] = 'Social generation must stay available when a node has no About topics.';
}
if (strpos($meta, '!this.options.isSocial') === FALSE) {
  $failures[] = 'Only SEO meta should offer the original title as a generated option.';
}

$start = strpos($controller, 'public function generateSocial(');
$end = $start === FALSE ? FALSE : strpos($controller, 'public function ', $start + 10);
$generate = ($start === FALSE) ? '' : substr($controller, $start, $end === FALSE ? NULL : $end - $start);
if ($generate === '') {
  $failures[] = 'MetaGeneratorController::generateSocial() was not found.';
}
else {
  if (strpos($generate, "strip_tags(\$content['focus_topic'])") === FALSE) {
    $failures[] = 'generateSocial() must sanitize focus_topic.';
  }
  if (strpos($generate, "\$api_params['focusTopic'] = \$focus_topic;") === FALSE) {
    $failures[] = 'generateSocial() must send focusTopic to the API.';
  }
  if (strpos($generate, "keyValue('ttd_topics.social_focus_topic')->set(") === FALSE) {
    $failures[] = 'generateSocial() must save the focus topic after a successful generation.';
  }
}

if (strpos($module, "'socialFocusTopic' => \\Drupal::keyValue('ttd_topics.social_focus_topic')->get(") === FALSE) {
  $failures[] = 'The editor settings must include the saved social focus topic.';
}
if (strpos($module, "keyValue('ttd_topics.social_focus_topic')->delete(") === FALSE) {
  $failures[] = 'Deleting a node must remove its saved social focus topic.';
}

if ($failures) {
  fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
  exit(1);
}

echo "Social focus topic static checks passed." . PHP_EOL;
