<?php

namespace Drupal\Core\Controller {
  class ControllerBase {}
}
namespace GuzzleHttp\Exception {
  class RequestException extends \Exception {
    public function __construct(private $response) { parent::__construct('Provider unavailable'); }
    public function hasResponse() { return $this->response !== NULL; }
    public function getResponse() { return $this->response; }
  }
}
namespace {
  require __DIR__ . '/../../src/Controller/TtdTopicsController.php';
  $controller = new \Drupal\ttd_topics\Controller\TtdTopicsController();
  $method = new \ReflectionMethod($controller, 'getDemandMetricsRetryAfter');
  $method->setAccessible(TRUE);
  $error = static function ($status, $body) {
    $response = new class($status, $body) {
      public function __construct(private $status, private $body) {}
      public function getStatusCode() { return $this->status; }
      public function getBody() { return $this->body; }
    };
    return new \GuzzleHttp\Exception\RequestException($response);
  };
  $assertions = 0;
  $assert = static function ($condition) use (&$assertions) {
    $assertions++;
    if (!$condition) { throw new \RuntimeException('Demand budget retry compatibility failed.'); }
  };
  $body = json_encode(['error' => 'External API error', 'message' => 'SEO metrics temporarily unavailable', 'retry_after_seconds' => 1800]);
  foreach ([429, 503] as $status) {
    $assert($method->invoke($controller, $error($status, $body)) === 1800);
    $assert($method->invoke($controller, $error($status, '{"error":"Other error"}')) === NULL);
  }
  $assert($method->invoke($controller, $error(500, $body)) === NULL);
  $assert($method->invoke($controller, new \RuntimeException('No HTTP response')) === NULL);
  $assert($method->invoke($controller, new \GuzzleHttp\Exception\RequestException(NULL)) === NULL);
  fwrite(STDOUT, "PASS $assertions Drupal demand budget retry checks.\n");
}
