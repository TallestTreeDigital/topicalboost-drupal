<?php

/**
 * Exercise the real schema formatter without a Drupal bootstrap.
 * An optional source path runs the same cases against a baseline generator.
 */

namespace Drupal\taxonomy {
  interface TermInterface {}
}

namespace {
  class Drupal {
    public static array $settings = [];

    public static function config($name) {
      return new class {
        public function get($key) {
          return \Drupal::$settings[$key] ?? ($key === 'disable_event_temporal_properties');
        }
      };
    }
  }

  class SchemaFixtureTerm implements \Drupal\taxonomy\TermInterface {
    public function __construct(public string $url) {}
  }

  function ttd_topics_get_topic_url($term, $absolute = FALSE) {
    return $term->url;
  }

  require $argv[1] ?? dirname(__DIR__, 2) . '/src/SchemaGenerator.php';
  $reflection = new \ReflectionClass(\Drupal\ttd_topics\SchemaGenerator::class);
  $generator = $reflection->newInstanceWithoutConstructor();
  $formatter = $reflection->getMethod('formatTopicsForSchema');
  $alias_entities = $reflection->getProperty('schemaAliasEntities');
  $alias_entities->setValue($generator, array_fill_keys(range(10, 30), []));
  $base = 'https://example.test';

  $cases = [
    'Unmapped visible topic' => [0, NULL, [], ['@id' => "$base/topics/1", '@type' => 'Thing', 'name' => 'Visible topic']],
    'Missing entity record' => [10, NULL, [], ['@id' => "$base/topics/1", '@type' => 'Thing']],
    'Missing external ID' => [11, ['name' => 'Database label'], [], ['@id' => "$base/topics/1", 'name' => 'Visible topic']],
    'Google identity keeps priority' => [12, ['mid' => '/m/fixture', 'wb_qid' => 'Q12'], ['Place'], ['@id' => 'kg:/m/fixture', '@type' => 'Place']],
    'Wikidata identity keeps priority' => [13, ['wb_qid' => 'Q13'], ['Person'], ['@id' => 'https://www.wikidata.org/wiki/Q13', '@type' => 'Person']],
    'Invalid Google ID falls back to Wikidata' => [14, ['mid' => 'invalid', 'wb_qid' => 'Q14'], [], ['@id' => 'https://www.wikidata.org/wiki/Q14']],
    'Invalid Google ID falls back to topic URL' => [15, ['mid' => 'invalid'], [], ['@id' => "$base/topics/1"]],
    'External identity retains its entity label' => [16, ['mid' => '/m/label', 'name' => 'Canonical label'], [], ['name' => 'Canonical label']],
    'Event demotion remains deduplicated' => [17, ['mid' => '/m/event'], ['Thing', 'Event'], ['@type' => 'Thing']],
    'Configured archive URL identifies an unmapped topic' => [0, NULL, [], ['@id' => "$base/research?topic=1"], "$base/research?topic=1"],
    'Hidden unmapped topic stays excluded' => [0, NULL, [], NULL, NULL, TRUE],
    'Hidden mapped topic stays excluded' => [18, ['mid' => '/m/hidden'], [], NULL, NULL, TRUE],
    'Invalid fallback URL stays excluded' => [0, NULL, [], NULL, 'javascript:invalid'],
  ];
  $failures = 0;
  foreach ($cases as $label => $case) {
    [$id, $entity, $types, $expected] = $case;
    Drupal::$settings['topic_url_mode'] = isset($case[4]) ? 'archive_query' : 'taxonomy_term';
    $topic = (object) [
      'tid' => 1,
      'term' => new SchemaFixtureTerm($case[4] ?? "$base/topics/1"),
      'name' => 'Visible topic',
      'field_ttd_id_value' => $id,
      'field_hide_value' => !empty($case[5]) ? 1 : 0,
      'alias' => '/topics/1',
    ];
    $items = $formatter->invoke($generator, [$topic], $base, $entity === NULL ? [] : [$id => $entity], [$id => $types]);
    $pass = $expected === NULL ? $items === [] : count($items) === 1;
    foreach ($expected ?? [] as $key => $value) {
      $pass = $pass && ($items[0][$key] ?? NULL) === $value;
    }
    echo ($pass ? 'PASS: ' : 'FAIL: ') . $label . "\n";
    $failures += $pass ? 0 : 1;
  }
  echo 'Cases: ' . count($cases) . "; failures: $failures\n";
  exit($failures === 0 ? 0 : 1);
}
