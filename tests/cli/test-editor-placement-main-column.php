<?php

/**
 * Static regression checks for editor placement parity with WordPress.
 *
 * Run from the module root:
 *   php tests/cli/test-editor-placement-main-column.php
 */

$root = dirname(__DIR__, 2);

function ttd_editor_placement_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }

  echo "PASS: {$message}\n";
}

$module_file = file_get_contents($root . '/ttd_topics.module');
$settings_form = file_get_contents($root . '/src/Form/SettingsForm.php');
$schema_file = file_get_contents($root . '/config/schema/topicalboost.schema.yml');
$install_file = file_get_contents($root . '/ttd_topics.install');
$default_config = file_get_contents($root . '/config/install/ttd_topics.settings.yml');
$settings_css = file_get_contents($root . '/css/settings.css');

ttd_editor_placement_assert(strpos($module_file, "get('metabox_position')") === false, 'Editor placement ignores the removed metabox position setting');
ttd_editor_placement_assert(strpos($module_file, '$use_combined_editor = ($meta_gen_enabled && $is_saved && !$analysis_in_progress);') !== false, 'Combined editor no longer depends on metabox position');
ttd_editor_placement_assert(strpos($settings_form, "['metabox_position']") === false, 'Settings form no longer renders the metabox position control');
ttd_editor_placement_assert(strpos($settings_form, "->set('metabox_position'") === false, 'Settings submit no longer saves metabox position');
ttd_editor_placement_assert(strpos($schema_file, 'metabox_position') === false, 'Config schema no longer defines metabox position');
ttd_editor_placement_assert(strpos($install_file, "'metabox_position'") === false, 'Install defaults no longer define metabox position');
ttd_editor_placement_assert(strpos($default_config, 'metabox_position') === false, 'Default config no longer defines metabox position');
ttd_editor_placement_assert(strpos($settings_css, '#edit-metabox-position') === false, 'Settings CSS no longer targets the removed metabox position control');

echo "Editor placement regression checks passed.\n";
