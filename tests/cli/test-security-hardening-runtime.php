<?php

use Drupal\file\Entity\File;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

function security_runtime_assert(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  print "PASS: {$message}\n";
}

$suffix = substr(hash('sha256', uniqid('tb-security-', TRUE)), 0, 10);
$created_entities = [];
$original_api_key = \Drupal::config('ttd_topics.settings')->get('topicalboost_api_key');

try {
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('topicalboost_api_key', 'runtime-secret-' . $suffix)
    ->save();
  $route_provider = \Drupal::service('router.route_provider');
  foreach ([
    'topicalboost.bulk_analysis.count',
    'ttd_topics.update_topics',
    'topicalboost.api.schema_images.generate',
    'topicalboost.api.watchlist.add',
    'topicalboost.api.sync.start',
    'topicalboost.api.clear_analysis_flags',
  ] as $route_name) {
    $route = $route_provider->getRouteByName($route_name);
    security_runtime_assert($route->getRequirement('_csrf_request_header_token') === 'TRUE', "{$route_name} requires Drupal's CSRF request-header check");
  }

  $editor_role = Role::create(['id' => 'tb_sec_editor_' . $suffix, 'label' => 'TB security editor']);
  $editor_role->grantPermission('access content');
  $editor_role->grantPermission('access content overview');
  $editor_role->save();
  $created_entities[] = $editor_role;

  $manager_role = Role::create(['id' => 'tb_sec_manager_' . $suffix, 'label' => 'TB security manager']);
  $manager_role->grantPermission('access content');
  $manager_role->grantPermission('administer topicalboost');
  $manager_role->save();
  $created_entities[] = $manager_role;

  $editor = User::create([
    'name' => 'tb_sec_editor_' . $suffix,
    'mail' => 'tb-sec-editor-' . $suffix . '@example.test',
    'status' => 1,
    'roles' => [$editor_role->id()],
  ]);
  $editor->save();
  $created_entities[] = $editor;

  $manager = User::create([
    'name' => 'tb_sec_manager_' . $suffix,
    'mail' => 'tb-sec-manager-' . $suffix . '@example.test',
    'status' => 1,
    'roles' => [$manager_role->id()],
  ]);
  $manager->save();
  $created_entities[] = $manager;

  $controller = new \Drupal\ttd_topics\Controller\WidgetsController();
  security_runtime_assert(!$controller->access($editor)->isAllowed(), 'Content-overview editor cannot open the widgets page');
  security_runtime_assert($controller->access($manager)->isAllowed(), 'Configured TopicalBoost manager can open the widgets page');
  $page_build = $controller->page();
  security_runtime_assert(strpos(serialize($page_build), 'runtime-secret-' . $suffix) === FALSE, 'Widgets page render array does not contain the site API key');

  $block_manager = \Drupal::service('plugin.manager.block');
  foreach (['topicalboost_citations', 'topicalboost_search_clippings'] as $plugin_id) {
    $block = $block_manager->createInstance($plugin_id, []);
    security_runtime_assert(!$block->access($editor, TRUE)->isAllowed(), "{$plugin_id} denies the content-overview editor");
    security_runtime_assert($block->access($manager, TRUE)->isAllowed(), "{$plugin_id} allows the configured TopicalBoost manager");
    security_runtime_assert(strpos(serialize($block->build()), 'runtime-secret-' . $suffix) === FALSE, "{$plugin_id} render array does not contain the site API key");
  }

  $owned_file = File::create([
    'uri' => 'public://tb-security-owned-' . $suffix . '.jpg',
    'filename' => 'tb-security-owned-' . $suffix . '.jpg',
    'uid' => $manager->id(),
    'status' => 1,
  ]);
  $owned_file->save();
  $created_entities[] = $owned_file;

  $foreign_file = File::create([
    'uri' => 'public://tb-security-foreign-' . $suffix . '.jpg',
    'filename' => 'tb-security-foreign-' . $suffix . '.jpg',
    'uid' => $editor->id(),
    'status' => 1,
  ]);
  $foreign_file->save();
  $created_entities[] = $foreign_file;

  $node_without_source = new class {
    public function hasField($field_name): bool {
      return FALSE;
    }
  };

  $schema_controller = new \Drupal\ttd_topics\Controller\SchemaImagesController();
  $reflection = new ReflectionMethod($schema_controller, 'isAllowedSourceFile');
  $reflection->setAccessible(TRUE);
  security_runtime_assert($reflection->invoke($schema_controller, $owned_file, $node_without_source, $manager), 'Schema image helper permits a file owned by the caller');
  security_runtime_assert(!$reflection->invoke($schema_controller, $foreign_file, $node_without_source, $manager), 'Schema image helper rejects an unrelated foreign file');

  print "Drupal security hardening runtime checks passed.\n";
}
finally {
  \Drupal::configFactory()->getEditable('ttd_topics.settings')
    ->set('topicalboost_api_key', $original_api_key)
    ->save();
  foreach (array_reverse($created_entities) as $entity) {
    try {
      $entity->delete();
    }
    catch (Throwable $error) {
      print "WARN: cleanup failed for " . get_class($entity) . ": " . $error->getMessage() . "\n";
    }
  }
}
