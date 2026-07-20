# TopicalBoost Module Tests

This directory contains tests for the TopicalBoost module that verify entity discovery and taxonomy term creation functionality.

## Test Structure

### Kernel Tests
- **`TtdTopicsEntityDiscoveryTest`**: Tests the core functionality of entity discovery and term creation at the unit level.

### Functional Tests  
- **`TtdTopicsEntityDiscoveryFunctionalTest`**: Tests the complete workflow including queue processing and web interface.

## What the Tests Cover

The tests ensure that when new entities are discovered during analysis:

1. **Entity Storage**: New entities are correctly added to the `ttd_entities` table
2. **Term Creation**: Corresponding taxonomy terms are created in the `topicalboost` vocabulary
3. **Entity Updates**: Existing entities are updated rather than duplicated
4. **Name Fallback**: The name fallback logic works correctly (name → nl_name → kg_name → wb_name)
5. **Error Handling**: Malformed data is handled gracefully without creating invalid records
6. **Node Assignment**: Discovered entities are properly assigned to the content being analyzed

## Running the Tests

### CLI Parity Tests

These mirror the high-value WordPress CLI tests without calling the API. They
create temporary Drupal content/topics, verify behavior, and clean up after
themselves.

```bash
# From the Drupal site root.
ddev exec drush scr web/modules/custom/topicalboost/tests/cli/test-parity-core.php
ddev exec drush scr web/modules/custom/topicalboost/tests/cli/test-parity-wp-equivalents.php
ddev exec drush scr web/modules/custom/topicalboost/tests/cli/test-parity-performance.php

# From the module root, no Drupal bootstrap required.
php tests/cli/test-sync-cursor-upgrade.php
php tests/cli/test-hidden-backfill-parity.php
php tests/cli/test-event-schema-dedup.php
php tests/cli/test-topic-archive-links.php
php tests/cli/test-topic-archive-managed-filter.php
```

`test-parity-core.php` covers:

1. Required Drupal fields for topic references, manual topics, rejected topics, tier overrides, hide, and force-show.
2. Frontend topic filtering parity with WordPress: display threshold, manual-topic bypass, force-show bypass, rejected-topic exclusion, and hidden-topic exclusion.
3. Schema parity for visible topics: `mainEntity`, `about`, `mentions`, manual topics, rejected topics, hidden topics, computed tiers, and custom schema image ratios.
4. WordPress-compatible tier computation from salience-only rows and LLM tier rows, including one `mainEntity`, max four `about` topics, overrides, mixed old/new API rows, and overflow demotion to `mentions`.
5. Backward-compatible salience-only rows where the API did not provide a stored tier, plus stale override cleanup when fresh LLM tiers arrive.
6. Below-threshold edge cases: overrides to `mentions`, dragging topics to `about`, rejected topics staying hidden, and count-threshold visibility after node deletes.
7. Editor controller parity for auto-unrejecting a topic when promoted to `mainEntity` or `about`, while keeping `mentions` rejected.
8. SEO meta content preview cleanup and the WordPress 5000-character preview limit.
9. Demand metric parity: traffic potential is stored and rendered for editor badges from single-analysis and bulk-analysis paths.
10. Entity metadata allowlist parity so known API fields are stored and unknown API fields are ignored.

`test-parity-wp-equivalents.php` maps the remaining WordPress CLI contracts to
Drupal runtime behavior:

1. Single-analysis parity: manual topics survive reanalysis, stale API topics are removed, promoted manual topics become API-managed, and the analysis-complete event fires.
2. Bulk-analysis parity: bulk initiation paginates queue jobs by configured batch size, bulk result application preserves manual topics, removes stale API topics, and is idempotent.
3. Sync parity: sync starts with the same topic/relationship page math, preserves manual topics, applies exact manual-plus-API relationships, and skips deleted content safely.
4. Content cleanup parity: analysis text filtering preserves paragraph/list boundaries, decodes entities, strips shortcodes, and avoids leaking field machine names into collected text.
5. Meta-preview parity: generated preview content strips HTML, shortcodes, image captions, image credits, excess whitespace, and caps at the WordPress 5000-character limit.
6. Access parity: the configured Drupal permission gates widget access the same way the WordPress role test gates SearchClippings/Citations widgets.
7. Schema parity: schema output uses `https://schema.org`, keeps per-node `mainEntity` isolation across batches, and generates WordPress-equivalent 16:9, 4:3, and 1:1 featured image ratios.

The WordPress `vip-scan.php` is a WordPress-specific static compatibility scan;
the Drupal equivalent gate is PHP syntax/static loading of the touched Drupal
files plus the runtime parity scripts above.

