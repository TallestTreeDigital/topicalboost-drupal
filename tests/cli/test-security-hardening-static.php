<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$monorepo = dirname($root, 2);

function security_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
  fwrite(STDOUT, "PASS: {$message}\n");
}

function security_file(string $path): string {
  $contents = file_get_contents($path);
  security_assert($contents !== FALSE, "Read {$path}");
  return (string) $contents;
}

function security_route_block(string $routing, string $route): string {
  $pattern = '/^' . preg_quote($route, '/') . ":\n(.*?)(?=^[A-Za-z0-9_.-]+:\n|\z)/ms";
  security_assert((bool) preg_match($pattern, $routing, $matches), "Route {$route} exists");
  return $matches[0];
}

$routing = security_file($root . '/ttd_topics.routing.yml');
$csrf_routes = [
  'topicalboost.bulk_analysis.count',
  'topicalboost.bulk_analysis.initiate',
  'topicalboost.bulk_analysis.reset',
  'topicalboost.bulk_analysis.apply_results',
  'topicalboost.api.validate_key',
  'topicalboost.api.author_field_mapping',
  'topicalboost.api.author_settings',
  'ttd_topics.update_tier',
  'ttd_topics.remove_tier',
  'ttd_topics.update_topics',
  'ttd_topics.run_analysis',
  'topicalboost.api.toggle_topic_visibility',
  'topicalboost.api.toggle_force_show',
  'topicalboost.api.schema_images.generate',
  'topicalboost.api.schema_images.clear',
  'topicalboost.api.watchlist.search',
  'topicalboost.api.watchlist.add',
  'topicalboost.api.watchlist.create_custom',
  'topicalboost.api.watchlist.remove',
  'topicalboost.api.sync.start',
  'topicalboost.api.sync.cancel',
  'topicalboost.api.clear_analysis_flags',
  'topicalboost.api.handle_stuck_posts',
  'topicalboost.api.clear_polling_flag',
  'topicalboost.api.clear_rejected_topics',
  'topicalboost.api.entity_info',
  'topicalboost.api.search_countries.save',
  'topicalboost.api.create_topic_term',
  'topicalboost.api.get_term_by_ttd_id',
];

foreach ($csrf_routes as $route) {
  $block = security_route_block($routing, $route);
  security_assert(strpos($block, "_csrf_request_header_token: 'TRUE'") !== FALSE, "{$route} requires a CSRF header token");
}

$manual_csrf_routes = [
  'topicalboost.api.meta.generate',
  'topicalboost.api.meta.save',
  'topicalboost.api.meta.generate_social',
  'topicalboost.api.meta.save_social',
  'topicalboost.api.meta.clear_social',
  'topicalboost.api.meta.discard_pending',
  'topicalboost.api.meta.clear',
];
$non_mutating_post_routes = ['topicalboost.api.telemetry'];
preg_match_all('/^([A-Za-z0-9_.-]+):\n(.*?)(?=^[A-Za-z0-9_.-]+:\n|\z)/ms', $routing, $route_matches, PREG_SET_ORDER);
foreach ($route_matches as $route_match) {
  $route_name = $route_match[1];
  $route_block = $route_match[0];
  if (!preg_match('/methods:\s*\[[^]]*(POST|PUT|PATCH|DELETE)[^]]*\]/', $route_block)) {
    continue;
  }
  $protected = strpos($route_block, "_csrf_request_header_token: 'TRUE'") !== FALSE;
  $manual = in_array($route_name, $manual_csrf_routes, TRUE);
  $non_mutating = in_array($route_name, $non_mutating_post_routes, TRUE);
  security_assert($protected || $manual || $non_mutating, "Unsafe route {$route_name} has CSRF protection or a reviewed exception");
}

$meta_controller = security_file($root . '/src/Controller/MetaGeneratorController.php');
security_assert(substr_count($meta_controller, "csrfToken()->validate(\$token, 'ttd_meta_generator')") >= count($manual_csrf_routes), 'Every manually protected meta mutation validates its CSRF token');

$widgets_route = security_route_block($routing, 'topicalboost.widgets');
security_assert(strpos($widgets_route, 'WidgetsController::access') !== FALSE, 'Widgets page uses configured TopicalBoost access callback');
security_assert(strpos($widgets_route, "access content overview") === FALSE, 'Widgets page no longer grants broad content-overview access');
$widgets_proxy_route = security_route_block($routing, 'topicalboost.widgets.proxy');
security_assert(strpos($widgets_proxy_route, 'WidgetsController::access') !== FALSE, 'Widget proxy uses configured TopicalBoost access callback');

