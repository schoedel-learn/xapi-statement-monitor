=== xAPI Statement Monitor ===
Contributors: barryschoedel
Tags: xapi, learndash, tin-canny, lrs, elearning
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Diagnoses xAPI completion tracking failures on LearnDash + Tin Canny sites by intercepting, logging, and analyzing every xAPI statement.

== Description ==

xAPI Statement Monitor is a diagnostic tool for WordPress sites running LearnDash with Uncanny Owl's Tin Canny Reporting plugin. It provides end-to-end visibility into every xAPI statement flowing through the pipeline:

**Rise/xAPI Content (iframe) → Tin Canny Endpoint → WordPress DB → LearnDash Completion**

= The Problem It Solves =

If you're running Articulate Rise (or other xAPI content) in LearnDash with Tin Canny as your LRS, you may experience intermittent issues where:

* Course completion doesn't register for some users
* Lesson/topic progress is lost or inconsistent
* xAPI statements silently fail to reach Tin Canny
* Support tickets pile up with "I finished the course but it shows incomplete"

This plugin makes those invisible failures visible.

= Key Features =

* **5-Layer Statement Capture** — Hooks into Tin Canny's processing pipeline (before processing, after processing, capture filter, REST intercept) plus a JavaScript beacon that monitors client-side delivery
* **Automated Diagnostic Engine** — Runs every 15 minutes to detect completion mismatches (Tin Canny has data but LearnDash doesn't), statement gaps (activity without completion), and endpoint health issues
* **Admin Dashboard** — Live statement feed, alerts with recommended actions, per-user diagnostics with side-by-side Tin Canny vs LearnDash comparison, system health checks
* **Email Alerts** — Configurable notifications for critical issues (failed endpoints, high failure rates, stuck users)
* **Fix Actions** — Force LearnDash completion, reset xAPI data, resend statements — all from the dashboard
* **JavaScript Beacon** — Intercepts XHR/Fetch from Rise iframes to detect client-side delivery failures (timeouts, network errors, blocked requests) that server-side logging can't see
* **Zero Impact** — Purely observational. Never blocks, modifies, or interferes with normal xAPI/Tin Canny/LearnDash processing

= Requirements =

* WordPress 6.0+
* PHP 8.0+
* [LearnDash LMS](https://www.learndash.com/)
* [Tin Canny Reporting for LearnDash](https://www.uncannyowl.com/downloads/tin-canny-learndash-reporting/) (Uncanny Owl)
* xAPI content (Articulate Rise, Storyline, or any xAPI-compliant authoring tool)

= How It Works =

1. **Server-Side Hooks** — Taps into Tin Canny's `tincanny_before_process_request`, `tincanny_module_result_processed`, and `tincanny_module_allow_db_capture` hooks to log every statement as it enters and exits the processing pipeline.

2. **REST API Monitoring** — Passively observes requests to the `ucTinCan` virtual endpoint via `rest_pre_dispatch`.

3. **Client-Side Beacon** — Injects a lightweight JavaScript observer on LearnDash lesson/topic pages that intercepts XMLHttpRequest and Fetch calls from the xAPI content iframe, logging delivery success/failure/timeout back to your server.

4. **Diagnostic Comparison** — A WP-Cron job compares Tin Canny's recorded statements against LearnDash's completion records, flagging mismatches and gaps.

= Use Cases =

* **Troubleshooting** — A learner reports their course shows incomplete. Search their user profile in the diagnostic tab to see exactly which xAPI verbs were received, whether Tin Canny processed them, and whether LearnDash was notified.
* **Monitoring** — Set up email alerts so you know about completion failures before your users report them.
* **Root Cause Analysis** — Determine whether failures are client-side (network issues, browser compatibility, iframe problems), server-side (Tin Canny not processing, endpoint down), or LMS-side (LearnDash not receiving the completion trigger).

= Tin Canny Filter: Reducing xAPI Statement Bloat =
Tin Canny 5.1.3+ supports a filter to skip specific xAPI events from being captured to the database.
If you are seeing excessive DB rows in the Tin Canny reporting table, use the `tincanny_module_allow_db_capture`
filter in a mu-plugin or your theme's functions.php to selectively skip low-value xAPI verbs (e.g., "initialized", "interacted"):

add_filter( 'tincanny_module_allow_db_capture', function( $allow, $data ) {
    if ( isset( $data['verb'] ) && in_array( $data['verb'], [ 'initialized', 'interacted' ], true ) ) {
        return false;
    }
    return $allow;
}, 10, 2 );

== Installation ==

1. Upload the `xapi-statement-monitor` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Navigate to **xAPI Monitor** in the admin sidebar
4. Go to the **Settings** tab to configure email alerts and thresholds
5. The diagnostic cron and JavaScript beacon activate automatically

= Manual Installation =

1. Download the plugin zip file
2. Go to Plugins → Add New → Upload Plugin
3. Select the zip file and click Install Now
4. Activate the plugin

== Frequently Asked Questions ==

= Does this plugin modify or interfere with Tin Canny or LearnDash? =

No. It is entirely passive and observational. All Tin Canny filter hooks return their original values unchanged. The JavaScript beacon observes network requests without modifying them. The plugin only reads LearnDash data — it never writes to it unless you explicitly use the "Force Complete" admin action.

= Will this slow down my site? =

The logging adds minimal overhead (a single database INSERT per xAPI statement). The diagnostic cron runs every 15 minutes and uses efficient indexed queries. The JavaScript beacon is lightweight (~3KB) and only loads on LearnDash lesson/topic pages.

= Does it work with content other than Articulate Rise? =

Yes. It monitors all xAPI statements passing through Tin Canny, regardless of the authoring tool. Rise, Storyline, Captivate, iSpring, Lectora, H5P — if it sends xAPI statements to Tin Canny, this plugin will capture them.

= Does it work with GrassBlade instead of Tin Canny? =

Currently it is designed specifically for Tin Canny's hooks and endpoint structure. GrassBlade support is a potential future addition. Contributions are welcome.

= What does the "Force Complete" action do? =

It calls LearnDash's `learndash_process_mark_complete()` function for a specific user/lesson combination, the same function that would be called if the user clicked the Mark Complete button. Use it to resolve cases where Tin Canny recorded the completion but LearnDash wasn't notified.

= The endpoint health check fails but everything works fine? =

This is common on hosts like Hostinger with LiteSpeed, where server-to-self loopback HTTP requests are blocked. The plugin detects this and falls back to verifying that Tin Canny is active with its database tables intact. Real browser requests to the endpoint work normally — the loopback test is the only thing affected.

= How long are logs retained? =

By default, 30 days. Configurable in Settings. You can also export to CSV before purging.

== Screenshots ==

1. Live Statement Feed — color-coded, filterable view of all captured xAPI statements
2. Alerts & Diagnostics — actionable alerts with recommended fixes
3. User Diagnostic — per-user side-by-side comparison of Tin Canny vs LearnDash completion
4. System Health — endpoint status, capture rates, and environmental diagnostics
5. Settings — configure alerts, thresholds, and monitoring behavior

== Changelog ==

= 1.1.0 =
* Tested up to WordPress 6.9 (compatible with 6.9.4 security releases)
* Updated minimum PHP requirement to 8.0 (PHP 7.4 reached end-of-life November 2022)
* Added LearnDash REST API health check to System Health diagnostics (LearnDash 5.0+ critical path)
* Added readme note about Tin Canny 5.1.3's tincanny_module_allow_db_capture filter for reducing DB bloat

= 1.0.2 =
* Fix: JavaScript beacon's URL patterns (`/xapi/i`, `/statements/i`) were too broad, intercepting the beacon's own endpoint (creating an infinite loop) and unrelated network requests (JS files, font URLs).
* Fix: XHR `onreadystatechange` replacement broke Rise content that sets its handler after `send()`. Replaced with `addEventListener` to avoid interfering with Rise's XHR lifecycle.
* Fix: Synchronous `contentDocument` access in MutationObserver blocked the DOM mutation queue Rise uses to render slides, causing modules to appear stuck. All iframe hooks now deferred to after the `load` event.
* Fix: MutationObserver lacked `nodeType` check, causing errors on text/comment nodes.

= 1.0.1 =
* Fix: `rest_pre_dispatch` hook was intercepting all REST API requests including WordPress Site Health checks and dashboard widget endpoints, causing the WP dashboard health-check spinner to stall and info module accordions to become unresponsive. Now exits immediately for all non-Tin-Canny routes.
* Fix: Added guard to prevent recursive interception of the plugin's own beacon REST endpoint.

= 1.0.0 =
* Initial public release
* 5-layer statement capture (4 server-side hooks + JavaScript beacon)
* Automated diagnostic engine with completion mismatch and statement gap detection
* Admin dashboard with 5 tabs: Live Feed, Alerts, User Diagnostic, System Health, Settings
* Email alert system for critical failures
* REST API endpoints for beacon data and admin operations
* Force Complete, Reset xAPI, and Resend Statement admin actions
* Smart endpoint health check with Hostinger/LiteSpeed loopback fallback
* Configurable log retention with CSV export
* Clean uninstall (drops all custom tables and options)

== Upgrade Notice ==

= 1.0.0 =
Initial release. Install and activate to begin monitoring xAPI statement delivery on your LearnDash + Tin Canny site.
