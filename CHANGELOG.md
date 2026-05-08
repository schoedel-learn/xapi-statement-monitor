# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-08

### Added
- **REST Log API** — New authenticated REST endpoints: GET /xapi-monitor/v1/logs (paginated), GET /xapi-monitor/v1/logs/{id} (single row with raw statement), GET /xapi-monitor/v1/alerts. Enables programmatic access to all monitoring data from external tools, n8n workflows, and dashboards.
- **Quiz Step Detection** — Diagnostic engine now detects when a LearnDash course contains quiz steps that cannot be completed via xAPI statements. Warns admin proactively in the User Diagnostic tab with the specific quiz IDs involved.
- **Evidence-Linked Diagnostics** — Every completion mismatch alert now includes a structured evidence payload: the Tin Canny DB rows that triggered the finding, matching LearnDash activity records, and xAPI Monitor log IDs. Evidence is displayed in a collapsible section in the Alerts tab.
- **"How xAPI Works" Documentation Tab** — In-plugin educational reference covering the full xAPI pipeline, ADL verb semantics, common failure patterns with field explanations, and references to authoritative sources. Grounded in peer-reviewed learning analytics literature (Vidal et al., 2018; Samuelsen et al., 2021; Nouira et al., 2018; Ahmad et al., 2022; Rocha et al., 2024; Friesen, 2013) with full APA 7 citations. Designed for both technical and non-technical admins.
- **Tin Canny Version Detection Fix** — Status endpoint now correctly detects and reports the installed Tin Canny version by reading the plugin file header directly, with multiple fallback strategies.
- **Memory Limit Warning** — Sites with WordPress memory limit below 64M now receive a yellow notice in Settings and System Health tabs.

### Changed
- `evidence` column added to xapi_monitor_alerts table (existing installs upgraded automatically via maybe_upgrade)
- User Diagnostic tab now shows quiz step warning and a Data Sources panel linking each finding to its source DB table and row IDs
- Alerts tab now shows Last Diagnostic Run timestamp in header
- Alerts tab evidence payload displayed in collapsible "View Evidence" section with raw DB rows

## [1.1.0] - 2026-07-01

### Changed
- Bumped "Tested up to" to WordPress 6.9 (compatible with 6.9.4 security releases)
- Updated "Requires PHP" to 8.0 (PHP 7.4 reached end-of-life November 2022)

### Added
- **System Health: LearnDash REST API check** — The diagnostic engine now verifies that the LearnDash v2 REST API (`/wp-json/ldlms/v2/sfwd-lessons`) is reachable and returning a valid response on every cron run. Failures are surfaced in the System Health tab and trigger the standard alert pipeline. This is critical for sites running LearnDash 5.0+, where the REST API became the production-ready backbone for completion tracking and integrations.
- **Tin Canny filter documentation in readme** — Added note about `tincanny_module_allow_db_capture` filter (enhanced in Tin Canny 5.1.3) for admins to reduce xAPI statement DB bloat.

## [1.0.3] - 2026-04-13

### Fixed / Added
- **Data preservation across updates:** Plugin deletion no longer automatically drops the `xapi_monitor_log` or `xapi_monitor_alerts` tables. Monitoring history is preserved through every deactivate → delete → reinstall cycle, so upgrading to a new version never erases accumulated diagnostic data.
- **Explicit wipe required:** `uninstall.php` now checks for an `xapi_monitor_wipe_on_uninstall` flag before dropping any tables. The flag is only set when the admin uses the new "Delete All Data" action — never set automatically.
- **Settings retained on delete:** `xapi_monitor_settings` (email recipients, thresholds, beacon toggle, etc.) is no longer removed by `uninstall.php` by default, preserving configuration across reinstalls.
- **"Delete All Data" action added to Settings → Danger Zone:** Two-step confirmation UI (click → confirm → execute) for permanently erasing all logs, alerts, and settings when intentionally resetting the plugin. Tables are immediately re-created empty so the plugin resumes collection without requiring a full reinstall.
- **Cron schedules remain the only thing always cleared on delete:** They are automatically re-registered on the next plugin activation.

## [1.0.2] - 2026-04-12

### Fixed
- **Critical (JS):** `XAPI_PATTERNS` included `/xapi/i` which matched the beacon's own REST endpoint URL (`/xapi-monitor/v1/beacon`), causing the beacon to intercept its own outgoing POST and fire another beacon about it — an infinite self-monitoring loop generating garbage log entries.
- **Critical (JS):** `/statements/i` pattern was too broad — matched any URL containing the word "statements" including JS filenames, font URLs, and unrelated REST routes, causing spurious xAPI intercepts on non-xAPI network requests.
- **Critical (JS):** `onreadystatechange` was replaced on the XHR prototype rather than using `addEventListener`. This broke Rise content that sets `onreadystatechange` *after* `send()` — a valid pattern — because our replacement wrapped the handler that existed *at send() time* only, dropping any later-assigned handler. Replaced with `addEventListener('load')` + `addEventListener('readystatechange')` with a `beaconSent` guard to prevent duplicate reports.
- **Moderate (JS):** `hookIframe()` was called synchronously in the MutationObserver callback and on existing iframes via `if (iframe.contentDocument)` — before the iframe had finished loading. Accessing `contentDocument` prematurely blocks the browser's DOM mutation queue, which Rise uses to render slides, causing modules to appear stuck or not progress. All iframe hooks are now deferred with `setTimeout(fn, 0)` after the `load` event.
- **Minor (JS):** MutationObserver callback did not check `node.nodeType` before accessing `node.tagName`, causing errors on text/comment nodes.

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
