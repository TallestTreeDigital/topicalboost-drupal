<?php

namespace Drupal\ttd_topics\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for API validation operations.
 */
class ApiValidationController extends ControllerBase {

  /**
   * The HTTP client to fetch data.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new ApiValidationController object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('config.factory')
    );
  }

  /**
   * Validates an API key by calling the external API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with validation results.
   */
  public function validateApiKey(Request $request) {
    // Get the API key from the request.
    $data = json_decode($request->getContent(), TRUE);
    $api_key = $data['api_key'] ?? '';

    if (empty($api_key)) {
      return new JsonResponse([
        'valid' => FALSE,
        'error' => 'API key is required',
      ], 400);
    }

    // Get the API endpoint from the constant (which respects local-config.php overrides).
    $api_endpoint = defined('TOPICALBOOST_API_ENDPOINT') ? TOPICALBOOST_API_ENDPOINT : 'https://api.topicalboost.com';

    // Prepare the external API endpoint.
    $endpoint = rtrim($api_endpoint, '/') . '/validate-api-key';

    try {
      // Make the request to the external API.
      $response = $this->httpClient->post($endpoint, [
        'headers' => [
          'Content-Type' => 'application/json',
          'x-api-key' => $api_key,
        ],
        'json' => [
          'api_key' => $api_key,
          'site_url' => $request->getSchemeAndHttpHost(),
        ],
        'timeout' => 10,
      ]);

      $response_data = json_decode($response->getBody()->getContents(), TRUE);
      $this->persistValidationStatus($api_key, $response_data ?: [], $request->getHost());

      // Return the response from the external API.
      return new JsonResponse([
        'valid' => $response_data['valid'] ?? FALSE,
        'site_name' => $response_data['site_name'] ?? '',
        'subscription_status' => $response_data['subscription_status'] ?? '',
        'domain_mismatch' => !empty($response_data['domain_mismatch']),
        'registered_domain' => $response_data['registered_domain'] ?? '',
        'registered_environment' => $response_data['registered_environment'] ?? '',
        'error' => $response_data['error'] ?? '',
      ]);

    }
    catch (\Exception $e) {
      // Log the error.
      $this->loggerFactory->get('ttd_topics')->error('API key validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      // Return error response.
      $error_message = 'Validation failed';

      if (strpos($e->getMessage(), '404') !== FALSE) {
        $error_message = 'Endpoint not found';
      }
      elseif (strpos($e->getMessage(), 'cURL error') !== FALSE) {
        $error_message = 'Network error';
      }
      elseif (strpos($e->getMessage(), 'CORS') !== FALSE) {
        $error_message = 'CORS error';
      }

      $config = $this->configFactory->get('ttd_topics.settings');
      $cached_hash = (string) $config->get('api_key_validation_hash');
      $cached_valid = (bool) $config->get('api_key_validated');
      if ($cached_valid && $cached_hash !== '' && hash_equals($cached_hash, hash('sha256', $api_key))) {
        $domain_mismatch = $config->get('domain_mismatch') ?: [];
        return new JsonResponse([
          'valid' => TRUE,
          'site_name' => 'Saved key',
          'subscription_status' => $config->get('subscription_status') ?: '',
          'domain_mismatch' => !empty($domain_mismatch),
          'registered_domain' => $domain_mismatch['registered_domain'] ?? '',
          'registered_environment' => $domain_mismatch['registered_environment'] ?? '',
          'warning' => $error_message,
        ]);
      }

      return new JsonResponse([
        'valid' => FALSE,
        'error' => $error_message,
      ], 500);
    }
  }

  /**
   * Persists a validation response for later admin notices.
   *
   * @param string $api_key
   *   API key that was validated.
   * @param array $response_data
   *   Decoded response data.
   * @param string $current_domain
   *   Current request host.
   */
  protected function persistValidationStatus($api_key, array $response_data, $current_domain = '') {
    $config = $this->configFactory->getEditable('ttd_topics.settings');
    $valid = !empty($response_data['valid']);

    $config
      ->set('api_key_validated', $valid)
      ->set('api_key_validation_hash', hash('sha256', $api_key));

    if (!$valid) {
      $config
        ->set('subscription_status', '')
        ->set('domain_mismatch', [])
        ->save();
      return;
    }

    if (!empty($response_data['subscription_status'])) {
      $config->set('subscription_status', strtoupper(trim((string) $response_data['subscription_status'])));
    }

    if (!empty($response_data['domain_mismatch'])) {
      $registered_domain = trim((string) ($response_data['registered_domain'] ?? ''));
      if ($registered_domain !== '' && $this->domainsMatch($registered_domain, $current_domain)) {
        $config->set('domain_mismatch', []);
      }
      else {
        $config->set('domain_mismatch', [
          'registered_domain' => $registered_domain,
          'registered_environment' => trim((string) ($response_data['registered_environment'] ?? '')),
        ]);
      }
    }
    else {
      $config->set('domain_mismatch', []);
    }

    $config->save();
  }

  /**
   * Compares host names from plain domains or URLs.
   */
  protected function domainsMatch($left, $right) {
    $left = $this->normalizeDomainForCompare($left);
    $right = $this->normalizeDomainForCompare($right);

    return $left !== '' && $right !== '' && $left === $right;
  }

  /**
   * Normalizes a domain or URL for comparison.
   */
  protected function normalizeDomainForCompare($value) {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
      return '';
    }

    if (!preg_match('/^https?:\/\//i', $value)) {
      $value = 'https://' . $value;
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (!$host) {
      return '';
    }

    return preg_replace('/^www\./', '', strtolower($host));
  }

}
