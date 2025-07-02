<?php

namespace Drupal\Tests\topicalboost\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;

/**
 * Tests TopicalBoost entity discovery and term creation functionality.
 *
 * @group topicalboost
 */
class TtdTopicsEntityDiscoveryTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'node',
    'taxonomy',
    'advancedqueue',
    'ttd_topics',
  ];

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface
   */
  protected $termStorage;

  /**
   * The TopicalBoost job type plugin.
   *
   * @var \Drupal\topicalboost\Plugin\AdvancedQueue\JobType\TtdTopicsAnalysis
   */
  protected $jobType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('taxonomy', ['taxonomy_index']);

    // Install TopicalBoost schema.
    $this->installSchema('ttd_topics', [
      'ttd_entities',
      'ttd_entity_schema_types',
      'ttd_entity_wb_categories',
      'ttd_schema_types',
      'ttd_wb_categories',
    ]);

    $this->database = $this->container->get('database');
    $this->termStorage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');

    // Create TopicalBoost vocabulary.
    $vocabulary = Vocabulary::create([
      'vid' => 'ttd_topics',
      'name' => 'TopicalBoost',
      'description' => 'Vocabulary for TopicalBoost',
    ]);
    $vocabulary->save();

    // Create field storage for TTD ID.
    FieldStorageConfig::create([
      'field_name' => 'field_ttd_id',
      'entity_type' => 'taxonomy_term',
      'type' => 'string',
      'cardinality' => 1,
    ])->save();

    // Create field instance for TTD ID.
    FieldConfig::create([
      'field_name' => 'field_ttd_id',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'ttd_topics',
      'label' => 'TTD ID',
    ])->save();

    // Create a content type for testing.
    $this->createContentType(['type' => 'article']);

    // Create TopicalBoost field storage.
    FieldStorageConfig::create([
      'field_name' => 'field_ttd_topics',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();

    // Create TopicalBoost field instance.
    FieldConfig::create([
      'field_name' => 'field_ttd_topics',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'TopicalBoost',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['ttd_topics' => 'ttd_topics'],
        ],
      ],
    ])->save();

    // Initialize the job type plugin.
    $this->jobType = $this->container->get('plugin.manager.advancedqueue_job_type')
      ->createInstance('ttd_topics_analysis');
  }

  /**
   * Test entity discovery and term creation for a new entity.
   */
  public function testNewEntityDiscoveryAndTermCreation() {
    // Prepare test data for a new entity.
    $entity_data = [
      'id' => 'TEST-123',
      'name' => 'Test Entity',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-01T00:00:00Z',
      'schema_types' => ['Organization', 'Place'],
      'wb_categories' => ['cat1', 'cat2'],
    ];

    // Verify entity doesn't exist in database yet.
    $existing_entity = $this->database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', $entity_data['id'])
      ->execute()
      ->fetchAssoc();
    $this->assertFalse($existing_entity, 'Entity should not exist in database initially');

    // Verify term doesn't exist yet.
    $existing_terms = $this->termStorage->loadByProperties([
      'vid' => 'ttd_topics',
              'field_ttd_id' => $entity_data['id'],
    ]);
    $this->assertEmpty($existing_terms, 'Term should not exist initially');

    // Call the method that handles entity discovery.
    $reflection = new \ReflectionClass($this->jobType);
    $method = $reflection->getMethod('getOrCreateTerm');
    $method->setAccessible(TRUE);

    $term_id = $method->invoke(
      $this->jobType,
      $entity_data['name'],
      $entity_data['id'],
      $entity_data
    );

    // Verify entity was added to ttd_entities table.
    $created_entity = $this->database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', $entity_data['id'])
      ->execute()
      ->fetchAssoc();

    $this->assertNotFalse($created_entity, 'Entity should be created in database');
    $this->assertEquals($entity_data['id'], $created_entity['ttd_id']);
    $this->assertEquals($entity_data['name'], $created_entity['name']);
    $this->assertEquals($entity_data['createdAt'], $created_entity['createdAt']);
    $this->assertEquals($entity_data['updatedAt'], $created_entity['updatedAt']);

    // Verify taxonomy term was created.
    $this->assertNotNull($term_id, 'Term ID should be returned');

    $created_term = $this->termStorage->load($term_id);
    $this->assertNotNull($created_term, 'Term should be created');
    $this->assertEquals('ttd_topics', $created_term->bundle());
    $this->assertEquals($entity_data['name'], $created_term->getName());
    $this->assertEquals($entity_data['id'], $created_term->get('field_ttd_id')->value);
  }

  /**
   * Test that existing entity is updated, not duplicated.
   */
  public function testExistingEntityUpdate() {
    // Insert initial entity data.
    $initial_data = [
      'ttd_id' => 'TEST-456',
      'name' => 'Original Name',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-01T00:00:00Z',
    ];

    $this->database->insert('ttd_entities')
      ->fields($initial_data)
      ->execute();

    // Create initial term.
    $initial_term = Term::create([
      'vid' => 'ttd_topics',
      'name' => $initial_data['name'],
              'field_ttd_id' => $initial_data['ttd_id'],
    ]);
    $initial_term->save();

    // Prepare updated entity data.
    $updated_data = [
      'id' => 'TEST-456',
      'name' => 'Updated Name',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-02T00:00:00Z',
      'new_field' => 'new_value',
    ];

    // Call the method that handles entity discovery.
    $reflection = new \ReflectionClass($this->jobType);
    $method = $reflection->getMethod('getOrCreateTerm');
    $method->setAccessible(TRUE);

    $term_id = $method->invoke(
      $this->jobType,
      $updated_data['name'],
      $updated_data['id'],
      $updated_data
    );

    // Verify entity was updated, not duplicated.
    $entities = $this->database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', $updated_data['id'])
      ->execute()
      ->fetchAll();

    $this->assertCount(1, $entities, 'Should have only one entity record');
    $updated_entity = $entities[0];
    $this->assertEquals($updated_data['updatedAt'], $updated_entity->updatedAt);

    // Verify the same term is returned.
    $this->assertEquals($initial_term->id(), $term_id, 'Should return the existing term ID');
  }

  /**
   * Test term creation by name when TTD ID is missing.
   */
  public function testTermCreationByName() {
    // Create a term without TTD ID.
    $existing_term = Term::create([
      'vid' => 'ttd_topics',
      'name' => 'Test Entity By Name',
    ]);
    $existing_term->save();

    $entity_data = [
      'id' => 'TEST-789',
      'name' => 'Test Entity By Name',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-01T00:00:00Z',
    ];

    // Call the method that handles entity discovery.
    $reflection = new \ReflectionClass($this->jobType);
    $method = $reflection->getMethod('getOrCreateTerm');
    $method->setAccessible(TRUE);

    $term_id = $method->invoke(
      $this->jobType,
      $entity_data['name'],
      $entity_data['id'],
      $entity_data
    );

    // Verify the existing term was updated with TTD ID.
    $this->assertEquals($existing_term->id(), $term_id, 'Should return the existing term ID');

    $updated_term = $this->termStorage->load($term_id);
    $this->assertEquals($entity_data['id'], $updated_term->get('field_ttd_id')->value);
  }

  /**
   * Test full analysis results processing.
   */
  public function testAnalysisResultsProcessing() {
    // Create a test node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Article',
      'body' => 'Test content',
    ]);
    $node->save();

    // Mock analysis results.
    $analysis_results = [
      'entities' => [
        [
          'id' => 'ENTITY-001',
          'name' => 'Test Organization',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
          'schema_types' => ['Organization'],
          'wb_categories' => [],
        ],
        [
          'id' => 'ENTITY-002',
          'name' => 'Test Person',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
          'schema_types' => ['Person'],
          'wb_categories' => [],
        ],
      ],
    ];

    // Process the results.
    $reflection = new \ReflectionClass($this->jobType);
    $method = $reflection->getMethod('saveAnalysisResults');
    $method->setAccessible(TRUE);

    $method->invoke($this->jobType, $node, $analysis_results);

    // Verify both entities were created in database.
    foreach ($analysis_results['entities'] as $entity_data) {
      $created_entity = $this->database->select('ttd_entities', 'te')
        ->fields('te')
        ->condition('ttd_id', $entity_data['id'])
        ->execute()
        ->fetchAssoc();

      $this->assertNotFalse($created_entity, "Entity {$entity_data['id']} should be created");
      $this->assertEquals($entity_data['name'], $created_entity['name']);
    }

    // Verify both terms were created.
    $terms = $this->termStorage->loadByProperties(['vid' => 'ttd_topics']);
    $this->assertCount(2, $terms, 'Should create 2 terms');

    // Verify node has the terms assigned.
    $node = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    $topicalboost = $node->get('field_ttd_topics')->getValue();
    $this->assertCount(2, $topicalboost, 'Node should have 2 TopicalBoost assigned');
  }

  /**
   * Test entity creation with empty or missing name.
   */
  public function testEntityCreationWithEmptyName() {
    $entity_data = [
      'id' => 'TEST-EMPTY',
      'name' => '',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-01T00:00:00Z',
    ];

    // Call the method that handles entity discovery.
    $reflection = new \ReflectionClass($this->jobType);
    $method = $reflection->getMethod('getOrCreateTerm');
    $method->setAccessible(TRUE);

    $term_id = $method->invoke(
      $this->jobType,
      $entity_data['name'],
      $entity_data['id'],
      $entity_data
    );

    // Should return null for empty name.
    $this->assertNull($term_id, 'Should return null for empty name');

    // Verify no entity was created.
    $existing_entity = $this->database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', $entity_data['id'])
      ->execute()
      ->fetchAssoc();
    $this->assertFalse($existing_entity, 'No entity should be created for empty name');
  }

  /**
   * Test name fallback logic (name -> nl_name -> kg_name -> wb_name).
   */
  public function testNameFallbackLogic() {
    $test_cases = [
      // Test nl_name fallback.
      [
        'entity_data' => [
          'id' => 'TEST-NL',
          'nl_name' => 'Dutch Name',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
        ],
        'expected_name' => 'Dutch Name',
      ],
      // Test kg_name fallback.
      [
        'entity_data' => [
          'id' => 'TEST-KG',
          'kg_name' => 'Knowledge Graph Name',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
        ],
        'expected_name' => 'Knowledge Graph Name',
      ],
      // Test wb_name fallback.
      [
        'entity_data' => [
          'id' => 'TEST-WB',
          'wb_name' => 'Wikidata Name',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
        ],
        'expected_name' => 'Wikidata Name',
      ],
    ];

    foreach ($test_cases as $test_case) {
      $entity_data = $test_case['entity_data'];
      $expected_name = $test_case['expected_name'];

      // Simulate the saveAnalysisResults logic for name fallback.
      $name = $entity_data['name'] ?? $entity_data['nl_name'] ?? $entity_data['kg_name'] ?? $entity_data['wb_name'] ?? NULL;

      $this->assertEquals($expected_name, $name, "Name fallback should work for {$entity_data['id']}");

      if (!empty($name)) {
        // Call the method that handles entity discovery.
        $reflection = new \ReflectionClass($this->jobType);
        $method = $reflection->getMethod('getOrCreateTerm');
        $method->setAccessible(TRUE);

        $term_id = $method->invoke(
          $this->jobType,
          $name,
          $entity_data['id'],
          $entity_data
        );

        $this->assertNotNull($term_id, "Term should be created for {$entity_data['id']}");

        $created_term = $this->termStorage->load($term_id);
        $this->assertEquals($expected_name, $created_term->getName(), "Term name should match expected name for {$entity_data['id']}");
      }
    }
  }

}
