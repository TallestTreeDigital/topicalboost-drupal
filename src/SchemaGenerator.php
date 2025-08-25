<?php

namespace Drupal\ttd_topics;

use Drupal\Core\Database\Connection;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Service for generating schema.org metadata for TopicalBoost.
 */
class SchemaGenerator {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * Constructs a new SchemaGenerator.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   */
  public function __construct(
    Connection $database,
    AliasManagerInterface $alias_manager,
  ) {
    $this->database = $database;
    $this->aliasManager = $alias_manager;
  }

  /**
   * Gets schema.org metadata for a node's TopicalBoost topics.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   The schema.org metadata array.
   */
  public function getNodeTopicsSchema($nid) {
    $config = \Drupal::config('ttd_topics.settings');
    $min_display_count = $config->get('post_topic_minimum_display_count');

    // Get site configuration - all dynamic.
    $site_config = \Drupal::config('system.site');
    $site_name = $site_config->get('name') ?: 'Your Organization';
    $site_slogan = $site_config->get('slogan') ?: 'Your organization\'s mission';

    // Get base URL dynamically.
    $base_url = \Drupal::request()->getSchemeAndHttpHost();

    // Determine logo URL with priority system.
    $logo_url = $this->getLogoUrl($base_url, $config);

    // Build sameAs array from admin configuration.
    $same_as = [];
    if ($wikipedia_url = $config->get('organization_wikipedia_url')) {
      $same_as[] = $wikipedia_url;
    }
    if ($facebook_url = $config->get('organization_facebook_url')) {
      $same_as[] = $facebook_url;
    }
    if ($twitter_url = $config->get('organization_twitter_url')) {
      $same_as[] = $twitter_url;
    }
    if ($linkedin_url = $config->get('organization_linkedin_url')) {
      $same_as[] = $linkedin_url;
    }
    if ($youtube_url = $config->get('organization_youtube_url')) {
      $same_as[] = $youtube_url;
    }

    // Create the @graph structure.
    $data = [
      '@context' => 'https://schema.org',
      '@graph' => [],
    ];

    // Add Organization schema - all dynamic.
    $organization = [
      '@type' => 'Organization',
      '@id' => $base_url . '/#organization',
      'name' => $site_name,
      'url' => $base_url,
      'logo' => [
        '@type' => 'ImageObject',
        'inLanguage' => 'en-US',
        '@id' => $logo_url . '#logo',
        'url' => $logo_url,
        'contentUrl' => $logo_url,
        'caption' => $site_name,
      ],
      'image' => [
        '@id' => $logo_url . '#logo',
      ],
    ];

    // Only add sameAs if there are configured social media links.
    if (!empty($same_as)) {
      $organization['sameAs'] = $same_as;
    }

    $data['@graph'][] = $organization;

    // Add WebSite schema - all dynamic.
    $website = [
      '@type' => 'WebSite',
      '@id' => $base_url . '/#website',
      'url' => $base_url,
    // Extract hostname dynamically.
      'name' => parse_url($base_url, PHP_URL_HOST),
      'publisher' => [
        '@id' => $base_url . '/#organization',
      ],
      'inLanguage' => 'en-US',
    ];

    // Only add description if slogan exists.
    if (!empty($site_slogan)) {
      $website['description'] = $site_slogan;
    }

    $data['@graph'][] = $website;

    // Add primary ImageObject schema - dynamic.
    $primary_image = [
      '@type' => 'ImageObject',
      'inLanguage' => 'en-US',
      '@id' => $logo_url . '#primaryimage',
      'url' => $logo_url,
      'contentUrl' => $logo_url,
    ];

    $data['@graph'][] = $primary_image;

    // Get rejected TTD IDs first.
    $rejected_ttd_ids = $this->getRejectedTopics($nid);

    // Reset query for full data.
    $query = $this->database->select('node__field_ttd_topics', 'ti');
    $query->join('taxonomy_term_field_data', 't', 't.tid = ti.field_ttd_topics_target_id');
    $query->leftJoin('taxonomy_term__field_ttd_id', 'ttd', 'ttd.entity_id = t.tid');
    $query->leftJoin('taxonomy_term__field_hide', 'h', 'h.entity_id = t.tid');
    $query->leftJoin('path_alias', 'pa', "pa.path = CONCAT('/taxonomy/term/', t.tid) AND pa.status = 1");

    // Add subquery to count posts per term.
    $subquery = $this->database->select('node__field_ttd_topics', 'ti2')
      ->fields('ti2', ['field_ttd_topics_target_id'])
      ->groupBy('field_ttd_topics_target_id');
    $subquery->addExpression('COUNT(DISTINCT ti2.entity_id)', 'post_count');

    $query->leftJoin($subquery, 'pc', 'pc.field_ttd_topics_target_id = t.tid');

    $query->fields('t', ['tid', 'name'])
      ->fields('ttd', ['field_ttd_id_value'])
      ->fields('h', ['field_hide_value'])
      ->fields('pa', ['alias'])
      ->fields('pc', ['post_count'])
      ->condition('ti.entity_id', $nid)
      ->condition('t.vid', 'ttd_topics')
      ->condition('ti.deleted', 0);

    // Only add NOT IN condition if there are rejected topics.
    if (!empty($rejected_ttd_ids)) {
      $query->condition('ttd.field_ttd_id_value', $rejected_ttd_ids, 'NOT IN');
    }

    $query->orderBy('pc.post_count', 'DESC');

    $topics = $query->execute()->fetchAll();

    // Create Article schema with mentions.
    $article = [
      '@type' => 'Article',
    ];

    if (!empty($topics)) {
      $article['mentions'] = [];

      // Sort topics by post count in descending order.
      usort($topics, function ($a, $b) {
        return $b->post_count - $a->post_count;
      });

      foreach ($topics as $topic) {
        // Skip hidden topics.
        if (!empty($topic->field_hide_value)) {
          continue;
        }

        // Skip topics below threshold.
        if ($topic->post_count < $min_display_count) {
          continue;
        }

        $entity = $this->getEntityData($topic->field_ttd_id_value);
        if (!$entity) {
          continue;
        }

        $schema_types = $this->getEntitySchemaTypes($topic->field_ttd_id_value);
        if (empty($schema_types)) {
          // Default to Thing if no schema types found.
          $schema_types = ['Thing'];
        }

        $output_data = [
          '@type' => count($schema_types) > 1 ? $schema_types : $schema_types[0],
          'name' => $topic->name,
          'url' => !empty($entity['official_website']) ? $entity['official_website'] : $base_url . $topic->alias,
        ];

        // Format the entity data.
        $output_data = $this->formatEntityData($output_data, $entity, $schema_types[0]);

        // Add to mentions array.
        $article['mentions'][] = $output_data;
      }
    }

    // Only add the Article schema if there are mentions.
    if (!empty($article['mentions'])) {
      $data['@graph'][] = $article;
    }

    return $data;
  }

