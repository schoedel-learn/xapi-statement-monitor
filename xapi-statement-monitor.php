<?php
/**
 * Plugin Name: xAPI Statement Monitor
 * Plugin URI:  https://github.com/barryschoedel/xapi-statement-monitor
 * Description: Diagnoses xAPI completion tracking failures on LearnDash + Tin Canny sites. Intercepts, logs, and analyzes every xAPI statement in the pipeline from Articulate Rise (and other xAPI content) through Tin Canny to LearnDash completion.
 * Version:     1.2.0
 * Author:      Barry Schoedel
 * Author URI:  https://schoedel.design/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: xapi-monitor
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Tested up to: 6.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// CONSTANTS
// ============================================================
define( 'XAPI_MONITOR_VERSION',    '1.2.0' );
define( 'XAPI_MONITOR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'XAPI_MONITOR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'XAPI_MONITOR_PLUGIN_FILE', __FILE__ );

// ============================================================
// ACTIVATION / DEACTIVATION / UNINSTALL
// ============================================================
register_activation_hook( __FILE__,   [ 'XAPI_Monitor', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'XAPI_Monitor', 'deactivate' ] );

// ============================================================
// MAIN PLUGIN CLASS
// ============================================================
class XAPI_Monitor {

    /** @var XAPI_Monitor|null */
    private static ?XAPI_Monitor $instance = null;

    /** @var wpdb */
    private wpdb $db;

    /** @var string */
    private string $log_table;

    /** @var string */
    private string $alerts_table;

    // Holds partial incoming request data before the result hook fires
    private array $pending_request = [];

    // --------------------------------------------------------
    // SINGLETON
    // --------------------------------------------------------
    public static function instance(): XAPI_Monitor {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->db           = $wpdb;
        $this->log_table    = $wpdb->prefix . 'xapi_monitor_log';
        $this->alerts_table = $wpdb->prefix . 'xapi_monitor_alerts';

        $this->init_hooks();
    }

    // --------------------------------------------------------
    // ACTIVATION
    // --------------------------------------------------------
    public static function activate(): void {
        self::create_tables();
        // Register the custom cron interval BEFORE scheduling,
        // because the cron_schedules filter isn't loaded yet during activation.
        add_filter( 'cron_schedules', function( $s ) {
            $s['xapi_monitor_15min'] = [
                'interval' => 900,
                'display'  => 'Every 15 Minutes',
            ];
            return $s;
        } );
        self::schedule_crons();
        add_option( 'xapi_monitor_version', XAPI_MONITOR_VERSION );
        add_option( 'xapi_monitor_settings', self::default_settings() );
    }

    // --------------------------------------------------------
    // DEACTIVATION
    // --------------------------------------------------------
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'xapi_monitor_diagnostic_cron' );
        wp_clear_scheduled_hook( 'xapi_monitor_cleanup_cron' );
        wp_clear_scheduled_hook( 'xapi_monitor_endpoint_health_cron' );
    }

    // --------------------------------------------------------
    // DEFAULT SETTINGS
    // --------------------------------------------------------
    private static function default_settings(): array {
        return [
            'alert_emails'            => get_option( 'admin_email', '' ),
            'gap_timeout_minutes'     => 30,
            'failure_rate_threshold'  => 10,
            'health_check_interval'   => 15,
            'js_beacon_enabled'       => true,
            'log_retention_days'      => 30,
            'email_notifications'     => true,
            'diagnostic_schedule'     => '15min',
        ];
    }

    // --------------------------------------------------------
    // TABLE CREATION
    // --------------------------------------------------------
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $log_table       = $wpdb->prefix . 'xapi_monitor_log';
        $alerts_table    = $wpdb->prefix . 'xapi_monitor_alerts';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_log = "CREATE TABLE {$log_table} (
            id                    bigint(20)   NOT NULL AUTO_INCREMENT,
            timestamp             datetime     NOT NULL,
            user_id               bigint(20)   NOT NULL DEFAULT 0,
            user_email            varchar(255) NOT NULL DEFAULT '',
            user_display_name     varchar(255) NOT NULL DEFAULT '',
            course_id             bigint(20)   NOT NULL DEFAULT 0,
            course_title          varchar(255) NOT NULL DEFAULT '',
            lesson_id             bigint(20)   NOT NULL DEFAULT 0,
            lesson_title          varchar(255) NOT NULL DEFAULT '',
            verb                  varchar(100) NOT NULL DEFAULT '',
            verb_display          varchar(100) NOT NULL DEFAULT '',
            activity_id           text         NOT NULL,
            activity_name         varchar(255) NOT NULL DEFAULT '',
            result_success        varchar(20)  NOT NULL DEFAULT '',
            result_completion     varchar(20)  NOT NULL DEFAULT '',
            result_score_raw      varchar(20)  NOT NULL DEFAULT '',
            result_score_scaled   varchar(20)  NOT NULL DEFAULT '',
            raw_statement         longtext     NOT NULL,
            source                varchar(50)  NOT NULL DEFAULT '',
            endpoint_response_code int(11)     NOT NULL DEFAULT 0,
            endpoint_response_body text        NOT NULL,
            delivery_status       varchar(30)  NOT NULL DEFAULT 'captured',
            failure_reason        text         NOT NULL,
            user_agent            text         NOT NULL,
            ip_address            varchar(45)  NOT NULL DEFAULT '',
            referer_url           text         NOT NULL,
            session_id            varchar(100) NOT NULL DEFAULT '',
            created_at            datetime     NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_timestamp  (timestamp),
            KEY idx_user_id    (user_id),
            KEY idx_verb       (verb),
            KEY idx_course_id  (course_id),
            KEY idx_lesson_id  (lesson_id),
            KEY idx_status     (delivery_status)
        ) {$charset_collate};";

        $sql_alerts = "CREATE TABLE {$alerts_table} (
            id               bigint(20)   NOT NULL AUTO_INCREMENT,
            timestamp        datetime     NOT NULL,
            alert_type       varchar(50)  NOT NULL DEFAULT '',
            severity         varchar(20)  NOT NULL DEFAULT 'info',
            user_id          bigint(20)   NOT NULL DEFAULT 0,
            course_id        bigint(20)   NOT NULL DEFAULT 0,
            lesson_id        bigint(20)   NOT NULL DEFAULT 0,
            message          text         NOT NULL,
            diagnostic_data  longtext     NOT NULL,
            resolved         tinyint(1)   NOT NULL DEFAULT 0,
            resolved_by      bigint(20)   NOT NULL DEFAULT 0,
            resolved_at      datetime     DEFAULT NULL,
            resolution_notes text         NOT NULL DEFAULT '',
            evidence         longtext     NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY idx_timestamp  (timestamp),
            KEY idx_type       (alert_type),
            KEY idx_severity   (severity),
            KEY idx_user_id    (user_id),
            KEY idx_resolved   (resolved)
        ) {$charset_collate};";

        dbDelta( $sql_log );
        dbDelta( $sql_alerts );
    }

    // --------------------------------------------------------
    // SCHEDULE CRONS
    // --------------------------------------------------------
    private static function schedule_crons(): void {
        if ( ! wp_next_scheduled( 'xapi_monitor_diagnostic_cron' ) ) {
            wp_schedule_event( time(), 'xapi_monitor_15min', 'xapi_monitor_diagnostic_cron' );
        }
        if ( ! wp_next_scheduled( 'xapi_monitor_cleanup_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'xapi_monitor_cleanup_cron' );
        }
        if ( ! wp_next_scheduled( 'xapi_monitor_endpoint_health_cron' ) ) {
            wp_schedule_event( time(), 'xapi_monitor_15min', 'xapi_monitor_endpoint_health_cron' );
        }
    }

    // --------------------------------------------------------
    // HOOKS INIT
    // --------------------------------------------------------
    private function init_hooks(): void {
        // Admin UI
        add_action( 'admin_menu',             [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_admin_assets' ] );

        // Tin Canny hooks (passive observation only)
        add_action( 'tincanny_before_process_request',  [ $this, 'log_incoming_request' ],    10, 0 );
        add_action( 'tincanny_module_result_processed', [ $this, 'log_processed_result' ],    10, 0 );
        add_filter( 'tincanny_module_allow_db_capture', [ $this, 'monitor_capture_decision' ], 10, 1 );

        // REST API intercept for /ucTinCan
        add_filter( 'rest_pre_dispatch',      [ $this, 'intercept_rest_request' ],    5, 3 );
        add_action( 'rest_api_init',          [ $this, 'register_rest_routes' ] );

        // JS beacon on front-end lesson/topic pages
        add_action( 'wp_enqueue_scripts',     [ $this, 'enqueue_beacon_script' ] );

        // AJAX handlers
        add_action( 'wp_ajax_xapi_monitor_force_complete',   [ $this, 'ajax_force_complete' ] );
        add_action( 'wp_ajax_xapi_monitor_reset_xapi',       [ $this, 'ajax_reset_xapi' ] );
        add_action( 'wp_ajax_xapi_monitor_resend_statement', [ $this, 'ajax_resend_statement' ] );
        add_action( 'wp_ajax_xapi_monitor_run_diagnostic',   [ $this, 'ajax_run_diagnostic' ] );
        add_action( 'wp_ajax_xapi_monitor_resolve_alert',    [ $this, 'ajax_resolve_alert' ] );
        add_action( 'wp_ajax_xapi_monitor_purge_logs',       [ $this, 'ajax_purge_logs' ] );
        add_action( 'wp_ajax_xapi_monitor_delete_all_data',   [ $this, 'ajax_delete_all_data' ] );
        add_action( 'wp_ajax_xapi_monitor_test_endpoint',    [ $this, 'ajax_test_endpoint' ] );
        add_action( 'wp_ajax_xapi_monitor_export_csv',       [ $this, 'ajax_export_csv' ] );

        // Cron handlers
        add_action( 'xapi_monitor_diagnostic_cron',      [ $this, 'run_diagnostic_comparison' ] );
        add_action( 'xapi_monitor_cleanup_cron',         [ $this, 'run_cleanup' ] );
        add_action( 'xapi_monitor_endpoint_health_cron', [ $this, 'run_endpoint_health_check' ] );

        // Custom cron schedule
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

        // Settings API
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Plugin action links
        add_filter( 'plugin_action_links_' . plugin_basename( XAPI_MONITOR_PLUGIN_FILE ),
            [ $this, 'add_plugin_action_links' ] );

        // Version upgrade check
        add_action( 'plugins_loaded', [ $this, 'maybe_upgrade' ] );
    }

    // --------------------------------------------------------
    // CRON SCHEDULES
    // --------------------------------------------------------
    public function add_cron_schedules( array $schedules ): array {
        $schedules['xapi_monitor_15min'] = [
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'xapi-monitor' ),
        ];
        return $schedules;
    }

    // --------------------------------------------------------
    // VERSION UPGRADE
    // --------------------------------------------------------
    public function maybe_upgrade(): void {
        $installed = get_option( 'xapi_monitor_version', '0' );
        if ( version_compare( $installed, XAPI_MONITOR_VERSION, '<' ) ) {
            self::create_tables();
            self::schedule_crons(); // Re-schedule in case activation didn't register them
            // v1.2.0: add evidence column to alerts table for existing installs
            if ( version_compare( $installed, '1.2.0', '<' ) ) {
                $this->maybe_add_evidence_column();
            }
            update_option( 'xapi_monitor_version', XAPI_MONITOR_VERSION );
        }
    }

    /**
     * Add the evidence column to the alerts table if it doesn't exist.
     * Uses ALTER TABLE ... ADD COLUMN pattern safe for repeated execution.
     */
    private function maybe_add_evidence_column(): void {
        $col_exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = %s
                   AND TABLE_NAME   = %s
                   AND COLUMN_NAME  = 'evidence'",
                DB_NAME,
                $this->alerts_table
            )
        );
        if ( ! $col_exists ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->db->query(
                "ALTER TABLE `{$this->alerts_table}` ADD COLUMN `evidence` longtext NOT NULL DEFAULT ''"
            );
            // phpcs:enable
        }
    }

    // ============================================================
    // SECTION 1: TIN CANNY HOOK INTERCEPTORS
    // ============================================================

    /**
     * Fires BEFORE Tin Canny processes an incoming xAPI request.
     * Captures raw POST data and global context.
     */
    public function log_incoming_request(): void {
        // phpcs:disable WordPress.Security.NonceVerification
        $raw_body = file_get_contents( 'php://input' );
        if ( empty( $raw_body ) ) {
            $raw_body = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';  // phpcs:ignore
        }

        $statement = json_decode( $raw_body, true );
        if ( ! is_array( $statement ) ) {
            // Try unwrapping array of statements
            if ( is_array( json_decode( $raw_body ) ) ) {
                $arr = json_decode( $raw_body, true );
                $statement = $arr[0] ?? null;
            }
        }

        if ( empty( $statement ) ) {
            return;
        }

        $user      = wp_get_current_user();
        $referer   = wp_get_referer();
        $course_id = 0;
        $lesson_id = 0;

        // Try to resolve course/lesson from referer URL
        if ( $referer ) {
            $post_id = url_to_postid( $referer );
            if ( $post_id ) {
                $post_type = get_post_type( $post_id );
                if ( in_array( $post_type, [ 'sfwd-lessons', 'sfwd-topic' ], true ) ) {
                    $lesson_id = $post_id;
                    $course_id = (int) learndash_get_course_id( $post_id );
                } elseif ( 'sfwd-courses' === $post_type ) {
                    $course_id = $post_id;
                }
            }
        }

        $this->pending_request = [
            'raw_statement'   => $raw_body,
            'parsed'          => $statement,
            'user_id'         => $user->ID,
            'user_email'      => $user->user_email,
            'user_display'    => $user->display_name,
            'course_id'       => $course_id,
            'lesson_id'       => $lesson_id,
            'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
            'ip_address'      => $this->get_client_ip(),
            'referer_url'     => $referer ?: '',
            'session_id'      => session_id() ?: wp_generate_password( 32, false ),
            'source'          => 'tin_canny_hook',
            'timestamp'       => current_time( 'mysql' ),
        ];
        // phpcs:enable
    }

    /**
     * Fires AFTER Tin Canny has processed a module result.
     * Confirms successful delivery.
     */
    public function log_processed_result(): void {
        if ( empty( $this->pending_request ) ) {
            return;
        }

        $req       = $this->pending_request;
        $statement = $req['parsed'] ?? [];

        $this->insert_log_entry( $req, $statement, 'processed', '' );
        $this->pending_request = [];
    }

    /**
     * Passive filter — monitors whether Tin Canny decided to capture.
     * Never modifies the return value.
     *
     * @param mixed $allow Original value passed by Tin Canny.
     * @return mixed Unmodified original value.
     */
    public function monitor_capture_decision( $allow ) {
        if ( ! empty( $this->pending_request ) && ! $allow ) {
            $req       = $this->pending_request;
            $statement = $req['parsed'] ?? [];
            $this->insert_log_entry( $req, $statement, 'blocked', 'Tin Canny returned false for tincanny_module_allow_db_capture' );
            $this->pending_request = [];
        }
        return $allow; // Always return unchanged
    }

    /**
     * Intercepts REST API requests to /ucTinCan or Tin Canny endpoints.
     * Passive observation only.
     *
     * @param mixed            $result   Current response (null = not handled yet).
     * @param WP_REST_Server   $server   REST server.
     * @param WP_REST_Request  $request  Incoming request.
     * @return mixed Unchanged $result.
     */
    public function intercept_rest_request( $result, WP_REST_Server $server, WP_REST_Request $request ) {
        // IMPORTANT: This hook fires on EVERY REST request (site-health checks,
        // dashboard widgets, Heartbeat, our own beacon, etc.). Return immediately
        // for anything that is not a Tin Canny xAPI endpoint — doing any work here
        // on unrelated requests was causing the WP dashboard health-check spinner
        // to stall and dashboard info modules to not respond.
        $route = $request->get_route();

        // Hard-coded fast-path exclusions — bail immediately on known WP core routes
        // that must never be slowed down.
        $excluded_prefixes = [
            '/wp/v2',
            '/wp-site-health',
            '/wp/v1',
            '/oembed',
            '/xapi-monitor', // Our own beacon — must not create a recursive loop
            '/wp-block-editor',
        ];
        foreach ( $excluded_prefixes as $prefix ) {
            if ( 0 === strpos( $route, $prefix ) ) {
                return $result;
            }
        }

        // Only intercept Tin Canny xAPI endpoint
        if ( false === strpos( $route, 'ucTinCan' ) &&
             false === strpos( $route, 'tincanny' ) &&
             false === strpos( $route, 'tin-canny' ) ) {
            return $result;
        }

        $body = $request->get_body();
        if ( empty( $body ) ) {
            return $result;
        }

        $statement = json_decode( $body, true );
        if ( ! is_array( $statement ) ) {
            $arr = json_decode( $body, true );
            if ( is_array( $arr ) ) {
                $statement = $arr[0] ?? null;
            }
        }

        if ( empty( $statement ) ) {
            return $result;
        }

        $user      = wp_get_current_user();
        $referer   = $request->get_header( 'referer' ) ?: '';
        $course_id = 0;
        $lesson_id = 0;

        if ( $referer ) {
            $post_id = url_to_postid( $referer );
            if ( $post_id ) {
                $post_type = get_post_type( $post_id );
                if ( in_array( $post_type, [ 'sfwd-lessons', 'sfwd-topic' ], true ) ) {
                    $lesson_id = $post_id;
                    $course_id = function_exists( 'learndash_get_course_id' )
                        ? (int) learndash_get_course_id( $post_id )
                        : 0;
                }
            }
        }

        $req = [
            'raw_statement' => $body,
            'parsed'        => $statement,
            'user_id'       => $user->ID,
            'user_email'    => $user->user_email,
            'user_display'  => $user->display_name,
            'course_id'     => $course_id,
            'lesson_id'     => $lesson_id,
            'user_agent'    => $request->get_header( 'user-agent' ) ?: '',
            'ip_address'    => $this->get_client_ip(),
            'referer_url'   => $referer,
            'session_id'    => session_id() ?: wp_generate_password( 32, false ),
            'source'        => 'endpoint_intercept',
            'timestamp'     => current_time( 'mysql' ),
        ];

        // Mark as 'captured'; the hook intercepts will update to 'processed' if Tin Canny handles it
        $this->insert_log_entry( $req, $statement, 'captured', '' );

        return $result; // Never modify the result
    }

    // ============================================================
    // SECTION 2: LOG ENTRY WRITER
    // ============================================================

    private function insert_log_entry( array $req, array $statement, string $status, string $failure_reason ): void {
        $now = current_time( 'mysql' );

        // Parse statement fields
        $verb_id       = $statement['verb']['id'] ?? '';
        $verb_display  = '';
        if ( isset( $statement['verb']['display'] ) && is_array( $statement['verb']['display'] ) ) {
            $verb_display = reset( $statement['verb']['display'] );
        }
        $verb_short    = $this->extract_verb_name( $verb_id );

        $activity_id   = $statement['object']['id'] ?? '';
        $activity_name = '';
        if ( isset( $statement['object']['definition']['name'] ) && is_array( $statement['object']['definition']['name'] ) ) {
            $activity_name = reset( $statement['object']['definition']['name'] );
        }

        $result_success    = '';
        $result_completion = '';
        $result_score_raw  = '';
        $result_score_scl  = '';
        if ( isset( $statement['result'] ) ) {
            $result_success    = isset( $statement['result']['success'] ) ? ( $statement['result']['success'] ? 'true' : 'false' ) : '';
            $result_completion = isset( $statement['result']['completion'] ) ? ( $statement['result']['completion'] ? 'true' : 'false' ) : '';
            $result_score_raw  = (string) ( $statement['result']['score']['raw'] ?? '' );
            $result_score_scl  = (string) ( $statement['result']['score']['scaled'] ?? '' );
        }

        // Course / lesson metadata
        $course_title = '';
        $lesson_title = '';
        $course_id    = (int) ( $req['course_id'] ?? 0 );
        $lesson_id    = (int) ( $req['lesson_id'] ?? 0 );

        if ( $course_id ) {
            $course_title = get_the_title( $course_id ) ?: '';
        }
        if ( $lesson_id ) {
            $lesson_title = get_the_title( $lesson_id ) ?: '';
        }

        $this->db->insert(
            $this->log_table,
            [
                'timestamp'             => $req['timestamp'] ?? $now,
                'user_id'               => (int) ( $req['user_id'] ?? 0 ),
                'user_email'            => sanitize_email( $req['user_email'] ?? '' ),
                'user_display_name'     => sanitize_text_field( $req['user_display'] ?? '' ),
                'course_id'             => $course_id,
                'course_title'          => sanitize_text_field( $course_title ),
                'lesson_id'             => $lesson_id,
                'lesson_title'          => sanitize_text_field( $lesson_title ),
                'verb'                  => sanitize_text_field( $verb_short ),
                'verb_display'          => sanitize_text_field( $verb_display ),
                'activity_id'           => esc_url_raw( $activity_id ),
                'activity_name'         => sanitize_text_field( $activity_name ),
                'result_success'        => sanitize_text_field( $result_success ),
                'result_completion'     => sanitize_text_field( $result_completion ),
                'result_score_raw'      => sanitize_text_field( $result_score_raw ),
                'result_score_scaled'   => sanitize_text_field( $result_score_scl ),
                'raw_statement'         => $req['raw_statement'] ?? '',
                'source'                => sanitize_text_field( $req['source'] ?? '' ),
                'endpoint_response_code'=> (int) ( $req['endpoint_response_code'] ?? 0 ),
                'endpoint_response_body'=> $req['endpoint_response_body'] ?? '',
                'delivery_status'       => sanitize_text_field( $status ),
                'failure_reason'        => sanitize_text_field( $failure_reason ),
                'user_agent'            => sanitize_text_field( $req['user_agent'] ?? '' ),
                'ip_address'            => sanitize_text_field( $req['ip_address'] ?? '' ),
                'referer_url'           => esc_url_raw( $req['referer_url'] ?? '' ),
                'session_id'            => sanitize_text_field( $req['session_id'] ?? '' ),
                'created_at'            => $now,
            ],
            [
                '%s','%d','%s','%s','%d','%s','%d','%s',
                '%s','%s','%s','%s','%s','%s','%s','%s',
                '%s','%s','%d','%s','%s','%s','%s','%s',
                '%s','%s','%s',
            ]
        );
    }

    /**
     * Extract the short verb name from a full IRI.
     * e.g. http://adlnet.gov/expapi/verbs/completed → completed
     */
    private function extract_verb_name( string $iri ): string {
        if ( empty( $iri ) ) {
            return '';
        }
        $parts = explode( '/', rtrim( $iri, '/' ) );
        return end( $parts );
    }

    /**
     * Get the real client IP, accounting for proxies.
     */
    private function get_client_ip(): string {
        $keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // X-Forwarded-For can be comma-separated
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }

    // ============================================================
    // SECTION 3: JS BEACON
    // ============================================================

    public function enqueue_beacon_script(): void {
        $settings = get_option( 'xapi_monitor_settings', self::default_settings() );
        if ( empty( $settings['js_beacon_enabled'] ) ) {
            return;
        }

        // Only on LearnDash lesson/topic/course pages
        if ( ! is_singular( [ 'sfwd-lessons', 'sfwd-topic', 'sfwd-courses' ] ) ) {
            return;
        }

        $nonce      = wp_create_nonce( 'xapi_monitor_beacon' );
        $beacon_url = rest_url( 'xapi-monitor/v1/beacon' );
        $user_id    = get_current_user_id();

        // Inline the beacon script
        add_action( 'wp_footer', function() use ( $nonce, $beacon_url, $user_id ) {
            $this->render_beacon_script( $nonce, $beacon_url, $user_id );
        }, 99 );
    }

    private function render_beacon_script( string $nonce, string $beacon_url, int $user_id ): void {
        // Pass the beacon URL to JS so it can exclude itself from interception.
        $beacon_url_js = rest_url( 'xapi-monitor/v1/beacon' );
        ?>
<script id="xapi-monitor-beacon" type="text/javascript">
(function() {
    'use strict';

    var BEACON_URL   = <?php echo wp_json_encode( $beacon_url_js ); ?>;
    var BEACON_NONCE = <?php echo wp_json_encode( $nonce ); ?>;
    var USER_ID      = <?php echo (int) $user_id; ?>;

    // ---------------------------------------------------------------------------
    // URL MATCHING — deliberately narrow to avoid false positives.
    //
    // REMOVED  /xapi/i    — too broad: matched the beacon's own URL
    //                        (/xapi-monitor/v1/beacon) causing an infinite loop
    //                        where the beacon intercepted its own POST and sent
    //                        another beacon about it, recursively.
    //
    // REMOVED  /statements/i — too broad: matched any URL containing the word
    //                          "statements" (JS filenames, font URLs, REST routes
    //                          unrelated to xAPI statement delivery).
    //
    // KEPT     /ucTinCan/i  — specific to the Tin Canny virtual endpoint.
    // KEPT     /tincan/i    — specific enough; covers ucTinCan and tincan paths.
    // ADDED    exact path suffixes for known xAPI LRS statement endpoints.
    // ---------------------------------------------------------------------------
    var XAPI_PATTERNS = [
        /ucTinCan/i,
        /tincan/i,
        /\/statements\//,        // xAPI LRS path segment (e.g. /xapi/statements/)
        /[?&]ucTinCan/i          // query-string form (?ucTinCan=1)
    ];

    // Hard exclusions — URLs that must NEVER be intercepted regardless of pattern match.
    // Prevents the beacon from intercepting its own outbound POST (infinite loop).
    var EXCLUSIONS = [
        BEACON_URL
    ];

    function isXapiUrl(url) {
        if (!url) return false;
        // Exclude the beacon's own endpoint first
        for (var i = 0; i < EXCLUSIONS.length; i++) {
            if (url.indexOf(EXCLUSIONS[i]) !== -1) return false;
        }
        // Must be a POST to a recognised xAPI delivery URL
        return XAPI_PATTERNS.some(function(p) { return p.test(url); });
    }

    function sendBeacon(data) {
        var payload = Object.assign({
            user_id:   USER_ID,
            timestamp: new Date().toISOString(),
            online:    navigator.onLine,
            page_url:  window.location.href
        }, data);

        // Use sendBeacon for reliability on page unload
        if (navigator.sendBeacon) {
            var blob = new Blob(
                [JSON.stringify(payload)],
                { type: 'application/json' }
            );
            navigator.sendBeacon(BEACON_URL + '?nonce=' + BEACON_NONCE, blob);
        } else {
            // Fallback XHR
            var xhr = new XMLHttpRequest();
            xhr.open('POST', BEACON_URL, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-WP-Nonce', BEACON_NONCE);
            xhr.send(JSON.stringify(payload));
        }
    }

    // -------------------------------------------------------
    // XHR INTERCEPT (parent window)
    // -------------------------------------------------------
    var OrigXHR   = window.XMLHttpRequest;
    var origOpen  = OrigXHR.prototype.open;
    var origSend  = OrigXHR.prototype.send;

    OrigXHR.prototype.open = function(method, url) {
        this._xapiMonitor = { method: method, url: url };
        origOpen.apply(this, arguments);
    };

    OrigXHR.prototype.send = function(body) {
        var self = this;
        var meta = this._xapiMonitor || {};

        if (isXapiUrl(meta.url) && meta.method === 'POST') {
            var startTime  = Date.now();
            var beaconSent = false; // guard: only send once per request

            var statementData = {};
            try {
                var parsed = JSON.parse(body);
                if (Array.isArray(parsed)) parsed = parsed[0];
                if (parsed && parsed.verb) {
                    statementData.verb_id     = parsed.verb.id || '';
                    statementData.activity_id = (parsed.object && parsed.object.id) ? parsed.object.id : '';
                }
            } catch(e) {}

            function reportXhr(status) {
                if (beaconSent) return;
                beaconSent = true;
                sendBeacon(Object.assign({
                    source:        'xhr_intercept',
                    url:           meta.url,
                    status_code:   status,
                    response_text: status >= 400 ? (self.responseText || '').substring(0, 500) : '',
                    elapsed_ms:    Date.now() - startTime,
                    timed_out:     (Date.now() - startTime) > 10000
                }, statementData));
            }

            // Use addEventListener (not onreadystatechange replacement) so we
            // don't break Rise's own handler even if it is set after send().
            this.addEventListener('load', function() { reportXhr(self.status); });
            this.addEventListener('error', function() { reportXhr(0); });
            this.addEventListener('timeout', function() { reportXhr(0); });

            // Fallback: also watch readystatechange in case 'load' doesn't fire
            // (some older browsers / XHR implementations).
            this.addEventListener('readystatechange', function() {
                if (self.readyState === 4) { reportXhr(self.status); }
            });
        }
        origSend.apply(this, arguments);
    };

    // -------------------------------------------------------
    // FETCH INTERCEPT (parent window)
    // -------------------------------------------------------
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function(input, init) {
            var url    = (typeof input === 'string') ? input : (input.url || '');
            var method = (init && init.method) ? init.method.toUpperCase() : 'GET';

            if (isXapiUrl(url) && method === 'POST') {
                var startTime   = Date.now();
                var bodyContent = (init && init.body) ? init.body : '';
                var statementData = {};
                try {
                    var parsed = JSON.parse(bodyContent);
                    if (Array.isArray(parsed)) parsed = parsed[0];
                    if (parsed && parsed.verb) {
                        statementData.verb_id     = parsed.verb.id || '';
                        statementData.activity_id = (parsed.object && parsed.object.id) ? parsed.object.id : '';
                    }
                } catch(e) {}

                return origFetch.apply(this, arguments).then(function(response) {
                    var elapsed = Date.now() - startTime;
                    var clone   = response.clone();
                    var payload = Object.assign({
                        source:      'fetch_intercept',
                        url:         url,
                        status_code: response.status,
                        elapsed_ms:  elapsed,
                        timed_out:   false
                    }, statementData);

                    if (response.status >= 400) {
                        clone.text().then(function(text) {
                            payload.response_text = text.substring(0, 500);
                            sendBeacon(payload);
                        }).catch(function() { sendBeacon(payload); });
                    } else {
                        sendBeacon(payload);
                    }
                    return response;
                }).catch(function(err) {
                    sendBeacon(Object.assign({
                        source:      'fetch_intercept',
                        url:         url,
                        status_code: 0,
                        error:       err.message || 'network_error',
                        elapsed_ms:  Date.now() - startTime
                    }, statementData));
                    throw err;
                });
            }
            return origFetch.apply(this, arguments);
        };
    }

    // -------------------------------------------------------
    // IFRAME HOOKING via MutationObserver
    // -------------------------------------------------------
    function hookIframe(iframe) {
        try {
            // Only attempt if same-origin
            var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
            if (!iframeDoc) return;

            var iframeWin = iframe.contentWindow;
            if (!iframeWin) return;

            // Patch XHR in iframe
            var IXhr = iframeWin.XMLHttpRequest;
            if (IXhr) {
                var iOrigOpen = IXhr.prototype.open;
                var iOrigSend = IXhr.prototype.send;
                IXhr.prototype.open = function(method, url) {
                    this._xapiMonitor = { method: method, url: url };
                    iOrigOpen.apply(this, arguments);
                };
                IXhr.prototype.send = function(body) {
                    var self = this;
                    var meta = this._xapiMonitor || {};
                    if (isXapiUrl(meta.url) && meta.method === 'POST') {
                        var startTime = Date.now();
                        var origRSC   = this.onreadystatechange;
                        this.onreadystatechange = function() {
                            if (self.readyState === 4) {
                                var statementData = {};
                                try {
                                    var p = JSON.parse(body);
                                    if (Array.isArray(p)) p = p[0];
                                    if (p && p.verb) {
                                        statementData.verb_id     = p.verb.id || '';
                                        statementData.activity_id = (p.object && p.object.id) ? p.object.id : '';
                                    }
                                } catch(e) {}
                                sendBeacon(Object.assign({
                                    source:      'iframe_xhr',
                                    url:         meta.url,
                                    status_code: self.status,
                                    elapsed_ms:  Date.now() - startTime
                                }, statementData));
                            }
                            if (origRSC) origRSC.apply(self, arguments);
                        };
                    }
                    iOrigSend.apply(this, arguments);
                };
            }

            // Patch fetch in iframe
            if (iframeWin.fetch) {
                var iOrigFetch = iframeWin.fetch;
                iframeWin.fetch = function(input, init) {
                    var url    = (typeof input === 'string') ? input : (input.url || '');
                    var method = (init && init.method) ? init.method.toUpperCase() : 'GET';
                    if (isXapiUrl(url) && method === 'POST') {
                        var startTime = Date.now();
                        var bodyContent = (init && init.body) ? init.body : '';
                        var statementData = {};
                        try {
                            var p = JSON.parse(bodyContent);
                            if (Array.isArray(p)) p = p[0];
                            if (p && p.verb) {
                                statementData.verb_id     = p.verb.id || '';
                                statementData.activity_id = (p.object && p.object.id) ? p.object.id : '';
                            }
                        } catch(e) {}
                        return iOrigFetch.apply(this, arguments).then(function(response) {
                            sendBeacon(Object.assign({
                                source:      'iframe_fetch',
                                url:         url,
                                status_code: response.status,
                                elapsed_ms:  Date.now() - startTime
                            }, statementData));
                            return response;
                        }).catch(function(err) {
                            sendBeacon(Object.assign({
                                source:      'iframe_fetch',
                                url:         url,
                                status_code: 0,
                                error:       err.message,
                                elapsed_ms:  Date.now() - startTime
                            }, statementData));
                            throw err;
                        });
                    }
                    return iOrigFetch.apply(this, arguments);
                };
            }
        } catch(e) {
            // Cross-origin — cannot hook; postMessage listener below handles this case
        }
    }

    // Hook existing iframes.
    // IMPORTANT: Never access contentDocument synchronously in a MutationObserver
    // callback or before the 'load' event — it blocks the DOM mutation queue
    // that Rise uses to render slides, causing the module to appear stuck.
    document.querySelectorAll('iframe').forEach(function(iframe) {
        // Always attach via load event, never synchronously.
        // If the iframe is already complete, the load event has already fired,
        // so we use a short async defer to avoid blocking the current call stack.
        if (iframe.complete || (iframe.contentDocument && iframe.contentDocument.readyState === 'complete')) {
            setTimeout(function() { hookIframe(iframe); }, 0);
        } else {
            iframe.addEventListener('load', function() {
                // Defer by one tick so the iframe's own load handlers run first
                setTimeout(function() { hookIframe(iframe); }, 0);
            });
        }
    });

    // Watch for dynamically added iframes (e.g. Tin Canny injecting the Rise iframe).
    // Only attach a 'load' listener — never access contentDocument in the observer
    // callback itself, as the iframe hasn't loaded yet at that point.
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return; // element nodes only
                if (node.tagName === 'IFRAME') {
                    node.addEventListener('load', function() {
                        setTimeout(function() { hookIframe(node); }, 0);
                    });
                } else if (node.querySelectorAll) {
                    node.querySelectorAll('iframe').forEach(function(iframe) {
                        iframe.addEventListener('load', function() {
                            setTimeout(function() { hookIframe(iframe); }, 0);
                        });
                    });
                }
            });
        });
    });
    // childList + subtree is sufficient; avoid attributeFilter on all nodes.
    observer.observe(document.body, { childList: true, subtree: true });

    // -------------------------------------------------------
    // postMessage listener (cross-origin Rise fallback)
    // -------------------------------------------------------
    window.addEventListener('message', function(event) {
        // Rise and scorm packages may post completion messages
        var data = event.data;
        if (!data) return;
        if (typeof data === 'string') {
            try { data = JSON.parse(data); } catch(e) { return; }
        }
        // Look for xAPI-shaped messages
        if (data.verb || (data.statement && data.statement.verb)) {
            var stmt = data.statement || data;
            var verbId = (stmt.verb && stmt.verb.id) ? stmt.verb.id : '';
            var actId  = (stmt.object && stmt.object.id) ? stmt.object.id : '';
            sendBeacon({
                source:       'postmessage',
                verb_id:      verbId,
                activity_id:  actId,
                status_code:  0,
                message_data: JSON.stringify(data).substring(0, 1000)
            });
        }
    });

    // -------------------------------------------------------
    // Catch uncaught JS errors on the page
    // -------------------------------------------------------
    window.addEventListener('error', function(event) {
        sendBeacon({
            source:    'js_error',
            message:   (event.message || '').substring(0, 500),
            filename:  (event.filename || '').substring(0, 200),
            lineno:    event.lineno || 0,
            colno:     event.colno || 0
        });
    });

    // -------------------------------------------------------
    // Online/offline detection
    // -------------------------------------------------------
    window.addEventListener('offline', function() {
        sendBeacon({ source: 'connectivity', status: 'offline' });
    });
    window.addEventListener('online', function() {
        sendBeacon({ source: 'connectivity', status: 'online' });
    });

})();
</script>
        <?php
    }

    // ============================================================
    // SECTION 4: REST API ROUTES
    // ============================================================

    public function register_rest_routes(): void {
        register_rest_route( 'xapi-monitor/v1', '/beacon', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_receive_beacon' ],
            'permission_callback' => '__return_true', // nonce checked in callback
        ] );

        register_rest_route( 'xapi-monitor/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_status' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
        ] );

        register_rest_route( 'xapi-monitor/v1', '/user/(?P<id>\d+)/diagnostics', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_user_diagnostics' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
            'args'                => [
                'id' => [ 'validate_callback' => 'is_numeric' ],
            ],
        ] );

        register_rest_route( 'xapi-monitor/v1', '/fix/complete', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_force_complete' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
        ] );

        register_rest_route( 'xapi-monitor/v1', '/fix/reset', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_reset_xapi' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
        ] );

        register_rest_route( 'xapi-monitor/v1', '/diagnostics/run', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_run_diagnostics' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
        ] );

        // v1.2.0: Log & Alerts REST API
        register_rest_route( 'xapi-monitor/v1', '/logs', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_logs' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
            'args'                => [
                'per_page'  => [ 'default' => 50,  'sanitize_callback' => 'absint' ],
                'page'      => [ 'default' => 1,   'sanitize_callback' => 'absint' ],
                'user_id'   => [ 'default' => 0,   'sanitize_callback' => 'absint' ],
                'lesson_id' => [ 'default' => 0,   'sanitize_callback' => 'absint' ],
                'status'    => [ 'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
                'days'      => [ 'default' => 30,  'sanitize_callback' => 'absint' ],
            ],
        ] );

        register_rest_route( 'xapi-monitor/v1', '/logs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_log_single' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
            'args'                => [
                'id' => [ 'validate_callback' => 'is_numeric' ],
            ],
        ] );

        register_rest_route( 'xapi-monitor/v1', '/alerts', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_alerts' ],
            'permission_callback' => [ $this, 'rest_admin_only' ],
            'args'                => [
                'status'   => [ 'default' => 'open', 'sanitize_callback' => 'sanitize_text_field' ],
                'per_page' => [ 'default' => 50,     'sanitize_callback' => 'absint' ],
                'page'     => [ 'default' => 1,      'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    public function rest_admin_only(): bool {
        return current_user_can( 'manage_options' );
    }

    /** POST /beacon — receives JS beacon data */
    public function rest_receive_beacon( WP_REST_Request $request ): WP_REST_Response {
        // Verify nonce (passed as query param for sendBeacon compatibility)
        $nonce = $request->get_param( 'nonce' );
        if ( ! $nonce ) {
            $nonce = $request->get_header( 'x-wp-nonce' );
        }
        if ( ! wp_verify_nonce( $nonce, 'xapi_monitor_beacon' ) ) {
            return new WP_REST_Response( [ 'error' => 'invalid_nonce' ], 403 );
        }

        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            return new WP_REST_Response( [ 'ok' => false ], 400 );
        }

        $now  = current_time( 'mysql' );
        $user = wp_get_current_user();

        // Determine status based on beacon data
        $source      = sanitize_text_field( $body['source'] ?? 'unknown' );
        $status_code = (int) ( $body['status_code'] ?? 0 );

        // Connectivity & JS error events are informational, not xAPI delivery reports.
        $info_sources = [ 'connectivity', 'js_error' ];

        if ( in_array( $source, $info_sources, true ) ) {
            $status = 'captured'; // informational — always treated as successfully received
            $reason = '';
        } elseif ( ! empty( $body['timed_out'] ) ) {
            $status = 'timeout';
            $reason = 'XHR/Fetch request timed out (>10s)';
        } elseif ( ! empty( $body['error'] ) ) {
            $status = 'failed';
            $reason = sanitize_text_field( $body['error'] );
        } elseif ( $status_code >= 400 ) {
            $status = 'failed';
            $reason = 'HTTP ' . $status_code;
        } elseif ( $status_code === 0 && ! in_array( $source, [ 'postmessage' ], true ) ) {
            $status = 'failed';
            $reason = 'No response (network error or timeout)';
        } else {
            $status = 'captured';
            $reason = '';
        }

        $this->db->insert(
            $this->log_table,
            [
                'timestamp'              => $now,
                'user_id'                => (int) ( $body['user_id'] ?? $user->ID ),
                'user_email'             => $user->user_email,
                'user_display_name'      => $user->display_name,
                'course_id'              => 0,
                'course_title'           => '',
                'lesson_id'              => 0,
                'lesson_title'           => '',
                'verb'                   => $this->extract_verb_name( sanitize_text_field( $body['verb_id'] ?? '' ) ),
                'verb_display'           => '',
                'activity_id'            => sanitize_text_field( $body['activity_id'] ?? '' ),
                'activity_name'          => '',
                'result_success'         => '',
                'result_completion'      => '',
                'result_score_raw'       => '',
                'result_score_scaled'    => '',
                'raw_statement'          => wp_json_encode( $body ),
                'source'                 => sanitize_text_field( 'js_beacon:' . ( $body['source'] ?? 'unknown' ) ),
                'endpoint_response_code' => $status_code,
                'endpoint_response_body' => sanitize_text_field( $body['response_text'] ?? '' ),
                'delivery_status'        => $status,
                'failure_reason'         => sanitize_text_field( $reason ),
                'user_agent'             => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
                'ip_address'             => $this->get_client_ip(),
                'referer_url'            => sanitize_url( $body['page_url'] ?? '' ),
                'session_id'             => '',
                'created_at'             => $now,
            ],
            [ '%s','%d','%s','%s','%d','%s','%d','%s',
              '%s','%s','%s','%s','%s','%s','%s','%s',
              '%s','%s','%d','%s','%s','%s','%s','%s',
              '%s','%s','%s' ]
        );

        return new WP_REST_Response( [ 'ok' => true ], 200 );
    }

    /** GET /status */
    public function rest_get_status( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response( $this->get_system_health_data(), 200 );
    }

    /** GET /user/{id}/diagnostics */
    public function rest_user_diagnostics( WP_REST_Request $request ): WP_REST_Response {
        $user_id = (int) $request->get_param( 'id' );
        return new WP_REST_Response( $this->get_user_diagnostic_data( $user_id ), 200 );
    }

    /** POST /fix/complete */
    public function rest_force_complete( WP_REST_Request $request ): WP_REST_Response {
        $user_id   = (int) $request->get_param( 'user_id' );
        $lesson_id = (int) $request->get_param( 'lesson_id' );
        $result    = $this->force_learndash_complete( $user_id, $lesson_id );
        return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    /** POST /fix/reset */
    public function rest_reset_xapi( WP_REST_Request $request ): WP_REST_Response {
        $user_id   = (int) $request->get_param( 'user_id' );
        $lesson_id = (int) $request->get_param( 'lesson_id' );
        $result    = $this->reset_user_xapi_data( $user_id, $lesson_id );
        return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    /** POST /diagnostics/run */
    public function rest_run_diagnostics( WP_REST_Request $request ): WP_REST_Response {
        $result = $this->run_diagnostic_comparison();
        return new WP_REST_Response( [ 'ok' => true, 'alerts_generated' => $result ], 200 );
    }

    /** GET /logs — paginated read access to xapi_monitor_log */
    public function rest_get_logs( WP_REST_Request $request ): WP_REST_Response {
        $per_page  = min( (int) $request->get_param( 'per_page' ), 200 );
        $page      = max( 1, (int) $request->get_param( 'page' ) );
        $user_id   = (int) $request->get_param( 'user_id' );
        $lesson_id = (int) $request->get_param( 'lesson_id' );
        $status    = sanitize_text_field( $request->get_param( 'status' ) );
        $days      = max( 1, (int) $request->get_param( 'days' ) );
        $offset    = ( $page - 1 ) * $per_page;

        $where  = ' WHERE timestamp > DATE_SUB(NOW(), INTERVAL %d DAY)';
        $params = [ $days ];

        if ( $user_id ) {
            $where    .= ' AND user_id = %d';
            $params[]  = $user_id;
        }
        if ( $lesson_id ) {
            $where    .= ' AND lesson_id = %d';
            $params[]  = $lesson_id;
        }
        if ( $status && in_array( $status, [ 'delivered', 'failed', 'timeout', 'captured', 'processed', 'blocked' ], true ) ) {
            $where    .= ' AND delivery_status = %s';
            $params[]  = $status;
        }

        $count_sql = "SELECT COUNT(*) FROM {$this->log_table}{$where}";
        $total     = (int) $this->db->get_var( $this->db->prepare( $count_sql, $params ) );
        $pages     = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        $data_params   = array_merge( $params, [ $per_page, $offset ] );
        $data_sql      = "SELECT id, user_id, user_email, user_display_name, lesson_id, lesson_title, course_id, course_title, verb, verb_display, activity_id, activity_name, result_success, result_completion, result_score_raw, result_score_scaled, source, delivery_status, failure_reason, endpoint_response_code, session_id, timestamp, created_at FROM {$this->log_table}{$where} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $logs          = $this->db->get_results( $this->db->prepare( $data_sql, $data_params ) );

        return new WP_REST_Response( [
            'total' => $total,
            'pages' => $pages,
            'logs'  => $logs ?: [],
        ], 200 );
    }

    /** GET /logs/{id} — single log row by ID with full raw_statement JSON */
    public function rest_get_log_single( WP_REST_Request $request ): WP_REST_Response {
        $id  = (int) $request->get_param( 'id' );
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->log_table} WHERE id = %d",
                $id
            )
        );
        if ( ! $row ) {
            return new WP_REST_Response( [ 'error' => 'Log entry not found' ], 404 );
        }
        // Decode raw_statement for clean JSON output
        if ( $row->raw_statement ) {
            $decoded = json_decode( $row->raw_statement, true );
            if ( $decoded ) {
                $row->raw_statement = $decoded;
            }
        }
        return new WP_REST_Response( $row, 200 );
    }

    /** GET /alerts — read access to xapi_monitor_alerts */
    public function rest_get_alerts( WP_REST_Request $request ): WP_REST_Response {
        $status   = sanitize_text_field( $request->get_param( 'status' ) );
        $per_page = min( (int) $request->get_param( 'per_page' ), 200 );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where  = ' WHERE 1=1';
        $params = [];
        if ( 'open' === $status ) {
            $where .= ' AND resolved = 0';
        } elseif ( 'resolved' === $status ) {
            $where .= ' AND resolved = 1';
        }

        $count_sql = "SELECT COUNT(*) FROM {$this->alerts_table}{$where}";
        $total     = ! empty( $params )
            ? (int) $this->db->get_var( $this->db->prepare( $count_sql, $params ) )
            : (int) $this->db->get_var( $count_sql );
        $pages     = $total > 0 ? (int) ceil( $total / $per_page ) : 1;

        $data_sql = "SELECT id, alert_type, severity, user_id, course_id, lesson_id, message, diagnostic_data, resolved, resolved_by, resolved_at, resolution_notes, timestamp, evidence FROM {$this->alerts_table}{$where} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $all_params = array_merge( $params, [ $per_page, $offset ] );
        $alerts   = $this->db->get_results( $this->db->prepare( $data_sql, $all_params ) );

        // Decode evidence JSON for cleaner output
        if ( $alerts ) {
            foreach ( $alerts as &$alert ) {
                if ( ! empty( $alert->evidence ) ) {
                    $decoded = json_decode( $alert->evidence, true );
                    if ( $decoded ) {
                        $alert->evidence = $decoded;
                    }
                }
            }
            unset( $alert );
        }

        return new WP_REST_Response( [
            'total'  => $total,
            'pages'  => $pages,
            'alerts' => $alerts ?: [],
        ], 200 );
    }

    // ============================================================
    // SECTION 5: DIAGNOSTIC COMPARISON ENGINE (CRON)
    // ============================================================

    /**
     * Main diagnostic cron job. Runs every 15 minutes.
     * Returns number of alerts generated.
     */
    public function run_diagnostic_comparison(): int {
        $alerts_generated = 0;
        $settings         = get_option( 'xapi_monitor_settings', self::default_settings() );
        $gap_timeout      = (int) ( $settings['gap_timeout_minutes'] ?? 30 );

        // Get all enrolled users who have had xAPI activity recently (last 2 hours)
        $active_users = $this->db->get_results(
            "SELECT DISTINCT user_id, course_id, lesson_id
             FROM {$this->log_table}
             WHERE timestamp > DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND user_id > 0
               AND delivery_status != 'blocked'"
        );

        if ( empty( $active_users ) ) {
            return 0;
        }

        foreach ( $active_users as $row ) {
            $uid = (int) $row->user_id;
            $cid = (int) $row->course_id;
            $lid = (int) $row->lesson_id;

            if ( ! $uid || ! $lid ) {
                continue;
            }

            // Get this user's statement trail for this lesson
            $statements = $this->db->get_results(
                $this->db->prepare(
                    "SELECT verb, delivery_status, timestamp
                     FROM {$this->log_table}
                     WHERE user_id = %d AND lesson_id = %d
                     ORDER BY timestamp DESC LIMIT 50",
                    $uid,
                    $lid
                )
            );

            if ( empty( $statements ) ) {
                continue;
            }

            $verbs        = array_map( fn( $s ) => strtolower( $s->verb ), $statements );
            $has_completed = in_array( 'completed', $verbs, true );
            $has_passed    = in_array( 'passed', $verbs, true );
            $has_active    = array_intersect( $verbs, [ 'experienced', 'attempted', 'progressed', 'answered' ] );

            // Check LearnDash completion status
            $ld_complete = false;
            if ( function_exists( 'learndash_is_lesson_complete' ) && $lid ) {
                $ld_complete = learndash_is_lesson_complete( get_userdata( $uid ), $lid );
            }

            // Scenario A: Tin Canny has completed/passed but LearnDash doesn't show complete
            if ( ( $has_completed || $has_passed ) && ! $ld_complete ) {
                $existing = $this->get_open_alert( $uid, $lid, 'missing_completion' );
                if ( ! $existing ) {
                    // Build evidence payload
                    $evidence = $this->build_completion_evidence( $uid, $lid, $cid );
                    $alerts_generated += $this->create_alert( [
                        'alert_type'     => 'missing_completion',
                        'severity'       => 'critical',
                        'user_id'        => $uid,
                        'course_id'      => $cid,
                        'lesson_id'      => $lid,
                        'message'        => sprintf(
                            'User %d has %s statement for lesson %d but LearnDash does not show completion.',
                            $uid,
                            $has_completed ? 'completed' : 'passed',
                            $lid
                        ),
                        'diagnostic_data' => wp_json_encode( [
                            'verbs_found'     => $verbs,
                            'ld_complete'     => $ld_complete,
                            'statements_count'=> count( $statements ),
                        ] ),
                        'evidence' => wp_json_encode( $evidence ),
                    ] ) ? 1 : 0;
                }
            }

            // Scenario B: User has activity but no completion in gap_timeout window
            if ( $has_active && ! $has_completed && ! $has_passed ) {
                $last_active = $statements[0]->timestamp ?? null;
                if ( $last_active ) {
                    $last_ts   = strtotime( $last_active );
                    $gap_limit = time() - ( $gap_timeout * 60 );
                    if ( $last_ts < $gap_limit ) {
                        $existing = $this->get_open_alert( $uid, $lid, 'statement_gap' );
                        if ( ! $existing ) {
                            $alerts_generated += $this->create_alert( [
                                'alert_type'     => 'statement_gap',
                                'severity'       => 'warning',
                                'user_id'        => $uid,
                                'course_id'      => $cid,
                                'lesson_id'      => $lid,
                                'message'        => sprintf(
                                    'User %d has been active on lesson %d for over %d minutes but no completion verb received.',
                                    $uid,
                                    $lid,
                                    $gap_timeout
                                ),
                                'diagnostic_data' => wp_json_encode( [
                                    'last_activity' => $last_active,
                                    'verbs_found'   => $verbs,
                                ] ),
                            ] ) ? 1 : 0;
                        }
                    }
                }
            }
        }

        // Check failure rate
        $alerts_generated += $this->check_failure_rate();

        // Send email notifications for critical alerts
        $this->maybe_send_alert_emails();

        update_option( 'xapi_monitor_last_diagnostic', current_time( 'mysql' ) );
        return $alerts_generated;
    }

    /**
     * Check for high failure rates in the last hour.
     */
    private function check_failure_rate(): int {
        $settings  = get_option( 'xapi_monitor_settings', self::default_settings() );
        $threshold = (float) ( $settings['failure_rate_threshold'] ?? 10 );

        $counts = $this->db->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN delivery_status IN ('failed','timeout') THEN 1 ELSE 0 END) as failed
             FROM {$this->log_table}
             WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
               AND source NOT LIKE 'js_error%'
               AND source NOT LIKE '%connectivity%'"
        );

        if ( ! $counts || ! $counts->total ) {
            return 0;
        }

        $rate = ( (float) $counts->failed / (float) $counts->total ) * 100.0;
        if ( $rate >= $threshold ) {
            $existing = $this->db->get_var(
                $this->db->prepare(
                    "SELECT id FROM {$this->alerts_table}
                     WHERE alert_type = 'high_failure_rate'
                       AND resolved = 0
                       AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                     LIMIT 1"
                )
            );
            if ( ! $existing ) {
                return $this->create_alert( [
                    'alert_type'      => 'high_failure_rate',
                    'severity'        => 'critical',
                    'user_id'         => 0,
                    'course_id'       => 0,
                    'lesson_id'       => 0,
                    'message'         => sprintf(
                        'High xAPI failure rate: %.1f%% failures in the last hour (%d of %d statements failed). Threshold: %.1f%%.',
                        $rate,
                        $counts->failed,
                        $counts->total,
                        $threshold
                    ),
                    'diagnostic_data' => wp_json_encode( [
                        'total'     => $counts->total,
                        'failed'    => $counts->failed,
                        'rate_pct'  => $rate,
                        'threshold' => $threshold,
                    ] ),
                ] ) ? 1 : 0;
            }
        }
        return 0;
    }

    // ============================================================
    // SECTION 5b: QUIZ STEP DETECTION & EVIDENCE BUILDING (v1.2.0)
    // ============================================================

    /**
     * Build a structured evidence payload for a completion mismatch alert.
     * Collects Tin Canny rows, LearnDash activity, and monitor log IDs.
     *
     * Memory-conscious: limits row counts and uses SELECT with only needed columns.
     */
    private function build_completion_evidence( int $user_id, int $lesson_id, int $course_id ): array {
        $evidence = [
            'tin_canny_rows'     => [],
            'learndash_activity' => null,
            'log_ids'            => [],
        ];

        // Fetch matching Tin Canny rows (uotincan_reporting table)
        $tc_table = $this->db->prefix . 'uotincan_reporting';
        $tc_exists = (bool) $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $tc_table ) );
        if ( $tc_exists ) {
            $tc_rows = $this->db->get_results(
                $this->db->prepare(
                    "SELECT user_id, lesson_id, course_id, verb, completion, passed, created_at
                     FROM {$tc_table}
                     WHERE user_id = %d AND lesson_id = %d
                     ORDER BY created_at DESC LIMIT 10",
                    $user_id,
                    $lesson_id
                )
            );
            if ( $tc_rows ) {
                $evidence['tin_canny_rows'] = array_map( fn( $r ) => (array) $r, $tc_rows );
            }
        }

        // Fetch LearnDash user activity
        if ( function_exists( 'learndash_get_user_activity' ) && $lesson_id ) {
            $ld_activity = learndash_get_user_activity( [
                'user_id'       => $user_id,
                'post_id'       => $lesson_id,
                'course_id'     => $course_id,
                'activity_type' => 'lesson',
            ] );
            if ( $ld_activity ) {
                $evidence['learndash_activity'] = (array) $ld_activity;
            }
        }

        // Get monitor log IDs supporting this finding
        $log_rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT id FROM {$this->log_table}
                 WHERE user_id = %d AND lesson_id = %d
                   AND verb IN ('completed','passed')
                 ORDER BY timestamp DESC LIMIT 20",
                $user_id,
                $lesson_id
            )
        );
        if ( $log_rows ) {
            $evidence['log_ids'] = array_map( fn( $r ) => (int) $r->id, $log_rows );
        }

        return $evidence;
    }

    /**
     * Detect quiz steps belonging to a course.
     * Returns array of [ 'quiz_id' => int, 'title' => string, 'lesson_id' => int ].
     */
    private function detect_quiz_steps( int $course_id ): array {
        if ( ! $course_id ) {
            return [];
        }
        $quizzes = get_posts( [
            'post_type'      => 'sfwd-quiz',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => 'course_id',
                    'value'   => $course_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ] );

        $result = [];
        foreach ( $quizzes as $quiz_id ) {
            $lesson_id = (int) get_post_meta( $quiz_id, 'lesson_id', true );
            $result[]  = [
                'quiz_id'   => (int) $quiz_id,
                'title'     => get_the_title( $quiz_id ) ?: 'Quiz #' . $quiz_id,
                'lesson_id' => $lesson_id,
            ];
        }
        return $result;
    }

    /**
     * Check whether any incomplete steps in a course are quizzes that xAPI cannot trigger.
     *
     * @return array { has_warning: bool, message: string, quiz_steps: array }
     */
    private function check_quiz_step_mismatch( int $user_id, int $course_id ): array {
        $no_warning = [ 'has_warning' => false ];

        if ( ! $user_id || ! $course_id ) {
            return $no_warning;
        }

        if ( ! function_exists( 'learndash_user_get_course_progress' ) ) {
            return $no_warning;
        }

        $progress   = learndash_user_get_course_progress( $user_id, $course_id );
        $quiz_steps = $this->detect_quiz_steps( $course_id );

        if ( empty( $quiz_steps ) ) {
            return $no_warning;
        }

        // Determine which quiz steps are not yet complete for this user
        $incomplete_quiz_steps = [];
        foreach ( $quiz_steps as $quiz ) {
            $is_complete = false;
            // Check LearnDash quiz completion via user meta
            if ( function_exists( 'learndash_is_quiz_complete' ) ) {
                $is_complete = (bool) learndash_is_quiz_complete( $user_id, $quiz['quiz_id'], $course_id );
            } else {
                // Fallback: check usermeta
                $quiz_data = get_user_meta( $user_id, '_sfwd-quizzes', true );
                if ( is_array( $quiz_data ) ) {
                    foreach ( $quiz_data as $entry ) {
                        if ( isset( $entry['quiz'] ) && (int) $entry['quiz'] === $quiz['quiz_id'] ) {
                            $is_complete = true;
                            break;
                        }
                    }
                }
            }
            if ( ! $is_complete ) {
                $incomplete_quiz_steps[] = $quiz;
            }
        }

        if ( empty( $incomplete_quiz_steps ) ) {
            return $no_warning;
        }

        $count = count( $incomplete_quiz_steps );
        return [
            'has_warning' => true,
            'message'     => sprintf(
                'This course contains %d quiz step(s) that cannot be completed via xAPI statements. Quizzes must be completed directly in LearnDash.',
                $count
            ),
            'quiz_steps'  => $incomplete_quiz_steps,
        ];
    }

    /**
     * Detect the installed Tin Canny version through multiple fallback strategies.
     */
    private function get_tincanny_version(): string {
        // 1. Try get_plugins() — most reliable but requires include
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins = get_plugins();
        $tc_slugs    = [
            'tin-canny-learndash-reporting/tin-canny-learndash-reporting.php',
            'uncanny-learndash-reporting/uncanny-learndash-reporting.php',
            'developer-tin-canny-learndash-reporting/developer-tin-canny-learndash-reporting.php',
        ];
        foreach ( $tc_slugs as $slug ) {
            if ( isset( $all_plugins[ $slug ]['Version'] ) ) {
                return $all_plugins[ $slug ]['Version'];
            }
        }

        // 2. Try class constant or static property
        foreach ( [ 'UO_Tin_Can', 'UNCANNY_REPORTING', '\\uncanny_learndash_reporting\\Config' ] as $cls ) {
            if ( class_exists( $cls ) ) {
                if ( defined( $cls . '::VERSION' ) ) {
                    return constant( $cls . '::VERSION' );
                }
                if ( defined( $cls . '::PLUGIN_VERSION' ) ) {
                    return constant( $cls . '::PLUGIN_VERSION' );
                }
            }
        }

        // 3. Try option values
        foreach ( [ 'tcrld_version', 'uncanny_reporting_version', 'uo_reporting_version' ] as $opt ) {
            $val = get_option( $opt );
            if ( $val && is_string( $val ) ) {
                return $val;
            }
        }

        // 4. Read the plugin file header directly
        $plugin_file = WP_PLUGIN_DIR . '/tin-canny-learndash-reporting/tin-canny-learndash-reporting.php';
        if ( file_exists( $plugin_file ) ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin_data = get_plugin_data( $plugin_file, false, false );
            return $plugin_data['Version'] ?? 'Unknown';
        }

        return 'Not detected';
    }

    /**
     * Endpoint health check cron.
     */
    public function run_endpoint_health_check(): void {
        $result = $this->test_tincanny_endpoint();
        update_option( 'xapi_monitor_last_health_check', [
            'timestamp' => current_time( 'mysql' ),
            'result'    => $result,
        ] );

        if ( ! $result['success'] ) {
            $existing = $this->db->get_var(
                "SELECT id FROM {$this->alerts_table}
                 WHERE alert_type = 'endpoint_failure'
                   AND resolved = 0
                   AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 LIMIT 1"
            );
            if ( ! $existing ) {
                $this->create_alert( [
                    'alert_type'      => 'endpoint_failure',
                    'severity'        => 'critical',
                    'user_id'         => 0,
                    'course_id'       => 0,
                    'lesson_id'       => 0,
                    'message'         => 'Tin Canny xAPI endpoint health check failed: ' . ( $result['error'] ?? 'unknown error' ),
                    'diagnostic_data' => wp_json_encode( $result ),
                ] );
                $this->maybe_send_alert_emails();
            }
        }

        // LearnDash REST API health check (critical for LearnDash 5.0+)
        if ( defined( 'LEARNDASH_VERSION' ) || function_exists( 'learndash_is_lesson_complete' ) ) {
            $ld_result = $this->check_learndash_rest_api();
            update_option( 'xapi_monitor_last_learndash_api_check', [
                'timestamp' => current_time( 'mysql' ),
                'result'    => $ld_result,
            ] );

            if ( 'error' === $ld_result['status'] ) {
                $existing_ld = $this->db->get_var(
                    "SELECT id FROM {$this->alerts_table}
                     WHERE alert_type = 'learndash_api_failure'
                       AND resolved = 0
                       AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                     LIMIT 1"
                );
                if ( ! $existing_ld ) {
                    $this->create_alert( [
                        'alert_type'      => 'learndash_api_failure',
                        'severity'        => 'critical',
                        'user_id'         => 0,
                        'course_id'       => 0,
                        'lesson_id'       => 0,
                        'message'         => $ld_result['message'],
                        'diagnostic_data' => wp_json_encode( $ld_result ),
                    ] );
                    $this->maybe_send_alert_emails();
                }
            }
        }
    }

    /**
     * Sends a minimal test xAPI statement to the Tin Canny endpoint.
     */
    public function test_tincanny_endpoint(): array {
        // Tin Canny uses a virtual rewrite endpoint (ucTinCan query var)
        // registered via WordPress rewrite rules, NOT a standard REST route.
        // The query-string fallback always works regardless of rewrite config.
        // We also try the pretty-permalink version and the REST route in case
        // a newer version of Tin Canny added one.
        $endpoints_to_try = [
            // 1. Query-string virtual endpoint (most reliable, always works)
            home_url( '/?ucTinCan=1' ),
            // 2. Pretty-permalink rewrite pattern
            home_url( '/ucTinCan/' ),
            // 3. Possible REST routes (newer TC versions or custom setups)
            rest_url( 'uo/v2/tin-canny' ),
            home_url( '/wp-json/uo/v2/tin-canny' ),
        ];

        $test_statement = wp_json_encode( [
            [
                'id'        => wp_generate_uuid4(),
                'actor'     => [
                    'objectType' => 'Agent',
                    'mbox'       => 'mailto:xapi-monitor-test@system.local',
                    'name'       => 'xAPI Monitor Health Check',
                ],
                'verb'      => [
                    'id'      => 'http://adlnet.gov/expapi/verbs/experienced',
                    'display' => [ 'en-US' => 'experienced' ],
                ],
                'object'    => [
                    'id'         => 'http://xapi-monitor.local/health-check',
                    'objectType' => 'Activity',
                    'definition' => [
                        'name'        => [ 'en-US' => 'Health Check Activity' ],
                        'description' => [ 'en-US' => 'Automated health check statement' ],
                    ],
                ],
                'timestamp' => gmdate( 'c' ),
            ],
        ] );

        $last_error = '';
        $attempts   = [];

        foreach ( $endpoints_to_try as $url ) {
            $response = wp_remote_post( $url, [
                'timeout'     => 15,
                'redirection' => 5,
                'sslverify'   => false, // Same server loopback may have cert issues
                'headers'     => [
                    'Content-Type'             => 'application/json',
                    'X-Experience-API-Version' => '1.0.3',
                ],
                'body'        => $test_statement,
            ] );

            if ( is_wp_error( $response ) ) {
                $attempts[] = [
                    'endpoint' => $url,
                    'code'     => 0,
                    'error'    => $response->get_error_message(),
                ];
                $last_error = $response->get_error_message();
                continue; // Try next endpoint
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $attempts[] = [
                'endpoint' => $url,
                'code'     => $code,
                'body'     => substr( $body, 0, 500 ),
            ];

            // Success: 2xx response
            if ( $code >= 200 && $code < 300 ) {
                return [
                    'success'  => true,
                    'endpoint' => $url,
                    'code'     => $code,
                    'attempts' => $attempts,
                ];
            }

            // A 404 means this specific URL pattern isn't registered.
            // Don't return failure yet — try the next endpoint.
            if ( $code === 404 ) {
                $last_error = 'HTTP 404: ' . $body;
                continue;
            }

            // A non-404 failure (403, 500, etc.) is more meaningful —
            // the endpoint exists but something else is wrong.
            // Still try remaining endpoints, but record this.
            $last_error = "HTTP {$code}: " . $body;
        }

        // All HTTP endpoint attempts failed.
        // Fallback: verify Tin Canny is active and its DB table exists,
        // which confirms the endpoint *should* work for real browser requests
        // even if server-to-server loopback is blocked (common on Hostinger/LiteSpeed).
        $tc_active = is_plugin_active( 'tin-canny-learndash-reporting/tin-canny-learndash-reporting.php' )
                  || is_plugin_active( 'developer-tin-canny-learndash-reporting/developer-tin-canny-learndash-reporting.php' )
                  || defined( 'SUSPENDED_UCTINCAN_PATH' );

        $snc_table = $this->db->prefix . 'snc_file';
        $tc_table_exists = (bool) $this->db->get_var(
            $this->db->prepare( 'SHOW TABLES LIKE %s', $snc_table )
        );

        if ( $tc_active && $tc_table_exists ) {
            return [
                'success'   => true,
                'endpoint'  => 'loopback_failed_but_tc_active',
                'code'      => 0,
                'note'      => 'All HTTP loopback tests failed (common on Hostinger/LiteSpeed shared hosting where server-to-self requests are blocked). However, Tin Canny is active and its database table exists. The endpoint works for real browser requests — the loopback test is a false negative.',
                'attempts'  => $attempts,
            ];
        }

        return [
            'success'  => false,
            'error'    => 'All endpoint attempts failed. Last: ' . $last_error,
            'attempts' => $attempts,
        ];
    }

    /**
     * Check LearnDash REST API v2 endpoint reachability.
     */
    private function check_learndash_rest_api(): array {
        $endpoint = rest_url( 'ldlms/v2/sfwd-lessons' );
        $response = wp_remote_get( $endpoint, [
            'timeout'    => 10,
            'user-agent' => 'xAPI-Monitor/' . XAPI_MONITOR_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'status'  => 'error',
                'message' => 'LearnDash REST API unreachable: ' . $response->get_error_message(),
                'code'    => 0,
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        // 200 = OK, 401 = auth required but API is up (acceptable for unauthenticated check)
        $ok = in_array( $code, [ 200, 401 ], true );

        return [
            'status'  => $ok ? 'ok' : 'error',
            'message' => $ok
                ? sprintf( 'LearnDash REST API reachable (HTTP %d)', $code )
                : sprintf( 'LearnDash REST API returned unexpected HTTP %d', $code ),
            'code'    => $code,
        ];
    }

    // ============================================================
    // SECTION 6: ALERT HELPERS
    // ============================================================

    private function create_alert( array $data ): bool {
        $result = $this->db->insert(
            $this->alerts_table,
            [
                'timestamp'       => current_time( 'mysql' ),
                'alert_type'      => sanitize_text_field( $data['alert_type'] ?? '' ),
                'severity'        => sanitize_text_field( $data['severity'] ?? 'info' ),
                'user_id'         => (int) ( $data['user_id'] ?? 0 ),
                'course_id'       => (int) ( $data['course_id'] ?? 0 ),
                'lesson_id'       => (int) ( $data['lesson_id'] ?? 0 ),
                'message'         => sanitize_text_field( $data['message'] ?? '' ),
                'diagnostic_data' => $data['diagnostic_data'] ?? '',
                'resolved'        => 0,
                'resolved_by'     => 0,
                'resolution_notes'=> '',
                'evidence'        => $data['evidence'] ?? '',
            ],
            [ '%s','%s','%s','%d','%d','%d','%s','%s','%d','%d','%s','%s' ]
        );
        return (bool) $result;
    }

    private function get_open_alert( int $user_id, int $lesson_id, string $type ): ?object {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->alerts_table}
                 WHERE alert_type = %s AND user_id = %d AND lesson_id = %d AND resolved = 0
                 ORDER BY timestamp DESC LIMIT 1",
                $type,
                $user_id,
                $lesson_id
            )
        );
    }

    // ============================================================
    // SECTION 7: EMAIL ALERTS
    // ============================================================

    private function maybe_send_alert_emails(): void {
        $settings = get_option( 'xapi_monitor_settings', self::default_settings() );
        if ( empty( $settings['email_notifications'] ) ) {
            return;
        }

        $recipients = array_filter( array_map(
            'trim',
            explode( ',', $settings['alert_emails'] ?? '' )
        ) );
        if ( empty( $recipients ) ) {
            return;
        }

        // Find unresolved critical alerts from the last 30 minutes that haven't been emailed
        $unsent = $this->db->get_results(
            "SELECT * FROM {$this->alerts_table}
             WHERE severity = 'critical'
               AND resolved = 0
               AND timestamp > DATE_SUB(NOW(), INTERVAL 30 MINUTE)
               AND (diagnostic_data NOT LIKE '%\"emailed\":true%')
             ORDER BY timestamp DESC LIMIT 20"
        );

        if ( empty( $unsent ) ) {
            return;
        }

        $admin_url = admin_url( 'admin.php?page=xapi-monitor&tab=alerts' );
        $site_name = get_bloginfo( 'name' );

        foreach ( $unsent as $alert ) {
            $user_info = '';
            if ( $alert->user_id ) {
                $user = get_userdata( $alert->user_id );
                if ( $user ) {
                    $diag_url  = admin_url( 'admin.php?page=xapi-monitor&tab=user-diagnostic&user_id=' . $alert->user_id );
                    $user_info = "User: {$user->display_name} ({$user->user_email})\n"
                               . "Diagnostic link: {$diag_url}\n";
                }
            }

            $course_info = '';
            if ( $alert->course_id ) {
                $course_info = 'Course: ' . get_the_title( $alert->course_id ) . " (ID: {$alert->course_id})\n";
            }
            if ( $alert->lesson_id ) {
                $course_info .= 'Lesson: ' . get_the_title( $alert->lesson_id ) . " (ID: {$alert->lesson_id})\n";
            }

            $recommended = $this->get_recommended_action( $alert->alert_type );

            $subject = "[{$site_name}] xAPI Monitor CRITICAL Alert: " . ucwords( str_replace( '_', ' ', $alert->alert_type ) );
            $body    = "xAPI Statement Monitor has detected a critical issue on {$site_name}.\n\n"
                     . "Alert Type: " . strtoupper( $alert->alert_type ) . "\n"
                     . "Time: {$alert->timestamp}\n"
                     . "Message: {$alert->message}\n"
                     . $user_info
                     . $course_info
                     . "\nRecommended Action:\n{$recommended}\n\n"
                     . "View all alerts: {$admin_url}\n";

            foreach ( $recipients as $email ) {
                wp_mail( $email, $subject, $body );
            }

            // Mark as emailed
            $diag = json_decode( $alert->diagnostic_data, true ) ?: [];
            $diag['emailed'] = true;
            $this->db->update(
                $this->alerts_table,
                [ 'diagnostic_data' => wp_json_encode( $diag ) ],
                [ 'id' => $alert->id ],
                [ '%s' ],
                [ '%d' ]
            );
        }
    }

    private function get_recommended_action( string $type ): string {
        $actions = [
            'missing_completion'  => "1. Check Tin Canny reporting for the user/lesson.\n2. Verify the 'Mark Complete' trigger is set to 'completed' or 'passed'.\n3. Use the 'Force Complete' button in the User Diagnostic tab to manually complete the lesson.",
            'endpoint_failure'    => "1. Check Tin Canny plugin status and endpoint URL.\n2. Verify WordPress permalinks are flushed (Settings > Permalinks > Save).\n3. Check for server 500 errors in the error log.\n4. Test the endpoint manually using the System Health tab.",
            'statement_gap'       => "1. The user may have abandoned the course mid-way.\n2. Check if the Rise content sent a completion statement via the JS Beacon tab.\n3. If the content did complete on the client side but the server didn't receive it, consider resending the last captured statement.",
            'high_failure_rate'   => "1. Check server error logs for 500/503 errors.\n2. Verify the database is accepting writes.\n3. Check for any recent WordPress updates or plugin conflicts.\n4. Review the Live Statement Feed for patterns in the failures.",
            'user_stuck'          => "1. Check the user's xAPI statement trail in the User Diagnostic tab.\n2. Verify they are enrolled in the course.\n3. Consider resetting their xAPI data so they can restart.",
            'learndash_api_failure' => "1. Verify LearnDash is active and updated to version 5.0+.\n2. Check that the REST API is not blocked by a security plugin or .htaccess rule.\n3. Visit /wp-json/ldlms/v2/sfwd-lessons in your browser to test manually.\n4. Check for conflicting plugins that may disable the REST API.",
        ];
        return $actions[ $type ] ?? 'Review the alert details in the xAPI Monitor admin dashboard.';
    }

    // ============================================================
    // SECTION 8: MANUAL FIX ACTIONS
    // ============================================================

    private function force_learndash_complete( int $user_id, int $lesson_id ): array {
        if ( ! $user_id || ! $lesson_id ) {
            return [ 'success' => false, 'error' => 'Invalid user_id or lesson_id' ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [ 'success' => false, 'error' => 'User not found' ];
        }

        $post_type = get_post_type( $lesson_id );
        if ( ! in_array( $post_type, [ 'sfwd-lessons', 'sfwd-topic', 'sfwd-courses' ], true ) ) {
            return [ 'success' => false, 'error' => 'Post is not a LearnDash lesson, topic, or course' ];
        }

        if ( function_exists( 'learndash_process_mark_complete' ) ) {
            learndash_process_mark_complete( $user, $lesson_id );
            return [ 'success' => true, 'message' => "Marked lesson {$lesson_id} complete for user {$user_id}" ];
        }

        // Fallback: use LD activity table directly
        if ( function_exists( 'learndash_update_user_activity' ) ) {
            $course_id = function_exists( 'learndash_get_course_id' )
                ? (int) learndash_get_course_id( $lesson_id )
                : 0;
            learndash_update_user_activity( [
                'user_id'          => $user_id,
                'post_id'          => $lesson_id,
                'course_id'        => $course_id,
                'activity_type'    => 'lesson',
                'activity_status'  => true,
                'activity_started' => time(),
                'activity_updated' => time(),
            ] );
            return [ 'success' => true, 'message' => "Updated activity for lesson {$lesson_id}, user {$user_id}" ];
        }

        return [ 'success' => false, 'error' => 'LearnDash functions not available' ];
    }

    private function reset_user_xapi_data( int $user_id, int $lesson_id ): array {
        if ( ! $user_id ) {
            return [ 'success' => false, 'error' => 'Invalid user_id' ];
        }

        $deleted = 0;

        // Clear from our monitor log
        $deleted += (int) $this->db->delete(
            $this->log_table,
            [ 'user_id' => $user_id, 'lesson_id' => $lesson_id ],
            [ '%d', '%d' ]
        );

        // Attempt to clear Tin Canny's own tables
        $tc_tables = [
            $this->db->prefix . 'snc_xapi_user',
            $this->db->prefix . 'uo_tin_canny_user_xapi',
            $this->db->prefix . 'tincanny_user_xapi',
        ];
        foreach ( $tc_tables as $table ) {
            // Check table exists
            if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
                // Try deleting by user_id (Tin Canny schemas vary by version)
                $this->db->delete( $table, [ 'user_id' => $user_id ], [ '%d' ] );
            }
        }

        // Clear resume/bookmark data
        $resume_table = $this->db->prefix . 'snc_suspend_data';
        if ( $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $resume_table ) ) ) {
            $this->db->delete( $resume_table, [ 'user_id' => $user_id ], [ '%d' ] );
        }

        return [
            'success' => true,
            'message' => "Reset xAPI data for user {$user_id}, lesson {$lesson_id}. Monitor log entries deleted: {$deleted}",
        ];
    }

    // ============================================================
    // SECTION 9: AJAX HANDLERS
    // ============================================================

    private function verify_admin_ajax(): bool {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
            return false;
        }
        if ( ! check_ajax_referer( 'xapi_monitor_admin', 'nonce', false ) ) {
            wp_send_json_error( 'Invalid nonce', 403 );
            return false;
        }
        return true;
    }

    public function ajax_force_complete(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $lesson_id = (int) ( $_POST['lesson_id'] ?? 0 );
        wp_send_json( $this->force_learndash_complete( $user_id, $lesson_id ) );
    }

    public function ajax_reset_xapi(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $user_id   = (int) ( $_POST['user_id'] ?? 0 );
        $lesson_id = (int) ( $_POST['lesson_id'] ?? 0 );
        wp_send_json( $this->reset_user_xapi_data( $user_id, $lesson_id ) );
    }

    public function ajax_resend_statement(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $log_id = (int) ( $_POST['log_id'] ?? 0 );
        $entry  = $this->db->get_row( $this->db->prepare(
            "SELECT * FROM {$this->log_table} WHERE id = %d", $log_id
        ) );
        if ( ! $entry ) {
            wp_send_json_error( 'Log entry not found' );
            return;
        }

        // Re-submit the raw statement to the Tin Canny endpoint
        $endpoints = [
            home_url( '/' ) . '?ucTinCan=1',
            rest_url( 'uo/v2/tin-canny' ),
        ];

        $raw = $entry->raw_statement;
        $success = false;
        foreach ( $endpoints as $url ) {
            $resp = wp_remote_post( $url, [
                'timeout' => 15,
                'headers' => [
                    'Content-Type'                  => 'application/json',
                    'X-Experience-API-Version'      => '1.0.3',
                ],
                'body'    => $raw,
            ] );
            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) < 300 ) {
                $success = true;
                break;
            }
        }

        wp_send_json( [
            'success' => $success,
            'message' => $success ? 'Statement resubmitted successfully' : 'Failed to resubmit statement',
        ] );
    }

    public function ajax_run_diagnostic(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $count = $this->run_diagnostic_comparison();
        wp_send_json( [
            'success'          => true,
            'alerts_generated' => $count,
            'timestamp'        => current_time( 'mysql' ),
        ] );
    }

    public function ajax_resolve_alert(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $alert_id = (int) ( $_POST['alert_id'] ?? 0 );
        $notes    = sanitize_textarea_field( $_POST['notes'] ?? '' );
        $user_id  = get_current_user_id();

        $this->db->update(
            $this->alerts_table,
            [
                'resolved'         => 1,
                'resolved_by'      => $user_id,
                'resolved_at'      => current_time( 'mysql' ),
                'resolution_notes' => $notes,
            ],
            [ 'id' => $alert_id ],
            [ '%d','%d','%s','%s' ],
            [ '%d' ]
        );
        wp_send_json( [ 'success' => true ] );
    }

    public function ajax_purge_logs(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $days    = (int) ( $_POST['days'] ?? 30 );
        $archive = ! empty( $_POST['archive'] );

        if ( $archive ) {
            $this->export_logs_to_csv( $days );
        }

        $deleted = $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->log_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        wp_send_json( [
            'success' => true,
            'deleted' => $deleted,
            'message' => "Purged {$deleted} log entries older than {$days} days.",
        ] );
    }

    /**
     * AJAX: Permanently delete all plugin data (tables + options).
     * Sets the wipe flag first so uninstall.php will also clean up
     * if the admin then deletes the plugin.
     */
    public function ajax_delete_all_data(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied.' ] );
            return;
        }
        if ( ! check_ajax_referer( 'xapi_monitor_delete_all_data', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ] );
            return;
        }

        // Drop log and alert tables.
        $tables = [
            $this->db->prefix . 'xapi_monitor_log',
            $this->db->prefix . 'xapi_monitor_alerts',
        ];
        foreach ( $tables as $table ) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
            // phpcs:enable
        }

        // Remove all plugin options.
        $options = [
            'xapi_monitor_version',
            'xapi_monitor_settings',
            'xapi_monitor_last_diagnostic',
            'xapi_monitor_last_health_check',
            'xapi_monitor_wipe_on_uninstall',
        ];
        foreach ( $options as $option ) {
            delete_option( $option );
        }

        // Set the wipe flag so uninstall.php also cleans up if plugin is then deleted.
        update_option( 'xapi_monitor_wipe_on_uninstall', true );

        // Re-create empty tables immediately so the plugin keeps working.
        self::create_tables();
        add_option( 'xapi_monitor_version', XAPI_MONITOR_VERSION );
        add_option( 'xapi_monitor_settings', self::default_settings() );
        delete_option( 'xapi_monitor_wipe_on_uninstall' ); // Reset flag — fresh start.

        wp_send_json_success( [ 'message' => 'All data has been permanently deleted. Tables have been recreated and the plugin is ready to collect new data.' ] );
    }

    public function ajax_test_endpoint(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $result = $this->test_tincanny_endpoint();
        update_option( 'xapi_monitor_last_health_check', [
            'timestamp' => current_time( 'mysql' ),
            'result'    => $result,
        ] );
        wp_send_json( $result );
    }

    public function ajax_export_csv(): void {
        if ( ! $this->verify_admin_ajax() ) {
            return;
        }
        $file = $this->export_logs_to_csv();
        if ( $file ) {
            wp_send_json( [
                'success'  => true,
                'file'     => basename( $file ),
                'download' => admin_url( 'admin-ajax.php?action=xapi_monitor_download_csv&file=' . urlencode( basename( $file ) ) . '&nonce=' . wp_create_nonce( 'xapi_monitor_admin' ) ),
            ] );
        } else {
            wp_send_json_error( 'Failed to create export file.' );
        }
    }

    private function export_logs_to_csv( int $days = 0 ): string|false {
        $where = '';
        if ( $days > 0 ) {
            $where = $this->db->prepare(
                " WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            );
        }
        $rows = $this->db->get_results( "SELECT * FROM {$this->log_table}{$where} ORDER BY created_at DESC", ARRAY_A );
        if ( empty( $rows ) ) {
            return false;
        }
        $upload_dir = wp_upload_dir();
        $file_path  = $upload_dir['basedir'] . '/xapi-monitor-export-' . date( 'Ymd-His' ) . '.csv';
        $fp = fopen( $file_path, 'w' );
        if ( ! $fp ) {
            return false;
        }
        fputcsv( $fp, array_keys( $rows[0] ) );
        foreach ( $rows as $row ) {
            fputcsv( $fp, $row );
        }
        fclose( $fp );
        return $file_path;
    }

    // ============================================================
    // SECTION 10: CLEANUP CRON
    // ============================================================

    public function run_cleanup(): void {
        $settings = get_option( 'xapi_monitor_settings', self::default_settings() );
        $days     = (int) ( $settings['log_retention_days'] ?? 30 );
        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->log_table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        // Also clean resolved alerts older than 90 days
        $this->db->query(
            "DELETE FROM {$this->alerts_table}
             WHERE resolved = 1
               AND resolved_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
    }

    // ============================================================
    // SECTION 11: ADMIN MENU & DASHBOARD
    // ============================================================

    public function register_admin_menu(): void {
        add_menu_page(
            __( 'xAPI Monitor', 'xapi-monitor' ),
            __( 'xAPI Monitor', 'xapi-monitor' ),
            'manage_options',
            'xapi-monitor',
            [ $this, 'render_admin_page' ],
            'dashicons-chart-line',
            56
        );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( 'toplevel_page_xapi-monitor' !== $hook ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
    }

    public function add_plugin_action_links( array $links ): array {
        $links[] = '<a href="' . admin_url( 'admin.php?page=xapi-monitor' ) . '">' . __( 'Dashboard', 'xapi-monitor' ) . '</a>';
        return $links;
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'xapi-monitor' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'live-feed';
        $tabs = [
            'live-feed'       => __( 'Live Statement Feed', 'xapi-monitor' ),
            'alerts'          => __( 'Alerts & Diagnostics', 'xapi-monitor' ),
            'user-diagnostic' => __( 'User Diagnostic', 'xapi-monitor' ),
            'system-health'   => __( 'System Health', 'xapi-monitor' ),
            'settings'        => __( 'Settings', 'xapi-monitor' ),
            'xapi-docs'       => __( 'How xAPI Works', 'xapi-monitor' ),
        ];

        $nonce = wp_create_nonce( 'xapi_monitor_admin' );

        $this->render_admin_styles();
        ?>
        <div class="wrap xapi-monitor-wrap">
            <h1>
                <span class="xapi-monitor-logo">&#x1F4E1;</span>
                <?php esc_html_e( 'xAPI Statement Monitor', 'xapi-monitor' ); ?>
            </h1>

            <nav class="nav-tab-wrapper xapi-nav-tabs">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=xapi-monitor&tab=' . $slug ) ); ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="xapi-tab-content">
                <?php
                switch ( $tab ) {
                    case 'live-feed':
                        $this->render_tab_live_feed( $nonce );
                        break;
                    case 'alerts':
                        $this->render_tab_alerts( $nonce );
                        break;
                    case 'user-diagnostic':
                        $this->render_tab_user_diagnostic( $nonce );
                        break;
                    case 'system-health':
                        $this->render_tab_system_health( $nonce );
                        break;
                    case 'settings':
                        $this->render_tab_settings( $nonce );
                        break;
                    case 'xapi-docs':
                        $this->render_tab_xapi_docs( $nonce );
                        break;
                }
                ?>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(function($) {
            var NONCE = <?php echo wp_json_encode( $nonce ); ?>;

            // Expand raw statement
            $(document).on('click', '.xapi-expand-btn', function() {
                var row = $(this).closest('tr').next('.xapi-raw-row');
                row.toggle();
                $(this).text(row.is(':visible') ? '▲ Collapse' : '▼ Expand');
            });

            // Force complete
            $(document).on('click', '.xapi-force-complete', function() {
                var uid = $(this).data('user');
                var lid = $(this).data('lesson');
                if (!confirm('Force mark lesson ' + lid + ' complete for user ' + uid + '?')) return;
                $.post(ajaxurl, { action:'xapi_monitor_force_complete', nonce:NONCE, user_id:uid, lesson_id:lid },
                    function(r) { alert(r.success ? r.message || 'Done!' : r.error || 'Failed'); location.reload(); });
            });

            // Reset xAPI
            $(document).on('click', '.xapi-reset-xapi', function() {
                var uid = $(this).data('user');
                var lid = $(this).data('lesson');
                if (!confirm('Reset xAPI data for user ' + uid + ', lesson ' + lid + '?\nThis cannot be undone.')) return;
                $.post(ajaxurl, { action:'xapi_monitor_reset_xapi', nonce:NONCE, user_id:uid, lesson_id:lid },
                    function(r) { alert(r.success ? r.message || 'Done!' : r.error || 'Failed'); location.reload(); });
            });

            // Resend statement
            $(document).on('click', '.xapi-resend-stmt', function() {
                var lid = $(this).data('log');
                if (!confirm('Resubmit this statement to Tin Canny?')) return;
                $.post(ajaxurl, { action:'xapi_monitor_resend_statement', nonce:NONCE, log_id:lid },
                    function(r) { alert(r.success ? r.message || 'Done!' : r.message || 'Failed'); });
            });

            // Run diagnostic
            $(document).on('click', '#xapi-run-diagnostic', function() {
                $(this).prop('disabled', true).text('Running...');
                $.post(ajaxurl, { action:'xapi_monitor_run_diagnostic', nonce:NONCE }, function(r) {
                    alert('Diagnostic complete. Alerts generated: ' + r.alerts_generated);
                    location.reload();
                });
            });

            // Test endpoint
            $(document).on('click', '#xapi-test-endpoint', function() {
                $(this).prop('disabled', true).text('Testing...');
                $.post(ajaxurl, { action:'xapi_monitor_test_endpoint', nonce:NONCE }, function(r) {
                    if (r.success) {
                        alert('Endpoint OK! HTTP ' + r.code + ' from ' + r.endpoint);
                    } else {
                        alert('Endpoint FAILED: ' + (r.error || JSON.stringify(r)));
                    }
                    location.reload();
                }).always(function() { $('#xapi-test-endpoint').prop('disabled', false).text('Test Endpoint Now'); });
            });

            // Resolve alert
            $(document).on('click', '.xapi-resolve-alert', function() {
                var aid   = $(this).data('alert');
                var notes = prompt('Resolution notes (optional):');
                if (notes === null) return;
                $.post(ajaxurl, { action:'xapi_monitor_resolve_alert', nonce:NONCE, alert_id:aid, notes:notes },
                    function(r) { if (r.success) location.reload(); });
            });

            // Purge logs
            $(document).on('click', '#xapi-purge-logs', function() {
                var days    = $('#xapi-purge-days').val() || 30;
                var archive = $('#xapi-archive-before-purge').is(':checked');
                if (!confirm('Delete log entries older than ' + days + ' days?')) return;
                $.post(ajaxurl, { action:'xapi_monitor_purge_logs', nonce:NONCE, days:days, archive:archive ? 1 : 0 },
                    function(r) { alert(r.message || 'Done'); location.reload(); });
            });

            // Export CSV
            $(document).on('click', '#xapi-export-csv', function() {
                $.post(ajaxurl, { action:'xapi_monitor_export_csv', nonce:NONCE }, function(r) {
                    if (r.success) {
                        window.location.href = r.download;
                    } else { alert('Export failed'); }
                });
            });

            // User diagnostic search
            $(document).on('click', '#xapi-search-user', function() {
                var q = $('#xapi-user-search').val().trim();
                if (!q) return;
                window.location.href = '<?php echo esc_url( admin_url( 'admin.php?page=xapi-monitor&tab=user-diagnostic&q=' ) ); ?>' + encodeURIComponent(q);
            });

            // Live feed filters
            $(document).on('click', '#xapi-apply-filters', function() {
                var url = new URL(window.location.href);
                ['filter_user','filter_verb','filter_status','filter_course','filter_from','filter_to'].forEach(function(f) {
                    var v = $('#' + f).val();
                    if (v) url.searchParams.set(f, v);
                    else url.searchParams.delete(f);
                });
                window.location.href = url.toString();
            });
        });
        </script>
        <?php
    }

    // ============================================================
    // ADMIN CSS
    // ============================================================
    private function render_admin_styles(): void {
        ?>
<style>
.xapi-monitor-wrap h1 { display:flex; align-items:center; gap:8px; }
.xapi-monitor-logo { font-size:1.4em; }
.xapi-nav-tabs { margin-bottom:0; }
.xapi-tab-content { background:#fff; border:1px solid #c3c4c7; border-top:none; padding:20px; }
.xapi-status-captured,.xapi-status-processed { color:#1d8348; font-weight:600; }
.xapi-status-failed,.xapi-status-timeout { color:#922b21; font-weight:600; }
.xapi-status-blocked { color:#784212; font-weight:600; }
.xapi-status-warning { color:#b7950b; font-weight:600; }
.xapi-verb-completed,.xapi-verb-passed { background:#d4efdf; color:#1d6a2e; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700; text-transform:uppercase; }
.xapi-verb-failed { background:#fadbd8; color:#922b21; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700; text-transform:uppercase; }
.xapi-verb-attempted { background:#fdebd0; color:#784212; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700; text-transform:uppercase; }
.xapi-verb-experienced,.xapi-verb-progressed { background:#d6eaf8; color:#1a5276; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700; text-transform:uppercase; }
.xapi-verb-other { background:#eaf0fb; color:#2c3e50; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:700; text-transform:uppercase; }
.xapi-raw-row { display:none; }
.xapi-raw-row td { background:#f8f9fa; }
.xapi-raw-json { font-family:monospace; font-size:11px; white-space:pre-wrap; max-height:300px; overflow-y:auto; background:#1e1e1e; color:#d4d4d4; padding:12px; border-radius:4px; }
.xapi-filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:16px; background:#f8f9fa; padding:12px; border:1px solid #ddd; border-radius:4px; }
.xapi-filter-bar label { font-size:12px; font-weight:600; display:flex; flex-direction:column; gap:4px; }
.xapi-filter-bar input,.xapi-filter-bar select { padding:4px 8px; border:1px solid #c3c4c7; border-radius:3px; }
.xapi-alert-critical { border-left:4px solid #e74c3c; background:#fdfefe; }
.xapi-alert-warning { border-left:4px solid #e67e22; background:#fdfefe; }
.xapi-alert-info { border-left:4px solid #3498db; background:#fdfefe; }
.xapi-alert-row td { padding:12px 8px; }
.xapi-badge-critical { background:#e74c3c; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.xapi-badge-warning { background:#e67e22; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.xapi-badge-info { background:#3498db; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.xapi-user-timeline { width:100%; border-collapse:collapse; }
.xapi-user-timeline th,.xapi-user-timeline td { border:1px solid #ddd; padding:8px 10px; font-size:13px; }
.xapi-user-timeline th { background:#f1f3f5; font-weight:600; }
.xapi-health-check { display:inline-block; padding:4px 12px; border-radius:3px; font-weight:600; font-size:13px; }
.xapi-health-ok { background:#d4efdf; color:#1d6a2e; }
.xapi-health-fail { background:#fadbd8; color:#922b21; }
.xapi-health-unknown { background:#eaecee; color:#566573; }
.xapi-actions-bar { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
.xapi-stat-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; margin-bottom:20px; }
.xapi-stat-card { background:#f8f9fa; border:1px solid #ddd; border-radius:6px; padding:16px; text-align:center; }
.xapi-stat-card .xapi-stat-value { font-size:2em; font-weight:700; color:#2c3e50; }
.xapi-stat-card .xapi-stat-label { font-size:12px; color:#7f8c8d; margin-top:4px; }
.xapi-comparison-match { color:#1d8348; }
.xapi-comparison-mismatch { color:#922b21; font-weight:600; }
.xapi-comparison-missing { color:#784212; }
table.wp-list-table td.column-raw-json { font-family:monospace; font-size:11px; }
.xapi-section-header { font-size:16px; font-weight:600; margin:20px 0 10px; padding-bottom:8px; border-bottom:2px solid #eee; }
.xapi-docs-section { margin-bottom:24px; }
.xapi-docs-section h2 { font-size:18px; font-weight:700; margin-bottom:8px; }
.xapi-docs-section h3 { font-size:15px; font-weight:700; margin:16px 0 6px; }
.xapi-docs-section table.xapi-verb-table { border-collapse:collapse; width:100%; margin:12px 0; }
.xapi-docs-section table.xapi-verb-table th,.xapi-docs-section table.xapi-verb-table td { border:1px solid #ddd; padding:8px 10px; font-size:13px; }
.xapi-docs-section table.xapi-verb-table th { background:#f1f3f5; font-weight:600; }
.xapi-docs-section .xapi-pipeline-steps { list-style:none; padding:0; margin:12px 0; }
.xapi-docs-section .xapi-pipeline-steps li { display:flex; align-items:flex-start; gap:10px; margin-bottom:10px; }
.xapi-docs-section .xapi-pipeline-steps li .step-num { background:#3498db; color:#fff; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; flex-shrink:0; }
.xapi-reference { font-size:85%; padding-left:2em; text-indent:-2em; margin:8px 0; line-height:1.5; }
.xapi-scholarly-note { background:#fff8e1; border-left:4px solid #ffc107; padding:10px 14px; margin:12px 0; font-size:13px; }
</style>
        <?php
    }

    // ============================================================
    // TAB 1: LIVE STATEMENT FEED
    // ============================================================
    private function render_tab_live_feed( string $nonce ): void {
        // Pagination
        $per_page   = 50;
        $current_pg = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset     = ( $current_pg - 1 ) * $per_page;

        // Filters
        $filter_user   = sanitize_text_field( $_GET['filter_user'] ?? '' );
        $filter_verb   = sanitize_text_field( $_GET['filter_verb'] ?? '' );
        $filter_status = sanitize_text_field( $_GET['filter_status'] ?? '' );
        $filter_course = sanitize_text_field( $_GET['filter_course'] ?? '' );
        $filter_from   = sanitize_text_field( $_GET['filter_from'] ?? '' );
        $filter_to     = sanitize_text_field( $_GET['filter_to'] ?? '' );

        $where  = ' WHERE 1=1';
        $params = [];

        if ( $filter_user ) {
            $where    .= ' AND (user_email LIKE %s OR user_display_name LIKE %s OR user_id = %d)';
            $params[]  = '%' . $this->db->esc_like( $filter_user ) . '%';
            $params[]  = '%' . $this->db->esc_like( $filter_user ) . '%';
            $params[]  = (int) $filter_user;
        }
        if ( $filter_verb ) {
            $where    .= ' AND verb = %s';
            $params[]  = $filter_verb;
        }
        if ( $filter_status ) {
            $where    .= ' AND delivery_status = %s';
            $params[]  = $filter_status;
        }
        if ( $filter_course ) {
            $where    .= ' AND (course_title LIKE %s OR course_id = %d)';
            $params[]  = '%' . $this->db->esc_like( $filter_course ) . '%';
            $params[]  = (int) $filter_course;
        }
        if ( $filter_from ) {
            $where    .= ' AND timestamp >= %s';
            $params[]  = $filter_from . ' 00:00:00';
        }
        if ( $filter_to ) {
            $where    .= ' AND timestamp <= %s';
            $params[]  = $filter_to . ' 23:59:59';
        }

        if ( empty( $filter_from ) && empty( $filter_to ) ) {
            $where .= ' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)';
        }

        $count_sql = "SELECT COUNT(*) FROM {$this->log_table}{$where}";
        $data_sql  = "SELECT * FROM {$this->log_table}{$where} ORDER BY timestamp DESC LIMIT %d OFFSET %d";

        if ( ! empty( $params ) ) {
            $all_params   = array_merge( $params, [ $per_page, $offset ] );
            $total        = (int) $this->db->get_var( $this->db->prepare( $count_sql, $params ) );
            $rows         = $this->db->get_results( $this->db->prepare( $data_sql, $all_params ) );
        } else {
            $count_sql_noparams = "SELECT COUNT(*) FROM {$this->log_table}{$where}";
            $data_sql_noparams  = "SELECT * FROM {$this->log_table}{$where} ORDER BY timestamp DESC LIMIT {$per_page} OFFSET {$offset}";
            $total              = (int) $this->db->get_var( $count_sql_noparams );
            $rows               = $this->db->get_results( $data_sql_noparams );
        }

        $total_pages = (int) ceil( $total / $per_page );

        // Stats bar
        $stats = $this->db->get_row(
            "SELECT COUNT(*) as total,
                    SUM(CASE WHEN delivery_status IN ('captured','processed') THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN delivery_status IN ('failed','timeout') THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN delivery_status = 'blocked' THEN 1 ELSE 0 END) as blocked
             FROM {$this->log_table}
             WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $distinct_verbs    = $this->db->get_col( "SELECT DISTINCT verb FROM {$this->log_table} WHERE verb != '' ORDER BY verb" );
        $distinct_statuses = [ 'captured', 'processed', 'failed', 'timeout', 'blocked' ];

        ?>
        <h2><?php esc_html_e( 'Live Statement Feed', 'xapi-monitor' ); ?></h2>
        <p class="description"><?php esc_html_e( 'All xAPI statements captured in the last 24 hours (default). Use filters to narrow results.', 'xapi-monitor' ); ?></p>

        <div class="xapi-stat-grid">
            <div class="xapi-stat-card">
                <div class="xapi-stat-value"><?php echo (int) ( $stats->total ?? 0 ); ?></div>
                <div class="xapi-stat-label"><?php esc_html_e( 'Total (24h)', 'xapi-monitor' ); ?></div>
            </div>
            <div class="xapi-stat-card">
                <div class="xapi-stat-value" style="color:#1d8348"><?php echo (int) ( $stats->success ?? 0 ); ?></div>
                <div class="xapi-stat-label"><?php esc_html_e( 'Successful', 'xapi-monitor' ); ?></div>
            </div>
            <div class="xapi-stat-card">
                <div class="xapi-stat-value" style="color:#c0392b"><?php echo (int) ( $stats->failed ?? 0 ); ?></div>
                <div class="xapi-stat-label"><?php esc_html_e( 'Failed/Timeout', 'xapi-monitor' ); ?></div>
            </div>
            <div class="xapi-stat-card">
                <div class="xapi-stat-value" style="color:#784212"><?php echo (int) ( $stats->blocked ?? 0 ); ?></div>
                <div class="xapi-stat-label"><?php esc_html_e( 'Blocked', 'xapi-monitor' ); ?></div>
            </div>
        </div>

        <div class="xapi-filter-bar">
            <label><?php esc_html_e( 'User (name/email/ID)', 'xapi-monitor' ); ?>
                <input type="text" id="filter_user" value="<?php echo esc_attr( $filter_user ); ?>" placeholder="Search user…">
            </label>
            <label><?php esc_html_e( 'Verb', 'xapi-monitor' ); ?>
                <select id="filter_verb">
                    <option value=""><?php esc_html_e( 'All verbs', 'xapi-monitor' ); ?></option>
                    <?php foreach ( $distinct_verbs as $v ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $filter_verb, $v ); ?>><?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'Status', 'xapi-monitor' ); ?>
                <select id="filter_status">
                    <option value=""><?php esc_html_e( 'All statuses', 'xapi-monitor' ); ?></option>
                    <?php foreach ( $distinct_statuses as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_status, $s ); ?>><?php echo esc_html( $s ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'Course', 'xapi-monitor' ); ?>
                <input type="text" id="filter_course" value="<?php echo esc_attr( $filter_course ); ?>" placeholder="Title or ID…">
            </label>
            <label><?php esc_html_e( 'From', 'xapi-monitor' ); ?>
                <input type="date" id="filter_from" value="<?php echo esc_attr( $filter_from ); ?>">
            </label>
            <label><?php esc_html_e( 'To', 'xapi-monitor' ); ?>
                <input type="date" id="filter_to" value="<?php echo esc_attr( $filter_to ); ?>">
            </label>
            <button class="button" id="xapi-apply-filters"><?php esc_html_e( 'Apply Filters', 'xapi-monitor' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=xapi-monitor&tab=live-feed' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'xapi-monitor' ); ?></a>
        </div>

        <p style="color:#666;font-size:13px;"><?php printf( esc_html__( 'Showing %1$d results (page %2$d of %3$d)', 'xapi-monitor' ), $total, $current_pg, max(1,$total_pages) ); ?></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:130px"><?php esc_html_e( 'Time', 'xapi-monitor' ); ?></th>
                    <th style="width:150px"><?php esc_html_e( 'User', 'xapi-monitor' ); ?></th>
                    <th><?php esc_html_e( 'Course / Lesson', 'xapi-monitor' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'Verb', 'xapi-monitor' ); ?></th>
                    <th style="width:90px"><?php esc_html_e( 'Status', 'xapi-monitor' ); ?></th>
                    <th style="width:70px"><?php esc_html_e( 'HTTP', 'xapi-monitor' ); ?></th>
                    <th style="width:90px"><?php esc_html_e( 'Source', 'xapi-monitor' ); ?></th>
                    <th style="width:100px"><?php esc_html_e( 'Actions', 'xapi-monitor' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?>
                <tr><td colspan="8" style="text-align:center;padding:20px;color:#888;"><?php esc_html_e( 'No statements found for the current filter criteria.', 'xapi-monitor' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $rows as $row ) : ?>
                    <?php
                    $verb_class = 'xapi-verb-other';
                    $verb       = strtolower( $row->verb );
                    if ( in_array( $verb, [ 'completed', 'passed' ], true ) ) {
                        $verb_class = 'xapi-verb-' . $verb;
                    } elseif ( 'failed' === $verb ) {
                        $verb_class = 'xapi-verb-failed';
                    } elseif ( 'attempted' === $verb ) {
                        $verb_class = 'xapi-verb-attempted';
                    } elseif ( in_array( $verb, [ 'experienced', 'progressed' ], true ) ) {
                        $verb_class = 'xapi-verb-' . $verb;
                    }
                    $status_class = 'xapi-status-' . strtolower( $row->delivery_status );
                    $json_pretty  = '';
                    if ( $row->raw_statement ) {
                        $decoded = json_decode( $row->raw_statement, true );
                        if ( $decoded ) {
                            $json_pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT );
                        } else {
                            $json_pretty = $row->raw_statement;
                        }
                    }
                    ?>
                    <tr>
                        <td><small><?php echo esc_html( $row->timestamp ); ?></small></td>
                        <td>
                            <strong><?php echo esc_html( $row->user_display_name ?: '#' . $row->user_id ); ?></strong><br>
                            <small><?php echo esc_html( $row->user_email ); ?></small>
                        </td>
                        <td>
                            <?php if ( $row->course_title ) : ?>
                                <small style="color:#888"><?php echo esc_html( $row->course_title ); ?></small><br>
                            <?php endif; ?>
                            <?php echo esc_html( $row->lesson_title ?: ( $row->lesson_id ? 'Lesson #' . $row->lesson_id : '—' ) ); ?>
                        </td>
                        <td><span class="<?php echo esc_attr( $verb_class ); ?>"><?php echo esc_html( $row->verb ?: '—' ); ?></span></td>
                        <td><span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $row->delivery_status ); ?></span>
                            <?php if ( $row->failure_reason ) : ?>
                                <br><small style="color:#c0392b"><?php echo esc_html( wp_trim_words( $row->failure_reason, 6 ) ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $row->endpoint_response_code ? esc_html( $row->endpoint_response_code ) : '—'; ?></td>
                        <td><small><?php echo esc_html( $row->source ); ?></small></td>
                        <td>
                            <button class="button button-small xapi-expand-btn">▼ Expand</button>
                            <button class="button button-small xapi-resend-stmt" data-log="<?php echo (int) $row->id; ?>">Resend</button>
                        </td>
                    </tr>
                    <?php if ( $json_pretty ) : ?>
                    <tr class="xapi-raw-row">
                        <td colspan="8">
                            <pre class="xapi-raw-json"><?php echo esc_html( $json_pretty ); ?></pre>
                            <?php if ( $row->failure_reason ) : ?>
                                <p style="color:#c0392b;padding:8px 0 0"><strong><?php esc_html_e( 'Failure reason:', 'xapi-monitor' ); ?></strong> <?php echo esc_html( $row->failure_reason ); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav bottom" style="margin-top:10px">
            <div class="tablenav-pages">
                <?php
                $base = admin_url( 'admin.php?page=xapi-monitor&tab=live-feed' );
                foreach ( [ 'filter_user'=>$filter_user,'filter_verb'=>$filter_verb,'filter_status'=>$filter_status,'filter_course'=>$filter_course,'filter_from'=>$filter_from,'filter_to'=>$filter_to ] as $k => $v ) {
                    if ( $v ) {
                        $base .= '&' . $k . '=' . urlencode( $v );
                    }
                }
                echo paginate_links( [
                    'base'      => $base . '&paged=%#%',
                    'format'    => '',
                    'current'   => $current_pg,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ] );
                ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    // ============================================================
    // TAB 2: ALERTS & DIAGNOSTICS
    // ============================================================
    private function render_tab_alerts( string $nonce ): void {
        $filter_type     = sanitize_text_field( $_GET['filter_type'] ?? '' );
        $filter_severity = sanitize_text_field( $_GET['filter_severity'] ?? '' );
        $filter_resolved = isset( $_GET['filter_resolved'] ) ? (int) $_GET['filter_resolved'] : -1;

        $where  = ' WHERE 1=1';
        $params = [];

        if ( $filter_type ) {
            $where    .= ' AND alert_type = %s';
            $params[]  = $filter_type;
        }
        if ( $filter_severity ) {
            $where    .= ' AND severity = %s';
            $params[]  = $filter_severity;
        }
        if ( $filter_resolved >= 0 ) {
            $where    .= ' AND resolved = %d';
            $params[]  = $filter_resolved;
        }

        $sql  = "SELECT * FROM {$this->alerts_table}{$where} ORDER BY timestamp DESC LIMIT 100";
        $rows = ! empty( $params )
            ? $this->db->get_results( $this->db->prepare( $sql, $params ) )
            : $this->db->get_results( "SELECT * FROM {$this->alerts_table}{$where} ORDER BY timestamp DESC LIMIT 100" );

        $alert_types = [ 'missing_completion','endpoint_failure','statement_gap','high_failure_rate','user_stuck' ];
        $severities  = [ 'critical','warning','info' ];

        $open_count    = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->alerts_table} WHERE resolved = 0 AND severity = 'critical'" );
        $last_diag_run = get_option( 'xapi_monitor_last_diagnostic', null );
        ?>
        <h2><?php esc_html_e( 'Alerts & Diagnostics', 'xapi-monitor' ); ?>
            <?php if ( $open_count ) : ?>
                <span style="background:#e74c3c;color:#fff;padding:3px 10px;border-radius:10px;font-size:13px;margin-left:10px"><?php echo $open_count; ?> <?php esc_html_e( 'open critical', 'xapi-monitor' ); ?></span>
            <?php endif; ?>
        </h2>
        <?php if ( $last_diag_run ) : ?>
            <p style="color:#666;font-size:12px;margin-top:-4px">
                <?php esc_html_e( 'Last diagnostic run:', 'xapi-monitor' ); ?>
                <strong><?php echo esc_html( $last_diag_run ); ?></strong>
            </p>
        <?php endif; ?>

        <div class="xapi-actions-bar">
            <button class="button button-primary" id="xapi-run-diagnostic"><?php esc_html_e( 'Run Diagnostic Now', 'xapi-monitor' ); ?></button>
        </div>

        <div class="xapi-filter-bar">
            <label><?php esc_html_e( 'Type', 'xapi-monitor' ); ?>
                <select onchange="this.form&&this.form.submit()" id="filter_type_alert">
                    <option value=""><?php esc_html_e( 'All types', 'xapi-monitor' ); ?></option>
                    <?php foreach ( $alert_types as $t ) : ?>
                        <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $filter_type, $t ); ?>><?php echo esc_html( ucwords( str_replace( '_', ' ', $t ) ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'Severity', 'xapi-monitor' ); ?>
                <select id="filter_severity_alert">
                    <option value=""><?php esc_html_e( 'All', 'xapi-monitor' ); ?></option>
                    <?php foreach ( $severities as $s ) : ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_severity, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label><?php esc_html_e( 'Resolved', 'xapi-monitor' ); ?>
                <select id="filter_resolved_alert">
                    <option value="-1"><?php esc_html_e( 'All', 'xapi-monitor' ); ?></option>
                    <option value="0" <?php selected( $filter_resolved, 0 ); ?>><?php esc_html_e( 'Open', 'xapi-monitor' ); ?></option>
                    <option value="1" <?php selected( $filter_resolved, 1 ); ?>><?php esc_html_e( 'Resolved', 'xapi-monitor' ); ?></option>
                </select>
            </label>
            <button class="button" onclick="
                var url = new URL(window.location.href);
                url.searchParams.set('filter_type', document.getElementById('filter_type_alert').value);
                url.searchParams.set('filter_severity', document.getElementById('filter_severity_alert').value);
                url.searchParams.set('filter_resolved', document.getElementById('filter_resolved_alert').value);
                window.location.href = url.toString();"><?php esc_html_e( 'Apply', 'xapi-monitor' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=xapi-monitor&tab=alerts' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'xapi-monitor' ); ?></a>
        </div>

        <?php if ( empty( $rows ) ) : ?>
            <p style="color:#888;padding:20px 0;"><?php esc_html_e( 'No alerts found.', 'xapi-monitor' ); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat">
                <thead>
                    <tr>
                        <th style="width:120px"><?php esc_html_e( 'Time', 'xapi-monitor' ); ?></th>
                        <th style="width:100px"><?php esc_html_e( 'Type', 'xapi-monitor' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Severity', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'xapi-monitor' ); ?></th>
                        <th style="width:150px"><?php esc_html_e( 'Affected', 'xapi-monitor' ); ?></th>
                        <th style="width:80px"><?php esc_html_e( 'Status', 'xapi-monitor' ); ?></th>
                        <th style="width:200px"><?php esc_html_e( 'Actions', 'xapi-monitor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $alert ) :
                    $diag   = json_decode( $alert->diagnostic_data, true ) ?: [];
                    $user   = $alert->user_id ? get_userdata( $alert->user_id ) : null;
                    $course = $alert->course_id ? get_the_title( $alert->course_id ) : '';
                    $lesson = $alert->lesson_id ? get_the_title( $alert->lesson_id ) : '';
                    $recommended = $this->get_recommended_action( $alert->alert_type );
                    $row_class = 'xapi-alert-' . $alert->severity;
                    ?>
                    <tr class="xapi-alert-row <?php echo esc_attr( $row_class ); ?>">
                        <td><small><?php echo esc_html( $alert->timestamp ); ?></small></td>
                        <td><code><?php echo esc_html( $alert->alert_type ); ?></code></td>
                        <td><span class="<?php echo 'xapi-badge-' . esc_attr( $alert->severity ); ?>"><?php echo esc_html( strtoupper( $alert->severity ) ); ?></span></td>
                        <td>
                            <strong><?php echo esc_html( $alert->message ); ?></strong>
                            <?php if ( ! empty( $diag ) ) : ?>
                                <details style="margin-top:6px">
                                    <summary style="cursor:pointer;font-size:12px;color:#3498db"><?php esc_html_e( 'Diagnostic Data', 'xapi-monitor' ); ?></summary>
                                    <pre style="font-size:11px;background:#f8f9fa;padding:8px;margin-top:4px"><?php echo esc_html( wp_json_encode( $diag, JSON_PRETTY_PRINT ) ); ?></pre>
                                </details>
                            <?php endif; ?>
                            <?php
                            // Evidence panel (v1.2.0)
                            $evidence_raw  = $alert->evidence ?? '';
                            $evidence_data = $evidence_raw ? json_decode( $evidence_raw, true ) : null;
                            if ( $evidence_data ) :
                            ?>
                                <details style="margin-top:6px">
                                    <summary style="cursor:pointer;font-size:12px;color:#8e44ad;font-weight:600"><?php esc_html_e( 'View Evidence', 'xapi-monitor' ); ?></summary>
                                    <div style="background:#fdf9ff;border:1px solid #d7bde2;border-radius:4px;padding:10px;margin-top:4px;font-size:12px">
                                        <?php if ( ! empty( $evidence_data['tin_canny_rows'] ) ) : ?>
                                            <p style="margin:0 0 4px"><strong><?php esc_html_e( 'Tin Canny DB rows (wpv5_uotincan_reporting):', 'xapi-monitor' ); ?></strong></p>
                                            <pre style="font-size:10px;background:#1e1e1e;color:#d4d4d4;padding:8px;border-radius:3px;overflow-x:auto;max-height:150px"><?php echo esc_html( wp_json_encode( $evidence_data['tin_canny_rows'], JSON_PRETTY_PRINT ) ); ?></pre>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $evidence_data['learndash_activity'] ) ) : ?>
                                            <p style="margin:6px 0 4px"><strong><?php esc_html_e( 'LearnDash activity record:', 'xapi-monitor' ); ?></strong></p>
                                            <pre style="font-size:10px;background:#1e1e1e;color:#d4d4d4;padding:8px;border-radius:3px;overflow-x:auto;max-height:120px"><?php echo esc_html( wp_json_encode( $evidence_data['learndash_activity'], JSON_PRETTY_PRINT ) ); ?></pre>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $evidence_data['log_ids'] ) ) : ?>
                                            <p style="margin:6px 0 2px"><strong><?php esc_html_e( 'xAPI Monitor log IDs:', 'xapi-monitor' ); ?></strong>
                                            <?php echo esc_html( implode( ', ', array_map( 'intval', $evidence_data['log_ids'] ) ) ); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                            <details style="margin-top:6px">
                                <summary style="cursor:pointer;font-size:12px;color:#27ae60"><?php esc_html_e( 'Recommended Action', 'xapi-monitor' ); ?></summary>
                                <p style="font-size:12px;padding:8px;background:#eafaf1;white-space:pre-line"><?php echo esc_html( $recommended ); ?></p>
                            </details>
                            <?php if ( $alert->resolved && $alert->resolution_notes ) : ?>
                                <p style="font-size:12px;color:#27ae60;margin-top:4px">
                                    <strong><?php esc_html_e( 'Resolution:', 'xapi-monitor' ); ?></strong> <?php echo esc_html( $alert->resolution_notes ); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $user ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=xapi-monitor&tab=user-diagnostic&user_id=' . $alert->user_id ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br>
                                <small><?php echo esc_html( $user->user_email ); ?></small><br>
                            <?php endif; ?>
                            <?php if ( $course ) : ?>
                                <small><?php echo esc_html( $course ); ?></small><br>
                            <?php endif; ?>
                            <?php if ( $lesson ) : ?>
                                <small><?php esc_html_e( 'Lesson:', 'xapi-monitor' ); ?> <?php echo esc_html( $lesson ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( $alert->resolved ) : ?>
                                <span style="color:#27ae60;font-weight:600"><?php esc_html_e( 'Resolved', 'xapi-monitor' ); ?></span>
                                <?php if ( $alert->resolved_at ) : ?>
                                    <br><small><?php echo esc_html( $alert->resolved_at ); ?></small>
                                <?php endif; ?>
                            <?php else : ?>
                                <span style="color:#e74c3c;font-weight:600"><?php esc_html_e( 'Open', 'xapi-monitor' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! $alert->resolved ) : ?>
                                <button class="button button-small xapi-resolve-alert" data-alert="<?php echo (int) $alert->id; ?>"><?php esc_html_e( 'Resolve', 'xapi-monitor' ); ?></button>
                            <?php endif; ?>
                            <?php if ( $alert->user_id && $alert->lesson_id ) : ?>
                                <button class="button button-small xapi-force-complete" data-user="<?php echo (int) $alert->user_id; ?>" data-lesson="<?php echo (int) $alert->lesson_id; ?>"><?php esc_html_e( 'Force Complete', 'xapi-monitor' ); ?></button>
                                <button class="button button-small xapi-reset-xapi" data-user="<?php echo (int) $alert->user_id; ?>" data-lesson="<?php echo (int) $alert->lesson_id; ?>"><?php esc_html_e( 'Reset xAPI', 'xapi-monitor' ); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    // ============================================================
    // TAB 3: USER DIAGNOSTIC
    // ============================================================
    private function render_tab_user_diagnostic( string $nonce ): void {
        $search_q = sanitize_text_field( $_GET['q'] ?? '' );
        $user_id  = (int) ( $_GET['user_id'] ?? 0 );
        $found_users = [];

        if ( $search_q ) {
            $found_users = get_users( [
                'search'         => '*' . $search_q . '*',
                'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
                'number'         => 20,
            ] );
            if ( count( $found_users ) === 1 ) {
                $user_id = $found_users[0]->ID;
            }
        }
        ?>
        <h2><?php esc_html_e( 'User Diagnostic', 'xapi-monitor' ); ?></h2>

        <div style="display:flex;gap:8px;margin-bottom:20px;align-items:center">
            <input type="text" id="xapi-user-search" value="<?php echo esc_attr( $search_q ); ?>"
                placeholder="<?php esc_attr_e( 'Search by name, email, or username…', 'xapi-monitor' ); ?>"
                style="width:300px;padding:6px 10px">
            <button class="button button-primary" id="xapi-search-user"><?php esc_html_e( 'Search', 'xapi-monitor' ); ?></button>
        </div>

        <?php if ( $search_q && count( $found_users ) > 1 && ! $user_id ) : ?>
            <p><?php esc_html_e( 'Multiple users found. Click a user to view their diagnostic:', 'xapi-monitor' ); ?></p>
            <ul>
                <?php foreach ( $found_users as $u ) : ?>
                    <li>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=xapi-monitor&tab=user-diagnostic&user_id=' . $u->ID ) ); ?>">
                            <?php echo esc_html( $u->display_name ); ?> — <?php echo esc_html( $u->user_email ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php elseif ( $user_id ) :
            $data = $this->get_user_diagnostic_data( $user_id );
            $user = get_userdata( $user_id );
            if ( ! $user ) : ?>
                <p style="color:#c0392b"><?php esc_html_e( 'User not found.', 'xapi-monitor' ); ?></p>
            <?php else : ?>
                <h3><?php echo esc_html( $user->display_name ); ?> &lt;<?php echo esc_html( $user->user_email ); ?>&gt;</h3>
                <p>
                    <?php esc_html_e( 'User ID:', 'xapi-monitor' ); ?> <strong><?php echo $user_id; ?></strong> |
                    <?php esc_html_e( 'Registered:', 'xapi-monitor' ); ?> <strong><?php echo esc_html( $user->user_registered ); ?></strong>
                </p>

                <div class="xapi-actions-bar">
                    <button class="button xapi-force-complete" data-user="<?php echo $user_id; ?>" data-lesson="0"
                        onclick="var l=prompt('Enter Lesson/Topic ID:');if(l){jQuery.post(ajaxurl,{action:'xapi_monitor_force_complete',nonce:<?php echo wp_json_encode( $nonce ); ?>,user_id:<?php echo $user_id; ?>,lesson_id:parseInt(l)},function(r){alert(r.success?r.message:r.error);location.reload();});} return false;">
                        <?php esc_html_e( 'Force Complete a Lesson', 'xapi-monitor' ); ?>
                    </button>
                    <button class="button xapi-reset-xapi" data-user="<?php echo $user_id; ?>" data-lesson="0"
                        onclick="var l=prompt('Enter Lesson/Topic ID to reset (0 for all):');if(l!==null){jQuery.post(ajaxurl,{action:'xapi_monitor_reset_xapi',nonce:<?php echo wp_json_encode( $nonce ); ?>,user_id:<?php echo $user_id; ?>,lesson_id:parseInt(l)},function(r){alert(r.success?r.message:r.error);location.reload();});} return false;">
                        <?php esc_html_e( 'Reset xAPI Data', 'xapi-monitor' ); ?>
                    </button>
                </div>

                <?php
                // Quiz step warning (v1.2.0)
                $qsw = $data['quiz_step_warning'] ?? [ 'has_warning' => false ];
                if ( ! empty( $qsw['has_warning'] ) ) :
                ?>
                    <div class="notice notice-warning inline" style="margin:12px 0;padding:10px 14px;">
                        <p><strong><?php esc_html_e( 'Quiz Step Warning', 'xapi-monitor' ); ?></strong><br>
                        <?php echo esc_html( $qsw['message'] ?? '' ); ?></p>
                        <?php if ( ! empty( $qsw['quiz_steps'] ) ) : ?>
                            <ul style="margin:8px 0 0 20px;font-size:13px">
                            <?php foreach ( $qsw['quiz_steps'] as $qs ) : ?>
                                <li><strong><?php echo esc_html( $qs['title'] ); ?></strong> (Quiz ID: <?php echo (int) $qs['quiz_id']; ?><?php echo $qs['lesson_id'] ? ', Lesson ID: ' . (int) $qs['lesson_id'] : ''; ?>)</li>
                            <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $data['courses'] ) ) : ?>
                    <?php foreach ( $data['courses'] as $course_data ) : ?>
                        <h4 style="background:#f1f3f5;padding:10px;border-left:4px solid #3498db;margin-top:20px">
                            <?php echo esc_html( $course_data['course_title'] ); ?> (ID: <?php echo (int) $course_data['course_id']; ?>)
                        </h4>

                        <table class="xapi-user-timeline">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Lesson/Topic', 'xapi-monitor' ); ?></th>
                                    <th><?php esc_html_e( 'xAPI Verbs Received', 'xapi-monitor' ); ?></th>
                                    <th><?php esc_html_e( 'Tin Canny Has Completion?', 'xapi-monitor' ); ?></th>
                                    <th><?php esc_html_e( 'LearnDash Complete?', 'xapi-monitor' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'xapi-monitor' ); ?></th>
                                    <th><?php esc_html_e( 'Actions', 'xapi-monitor' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $course_data['lessons'] as $lesson_data ) : ?>
                                <?php
                                $has_tc  = $lesson_data['tc_has_completion'] ?? false;
                                $has_ld  = $lesson_data['ld_complete'] ?? false;
                                $mismatch = $has_tc && ! $has_ld;
                                $row_bg   = $mismatch ? 'background:#fef9e7' : ( ! $has_tc && $lesson_data['has_activity'] ? 'background:#fdf2f8' : '' );
                                ?>
                                <tr style="<?php echo esc_attr( $row_bg ); ?>">
                                    <td>
                                        <strong><?php echo esc_html( $lesson_data['lesson_title'] ); ?></strong>
                                        <br><small>ID: <?php echo (int) $lesson_data['lesson_id']; ?></small>
                                    </td>
                                    <td>
                                        <?php if ( ! empty( $lesson_data['verbs'] ) ) :
                                            foreach ( $lesson_data['verbs'] as $verb_entry ) : ?>
                                                <span class="xapi-verb-<?php echo esc_attr( $verb_entry['verb'] ); ?>" style="display:inline-block;margin:1px"><?php echo esc_html( $verb_entry['verb'] ); ?></span>
                                            <?php endforeach;
                                        else : ?>
                                            <em style="color:#888"><?php esc_html_e( 'No statements', 'xapi-monitor' ); ?></em>
                                        <?php endif; ?>
                                    </td>
                                    <td class="<?php echo $has_tc ? 'xapi-comparison-match' : 'xapi-comparison-missing'; ?>">
                                        <?php echo $has_tc ? esc_html__( 'Yes', 'xapi-monitor' ) : esc_html__( 'No', 'xapi-monitor' ); ?>
                                    </td>
                                    <td class="<?php echo $has_ld ? 'xapi-comparison-match' : 'xapi-comparison-missing'; ?>">
                                        <?php echo $has_ld ? esc_html__( 'Yes', 'xapi-monitor' ) : esc_html__( 'No', 'xapi-monitor' ); ?>
                                    </td>
                                    <td>
                                        <?php if ( $mismatch ) : ?>
                                            <span class="xapi-comparison-mismatch"><?php esc_html_e( 'MISMATCH', 'xapi-monitor' ); ?></span>
                                        <?php elseif ( $has_ld ) : ?>
                                            <span class="xapi-comparison-match"><?php esc_html_e( 'Complete', 'xapi-monitor' ); ?></span>
                                        <?php elseif ( $lesson_data['has_activity'] ) : ?>
                                            <span class="xapi-comparison-missing"><?php esc_html_e( 'In Progress', 'xapi-monitor' ); ?></span>
                                        <?php else : ?>
                                            <span style="color:#888"><?php esc_html_e( 'Not Started', 'xapi-monitor' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ( ! $has_ld && $lesson_data['lesson_id'] ) : ?>
                                            <button class="button button-small xapi-force-complete"
                                                data-user="<?php echo $user_id; ?>"
                                                data-lesson="<?php echo (int) $lesson_data['lesson_id']; ?>">
                                                <?php esc_html_e( 'Force Complete', 'xapi-monitor' ); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php // Data Sources panel (v1.2.0) ?>
                        <details style="margin:10px 0">
                            <summary style="cursor:pointer;font-size:12px;color:#666;font-weight:600"><?php esc_html_e( 'Data Sources for This Course', 'xapi-monitor' ); ?></summary>
                            <div style="background:#f8f9fa;border:1px solid #ddd;border-radius:4px;padding:10px;margin-top:4px;font-size:12px">
                                <p style="margin:0 0 4px"><strong><?php esc_html_e( 'xAPI Monitor Log Table:', 'xapi-monitor' ); ?></strong> <code><?php echo esc_html( $this->log_table ); ?></code>
                                — <?php echo esc_html( count( $course_data['statement_timeline'] ?? [] ) ); ?> <?php esc_html_e( 'rows for this course', 'xapi-monitor' ); ?></p>
                                <p style="margin:4px 0"><strong><?php esc_html_e( 'Tin Canny Reporting Table:', 'xapi-monitor' ); ?></strong> <code><?php echo esc_html( $this->db->prefix . 'uotincan_reporting' ); ?></code>
                                — <?php esc_html_e( 'queried live for completion/passed rows per lesson', 'xapi-monitor' ); ?></p>
                                <p style="margin:4px 0"><strong><?php esc_html_e( 'LearnDash:', 'xapi-monitor' ); ?></strong> <?php esc_html_e( 'learndash_is_lesson_complete() called per lesson row above', 'xapi-monitor' ); ?></p>
                                <?php if ( ! empty( $course_data['statement_timeline'] ) ) :
                                    $log_ids = array_map( fn( $s ) => (int) $s->id, $course_data['statement_timeline'] );
                                    sort( $log_ids );
                                ?>
                                <p style="margin:4px 0"><strong><?php esc_html_e( 'Log row IDs:', 'xapi-monitor' ); ?></strong> <?php echo esc_html( implode( ', ', array_slice( $log_ids, 0, 20 ) ) ); ?><?php echo count( $log_ids ) > 20 ? ' …' : ''; ?></p>
                                <?php endif; ?>
                            </div>
                        </details>

                        <?php // Statement timeline for this course ?>
                        <?php if ( ! empty( $course_data['statement_timeline'] ) ) : ?>
                            <p class="xapi-section-header"><?php esc_html_e( 'Statement Timeline', 'xapi-monitor' ); ?></p>
                            <table class="xapi-user-timeline" style="font-size:12px">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Time', 'xapi-monitor' ); ?></th>
                                        <th><?php esc_html_e( 'Lesson', 'xapi-monitor' ); ?></th>
                                        <th><?php esc_html_e( 'Verb', 'xapi-monitor' ); ?></th>
                                        <th><?php esc_html_e( 'Activity', 'xapi-monitor' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'xapi-monitor' ); ?></th>
                                        <th><?php esc_html_e( 'Source', 'xapi-monitor' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $course_data['statement_timeline'] as $stmt ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $stmt->timestamp ); ?></td>
                                        <td><?php echo esc_html( $stmt->lesson_title ?: '#' . $stmt->lesson_id ); ?></td>
                                        <td><span class="xapi-verb-<?php echo esc_attr( strtolower( $stmt->verb ) ); ?>"><?php echo esc_html( $stmt->verb ); ?></span></td>
                                        <td><small><?php echo esc_html( $stmt->activity_name ?: wp_trim_words( $stmt->activity_id, 5 ) ); ?></small></td>
                                        <td class="xapi-status-<?php echo esc_attr( $stmt->delivery_status ); ?>"><?php echo esc_html( $stmt->delivery_status ); ?></td>
                                        <td><small><?php echo esc_html( $stmt->source ); ?></small></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p style="color:#888"><?php esc_html_e( 'No xAPI statement history found for this user. They may not have accessed any xAPI content yet.', 'xapi-monitor' ); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        <?php elseif ( $search_q ) : ?>
            <p style="color:#888"><?php esc_html_e( 'No users found for that search.', 'xapi-monitor' ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Build diagnostic data for a user.
     */
    private function get_user_diagnostic_data( int $user_id ): array {
        $result = [ 'user_id' => $user_id, 'courses' => [], 'quiz_step_warning' => [ 'has_warning' => false ] ];

        // Get all statements for this user
        $statements = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->log_table}
                 WHERE user_id = %d
                 ORDER BY timestamp DESC LIMIT 500",
                $user_id
            )
        );

        if ( empty( $statements ) ) {
            return $result;
        }

        // Group by course
        $by_course = [];
        foreach ( $statements as $stmt ) {
            $cid = (int) $stmt->course_id;
            if ( ! isset( $by_course[ $cid ] ) ) {
                $by_course[ $cid ] = [
                    'course_id'          => $cid,
                    'course_title'       => $stmt->course_title ?: ( $cid ? ( get_the_title( $cid ) ?: 'Course #' . $cid ) : 'Unknown Course' ),
                    'statement_timeline' => [],
                    'lessons'            => [],
                ];
            }
            $by_course[ $cid ]['statement_timeline'][] = $stmt;

            // Group by lesson
            $lid = (int) $stmt->lesson_id;
            if ( $lid ) {
                if ( ! isset( $by_course[ $cid ]['lessons'][ $lid ] ) ) {
                    $by_course[ $cid ]['lessons'][ $lid ] = [
                        'lesson_id'    => $lid,
                        'lesson_title' => $stmt->lesson_title ?: ( get_the_title( $lid ) ?: 'Lesson #' . $lid ),
                        'verbs'        => [],
                        'has_activity' => false,
                        'tc_has_completion' => false,
                        'ld_complete'  => false,
                    ];
                }
                $verb_lower = strtolower( $stmt->verb );
                $by_course[ $cid ]['lessons'][ $lid ]['verbs'][] = [
                    'verb'      => $verb_lower,
                    'timestamp' => $stmt->timestamp,
                    'status'    => $stmt->delivery_status,
                ];
                if ( in_array( $verb_lower, [ 'experienced', 'attempted', 'progressed', 'answered' ], true ) ) {
                    $by_course[ $cid ]['lessons'][ $lid ]['has_activity'] = true;
                }
                if ( in_array( $verb_lower, [ 'completed', 'passed' ], true ) ) {
                    $by_course[ $cid ]['lessons'][ $lid ]['tc_has_completion'] = true;
                }
            }
        }

        // Check LearnDash completion for each lesson
        // Track the first course with quiz step warning
        $combined_quiz_warning = [ 'has_warning' => false ];
        $userdata = get_userdata( $user_id );

        foreach ( $by_course as &$cd ) {
            foreach ( $cd['lessons'] as &$ld_data ) {
                if ( function_exists( 'learndash_is_lesson_complete' ) && $userdata ) {
                    $ld_data['ld_complete'] = (bool) learndash_is_lesson_complete(
                        $userdata,
                        $ld_data['lesson_id']
                    );
                }
            }
            unset( $ld_data );
            // Sort lessons alphabetically by title
            usort( $cd['lessons'], fn( $a, $b ) => strcmp( $a['lesson_title'], $b['lesson_title'] ) );

            // Check quiz step mismatch for this course
            if ( $cd['course_id'] && ! $combined_quiz_warning['has_warning'] ) {
                $quiz_check = $this->check_quiz_step_mismatch( $user_id, $cd['course_id'] );
                if ( $quiz_check['has_warning'] ) {
                    $combined_quiz_warning = $quiz_check;
                }
            }
        }
        unset( $cd );

        $result['courses']           = array_values( $by_course );
        $result['quiz_step_warning'] = $combined_quiz_warning;
        return $result;
    }

    // ============================================================
    // TAB 4: SYSTEM HEALTH
    // ============================================================
    private function render_tab_system_health( string $nonce ): void {
        $health = $this->get_system_health_data();
        $last_health_check = get_option( 'xapi_monitor_last_health_check', [] );
        ?>
        <h2><?php esc_html_e( 'System Health', 'xapi-monitor' ); ?></h2>
        <?php $this->maybe_render_memory_warning(); ?>

        <div class="xapi-actions-bar">
            <button class="button button-primary" id="xapi-test-endpoint"><?php esc_html_e( 'Test Endpoint Now', 'xapi-monitor' ); ?></button>
            <button class="button" id="xapi-run-diagnostic"><?php esc_html_e( 'Run Full Diagnostic', 'xapi-monitor' ); ?></button>
        </div>

        <?php // Endpoint Status ?>
        <div class="xapi-section-header"><?php esc_html_e( 'Endpoint Connectivity', 'xapi-monitor' ); ?></div>
        <?php if ( ! empty( $last_health_check['result'] ) ) :
            $hc = $last_health_check['result'];
            $hc_class = $hc['success'] ? 'xapi-health-ok' : 'xapi-health-fail';
            ?>
            <table class="wp-list-table widefat" style="max-width:600px">
                <tr>
                    <th style="width:200px"><?php esc_html_e( 'Last Check Time', 'xapi-monitor' ); ?></th>
                    <td><?php echo esc_html( $last_health_check['timestamp'] ?? '—' ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Status', 'xapi-monitor' ); ?></th>
                    <td><span class="xapi-health-check <?php echo esc_attr( $hc_class ); ?>"><?php echo $hc['success'] ? esc_html__( 'PASSING', 'xapi-monitor' ) : esc_html__( 'FAILING', 'xapi-monitor' ); ?></span></td>
                </tr>
                <?php if ( isset( $hc['endpoint'] ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Endpoint', 'xapi-monitor' ); ?></th>
                    <td><code><?php echo esc_html( $hc['endpoint'] ); ?></code></td>
                </tr>
                <?php endif; ?>
                <?php if ( isset( $hc['code'] ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'HTTP Response', 'xapi-monitor' ); ?></th>
                    <td><?php echo esc_html( $hc['code'] ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( ! $hc['success'] && isset( $hc['error'] ) ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Error', 'xapi-monitor' ); ?></th>
                    <td style="color:#c0392b"><?php echo esc_html( $hc['error'] ); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ( ! empty( $hc['attempts'] ) ) : ?>
                <tr>
                    <th style="vertical-align:top"><?php esc_html_e( 'Endpoints Tested', 'xapi-monitor' ); ?></th>
                    <td>
                        <table class="widefat" style="margin:0;border:1px solid #ddd">
                            <thead><tr><th>URL</th><th>HTTP Code</th><th>Response</th></tr></thead>
                            <tbody>
                            <?php foreach ( $hc['attempts'] as $att ) : ?>
                                <tr>
                                    <td><code style="font-size:11px;word-break:break-all"><?php echo esc_html( $att['endpoint'] ?? '—' ); ?></code></td>
                                    <td><?php echo esc_html( $att['code'] ?? '—' ); ?></td>
                                    <td style="max-width:300px;word-break:break-word;font-size:11px"><?php echo esc_html( substr( $att['error'] ?? $att['body'] ?? '—', 0, 200 ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
        <?php else : ?>
            <p style="color:#888"><?php esc_html_e( 'No endpoint health check has been run yet. Click "Test Endpoint Now" above.', 'xapi-monitor' ); ?></p>
        <?php endif; ?>

        <?php // LearnDash REST API Status ?>
        <?php $ld_api_check = get_option( 'xapi_monitor_last_learndash_api_check', [] ); ?>
        <?php if ( ! empty( $ld_api_check['result'] ) ) :
            $ld = $ld_api_check['result'];
            $ld_ok = ( $ld['status'] ?? '' ) === 'ok';
            $ld_class = $ld_ok ? 'xapi-health-ok' : 'xapi-health-fail';
            ?>
            <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'LearnDash REST API', 'xapi-monitor' ); ?></div>
            <table class="wp-list-table widefat" style="max-width:600px">
                <tr>
                    <th style="width:200px"><?php esc_html_e( 'Last Check Time', 'xapi-monitor' ); ?></th>
                    <td><?php echo esc_html( $ld_api_check['timestamp'] ?? '—' ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Status', 'xapi-monitor' ); ?></th>
                    <td><span class="xapi-health-check <?php echo esc_attr( $ld_class ); ?>"><?php echo $ld_ok ? '✅ ' . esc_html__( 'PASSING', 'xapi-monitor' ) : '❌ ' . esc_html__( 'FAILING', 'xapi-monitor' ); ?></span></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Message', 'xapi-monitor' ); ?></th>
                    <td style="<?php echo $ld_ok ? '' : 'color:#c0392b'; ?>"><?php echo esc_html( $ld['message'] ?? '—' ); ?></td>
                </tr>
                <?php if ( isset( $ld['code'] ) && $ld['code'] > 0 ) : ?>
                <tr>
                    <th><?php esc_html_e( 'HTTP Response', 'xapi-monitor' ); ?></th>
                    <td><?php echo esc_html( $ld['code'] ); ?></td>
                </tr>
                <?php endif; ?>
            </table>
        <?php elseif ( defined( 'LEARNDASH_VERSION' ) || function_exists( 'learndash_is_lesson_complete' ) ) : ?>
            <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'LearnDash REST API', 'xapi-monitor' ); ?></div>
            <p style="color:#888"><?php esc_html_e( 'No LearnDash REST API check has been run yet. It will run automatically on the next endpoint health cron cycle.', 'xapi-monitor' ); ?></p>
        <?php endif; ?>

        <?php // Quick Diagnostic Checks ?>
        <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'Quick Diagnostic Checks', 'xapi-monitor' ); ?></div>
        <table class="wp-list-table widefat" style="max-width:700px">
            <?php foreach ( $health['checks'] as $check ) :
                $icon = $check['pass'] ? '✅' : ( $check['warn'] ?? false ? '⚠️' : '❌' );
                $color = $check['pass'] ? '' : ( $check['warn'] ?? false ? 'color:#b7950b' : 'color:#c0392b;font-weight:600' );
                ?>
                <tr>
                    <td style="width:220px"><?php echo $icon; ?> <?php echo esc_html( $check['name'] ); ?></td>
                    <td style="<?php echo esc_attr( $color ); ?>"><?php echo esc_html( $check['message'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php // Statement Rate ?>
        <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'Statement Capture Rate (Last 24 Hours)', 'xapi-monitor' ); ?></div>
        <table class="wp-list-table widefat" style="max-width:700px">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Hour', 'xapi-monitor' ); ?></th>
                    <th><?php esc_html_e( 'Statements', 'xapi-monitor' ); ?></th>
                    <th><?php esc_html_e( 'Successful', 'xapi-monitor' ); ?></th>
                    <th><?php esc_html_e( 'Failed', 'xapi-monitor' ); ?></th>
                    <th><?php esc_html_e( 'Failure Rate', 'xapi-monitor' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $hourly = $this->db->get_results(
                "SELECT
                    DATE_FORMAT(timestamp, '%Y-%m-%d %H:00') as hour_label,
                    COUNT(*) as total,
                    SUM(CASE WHEN delivery_status IN ('captured','processed') THEN 1 ELSE 0 END) as success,
                    SUM(CASE WHEN delivery_status IN ('failed','timeout') THEN 1 ELSE 0 END) as failed
                 FROM {$this->log_table}
                 WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   AND source NOT LIKE 'js_error%'
                 GROUP BY hour_label
                 ORDER BY hour_label DESC"
            );
            if ( empty( $hourly ) ) : ?>
                <tr><td colspan="5" style="color:#888;text-align:center"><?php esc_html_e( 'No data yet', 'xapi-monitor' ); ?></td></tr>
            <?php else :
                foreach ( $hourly as $h ) :
                    $rate      = $h->total > 0 ? round( ( $h->failed / $h->total ) * 100, 1 ) : 0;
                    $rate_color = $rate > 10 ? 'color:#c0392b;font-weight:600' : ( $rate > 5 ? 'color:#b7950b' : '' );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $h->hour_label ); ?></td>
                        <td><?php echo (int) $h->total; ?></td>
                        <td style="color:#1d8348"><?php echo (int) $h->success; ?></td>
                        <td style="color:#c0392b"><?php echo (int) $h->failed; ?></td>
                        <td style="<?php echo esc_attr( $rate_color ); ?>"><?php echo $rate; ?>%</td>
                    </tr>
                <?php endforeach;
            endif; ?>
            </tbody>
        </table>

        <?php // Environment Info ?>
        <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'Environment', 'xapi-monitor' ); ?></div>
        <table class="wp-list-table widefat" style="max-width:600px">
            <?php foreach ( $health['environment'] as $label => $value ) : ?>
                <tr>
                    <th style="width:250px"><?php echo esc_html( $label ); ?></th>
                    <td><code><?php echo esc_html( $value ); ?></code></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <?php // DB Table Sizes ?>
        <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'Database Tables', 'xapi-monitor' ); ?></div>
        <table class="wp-list-table widefat" style="max-width:600px">
            <thead><tr><th><?php esc_html_e( 'Table', 'xapi-monitor' ); ?></th><th><?php esc_html_e( 'Rows', 'xapi-monitor' ); ?></th><th><?php esc_html_e( 'Size', 'xapi-monitor' ); ?></th></tr></thead>
            <tbody>
            <?php foreach ( $health['db_tables'] as $table => $info ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $table ); ?></code></td>
                    <td><?php echo number_format( (int) $info['rows'] ); ?></td>
                    <td><?php echo esc_html( $info['size'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php // Tin Canny Settings ?>
        <?php if ( ! empty( $health['tincanny'] ) ) : ?>
            <div class="xapi-section-header" style="margin-top:24px"><?php esc_html_e( 'Tin Canny Settings', 'xapi-monitor' ); ?></div>
            <table class="wp-list-table widefat" style="max-width:600px">
                <?php foreach ( $health['tincanny'] as $key => $val ) : ?>
                    <tr>
                        <th style="width:250px"><?php echo esc_html( $key ); ?></th>
                        <td><?php echo esc_html( is_bool( $val ) ? ( $val ? 'Yes' : 'No' ) : (string) $val ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Compile system health data.
     */
    private function get_system_health_data(): array {
        $checks = [];

        // Check 1: Tin Canny active?
        $tc_active = is_plugin_active( 'tin-canny-learndash-reporting/uncanny-learndash-reporting.php' )
                  || is_plugin_active( 'tin-canny-learndash-reporting-pro/uncanny-learndash-reporting-pro.php' )
                  || defined( 'UO_REPORTING_VERSION' )
                  || class_exists( '\\uncanny_learndash_reporting\\Config' );
        $checks[] = [
            'name'    => 'Tin Canny Active',
            'pass'    => $tc_active,
            'message' => $tc_active ? 'Detected' : 'Tin Canny plugin not detected',
        ];

        // Check 2: LearnDash active?
        $ld_active = defined( 'LEARNDASH_VERSION' ) || function_exists( 'learndash_is_lesson_complete' );
        $checks[] = [
            'name'    => 'LearnDash Active',
            'pass'    => $ld_active,
            'message' => $ld_active ? ( defined( 'LEARNDASH_VERSION' ) ? 'Version ' . LEARNDASH_VERSION : 'Detected' ) : 'LearnDash not detected',
        ];

        // Check 3: Permalink structure
        $permalink = get_option( 'permalink_structure' );
        $has_pretty = ! empty( $permalink );
        $checks[] = [
            'name'    => 'Pretty Permalinks',
            'pass'    => $has_pretty,
            'message' => $has_pretty ? $permalink : 'Plain permalinks enabled — may affect REST API/ucTinCan endpoint',
        ];

        // Check 4: REST API reachable
        $rest_url = rest_url( 'wp/v2/types' );
        $checks[] = [
            'name'    => 'REST API Reachable',
            'pass'    => true, // If we're running, REST is likely OK
            'message' => 'REST URL: ' . $rest_url,
        ];

        // Check 5: Object caching
        $object_cache = wp_using_ext_object_cache();
        $checks[] = [
            'name'    => 'Object Cache',
            'pass'    => true,
            'warn'    => $object_cache,
            'message' => $object_cache ? 'External object cache detected — may cause stale data. Filter uo_tincanny_reporting_disable_cache can help.' : 'No external object cache',
        ];

        // Check 6: Memory limit
        $mem_limit = ini_get( 'memory_limit' );
        $mem_bytes = wp_convert_hr_to_bytes( $mem_limit );
        $mem_ok    = $mem_bytes >= 128 * MB_IN_BYTES;
        $checks[] = [
            'name'    => 'PHP Memory Limit',
            'pass'    => $mem_ok,
            'warn'    => ! $mem_ok,
            'message' => $mem_limit . ( $mem_ok ? '' : ' — recommend 256M+' ),
        ];

        // Check 7: Monitor log table exists
        $log_exists = (bool) $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->log_table ) );
        $checks[] = [
            'name'    => 'Monitor Log Table',
            'pass'    => $log_exists,
            'message' => $log_exists ? $this->log_table . ' exists' : 'Table missing — try deactivating and reactivating the plugin',
        ];

        // Check 8: Cron scheduled
        $cron_scheduled = (bool) wp_next_scheduled( 'xapi_monitor_diagnostic_cron' );
        $checks[] = [
            'name'    => 'Cron Job Scheduled',
            'pass'    => $cron_scheduled,
            'message' => $cron_scheduled ? 'Next run: ' . date( 'Y-m-d H:i', wp_next_scheduled( 'xapi_monitor_diagnostic_cron' ) ) : 'Diagnostic cron not scheduled — deactivate/reactivate plugin',
        ];

        // Environment
        $env = [
            'WordPress Version'     => get_bloginfo( 'version' ),
            'PHP Version'           => PHP_VERSION,
            'xAPI Monitor Version'  => XAPI_MONITOR_VERSION,
            'LearnDash Version'     => defined( 'LEARNDASH_VERSION' ) ? LEARNDASH_VERSION : 'Not detected',
            'Tin Canny Version'     => $this->get_tincanny_version(),
            'WordPress Memory Limit'=> WP_MEMORY_LIMIT,
            'PHP Memory Limit'      => ini_get( 'memory_limit' ),
            'Max Execution Time'    => ini_get( 'max_execution_time' ) . 's',
            'Site URL'              => get_site_url(),
            'REST URL base'         => rest_url(),
            'Last Diagnostic Run'   => get_option( 'xapi_monitor_last_diagnostic', 'Never' ),
        ];

        // DB table sizes
        $tables_info = [];
        foreach ( [ $this->log_table, $this->alerts_table ] as $tbl ) {
            $size_row = $this->db->get_row( $this->db->prepare(
                "SELECT TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 2) AS size_kb
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $tbl
            ) );
            $tables_info[ $tbl ] = [
                'rows' => $size_row ? $size_row->TABLE_ROWS : 0,
                'size' => $size_row ? $size_row->size_kb . ' KB' : '—',
            ];
        }

        // Tin Canny settings
        $tc_settings = [];
        $tc_options  = [
            'uo_snc_enable_mark_complete'      => 'Mark Complete Trigger',
            'uo_snc_tin_can_content_type'      => 'Content Type Setting',
            'uo_reporting_completion_verb'     => 'Completion Verb(s)',
            'uo_snc_disable_object_cache'      => 'Object Cache Disabled',
        ];
        foreach ( $tc_options as $opt_key => $opt_label ) {
            $val = get_option( $opt_key );
            if ( false !== $val ) {
                $tc_settings[ $opt_label ] = is_array( $val ) ? implode( ', ', $val ) : (string) $val;
            }
        }
        // Attempt to read from Tin Canny's config class
        if ( class_exists( '\\uncanny_learndash_reporting\\Config' ) ) {
            $tc_settings['Tin Canny Config Class'] = 'Available';
        }

        return [
            'checks'      => $checks,
            'environment' => $env,
            'db_tables'   => $tables_info,
            'tincanny'    => $tc_settings,
        ];
    }

    // ============================================================
    // MEMORY WARNING HELPER (v1.2.0)
    // ============================================================

    /**
     * Render a yellow admin notice if WP memory limit is below 64M.
     */
    private function maybe_render_memory_warning(): void {
        $wp_mem_bytes = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
        if ( $wp_mem_bytes < 64 * MB_IN_BYTES ) {
            $mem_display = WP_MEMORY_LIMIT;
            ?>
            <div class="notice notice-warning inline" style="margin:12px 0;padding:10px 14px;">
                <p><strong><?php esc_html_e( 'Memory Limit Notice', 'xapi-monitor' ); ?></strong><br>
                <?php
                printf(
                    /* translators: %s: current WP memory limit value */
                    esc_html__(
                        'WordPress memory limit is set to %sM. The xAPI Monitor recommends at least 64M for reliable operation. Contact your host to increase wp-memory-limit in wp-config.php.',
                        'xapi-monitor'
                    ),
                    esc_html( rtrim( $mem_display, 'Mm' ) )
                );
                ?></p>
            </div>
            <?php
        }
    }

    // ============================================================
    // TAB 5: SETTINGS
    // ============================================================
    public function register_settings(): void {
        register_setting( 'xapi_monitor_settings_group', 'xapi_monitor_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( $input ): array {
        $clean    = self::default_settings();
        $defaults = self::default_settings();

        foreach ( $defaults as $key => $default ) {
            if ( is_bool( $default ) ) {
                $clean[ $key ] = ! empty( $input[ $key ] );
            } elseif ( is_int( $default ) ) {
                $clean[ $key ] = isset( $input[ $key ] ) ? (int) $input[ $key ] : $default;
            } else {
                $clean[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : $default;
            }
        }

        return $clean;
    }

    private function render_tab_settings( string $nonce ): void {
        $settings = get_option( 'xapi_monitor_settings', self::default_settings() );
        ?>
        <h2><?php esc_html_e( 'Settings', 'xapi-monitor' ); ?></h2>
        <?php $this->maybe_render_memory_warning(); ?>

        <form method="post" action="options.php">
            <?php settings_fields( 'xapi_monitor_settings_group' ); ?>
            <input type="hidden" name="option_page" value="xapi_monitor_settings_group">
            <input type="hidden" name="action" value="update">
            <?php wp_nonce_field( 'xapi_monitor_settings_group-options' ); ?>

            <h3><?php esc_html_e( 'Email Alerts', 'xapi-monitor' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Alert Recipients', 'xapi-monitor' ); ?></th>
                    <td>
                        <input type="text" name="xapi_monitor_settings[alert_emails]" value="<?php echo esc_attr( $settings['alert_emails'] ); ?>" class="large-text" placeholder="admin@example.com, teacher@example.com">
                        <p class="description"><?php esc_html_e( 'Comma-separated email addresses to receive critical alerts.', 'xapi-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Email Notifications', 'xapi-monitor' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="xapi_monitor_settings[email_notifications]" value="1" <?php checked( $settings['email_notifications'] ); ?>>
                            <?php esc_html_e( 'Send email alerts for critical issues', 'xapi-monitor' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Alert Thresholds', 'xapi-monitor' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Statement Gap Timeout (minutes)', 'xapi-monitor' ); ?></th>
                    <td>
                        <input type="number" name="xapi_monitor_settings[gap_timeout_minutes]" value="<?php echo (int) $settings['gap_timeout_minutes']; ?>" min="5" max="240" class="small-text"> <?php esc_html_e( 'minutes', 'xapi-monitor' ); ?>
                        <p class="description"><?php esc_html_e( 'How long after the last activity verb with no completion before generating a "statement gap" alert.', 'xapi-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Failure Rate Threshold', 'xapi-monitor' ); ?></th>
                    <td>
                        <input type="number" name="xapi_monitor_settings[failure_rate_threshold]" value="<?php echo (float) $settings['failure_rate_threshold']; ?>" min="1" max="100" step="0.5" class="small-text"> %
                        <p class="description"><?php esc_html_e( 'Generate a critical alert if the xAPI statement failure rate in any hour exceeds this percentage.', 'xapi-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Endpoint Health Check Interval (minutes)', 'xapi-monitor' ); ?></th>
                    <td>
                        <input type="number" name="xapi_monitor_settings[health_check_interval]" value="<?php echo (int) $settings['health_check_interval']; ?>" min="5" max="60" class="small-text"> <?php esc_html_e( 'minutes', 'xapi-monitor' ); ?>
                        <p class="description"><?php esc_html_e( 'How often to send a test xAPI statement to verify the Tin Canny endpoint is reachable.', 'xapi-monitor' ); ?></p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Data & Logging', 'xapi-monitor' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Log Retention', 'xapi-monitor' ); ?></th>
                    <td>
                        <input type="number" name="xapi_monitor_settings[log_retention_days]" value="<?php echo (int) $settings['log_retention_days']; ?>" min="1" max="365" class="small-text"> <?php esc_html_e( 'days', 'xapi-monitor' ); ?>
                        <p class="description"><?php esc_html_e( 'Automatically delete log entries older than this many days.', 'xapi-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'JavaScript Beacon', 'xapi-monitor' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="xapi_monitor_settings[js_beacon_enabled]" value="1" <?php checked( $settings['js_beacon_enabled'] ); ?>>
                            <?php esc_html_e( 'Enable client-side JS beacon on LearnDash lesson/topic pages', 'xapi-monitor' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Intercepts XHR/Fetch calls from the page (and the Rise iframe if same-origin) to capture client-side xAPI delivery data.', 'xapi-monitor' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Diagnostic Schedule', 'xapi-monitor' ); ?></th>
                    <td>
                        <select name="xapi_monitor_settings[diagnostic_schedule]">
                            <option value="15min" <?php selected( $settings['diagnostic_schedule'], '15min' ); ?>><?php esc_html_e( 'Every 15 minutes', 'xapi-monitor' ); ?></option>
                            <option value="hourly" <?php selected( $settings['diagnostic_schedule'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'xapi-monitor' ); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'xapi-monitor' ) ); ?>
        </form>

        <hr>
        <h3><?php esc_html_e( 'Manual Operations', 'xapi-monitor' ); ?></h3>
        <div class="xapi-actions-bar">
            <button class="button button-primary" id="xapi-run-diagnostic"><?php esc_html_e( 'Run Diagnostic Comparison Now', 'xapi-monitor' ); ?></button>
            <button class="button button-primary" id="xapi-test-endpoint"><?php esc_html_e( 'Send Test xAPI Statement', 'xapi-monitor' ); ?></button>
            <button class="button" id="xapi-export-csv"><?php esc_html_e( 'Export All Logs to CSV', 'xapi-monitor' ); ?></button>
        </div>

        <h3 style="margin-top:20px"><?php esc_html_e( 'Purge Old Logs', 'xapi-monitor' ); ?></h3>
        <div style="display:flex;gap:12px;align-items:center;background:#fff3cd;padding:12px;border:1px solid #ffc107;border-radius:4px;max-width:600px">
            <label><?php esc_html_e( 'Delete entries older than', 'xapi-monitor' ); ?>
                <input type="number" id="xapi-purge-days" value="<?php echo (int) $settings['log_retention_days']; ?>" min="1" max="365" style="width:70px"> <?php esc_html_e( 'days', 'xapi-monitor' ); ?>
            </label>
            <label>
                <input type="checkbox" id="xapi-archive-before-purge">
                <?php esc_html_e( 'Archive to CSV first', 'xapi-monitor' ); ?>
            </label>
            <button class="button button-secondary" id="xapi-purge-logs"><?php esc_html_e( 'Purge Logs', 'xapi-monitor' ); ?></button>
        </div>

        <hr style="margin:30px 0">
        <h3 style="color:#a00"><?php esc_html_e( 'Danger Zone', 'xapi-monitor' ); ?></h3>

        <div style="background:#fdf2f2;padding:16px;border:1px solid #c0392b;border-radius:4px;max-width:600px">
            <p style="margin:0 0 8px">
                <strong><?php esc_html_e( 'Data Preservation Notice', 'xapi-monitor' ); ?></strong><br>
                <?php esc_html_e( 'Deactivating or updating this plugin never erases your monitoring data. Your xAPI statement logs and alerts are preserved across all plugin updates.', 'xapi-monitor' ); ?>
            </p>
            <p style="margin:0 0 12px;color:#555;font-size:13px">
                <?php esc_html_e( 'Use the button below only if you want to permanently erase all captured statements, alerts, and settings. This cannot be undone.', 'xapi-monitor' ); ?>
            </p>
            <button class="button" id="xapi-delete-all-data" style="background:#c0392b;color:#fff;border-color:#a93226">
                <?php esc_html_e( 'Delete All Data', 'xapi-monitor' ); ?>
            </button>
            <span id="xapi-delete-confirm" style="display:none;margin-left:12px">
                <strong style="color:#a00"><?php esc_html_e( 'Are you sure? This will permanently erase all logs, alerts, and settings.', 'xapi-monitor' ); ?></strong>
                <button class="button" id="xapi-delete-confirm-yes" style="margin-left:8px;background:#a00;color:#fff;border-color:#800"><?php esc_html_e( 'Yes, delete everything', 'xapi-monitor' ); ?></button>
                <button class="button" id="xapi-delete-confirm-no" style="margin-left:4px"><?php esc_html_e( 'Cancel', 'xapi-monitor' ); ?></button>
            </span>
            <p id="xapi-delete-result" style="margin:8px 0 0;font-weight:600"></p>
        </div>
        <script>
        (function(){
            document.getElementById('xapi-delete-all-data').addEventListener('click', function(){
                document.getElementById('xapi-delete-confirm').style.display = 'inline';
            });
            document.getElementById('xapi-delete-confirm-no').addEventListener('click', function(){
                document.getElementById('xapi-delete-confirm').style.display = 'none';
            });
            document.getElementById('xapi-delete-confirm-yes').addEventListener('click', function(){
                var btn = this;
                btn.disabled = true;
                btn.textContent = '<?php echo esc_js( __( 'Deleting…', 'xapi-monitor' ) ); ?>';
                var nonce = '<?php echo esc_js( wp_create_nonce( 'xapi_monitor_delete_all_data' ) ); ?>';
                fetch(ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=xapi_monitor_delete_all_data&nonce=' + encodeURIComponent(nonce)
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    document.getElementById('xapi-delete-confirm').style.display = 'none';
                    var el = document.getElementById('xapi-delete-result');
                    if (data.success) {
                        el.style.color = 'green';
                        el.textContent = data.data.message;
                    } else {
                        el.style.color = '#a00';
                        el.textContent = (data.data && data.data.message) ? data.data.message : '<?php echo esc_js( __( 'Error. Please try again.', 'xapi-monitor' ) ); ?>';
                        btn.disabled = false;
                        btn.textContent = '<?php echo esc_js( __( 'Yes, delete everything', 'xapi-monitor' ) ); ?>';
                    }
                });
            });
        })();
        </script>
        <?php
    }

    // ============================================================
    // TAB 6: HOW xAPI WORKS (v1.2.0)
    // ============================================================

    /**
     * Render the "How xAPI Works" educational reference tab.
     * Includes scholarly context with APA 7 citations.
     */
    private function render_tab_xapi_docs( string $nonce ): void {
        ?>
        <div style="max-width:900px">

        <h2><?php esc_html_e( 'How xAPI Works', 'xapi-monitor' ); ?></h2>
        <p class="description" style="font-size:14px;margin-bottom:20px">
            <?php esc_html_e( 'An in-plugin reference guide covering the full xAPI pipeline on this site, ADL verb semantics, common failure patterns, and the scholarly context behind why this tooling is necessary.', 'xapi-monitor' ); ?>
        </p>

        <?php /* ===== SECTION 0: SCHOLARLY CONTEXT ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h2><?php esc_html_e( 'Section 0: xAPI and Learning Analytics — Scholarly Context', 'xapi-monitor' ); ?></h2>

            <p><?php esc_html_e( 'Learning analytics is defined as "the measurement, collection, analysis and reporting of data about learners and their contexts, for purposes of understanding and optimising learning and the environments in which it occurs" (Siemens &amp; Long, 2011, as cited in Friesen, 2013). xAPI (Experience API) sits at the data-collection layer of that definition — it is the specification that makes learning events machine-readable and portable.', 'xapi-monitor' ); ?></p>

            <p><?php esc_html_e( 'xAPI was developed by Advanced Distributed Learning (ADL) as the successor to SCORM. Where SCORM was limited to tracking learning inside a single LMS, xAPI was designed to capture learning activity from any context — mobile apps, simulations, informal practice, physical environments — and route those records to a Learning Record Store (LRS). On this site, Tin Canny acts as the LRS, receiving xAPI statements from Articulate Rise content and translating them into LearnDash completion events.', 'xapi-monitor' ); ?></p>

            <div class="xapi-scholarly-note">
                <p style="margin:0"><strong><?php esc_html_e( 'Why formal tooling is necessary:', 'xapi-monitor' ); ?></strong>
                <?php esc_html_e( 'Vidal et al. (2018) found that "the xAPI specification is informal, with some loose definitions, that may lead to unexpected mistakes." Their ontological analysis demonstrated that different authoring tools and LRS platforms can interpret the same verb differently — and those differences produce silent failures that are invisible without a monitoring layer. This plugin provides that layer.', 'xapi-monitor' ); ?></p>
            </div>

            <p><?php esc_html_e( 'At the practitioner level, Samuelsen et al. (2021) found in a real-world case study that "standards are seldom used for integration of multiple sources in LA" and identified "challenges and limitations in describing learning context data." The gap between what xAPI promises and what production deployments actually achieve is well-documented. This plugin directly addresses that gap by making every step in the pipeline inspectable.', 'xapi-monitor' ); ?></p>

            <p><?php esc_html_e( 'More recently, Rocha et al. (2024) documented "bottlenecks" in real-world xAPI/LRS implementations even when specifications are correctly followed — finding that routing statements through intermediary systems (such as Tin Canny sitting between Rise and LearnDash) introduces latency, deduplication decisions, and schema assumptions that can silently drop or misinterpret records.', 'xapi-monitor' ); ?></p>
        </div>

        <?php /* ===== SECTION 1: THE PIPELINE ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h2><?php esc_html_e( 'Section 1: The xAPI Pipeline on This Site', 'xapi-monitor' ); ?></h2>

            <p><?php esc_html_e( 'Every completion event on this site travels through a fixed sequence of steps. Understanding each step is essential for diagnosing failures.', 'xapi-monitor' ); ?></p>

            <ul class="xapi-pipeline-steps">
                <li>
                    <span class="step-num">1</span>
                    <div>
                        <strong><?php esc_html_e( 'Articulate Rise content renders in an iframe', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'The Rise course runs inside a browser iframe embedded in the LearnDash lesson page. When a learner reaches a completion trigger (finishing all slides, passing a knowledge check), Rise generates an xAPI statement in memory.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">2</span>
                    <div>
                        <strong><?php esc_html_e( 'xAPI statement POSTed to the ucTinCan virtual endpoint', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'Rise\'s xAPI runtime sends the statement as a JSON POST to the ucTinCan endpoint registered by Tin Canny via WordPress rewrite rules (or the query-string fallback /?ucTinCan=1). This is a browser-to-server HTTP request, not a server-to-server call.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">3</span>
                    <div>
                        <strong><?php esc_html_e( 'Tin Canny processes the statement', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'Tin Canny validates the statement, deduplicates it (same statement_id received twice is stored once), and writes a row to wpv5_uotincan_reporting. The relevant columns for completion are: verb (passed/completed), completion (1/0), and passed (1/0).', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">4</span>
                    <div>
                        <strong><?php esc_html_e( 'Tin Canny triggers LearnDash completion', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'If the statement\'s verb matches Tin Canny\'s configured completion verb(s) (default: "completed" or "passed"), Tin Canny calls learndash_process_mark_complete() on the corresponding lesson_id and course_id. This is the only bridge between the LRS and the LMS.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">5</span>
                    <div>
                        <strong><?php esc_html_e( 'LearnDash updates user activity tables', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'LearnDash marks the lesson complete in its own activity tables and recalculates course progress. If the lesson was the last incomplete step, the course is marked complete. If any step remains — including a quiz step that xAPI cannot trigger — the course stays at N-1/N.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
            </ul>
        </div>

        <?php /* ===== SECTION 2: VERB TABLE ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h2><?php esc_html_e( 'Section 2: xAPI Verbs and What They Mean', 'xapi-monitor' ); ?></h2>

            <p><?php esc_html_e( 'xAPI verbs are identified by URIs (Internationalized Resource Identifiers), not plain words. The ADL vocabulary registry defines the canonical IRIs and their natural-language meanings. Not all verbs carry the same weight in a completion workflow.', 'xapi-monitor' ); ?></p>

            <table class="xapi-verb-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Verb', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'IRI', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'Semantic Meaning', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'Used by Tin Canny for completion?', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'Triggers LearnDash?', 'xapi-monitor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>attempted</strong></td>
                        <td><code style="font-size:11px">http://adlnet.gov/expapi/verbs/attempted</code></td>
                        <td><?php esc_html_e( 'The learner started the activity. Does not imply any result.', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>experienced</strong></td>
                        <td><code style="font-size:11px">http://adlnet.gov/expapi/verbs/experienced</code></td>
                        <td><?php esc_html_e( 'The learner was exposed to the activity. Weakest intent — no completion implied.', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>answered</strong></td>
                        <td><code style="font-size:11px">http://adlnet.gov/expapi/verbs/answered</code></td>
                        <td><?php esc_html_e( 'The learner responded to a question. Used for knowledge check interactions.', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>passed</strong></td>
                        <td><code style="font-size:11px">http://adlnet.gov/expapi/verbs/passed</code></td>
                        <td><?php esc_html_e( 'The learner met the passing criterion. Implies a result/score threshold was met.', 'xapi-monitor' ); ?></td>
                        <td style="color:#1d8348;font-weight:600"><?php esc_html_e( 'Yes (default)', 'xapi-monitor' ); ?></td>
                        <td style="color:#1d8348;font-weight:600"><?php esc_html_e( 'Yes', 'xapi-monitor' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>failed</strong></td>
                        <td><code style="font-size:11px">http://adlnet.gov/expapi/verbs/failed</code></td>
                        <td><?php esc_html_e( 'The learner did not meet the passing criterion. LearnDash will not mark complete.', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                        <td style="color:#c0392b"><?php esc_html_e( 'No', 'xapi-monitor' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong>completed</strong></td>
                        <td><code style="font-size:11px">http://adlnet.gov/expapi/verbs/completed</code></td>
                        <td><?php esc_html_e( 'The learner finished all required parts of the activity. Primary completion signal for most Rise content.', 'xapi-monitor' ); ?></td>
                        <td style="color:#1d8348;font-weight:600"><?php esc_html_e( 'Yes (default)', 'xapi-monitor' ); ?></td>
                        <td style="color:#1d8348;font-weight:600"><?php esc_html_e( 'Yes', 'xapi-monitor' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <div class="xapi-scholarly-note">
                <p style="margin:0"><strong><?php esc_html_e( 'Note on verb informality:', 'xapi-monitor' ); ?></strong>
                <?php esc_html_e( 'The xAPI specification defines verbs as URIs with informal natural-language semantics. Vidal et al. (2018) demonstrated through ontological analysis that this informality produces ambiguity in real implementations — different authoring tools and LRS platforms may assign different completion logic to the same verb. Tin Canny\'s interpretation of "passed" vs. "completed" (accepting either for completion by default) reflects one vendor\'s reading of an underspecified standard. This is a documented source of interoperability failures.', 'xapi-monitor' ); ?></p>
            </div>
        </div>

        <?php /* ===== SECTION 3: WHY COMPLETIONS FAIL ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h2><?php esc_html_e( 'Section 3: Why Completions Fail — Common Patterns', 'xapi-monitor' ); ?></h2>

            <div class="xapi-scholarly-note">
                <p style="margin:0"><?php esc_html_e( 'These failure patterns are not merely implementation bugs — they reflect structural limitations identified in the learning analytics literature. Nouira et al. (2018) argue that xAPI\'s data model has inherent "weaknesses from an assessment point of view," noting the standard was not designed with quiz-step semantics in mind. Ahmad et al. (2022) found in a systematic review that learning analytics implementations frequently suffer from misalignment between data collection systems and learning design — precisely the condition that produces completion mismatches between an LRS (Tin Canny) and an LMS (LearnDash).', 'xapi-monitor' ); ?></p>
            </div>

            <ol style="margin:12px 0 0 20px;line-height:2">
                <li>
                    <strong><?php esc_html_e( 'Quiz Step Gap', 'xapi-monitor' ); ?></strong> —
                    <?php esc_html_e( 'A course has a quiz step that xAPI cannot trigger. LearnDash requires the quiz to be submitted in-browser. xAPI cannot send a "quiz passed" statement that LearnDash will honor for step completion. The course will remain stuck at N-1/N steps regardless of how many xAPI statements are received.', 'xapi-monitor' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Verb Mismatch', 'xapi-monitor' ); ?></strong> —
                    <?php esc_html_e( 'Content sends "experienced" but Tin Canny is configured to require "completed" or "passed." Check Tin Canny → Modules settings for the configured completion verb. This is a direct instance of the informality problem Vidal et al. (2018) identified.', 'xapi-monitor' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Duplicate Statements', 'xapi-monitor' ); ?></strong> —
                    <?php esc_html_e( 'The same statement_id is received multiple times (e.g., because the learner refreshed the page or the Rise runtime retried on error). Tin Canny deduplicates by statement_id — only the first is stored. If the duplicate carried a different verb, the stored record may not be the completion statement.', 'xapi-monitor' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Iframe Delivery Failure', 'xapi-monitor' ); ?></strong> —
                    <?php esc_html_e( 'Rise content is in an iframe that cannot reach the ucTinCan endpoint due to CORS restrictions, LiteSpeed cache interference, or cookie-based authentication requirements. The statement never arrives at the server. The JavaScript beacon in this plugin detects these cases by monitoring XHR/Fetch calls from within the iframe.', 'xapi-monitor' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Score Threshold Not Met', 'xapi-monitor' ); ?></strong> —
                    <?php esc_html_e( 'Tin Canny is configured with a minimum score threshold (result > X). The learner\'s score was below the threshold, so even though a "passed" verb arrived, Tin Canny rejected it as not meeting the completion criterion.', 'xapi-monitor' ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'LearnDash Step Count Mismatch', 'xapi-monitor' ); ?></strong> —
                    <?php esc_html_e( 'A course reports N steps in LearnDash but only N−1 can be completed via xAPI. The Nth step is a quiz (see Quiz Step Gap above). The course progress display correctly shows N−1/N because the quiz step is genuinely incomplete from LearnDash\'s perspective.', 'xapi-monitor' ); ?>
                </li>
            </ol>
        </div>

        <?php /* ===== SECTION 4: HOW THIS PLUGIN HELPS ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h2><?php esc_html_e( 'Section 4: How This Plugin Helps', 'xapi-monitor' ); ?></h2>

            <p><?php esc_html_e( 'Each tab in this dashboard addresses a specific layer of the pipeline. Every diagnostic finding links to raw DB rows, so every conclusion is verifiable — not inferred.', 'xapi-monitor' ); ?></p>

            <table class="wp-list-table widefat" style="max-width:800px">
                <thead>
                    <tr>
                        <th style="width:160px"><?php esc_html_e( 'Tab', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'What It Shows', 'xapi-monitor' ); ?></th>
                        <th><?php esc_html_e( 'Source Tables', 'xapi-monitor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e( 'Live Statement Feed', 'xapi-monitor' ); ?></strong></td>
                        <td><?php esc_html_e( 'Every xAPI statement captured in the last 24 hours (default), with verb, delivery status, HTTP response code, and raw JSON. Filterable by user, verb, status, and date range.', 'xapi-monitor' ); ?></td>
                        <td><code><?php echo esc_html( $this->log_table ); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Alerts & Diagnostics', 'xapi-monitor' ); ?></strong></td>
                        <td><?php esc_html_e( 'Automatically generated alerts for completion mismatches (Tin Canny has data but LearnDash doesn\'t), statement gaps, endpoint failures, and high failure rates. Each mismatch alert now includes an evidence payload with the raw Tin Canny rows, LearnDash activity record, and log IDs that prove the finding.', 'xapi-monitor' ); ?></td>
                        <td><code><?php echo esc_html( $this->alerts_table ); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'User Diagnostic', 'xapi-monitor' ); ?></strong></td>
                        <td><?php esc_html_e( 'Per-user side-by-side comparison: for each lesson in each course, shows which xAPI verbs were received, whether Tin Canny recorded a completion, and whether LearnDash shows the lesson as complete. Highlights mismatches. Now also detects quiz steps that xAPI cannot trigger.', 'xapi-monitor' ); ?></td>
                        <td><code><?php echo esc_html( $this->log_table ); ?></code>, <code><?php echo esc_html( $this->db->prefix . 'uotincan_reporting' ); ?></code>, LearnDash activity</td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'System Health', 'xapi-monitor' ); ?></strong></td>
                        <td><?php esc_html_e( 'Endpoint connectivity, LearnDash REST API status, hourly statement capture rates and failure percentages, environment info (WP/PHP/plugin versions), DB table sizes, and Tin Canny settings.', 'xapi-monitor' ); ?></td>
                        <td><?php esc_html_e( 'Live WP environment + information_schema', 'xapi-monitor' ); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e( 'Settings', 'xapi-monitor' ); ?></strong></td>
                        <td><?php esc_html_e( 'Configure email alert recipients, statement gap timeout, failure rate threshold, JS beacon toggle, log retention, and diagnostic schedule.', 'xapi-monitor' ); ?></td>
                        <td><?php esc_html_e( 'wp_options (xapi_monitor_settings)', 'xapi-monitor' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top:12px"><?php esc_html_e( 'The REST API endpoints (/xapi-monitor/v1/logs, /logs/{id}, /alerts) expose all monitoring data programmatically, enabling integration with n8n workflows, external dashboards, or custom reporting tools.', 'xapi-monitor' ); ?></p>
        </div>

        <?php /* ===== SECTION 5: STATEMENT LIFECYCLE ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h2><?php esc_html_e( 'Section 5: xAPI Statement Lifecycle', 'xapi-monitor' ); ?></h2>

            <p><?php esc_html_e( 'An xAPI statement is a structured JSON document with a defined anatomy: Actor–Verb–Object, with optional Result and Context extensions. Here is how each field maps to what you see in the Live Statement Feed.', 'xapi-monitor' ); ?></p>

            <ul class="xapi-pipeline-steps">
                <li>
                    <span class="step-num">1</span>
                    <div>
                        <strong><?php esc_html_e( 'Authoring tool generates the statement', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'Articulate Rise constructs the statement using its xAPI runtime. The Actor is identified by the learner\'s email (mbox) pulled from the LMS session. The Verb is selected based on the completion trigger (all slides viewed → completed; knowledge check passed → passed). The Object is the activity IRI — typically the course URL or a unique activity ID configured in Rise.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">2</span>
                    <div>
                        <strong><?php esc_html_e( 'Actor identified by email', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'The actor.mbox field contains "mailto:user@example.com". Tin Canny maps this to a WordPress user_id by matching against wp_users.user_email. If the email does not match any user, the statement is stored but may not trigger completion.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">3</span>
                    <div>
                        <strong><?php esc_html_e( 'Result includes score/completion/success', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'The optional Result object carries result.completion (bool), result.success (bool), and result.score.scaled (0.0–1.0). These map to the result_completion, result_success, and result_score_scaled columns in the monitor log. A statement can have verb=completed but result.success=false — this is not a contradiction in xAPI terms but may confuse downstream systems.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">4</span>
                    <div>
                        <strong><?php esc_html_e( 'Statement POSTed to ucTinCan', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'The Rise runtime sends the statement as a JSON-encoded HTTP POST. On this site the endpoint is the Tin Canny virtual endpoint (ucTinCan). The statement includes an X-Experience-API-Version header (1.0.3). If this request fails (network error, server 500, CORS rejection), the learner\'s completion is never recorded.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">5</span>
                    <div>
                        <strong><?php esc_html_e( 'Tin Canny validates, deduplicates, stores', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'Tin Canny checks the statement_id. If it has been seen before, the statement is discarded (deduplication). Otherwise it is written to wpv5_uotincan_reporting with the lesson_id and course_id from its module configuration (not from the xAPI statement itself — Tin Canny resolves these from internal metadata).', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
                <li>
                    <span class="step-num">6</span>
                    <div>
                        <strong><?php esc_html_e( 'Hook fires → LearnDash notified', 'xapi-monitor' ); ?></strong><br>
                        <span style="color:#555;font-size:13px"><?php esc_html_e( 'After storage, Tin Canny evaluates whether the stored verb satisfies its completion rule. If yes, it calls learndash_process_mark_complete($user, $lesson_id). LearnDash then updates its user activity tables and recalculates course progress. This plugin observes both the incoming statement (tincanny_before_process_request) and the outgoing result (tincanny_module_result_processed) to confirm the full chain executed.', 'xapi-monitor' ); ?></span>
                    </div>
                </li>
            </ul>
        </div>

        <?php /* ===== SECTION 6: REFERENCES ===== */ ?>
        <div class="card xapi-docs-section" style="padding:20px;margin-bottom:20px">
            <h3><?php esc_html_e( 'References', 'xapi-monitor' ); ?></h3>

            <p class="xapi-reference">Ahmad, A., Griffiths, D., Schiffner, D., Biedermann, D., Drachsler, H., Schneider, J., &amp; Greller, W. (2022). Connecting the dots — A literature review on learning analytics indicators from a learning design perspective. <em>Journal of Computer Assisted Learning</em>, <em>38</em>(5), 1644–1670. <a href="https://doi.org/10.1111/jcal.12716" target="_blank" rel="noopener noreferrer">https://doi.org/10.1111/jcal.12716</a></p>

            <p class="xapi-reference">Advanced Distributed Learning Initiative. (2016). <em>Experience API (xAPI) specification, version 1.0.3</em>. <a href="https://github.com/adlnet/xAPI-Spec" target="_blank" rel="noopener noreferrer">https://github.com/adlnet/xAPI-Spec</a></p>

            <p class="xapi-reference">Friesen, N. (2013). Learning analytics: Readiness and rewards. <em>Canadian Journal of Learning and Technology</em>, <em>39</em>(4). <a href="https://doi.org/10.21432/T2J01B" target="_blank" rel="noopener noreferrer">https://doi.org/10.21432/T2J01B</a></p>

            <p class="xapi-reference">Khaddar, A. M., Said, Y., Dehbi, A., &amp; Chafiq, T. (2026). An interoperable multi-agent architecture for personalized smart learning using generative AI and learning analytics. <em>International Journal of Advanced Computer Science and Applications</em>, <em>17</em>(4). <a href="https://doi.org/10.14569/ijacsa.2026.0170449" target="_blank" rel="noopener noreferrer">https://doi.org/10.14569/ijacsa.2026.0170449</a></p>

            <p class="xapi-reference">Nouira, A., Cheniti-Belcadhi, L., &amp; Braham, R. (2018). An enhanced xAPI data model supporting assessment analytics. <em>Procedia Computer Science</em>, <em>137</em>, 147–157. <a href="https://doi.org/10.1016/J.PROCS.2018.07.291" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/J.PROCS.2018.07.291</a></p>

            <p class="xapi-reference">Rocha, J. C., Hernández-Leal, E., Ramos, V. F. C., Muñoz, R., Cechinel, C., Primo, T., &amp; Queiroga, E. (2024). Implementing a learning record warehouse for different interoperability specifications in Moodle LMS. In <em>Proceedings of the 2024 International Symposium on Innovation and Intelligence in Education</em>. <a href="https://doi.org/10.1109/SIIE63180.2024.10604489" target="_blank" rel="noopener noreferrer">https://doi.org/10.1109/SIIE63180.2024.10604489</a></p>

            <p class="xapi-reference">Samuelsen, J., Chen, W., &amp; Wasson, B. (2021). Enriching context descriptions for enhanced LA scalability: A case study. <em>Research and Practice in Technology Enhanced Learning</em>, <em>16</em>(1), 11. <a href="https://doi.org/10.1186/s41039-021-00150-2" target="_blank" rel="noopener noreferrer">https://doi.org/10.1186/s41039-021-00150-2</a></p>

            <p class="xapi-reference">Vidal, J. C., Rabelo, T., Lama, M., &amp; Amorim, R. (2018). Ontology-based approach for the validation and conformance testing of xAPI events. <em>Knowledge-Based Systems</em>, <em>158</em>, 101–113. <a href="https://doi.org/10.1016/J.KNOSYS.2018.04.035" target="_blank" rel="noopener noreferrer">https://doi.org/10.1016/J.KNOSYS.2018.04.035</a></p>

            <hr style="margin:16px 0">
            <p style="font-size:12px;color:#666"><?php esc_html_e( 'External links:', 'xapi-monitor' ); ?>
                <a href="https://github.com/adlnet/xAPI-Spec" target="_blank" rel="noopener noreferrer">ADL xAPI Specification</a> &bull;
                <a href="https://www.uncannyowl.com/knowledge-base/" target="_blank" rel="noopener noreferrer">Tin Canny Documentation</a> &bull;
                <a href="https://developers.learndash.com/" target="_blank" rel="noopener noreferrer">LearnDash Developer Docs</a> &bull;
                <a href="https://github.com/schoedel-learn/xapi-statement-monitor" target="_blank" rel="noopener noreferrer">This plugin on GitHub</a>
            </p>
        </div>

        </div><?php // end max-width wrapper
    }
}

// ============================================================
// BOOTSTRAP
// ============================================================
add_action( 'plugins_loaded', function() {
    XAPI_Monitor::instance();
}, 5 );

// Uninstall hook is in uninstall.php
