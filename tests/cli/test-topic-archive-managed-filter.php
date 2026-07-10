<?php

namespace Drupal\Core\Entity {
  interface EntityTypeManagerInterface {
    public function getStorage($entity_type_id);
    public function getDefinition($entity_type_id, $exception_on_invalid = TRUE);
  }
}

namespace Drupal\Core\Extension {
  interface ModuleHandlerInterface {
    public function moduleExists($module);
  }
}

namespace Drupal\Core\Session {
  interface AccountProxyInterface {
    public function hasPermission($permission);
  }
}

namespace Drupal\Core\Config {
  interface ConfigFactoryInterface {
    public function get($name);
  }
}

namespace Symfony\Component\EventDispatcher {
  interface EventSubscriberInterface {
    public static function getSubscribedEvents(): array;
  }
}

namespace Symfony\Component\HttpFoundation {
  class RequestStack {
    private $request;

    public function __construct($request = NULL) {
      $this->request = $request;
    }

    public function getCurrentRequest() {
      return $this->request;
    }
  }
}

namespace {
  use Drupal\Core\Config\ConfigFactoryInterface;
  use Drupal\Core\Entity\EntityTypeManagerInterface;
  use Drupal\Core\Extension\ModuleHandlerInterface;
  use Drupal\Core\Session\AccountProxyInterface;
  use Drupal\ttd_topics\EventSubscriber\SearchArchiveQuerySubscriber;
  use Drupal\ttd_topics\Service\SearchArchiveSetupManager;
  use Symfony\Component\HttpFoundation\RequestStack;

  class Drupal {
    public static $services = [];

    public static function service($name) {
      return self::$services[$name];
    }
  }

  class FakeModuleHandler implements ModuleHandlerInterface {
    private $available;

    public function __construct($available = TRUE) {
      $this->available = $available;
    }

    public function moduleExists($module) {
      return $this->available && in_array($module, ['search_api', 'views'], TRUE);
    }
  }

  class FakeCurrentUser implements AccountProxyInterface {
    private $allowed;

    public function __construct($allowed = TRUE) {
      $this->allowed = $allowed;
    }

    public function hasPermission($permission) {
      return $this->allowed && $permission === 'administer search_api';
    }
  }

  class FakeStorage {
    private $entities;

    public function __construct(array $entities) {
      $this->entities = $entities;
    }

    public function loadMultiple() {
      return $this->entities;
    }

    public function load($id) {
      return $this->entities[$id] ?? NULL;
    }
  }

  class FakeEntityTypeManager implements EntityTypeManagerInterface {
    private $storages;

    public function __construct(array $storages) {
      $this->storages = $storages;
    }

    public function getStorage($entity_type_id) {
      return $this->storages[$entity_type_id];
    }

    public function getDefinition($entity_type_id, $exception_on_invalid = TRUE) {
      return isset($this->storages[$entity_type_id]) ? (object) ['id' => $entity_type_id] : NULL;
    }
  }

  class FakeViewEntity {
    private $id;
    private $label;
    private $baseTable;
    private $display;

    public function __construct($id, $label, $base_table, array $display) {
      $this->id = $id;
      $this->label = $label;
      $this->baseTable = $base_table;
      $this->display = $display;
    }

    public function status() {
      return TRUE;
    }

    public function id() {
      return $this->id;
    }

    public function label() {
      return $this->label;
    }

    public function get($key) {
      return $key === 'base_table' ? $this->baseTable : $this->display;
    }
  }

  class FakeField {
    private $id;

    public function __construct($id) {
      $this->id = $id;
    }

    public function getDatasourceId() {
      return 'entity:node';
    }

    public function getPropertyPath() {
      return 'field_ttd_topics';
    }

    public function getType() {
      return 'integer';
    }

    public function getFieldIdentifier() {
      return $this->id;
    }

    public function setLabel($label) {
      return $this;
    }
  }

  class FakeIndex {
    private $id;
    private $fields = [];
    public $saveCount = 0;
    public $reindexCount = 0;

    public function __construct($id) {
      $this->id = $id;
    }

    public function id() {
      return $this->id;
    }

