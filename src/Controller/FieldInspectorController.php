<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\ttd_topics\Service\FieldCollectorService;

/**
 * Controller for field inspector functionality.
 */
class FieldInspectorController extends ControllerBase {

  /**
   * The field collector service.
   */
  protected FieldCollectorService $fieldCollector;

  /**
   * Constructs a FieldInspectorController object.
   */
  public function __construct(FieldCollectorService $field_collector) {
    $this->fieldCollector = $field_collector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ttd_topics.field_collector')
    );
  }

  /**
   * Inspect fields for a given node.
   *
   * @param int $node
   *   The node ID to inspect.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing field information.
   */
  public function inspectFields(int $node): JsonResponse {
    // Load the node
    $node_entity = $this->entityTypeManager()->getStorage('node')->load($node);

    if (!$node_entity || !($node_entity instanceof NodeInterface)) {
      throw new NotFoundHttpException('Node not found');
    }

    // Check if user can view this node
    if (!$node_entity->access('view')) {
      throw new AccessDeniedHttpException('Access denied to this node');
    }

    // Get all text-compatible fields for this content type
    $compatible_fields = $this->fieldCollector->getTextCompatibleFields('node', $node_entity->bundle());
    
    $fields_data = [];

    foreach ($compatible_fields as $field_name => $field_definition) {
      if ($node_entity->hasField($field_name) && !$node_entity->get($field_name)->isEmpty()) {
        $sample_value = $this->getSampleFieldValue($node_entity, $field_name);
        
        $fields_data[] = [
          'machine_name' => $field_name,
          'label' => $field_definition->getLabel(),
          'sample_value' => $sample_value,
          'type' => $field_definition->getType(),
        ];
      }
    }

    // Sort by label for better UX
    usort($fields_data, function($a, $b) {
      return strcmp($a['label'], $b['label']);
    });

    return new JsonResponse([
      'node_id' => $node,
      'node_title' => $node_entity->getTitle(),
      'content_type' => $node_entity->bundle(),
      'fields' => $fields_data,
    ]);
  }

  /**
   * Get a sample value from a field for preview.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   A sample value for display.
   */
  protected function getSampleFieldValue(NodeInterface $node, string $field_name): string {
    $field = $node->get($field_name);
    $field_definition = $field->getFieldDefinition();
    $field_type = $field_definition->getType();

    // Get the first item for sampling
    $first_item = $field->first();
    if (!$first_item) {
      return '';
    }

    $sample_text = '';

    switch ($field_type) {
      case 'string':
      case 'string_long':
        if (isset($first_item->value)) {
          $sample_text = strip_tags($first_item->value);
        }
        break;

      case 'text':
      case 'text_long':
      case 'text_with_summary':
        if (isset($first_item->value)) {
          // Apply text format processing if format is specified
          if (isset($first_item->format) && $first_item->format) {
            $build = [
              '#type' => 'processed_text',
              '#text' => $first_item->value,
              '#format' => $first_item->format,
            ];
            $rendered = \Drupal::service('renderer')->renderPlain($build);
            $sample_text = strip_tags($rendered);
          } else {
            $sample_text = strip_tags($first_item->value);
          }
        }
        break;

      case 'list_string':
        if (isset($first_item->value)) {
          $sample_text = strip_tags($first_item->value);
        }
        break;

      default:
        // For other field types, try to get a string representation
        if (method_exists($first_item, '__toString')) {
          $sample_text = strip_tags((string) $first_item);
        }
        break;
    }

    // Truncate long text for preview
    if (strlen($sample_text) > 100) {
      $sample_text = substr($sample_text, 0, 100) . '...';
    }

    return trim($sample_text);
  }

  /**
   * Get recent nodes with custom fields for the inspector.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing recent nodes.
   */
  public function getRecentNodes(): JsonResponse {
    $config = $this->config('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);

    if (empty($enabled_content_types)) {
      return new JsonResponse(['nodes' => []]);
    }

    // Get the 10 most recently updated nodes with eligible fields
    $query = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->condition('type', $enabled_content_types, 'IN')
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, 10)
      ->accessCheck(TRUE);

    $node_ids = $query->execute();
    $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($node_ids);

    $recent_nodes = [];
    foreach ($nodes as $node) {
      // Check if this node has any text-compatible fields
      $compatible_fields = $this->fieldCollector->getTextCompatibleFields('node', $node->bundle());
      $has_eligible_fields = FALSE;

      foreach ($compatible_fields as $field_name => $field_definition) {
        if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
          $has_eligible_fields = TRUE;
          break;
        }
      }

      if ($has_eligible_fields) {
        $recent_nodes[] = [
          'nid' => $node->id(),
          'title' => $node->getTitle(),
          'type' => $node->bundle(),
          'changed' => $node->getChangedTime(),
        ];
      }
    }

    return new JsonResponse(['nodes' => $recent_nodes]);
  }
} 