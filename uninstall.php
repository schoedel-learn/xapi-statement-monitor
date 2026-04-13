<?php
/**
 * Uninstall script for xAPI Statement Monitor plugin.
 *
 * Runs ONLY when the plugin is deleted (not deactivated) via the WordPress
 * admin Plugins screen.
 *
 * DATA PRESERVATION POLICY:
 * - Monitoring logs and alert records are NEVER destroyed automatically.
 * - They are only wiped when the administrator explicitly clicks
 *   "Delete All Data" on the plugin Settings page and confirms the action.
 * - Deleting the plugin via the Plugins screen only removes cron schedules
 *   and plugin option keys — the log tables are left intact.
 * - This ensures that installing a newer version (deactivate → delete →
 *   install) never destroys accumulated diagnostic history.
 *
 * To fully wipe all data: use Settings → Delete All Data before deleting.
 */

// Only run from WordPress uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// -----------------------------------------------------------------------
// Check whether the admin explicitly requested a full data wipe.
// This flag is set by the "Delete All Data" action in the Settings tab.
// Without it, tables are preserved across reinstalls and updates.
// -----------------------------------------------------------------------
$wipe_data = (bool) get_option( 'xapi_monitor_wipe_on_uninstall', false );

if ( $wipe_data ) {
    // Admin explicitly opted in — drop the tables.
    $tables = [
        $wpdb->prefix . 'xapi_monitor_log',
        $wpdb->prefix . 'xapi_monitor_alerts',
    ];
    foreach ( $tables as $table ) {
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
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

    // Remove exported CSV files.
    $upload_dir = wp_upload_dir();
    $files      = glob( $upload_dir['basedir'] . '/xapi-monitor-export-*.csv' );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
    }
} else {
    // Default path: preserve log and alert tables.
    // Only clean up non-data options and cron schedules.
    delete_option( 'xapi_monitor_version' );       // Will be re-created on next activation.
    delete_option( 'xapi_monitor_last_diagnostic' );
    delete_option( 'xapi_monitor_last_health_check' );
    // Intentionally NOT deleting: xapi_monitor_settings (user preferences),
    // xapi_monitor_log table, xapi_monitor_alerts table.
}

// Always clear cron schedules — they will be re-registered on next activation.
$cron_hooks = [
    'xapi_monitor_diagnostic_cron',
    'xapi_monitor_cleanup_cron',
    'xapi_monitor_endpoint_health_cron',
];
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}
