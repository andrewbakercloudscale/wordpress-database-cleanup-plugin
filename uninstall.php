<?php
/**
 * CloudScale Cleanup — Uninstall
 *
 * Removes all plugin options and clears scheduled cron events when the plugin
 * is deleted via Plugins > Delete.
 */

defined( 'ABSPATH' ) || exit;
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Settings.
$options = [
    'csc_loaded_version',
    'csc_clean_revisions',
    'csc_clean_drafts',
    'csc_clean_trashed',
    'csc_clean_autodrafts',
    'csc_clean_transients',
    'csc_clean_orphan_post',
    'csc_clean_orphan_user',
    'csc_clean_spam_comments',
    'csc_clean_trash_comments',
    'csc_post_revisions_age',
    'csc_trash_age',
    'csc_drafts_age',
    'csc_autodraft_age',
    'csc_spam_comments_age',
    'csc_trash_comments_age',
    'csc_schedule_db_enabled',
    'csc_schedule_db_days',
    'csc_schedule_db_hour',
    'csc_schedule_img_enabled',
    'csc_schedule_img_days',
    'csc_schedule_img_hour',
    'csc_img_max_width',
    'csc_img_max_height',
    'csc_img_quality',
    'csc_convert_png_to_jpg',
    'cspj_chunk_mb',
    // Runtime data.
    'csc_last_db_cleanup',
    'csc_last_img_cleanup',
    'csc_last_img_optimise',
    'csc_last_scheduled_db_cleanup',
    'csc_last_scheduled_img_cleanup',
    'csc_total_png_conversions',
    'csc_health_hourly_metrics',
    'csc_health_weekly_snapshots',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Clear scheduled cron events.
wp_clear_scheduled_hook( 'csc_scheduled_db_cleanup' );
wp_clear_scheduled_hook( 'csc_scheduled_img_cleanup' );
wp_clear_scheduled_hook( 'cspj_cleanup_chunks' );
wp_clear_scheduled_hook( 'csc_health_hourly_collect' );
wp_clear_scheduled_hook( 'csc_health_weekly_snapshot' );
