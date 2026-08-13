<?php

/**
 * Static regression checks for sitemap eligibility and regeneration.
 *
 * Run from the module root:
 *   php tests/cli/test-sitemap-eligibility-refresh.php
 */

$root = dirname(__DIR__, 2);
$module = file_get_contents($root . '/ttd_topics.module');
$services = file_get_contents($root . '/ttd_topics.services.yml');
$subscriber = file_get_contents($root . '/src/EventSubscriber/TopicSitemapRefreshSubscriber.php');
$sync_service = file_get_contents($root . '/src/Service/TtdSyncService.php');

function ttd_sitemap_eligibility_assert(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }

  echo "PASS: {$message}\n";
}

$filter_start = strpos($module, 'function ttd_topics_simple_sitemap_links_alter(');
$filter_end = strpos($module, "\n}\n\n/**\n * Get node counts for topic terms.", $filter_start);
$filter = substr($module, $filter_start, $filter_end - $filter_start);

ttd_sitemap_eligibility_assert($filter_start !== false && $filter_end !== false, 'Simple XML Sitemap filter is present');
ttd_sitemap_eligibility_assert(strpos($filter, "bundle() === 'ttd_topics'") !== false, 'Filter verifies the installed topic vocabulary machine name');
ttd_sitemap_eligibility_assert(strpos($filter, "=== 'topicalboost'") === false, 'Legacy incorrect vocabulary check is absent');
ttd_sitemap_eligibility_assert(strpos($filter, "\$link['meta']['entity_info']") !== false, 'Simple XML Sitemap 3.x metadata is supported');
ttd_sitemap_eligibility_assert(strpos($filter, "\$link['entity_info']") !== false, 'Newer top-level sitemap metadata is supported');
ttd_sitemap_eligibility_assert(strpos($filter, 'loadMultiple($candidate_ids)') !== false, 'Taxonomy entities are loaded in one batch');
ttd_sitemap_eligibility_assert(strpos($filter, 'ttd_topics_get_topic_node_counts($term_ids)') !== false, 'Topic counts are loaded in one batch');
ttd_sitemap_eligibility_assert(strpos($filter, 'ttd_topics_get_curation_scores($entity_ids)') !== false, 'Curation decisions are loaded in one batch');
ttd_sitemap_eligibility_assert(substr_count($filter, "->select('taxonomy_index'") === 1, 'Rendered exceptions use one batched relationship query');
ttd_sitemap_eligibility_assert(strpos($filter, "get('enable_frontend')") !== false, 'Filter removes archives disabled for anonymous visitors');
ttd_sitemap_eligibility_assert(strpos($filter, 'ttd_topics_should_exclude_public_topic(') !== false, 'Sitemap uses the shared public eligibility decision');
ttd_sitemap_eligibility_assert(strpos($filter, 'ttd_topics_get_rendered_topic_ids_for_terms($rendered_candidates)') !== false, 'Manual, Main, and About exceptions use frontend rendering results');

ttd_sitemap_eligibility_assert(strpos($module, 'TTD_TOPICS_SITEMAP_REFRESH_DELAY = 60') !== false, 'Refreshes use a short debounce window');
ttd_sitemap_eligibility_assert(strpos($module, 'if (!$state->get(TTD_TOPICS_SITEMAP_REFRESH_DUE_STATE))') !== false, 'Bulk mutations reuse one refresh marker');
ttd_sitemap_eligibility_assert(strpos($module, "Cache::invalidateTags(['ttd_topics:archive_eligibility'])") !== false, 'Mutations invalidate cached archive eligibility');
ttd_sitemap_eligibility_assert(strpos($module, "->addCacheableDependency(\$config)") !== false, 'Archive access varies with TopicalBoost settings');
ttd_sitemap_eligibility_assert(strpos($module, "'ttd_topics:archive_eligibility'") !== false, 'Archive access carries the shared eligibility cache tag');
ttd_sitemap_eligibility_assert(strpos($module, 'ttd_topics_maybe_refresh_simple_sitemap();') !== false, 'Cron processes deferred sitemap refreshes');
ttd_sitemap_eligibility_assert(strpos($module, "is_callable([\$generator, 'generateSitemap'])") !== false, 'Simple XML Sitemap 3.x generation is supported');
ttd_sitemap_eligibility_assert(strpos($module, "is_callable([\$generator, 'generate'])") !== false, 'Simple XML Sitemap 4.x generation is supported');
ttd_sitemap_eligibility_assert(strpos($module, "is_callable([\$generator, 'setSitemaps'])") !== false, 'Simple XML Sitemap 4.x selects sitemap entities with the current API');
ttd_sitemap_eligibility_assert(strpos($module, "\$generator->generate('backend')") !== false, 'Simple XML Sitemap 4.x runs in bounded backend mode');
ttd_sitemap_eligibility_assert(strpos($module, "hasService('simple_sitemap.queue_worker')") !== false, 'Large sitemap generations detect current 4.x queue progress');
ttd_sitemap_eligibility_assert(strpos($module, 'if (!$generation_in_progress)') !== false, 'Existing sitemap queues continue without being rebuilt');

ttd_sitemap_eligibility_assert(strpos($services, 'TopicSitemapRefreshSubscriber') !== false, 'Settings refresh subscriber is registered');
ttd_sitemap_eligibility_assert(strpos($subscriber, "getName() === 'ttd_topics.settings'") !== false, 'Settings changes schedule a refresh');
ttd_sitemap_eligibility_assert(strpos($sync_service, 'ttd_topics_schedule_sitemap_refresh') !== false, 'Changed curation scores schedule a refresh');

echo "Sitemap eligibility refresh checks passed.\n";
