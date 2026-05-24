<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles schema image generation in 3 aspect ratios with focal point cropping.
 */
class SchemaImagesController extends ControllerBase {

  /**
   * Image size definitions.
   */
  const SIZES = [
    '16x9' => ['width' => 1200, 'height' => 675],
    '4x3' => ['width' => 900, 'height' => 675],
    '1x1' => ['width' => 675, 'height' => 675],
  ];

  /**
   * Image fields that can act as the source for generated schema crops.
   */
  const SOURCE_IMAGE_FIELDS = [
    'field_image',
    'field_featured_image',
    'field_article_image',
    'field_article_banner',
    'field_hero_image',
    'field_media_image',
  ];

  /**
   * Generate schema images from an uploaded or existing image.
   */
  public function generate(Request $request) {
    $nid = (int) $request->request->get('nid');
    $fid = (int) $request->request->get('fid');
    $focal_x = max(0.0, min(1.0, (float) $request->request->get('focal_x', 0.5)));
    $focal_y = max(0.0, min(1.0, (float) $request->request->get('focal_y', 0.5)));

    if (!$nid) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Node ID required'], 400);
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Node not found'], 404);
    }

    // Get source image.
    $source_file = NULL;
    if ($request->files->has('schema_image')) {
      $source_file = $this->createSourceFileFromUpload($request, $nid);
      if (!$source_file) {
        return new JsonResponse(['success' => FALSE, 'message' => 'Uploaded image could not be saved'], 400);
      }
    }

    if ($fid) {
      $source_file = File::load($fid);
    }

    // Fall back to node's featured image.
    if (!$source_file) {
      $source_file = $this->getSourceFileFromNode($node);
    }

    if (!$source_file) {
      return new JsonResponse(['success' => FALSE, 'message' => 'No source image found'], 400);
    }

    $source_path = \Drupal::service('file_system')->realpath($source_file->getFileUri());
    if (!$source_path || !file_exists($source_path)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Source image file not found on disk'], 400);
    }

