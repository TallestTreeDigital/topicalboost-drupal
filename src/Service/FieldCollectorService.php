<?php

namespace Drupal\ttd_topics\Service;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Service for collecting custom field content for analysis.
 */
class FieldCollectorService {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The maximum text length to avoid API limits.
   */
  const MAX_TEXT_LENGTH = 15000;

  /**
   * The maximum recursion depth for entity references.
   */
  const MAX_RECURSION_DEPTH = 3;

  /**
   * The maximum number of entities to process per field.
   */
  const MAX_ENTITIES_PER_FIELD = 50;

  /**
   * Constructs a FieldCollectorService object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Collect text content from a node for analysis.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node to collect content from.
   *
   * @return string
   *   The concatenated text content including title, body, and custom fields.
   */
  public function collect(NodeInterface $node): string {
    $config = $this->configFactory->get('ttd_topics.settings');
    $custom_fields = $config->get('analysis_custom_fields') ?: [];
    
    $content_parts = [];
    $current_length = 0;

    // Always include title
    if ($node->getTitle()) {
      $title_text = strip_tags($node->getTitle());
      $content_parts[] = $title_text;
      $current_length += strlen($title_text);
    }

    // Always include body if it exists
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body_text = $this->extractFieldText($node->get('body'));
      if ($body_text && $current_length + strlen($body_text) < self::MAX_TEXT_LENGTH) {
        $content_parts[] = $body_text;
        $current_length += strlen($body_text);
      }
    }