    public function label() {
      return 'News index';
    }

    public function status() {
      return TRUE;
    }

    public function getDatasources() {
      return ['entity:node' => new \stdClass()];
    }

    public function getFields() {
      return $this->fields;
    }

    public function getField($id) {
      return $this->fields[$id] ?? NULL;
    }

    public function getPropertyDefinitions($datasource_id) {
      return ['field_ttd_topics' => new \stdClass()];
    }

    public function addField($field) {
      $this->fields[$field->getFieldIdentifier()] = $field;
    }

    public function save() {
      $this->saveCount++;
    }

    public function reindex() {
      $this->reindexCount++;
    }
  }

  class FakeFieldsHelper {
    public function retrieveNestedProperty(array $properties, $property_path) {
      return $properties[$property_path] ?? NULL;
    }

    public function getNewFieldId($index, $property_path) {
      return $property_path . '_2';
    }

    public function createFieldFromProperty($index, $property, $datasource_id, $property_path, $field_id, $type) {
      return new FakeField($field_id);
    }
  }

  class FakeConfig {
    private $values;

    public function __construct(array $values) {
      $this->values = $values;
    }

    public function get($key) {
      return $this->values[$key] ?? NULL;
    }
  }

  class FakeConfigFactory implements ConfigFactoryInterface {
    private $config;

    public function __construct(array $values) {
      $this->config = new FakeConfig($values);
    }

    public function get($name) {
      return $this->config;
    }
  }

  class FakeQueryBag {
    private $values;

    public function __construct(array $values) {
      $this->values = $values;
    }

    public function get($key) {
      return $this->values[$key] ?? NULL;
    }
  }

  class FakeRequest {
    public $query;

    public function __construct(array $query) {
      $this->query = new FakeQueryBag($query);
    }
  }

  class FakeViewExecutable {
    public $storage;
    public $current_display;

    public function __construct($view_id, $display_id) {
      $this->storage = new class($view_id) {
        private $id;

        public function __construct($id) {
          $this->id = $id;
        }

        public function id() {
          return $this->id;
        }
      };
      $this->current_display = $display_id;
    }
  }

  class FakeSearchQuery {
    private $index;
    private $view;
    private $tags = [];
    public $conditions = [];
    public $cacheContexts = [];

    public function __construct($index, $view) {
      $this->index = $index;
      $this->view = $view;
    }

    public function getIndex() {
      return $this->index;
    }

    public function getOption($name) {
      return $name === 'search_api_view' ? $this->view : NULL;
    }

    public function hasTag($tag) {
      return isset($this->tags[$tag]);
    }

    public function addTag($tag) {
      $this->tags[$tag] = TRUE;
    }

    public function addCondition($field, $value, $operator) {
      $this->conditions[] = [$field, $value, $operator];
    }

    public function addCacheContexts(array $contexts) {
      $this->cacheContexts = array_merge($this->cacheContexts, $contexts);
    }
  }

  class FakeQueryEvent {
    private $query;

    public function __construct($query) {
      $this->query = $query;
    }

    public function getQuery() {
      return $this->query;
    }
  }

  function assert_managed($condition, $message) {
    if (!$condition) {
      fwrite(STDERR, "FAIL: $message\n");
      exit(1);
    }
    echo "PASS: $message\n";
  }

  require dirname(__DIR__, 2) . '/src/Service/SearchArchiveSetupManager.php';
  require dirname(__DIR__, 2) . '/src/EventSubscriber/SearchArchiveQuerySubscriber.php';

  $index = new FakeIndex('news');
  $view = new FakeViewEntity('news_archive', 'News archive', 'search_api_index_news', [
    'default' => [
      'display_plugin' => 'default',
      'display_title' => 'Default',
      'display_options' => [],
    ],
    'page_1' => [
      'display_plugin' => 'page',
      'display_title' => 'Archive page',
      'display_options' => ['path' => 'news-archive'],
    ],
  ]);
  $entity_manager = new FakeEntityTypeManager([
    'view' => new FakeStorage(['news_archive' => $view]),
    'search_api_index' => new FakeStorage(['news' => $index]),
  ]);
  Drupal::$services['search_api.fields_helper'] = new FakeFieldsHelper();