`test-sync-cursor-upgrade.php` statically guards the cursor-only sync upgrade:
new sync jobs must include `after_id`, legacy jobs missing `after_id` must cancel
without retrying, and the update hook must clear only non-processing
TopicalBoost sync pull jobs.

`test-event-schema-dedup.php` verifies that the Event-to-Thing fallback emits
one `Thing` type while preserving any other schema types in their original order.

`test-topic-archive-links.php` guards Search API/archive topic links: taxonomy
fallbacks, internal and absolute archive URLs, existing query strings and
fragments, Facets-style nested query parameters, value prefixes, slug values,
and missing-value fallback behavior.

`test-topic-archive-managed-filter.php` guards the optional one-click Search
API setup: archive View detection, scoped index field creation and reindexing,
hidden query filtering, cache variation, permissions, and invalid URL values.

`test-parity-performance.php` maps the WordPress performance/query-count tests
to Drupal schema and topic hot paths:

1. Large-post consistency: repeated schema generations for a 120-topic node return identical, non-empty topic sets.
2. Topic quality: every visible topic has entity data and the schema includes at least five schema.org entity types.
3. Performance budgets: cold and warm schema/topic generation stay within the WordPress cold/warm budgets.
4. Regression guard: entity and schema-type lookups are batched instead of one query per topic.
5. Query ceiling: TopicalBoost schema hot-path queries and warm total schema queries stay under the WordPress regression ceiling.
6. Index checks: the Drupal entity and schema relation tables have indexes used by the schema hot path.

### Using DDEV

```bash
# Run all TopicalBoost tests
ddev exec vendor/bin/phpunit docroot/modules/custom/topicalboost/tests/

# Run only kernel tests
ddev exec vendor/bin/phpunit docroot/modules/custom/topicalboost/tests/src/Kernel/

# Run only functional tests  
ddev exec vendor/bin/phpunit docroot/modules/custom/topicalboost/tests/src/Functional/

# Run a specific test
ddev exec vendor/bin/phpunit docroot/modules/custom/topicalboost/tests/src/Kernel/TtdTopicsEntityDiscoveryTest.php
```

### Using Drupal Core PHPUnit

```bash
# From the Drupal root directory
vendor/bin/phpunit modules/custom/topicalboost/tests/ --group topicalboost
```

### Using the Module's PHPUnit Configuration

```bash
# From the module directory
vendor/bin/phpunit -c phpunit.xml
```

## Test Environment Setup

The tests require:

- Drupal core test dependencies
- The `advancedqueue` module
- Database tables created by the TopicalBoost module
- Proper field configurations for the `topicalboost` vocabulary

The test setup automatically handles:
- Creating the required vocabulary and fields
- Installing necessary database schemas
- Setting up test content types and permissions

## Key Test Methods

### `testNewEntityDiscoveryAndTermCreation()`
Tests the primary workflow:
1. Verifies entity doesn't exist initially
2. Processes mock analysis results
3. Confirms entity is added to database
4. Confirms taxonomy term is created
5. Verifies proper field values and relationships

### `testExistingEntityUpdate()`
Tests update scenarios:
1. Creates initial entity and term
2. Processes updated analysis data
3. Confirms entity is updated, not duplicated
4. Verifies term relationships remain intact

### `testNameFallbackLogic()`
Tests all name fallback scenarios:
1. Tests nl_name fallback
2. Tests kg_name fallback  
3. Tests wb_name fallback
4. Verifies correct name is used for term creation

### `testErrorHandlingForMalformedData()`
Tests error scenarios:
1. Tests handling of null/empty IDs
2. Tests handling of empty names
3. Tests handling of missing required fields
4. Verifies no invalid data is persisted

## Debugging Tests

To debug test failures:

1. **Check Database State**: Tests can fail if the database schema doesn't match expectations
2. **Verify Module Dependencies**: Ensure all required modules are installed
3. **Review Error Logs**: Check Drupal logs for detailed error messages
4. **Use Debug Output**: Add `var_dump()` or `print_r()` in test methods for debugging

## Extending the Tests

When adding new functionality to the TopicalBoost module:

1. Add corresponding test methods to cover new features
2. Update existing tests if behavior changes
3. Ensure both kernel and functional test coverage
4. Add edge case testing for error conditions
5. Update this README if new test files are added

## Test Data

The tests use mock data that simulates what the TopicalBoost API would return:

```php
$mock_analysis_results = [
  'entities' => [
    [
      'id' => 'TEST-123',
      'name' => 'Test Entity',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-01T00:00:00Z',
      'schema_types' => ['Organization'],
      'wb_categories' => ['education'],
    ],
  ],
];
```

This allows testing without requiring an active connection to the external API service.
