# Changelog

All notable changes to this project will be documented in this file.

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