    // Process custom fields
    foreach ($custom_fields as $field_name) {
      if ($current_length >= self::MAX_TEXT_LENGTH) {
        break;
      }

      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $field_text = $this->extractFieldText($node->get($field_name), [], 0);
        if ($field_text && $current_length + strlen($field_text) < self::MAX_TEXT_LENGTH) {
          $content_parts[] = $field_text;
          $current_length += strlen($field_text);
        }
      }
    }

    return implode("\n\n", $content_parts);
  }

  /**
   * Extract text from a field, handling various field types and nested entities.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   The field to extract text from.
   * @param array $processed_entities
   *   Array to track processed entities to prevent infinite loops.
   * @param int $depth
   *   Current recursion depth.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractFieldText(FieldItemListInterface $field, array $processed_entities = [], int $depth = 0): string {
    if ($depth >= self::MAX_RECURSION_DEPTH) {
      return '';
    }

    // Check field access
    if (!$field->access('view')) {
      return '';
    }

    $field_definition = $field->getFieldDefinition();
    $field_type = $field_definition->getType();
    $text_parts = [];
    $item_count = 0;

    foreach ($field as $item) {
      // Limit number of items processed per field for performance
      if ($item_count++ >= self::MAX_ENTITIES_PER_FIELD) {
        break;
      }
      
      $text = '';

      switch ($field_type) {
        case 'string':
        case 'string_long':
          if (isset($item->value)) {
            $text = strip_tags($item->value);
          }
          break;

        case 'text':
        case 'text_long':
        case 'text_with_summary':
          if (isset($item->value)) {
            // Apply text format processing if format is specified
            if (isset($item->format) && $item->format) {
              $build = [
                '#type' => 'processed_text',
                '#text' => $item->value,
                '#format' => $item->format,
              ];
              $rendered = \Drupal::service('renderer')->renderPlain($build);
              $text = strip_tags($rendered);
            } else {
              $text = strip_tags($item->value);
            }
          }
          break;

        case 'list_string':
          if (isset($item->value)) {
            $text = strip_tags($item->value);
          }
          break;

        case 'entity_reference':
        case 'entity_reference_revisions':
          if (isset($item->target_id)) {
            $target_type = $field_definition->getSetting('target_type');
            $entity_key = $target_type . ':' . $item->target_id;
            
            // Prevent infinite loops
            if (in_array($entity_key, $processed_entities)) {
              continue 2;
            }

            $processed_entities[] = $entity_key;
            
            try {
              $referenced_entity = $this->entityTypeManager
                ->getStorage($target_type)
                ->load($item->target_id);

              if ($referenced_entity) {
                $text = $this->extractEntityText($referenced_entity, $processed_entities, $depth + 1);
              }
                         } catch (\Exception $e) {
               // Silently skip entities that can't be loaded
               continue 2;
             }
          }
          break;

        default:
          // For other field types, try to get a string representation
          if (method_exists($item, '__toString')) {
            $text = strip_tags((string) $item);
          }
          break;
      }

      if ($text) {
        $text_parts[] = trim($text);
      }
    }

    return implode("\n", array_filter($text_parts));
  }

  /**
   * Extract text from an entity by processing its text-compatible fields.
   *
   * @param object $entity
   *   The entity to extract text from.
   * @param array $processed_entities
   *   Array to track processed entities.
   * @param int $depth
   *   Current recursion depth.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractEntityText($entity, array $processed_entities, int $depth): string {
    if ($depth >= self::MAX_RECURSION_DEPTH) {
      return '';
    }

    $text_parts = [];
    $field_definitions = $entity->getFieldDefinitions();

    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip base fields except for common ones
      if ($field_definition->isBaseField() && !in_array($field_name, ['title', 'name', 'body'])) {
        continue;
      }

      $field_type = $field_definition->getType();
      
      // Only process text-compatible field types
      $allowed_types = [
        'string', 'string_long', 'text', 'text_long', 'text_with_summary',
        'list_string', 'entity_reference', 'entity_reference_revisions'
      ];

      if (!in_array($field_type, $allowed_types)) {
        continue;
      }

      if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
        $field_text = $this->extractFieldText($entity->get($field_name), $processed_entities, $depth);
        if ($field_text) {
          $text_parts[] = $field_text;
        }
      }
    }

    return implode("\n", array_filter($text_parts));
  }

  /**
   * Get all text-compatible fields for a given entity type and bundle.
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node').
   * @param string $bundle
   *   The bundle name (e.g., 'article').
   *
   * @return array
   *   Array of field definitions keyed by field name.
   */
  public function getTextCompatibleFields(string $entity_type, string $bundle): array {
    $compatible_fields = [];
    
    try {
      $field_definitions = $this->entityTypeManager
        ->getStorage('field_config')
        ->loadByProperties([
          'entity_type' => $entity_type,
          'bundle' => $bundle,
        ]);

      foreach ($field_definitions as $field_definition) {
        $field_name = $field_definition->getName();
        $field_type = $field_definition->getType();
        
        // Skip if field is not useful for analysis
        if (!$this->isFieldUsefulForAnalysis($field_name, $field_type, $field_definition)) {
          continue;
        }
        
        $compatible_fields[$field_name] = $field_definition;
      }
    } catch (\Exception $e) {
      // Return empty array if there's an error
    }

    return $compatible_fields;
  }

  /**
   * Check if a field is useful for topic analysis.
   *
   * @param string $field_name
   *   The field machine name.
   * @param string $field_type
   *   The field type.
   * @param object $field_definition
   *   The field definition object.
   *
   * @return bool
   *   TRUE if the field should be included in analysis options.
   */
  protected function isFieldUsefulForAnalysis(string $field_name, string $field_type, $field_definition): bool {
    // Skip system/internal fields that don't contain useful content
    $excluded_field_patterns = [
      // TopicalBoost internal fields
      'field_ttd_',
      // Common system fields
      'field_metatag',
      'field_meta_',
      'comment',
      // Media/file fields without text content
      'field_image',
      'field_file',
      'field_media',
      'field_video',
      'field_audio',
      // Technical fields
      'field_weight',
      'field_status',
      'field_published',
      'field_featured',
      'field_sticky',
      // Date/time fields (not useful for topic analysis)
      'field_date',
      'field_created',
      'field_updated',
      'field_event_date',
      // Layout/display fields
      'field_layout',
      'field_display',
      'field_view_mode',
    ];

    foreach ($excluded_field_patterns as $pattern) {
      if (strpos($field_name, $pattern) === 0) {
        return FALSE;
      }
    }

    // Only include field types that contain or reference useful text content
    $useful_field_types = [
      // Direct text fields
      'string',
      'string_long', 
      'text',
      'text_long',
      'text_with_summary',
      'list_string',
      // Reference fields that might contain text
      'entity_reference',
      'entity_reference_revisions',
    ];

    if (!in_array($field_type, $useful_field_types)) {
      return FALSE;
    }

    // For entity reference fields, check if they reference useful entities
    if (in_array($field_type, ['entity_reference', 'entity_reference_revisions'])) {
      return $this->isEntityReferenceUseful($field_definition);
    }

    return TRUE;
  }

  /**
   * Check if an entity reference field is useful for analysis.
   *
   * @param object $field_definition
   *   The field definition object.
   *
   * @return bool
   *   TRUE if the entity reference contains useful text content.
   */
  protected function isEntityReferenceUseful($field_definition): bool {
    $target_type = $field_definition->getSetting('target_type');
    
    // Useful entity reference types
    $useful_reference_types = [
      'taxonomy_term',  // Tags, categories, etc.
      'paragraph',      // Paragraph entities often contain text
      'node',          // Other nodes
      'user',          // User profiles might have useful text
    ];

    if (!in_array($target_type, $useful_reference_types)) {
      return FALSE;
    }

    // For taxonomy references, check if it's a useful vocabulary
    if ($target_type === 'taxonomy_term') {
      $handler_settings = $field_definition->getSetting('handler_settings');
      $target_bundles = $handler_settings['target_bundles'] ?? [];
      
      // Exclude internal TopicalBoost vocabulary
      if (in_array('ttd_topics', $target_bundles)) {
        return FALSE;
      }
    }

    return TRUE;
  }
} 