$widgets_controller = security_file($root . '/src/Controller/WidgetsController.php');
security_assert(strpos($widgets_controller, "get('required_permission')") !== FALSE, 'Widgets page honors the configured management permission');
security_assert(strpos($widgets_controller, 'data-topicalboost-api-key') === FALSE, 'Widgets page does not render the site API key into browser markup');
security_assert(strpos($widgets_controller, 'data-topicalboost-proxy-base') !== FALSE, 'Widgets page sends browser data requests through the authenticated Drupal proxy');
security_assert(strpos($widgets_controller, "'x-topicalboost-api-key' => \$api_key") !== FALSE, 'Widget proxy adds the site API key only to its server-side request');

foreach (['CitationsBlock.php', 'SearchClippingsBlock.php'] as $block_file) {
  $block_source = security_file($root . '/src/Plugin/Block/' . $block_file);
  security_assert(strpos($block_source, 'function blockAccess') !== FALSE, "{$block_file} enforces block access");
  security_assert(strpos($block_source, "get('required_permission')") !== FALSE, "{$block_file} honors the configured management permission");
  security_assert(strpos($block_source, 'data-api-key') === FALSE, "{$block_file} does not render the site API key into browser markup");
  security_assert(strpos($block_source, 'data-topicalboost-proxy-base') !== FALSE, "{$block_file} uses the authenticated Drupal widget proxy");
}

$schema_controller = security_file($root . '/src/Controller/SchemaImagesController.php');
security_assert(strpos($schema_controller, "access('update', \$account)") !== FALSE, 'Schema generation requires node update access');
security_assert(strpos($schema_controller, "access('view', \$account)") !== FALSE, 'Schema generation requires source file view access');
security_assert(strpos($schema_controller, 'isAllowedSourceFile') !== FALSE, 'Explicit schema source files must belong to the node or caller');
security_assert(strpos($schema_controller, "'uid' => \\Drupal::currentUser()->id()") !== FALSE, 'Uploaded schema sources retain caller ownership');

$csrf_js = security_file($root . '/js/csrf.js');
security_assert(strpos($csrf_js, '$.ajaxPrefilter') !== FALSE, 'Drupal AJAX mutations receive the standard CSRF header');
security_assert(strpos($csrf_js, "'/api/topicalboost/'") !== FALSE, 'CSRF header injection is scoped to TopicalBoost API routes');

$widget_ts = security_file($monorepo . '/apps/api/src/embed/widget.ts');
$citations_ts = security_file($monorepo . '/apps/api/src/embed/citations-widget.ts');
security_assert(strpos($widget_ts, "headers['x-topicalboost-api-key'] = config.topicalBoostApiKey") !== FALSE, 'Top Stories widget sends direct-embed keys in a request header');
security_assert(strpos($widget_ts, 'topicalboostProxyBase') !== FALSE, 'Top Stories widget supports a server-side data proxy without a browser key');
security_assert(strpos($widget_ts, 'apiKey: config.topicalBoostApiKey,\n        sortBy') === FALSE, 'Top Stories widget does not put its key in the query string');
security_assert(substr_count($citations_ts, "headers['x-topicalboost-api-key'] = this.topicalBoostApiKey") >= 2, 'Citations widget sends direct-embed keys in request headers');
security_assert(strpos($citations_ts, 'topicalboostProxyBase') !== FALSE, 'Citations widget supports a server-side data proxy without a browser key');
security_assert(strpos($citations_ts, 'apiKey=${encodeURIComponent(this.topicalBoostApiKey)}') === FALSE, 'Citations widget does not put its key in the query string');

$allowlist = security_file($monorepo . '/scripts/drupal-public-files.txt');
$operational_artifacts = array_merge(
  glob($root . '/reprocess*') ?: [],
  glob($root . '/*REPROCESS*') ?: []
);
security_assert($operational_artifacts === [], 'One-off reprocessing artifacts are absent from the public source tree');
security_assert(!preg_match('/^.*reprocess.*$/mi', $allowlist), 'One-off reprocessing artifacts are absent from the public export allowlist');

fwrite(STDOUT, "Drupal security hardening static checks passed.\n");
