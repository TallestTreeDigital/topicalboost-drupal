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

    // Debug logging for collection start
    \Drupal::logger('ttd_topics_debug')->debug('Starting content collection for node @nid (@title). Custom fields: @fields', [
      '@nid' => $node->id(),
      '@title' => $node->getTitle(),
      '@fields' => implode(', ', $custom_fields),
    ]);

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
        $field_definition = $node->get($field_name)->getFieldDefinition();
        $field_type = $field_definition->getType();

        // Debug logging for custom field processing
        \Drupal::logger('ttd_topics_debug')->debug('Processing custom field @field_name (@field_type)', [
          '@field_name' => $field_name,
          '@field_type' => $field_type,
        ]);

        $field_text = $this->extractFieldText($node->get($field_name), [], 0);
        if ($field_text && $current_length + strlen($field_text) < self::MAX_TEXT_LENGTH) {
          $field_text_length = strlen($field_text);
          \Drupal::logger('ttd_topics_debug')->debug('Added @length chars from field @field_name: @preview', [
            '@length' => $field_text_length,
            '@field_name' => $field_name,
            '@preview' => substr($field_text, 0, 100) . ($field_text_length > 100 ? '...' : ''),
          ]);
          $content_parts[] = $field_text;
          $current_length += $field_text_length;
        } else {
          \Drupal::logger('ttd_topics_debug')->debug('Field @field_name produced no text or would exceed length limit', [
            '@field_name' => $field_name,
          ]);
        }
      } else {
        \Drupal::logger('ttd_topics_debug')->debug('Field @field_name not found or empty on node', [
          '@field_name' => $field_name,
        ]);
      }
    }

    $final_content = implode("\n\n", $content_parts);
    $final_length = strlen($final_content);

    // Debug logging for collection summary
    \Drupal::logger('ttd_topics_debug')->debug('Collection complete for node @nid. Total length: @length chars from @parts parts', [
      '@nid' => $node->id(),
      '@length' => $final_length,
      '@parts' => count($content_parts),
    ]);

    return $final_content;
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

        case 'link':
          // Extract title text from link fields
          if (isset($item->title) && !empty($item->title)) {
            $text = strip_tags($item->title);
          }
          // Fallback to URI if no title
          elseif (isset($item->uri) && !empty($item->uri)) {
            $text = $item->uri;
          }
          break;

        case 'file':
        case 'image':
          // Extract description text from file fields
          if (isset($item->description) && !empty($item->description)) {
            $text = strip_tags($item->description);
          }
          // Also get alt text for images
          elseif (isset($item->alt) && !empty($item->alt)) {
            $text = strip_tags($item->alt);
          }
          // Fallback to filename if no description/alt
          elseif (isset($item->target_id)) {
            try {
              $file = $this->entityTypeManager->getStorage('file')->load($item->target_id);
              if ($file) {
                $filename = $file->getFilename();
                // Clean up filename - remove extension and replace underscores/dashes
                $text = preg_replace('/\.[^.]*$/', '', $filename);
                $text = str_replace(['_', '-'], ' ', $text);
              }
            } catch (\Exception $e) {
              // Continue if file cannot be loaded
            }
          }
          break;

        case 'address':
          // Extract all address components
          $address_parts = [];
          $address_fields = [
            'organization', 'address_line1', 'address_line2',
            'locality', 'administrative_area', 'postal_code', 'country_code'
          ];

          foreach ($address_fields as $field) {
            if (isset($item->$field) && !empty($item->$field)) {
              $address_parts[] = $item->$field;
            }
          }

          $text = implode(' ', $address_parts);
          break;

        case 'email':
        case 'telephone':
          // Extract email/phone values
          if (isset($item->value) && !empty($item->value)) {
            $text = $item->value;
          }
          break;

        case 'integer':
        case 'decimal':
        case 'float':
        case 'number':
          // Convert numbers to text
          if (isset($item->value) && $item->value !== '') {
            $text = (string) $item->value;
          }
          break;

        case 'datetime':
        case 'date':
        case 'daterange':
          // Extract date values as text
          if (isset($item->value) && !empty($item->value)) {
            $text = $item->value;
          }
          // Also handle end date for date ranges
          if (isset($item->end_value) && !empty($item->end_value)) {
            $text .= ' ' . $item->end_value;
          }
          break;

        case 'boolean':
          // Extract boolean labels if available
          if (isset($item->value)) {
            $on_label = $field_definition->getSetting('on_label');
            $off_label = $field_definition->getSetting('off_label');

            if ($item->value && $on_label) {
              $text = $on_label;
            } elseif (!$item->value && $off_label) {
              $text = $off_label;
            }
          }
          break;

        case 'entity_reference':
        case 'entity_reference_revisions':
          if (isset($item->target_id)) {
            $target_type = $field_definition->getSetting('target_type');
            $entity_key = $target_type . ':' . $item->target_id;

            // Debug logging for paragraph fields
            $field_name = $field_definition->getName();
            if ($target_type === 'paragraph') {
              \Drupal::logger('ttd_topics_debug')->debug('Processing paragraph field @field (target_id: @target_id)', [
                '@field' => $field_name,
                '@target_id' => $item->target_id,
              ]);
            }

            // Prevent infinite loops
            if (in_array($entity_key, $processed_entities)) {
              if ($target_type === 'paragraph') {
                \Drupal::logger('ttd_topics_debug')->debug('Skipping paragraph @target_id to prevent infinite loop', [
                  '@target_id' => $item->target_id,
                ]);
              }
              continue 2;
            }

            $processed_entities[] = $entity_key;

            try {
              $referenced_entity = $this->entityTypeManager
                ->getStorage($target_type)
                ->load($item->target_id);

              if ($referenced_entity) {
                $text = $this->extractEntityText($referenced_entity, $processed_entities, $depth + 1);

                // Debug logging for paragraph content extraction
                if ($target_type === 'paragraph') {
                  $bundle = $referenced_entity->bundle();
                  $text_length = strlen($text);
                  \Drupal::logger('ttd_topics_debug')->debug('Extracted @length chars from paragraph @id (@bundle): @preview', [
                    '@length' => $text_length,
                    '@id' => $item->target_id,
                    '@bundle' => $bundle,
                    '@preview' => substr($text, 0, 100) . ($text_length > 100 ? '...' : ''),
                  ]);
                }
              } else {
                if ($target_type === 'paragraph') {
                  \Drupal::logger('ttd_topics_debug')->warning('Paragraph entity @target_id could not be loaded', [
                    '@target_id' => $item->target_id,
                  ]);
                }
              }
                         } catch (\Exception $e) {
               // Log errors for paragraph fields specifically
               if ($target_type === 'paragraph') {
                 \Drupal::logger('ttd_topics_debug')->error('Error loading paragraph @target_id: @message', [
                   '@target_id' => $item->target_id,
                   '@message' => $e->getMessage(),
                 ]);
               }
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
      $is_base_field = method_exists($field_definition, 'isBaseField') ? $field_definition->isBaseField() : false;
      if ($is_base_field && !in_array($field_name, ['title', 'name', 'body'])) {
        continue;
      }

      $field_type = $field_definition->getType();
      
      // Only process text-compatible field types
      $allowed_types = [
        'string', 'string_long', 'text', 'text_long', 'text_with_summary',
        'list_string', 'link', 'file', 'image', 'address', 'email', 'telephone',
        'integer', 'decimal', 'float', 'number', 'datetime', 'date', 'daterange',
        'boolean', 'entity_reference', 'entity_reference_revisions'
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
      // Media fields without text content
      'field_media',
      'field_video',
      'field_audio',
      // Technical fields
      'field_weight',
      'field_status',
      'field_published',
      'field_featured',
      'field_sticky',
      // System date/time fields (not custom date content)
      'field_created',
      'field_updated',
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
      // Link fields
      'link',
      // File and media fields
      'file',
      'image',
      // Contact fields
      'address',
      'email',
      'telephone',
      // Number fields
      'integer',
      'decimal',
      'float',
      'number',
      // Date fields
      'datetime',
      'date',
      'daterange',
      // Boolean fields
      'boolean',
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