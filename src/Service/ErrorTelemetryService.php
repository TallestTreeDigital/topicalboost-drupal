<?php

namespace Drupal\ttd_topics\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Service for collecting and sending error telemetry.
 */
class ErrorTelemetryService {

  /**
   * Maximum number of errors to buffer.
   */
  const MAX_BUFFER_SIZE = 50;

  /**
   * State key for the error buffer.
   */
  const STATE_KEY = 'topicalboost.error_telemetry_buffer';

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The state service.
   */
  protected StateInterface $state;

  /**
   * Constructs an ErrorTelemetryService.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state) {
    $this->configFactory = $config_factory;
    $this->state = $state;
  }

  /**
   * Check if telemetry is enabled.
   */
  public function isEnabled(): bool {
    $config = $this->configFactory->get('ttd_topics.settings');
    return (bool) ($config->get('error_telemetry_enabled') ?? TRUE);
  }

  /**
   * Buffer a PHP error for later transmission.
   *
   * Only captures errors from module files.
   *
   * @param int $type
   *   Error type (E_WARNING, E_NOTICE, etc).
   * @param string $message
   *   Error message.
   * @param string $file
   *   File where error occurred.
   * @param int $line
   *   Line number.
   */
  public function bufferError(int $type, string $message, string $file, int $line): void {
    if (!$this->isEnabled()) {
      return;
    }

    // Only capture errors from TopicalBoost module files.
    if (strpos($file, 'ttd_topics') === FALSE && strpos($file, 'topicalboost') === FALSE) {
      return;
    }

    $buffer = $this->state->get(self::STATE_KEY, []);

    if (count($buffer) >= self::MAX_BUFFER_SIZE) {
      return;
    }

    $buffer[] = [
      'errorType' => $this->getErrorTypeName($type),
      'message' => mb_substr($message, 0, 2000),
      'file' => basename($file),
      'line' => $line,
      'severity' => $this->getSeverity($type),
      'context' => [
        'drupal_version' => \Drupal::VERSION,
        'module_version' => $this->getModuleVersion(),
        'php_version' => PHP_VERSION,
      ],
    ];

    $this->state->set(self::STATE_KEY, $buffer);
  }

  /**
   * Buffer a JS error received from the client.
   *
   * @param array $error_data
   *   Error data from the client.
   */
  public function bufferJsError(array $error_data): void {
    if (!$this->isEnabled()) {
      return;
    }

    $buffer = $this->state->get(self::STATE_KEY, []);

    if (count($buffer) >= self::MAX_BUFFER_SIZE) {
      return;
    }

    $buffer[] = [
      'errorType' => 'js_error',
      'message' => mb_substr($error_data['message'] ?? '', 0, 2000),
      'file' => basename($error_data['file'] ?? ''),
      'line' => (int) ($error_data['line'] ?? 0),
      'stackTrace' => mb_substr($error_data['stack'] ?? '', 0, 5000),
      'severity' => 'error',
      'context' => [
        'drupal_version' => \Drupal::VERSION,
        'module_version' => $this->getModuleVersion(),
        'user_agent' => mb_substr($error_data['user_agent'] ?? '', 0, 200),
      ],
    ];

    $this->state->set(self::STATE_KEY, $buffer);
  }

  /**
   * Flush buffered errors to the API.
   *
   * Called during cron.
   */
  public function flush(): void {
    if (!$this->isEnabled()) {
      return;
    }

    $buffer = $this->state->get(self::STATE_KEY, []);
    if (empty($buffer)) {
      return;
    }

    $config = $this->configFactory->get('ttd_topics.settings');
    $api_key = $config->get('topicalboost_api_key');
    if (empty($api_key)) {
      return;
    }

    $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    try {
      $client = \Drupal::httpClient();
      $client->post($api_endpoint . '/telemetry/errors', [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'errors' => $buffer,
        ],
        'timeout' => 10,
      ]);

      // Clear buffer on successful send.
      $this->state->delete(self::STATE_KEY);
    }
    catch (\Exception $e) {
      // Silently fail - don't log telemetry errors to avoid noise.
      // Clear buffer anyway to prevent unbounded growth.
      if (count($buffer) >= self::MAX_BUFFER_SIZE) {
        $this->state->delete(self::STATE_KEY);
      }
    }
  }

  /**
   * Get human-readable error type name.
   */
  protected function getErrorTypeName(int $type): string {
    $types = [
      E_ERROR => 'php_fatal',
      E_WARNING => 'php_warning',
      E_NOTICE => 'php_notice',
      E_DEPRECATED => 'php_deprecated',
      E_USER_ERROR => 'php_error',
      E_USER_WARNING => 'php_warning',
      E_USER_NOTICE => 'php_notice',
      E_USER_DEPRECATED => 'php_deprecated',
    ];
    return $types[$type] ?? 'php_error';
  }

  /**
   * Map PHP error type to severity level matching the API schema.
   */
  protected function getSeverity(int $type): string {
    $map = [
      E_ERROR => 'fatal',
      E_USER_ERROR => 'error',
      E_WARNING => 'warning',
      E_USER_WARNING => 'warning',
      E_NOTICE => 'notice',
      E_USER_NOTICE => 'notice',
      E_DEPRECATED => 'notice',
      E_USER_DEPRECATED => 'notice',
    ];
    return $map[$type] ?? 'error';
  }

  /**
   * Get the module version.
   */
  protected function getModuleVersion(): string {
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('ttd_topics');
    return $info['version'] ?? 'unknown';
  }

}
