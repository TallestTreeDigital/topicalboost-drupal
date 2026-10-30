<?php

namespace Drupal\node { interface NodeInterface {} }
namespace Drupal\Core\Controller { class ControllerBase {} }

namespace {
  function metatag_data_encode(array $tags = []): string { return json_encode($tags, JSON_THROW_ON_ERROR); }
  require dirname(__DIR__, 2) . '/src/Service/SavedMetatag.php';
  require dirname(__DIR__, 2) . '/src/Controller/MetaGeneratorController.php';
  require dirname(__DIR__, 2) . '/src/Service/AnalyticsInventoryService.php';

  use Drupal\ttd_topics\Service\SavedMetatag;
  $checks = 0;
  function check_meta($condition, $message) {
    if (!$condition) { throw new \RuntimeException('FAIL: ' . $message); }
    $GLOBALS['checks']++;
    echo 'PASS: ' . $message . "\n";
  }
  class MetaNode implements \Drupal\node\NodeInterface {
    public function __construct(public $raw, public string $type = 'metatag', public string $name = 'field_custom_seo') {}
    public function hasField($name) { return $name === $this->name; }
    public function getFieldDefinitions() { return [$this->name => $this->getFieldDefinition($this->name)]; }
    public function getFieldDefinition($name) { return new class($this->type) {
      public function __construct(private string $type) {}
      public function getType() { return $this->type; }
    }; }
    public function get($name) { return new MetaValues([['value' => $this->raw]]); }
    public function set($name, $value) { $this->raw = is_array($value) ? $value[0]['value'] : $value; }
  }
  class MetaValues {
    public $value;
    public function __construct(private array $items) { $this->value = $items[0]['value'] ?? NULL; }
    public function getValue() { return $this->items; }
  }
  class MultiMetaNode implements \Drupal\node\NodeInterface {
    public function __construct(public array $fields) {}
    public function hasField($name) { return isset($this->fields[$name]); }
    public function getFieldDefinitions() {
      $definitions = [];
      foreach ($this->fields as $name => $_) $definitions[$name] = $this->getFieldDefinition($name);
      return $definitions;
    }
    public function getFieldDefinition($name) { return (new MetaNode('', $this->fields[$name]['type']))->getFieldDefinition($name); }
    public function get($name) { return new MetaValues($this->fields[$name]['items']); }
    public function set($name, $value) { $this->fields[$name]['items'] = $value; }
  }
  $tags = ['description' => ' Raw &amp; [node:title] 🙂 ', 'canonical_url' => 'https://example.test/canonical/', 'og_description' => 'Social text', 'robots' => 'index, follow'];
  foreach ([$tags, json_encode($tags), serialize($tags)] as $raw) {
    $node = new MetaNode($raw);
    check_meta(SavedMetatag::decode($raw) === $tags, 'JSON, serialized and native maps retain all saved tags');
    check_meta(SavedMetatag::description($node) === ['available' => TRUE, 'value' => $tags['description']], 'The raw saved description retains tokens, entities, whitespace and Unicode');
  }
  foreach ([NULL, '', [], '{}', '[]', serialize([]), json_encode(['description' => " \n "])] as $raw) {
    check_meta(SavedMetatag::description(new MetaNode($raw)) === ['available' => TRUE, 'value' => NULL], 'A successfully read empty field is a confirmed absence');
  }
  foreach (['{broken', 'not encoded', 'null', 'false', '["invalid list"]', json_encode(['description' => FALSE]), serialize(['description' => new \stdClass()])] as $raw) {
    check_meta(SavedMetatag::description(new MetaNode($raw)) === ['available' => FALSE], 'Malformed descriptions stay unavailable and cannot become a clear');
  }
  check_meta(SavedMetatag::description(new MetaNode('', 'string_long')) === ['available' => FALSE], 'An unrelated custom field is not mistaken for a Metatag description');
  check_meta(SavedMetatag::description(new MetaNode(serialize($tags), 'string_long', 'field_meta_tags'))['value'] === $tags['description'], 'Legacy named Metatag fields remain supported');