  $manager = new SearchArchiveSetupManager($entity_manager, new FakeModuleHandler(), new FakeCurrentUser());
  $candidates = $manager->getCandidates('/news-archive');
  assert_managed(isset($candidates['news_archive:page_1']), 'detects the Search API page View behind the archive path');
  assert_managed($manager->suggestCandidate('/news-archive') === 'news_archive:page_1', 'suggests the single exact path match');

  $unavailable_manager = new SearchArchiveSetupManager($entity_manager, new FakeModuleHandler(FALSE), new FakeCurrentUser());
  assert_managed($unavailable_manager->getCandidates('/news-archive') === [], 'offers no managed setup when Search API is unavailable');

  try {
    $manager->validateSelection('news_archive:page_1', '/different-archive');
    assert_managed(FALSE, 'rejects a View whose path does not match the archive path');
  }
  catch (RuntimeException $e) {
    assert_managed(strpos($e->getMessage(), 'does not match') !== FALSE, 'rejects a View whose path does not match the archive path');
  }

  $setup = $manager->prepare('news_archive:page_1', '/news-archive', TRUE);
  assert_managed($setup['field_added'] && $setup['field_id'] === 'ttd_topic_ids', 'adds one integer TopicalBoost topic ID field');
  assert_managed($index->saveCount === 1 && $index->reindexCount === 1, 'saves and queues only the selected index');
  $setup = $manager->prepare('news_archive:page_1', '/news-archive', FALSE);
  assert_managed(!$setup['field_added'] && !$setup['reindex_queued'] && $index->reindexCount === 1, 'repeated setup does not queue another reindex');

  try {
    (new SearchArchiveSetupManager($entity_manager, new FakeModuleHandler(), new FakeCurrentUser(FALSE)))
      ->validateSelection('news_archive:page_1', '/news-archive');
    assert_managed(FALSE, 'rejects setup without Search API administration permission');
  }
  catch (RuntimeException $e) {
    assert_managed(strpos($e->getMessage(), 'Administer Search API') !== FALSE, 'rejects setup without Search API administration permission');
  }

  $config = [
    'topic_url_mode' => 'archive_query',
    'topic_archive_managed_filter' => TRUE,
    'topic_archive_value_source' => 'term_id',
    'topic_archive_value_template' => '[value]',
    'topic_archive_view' => 'news_archive:page_1',
    'topic_archive_index' => 'news',
    'topic_archive_index_field' => 'ttd_topic_ids',
    'topic_archive_query_parameter' => 'ttd_topic',
  ];
  $query = new FakeSearchQuery($index, new FakeViewExecutable('news_archive', 'page_1'));
  $subscriber = new SearchArchiveQuerySubscriber(
    new FakeConfigFactory($config),
    new RequestStack(new FakeRequest(['ttd_topic' => '42']))
  );
  $subscriber->onQueryPreExecute(new FakeQueryEvent($query));
  assert_managed($query->conditions === [['ttd_topic_ids', 42, '=']], 'applies the numeric topic ID only to the selected archive query');
  assert_managed($query->cacheContexts === ['url.query_args:ttd_topic'], 'adds a cache context for the managed URL parameter');

  $wrong_view_query = new FakeSearchQuery($index, new FakeViewExecutable('other_view', 'page_1'));
  $subscriber->onQueryPreExecute(new FakeQueryEvent($wrong_view_query));
  assert_managed($wrong_view_query->conditions === [], 'does not alter other Views on the same index');

  $invalid_query = new FakeSearchQuery($index, new FakeViewExecutable('news_archive', 'page_1'));
  $invalid_subscriber = new SearchArchiveQuerySubscriber(
    new FakeConfigFactory($config),
    new RequestStack(new FakeRequest(['ttd_topic' => '42 OR 1=1']))
  );
  $invalid_subscriber->onQueryPreExecute(new FakeQueryEvent($invalid_query));
  assert_managed($invalid_query->conditions === [], 'rejects non-numeric topic query values');

  echo "All managed topic archive filter tests passed.\n";
}