  /**
   * Gets rejected topics for a node.
   */
  protected function getRejectedTopics($nid) {
    // First get the rejected term IDs.
    $rejected_tids = [];
    $result = $this->database->select('node__field_ttd_rejected_topics', 'rt')
      ->fields('rt', ['field_ttd_rejected_topics_target_id'])
      ->condition('entity_id', $nid)
      ->execute();
    foreach ($result as $record) {
      $rejected_tids[] = $record->field_ttd_rejected_topics_target_id;
    }

    // Then get the TTD IDs for those terms.
    if (!empty($rejected_tids)) {
          $result = $this->database->select('taxonomy_term__field_ttd_id', 'ttd')
      ->fields('ttd', ['field_ttd_id_value'])
        ->condition('entity_id', $rejected_tids, 'IN')
        ->execute();
      return $result->fetchCol();
    }

    return [];
  }

  /**
   * Gets entity data from ttd_entities table.
   */
  protected function getEntityData($ttd_id) {
    $query = $this->database->select('ttd_entities', 'e')
      ->fields('e')
      ->condition('ttd_id', $ttd_id);

    return $query->execute()->fetchAssoc();
  }

  /**
   * Gets schema types for an entity.
   */
  protected function getEntitySchemaTypes($ttd_id) {
    $query = $this->database->select('ttd_entity_schema_types', 'est');
    $query->join('ttd_schema_types', 'st', 'st.ttd_id = est.schema_type_id');
    $query->fields('st', ['name'])
      ->condition('est.entity_id', $ttd_id);

    $types = [];
    $result = $query->execute();
    foreach ($result as $record) {
      $types[] = $record->name;
    }

    return $types;
  }

