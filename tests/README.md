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