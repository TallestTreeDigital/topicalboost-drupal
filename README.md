# TopicalBoost for Drupal

AI-powered topic analysis and structured data generation for Drupal. Automatically analyzes content to identify relevant topics and generates schema.org markup for improved SEO.

## Features

- **AI Topic Analysis**: Automatically identifies relevant topics in your content
- **Schema.org Integration**: Generates structured data for better SEO
- **Bulk Processing**: Analyze multiple content items simultaneously
- **Configurable Display**: Control how and where topics appear
- **Admin Interface**: Easy-to-use configuration and management tools

## Requirements

- Drupal 9.5+ or 10.0+ or 11.0+
- PHP 8.1+
- Advanced Queue module
- Pathauto module (recommended)

## Installation

### Via Composer (Recommended)

```bash
composer require tallesttree/topicalboost-drupal
```

### Manual Installation

1. Download the module
2. Extract to `web/modules/contrib/ttd_topics`
3. Enable the module: `drush en ttd_topics`

## Configuration

1. Navigate to **Administration > Configuration > Content > TopicalBoost**
2. Configure your API settings
3. Select content types to analyze
4. Customize display options
5. **Important**: Set up cron to run every minute for optimal queue processing: `* * * * * /path/to/drush cron`
   - This ensures timely processing of both single content analysis and bulk operations
   - Running cron every minute is safe and normal for Drupal queue-based modules
   - Without frequent cron runs, content analysis may be delayed significantly

## Usage

Once configured, TopicalBoost will automatically:
- Analyze new published content
- Generate topic tags
- Add structured data to pages
- Display topic mentions (if enabled)

## API Key

This module requires a TopicalBoost API key. Sign up at [topicalboost.com](https://topicalboost.com) to get started.

## Support

- [Issue queue](https://github.com/TallestTreeDigital/topicalboost-drupal/issues)
- [Documentation](https://api.topicalboost.com/docs)
- [Support](mailto:hello@tallesttree.digital)

## License

GPL-2.0-or-later