    // Get source dimensions.
    $image_factory = \Drupal::service('image.factory');
    $source_image = $image_factory->get($source_path);
    if (!$source_image->isValid()) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Invalid image file'], 400);
    }

    $src_width = $source_image->getWidth();
    $src_height = $source_image->getHeight();

    // Check minimum size.
    if ($src_width < 675 || $src_height < 675) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => 'Source image too small. Minimum 675x675 pixels required. Current: ' . $src_width . 'x' . $src_height,
      ], 400);
    }

    // Prepare output directory.
    $directory = 'public://schema-images/' . $nid;
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $results = [];
    $field_map = [
      '16x9' => 'field_ttd_schema_16x9',
      '4x3' => 'field_ttd_schema_4x3',
      '1x1' => 'field_ttd_schema_1x1',
    ];

    foreach (self::SIZES as $ratio => $dimensions) {
      $dest_width = $dimensions['width'];
      $dest_height = $dimensions['height'];

      // Calculate crop region using focal point.
      $crop = $this->calculateFocalPointCrop(
        $src_width, $src_height,
        $dest_width, $dest_height,
        (float) $focal_x, (float) $focal_y
      );

      // Create the cropped image.
      $cropped = $image_factory->get($source_path);
      $cropped->crop($crop['x'], $crop['y'], $crop['width'], $crop['height']);
      $cropped->resize($dest_width, $dest_height);

      $filename = 'schema-' . $ratio . '-' . time() . '.jpg';
      $dest_uri = $directory . '/' . $filename;
      $dest_path = \Drupal::service('file_system')->realpath($dest_uri);

      // Ensure parent directory exists for realpath.
      $real_dir = \Drupal::service('file_system')->realpath($directory);
      if (!$real_dir) {
        \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
        $real_dir = \Drupal::service('file_system')->realpath($directory);
      }
      $dest_path = $real_dir . '/' . $filename;

      if (!$cropped->save($dest_path)) {
        $results[$ratio] = ['success' => FALSE, 'message' => 'Failed to save ' . $ratio];
        continue;
      }

      // Create file entity.
      $file = File::create([
        'uri' => $dest_uri,
        'filename' => $filename,
        'status' => 1,
      ]);
      $file->save();

      // Delete old file entity if one exists.
      $field_name = $field_map[$ratio];
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $old_file = $node->get($field_name)->entity;
        if ($old_file) {
          $old_file->delete();
        }
      }

      // Set on node.
      if ($node->hasField($field_name)) {
        $node->set($field_name, ['target_id' => $file->id(), 'alt' => $node->getTitle() . ' (' . $ratio . ')']);
      }

      $results[$ratio] = [
        'success' => TRUE,
        'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($dest_uri),
        'width' => $dest_width,
        'height' => $dest_height,
      ];
    }

    // Save focal point data.
    if ($node->hasField('field_ttd_schema_focal_point')) {
      $node->set('field_ttd_schema_focal_point', $focal_x . ',' . $focal_y);
    }

    $node->save();

    return new JsonResponse([
      'success' => TRUE,
      'images' => $results,
      'source' => [
        'width' => $src_width,
        'height' => $src_height,
        'fid' => $source_file->id(),
      ],
    ]);
  }

  /**
   * Get current schema image status for a node.
   */
  public function getStatus(Request $request) {
    $nid = $request->query->get('nid');
    if (!$nid) {
      return new JsonResponse(['success' => FALSE], 400);
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      return new JsonResponse(['success' => FALSE], 404);
    }

    $images = [];
    $field_map = [
      '16x9' => 'field_ttd_schema_16x9',
      '4x3' => 'field_ttd_schema_4x3',
      '1x1' => 'field_ttd_schema_1x1',
    ];

    foreach ($field_map as $ratio => $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $file = $node->get($field_name)->entity;
        if ($file) {
          $images[$ratio] = [
            'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()),
            'fid' => $file->id(),
            'width' => self::SIZES[$ratio]['width'],
            'height' => self::SIZES[$ratio]['height'],
          ];
        }
      }
    }

    // Get focal point.
    $focal_point = NULL;
    if ($node->hasField('field_ttd_schema_focal_point') && !$node->get('field_ttd_schema_focal_point')->isEmpty()) {
      $fp_value = $node->get('field_ttd_schema_focal_point')->value;
      $parts = explode(',', $fp_value);
      if (count($parts) === 2) {
        $focal_point = ['x' => (float) $parts[0], 'y' => (float) $parts[1]];
      }
    }

    // Check source image availability.
    $source_info = $this->getSourceImageInfo($node);

    return new JsonResponse([
      'success' => TRUE,
      'images' => $images,
      'focal_point' => $focal_point,
      'source' => $source_info,
      'all_ready' => count($images) === 3,
      'partial' => count($images) > 0 && count($images) < 3,
    ]);
  }

  /**
   * Clear all schema images for a node.
   */
  public function clear(Request $request) {
    $nid = (int) $request->request->get('nid');
    if (!$nid) {
      return new JsonResponse(['success' => FALSE], 400);
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node) {
      return new JsonResponse(['success' => FALSE], 404);
    }

    $field_map = [
      'field_ttd_schema_16x9',
      'field_ttd_schema_4x3',
      'field_ttd_schema_1x1',
    ];

    foreach ($field_map as $field_name) {
      if ($node->hasField($field_name) && !$node->get($field_name)->isEmpty()) {
        $old_file = $node->get($field_name)->entity;
        if ($old_file) {
          $old_file->delete();
        }
        $node->set($field_name, NULL);
      }
    }

    if ($node->hasField('field_ttd_schema_focal_point')) {
      $node->set('field_ttd_schema_focal_point', NULL);
    }

    $node->save();

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Calculate crop region based on focal point.
   */
  protected function calculateFocalPointCrop(int $src_w, int $src_h, int $dest_w, int $dest_h, float $focal_x, float $focal_y): array {
    $target_ratio = $dest_w / $dest_h;
    $src_ratio = $src_w / $src_h;

    if ($src_ratio > $target_ratio) {
      // Source is wider - crop width.
      $crop_height = $src_h;
      $crop_width = (int) round($src_h * $target_ratio);
    }
    else {
      // Source is taller - crop height.
      $crop_width = $src_w;
      $crop_height = (int) round($src_w / $target_ratio);
    }

    // Position crop around focal point.
    $focal_px_x = (int) round($focal_x * $src_w);
    $focal_px_y = (int) round($focal_y * $src_h);

    $x = max(0, min($src_w - $crop_width, $focal_px_x - (int) round($crop_width / 2)));
    $y = max(0, min($src_h - $crop_height, $focal_px_y - (int) round($crop_height / 2)));

    return [
      'x' => $x,
      'y' => $y,
      'width' => $crop_width,
      'height' => $crop_height,
    ];
  }

  /**
   * Get source image info from node's featured image.
   */
  protected function getSourceImageInfo($node): ?array {
    $file = $this->getSourceFileFromNode($node);
    if ($file) {
      $path = \Drupal::service('file_system')->realpath($file->getFileUri());
      if ($path && file_exists($path)) {
        $size = getimagesize($path);
        if ($size) {
          $suitable = $size[0] >= 675 && $size[1] >= 675;
          return [
            'fid' => $file->id(),
            'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()),
            'width' => $size[0],
            'height' => $size[1],
            'suitable' => $suitable,
            'message' => $suitable
              ? 'Source image is suitable (' . $size[0] . 'x' . $size[1] . ')'
              : 'Source image is too small (' . $size[0] . 'x' . $size[1] . '). Minimum 675x675 required.',
          ];
        }
      }
    }
    return NULL;
  }

  /**
   * Resolve the best available source file from a node image/media field.
   */
  protected function getSourceFileFromNode($node): ?File {
    foreach (self::SOURCE_IMAGE_FIELDS as $field_name) {
      if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
        continue;
      }

      $referenced = $node->get($field_name)->entity;
      if (!$referenced) {
        continue;
      }

      if ($referenced->getEntityTypeId() === 'file') {
        return $referenced;
      }

      if ($referenced->getEntityTypeId() === 'media') {
        $media_source = $referenced->getSource();
        $source_field = $media_source->getConfiguration()['source_field'] ?? 'field_media_image';
        if ($referenced->hasField($source_field) && !$referenced->get($source_field)->isEmpty()) {
          $file = $referenced->get($source_field)->entity;
          if ($file instanceof File) {
            return $file;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * Persist an uploaded source image so the generator can crop it.
   */
  protected function createSourceFileFromUpload(Request $request, int $nid): ?File {
    $uploaded = $request->files->get('schema_image');
    if (!$uploaded || !$uploaded->getRealPath() || !file_exists($uploaded->getRealPath())) {
      return NULL;
    }

    $extension = strtolower(pathinfo($uploaded->getClientOriginalName(), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], TRUE)) {
      return NULL;
    }

    $directory = 'public://schema-images/' . $nid;
    \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $filename = 'schema-source-' . time() . '.' . $extension;
    $dest_uri = $directory . '/' . $filename;
    $copied_uri = \Drupal::service('file_system')->copy($uploaded->getRealPath(), $dest_uri, FileSystemInterface::EXISTS_RENAME);
    if (!$copied_uri) {
      return NULL;
    }

    $file = File::create([
      'uri' => $copied_uri,
      'filename' => basename($copied_uri),
      'status' => 1,
    ]);
    $file->save();

    return $file;
  }

}