  $class = new \ReflectionClass(\Drupal\ttd_topics\Controller\MetaGeneratorController::class);
  $controller = $class->newInstanceWithoutConstructor();
  $read = $class->getMethod('getMetatagValues'); $read->setAccessible(TRUE);
  $write = $class->getMethod('setMetatagValues'); $write->setAccessible(TRUE);
  $node = new MultiMetaNode([
    'field_meta_tags' => ['type' => 'string_long', 'items' => [['value' => serialize(['description' => 'Legacy'])]]],
    'field_metatag' => ['type' => 'metatag', 'items' => [['value' => json_encode(['description' => 'Earlier native'])]]],
    'field_custom_seo' => ['type' => 'metatag', 'items' => [
      ['value' => json_encode(['canonical_url' => 'https://example.test/canonical'])],
      ['value' => json_encode(['description' => 'Later delta', 'og_description' => 'Keep social'])],
      ['value' => json_encode(['description' => 'Hidden duplicate'])],
    ]],
  ]);
  check_meta(SavedMetatag::fieldName($node) === 'field_custom_seo', 'The last native Metatag field wins over named native and legacy fields');
  check_meta(SavedMetatag::description($node)['value'] === 'Later delta', 'The first delta defining description wins within the final native field');
  $before = $node->fields;
  $current = $read->invoke($controller, $node);
  $current['description'] = 'New description';
  $write->invoke($controller, $node, $current);
  check_meta(SavedMetatag::description($node)['value'] === 'New description', 'Save updates the description that native Metatag will use');
  check_meta(!isset(SavedMetatag::decode($node->fields['field_custom_seo']['items'][1]['value'])['description']) && !isset(SavedMetatag::decode($node->fields['field_custom_seo']['items'][2]['value'])['description']), 'Save removes stale duplicates before native default-value filtering');
  check_meta(count($node->fields['field_custom_seo']['items']) === 3 && SavedMetatag::tags($node)['og_description'] === 'Keep social', 'Save preserves later deltas and unrelated tags');
  check_meta($node->fields['field_meta_tags'] === $before['field_meta_tags'] && $node->fields['field_metatag'] === $before['field_metatag'], 'Save leaves other metadata fields untouched');
  unset($current['description']);
  $write->invoke($controller, $node, $current);
  check_meta(SavedMetatag::description($node)['value'] === NULL, 'Clear removes all duplicate description values without resurrecting a later delta');
  check_meta(SavedMetatag::tags($node) === $current, 'Clear retains canonical and social values across deltas');
  $node->fields['field_custom_seo']['items'] = [];
  check_meta(SavedMetatag::description($node) === ['available' => TRUE, 'value' => NULL], 'An empty final native field does not fall back to earlier fields');
  foreach (['metatag', 'string_long'] as $type) {
    $node = new MetaNode($type === 'metatag' ? json_encode($tags) : serialize($tags), $type, 'field_meta_tags');
    $current = $read->invoke($controller, $node);
    $current['description'] = 'Saved replacement';
    $write->invoke($controller, $node, $current);
    check_meta(SavedMetatag::decode($node->raw) === $current, 'The existing metadata writer preserves unrelated values on Save');
    unset($current['description']); $write->invoke($controller, $node, $current);
    check_meta(SavedMetatag::decode($node->raw) === $current && SavedMetatag::description($node)['value'] === NULL, 'The existing metadata writer preserves unrelated values on Clear');
    check_meta($type === 'metatag' ? is_array(json_decode($node->raw, TRUE)) : str_starts_with($node->raw, 'a:'), 'The writer uses the native or legacy field storage format');
  }
  $node = new MetaNode('{broken');
  try { $read->invoke($controller, $node); throw new \RuntimeException('Malformed read was accepted.'); }
  catch (\RuntimeException $error) { check_meta($node->raw === '{broken' && str_contains($error->getMessage(), 'could not be read'), 'A malformed field stops the writer without deleting saved data'); }
  $inventory = (new \ReflectionClass(\Drupal\ttd_topics\Service\AnalyticsInventoryService::class))->newInstanceWithoutConstructor();
  $ack = new \ReflectionMethod($inventory, 'confirmedWrite'); $ack->setAccessible(TRUE);
  check_meta($ack->invoke($inventory, ['ok' => TRUE, 'siteMatches' => TRUE, 'summary' => ['rowsAccepted' => 1, 'rowsWritten' => 1]], 1) === 1, 'A complete write acknowledgement is accepted');
  foreach ([['ok' => TRUE], ['ok' => TRUE, 'dryRun' => TRUE],
    ['ok' => TRUE, 'siteMatches' => TRUE, 'summary' => ['rowsAccepted' => 0, 'rowsWritten' => 0]],
    ['ok' => TRUE, 'siteMatches' => TRUE, 'summary' => ['rowsAccepted' => 1, 'rowsWritten' => 0]]] as $response) {
    try { $ack->invoke($inventory, $response, 1); throw new \RuntimeException('Unconfirmed write was accepted.'); }
    catch (\RuntimeException $error) { check_meta(str_contains($error->getMessage(), 'not confirmed'), 'Missing, dry-run, excluded and partial writes cannot advance Drupal catch-up'); }
  }
  echo "Saved Metatag and acknowledgement checks passed: {$checks} assertions.\n";
}
