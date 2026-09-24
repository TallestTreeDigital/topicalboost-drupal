# Changelog

All notable changes to this project will be documented in this file.

## [2.0.26] - 2026-09-24

### Fixed
- Restore the organization logo uploader in Schema settings on Drupal 10 and 11. Save and remove the selected file correctly.
- Generate the uploaded logo URL through Drupal's file URL service.

## [2.0.25] - 2026-08-19

### Security
- Keep the configured TopicalBoost site API key on the server for dashboard widgets and proxy widget data only for authorized users.
- Require Drupal CSRF request-header tokens on state-changing TopicalBoost routes.
- Restrict schema-image operations to authorized nodes and reject unrelated file IDs as image sources.
- Remove one-off reprocessing artifacts from the public Drupal package.

## [2.0.24] - 2026-08-15

### Fixed
- Skip the URL-only publish update until node analysis has completed, preventing expected `Content not found` responses while an asynchronous analysis is still creating the API content record.

## [2.0.23] - 2026-08-13

### Fixed
- Keep rendered Manual, Main, and About topics available in the sitemap and align sitemap eligibility with anonymous archive access.

### Changed
- Refresh sitemap eligibility after relevant topic, content, curation, and settings changes with a short debounced background regeneration.

## [2.0.22] - 2026-08-05

### Changed
- Report the site's configured minimum topic post count so TopicalBoost Analytics uses the same topic-portfolio threshold as Drupal.

## [2.0.21] - 2026-08-03

### Added
- Add WordPress-equivalent taxonomy category defaults and per-run Remove/Only controls to Drupal bulk analysis.

### Changed
- Batch topic and alias loading during schema generation to keep query counts bounded on articles with many topics.

## [2.0.17] - 2026-07-11

### Changed
- Clarify that archive admin links are optional troubleshooting shortcuts and hide manual-only field and Facets links after automatic filtering is connected.

## [2.0.16] - 2026-07-11

### Changed
- Replace the expanded Search API/archive guidance panel with a compact connection status, a real archive-filter test link, and progressively disclosed setup details.
- Improve archive setup labels and dark-theme contrast without changing link generation or filtering behavior.

## [2.0.15] - 2026-07-09

### Added
- Add configurable Drupal topic links for Search API/archive pages, including query parameter, value source, and Facets-style value pattern settings.
- Add an optional managed setup that detects an existing Search API archive View, indexes TopicalBoost topic IDs, applies a hidden URL filter, and queues reindexing without creating a visible facet.

## [2.0.1] - 2026-05-22

### Fixed
- Clear stale queued sync pull jobs on update and cancel legacy offset sync jobs that do not include cursor pagination.
- Use stable site URLs for background analysis requests when Drupal CLI would otherwise generate placeholder hosts.

## [1.0.0] - 2025-07-XX

### Added
- Initial release of TopicalBoost for Drupal
