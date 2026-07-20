<?php

/**
 * Regression checks for Event-to-Thing schema type deduplication.
 *
 * Run from the module root:
 *   php tests/cli/test-event-schema-dedup.php
 */

$root = dirname(__DIR__, 2);
$schema_generator_file = $root . '/src/SchemaGenerator.php';
require_once $schema_generator_file;

function ttd_event_schema_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }

  echo "PASS: {$message}\n";
}

$reflection = new ReflectionClass(\Drupal\ttd_topics\SchemaGenerator::class);
$generator = $reflection->newInstanceWithoutConstructor();
$replace_event = $reflection->getMethod('replaceEventSchemaType');

$types = $replace_event->invoke($generator, ['Thing', 'Event']);
ttd_event_schema_assert($types === ['Thing'], 'Thing and Event collapse to one Thing type');

$mixed_types = $replace_event->invoke($generator, ['Organization', 'Event', 'Thing', 'Event']);
ttd_event_schema_assert($mixed_types === ['Organization', 'Thing'], 'Other schema types are preserved in order');
ttd_event_schema_assert(!in_array('Event', $mixed_types, TRUE), 'Event is removed from fallback schema output');

$schema_generator_source = file_get_contents($schema_generator_file);
ttd_event_schema_assert(
  substr_count($schema_generator_source, '$this->replaceEventSchemaType($schema_types)') === 2,
  'Article topics and topic archives both use the deduplicating fallback',
);

echo "Event schema deduplication regression checks passed.\n";
