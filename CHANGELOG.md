# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-04-12

### Fixed
- **Critical:** `rest_pre_dispatch` hook (priority 5) was executing on every REST API request site-wide — including WordPress core Site Health checks (`/wp-site-health/v1/tests/*`), dashboard widget endpoints, and the Block Editor. This caused the WP dashboard Health Check to spin indefinitely and dashboard info module accordions to become unresponsive. Added a fast-path exclusion list that immediately returns for all non-Tin-Canny REST routes (`/wp/v2`, `/wp-site-health`, `/wp/v1`, `/oembed`, `/xapi-monitor`, `/wp-block-editor`) before doing any work.
- Added recursive-loop guard: the plugin's own beacon REST endpoint (`/xapi-monitor/*`) is now excluded from interception.

## [1.0.0] - 2026-04-12

### Added
- 5-layer xAPI statement capture (4 server-side Tin Canny hooks + JavaScript beacon)
- Automated diagnostic engine running every 15 minutes via WP-Cron
- Completion mismatch detection (Tin Canny has data but LearnDash doesn't)
- Statement gap detection (activity verbs without completion within configurable timeout)
- Endpoint health monitoring with synthetic test statements
- Failure rate tracking with configurable threshold alerts
- Admin dashboard with 5 tabs: Live Feed, Alerts, User Diagnostic, System Health, Settings
- Email alert system for critical failures
- REST API endpoints for beacon data collection and admin operations
- Force Complete, Reset xAPI Data, and Resend Statement admin actions
- Smart endpoint health check with Hostinger/LiteSpeed loopback fallback
- Configurable log retention (default 30 days) with CSV export
- JavaScript beacon with XHR/Fetch interception, iframe monitoring, and sendBeacon fallback
- Connectivity and JS error client-side monitoring
- Clean uninstall (drops all custom tables, options, and cron events)
