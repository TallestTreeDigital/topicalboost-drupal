# Reprocessing BA Request #250532

This guide explains how to reimport all entities from request #250532 from scratch.

## What This Does

- Clears the request state from Drupal
- Clears any queued jobs for this request
- Reinitializes the entity import from page 1
- The system will fetch ALL entities from the API and create/update taxonomy terms
- Topics will be assigned to nodes as specified by the API

## Prerequisites

- Drupal with ttd_topics module installed and enabled
- AdvancedQueue module enabled and working
- Database with existing ttd_topics tables
- Access to run drush commands or PHP in your Drupal environment

## Method 1: Using Drush (Recommended)

```bash
# Navigate to your Drupal root (where settings.php is located)
cd [your-drupal-root]

# Clear the request state
drush state:delete topicalboost.bulk_analysis.request_id
drush state:delete topicalboost.bulk_analysis.filters
drush state:delete topicalboost.bulk_analysis.content_count
drush state:delete topicalboost.bulk_analysis.apply_progress
drush state:delete topicalboost.bulk_analysis.completed_at
drush state:delete topicalboost.bulk_analysis.customer_id_page_count
drush state:delete topicalboost.bulk_analysis.entity_page_count

# Clear any queued jobs for this request
drush sql-query "DELETE FROM advancedqueue WHERE queue_id='ttd_topics_analysis' AND (type='ttd_bulk_apply_customer_ids' OR type='ttd_bulk_apply_entities') AND payload LIKE '%250532%' AND state IN ('queued', 'processing')"

# Initialize fresh request state
drush state:set topicalboost.bulk_analysis.request_id 250532
drush state:set topicalboost.bulk_analysis.apply_progress '{"stage":"entities","customer_ids":{"completed":0,"total":0,"current_page":1},"entities":{"completed":0,"total":0,"current_page":1}}'

# Schedule the entity reimport job
drush php:eval '
$queue_storage = \Drupal::entityTypeManager()->getStorage("advancedqueue_queue");
$queue = $queue_storage->load("ttd_topics_analysis");
$job = \Drupal\advancedqueue\Entity\Job::create("ttd_bulk_apply_entities", [
  "request_id" => "250532",
  "page" => 1,
]);
$queue->enqueueJob($job);
echo "Job queued successfully\n";
'

# Process the queue
drush advancedqueue:queue:process ttd_topics_analysis
```

## Method 2: Using PHP Script (Direct Execution)

From the module directory, run:

```bash
php reprocess_ba_250532.php
```

This script will:
1. Clear state
2. Clear queued jobs
3. Initialize fresh request state
4. Queue the first entity import job

## Method 3: If Using DDEV

```bash
# From your Drupal module location
cd /Users/ej/Maker/Products/TopicalBoost WP+D/Drupal

# OR from your Drupal root, depending on setup
ddev exec drush state:delete topicalboost.bulk_analysis.request_id
ddev exec drush state:set topicalboost.bulk_analysis.request_id 250532

# ... continue with other drush commands as in Method 1
```

## What to Expect

After running reprocess commands:

1. The system queues `ttd_bulk_apply_entities` job for page 1
2. The job fetches `/result/entities?request_id=250532&page=1` from API
3. For each entity in the response:
   - Creates or updates a taxonomy term in `ttd_topics` vocabulary
   - Creates/updates database records in `ttd_entities` table
   - Creates relationships in `ttd_entity_post_ids` table
   - Adds topic references to nodes via `field_ttd_topics`
   - Handles SchemaTypes and WBCategories relationships
4. If `has_next_page` is true, queues page 2, and so on
5. Continues until all pages processed

## Monitoring Progress

Check progress at:
```
/admin/config/content/topicalboost/bulk-analysis
```

Or via Drush:
```bash
drush state:get topicalboost.bulk_analysis.apply_progress
```

## Troubleshooting

### Queue not processing
- Make sure AdvancedQueue cron is running: `drush cron`
- Or manually process: `drush advancedqueue:queue:process ttd_topics_analysis`

### No entities importing
- Check Drupal logs: `drush watchdog:list --category=ttd_topics`
- Check if API is accessible: `curl -H "x-api-key: YOUR_KEY" http://api.endpoint/result/entities?request_id=250532&page=1`

### Database errors
- Check `ttd_entities` table exists
- Check `ttd_entity_post_ids` table exists
- Check `node__field_ttd_topics` field exists on content types

## Database Views

Check counts with SQL:

```sql
-- Total entities imported
SELECT COUNT(*) FROM ttd_entities;

-- Total relationships
SELECT COUNT(*) FROM ttd_entity_post_ids;

-- Topics per node
SELECT entity_id, COUNT(*) as topic_count
FROM node__field_ttd_topics
GROUP BY entity_id;

-- Total taxonomy terms
SELECT COUNT(*) FROM taxonomy_term_data WHERE vid='ttd_topics';
```
