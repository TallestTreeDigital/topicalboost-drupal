# Changelog

All notable changes to this project will be documented in this file.

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
