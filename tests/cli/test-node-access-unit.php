<?php

/**
 * Editor routes check only a site-wide permission. Each handler that takes a
 * node must also check access to that node before it reads or changes it.
 * Runs the real controllers against small stubs, without a Drupal bootstrap.
 * Run with: php tests/cli/test-node-access-unit.php
 */

namespace Drupal\Core\Controller {
  abstract class ControllerBase {
    protected function t($string, array $args = []) {
      return $string;
    }
  }
}

namespace Drupal\Core\Database {
  class Connection {}
}

namespace Drupal\node {
  interface NodeInterface {}
}

namespace Symfony\Component\HttpFoundation {
  class JsonResponse {
    public $data;
    public $status;

    public function __construct($data = NULL, $status = 200) {
      $this->data = $data;
      $this->status = $status;
    }

    public function getStatusCode() {
      return $this->status;
    }
  }

  class Bag {
    private array $values;

    public function __construct(array $values = []) {
      $this->values = $values;
    }

    public function get($key, $default = NULL) {
      return $this->values[$key] ?? $default;
    }
  }

  class Request {
    public Bag $headers;
    public Bag $query;
    public Bag $request;
    private string $content;

    public function __construct(array $body = [], array $query = []) {
      $this->headers = new Bag(['X-CSRF-Token' => 'valid']);
      $this->query = new Bag($query);
      $this->request = new Bag($body);
      $this->content = json_encode($body);
    }

    public function getContent() {
      return $this->content;
    }
  }
}

namespace {
  use Drupal\node\NodeInterface;
  use Symfony\Component\HttpFoundation\Request;

  class TtdFakeField {
    public $value = NULL;

    public function getValue() {
      $GLOBALS['ttd_reads'][] = 'field';
      return [];
    }

    public function isEmpty() {
      return TRUE;
    }

    public function referencedEntities() {
      $GLOBALS['ttd_reads'][] = 'referenced';
      return [];
    }
  }

  class TtdFakeNode implements NodeInterface {
    private int $id;

    public function __construct(int $id) {
      $this->id = $id;
    }

    public function id() {
      return $this->id;
    }

    public function access($operation) {
      $GLOBALS['ttd_access_checks'][] = $operation . ':' . $this->id;
      return in_array($this->id, $GLOBALS['ttd_access'][$operation] ?? [], TRUE);
    }

    public function hasField($name) {
      return TRUE;
    }

    public function get($name) {
      $GLOBALS['ttd_reads'][] = 'get:' . $this->id . ':' . $name;
      $field = new TtdFakeField();
      if ($name === 'field_ttd_analysis_in_progress') { $field->value = $GLOBALS['ttd_analysis_running'] ?? FALSE; }
      return $field;
    }

    public function set($name, $value) {
      $GLOBALS['ttd_writes'][] = 'set:' . $this->id . ':' . $name;
    }

    public function save() {
      $GLOBALS['ttd_writes'][] = 'save:' . $this->id;
    }
  }

  class TtdFakeStorage {
    public function load($id) {
      return in_array((int) $id, [5, 9], TRUE) ? new TtdFakeNode((int) $id) : NULL;
    }

    public function loadMultiple(array $ids) {
      return array_filter(array_map([$this, 'load'], $ids));
    }
  }

  class TtdFakeQuery {
    public function __call($name, $args) {
      return $this;
    }

    public function execute() {
      return new class {
        public function fetchAll() {
          return [];
        }

        public function fetchField() {
          return FALSE;
        }

        public function fetchCol() {
          return [];
        }
      };
    }
  }

  class Drupal {
    public static function entityTypeManager() {
      return new class {
        public function getStorage($type) {
          return new TtdFakeStorage();
        }
      };
    }

    public static function csrfToken() {
      return new class {
        public function validate($token, $value) {
          return $token === 'valid';
        }
      };
    }

    public static function database() {
      return new class {
        public function select(...$args) {
          return new TtdFakeQuery();
        }

        public function escapeLike($value) {
          return $value;
        }

        public function insert(...$args) {
          $GLOBALS['ttd_writes'][] = 'database_insert';
          return new TtdFakeQuery();
        }
      };
    }

    public static function logger($channel) {
      return new class {
        public function __call($name, $args) {}
      };
    }

    public static function state() {
      return new class {
        public function get($key) {
          return $GLOBALS['ttd_analysis_request'] ?? NULL;
        }

        public function __call($name, $args) {
          $GLOBALS['ttd_writes'][] = 'state:' . $name;
        }
      };
    }

    public static function service($name) {
      return new class {
        public function __call($method, $args) {
          $GLOBALS['ttd_writes'][] = 'service:' . $method;
          return $method === 'isAnalysisReady' ? TRUE : NULL;
        }
      };
    }
  }

  $root = dirname(__DIR__, 2);
  require $root . '/src/Controller/MetaGeneratorController.php';
  require $root . '/src/Controller/TtdTopicsController.php';
  require $root . '/src/Controller/TroubleshootController.php';

