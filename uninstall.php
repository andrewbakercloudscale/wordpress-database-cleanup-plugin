<?php
/**
 * CloudScale Cleanup, Uninstall
 *
 * Removes all plugin options and clears scheduled cron events when the plugin
 * is deleted via Plugins > Delete.
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Settings.
$cscc_options = [
    'cscc_loaded_version',
    'cscc_clean_revisions',
    'cscc_clean_drafts',
    'cscc_clean_trashed',
    'cscc_clean_autodrafts',
    'cscc_clean_transients',
    'cscc_clean_orphan_post',
    'cscc_clean_orphan_user',
    'cscc_clean_spam_comments',
    'cscc_clean_trash_comments',
    'cscc_post_revisions_age',
    'cscc_trash_age',
    'cscc_drafts_age',
    'cscc_autodraft_age',
    'cscc_spam_comments_age',
    'cscc_trash_comments_age',
    'cscc_schedule_db_enabled',
    'cscc_schedule_db_days',
    'cscc_schedule_db_hour',
    'cscc_schedule_img_enabled',
    'cscc_schedule_img_days',
    'cscc_schedule_img_hour',
    'cscc_img_max_width',
    'cscc_img_max_height',
    'cscc_img_quality',
    'cscc_convert_png_to_jpg',
    // Both names: the key was renamed for WordPress.org's one-prefix-per-plugin rule and the
    // old row is deliberately left in place on upgrade, so uninstall is what finally removes it.
    'cscc_chunk_mb',
    'cspj_chunk_mb',
    // Runtime data.
    'cscc_last_db_cleanup',
    'cscc_last_img_cleanup',
    'cscc_last_img_optimise',
    'cscc_last_scheduled_db_cleanup',
    'cscc_last_scheduled_img_cleanup',
    'cscc_total_png_conversions',
    'cscc_health_hourly_metrics',
    'cscc_health_weekly_snapshots',
    // Cron management data.
    'cscc_cron_run_log',
    'cscc_cron_recycle_bin',
];

foreach ( $cscc_options as $cscc_option ) {
    delete_option( $cscc_option );
}

// Then everything else carrying the plugin's prefix, so a row added after this list was
// written (queue transients, run state, alert settings) is not left behind. A hand list
// drifts; a prefix sweep cannot. Each row goes through delete_option() so the object cache
// is cleared with it.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall sweep by prefix; no WordPress API lists options by prefix
$cscc_swept = $wpdb->get_col(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( 'cscc_' ) . '%',
        $wpdb->esc_like( '_transient_cscc_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_cscc_' ) . '%'
    )
);
foreach ( (array) $cscc_swept as $cscc_option ) {
    delete_option( $cscc_option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'cscc_scheduled_db_cleanup' );
wp_clear_scheduled_hook( 'cscc_scheduled_img_cleanup' );
wp_clear_scheduled_hook( 'cscc_cleanup_chunks' );
wp_clear_scheduled_hook( 'cspj_cleanup_chunks' ); // pre-rename name; harmless if never scheduled
wp_clear_scheduled_hook( 'cscc_health_hourly_collect' );
wp_clear_scheduled_hook( 'cscc_health_weekly_snapshot' );
