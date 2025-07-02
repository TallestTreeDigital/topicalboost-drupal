<?php

namespace Drupal\Tests\topicalboost\Functional;

use Drupal\advancedqueue\Entity\Queue;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;

/**
 * Functional tests for TopicalBoost entity discovery and term creation.
 *
 * @group topicalboost
 */
class TtdTopicsEntityDiscoveryFunctionalTest extends BrowserTestBase {

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
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->container->get('database');

    // Install TopicalBoost module setup.
    topicalboost_install();

    // Create a content type for testing.
    $this->drupalCreateContentType(['type' => 'article', 'name' => 'Article']);

    // Create a user with admin permissions.
    $admin_user = $this->drupalCreateUser([
      'administer taxonomy',
      'administer nodes',
      'create article content',
      'edit any article content',
    ]);
    $this->drupalLogin($admin_user);
  }

  /**
   * Test the complete workflow from analysis to entity creation.
   */
  public function testCompleteEntityDiscoveryWorkflow() {
    // Create a test node.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test Article for Entity Discovery',
      'body' => 'This article mentions organizations and people for testing.',
      'status' => 1,
    ]);
    $node->save();

    // Manually simulate what the analysis API would return.
    $mock_analysis_results = [
      'entities' => [
        [
          'id' => 'FUNC-TEST-001',
          'name' => 'Functional Test Organization',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
          'schema_types' => ['Organization'],
          'wb_categories' => ['education'],
        ],
        [
          'id' => 'FUNC-TEST-002',
          'name' => 'Functional Test Person',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-01T00:00:00Z',
          'schema_types' => ['Person'],
          'wb_categories' => [],
        ],
      ],
    ];

    // Get the job type plugin and process the results directly.
    $job_type = $this->container->get('plugin.manager.advancedqueue_job_type')
      ->createInstance('ttd_topics_analysis');

    $reflection = new \ReflectionClass($job_type);
    $method = $reflection->getMethod('saveAnalysisResults');
    $method->setAccessible(TRUE);

    $method->invoke($job_type, $node, $mock_analysis_results);

    // Verify entities were created in ttd_entities table.
    foreach ($mock_analysis_results['entities'] as $entity_data) {
      $created_entity = $this->database->select('ttd_entities', 'te')
        ->fields('te')
        ->condition('ttd_id', $entity_data['id'])
        ->execute()
        ->fetchAssoc();

      $this->assertNotFalse($created_entity, "Entity {$entity_data['id']} should be created in database");
      $this->assertEquals($entity_data['name'], $created_entity['name']);
    }

    // Verify taxonomy terms were created.
    $term_storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $terms = $term_storage->loadByProperties(['vid' => 'ttd_topics']);
    $this->assertGreaterThanOrEqual(2, count($terms), 'At least 2 terms should be created');

    // Verify node has the terms assigned.
    $updated_node = Node::load($node->id());
    $topicalboost = $updated_node->get('field_ttd_topics')->getValue();
    $this->assertCount(2, $topicalboost, 'Node should have 2 TopicalBoost assigned');

    // Test the taxonomy term pages are accessible.
    foreach ($terms as $term) {
      $this->drupalGet('/taxonomy/term/' . $term->id());
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->pageTextContains($term->getName());
    }
  }

  /**
   * Test entity update scenario.
   */
  public function testEntityUpdateScenario() {
    // First, insert an entity manually.
    $initial_data = [
      'ttd_id' => 'FUNC-UPDATE-001',
      'name' => 'Original Entity Name',
      'createdAt' => '2024-01-01T00:00:00Z',
      'updatedAt' => '2024-01-01T00:00:00Z',
    ];

    $this->database->insert('ttd_entities')
      ->fields($initial_data)
      ->execute();

    // Create a term for this entity.
    $term_storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    $initial_term = $term_storage->create([
      'vid' => 'ttd_topics',
      'name' => $initial_data['name'],
              'field_ttd_id' => $initial_data['ttd_id'],
    ]);
    $initial_term->save();

    // Now simulate an analysis that returns updated data.
    $node = Node::create([
      'type' => 'article',
      'title' => 'Article for Update Test',
      'body' => 'Testing entity updates.',
      'status' => 1,
    ]);
    $node->save();

    $updated_analysis_results = [
      'entities' => [
        [
          'id' => 'FUNC-UPDATE-001',
          'name' => 'Updated Entity Name',
          'createdAt' => '2024-01-01T00:00:00Z',
          'updatedAt' => '2024-01-02T00:00:00Z',
          'description' => 'This is a new description',
        ],
      ],
    ];

    // Process the updated results.
    $job_type = $this->container->get('plugin.manager.advancedqueue_job_type')
      ->createInstance('ttd_topics_analysis');

    $reflection = new \ReflectionClass($job_type);
    $method = $reflection->getMethod('saveAnalysisResults');
    $method->setAccessible(TRUE);

    $method->invoke($job_type, $node, $updated_analysis_results);

    // Verify entity was updated, not duplicated.
    $entities = $this->database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', 'FUNC-UPDATE-001')
      ->execute()
      ->fetchAll();

    $this->assertCount(1, $entities, 'Should have only one entity record');
    $updated_entity = $entities[0];
    $this->assertEquals('2024-01-02T00:00:00Z', $updated_entity->updatedAt);

    // Verify the term still exists and references the same entity.
    $updated_term = $term_storage->load($initial_term->id());
    $this->assertNotNull($updated_term, 'Original term should still exist');
    $this->assertEquals('FUNC-UPDATE-001', $updated_term->get('field_ttd_id')->value);
  }

  /**
   * Test queue processing integration.
   */
  public function testQueueProcessingIntegration() {
    // Verify the queue exists.
    $queue = Queue::load('ttd_topics_analysis');
    $this->assertNotNull($queue, 'TopicalBoost analysis queue should exist');

    // The actual queue processing would require mocking the external API
    // For this test, we verify the queue configuration.
    $this->assertEquals('database', $queue->getBackend()->getPluginId());
    $this->assertEquals('cron', $queue->getProcessor());
  }

  /**
   * Test error handling for malformed entity data.
   */
  public function testErrorHandlingForMalformedData() {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Error Handling Test',
      'body' => 'Testing error scenarios.',
      'status' => 1,
    ]);
    $node->save();

    // Test with malformed analysis results.
    $malformed_analysis_results = [
      'entities' => [
        [
    // Invalid ID.
          'id' => NULL,
          'name' => 'Test Entity',
        ],
        [
          'id' => 'VALID-ID',
        // Empty name.
          'name' => '',
        ],
        [
          // Missing ID and name.
        ],
      ],
    ];

    $job_type = $this->container->get('plugin.manager.advancedqueue_job_type')
      ->createInstance('ttd_topics_analysis');

    $reflection = new \ReflectionClass($job_type);
    $method = $reflection->getMethod('saveAnalysisResults');
    $method->setAccessible(TRUE);

    // This should not throw an exception, but handle errors gracefully.
    $method->invoke($job_type, $node, $malformed_analysis_results);

    // Verify no invalid entities were created.
    $invalid_entities = $this->database->select('ttd_entities', 'te')
      ->fields('te')
      ->condition('ttd_id', [NULL, ''], 'IN')
      ->execute()
      ->fetchAll();

    $this->assertEmpty($invalid_entities, 'No invalid entities should be created');

    // Verify the node wasn't assigned any invalid terms.
    $updated_node = Node::load($node->id());
    $topicalboost = $updated_node->get('field_ttd_topics')->getValue();
    $this->assertEmpty($topicalboost, 'Node should have no TopicalBoost assigned for malformed data');
  }

}
