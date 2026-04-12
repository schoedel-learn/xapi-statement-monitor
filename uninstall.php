<?php
/**
 * Uninstall script for xAPI Statement Monitor plugin.
 *
 * Runs when the plugin is deleted via the WordPress admin Plugins screen.
 * Drops all custom database tables and removes all plugin options.
 */

// Only run from WordPress uninstall context
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables
$tables = [
    $wpdb->prefix . 'xapi_monitor_log',
    $wpdb->prefix . 'xapi_monitor_alerts',
];

foreach ( $tables as $table ) {
    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
    // phpcs:enable
}

// Remove plugin options
$options = [
    'xapi_monitor_version',
    'xapi_monitor_settings',
    'xapi_monitor_last_diagnostic',
    'xapi_monitor_last_health_check',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Clear scheduled cron events
$cron_hooks = [
    'xapi_monitor_diagnostic_cron',
    'xapi_monitor_cleanup_cron',
    'xapi_monitor_endpoint_health_cron',
];

foreach ( $cron_hooks as $hook ) {
    $timestamp = wp_next_scheduled( $hook );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, $hook );
    }
    wp_clear_scheduled_hook( $hook );
}

// Remove any uploaded export CSV files from the uploads directory
$upload_dir = wp_upload_dir();
$pattern    = $upload_dir['basedir'] . '/xapi-monitor-export-*.csv';
$files      = glob( $pattern );
if ( is_array( $files ) ) {
    foreach ( $files as $file ) {
        if ( is_file( $file ) ) {
            @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }
}
