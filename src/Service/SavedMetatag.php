<?php

namespace Drupal\ttd_topics\Service;

use Drupal\node\NodeInterface;

/** Reads stored per-node Metatag overrides without resolving default tokens. */
final class SavedMetatag {

  public static function fieldName(NodeInterface $node): ?string {
    $native = NULL;
    // Metatag replaces the saved map for each native field in definition order.
    foreach ($node->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'metatag') {
        $native = $name;
      }
    }
    if ($native !== NULL) {
      return $native;
    }
    foreach (['field_meta_tags', 'field_metatag'] as $name) {
      if ($node->hasField($name)) {
        return $name;
      }
    }
    return NULL;
  }

  /** NULL means unreadable; an empty map is a successfully read empty field. */
  public static function decode($raw): ?array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (is_string($raw)) {
      $json = json_decode($raw, TRUE);
      $raw = json_last_error() === JSON_ERROR_NONE ? $json : @unserialize($raw, ['allowed_classes' => FALSE]);
    }
    if (!is_array($raw) || ($raw && array_is_list($raw))) {
      return NULL;
    }
    return $raw;
  }

  public static function tags(NodeInterface $node): ?array {
    $name = self::fieldName($node);
    if ($name === NULL) {
      return NULL;
    }
    if ($node->getFieldDefinition($name)->getType() !== 'metatag') {
      return self::decode($node->get($name)->value ?? NULL);
    }
    $tags = [];
    foreach ($node->get($name)->getValue() as $item) {
      $values = self::decode($item['value'] ?? NULL);
      if ($values === NULL) {
        return NULL;
      }
      // Native Metatag keeps the earliest delta containing each tag.
      $tags += $values;
    }
    return $tags;
  }

  public static function description(NodeInterface $node): array {
    $tags = self::tags($node);
    if ($tags === NULL || (isset($tags['description']) && !is_string($tags['description']))) {
      return ['available' => FALSE];
    }
    $value = $tags['description'] ?? NULL;
    return ['available' => TRUE, 'value' => is_string($value) && trim($value) !== '' ? $value : NULL];
  }

  public static function write(NodeInterface $node, array $tags): void {
    $name = self::fieldName($node);
    if ($name === NULL) {
      return;
    }
    $current = self::tags($node);
    if ($current === NULL) {
      throw new \RuntimeException('Saved Metatag values could not be read. No replacement was applied.');
    }
    if ($node->getFieldDefinition($name)->getType() !== 'metatag') {
      $node->set($name, self::encode($node->get($name)->value ?? NULL, $tags, FALSE));
      return;
    }
    $items = $node->get($name)->getValue() ?: [['value' => NULL]];
    $removed = array_diff_key($current, $tags);
    $changed = [];
    foreach ($tags as $key => $value) {
      if (!array_key_exists($key, $current) || $current[$key] !== $value) {
        $changed[$key] = $value;
      }
    }
    foreach ($items as $delta => &$item) {
      $values = self::decode($item['value'] ?? NULL);
      // Native preSave may drop a value matching defaults; discard stale copies.
      foreach ($removed + ($delta === 0 ? [] : $changed) as $key => $_) {
        unset($values[$key]);
      }
      if ($delta === 0) {
        $values = array_replace($values, $changed);
      }
      $item['value'] = self::encode($item['value'] ?? NULL, $values, TRUE);
    }
    unset($item);
    $node->set($name, $items);
  }

  public static function encode($raw, array $tags, bool $native_metatag): string {
    // Metatag's helper chooses its own storage format, including an empty field.
    if ($native_metatag && function_exists('metatag_data_encode')) {
      return \metatag_data_encode($tags);
    }
    if (is_string($raw) && is_array(json_decode($raw, TRUE))) {
      return json_encode($tags, JSON_THROW_ON_ERROR);
    }
    return serialize($tags);
  }

}