  /**
   * Formats entity data into schema.org structure.
   */
  protected function formatEntityData($output_data, $entity, $schema_type) {
    // Name - handle all possible fallbacks in correct order.
    if (!empty($entity['name'])) {
      $output_data['name'] = $entity['name'];
    }
    elseif (!empty($entity['nl_name'])) {
      $output_data['name'] = $entity['nl_name'];
    }
    elseif (!empty($entity['kg_name'])) {
      $output_data['name'] = $entity['kg_name'];
    }
    elseif (!empty($entity['wb_name']) && $entity['wb_name'] !== 'No Label Defined') {
      $output_data['name'] = $entity['wb_name'];
    }

    // Description.
    if (isset($entity['wb_description'])) {
      $output_data['description'] = $entity['wb_description'];
    }

    // URL.
    if (isset($entity['official_website'])) {
      $output_data['url'] = $entity['official_website'];
    }

    // Image.
    if (isset($entity['kg_image'])) {
      $output_data['image'] = $entity['kg_image'];
    }
    elseif (isset($entity['wb_image'])) {
      $output_data['image'] = $entity['wb_image'];
    }
    elseif (isset($entity['wb_logo_image'])) {
      $output_data['image'] = $entity['wb_logo_image'];
    }

    // Add schema.org properties based on schema type.
    $this->addSchemaTypeProperties($output_data, $entity, $schema_type);

    // Add sameAs links.
    $output_data['sameAs'] = $this->getSameAsLinks($entity);

    return $output_data;
  }

  /**
   * Adds schema type specific properties.
   */
  protected function addSchemaTypeProperties(&$output_data, $entity, $schema_type) {
    // Date published.
    if ($this->hasDatePublished($schema_type) && isset($entity['publication_date'])) {
      $output_data['datePublished'] = $this->formatDate($entity['publication_date']);
    }

    // Duration.
    if ($this->hasDuration($schema_type) && isset($entity['duration'])) {
      $output_data['duration'] = 'PT' . str_replace('+', '', $entity['duration']) . 'M';
    }

    // Location.
    if ($this->hasLocation($schema_type) && isset($entity['country'])) {
      $output_data['location'] = [
        '@type' => 'Country',
        'name' => $entity['country'],
      ];
    }

    // Add missing properties from WordPress version:
    // Start time.
    if ($this->hasStartTime($schema_type) && isset($entity['start_time'])) {
      $output_data['startDate'] = $this->formatDate($entity['start_time']);
    }

    // End time.
    if ($this->hasEndTime($schema_type) && isset($entity['end_time'])) {
      $output_data['endDate'] = $this->formatDate($entity['end_time']);
    }

    // Founding date.
    if ($this->hasFoundingDate($schema_type) && isset($entity['inception'])) {
      $output_data['foundingDate'] = $this->formatDate($entity['inception']);
    }

    // Birth date.
    if ($this->hasBirthDate($schema_type) && isset($entity['date_of_birth'])) {
      $output_data['birthDate'] = $this->formatDate($entity['date_of_birth']);
    }

    // Content rating.
    if ($this->hasContentRating($schema_type) && isset($entity['mpa_film_rating'])) {
      $output_data['contentRating'] = $entity['mpa_film_rating'];
    }

    // Is part of.
    if ($this->hasIsPartOf($schema_type)) {
      $output_data['isPartOf'] = [];
      if (isset($entity['series'])) {
        $output_data['isPartOf'][] = [
          '@type' => 'CreativeWorkSeries',
          'name' => $entity['series'],
        ];
      }
      if (isset($entity['season'])) {
        $output_data['isPartOf'][] = [
          '@type' => 'CreativeWorkSeason',
          'name' => $entity['season'],
        ];
      }
    }

    // Genre.
    if ($this->hasGenre($schema_type) && isset($entity['genre'])) {
      $output_data['genre'] = explode(';', $entity['genre']);
    }

    // Producer.
    if ($this->hasProducer($schema_type) && isset($entity['producer'])) {
      $output_data['producer'] = array_map(function ($producer) {
        return [
          '@type' => 'Person',
          'name' => $producer,
        ];
      }, explode(';', $entity['producer']));
    }

    // Director.
    if ($this->hasDirector($schema_type) && isset($entity['director'])) {
      $output_data['director'] = array_map(function ($director) {
        return [
          '@type' => 'Person',
          'name' => $director,
        ];
      }, explode(';', $entity['director']));
    }

    // Author.
    if ($this->hasAuthor($schema_type) && isset($entity['screenwriter'])) {
      $output_data['author'] = array_map(function ($author) {
        return [
          '@type' => 'Person',
          'name' => $author,
        ];
      }, explode(';', $entity['screenwriter']));
    }

    // Actor.
    if ($this->hasActor($schema_type) && isset($entity['cast_member'])) {
      $output_data['actor'] = array_map(function ($actor) {
        return [
          '@type' => 'Person',
          'name' => $actor,
        ];
      }, explode(';', $entity['cast_member']));
    }

    // Add containedInPlace.
    if ($this->hasContainedInPlace($schema_type) && isset($entity['country'])) {
      $output_data['containedInPlace'] = [
        '@type' => 'Country',
        'name' => $entity['country'],
      ];
    }

    // Add locationCreated.
    if ($this->hasLocationCreated($schema_type) && isset($entity['country'])) {
      $output_data['locationCreated'] = [
        '@type' => 'Country',
        'name' => $entity['country'],
      ];
    }

    // Characters.
    if ($this->hasCharacters($schema_type) && isset($entity['characters'])) {
      $output_data['characters'] = array_map(function ($character) {
        return [
          '@type' => 'Person',
          'name' => $character,
        ];
      }, explode(';', $entity['characters']));
    }

    // Composer.
    if ($this->hasComposer($schema_type) && isset($entity['composer'])) {
      $output_data['composer'] = array_map(function ($composer) {
        return [
          '@type' => 'Person',
          'name' => $composer,
        ];
      }, explode(';', $entity['composer']));
    }

    // Add creator.
    if ($this->hasCreator($schema_type) && isset($entity['creator'])) {
      $output_data['creator'] = array_map(function ($creator) {
        return [
          '@type' => 'Person',
          'name' => $creator,
        ];
      }, explode(';', $entity['creator']));
    }
  }

