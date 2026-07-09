<?php

namespace Drupal\Core {

  class Url {
    protected string $uri;
    protected array $options;

    public function __construct(string $uri, array $options = []) {
      $this->uri = $uri;
      $this->options = $options;
    }

    public static function fromUri($uri, $options = []) {
      return new static($uri, $options);
    }

    public function setAbsolute($absolute = TRUE) {
      $this->options['absolute'] = $absolute;
      return $this;
    }

    public function toString($collect_bubbleable_metadata = FALSE) {
      $base = $this->uri;
      if (str_starts_with($base, 'internal:')) {
        $base = substr($base, strlen('internal:'));
        if (($this->options['absolute'] ?? FALSE) === TRUE) {
          $base = 'https://example.test' . $base;
        }
      }

      $query = $this->options['query'] ?? [];
      if (!empty($query)) {
        $base .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
      }

      if (!empty($this->options['fragment'])) {
        $base .= '#' . $this->options['fragment'];
      }

      return $base;
    }
  }
}

namespace Drupal\taxonomy {

  interface TermInterface {}

}

namespace {

  class Drupal {
    public static array $settings = [];
    public static bool $searchApiEnabled = TRUE;

    public static function config($name) {
      return new class(self::$settings) {
        public function __construct(protected array $settings) {}

        public function get($key = '') {
          return $this->settings[$key] ?? NULL;
        }
      };
    }

    public static function languageManager() {
      return new class {
        public function getCurrentLanguage() {
          return new class {
            public function getId() {
              return 'en';
            }
          };
        }
      };
    }

    public static function moduleHandler() {
      return new class {
        public function moduleExists($name) {
          return $name === 'search_api' ? \Drupal::$searchApiEnabled : FALSE;
        }
      };
    }

    public static function service($id) {
      if ($id === 'transliteration') {
        return new class {
          public function transliterate($string, $langcode = 'en', $unknown_character = '?') {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $string);
            return $transliterated === FALSE ? $string : $transliterated;
          }
        };
      }

      throw new \RuntimeException("Unexpected service request: $id");
    }

    public static function logger($channel) {
      return new class {
        public function warning($message, array $context = []) {}
      };
    }
  }

  class FakeField {
    public function __construct(public ?string $value) {}

    public function isEmpty() {
      return $this->value === NULL || $this->value === '';
    }
  }

  class FakeTerm implements \Drupal\taxonomy\TermInterface {
    public function __construct(
      protected int $id,
      protected string $label,
      protected string $uuid,
      protected ?string $ttdId = NULL,
    ) {}

    public function id() {
      return $this->id;
    }

    public function label() {
      return $this->label;
    }

    public function getName() {
      return $this->label;
    }

    public function uuid() {
      return $this->uuid;
    }

    public function hasField($field_name) {
      return $field_name === 'field_ttd_id';
    }

    public function get($field_name) {
      return new FakeField($field_name === 'field_ttd_id' ? $this->ttdId : NULL);
    }

    public function toUrl($rel = 'canonical', array $options = []) {
      $url = \Drupal\Core\Url::fromUri('internal:/taxonomy/term/' . $this->id);
      if (!empty($options['absolute'])) {
        $url->setAbsolute();
      }
      return $url;
    }
  }

  function set_topic_url_settings(array $overrides = [], bool $search_api_enabled = TRUE): void {
    \Drupal::$searchApiEnabled = $search_api_enabled;
    \Drupal::$settings = $overrides + [
      'topic_url_mode' => 'taxonomy_term',
      'topic_archive_path' => '',
      'topic_archive_query_parameter' => 'topic',
      'topic_archive_value_source' => 'term_id',
      'topic_archive_value_template' => '[value]',
      'use_beta_api' => FALSE,
    ];
  }

  function assert_same($expected, $actual, $message): void {
    if ($expected !== $actual) {
      fwrite(STDERR, "FAIL: $message\nExpected: $expected\nActual:   $actual\n");
      exit(1);
    }
    echo "PASS: $message\n";
  }

  set_topic_url_settings();
  require dirname(__DIR__, 2) . '/ttd_topics.module';

  $term = new FakeTerm(42, 'Economic Growth', 'uuid-42', '999');

  set_topic_url_settings();
  assert_same('/taxonomy/term/42', ttd_topics_get_topic_url($term), 'taxonomy mode falls back to the canonical term URL');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/research',
  ], FALSE);
  assert_same('/taxonomy/term/42', ttd_topics_get_topic_url($term), 'archive mode falls back when Search API is not enabled');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/research',
  ]);
  assert_same('/research?topic=42', ttd_topics_get_topic_url($term), 'archive mode builds a simple internal query URL');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/research?sort=new#results',
  ]);
  assert_same('/research?sort=new&topic=42#results', ttd_topics_get_topic_url($term), 'archive URLs preserve existing query strings and fragments');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/archive',
    'topic_archive_query_parameter' => 'f[0]',
    'topic_archive_value_template' => 'field_ttd_topics:[value]',
  ]);
  assert_same('/archive?f%5B0%5D=field_ttd_topics%3A42', ttd_topics_get_topic_url($term), 'Facets-style nested query parameters and value prefixes are supported');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => 'https://example.org/news',
    'topic_archive_value_source' => 'ttd_id',
  ]);
  assert_same('https://example.org/news?topic=999', ttd_topics_get_topic_url($term), 'absolute archive URLs can use TopicalBoost topic IDs');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/topics',
    'topic_archive_value_source' => 'term_slug',
  ]);
  assert_same('/topics?topic=economic-growth', ttd_topics_get_topic_url($term), 'term slug values are generated from labels');

  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/research',
  ]);
  assert_same('https://example.test/research?topic=42', ttd_topics_get_topic_url($term, TRUE), 'internal archive URLs can be absolute');

  $missing_ttd_id = new FakeTerm(43, 'Missing ID', 'uuid-43', NULL);
  set_topic_url_settings([
    'topic_url_mode' => 'archive_query',
    'topic_archive_path' => '/research',
    'topic_archive_value_source' => 'ttd_id',
    'topic_archive_value_template' => 'field_ttd_topics:[value]',
  ]);
  assert_same('/taxonomy/term/43', ttd_topics_get_topic_url($missing_ttd_id), 'missing configured values fall back to canonical term URLs before value patterns are applied');

  echo "All topic archive link tests passed.\n";
}
