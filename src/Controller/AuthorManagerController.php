<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\RendererInterface;
use Drupal\ttd_topics\AuthorManagerHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX endpoints for TopicalBoost Author Manager.
 */
class AuthorManagerController extends ControllerBase {

  /**
   * Constructs an AuthorManagerController object.
   */
  public function __construct(
    protected AuthorManagerHelper $authorHelper,
    protected ConfigFactoryInterface $settingsConfigFactory,
    protected RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ttd_topics.author_manager_helper'),
      $container->get('config.factory'),
      $container->get('renderer')
    );
  }

  /**
   * Returns the mapping UI for a selected author source field.
   */
  public function fieldMapping(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    $field_name = trim((string) ($data['field_name'] ?? $request->request->get('field_name', '')));
    if ($field_name === '') {
      return new JsonResponse(['success' => TRUE, 'html' => '', 'type' => '']);
    }

    if (!preg_match('/^[A-Za-z0-9_:.-]+$/', $field_name)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Invalid field name.'], 400);
    }

    $config = $this->settingsConfigFactory->get('ttd_topics.settings');
    $settings = [
      'author_name_field' => $config->get('author_name_field') ?: '',
      'author_image_field' => $config->get('author_image_field') ?: '',
      'author_description_field' => $config->get('author_description_field') ?: '',
    ];
    $content_types = array_filter($config->get('enabled_content_types') ?: []);
    $target = $this->authorHelper->getAuthorFieldTarget($field_name, $content_types);
    $build = $this->authorHelper->buildMappingForm($field_name, $settings, $content_types);

    return new JsonResponse([
      'success' => TRUE,
      'html' => (string) $this->renderer->renderPlain($build),
      'type' => $target['target_type'] ?? '',
    ]);
  }

  /**
   * Saves Author Manager settings from an admin JSON request.
   */
  public function saveSettings(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['success' => FALSE, 'message' => 'Invalid JSON body.'], 400);
    }

    $config = $this->settingsConfigFactory->getEditable('ttd_topics.settings');
    $config
      ->set('author_manager_enabled', !empty($data['author_manager_enabled']))
      ->set('author_hide_content_types', $this->cleanStringList($data['author_hide_content_types'] ?? []))
      ->set('author_force_show_content_types', $this->cleanStringList($data['author_force_show_content_types'] ?? []))
      ->set('custom_author_schema_enabled', !empty($data['custom_author_schema_enabled']))
      ->set('author_field_name', $this->cleanMachineName($data['author_field_name'] ?? 'uid') ?: 'uid')
      ->set('author_name_field', $this->cleanMachineName($data['author_name_field'] ?? 'display_name') ?: 'display_name')
      ->set('author_image_field', $this->cleanMachineName($data['author_image_field'] ?? ''))
      ->set('author_description_field', $this->cleanMachineName($data['author_description_field'] ?? ''))
      ->save();

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Cleans a list of machine-name-ish strings.
   */
  protected function cleanStringList($value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter(array_map([$this, 'cleanMachineName'], $value)));
  }

  /**
   * Cleans one machine-name-ish string.
   */
  protected function cleanMachineName($value): string {
    $value = trim((string) $value);
    return preg_match('/^[A-Za-z0-9_:.-]+$/', $value) ? $value : '';
  }

}