  /**
   * Gets sameAs links for entity.
   */
  protected function getSameAsLinks($entity) {
    $links = [];

    // Google Knowledge Graph links - handle all variants.
    if (!empty($entity['mid'])) {
      $links[] = 'https://www.google.com/search?kgmid=' . $entity['mid'];
    }
    elseif (!empty($entity['freebase_id'])) {
      $links[] = 'https://www.google.com/search?kgmid=' . $entity['freebase_id'];
    }
    elseif (!empty($entity['google_knowledge_graph_id'])) {
      $links[] = 'https://www.google.com/search?kgmid=' . $entity['google_knowledge_graph_id'];
    }

    // Add missing links.
    if (!empty($entity['rotten_tomatoes_id'])) {
      $links[] = 'https://www.rottentomatoes.com/' . $entity['rotten_tomatoes_id'];
    }
    if (!empty($entity['goodreads_work_id'])) {
      $links[] = 'https://www.goodreads.com/work/show/' . $entity['goodreads_work_id'];
    }
    if (!empty($entity['allmusic_album_id'])) {
      $links[] = 'https://www.allmusic.com/album/' . $entity['allmusic_album_id'];
    }
    if (!empty($entity['spotify_album_id'])) {
      $links[] = 'https://open.spotify.com/album/' . $entity['spotify_album_id'];
    }

    // Rest of existing links...
    return $links;
  }

  /**
   * Helper function to check if schema type has datePublished.
   */
  protected function hasDatePublished($schema_type) {
    return in_array($schema_type, [
      'Legislation',
      'Movie',
      'TVSeries',
      'Book',
      'MusicGroup',
    ]);
  }

  /**
   * Helper function to check if schema type has duration.
   */
  protected function hasDuration($schema_type) {
    return in_array($schema_type, ['Event', 'Movie']);
  }

  /**
   * Helper function to check if schema type has location.
   */
  protected function hasLocation($schema_type) {
    return in_array($schema_type, [
      'Event',
      'Organization',
      'EducationalOrganization',
      'NGO',
      'Corporation',
      'NewsMediaOrganization',
      'GovernmentOrganization',
      'SportsTeam',
    ]);
  }

  /**
   * Helper function to check if schema type has start time.
   */
  protected function hasStartTime($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries']);
  }

  /**
   * Helper function to check if schema type has end time.
   */
  protected function hasEndTime($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries']);
  }

  /**
   * Helper function to check if schema type has founding date.
   */
  protected function hasFoundingDate($schema_type) {
    return in_array($schema_type, [
      'Organization', 'EducationalOrganization', 'NGO', 'Corporation',
      'NewsMediaOrganization', 'GovernmentOrganization', 'SportsTeam',
    ]);
  }

  /**
   * Helper function to check if schema type has birth date.
   */
  protected function hasBirthDate($schema_type) {
    return $schema_type === 'Person';
  }

