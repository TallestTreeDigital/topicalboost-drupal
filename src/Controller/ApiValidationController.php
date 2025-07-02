<?php

namespace Drupal\ttd_topics\Controller;

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
   * Constructs a new ApiValidationController object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('logger.factory')
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

    // Get the API endpoint from configuration.
    $config = $this->config('ttd_topics.settings');
    $api_endpoint = $config->get('topicalboost_api_endpoint') ?: 'https://topics-api.tallesttree.digital';

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
        ],
        'timeout' => 10,
      ]);

      $response_data = json_decode($response->getBody()->getContents(), TRUE);

      // Return the response from the external API.
      return new JsonResponse([
        'valid' => $response_data['valid'] ?? FALSE,
        'site_name' => $response_data['site_name'] ?? '',
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

      return new JsonResponse([
        'valid' => FALSE,
        'error' => $error_message,
      ], 500);
    }
  }

}
