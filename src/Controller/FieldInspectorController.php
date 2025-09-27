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
        $field_type = $field_definition->getType();
        $enhanced_label = $field_definition->getLabel();

        // For paragraph fields, show subfield count
        if ($field_type === 'entity_reference_revisions') {
          $subfield_count = $this->countParagraphSubfields($node_entity, $field_name);
          if ($subfield_count > 0) {
            $enhanced_label .= ' (' . $subfield_count . ' subfield' . ($subfield_count !== 1 ? 's' : '') . ')';
          }

          // Debug logging
          \Drupal::logger('ttd_topics')->info('Field @field_name: type=@type, subfield_count=@count', [
            '@field_name' => $field_name,
            '@type' => $field_type,
            '@count' => $subfield_count,
          ]);
        }

        $fields_data[] = [
          'machine_name' => $field_name,
          'label' => $enhanced_label,
          'sample_value' => $sample_value,
          'type' => $field_type,
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

      case 'entity_reference_revisions':
        // Handle paragraph fields - show all paragraphs, not just first
        $all_paragraph_texts = [];
        $total_paragraphs = $field->count();

        foreach ($field as $delta => $item) {
          if (isset($item->target_id) && $item->entity) {
            $paragraph = $item->entity;
            $paragraph_bundle = $paragraph->bundle();

            // Get sample text from paragraph fields
            $paragraph_texts = [];
            $paragraph_field_definitions = $paragraph->getFieldDefinitions();

            foreach ($paragraph_field_definitions as $para_field_name => $para_field_definition) {
              $is_base_field = method_exists($para_field_definition, 'isBaseField') ? $para_field_definition->isBaseField() : false;
              if (!$is_base_field && strpos($para_field_name, 'field_') === 0) {
                if ($paragraph->hasField($para_field_name) && !$paragraph->get($para_field_name)->isEmpty()) {
                  $para_field_type = $para_field_definition->getType();

                  if (in_array($para_field_type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary'])) {
                    $para_first_item = $paragraph->get($para_field_name)->first();
                    if ($para_first_item && isset($para_first_item->value)) {
                      $para_text = strip_tags($para_first_item->value);
                      if ($para_text) {
                        $paragraph_texts[] = $para_text;
                      }
                    }
                  }
                }
              }
            }

            if (!empty($paragraph_texts)) {
              $all_paragraph_texts[] = implode(', ', $paragraph_texts);
            }
          }
        }

        if (!empty($all_paragraph_texts)) {
          if (count($all_paragraph_texts) > 3) {
            // Show first 3 and indicate there are more
            $shown = array_slice($all_paragraph_texts, 0, 3);
            $sample_text = implode(' | ', $shown) . ' | +' . (count($all_paragraph_texts) - 3) . ' more';
          } else {
            $sample_text = implode(' | ', $all_paragraph_texts);
          }
        } else {
          $sample_text = $total_paragraphs . ' paragraph(s)';
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

  /**
   * Count text-compatible subfields in paragraph fields.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node entity.
   * @param string $field_name
   *   The paragraph field name.
   *
   * @return int
   *   Number of text-compatible subfields.
   */
  protected function countParagraphSubfields(NodeInterface $node, string $field_name): int {
    $subfield_count = 0;

    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return 0;
    }

    // Get unique paragraph bundles used in this field
    $paragraph_bundles = [];
    foreach ($node->get($field_name) as $item) {
      if ($item->entity) {
        $bundle = $item->entity->bundle();
        $paragraph_bundles[$bundle] = $bundle;
      }
    }

    // Count text-compatible fields across all paragraph bundles
    foreach ($paragraph_bundles as $bundle) {
      $compatible_fields = $this->fieldCollector->getTextCompatibleFields('paragraph', $bundle);

      foreach ($compatible_fields as $para_field_name => $para_field_definition) {
        // Skip base fields and only count custom fields
        $is_base_field = method_exists($para_field_definition, 'isBaseField') ? $para_field_definition->isBaseField() : false;
        if (!$is_base_field && strpos($para_field_name, 'field_') === 0) {
          $subfield_count++;
        }
      }
    }

    return $subfield_count;
  }

  /**
   * Search nodes by title within enabled content types.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing matching nodes.
   */
  public function searchNodesByTitle(): JsonResponse {
    $query = \Drupal::request()->query->get('q', '');

    // Require minimum 2 characters for search
    if (strlen(trim($query)) < 2) {
      return new JsonResponse(['nodes' => []]);
    }

    $config = $this->config('ttd_topics.settings');
    $enabled_content_types = array_filter($config->get('enabled_content_types') ?: []);

    if (empty($enabled_content_types)) {
      return new JsonResponse(['nodes' => []]);
    }

    try {
      // Search nodes by title
      $node_query = $this->entityTypeManager()->getStorage('node')->getQuery()
        ->condition('type', $enabled_content_types, 'IN')
        ->condition('status', 1)
        ->condition('title', '%' . \Drupal::database()->escapeLike(trim($query)) . '%', 'LIKE')
        ->sort('changed', 'DESC')
        ->range(0, 15) // Limit to 15 results for performance
        ->accessCheck(TRUE);

      $node_ids = $node_query->execute();

      if (empty($node_ids)) {
        return new JsonResponse(['nodes' => []]);
      }

      $nodes = $this->entityTypeManager()->getStorage('node')->loadMultiple($node_ids);

      $search_results = [];
      foreach ($nodes as $node) {
        // Only include nodes with text-compatible fields
        $compatible_fields = $this->fieldCollector->getTextCompatibleFields('node', $node->bundle());
        $has_eligible_fields = FALSE;

        foreach ($compatible_fields as $field_name => $field_definition) {
          if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
            $has_eligible_fields = TRUE;
            break;
          }
        }

        if ($has_eligible_fields) {
          $search_results[] = [
            'nid' => $node->id(),
            'title' => $node->getTitle(),
            'type' => $node->bundle(),
            'type_label' => $node->type->entity->label(),
            'changed' => $node->getChangedTime(),
            'changed_formatted' => \Drupal::service('date.formatter')->format($node->getChangedTime(), 'custom', 'M j, Y'),
          ];
        }
      }

      return new JsonResponse([
        'nodes' => $search_results,
        'query' => $query,
        'total' => count($search_results),
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('ttd_topics')->error('Error searching nodes by title: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'nodes' => [],
        'error' => 'An error occurred while searching.',
      ], 500);
    }
  }
} 