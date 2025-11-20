#!/bin/bash

# Reprocess BA Request #250532
# This script reinitializes the reimport of all entities from request #250532

set -e

cd "$(dirname "$0")"

REQUEST_ID="250532"

echo "=== REPROCESSING BA REQUEST #$REQUEST_ID ==="
echo ""

# You'll need to run this in your Drupal environment
# Adjust the command below based on where your Drupal is running

# If using DDEV:
# ddev exec php reprocess_ba_250532.php

# If using Docker Compose:
# docker-compose exec drupal php reprocess_ba_250532.php

# If running PHP directly:
# php reprocess_ba_250532.php

echo "To execute the reprocess, run one of the following based on your setup:"
echo ""
echo "DDEV:"
echo "  cd /Users/ej/Maker/Products/TopicalBoost\\ WP+D/Drupal"
echo "  ddev exec php reprocess_ba_250532.php"
echo ""
echo "Or if you have Drush available:"
echo "  drush state:get topicalboost.bulk_analysis.request_id"
echo "  drush state:delete topicalboost.bulk_analysis.request_id"
echo "  drush state:delete topicalboost.bulk_analysis.apply_progress"
echo "  drush advancedqueue:queue:process ttd_topics_analysis"
echo ""
