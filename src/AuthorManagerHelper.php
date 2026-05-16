<?php

namespace Drupal\ttd_topics;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Shared helpers for the TopicalBoost Author Manager settings and schema.
 */
class AuthorManagerHelper {

  /**
   * Constructs an AuthorManagerHelper object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected Connection $database,
  ) {}

  /**
   * Gets public Drupal content types with node counts.
   */
  public function getContentTypeRows(): array {
    $rows = [];
    $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($types as $type) {
      $count = (int) $this->database->select('node_field_data', 'n')
        ->condition('type', $type->id())
        ->countQuery()
        ->execute()
        ->fetchField();
      $rows[$type->id()] = [
        'id' => $type->id(),
        'label' => $type->label(),
        'count' => $count,
      ];
    }

    uasort($rows, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $rows;
  }

  /**
   * Gets candidate author source fields.
   */
  public function getAuthorFieldOptions(array $content_types = []): array {
    $options = ['uid' => 'Drupal author - uid'];
    $content_types = $content_types ?: array_keys($this->getContentTypeRows());

    foreach ($content_types as $bundle) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      foreach ($definitions as $field_name => $definition) {
        if (!$this->isAuthorReferenceField($definition)) {
          continue;
        }
        $target_type = $definition->getSetting('target_type');
        $options[$field_name] = sprintf('%s - %s (%s)', $definition->getLabel(), $field_name, $target_type);
      }
    }

    asort($options);
    return $options;
  }

  /**
   * Gets target metadata for a configured author source field.
   */
  public function getAuthorFieldTarget(string $field_name, array $content_types = []): array {
    if ($field_name === 'uid') {
      return [
        'field_name' => 'uid',
        'target_type' => 'user',
        'target_bundles' => ['user'],
        'label' => 'Drupal author',
      ];
    }

    $content_types = $content_types ?: array_keys($this->getContentTypeRows());
    foreach ($content_types as $bundle) {
      $definition = $this->entityFieldManager->getFieldDefinitions('node', $bundle)[$field_name] ?? NULL;
      if (!$definition || !$this->isAuthorReferenceField($definition)) {
        continue;
      }

      return [
        'field_name' => $field_name,
        'target_type' => $definition->getSetting('target_type'),
        'target_bundles' => $this->getTargetBundles($definition),
        'label' => (string) $definition->getLabel(),
      ];
    }

    return [];
  }

  /**
   * Builds the field mapping form fragment.
   */
  public function buildMappingForm(string $field_name, array $settings = [], array $content_types = []): array {
    $target = $this->getAuthorFieldTarget($field_name, $content_types);
    if (empty($target)) {
      return [
        '#type' => 'container',
        '#attributes' => ['id' => 'ttd-author-field-mapping'],
        'empty' => ['#markup' => '<div class="messages messages--status">Select an author field to configure mapping.</div>'],
      ];
    }

    $mapping = $this->getMappingOptions($target);

    return [
      '#type' => 'container',
      '#attributes' => ['id' => 'ttd-author-field-mapping', 'class' => ['ttd-author-field-mapping']],
      'title' => [
        '#markup' => '<h4>' . ucfirst(str_replace('_', ' ', $target['target_type'])) . ' Author Field Mapping</h4>',
      ],
      'author_name_field' => [
        '#type' => 'select',
        '#title' => 'Name field',
        '#options' => $mapping['name'],
        '#default_value' => $settings['author_name_field'] ?? array_key_first($mapping['name']),
        '#attributes' => ['class' => ['ttd-topics-field-group']],
      ],
      'author_image_field' => [
        '#type' => 'select',
        '#title' => 'Image field',
        '#options' => ['' => 'No image'] + $mapping['image'],
        '#default_value' => $settings['author_image_field'] ?? '',
        '#attributes' => ['class' => ['ttd-topics-field-group']],
      ],
      'author_description_field' => [
        '#type' => 'select',
        '#title' => 'Description field',
        '#options' => ['' => 'No description'] + $mapping['description'],
        '#default_value' => $settings['author_description_field'] ?? '',
        '#attributes' => ['class' => ['ttd-topics-field-group']],
      ],
    ];
  }

  /**
   * Gets mapping options for the target entity type.
   */
  public function getMappingOptions(array $target): array {
    $target_type = $target['target_type'] ?? '';
    $options = [
      'name' => [],
      'image' => [],
      'description' => [],
    ];

    if ($target_type === 'user') {
      $options['name'] = [
        'display_name' => 'Display name',
        'account_name' => 'Username',
        'mail' => 'Email',
      ];
      $options['image'] = $this->collectFieldOptions('user', 'user', ['image']);
      $options['description'] = $this->collectFieldOptions('user', 'user', ['text', 'text_long', 'string', 'string_long']);
      return $options;
    }

    if ($target_type === 'taxonomy_term') {
      $options['name'] = ['name' => 'Term name'] + $this->collectBundleFields($target, ['string', 'string_long', 'text', 'text_long']);
      $options['image'] = $this->collectBundleFields($target, ['image']);
      $options['description'] = ['description' => 'Term description'] + $this->collectBundleFields($target, ['text', 'text_long', 'string_long']);
      return $options;
    }

    if ($target_type === 'node') {
      $options['name'] = ['title' => 'Title'] + $this->collectBundleFields($target, ['string', 'string_long', 'text', 'text_long']);
      $options['image'] = $this->collectBundleFields($target, ['image']);
      $options['description'] = ['body' => 'Body'] + $this->collectBundleFields($target, ['text', 'text_long', 'string_long']);
      return $options;
    }

    $options['name'] = ['label' => 'Entity label'];
    return $options;
  }

  /**
   * Checks whether a node field can be an author source.
   */
  protected function isAuthorReferenceField(FieldDefinitionInterface $definition): bool {
    if ($definition->getType() !== 'entity_reference') {
      return FALSE;
    }
    return in_array($definition->getSetting('target_type'), ['user', 'node', 'taxonomy_term'], TRUE);
  }

  /**
   * Gets target bundle constraints from a reference field.
   */
  protected function getTargetBundles(FieldDefinitionInterface $definition): array {
    $settings = $definition->getSetting('handler_settings') ?: [];
    $bundles = array_filter($settings['target_bundles'] ?? []);
    return $bundles ? array_values($bundles) : [];
  }

  /**
   * Collects fields from all target bundles.
   */
  protected function collectBundleFields(array $target, array $types): array {
    $options = [];
    $target_type = $target['target_type'];
    $bundles = $target['target_bundles'] ?: array_keys($this->entityTypeManager->getStorage($target_type === 'node' ? 'node_type' : 'taxonomy_vocabulary')->loadMultiple());

    foreach ($bundles as $bundle) {
      $options += $this->collectFieldOptions($target_type, $bundle, $types);
    }

    asort($options);
    return $options;
  }

  /**
   * Collects fields of matching types for one bundle.
   */
  protected function collectFieldOptions(string $entity_type, string $bundle, array $types): array {
    $options = [];
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type, $bundle) as $field_name => $definition) {
      if (in_array($definition->getType(), $types, TRUE)) {
        $options[$field_name] = sprintf('%s - %s', $definition->getLabel(), $field_name);
      }
    }
    return $options;
  }

}
