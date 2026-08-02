<?php

/**
 * Static release-safety checks for site-controlled analysis rollout support.
 */

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/ttd_topics.module');
$settings_form = file_get_contents($root . '/src/Form/SettingsForm.php');
$bulk_controller = file_get_contents($root . '/src/Controller/BulkAnalysisController.php');
$watchlist_controller = file_get_contents($root . '/src/Controller/WatchlistController.php');
$module_info = file_get_contents($root . '/ttd_topics.info.yml');

$failures = [];
$assert = static function ($condition, $message) use (&$failures) {
  if (!$condition) {
    $failures[] = $message;
  }
};

$assert(strpos($module, "define('TOPICALBOOST_API_ENDPOINT', 'https://api.topicalboost.com')") !== FALSE, 'The normal endpoint must always be production.');
$assert(strpos($module, 'beta.api.topicalboost.com') === FALSE, 'The removed client-side beta endpoint must not remain in module routing.');
$assert(strpos($module, "define('TOPICALBOOST_ANALYSIS_CAPABILITIES', 'analysis-about-v2,priority-topics-v1')") !== FALSE, 'Both V7 capabilities must be advertised.');
$assert(strpos($module, "'x-tb-capabilities' => TOPICALBOOST_ANALYSIS_CAPABILITIES") !== FALSE, 'Standard authenticated headers must advertise V7 capabilities.');
$assert(strpos($bulk_controller, "'/analyze/bulk/initiate'") !== FALSE && strpos($bulk_controller, "'headers' => \\ttd_topics_api_headers(\$api_key)") !== FALSE, 'Bulk initiate must use the capability-aware header helper.');
$assert(strpos($watchlist_controller, "'headers' => \\ttd_topics_api_headers(\$api_key)") !== FALSE, 'Priority Topic requests must advertise capabilities.');
$assert(strpos($settings_form, "['use_beta_api']") === FALSE && strpos($settings_form, "->set('use_beta_api'") === FALSE, 'The removed per-client Beta Analysis control must not be rendered or saved.');
$assert(strpos($module_info, 'version: 2.0.20') !== FALSE, 'The compatible module release must be version 2.0.20.');

if ($failures) {
  fwrite(STDERR, "Analysis rollout compatibility checks failed:\n- " . implode("\n- ", $failures) . "\n");
  exit(1);
}

echo "Analysis rollout compatibility checks passed.\n";