  $failures = 0;
  $assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
      fwrite(STDERR, "FAIL: {$message}\n");
      $failures++;
      return;
    }
    fwrite(STDOUT, "PASS: {$message}\n");
  };

  $run = static function (callable $handler) {
    $GLOBALS['ttd_writes'] = [];
    $GLOBALS['ttd_reads'] = [];
    $GLOBALS['ttd_access_checks'] = [];
    return $handler();
  };

  $meta = new \Drupal\ttd_topics\Controller\MetaGeneratorController();
  $topics = new \Drupal\ttd_topics\Controller\TtdTopicsController();
  $troubleshoot = new \Drupal\ttd_topics\Controller\TroubleshootController(new \Drupal\Core\Database\Connection());

  // The caller may view and update node 5, but has no access to node 9.
  $GLOBALS['ttd_access'] = ['view' => [5], 'update' => [5]];
  $other = 9;
  $meta_body = [
    'node_id' => $other,
    'keywords' => ['Topic'],
    'meta_title' => 'Title',
    'meta_description' => 'Description',
  ];

  $denied_cases = [
    'getKeywords' => fn() => $meta->getKeywords(new TtdFakeNode($other)),
    'generate' => fn() => $meta->generate(new Request($meta_body)),
    'save' => fn() => $meta->save(new Request($meta_body)),
    'generateSocial' => fn() => $meta->generateSocial(new Request($meta_body)),
    'saveSocial' => fn() => $meta->saveSocial(new Request($meta_body)),
    'clearSocial' => fn() => $meta->clearSocial(new Request($meta_body)),
    'discardPending' => fn() => $meta->discardPending(new Request($meta_body)),
    'clearMeta' => fn() => $meta->clearMeta(new Request($meta_body)),
    'updateTopicTier' => fn() => $topics->updateTopicTier(new Request(['node_id' => $other, 'ttd_id' => 101, 'new_tier' => 'about'])),
    'removeTopicTierOverride' => fn() => $topics->removeTopicTierOverride(new Request(['node_id' => $other, 'ttd_id' => 101])),
    'updateTopics' => fn() => $topics->updateTopics(new Request(['node_id' => $other, 'topic_id' => 3, 'add_manual' => TRUE])),
    'runAnalysis' => fn() => $topics->runAnalysis(new TtdFakeNode($other), new Request()),
    'createTopicTerm' => fn() => $topics->createTopicTerm(new Request([
      'topic_data' => ['name' => 'Topic', 'ttd_id' => 101],
      'post_id' => $other,
      'add_to_post' => TRUE,
    ])),
    'handleStuckPosts' => fn() => $troubleshoot->handleStuckPosts(new Request(['post_ids' => [5, $other], 'operation' => 'clear'])),
    'clearPollingFlag' => fn() => $troubleshoot->clearPollingFlag(new Request(['node_id' => $other])),
    'clearRejectedTopics' => fn() => $troubleshoot->clearRejectedTopics(new Request(['post_id' => $other])),
  ];

  foreach ($denied_cases as $name => $handler) {
    $response = $run($handler);
    $assert($response->getStatusCode() === 403, "{$name} returns 403 without access to the selected node");
    $assert($GLOBALS['ttd_writes'] === [], "{$name} changes nothing without node access");
    $assert($GLOBALS['ttd_reads'] === [], "{$name} reads no node fields without node access");
  }

  $response = $run(fn() => $topics->searchTopics(new Request([], ['q' => 'topic', 'node_id' => $other])));
  $assert($response->getStatusCode() === 200, 'Topic search still answers when the caller cannot view the node');
  $assert($GLOBALS['ttd_reads'] === [] && in_array('view:' . $other, $GLOBALS['ttd_access_checks'], TRUE), 'Topic search does not read topics from a node the caller cannot view');

  // The same caller keeps working on a node they can update.
  $response = $run(fn() => $meta->discardPending(new Request(['node_id' => 5])));
  $assert($response->getStatusCode() === 200 && in_array('save:5', $GLOBALS['ttd_writes'], TRUE), 'An editor can discard pending meta on an updatable node');
  $response = $run(fn() => $troubleshoot->clearRejectedTopics(new Request(['post_id' => 5])));
  $assert($response->getStatusCode() === 200 && in_array('save:5', $GLOBALS['ttd_writes'], TRUE), 'An editor can clear rejected topics on an updatable node');
  $response = $run(fn() => $meta->getKeywords(new TtdFakeNode(5)));
  $assert($response->getStatusCode() === 200, 'An editor can read keywords for a viewable node');

  $GLOBALS['ttd_access'] = ['view' => [5], 'update' => []];
  $GLOBALS['ttd_analysis_running'] = TRUE;
  $GLOBALS['ttd_analysis_request'] = ['request_id' => 'synthetic-request'];
  $response = $run(fn() => $topics->checkAnalysisStatus(5));
  $assert($response->getStatusCode() === 403 && !$GLOBALS['ttd_writes'], 'A viewer cannot apply ready analysis results through status polling');
  $GLOBALS['ttd_access']['update'] = [5];
  $response = $run(fn() => $topics->checkAnalysisStatus(5));
  $assert($response->getStatusCode() === 200 && in_array('save:5', $GLOBALS['ttd_writes'], TRUE), 'An editor can complete analysis through status polling');

  $source = file_get_contents($root . '/src/Controller/TtdTopicsController.php');
  $assert(substr_count($source, "\$node && \$node->access('view') && \$node->hasField('field_ttd_topics')") === 2, 'Topic search and API lookup only mark topics from viewable nodes');

  if ($failures > 0) {
    exit(1);
  }

  fwrite(STDOUT, "Drupal node access checks passed.\n");
}
