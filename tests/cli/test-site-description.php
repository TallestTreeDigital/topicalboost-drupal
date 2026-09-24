<?php

/**
 * Checks that an empty site slogan does not produce a schema placeholder.
 *
 * Run from the module root:
 *   php tests/cli/test-site-description.php
 */

class Drupal {
  public static ?string $slogan = NULL;

  public static function config(string $name): object {
    return new class($name) {
      public function __construct(private string $name) {}

      public function get(string $key): ?string {
        if ($this->name === 'system.site') {
          return $key === 'name' ? 'Example Site' : Drupal::$slogan;
        }
        return NULL;
      }
    };
  }

  public static function request(): object {
    return new class {
      public function getSchemeAndHttpHost(): string {
        return 'https://example.test';
      }
    };
  }

  public static function entityTypeManager(): object {
    return new class {
      public function getStorage(string $type): object {
        return new class {
          public function load($id): ?object {
            return NULL;
          }
        };
      }
    };
  }
}

require_once dirname(__DIR__, 2) . '/src/SchemaGenerator.php';

$generator = new class extends \Drupal\ttd_topics\SchemaGenerator {
  public function __construct() {}

  protected function getLogoUrl($base_url, $config) {
    return $base_url . '/logo.png';
  }
};

foreach ([NULL, '', '  ', "Your organization's mission"] as $slogan) {
  Drupal::$slogan = $slogan;
  $graph = $generator->getNodeTopicsSchema(1)['@graph'];
  $website = array_values(array_filter($graph, fn(array $item): bool => $item['@type'] === 'WebSite'))[0];
  if (isset($website['description'])) {
    fwrite(STDERR, "FAIL: Empty or placeholder slogan produced a description\n");
    exit(1);
  }
}

Drupal::$slogan = 'A real site slogan';
$graph = $generator->getNodeTopicsSchema(1)['@graph'];
$website = array_values(array_filter($graph, fn(array $item): bool => $item['@type'] === 'WebSite'))[0];
if (($website['description'] ?? NULL) !== Drupal::$slogan) {
  fwrite(STDERR, "FAIL: Configured slogan was not used\n");
  exit(1);
}

echo "Drupal site description checks passed.\n";