  /**
   * Helper function to check if schema type has content rating.
   */
  protected function hasContentRating($schema_type) {
    return in_array($schema_type, ['Book', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has is part of.
   */
  protected function hasIsPartOf($schema_type) {
    return in_array($schema_type, [
      'Book',
      'TVSeries',
      'Legislation',
      'Movie',
      'CreativeWorkSeries',
      'CreativeWorkSeason',
    ]);
  }

  /**
   * Helper function to check if schema type has genre.
   */
  protected function hasGenre($schema_type) {
    return in_array($schema_type, [
      'MusicGroup',
      'TVSeries',
      'Book',
      'Legislation',
      'Movie',
      'CreativeWork',
    ]);
  }

  /**
   * Helper function to check if schema type has producer.
   */
  protected function hasProducer($schema_type) {
    return in_array($schema_type, ['Book', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has director.
   */
  protected function hasDirector($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has author.
   */
  protected function hasAuthor($schema_type) {
    return in_array($schema_type, ['Book', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has actor.
   */
  protected function hasActor($schema_type) {
    return in_array($schema_type, ['Event', 'TVSeries', 'Legislation', 'Movie']);
  }

  /**
   * Helper function to check if schema type has containedInPlace.
   */
  protected function hasContainedInPlace($schema_type) {
    return in_array($schema_type, ['City', 'State', 'Place']);
  }

  /**
   * Helper function to check if schema type has locationCreated.
   */
  protected function hasLocationCreated($schema_type) {
    return in_array($schema_type, ['Legislation', 'MusicGroup', 'TVSeries', 'Book']);
  }

  /**
   * Helper function to check if schema type has characters.
   */
  protected function hasCharacters($schema_type) {
    return in_array($schema_type, ['Movie', 'TVSeries', 'Book']);
  }

  /**
   * Helper function to check if schema type has composer.
   */
  protected function hasComposer($schema_type) {
    return in_array($schema_type, ['Movie', 'TVSeries', 'MusicGroup']);
  }

  /**
   * Helper function to check if schema type has creator.
   */
  protected function hasCreator($schema_type) {
    return in_array($schema_type, ['Book', 'Movie', 'TVSeries']);
  }

  /**
   * Get logo URL: uploaded file or auto-detect.
   *
   * @param string $base_url
   *   The base URL of the site.
   * @param \Drupal\Core\Config\Config $config
   *   The module configuration.
   *
   * @return string
   *   The URL to the logo.
   */
  protected function getLogoUrl($base_url, $config) {
    // Priority 1: Uploaded custom logo.
    $logo_fid = $config->get('organization_logo_fid');
    if (!empty($logo_fid)) {
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($logo_fid);
      if ($file) {
        return file_create_url($file->getFileUri());
      }
    }

    // Priority 2: Auto-detect as fallback.
    return $this->getAutoDetectedLogoUrl($base_url);
  }

  /**
   * Auto-detect logo from theme and site settings.
   *
   * @param string $base_url
   *   The base URL of the site.
   *
   * @return string
   *   The URL to the auto-detected logo.
   */
  protected function getAutoDetectedLogoUrl($base_url) {
    // First, try to get logo from Drupal site logo settings.
    $site_logo = theme_get_setting('logo');
    if (!empty($site_logo['url'])) {
      return $site_logo['url'];
    }

    // Get active theme info.
    $theme_handler = \Drupal::service('theme_handler');
    $active_theme = $theme_handler->getDefault();
    $theme_path = $theme_handler->getTheme($active_theme)->getPath();

    // Common logo filenames to search for.
    $logo_filenames = [
      'logo.svg',
      'logo.png',
      'logo.jpg',
      'logo.jpeg',
      'logo.gif',
      'images/logo.svg',
      'images/logo.png',
      'images/logo.jpg',
      'images/logo.jpeg',
      'assets/logo.svg',
      'assets/logo.png',
      'img/logo.svg',
      'img/logo.png',
    ];

    // Search for logo files in active theme.
    foreach ($logo_filenames as $filename) {
      $logo_path = $theme_path . '/' . $filename;
      if (file_exists(\Drupal::root() . '/' . $logo_path)) {
        return $base_url . '/' . $logo_path;
      }
    }

    // Fallback: look for site-specific logos (like your fordhaminstitute theme)
    $site_specific_logos = [
      '/themes/fordhaminstitute/logo.png',
      '/themes/fordhaminstitute/images/logo-alt.png',
      '/themes/fordhaminstitute/2color-logo.svg',
      '/themes/fordhaminstitute/logo.svg',
    ];

    foreach ($site_specific_logos as $logo_path) {
      if (file_exists(\Drupal::root() . $logo_path)) {
        return $base_url . $logo_path;
      }
    }

    // Ultimate fallback: try to construct a generic logo path.
    return $base_url . '/' . $theme_path . '/logo.png';
  }

  /**
   * Formats a date string to ISO 8601.
   */
  protected function formatDate($date) {
    return date('c', strtotime($date));
  }

}
