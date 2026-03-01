<?php
/**
 * Plugin Name: CloudScale Cleanup
 * Plugin URI:  https://andrewbaker.ninja
 * Description: Database and media library cleanup with dry-run preview, image optimisation, PNG to JPEG conversion, and chunked processing safe on any server. Free, open source, no subscriptions.
 * Version:     2.2.9
 * Author:      Andrew Baker
 * Author URI:  https://andrewbaker.ninja
 * License:     GPL-2.0+
 * Text Domain: cloudscale-cleanup
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CLOUDSCALE_CLEANUP_VERSION', '2.2.9' );
define( 'CLOUDSCALE_CLEANUP_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLOUDSCALE_CLEANUP_URL', plugin_dir_url( __FILE__ ) );
define( 'CLOUDSCALE_CLEANUP_SLUG', 'cloudscale-cleanup' );

// On deactivation, wipe old asset files so next install gets fresh files
register_deactivation_hook( __FILE__, function() {
    $dir = CLOUDSCALE_CLEANUP_DIR;
    // Clean root-level assets
    foreach ( glob( $dir . 'admin.{js,css}', GLOB_BRACE ) as $f ) { @unlink( $f ); }
    // Clean old assets/ subdirectory
    $assets = $dir . 'assets/';
    if ( is_dir( $assets ) ) {
        foreach ( glob( $assets . '*' ) as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } }
        @rmdir( $assets );
    }
} );

// Clear opcode cache on activation so updated files take effect immediately
register_activation_hook( __FILE__, function() {
    if ( function_exists( 'opcache_reset' ) ) {
        opcache_reset();
    }
    csc_cleanup_stale_assets();
} );

// Also clear on every admin page load if version changed
add_action( 'admin_init', function() {
    $cached_version = get_option( 'csc_loaded_version', '' );
    if ( $cached_version !== CLOUDSCALE_CLEANUP_VERSION ) {
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        }
        csc_cleanup_stale_assets();
        update_option( 'csc_loaded_version', CLOUDSCALE_CLEANUP_VERSION );
    }
} );

/**
 * Remove ALL old asset files from the assets directory.
 * WordPress plugin upload does not always overwrite existing files in
 * subdirectories. By deleting all admin-v* files on version change,
 * we guarantee the zip extraction writes fresh copies.
 */
function csc_cleanup_stale_assets() {
    $dir = CLOUDSCALE_CLEANUP_DIR;
    // Clean old assets/ subdirectory from previous versions
    $assets = $dir . 'assets/';
    if ( is_dir( $assets ) ) {
        foreach ( glob( $assets . '*' ) as $f ) { if ( is_file( $f ) ) { @unlink( $f ); } }
        @rmdir( $assets );
    }
}

/*
 * CHUNKED PROCESSING ARCHITECTURE
 * ─────────────────────────────────────────────────────────────────────────────
 * Every "run" operation works in three AJAX steps:
 *
 *   Step 1  csc_*_start   — Build the full list of IDs to process, store in a
 *                           transient, return the total count to JS.
 *
 *   Step 2  csc_*_chunk   — Pull the transient, process one small batch, update
 *                           the transient with the remaining IDs, return log
 *                           lines + remaining count. JS fires repeatedly until
 *                           remaining === 0.
 *
 *   Step 3  csc_*_finish  — Clean up the transient, write the last-run
 *                           timestamp, return a summary line.
 *
 * Each AJAX request completes in well under 30 seconds on any shared host.
 * Chunk sizes: 50 DB items · 25 image deletions · 5 image optimisations.
 */

define( 'CSC_CHUNK_DB',       50 );
define( 'CSC_CHUNK_IMAGES',   25 );
define( 'CSC_CHUNK_OPTIMISE',  5 );

// PNG to JPEG converter constants
define( 'CSPJ_OPTION_CHUNK_MB',  'cspj_chunk_mb' );
define( 'CSPJ_DEFAULT_CHUNK_MB', 1.5 );
define( 'CSPJ_MAX_TOTAL_MB',     200 );

// ─── Admin menu ──────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'csc_add_menu' );
function csc_add_menu() {
    add_management_page(
        'CloudScale Cleanup',
        'CloudScale Cleanup',
        'manage_options',
        CLOUDSCALE_CLEANUP_SLUG,
        'csc_render_page'
    );
}

// ─── Enqueue assets ──────────────────────────────────────────────────────────

add_action( 'admin_enqueue_scripts', 'csc_enqueue_assets' );
function csc_enqueue_assets( $hook ) {
    if ( $hook !== 'tools_page_cloudscale-cleanup' ) {
        return;
    }
    wp_enqueue_style(
        'cloudscale-cleanup-css',
        CLOUDSCALE_CLEANUP_URL . 'admin.css',
        array(),
        CLOUDSCALE_CLEANUP_VERSION
    );
    wp_enqueue_script(
        'cloudscale-cleanup-js',
        CLOUDSCALE_CLEANUP_URL . 'admin.js',
        array( 'jquery' ),
        CLOUDSCALE_CLEANUP_VERSION,
        true
    );
    $cspj_chunk_mb    = csc_get_cspj_chunk_mb();
    $cspj_server_max  = csc_get_cspj_server_max_mb();
    wp_localize_script( 'cloudscale-cleanup-js', 'CSC', array(
        'ajax_url'       => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'csc_nonce' ),
        'cspj_chunk_mb'  => $cspj_chunk_mb,
        'cspj_server_max_mb' => $cspj_server_max,
        'cspj_max_total_mb'  => CSPJ_MAX_TOTAL_MB,
        'version'        => CLOUDSCALE_CLEANUP_VERSION,
    ) );
}

// ─── Admin dashboard widget ───────────────────────────────────────────────────

add_action( 'wp_dashboard_setup', 'csc_register_dashboard_widget' );
function csc_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'csc_dashboard_widget',
        '🥷 AndrewBaker.Ninja CloudScale Cleanup',
        'csc_render_dashboard_widget'
    );
}

function csc_render_dashboard_widget() {
    $last_db  = get_option( 'csc_last_db_cleanup', null );
    $last_img = get_option( 'csc_last_img_cleanup', null );
    $last_opt = get_option( 'csc_last_img_optimise', null );

    $fmt = function ( $val ) {
        return $val
            ? '<span style="font-size:12px;font-weight:700;color:#fff">' . esc_html( human_time_diff( strtotime( $val ), current_time( 'timestamp' ) ) . ' ago' ) . '</span>'
            : '<span style="font-size:12px;font-weight:700;color:rgba(255,255,255,0.5)">Not yet run</span>';
    };

    // Health data
    $weekly = get_option( CSC_HEALTH_WEEKLY_KEY, array() );
    $health = ( count( $weekly ) >= 2 && function_exists( 'csc_health_calculate' ) ) ? csc_health_calculate() : null;
    $rag       = $health ? $health['disk_rag'] : 'grey';
    $rag_map   = array(
        'green' => array( 'label' => 'Healthy',    'bg' => 'linear-gradient(135deg,#2e7d32 0%,#43a047 100%)', 'shadow' => 'rgba(46,125,50,0.35)' ),
        'amber' => array( 'label' => 'Warning',    'bg' => 'linear-gradient(135deg,#e65100 0%,#f57c00 100%)', 'shadow' => 'rgba(230,81,0,0.35)' ),
        'red'   => array( 'label' => 'Critical',   'bg' => 'linear-gradient(135deg,#b71c1c 0%,#e53935 100%)', 'shadow' => 'rgba(183,28,28,0.35)' ),
        'grey'  => array( 'label' => 'Collecting',  'bg' => 'linear-gradient(135deg,#546e7a 0%,#78909c 100%)', 'shadow' => 'rgba(84,110,122,0.35)' ),
    );
    $rag_info  = isset( $rag_map[ $rag ] ) ? $rag_map[ $rag ] : $rag_map['grey'];

    $db_url     = admin_url( 'tools.php?page=cloudscale-cleanup&tab=db-cleanup' );
    $img_url    = admin_url( 'tools.php?page=cloudscale-cleanup&tab=img-cleanup' );
    $opt_url    = admin_url( 'tools.php?page=cloudscale-cleanup&tab=img-optimise' );
    $health_url = admin_url( 'tools.php?page=cloudscale-cleanup&tab=site-health' );
    $tile       = 'display:block;text-decoration:none;border-radius:8px;padding:10px 8px;text-align:center;transition:filter 0.15s,transform 0.15s;cursor:pointer';
    $hover      = "onmouseover=\"this.style.filter='brightness(1.15)';this.style.transform='scale(1.03)'\" onmouseout=\"this.style.filter='';this.style.transform=''\"";
    ?>
    <div style="padding:4px 0 8px">
        <p style="margin:0 0 14px;font-size:13px;color:#50575e;line-height:1.5">
            CloudScale Cleanup is keeping your database and media library lean —
            revisions, transients, unused media, and unregistered files all handled.
        </p>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px">
            <a href="<?php echo esc_url( $db_url ); ?>" style="<?php echo $tile; ?>;background:linear-gradient(135deg,#1565c0 0%,#1976d2 100%);box-shadow:0 2px 6px rgba(21,101,192,0.35)" <?php echo $hover; ?>>
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:rgba(255,255,255,0.7);margin-bottom:5px">⚡ DB Cleanup</div>
                <?php echo $fmt( $last_db ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <a href="<?php echo esc_url( $img_url ); ?>" style="<?php echo $tile; ?>;background:linear-gradient(135deg,#4527a0 0%,#5e35b1 100%);box-shadow:0 2px 6px rgba(69,39,160,0.35)" <?php echo $hover; ?>>
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:rgba(255,255,255,0.7);margin-bottom:5px">🖼 Unused Media</div>
                <?php echo $fmt( $last_img ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <a href="<?php echo esc_url( $opt_url ); ?>" style="<?php echo $tile; ?>;background:linear-gradient(135deg,#00695c 0%,#00897b 100%);box-shadow:0 2px 6px rgba(0,105,92,0.35)" <?php echo $hover; ?>>
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:rgba(255,255,255,0.7);margin-bottom:5px">✨ Img Optimise</div>
                <?php echo $fmt( $last_opt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <a href="<?php echo esc_url( $health_url ); ?>" style="<?php echo $tile; ?>;background:<?php echo $rag_info['bg']; ?>;box-shadow:0 2px 6px <?php echo $rag_info['shadow']; ?>" <?php echo $hover; ?>>
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:rgba(255,255,255,0.7);margin-bottom:5px">📊 Site Health</div>
                <span style="font-size:12px;font-weight:700;color:#fff"><?php echo esc_html( $rag_info['label'] ); ?></span>
            </a>
        </div>

        <?php if ( $health ) : ?>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px;font-size:11px;text-align:center">
            <div style="background:#f0f2f5;border-radius:6px;padding:6px 4px">
                <div style="color:#78909c;font-weight:600;margin-bottom:2px">Disk Used</div>
                <div style="font-weight:700;color:#263238"><?php echo esc_html( size_format( $health['disk_used'], 1 ) ); ?></div>
            </div>
            <div style="background:#f0f2f5;border-radius:6px;padding:6px 4px">
                <div style="color:#78909c;font-weight:600;margin-bottom:2px">Disk Free</div>
                <div style="font-weight:700;color:#263238"><?php echo esc_html( size_format( $health['disk_free'], 1 ) ); ?></div>
            </div>
            <div style="background:#f0f2f5;border-radius:6px;padding:6px 4px">
                <div style="color:#78909c;font-weight:600;margin-bottom:2px">Growth/Wk</div>
                <div style="font-weight:700;color:#263238"><?php echo $health['growth_per_week'] > 0 ? esc_html( size_format( $health['growth_per_week'], 1 ) ) : '—'; ?></div>
            </div>
            <div style="background:#f0f2f5;border-radius:6px;padding:6px 4px">
                <div style="color:#78909c;font-weight:600;margin-bottom:2px">Est. Storage Full</div>
                <div style="font-weight:700;color:#263238"><?php echo $health['weeks_remaining'] > 104 ? '>> 2 Yrs' : ( $health['weeks_remaining'] > 0 ? esc_html( round( $health['weeks_remaining'] ) ) : '—' ); ?></div>
            </div>
        </div>
        <?php else : ?>
        <p style="margin:0 0 16px;font-size:11px;color:#90a4ae;text-align:center">📊 Health metrics collecting — summary available after first weekly snapshot.</p>
        <?php endif; ?>

        <div style="display:flex;flex-direction:column;gap:10px">
            <a href="https://andrewbaker.ninja" target="_blank" rel="noopener"
               style="display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,#f953c6 0%,#b91d73 40%,#4f46e5 100%);color:#fff;font-weight:700;font-size:13px;padding:10px 16px;border-radius:8px;text-decoration:none;box-shadow:0 3px 10px rgba(249,83,198,0.4);transition:filter 0.15s,transform 0.15s"
               onmouseover="this.style.filter='brightness(1.15)';this.style.transform='scale(1.02)'"
               onmouseout="this.style.filter='';this.style.transform=''">
                <span style="font-size:15px">🥷</span> Visit AndrewBaker.Ninja
            </a>
            <a href="<?php echo esc_url( admin_url( 'tools.php?page=cloudscale-cleanup' ) ); ?>"
               style="display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,#0ea5e9 0%,#0369a1 100%);color:#fff;font-weight:700;font-size:13px;padding:10px 16px;border-radius:8px;text-decoration:none;box-shadow:0 3px 10px rgba(14,165,233,0.35);transition:filter 0.15s,transform 0.15s"
               onmouseover="this.style.filter='brightness(1.15)';this.style.transform='scale(1.02)'"
               onmouseout="this.style.filter='';this.style.transform=''">
                <span style="font-size:15px">⚡</span> Open CloudScale Cleanup
            </a>
            <a href="<?php echo esc_url( admin_url( 'tools.php?page=cloudscale-cleanup&tab=png-to-jpeg' ) ); ?>"
               style="display:flex;align-items:center;justify-content:center;gap:8px;background:linear-gradient(135deg,#689f38 0%,#8bc34a 100%);color:#fff;font-weight:700;font-size:13px;padding:10px 16px;border-radius:8px;text-decoration:none;box-shadow:0 3px 10px rgba(104,159,56,0.35);transition:filter 0.15s,transform 0.15s"
               onmouseover="this.style.filter='brightness(1.15)';this.style.transform='scale(1.02)'"
               onmouseout="this.style.filter='';this.style.transform=''">
                <span style="font-size:15px">🖼</span> PNG to JPEG
            </a>
        </div>
    </div>
    <?php
}


// ─── Front-end sidebar widget ─────────────────────────────────────────────────
/*
 * Registers a widget visible in Appearance -> Widgets (classic widget screen)
 * and the block editor widget screen. Drag it into any sidebar or widget area
 * in your theme to show it on the front end of the site.
 */

add_action( 'widgets_init', function () {
    register_widget( 'CSC_Front_Widget' );
} );

class CSC_Front_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'csc_front_widget',
            'CloudScale Cleanup',
            array(
                'description' => 'Shows last cleanup run times and links to the CloudScale Cleanup plugin and andrewbaker.ninja.',
                'classname'   => 'widget-csc-cleanup',
            )
        );
    }

    /** Render the widget on the front end */
    public function widget( $args, $instance ) {
        $title    = ! empty( $instance['title'] ) ? $instance['title'] : 'Site Maintenance';
        $last_db  = get_option( 'csc_last_db_cleanup',   null );
        $last_img = get_option( 'csc_last_img_cleanup',  null );
        $last_opt = get_option( 'csc_last_img_optimise', null );

        // Site health RAG
        $health_rag = 'grey';
        $health_label = 'Collecting';
        $weekly = get_option( CSC_HEALTH_WEEKLY_KEY, array() );
        if ( count( $weekly ) >= 2 && function_exists( 'csc_health_calculate' ) ) {
            $h = csc_health_calculate();
            $health_rag = $h['disk_rag'];
            if ( $health_rag === 'green' ) { $health_label = 'Healthy'; }
            elseif ( $health_rag === 'amber' ) { $health_label = 'Warning'; }
            elseif ( $health_rag === 'red' ) { $health_label = 'Critical'; }
        }

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        ?>
        <div class="csc-front-widget">
            <ul class="csc-fw-list">
                <li>
                    <span class="csc-fw-label">DB Cleanup</span>
                    <span class="csc-fw-value"><?php echo $last_db  ? esc_html( human_time_diff( strtotime( $last_db  ), current_time( 'timestamp' ) ) . ' ago' ) : 'Never run'; ?></span>
                </li>
                <li>
                    <span class="csc-fw-label">Unused Media</span>
                    <span class="csc-fw-value"><?php echo $last_img ? esc_html( human_time_diff( strtotime( $last_img ), current_time( 'timestamp' ) ) . ' ago' ) : 'Never run'; ?></span>
                </li>
                <li>
                    <span class="csc-fw-label">Img Optimise</span>
                    <span class="csc-fw-value"><?php echo $last_opt ? esc_html( human_time_diff( strtotime( $last_opt ), current_time( 'timestamp' ) ) . ' ago' ) : 'Never run'; ?></span>
                </li>
                <li>
                    <span class="csc-fw-label">Site Health</span>
                    <?php
                    $rag_colors = array( 'green' => '#2e7d32', 'amber' => '#e65100', 'red' => '#c62828', 'grey' => '#78909c' );
                    $rag_color  = isset( $rag_colors[ $health_rag ] ) ? $rag_colors[ $health_rag ] : '#78909c';
                    ?>
                    <span class="csc-fw-value" style="color:<?php echo esc_attr( $rag_color ); ?>">&#9679; <?php echo esc_html( $health_label ); ?></span>
                </li>
            </ul>
            <div class="csc-fw-links">
                <a href="https://andrewbaker.ninja" target="_blank" rel="noopener" class="csc-fw-link">andrewbaker.ninja</a>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=cloudscale-cleanup' ) ); ?>" class="csc-fw-link csc-fw-link-admin">Run Cleanup</a>
                <?php endif; ?>
            </div>
            <p class="csc-fw-credit">Powered by <a href="https://andrewbaker.ninja" target="_blank" rel="noopener">CloudScale Cleanup</a></p>
        </div>
        <?php
        echo $args['after_widget'];
    }

    /** Settings form in Appearance -> Widgets */
    public function form( $instance ) {
        $title = ! empty( $instance['title'] ) ? $instance['title'] : 'Site Maintenance';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title:</label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <?php
    }

    /** Save widget settings */
    public function update( $new_instance, $old_instance ) {
        $instance          = $old_instance;
        $instance['title'] = sanitize_text_field( $new_instance['title'] );
        return $instance;
    }
}

// Inline CSS for the front-end widget — only loaded when widget is active
add_action( 'wp_enqueue_scripts', 'csc_enqueue_front_widget_styles' );
function csc_enqueue_front_widget_styles() {
    if ( ! is_active_widget( false, false, 'csc_front_widget', true ) ) {
        return;
    }
    wp_add_inline_style( 'wp-block-library', '
.csc-front-widget{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13.5px}
.csc-fw-list{margin:0 0 12px;padding:0;list-style:none}
.csc-fw-list li{display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid rgba(0,0,0,.07)}
.csc-fw-list li:last-child{border-bottom:none}
.csc-fw-label{color:#555;font-size:12.5px}
.csc-fw-value{font-weight:600;color:#1a1f2e;font-size:12.5px}
.csc-fw-links{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.csc-fw-link{display:inline-block;font-size:12px;font-weight:600;padding:6px 12px;border-radius:5px;text-decoration:none;background:#1a1f2e;color:#fff!important;transition:background .15s}
.csc-fw-link:hover{background:#4a9eff;color:#fff!important}
.csc-fw-link-admin{background:#27ae60}
.csc-fw-link-admin:hover{background:#219150}
.csc-fw-credit{font-size:11px;color:#999;margin:0}
.csc-fw-credit a{color:#4a9eff;text-decoration:none}
    ' );
}

// ─── Settings save ────────────────────────────────────────────────────────────

add_action( 'wp_ajax_csc_save_settings', 'csc_ajax_save_settings' );
function csc_ajax_save_settings() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions.' );
    }

    $scalars = array(
        'csc_post_revisions_age', 'csc_drafts_age', 'csc_trash_age',
        'csc_autodraft_age', 'csc_spam_comments_age', 'csc_trash_comments_age',
        'csc_img_max_width', 'csc_img_max_height', 'csc_img_quality',
        'csc_schedule_db_hour', 'csc_schedule_img_hour',
        'csc_clean_revisions', 'csc_clean_drafts', 'csc_clean_trashed', 'csc_clean_autodrafts',
        'csc_clean_transients', 'csc_clean_orphan_post', 'csc_clean_orphan_user',
        'csc_clean_spam_comments', 'csc_clean_trash_comments',
    );
    $bools = array(
        'csc_schedule_db_enabled', 'csc_schedule_img_enabled', 'csc_convert_png_to_jpg',
    );
    $arrays = array( 'csc_schedule_db_days', 'csc_schedule_img_days' );

    foreach ( $scalars as $f ) {
        if ( isset( $_POST[ $f ] ) ) {
            $val = sanitize_text_field( wp_unslash( $_POST[ $f ] ) );
            // Toggle fields: only accept '0' or '1'
            if ( in_array( $f, array(
                'csc_clean_revisions', 'csc_clean_drafts', 'csc_clean_trashed', 'csc_clean_autodrafts',
                'csc_clean_transients', 'csc_clean_orphan_post', 'csc_clean_orphan_user',
                'csc_clean_spam_comments', 'csc_clean_trash_comments',
            ), true ) ) {
                $val = $val === '1' ? '1' : '0';
            }
            update_option( $f, $val );
        }
    }
    foreach ( $bools as $f ) {
        update_option( $f, isset( $_POST[ $f ] ) ? '1' : '0' );
    }
    foreach ( $arrays as $f ) {
        update_option( $f, isset( $_POST[ $f ] ) && is_array( $_POST[ $f ] )
            ? array_map( 'sanitize_text_field', $_POST[ $f ] )
            : array()
        );
    }

    csc_schedule_crons();
    wp_send_json_success( 'Settings saved.' );
}

// ─── Cron scheduling ─────────────────────────────────────────────────────────

function csc_schedule_crons() {
    wp_clear_scheduled_hook( 'csc_scheduled_db_cleanup' );
    if ( get_option( 'csc_schedule_db_enabled', '0' ) === '1' ) {
        $ts = csc_next_run_timestamp(
            (array) get_option( 'csc_schedule_db_days', array() ),
            intval( get_option( 'csc_schedule_db_hour', 3 ) )
        );
        if ( $ts ) { wp_schedule_single_event( $ts, 'csc_scheduled_db_cleanup' ); }
    }

    wp_clear_scheduled_hook( 'csc_scheduled_img_cleanup' );
    if ( get_option( 'csc_schedule_img_enabled', '0' ) === '1' ) {
        $ts = csc_next_run_timestamp(
            (array) get_option( 'csc_schedule_img_days', array() ),
            intval( get_option( 'csc_schedule_img_hour', 4 ) )
        );
        if ( $ts ) { wp_schedule_single_event( $ts, 'csc_scheduled_img_cleanup' ); }
    }
}

function csc_next_run_timestamp( $days, $hour ) {
    $map = array(
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday',
        'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    );
    $now  = current_time( 'timestamp' );
    $best = null;
    foreach ( $days as $d ) {
        $d = strtolower( trim( $d ) );
        if ( ! isset( $map[ $d ] ) ) { continue; }
        $candidate = strtotime( 'next ' . $map[ $d ], $now );
        $candidate = mktime( $hour, 0, 0, date( 'n', $candidate ), date( 'j', $candidate ), date( 'Y', $candidate ) );
        if ( $candidate <= $now ) { $candidate += WEEK_IN_SECONDS; }
        if ( $best === null || $candidate < $best ) { $best = $candidate; }
    }
    return $best;
}

// Cron handlers — run synchronously (no HTTP chunking needed in a cron context)
add_action( 'csc_scheduled_db_cleanup', 'csc_cron_db_cleanup' );
function csc_cron_db_cleanup() {
    $ids = csc_build_db_id_list();
    foreach ( $ids['revisions']      as $id ) { wp_delete_post( intval( $id ), true ); }
    foreach ( $ids['drafts']         as $id ) { wp_delete_post( intval( $id ), true ); }
    foreach ( $ids['trashed']        as $id ) { wp_delete_post( intval( $id ), true ); }
    foreach ( $ids['autodrafts']     as $id ) { wp_delete_post( intval( $id ), true ); }
    csc_delete_expired_transients();
    csc_delete_orphaned_postmeta();
    csc_delete_orphaned_usermeta();
    foreach ( $ids['spam_comments']  as $id ) { wp_delete_comment( intval( $id ), true ); }
    foreach ( $ids['trash_comments'] as $id ) { wp_delete_comment( intval( $id ), true ); }
    update_option( 'csc_last_db_cleanup', current_time( 'mysql' ) );
    update_option( 'csc_last_scheduled_db_cleanup', current_time( 'mysql' ) );
    csc_schedule_crons();
}

add_action( 'csc_scheduled_img_cleanup', 'csc_cron_img_cleanup' );
function csc_cron_img_cleanup() {
    $used = csc_get_used_attachment_ids();
    $all  = get_posts( array(
        'post_type' => 'attachment', 'post_status' => 'inherit',
        'posts_per_page' => -1, 'fields' => 'ids',
    ) );

    // Load existing media recycle manifest
    if ( ! csc_media_recycle_ensure_dir() ) {
        return;
    }
    $manifest = csc_media_recycle_read_manifest();

    $recycled = 0;
    foreach ( $all as $id ) {
        if ( isset( $used[ $id ] ) ) { continue; }
        try {
            $result = csc_media_recycle_save_attachment( intval( $id ) );
            if ( ! empty( $result['error'] ) ) {
                error_log( '[CSC] Cron recycle error for ID ' . $id . ': ' . $result['error'] );
                continue;
            }
            $manifest[ (string) $id ] = array(
                'post'        => $result['post'],
                'meta'        => $result['meta'],
                'files_moved' => $result['files_moved'],
                'recycled_at' => current_time( 'mysql' ),
            );
            wp_delete_attachment( $id, true );
            $recycled++;
        } catch ( Exception $e ) {
            error_log( '[CSC] Cron recycle exception for ID ' . $id . ': ' . $e->getMessage() );
        } catch ( Throwable $e ) {
            error_log( '[CSC] Cron recycle fatal for ID ' . $id . ': ' . $e->getMessage() );
        }
    }

    if ( ! csc_media_recycle_write_manifest( $manifest ) ) {
        error_log( '[CSC] Cron: Failed to write media recycle manifest.' );
    }

    error_log( '[CSC] Cron: Recycled ' . $recycled . ' unused attachment(s). Total in recycle bin: ' . count( $manifest ) );
    update_option( 'csc_last_img_cleanup', current_time( 'mysql' ) );
    update_option( 'csc_last_scheduled_img_cleanup', current_time( 'mysql' ) );
    csc_schedule_crons();
}

// ═════════════════════════════════════════════════════════════════════════════
// DATABASE CLEANUP
// ═════════════════════════════════════════════════════════════════════════════

function csc_build_db_id_list( $overrides = array() ) {
    global $wpdb;
    $ra  = intval( get_option( 'csc_post_revisions_age', 30 ) );
    $da  = intval( get_option( 'csc_drafts_age', 90 ) );
    $ta  = intval( get_option( 'csc_trash_age', 30 ) );
    $aa  = intval( get_option( 'csc_autodraft_age', 7 ) );
    $sa  = intval( get_option( 'csc_spam_comments_age', 30 ) );
    $tca = intval( get_option( 'csc_trash_comments_age', 30 ) );

    $tog = function( $opt ) use ( $overrides ) {
        if ( ! empty( $overrides ) ) {
            // Full UI submission passed — absent key means toggled off
            return isset( $overrides[ $opt ] ) && $overrides[ $opt ] === '1';
        }
        return get_option( $opt, '1' ) === '1';
    };

    return array(
        'revisions'      => $tog( 'csc_clean_revisions' )      ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='revision' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $ra ) ) : array(),
        'drafts'         => $tog( 'csc_clean_drafts' )         ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status='draft' AND post_type='post' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $da ) ) : array(),
        'trashed'        => $tog( 'csc_clean_trashed' )        ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status='trash' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $ta ) ) : array(),
        'autodrafts'     => $tog( 'csc_clean_autodrafts' )     ? $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status='auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $aa ) ) : array(),
        'spam_comments'  => $tog( 'csc_clean_spam_comments' )  ? $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved='spam' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $sa ) ) : array(),
        'trash_comments' => $tog( 'csc_clean_trash_comments' ) ? $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved='trash' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $tca ) ) : array(),
    );
}

function csc_delete_expired_transients() {
    global $wpdb;
    $keys = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
    foreach ( $keys as $k ) { delete_transient( str_replace( '_transient_timeout_', '', $k ) ); }
    return count( $keys );
}

function csc_delete_orphaned_postmeta() {
    global $wpdb;
    return (int) $wpdb->query( "DELETE pm FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
}

function csc_delete_orphaned_usermeta() {
    global $wpdb;
    return (int) $wpdb->query( "DELETE um FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
}

// Dry run
add_action( 'wp_ajax_csc_scan_db', 'csc_ajax_scan_db' );
function csc_ajax_scan_db() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    // Read toggle state from POST if provided (live UI state), otherwise fall back to DB.
    // If ANY toggle key is present in POST, we treat this as a full UI submission —
    // missing keys default to '0' rather than falling back to DB, preventing stale DB
    // values from overriding the user's current screen state.
    $has_post_toggles = isset( $_POST['csc_clean_revisions'] )
        || isset( $_POST['csc_clean_drafts'] )
        || isset( $_POST['csc_clean_transients'] );



    $toggle = function( $opt ) use ( $has_post_toggles ) {
        if ( $has_post_toggles ) {
            // Full UI submission — use POST value, absent = '0' (toggled off)
            return isset( $_POST[ $opt ] ) && $_POST[ $opt ] === '1';
        }
        // No UI data sent (e.g. scheduled run) — use DB
        return get_option( $opt, '1' ) === '1';
    };

    global $wpdb;
    $ra  = intval( get_option( 'csc_post_revisions_age', 30 ) );
    $da  = intval( get_option( 'csc_drafts_age', 90 ) );
    $ta  = intval( get_option( 'csc_trash_age', 30 ) );
    $aa  = intval( get_option( 'csc_autodraft_age', 7 ) );
    $sa  = intval( get_option( 'csc_spam_comments_age', 30 ) );
    $tca = intval( get_option( 'csc_trash_comments_age', 30 ) );

    $toggle_keys = array(
        'csc_clean_revisions', 'csc_clean_drafts', 'csc_clean_trashed', 'csc_clean_autodrafts',
        'csc_clean_transients', 'csc_clean_orphan_post', 'csc_clean_orphan_user',
        'csc_clean_spam_comments', 'csc_clean_trash_comments',
    );
    $lines = array();

    if ( $toggle( 'csc_clean_revisions' ) ) {
        $revisions = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type='revision' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY post_date DESC LIMIT 1000", $ra ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Post Revisions (older than ' . $ra . ' days)' );
        foreach ( $revisions as $r ) { $lines[] = array( 'type' => 'item', 'text' => '  [REVISION] ID ' . $r->ID . ' — ' . esc_html( $r->post_title ) . ' (' . $r->post_date . ')' ); }
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . count( $revisions ) );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Post Revisions — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_drafts' ) ) {
        $drafts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_status='draft' AND post_type='post' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY post_date DESC LIMIT 500", $da ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Draft Posts (older than ' . $da . ' days)' );
        foreach ( $drafts as $d ) { $lines[] = array( 'type' => 'item', 'text' => '  [DRAFT] ID ' . $d->ID . ' — ' . esc_html( $d->post_title ) . ' (' . $d->post_date . ')' ); }
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . count( $drafts ) );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Draft Posts — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_trashed' ) ) {
        $trashed = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_modified FROM {$wpdb->posts} WHERE post_status='trash' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY) ORDER BY post_modified DESC LIMIT 500", $ta ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Posts (older than ' . $ta . ' days)' );
        foreach ( $trashed as $t ) { $lines[] = array( 'type' => 'item', 'text' => '  [TRASH] ID ' . $t->ID . ' — ' . esc_html( $t->post_title ) . ' (' . $t->post_modified . ')' ); }
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . count( $trashed ) );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Posts — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_autodrafts' ) ) {
        $cnt_auto = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='auto-draft' AND post_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $aa ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Auto-Drafts (older than ' . $aa . ' days)' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_auto );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Auto-Drafts — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_transients' ) ) {
        $cnt_t = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()" );
        $lines[] = array( 'type' => 'section', 'text' => 'Expired Transients' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_t );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Expired Transients — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_orphan_post' ) ) {
        $cnt_pm = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.ID IS NULL" );
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned Post Meta' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_pm . ' rows' );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned Post Meta — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_orphan_user' ) ) {
        $cnt_um = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON um.user_id = u.ID WHERE u.ID IS NULL" );
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned User Meta' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_um . ' rows' );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Orphaned User Meta — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_spam_comments' ) ) {
        $cnt_spam = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='spam' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $sa ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Spam Comments (older than ' . $sa . ' days)' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_spam );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Spam Comments — SKIPPED (disabled)' );
    }

    if ( $toggle( 'csc_clean_trash_comments' ) ) {
        $cnt_tc = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved='trash' AND comment_date < DATE_SUB(NOW(), INTERVAL %d DAY)", $tca ) );
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Comments (older than ' . $tca . ' days)' );
        $lines[] = array( 'type' => 'count', 'text' => '  Found: ' . $cnt_tc );
    } else {
        $lines[] = array( 'type' => 'section', 'text' => 'Trashed Comments — SKIPPED (disabled)' );
    }

    wp_send_json_success( $lines );
}

// Chunked run — Step 1: build queue
add_action( 'wp_ajax_csc_db_start', 'csc_ajax_db_start' );
function csc_ajax_db_start() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    // Collect any toggle overrides sent from the live UI
    $toggle_keys = array(
        'csc_clean_revisions', 'csc_clean_drafts', 'csc_clean_trashed', 'csc_clean_autodrafts',
        'csc_clean_transients', 'csc_clean_orphan_post', 'csc_clean_orphan_user',
        'csc_clean_spam_comments', 'csc_clean_trash_comments',
    );
    $overrides = array();
    foreach ( $toggle_keys as $k ) {
        if ( isset( $_POST[ $k ] ) ) {
            $overrides[ $k ] = $_POST[ $k ] === '1' ? '1' : '0';
        }
    }

    $has_post_toggles = isset( $_POST['csc_clean_revisions'] )
        || isset( $_POST['csc_clean_drafts'] )
        || isset( $_POST['csc_clean_transients'] );

    $tog = function( $opt ) use ( $overrides, $has_post_toggles ) {
        if ( $has_post_toggles ) {
            return isset( $overrides[ $opt ] ) && $overrides[ $opt ] === '1';
        }
        return get_option( $opt, '1' ) === '1';
    };

    $ids   = csc_build_db_id_list( $overrides );
    $queue = array();
    foreach ( $ids['revisions']      as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'revision' ); }
    foreach ( $ids['drafts']         as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'draft' ); }
    foreach ( $ids['trashed']        as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'trashed post' ); }
    foreach ( $ids['autodrafts']     as $id ) { $queue[] = array( 'type' => 'post',          'id' => intval( $id ), 'label' => 'auto-draft' ); }
    foreach ( $ids['spam_comments']  as $id ) { $queue[] = array( 'type' => 'comment',       'id' => intval( $id ), 'label' => 'spam comment' ); }
    foreach ( $ids['trash_comments'] as $id ) { $queue[] = array( 'type' => 'comment',       'id' => intval( $id ), 'label' => 'trashed comment' ); }
    if ( $tog( 'csc_clean_transients' ) )  { $queue[] = array( 'type' => 'transients',  'id' => 0, 'label' => 'expired transients' ); }
    if ( $tog( 'csc_clean_orphan_post' ) ) { $queue[] = array( 'type' => 'orphan_post', 'id' => 0, 'label' => 'orphaned postmeta' ); }
    if ( $tog( 'csc_clean_orphan_user' ) ) { $queue[] = array( 'type' => 'orphan_user', 'id' => 0, 'label' => 'orphaned usermeta' ); }

    set_transient( 'csc_db_queue', $queue, HOUR_IN_SECONDS );

    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  Work queue built: ' . count( $queue ) . ' items.' ) ),
    ) );
}

// Step 2: process a chunk
add_action( 'wp_ajax_csc_db_chunk', 'csc_ajax_db_chunk' );
function csc_ajax_db_chunk() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $queue = get_transient( 'csc_db_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired — please start again.' ); }

    $chunk = array_splice( $queue, 0, CSC_CHUNK_DB );
    set_transient( 'csc_db_queue', $queue, HOUR_IN_SECONDS );

    $lines = array();
    foreach ( $chunk as $item ) {
        switch ( $item['type'] ) {
            case 'post':
                wp_delete_post( $item['id'], true );
                $lines[] = array( 'type' => 'deleted', 'text' => '  Deleted ' . $item['label'] . ' ID ' . $item['id'] );
                break;
            case 'comment':
                wp_delete_comment( $item['id'], true );
                $lines[] = array( 'type' => 'deleted', 'text' => '  Deleted ' . $item['label'] . ' ID ' . $item['id'] );
                break;
            case 'transients':
                $n = csc_delete_expired_transients();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' expired transients.' );
                break;
            case 'orphan_post':
                $n = csc_delete_orphaned_postmeta();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' orphaned postmeta rows.' );
                break;
            case 'orphan_user':
                $n = csc_delete_orphaned_usermeta();
                $lines[] = array( 'type' => 'count', 'text' => '  Deleted ' . $n . ' orphaned usermeta rows.' );
                break;
        }
    }

    wp_send_json_success( array( 'remaining' => count( $queue ), 'lines' => $lines ) );
}

// Step 3: finish
add_action( 'wp_ajax_csc_db_finish', 'csc_ajax_db_finish' );
function csc_ajax_db_finish() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    delete_transient( 'csc_db_queue' );
    update_option( 'csc_last_db_cleanup', current_time( 'mysql' ) );
    wp_send_json_success( array( 'lines' => array( array( 'type' => 'success', 'text' => 'Database cleanup complete.' ) ) ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// IMAGE CLEANUP
// ═════════════════════════════════════════════════════════════════════════════

function csc_get_used_attachment_ids() {
    global $wpdb;
    $used = array();

    // Featured images
    foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id'" ) as $id ) {
        $used[ intval( $id ) ] = true;
    }

    // Gutenberg block IDs and legacy class-based image references
    $contents = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_status='publish' AND post_type NOT IN ('attachment','revision')" );

    // Build a lookup of upload filenames to attachment IDs for URL matching
    $upload_dir  = wp_upload_dir();
    $upload_url  = trailingslashit( $upload_dir['baseurl'] );
    $file_to_id  = array();
    $rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" );
    foreach ( $rows as $row ) {
        $file_to_id[ $row->meta_value ] = intval( $row->post_id );
        // Also index the basename without extension for thumbnail matching
        $base = pathinfo( $row->meta_value, PATHINFO_FILENAME );
        $dir  = dirname( $row->meta_value );
        $file_to_id[ '__base__' . $dir . '/' . $base ] = intval( $row->post_id );
    }

    foreach ( $contents as $c ) {
        // Standard wp-image-NNN class detection
        if ( preg_match_all( '/wp-image-(\d+)/i', $c, $m ) ) {
            foreach ( $m[1] as $id ) { $used[ intval( $id ) ] = true; }
        }
        // Gutenberg block "id":NNN detection
        if ( preg_match_all( '/"id"\s*:\s*(\d+)/i', $c, $m ) ) {
            foreach ( $m[1] as $id ) { $used[ intval( $id ) ] = true; }
        }

        // URL based detection: find all image URLs in wp-content/uploads and resolve to attachment IDs
        if ( preg_match_all( '/(?:src|href)=["\']([^"\']*wp-content\/uploads\/[^"\']+)/i', $c, $url_matches ) ) {
            foreach ( $url_matches[1] as $url ) {
                // Extract relative path after uploads/
                $pos = strpos( $url, 'wp-content/uploads/' );
                if ( $pos === false ) { continue; }
                $rel = substr( $url, $pos + strlen( 'wp-content/uploads/' ) );

                // Direct match (original file referenced by URL)
                if ( isset( $file_to_id[ $rel ] ) ) {
                    $used[ $file_to_id[ $rel ] ] = true;
                    continue;
                }

                // Thumbnail match: strip -NNNxNNN from filename and match to original
                $stripped = preg_replace( '/-\d{2,5}x\d{2,5}(\.[a-zA-Z]+)$/', '$1', $rel );
                if ( $stripped !== $rel && isset( $file_to_id[ $stripped ] ) ) {
                    $used[ $file_to_id[ $stripped ] ] = true;
                    continue;
                }

                // Basename match: use directory + basename without dimensions
                $dir  = dirname( $rel );
                $base = pathinfo( $stripped, PATHINFO_FILENAME );
                $key  = '__base__' . $dir . '/' . $base;
                if ( isset( $file_to_id[ $key ] ) ) {
                    $used[ $file_to_id[ $key ] ] = true;
                }
            }
        }
    }

    // Widget options and theme mods
    $opts = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget_%' OR option_name LIKE 'theme_mods_%'" );
    foreach ( $opts as $v ) {
        if ( preg_match_all( '/"id"\s*:\s*(\d+)/i', $v, $m ) ) {
            foreach ( $m[1] as $id ) { $used[ intval( $id ) ] = true; }
        }
    }

    // Site logo and site icon are always protected
    $logo = get_theme_mod( 'custom_logo' );
    if ( $logo ) { $used[ intval( $logo ) ] = true; }
    $icon = get_option( 'site_icon' );
    if ( $icon ) { $used[ intval( $icon ) ] = true; }

    return $used;
}

// Dry run
add_action( 'wp_ajax_csc_scan_images', 'csc_ajax_scan_images' );
function csc_ajax_scan_images() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $used = csc_get_used_attachment_ids();
    $all  = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => 'Unused Media Attachments' );
    $lines[] = array( 'type' => 'info',    'text' => '  Total in library: ' . count( $all ) . '   Confirmed in use: ' . count( $used ) );

    $unused = array();
    foreach ( $all as $id ) {
        if ( ! isset( $used[ $id ] ) ) { $unused[] = $id; }
    }
    $total_unused_size = 0;
    foreach ( $unused as $id ) {
        $file      = get_attached_file( $id );
        $file_size = ( $file && file_exists( $file ) ) ? filesize( $file ) : 0;
        $total_unused_size += $file_size;
        $ext     = $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
        $size_str = $file_size > 0 ? size_format( $file_size ) : 'file missing';
        $label   = esc_html( get_the_title( $id ) );
        if ( $ext ) { $label .= '.' . strtolower( $ext ); }
        $lines[] = array( 'type' => 'item', 'text' => '  [UNUSED] ID ' . $id . ' — ' . $label . ' (' . $size_str . ')' );
    }
    $lines[] = array( 'type' => 'count', 'text' => '  Total unused: ' . count( $unused ) );
    $lines[] = array( 'type' => 'count', 'text' => '  Total size on disk: ' . size_format( $total_unused_size ) );

    wp_send_json_success( $lines );
}

// ─── Media Recycle Bin helpers ────────────────────────────────────────────────

function csc_media_recycle_dir(): string {
    return trailingslashit( wp_upload_dir()['basedir'] ) . '.csc-media-recycle/';
}

function csc_media_recycle_manifest(): string {
    return csc_media_recycle_dir() . 'manifest.json';
}

function csc_media_recycle_count(): int {
    $manifest = csc_media_recycle_manifest();
    if ( ! file_exists( $manifest ) ) { return 0; }
    $data = json_decode( file_get_contents( $manifest ), true );
    return is_array( $data ) ? count( $data ) : 0;
}

/**
 * Ensure the media recycle directory exists, is protected from direct web
 * access, and contains index.php / .htaccess guards.
 */
function csc_media_recycle_ensure_dir(): bool {
    $dir = csc_media_recycle_dir();
    if ( ! wp_mkdir_p( $dir ) ) {
        error_log( '[CSC] Cannot create media recycle directory: ' . $dir );
        return false;
    }
    // Prevent directory listing and direct file access
    $htaccess = $dir . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        @file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
    }
    $index = $dir . 'index.php';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, "<?php // Silence is golden.\n" );
    }
    return true;
}

/**
 * Read the media recycle manifest with corruption detection.
 * If manifest.json is corrupted, try the backup. If both fail, return empty.
 */
function csc_media_recycle_read_manifest(): array {
    $path   = csc_media_recycle_manifest();
    $backup = $path . '.bak';

    // Try primary manifest
    if ( file_exists( $path ) ) {
        $raw  = file_get_contents( $path );
        $data = json_decode( $raw, true );
        if ( is_array( $data ) ) {
            return $data;
        }
        error_log( '[CSC] Media recycle manifest.json corrupted (json_last_error=' . json_last_error() . '). Trying backup.' );
    }

    // Try backup manifest
    if ( file_exists( $backup ) ) {
        $raw  = file_get_contents( $backup );
        $data = json_decode( $raw, true );
        if ( is_array( $data ) ) {
            error_log( '[CSC] Recovered media recycle manifest from backup.' );
            // Restore the primary from backup
            @copy( $backup, $path );
            return $data;
        }
        error_log( '[CSC] Media recycle backup manifest also corrupted.' );
    }

    return array();
}

/**
 * Write the media recycle manifest atomically with a backup copy.
 * Returns true on success, false on failure.
 */
function csc_media_recycle_write_manifest( array $manifest ): bool {
    $path   = csc_media_recycle_manifest();
    $backup = $path . '.bak';
    $json   = json_encode( $manifest, JSON_PRETTY_PRINT );

    if ( $json === false ) {
        error_log( '[CSC] Failed to encode media recycle manifest (json_last_error=' . json_last_error() . ').' );
        return false;
    }

    // Backup current manifest before overwriting
    if ( file_exists( $path ) ) {
        @copy( $path, $backup );
    }

    // Write atomically: write to temp file then rename
    $tmp = $path . '.tmp';
    $written = file_put_contents( $tmp, $json );
    if ( $written === false ) {
        error_log( '[CSC] Failed to write media recycle manifest temp file.' );
        return false;
    }

    if ( ! @rename( $tmp, $path ) ) {
        // Fallback: direct write if rename fails (cross device)
        $written = file_put_contents( $path, $json );
        @unlink( $tmp );
        if ( $written === false ) {
            error_log( '[CSC] Failed to write media recycle manifest (direct write also failed).' );
            return false;
        }
    }

    return true;
}

/**
 * Save a single attachment's complete data (post row + meta + files) into the
 * media recycle manifest so it can be fully restored later.
 */
function csc_media_recycle_save_attachment( int $id ): array {
    global $wpdb;
    $post = get_post( $id, ARRAY_A );
    if ( ! $post ) { return array( 'error' => 'Attachment post not found for ID ' . $id ); }

    $meta = $wpdb->get_results(
        $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id ),
        ARRAY_A
    );

    $file   = get_attached_file( $id );
    $upload = wp_upload_dir();
    $base   = trailingslashit( $upload['basedir'] );
    $recycle = csc_media_recycle_dir() . 'files/';

    // Collect all physical files: original + thumbnails
    $files_moved = array();
    $errors      = array();

    // Build list of all files for this attachment
    $all_files = array();
    if ( $file && file_exists( $file ) ) {
        $all_files[] = $file;
    }
    $attachment_meta = wp_get_attachment_metadata( $id );
    if ( is_array( $attachment_meta ) && ! empty( $attachment_meta['file'] ) ) {
        $dir = trailingslashit( $base . dirname( $attachment_meta['file'] ) );
        if ( ! empty( $attachment_meta['sizes'] ) && is_array( $attachment_meta['sizes'] ) ) {
            foreach ( $attachment_meta['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $thumb_path = $dir . $size_data['file'];
                    if ( file_exists( $thumb_path ) ) {
                        $all_files[] = $thumb_path;
                    }
                }
            }
        }
        // Scaled original backup (WP 5.3+)
        if ( ! empty( $attachment_meta['original_image'] ) ) {
            $orig_path = $dir . $attachment_meta['original_image'];
            if ( file_exists( $orig_path ) ) {
                $all_files[] = $orig_path;
            }
        }
    }

    $all_files = array_unique( $all_files );

    // Move each file to recycle dir
    foreach ( $all_files as $src_path ) {
        $rel  = str_replace( $base, '', $src_path );
        $dest = $recycle . $rel;
        if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
            $errors[] = 'Cannot create dir for: ' . $rel;
            continue;
        }
        if ( @rename( $src_path, $dest ) ) {
            $files_moved[] = $rel;
        } else {
            // Try copy+delete as fallback (cross-device move)
            if ( @copy( $src_path, $dest ) && @unlink( $src_path ) ) {
                $files_moved[] = $rel;
            } else {
                $errors[] = 'Failed to move: ' . $rel;
            }
        }
    }

    return array(
        'id'          => $id,
        'post'        => $post,
        'meta'        => $meta,
        'files_moved' => $files_moved,
        'errors'      => $errors,
    );
}

// ─── Chunked Move to Recycle — Step 1: build queue ───────────────────────────

add_action( 'wp_ajax_csc_img_start', 'csc_ajax_img_start' );
function csc_ajax_img_start() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $used  = csc_get_used_attachment_ids();
    $all   = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $queue = array();
    foreach ( $all as $id ) {
        if ( ! isset( $used[ $id ] ) ) { $queue[] = intval( $id ); }
    }

    set_transient( 'csc_img_queue', $queue, HOUR_IN_SECONDS );
    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  Found ' . count( $queue ) . ' unused attachments. Moving to recycle bin.' ) ),
    ) );
}

// Step 2: process a chunk — move to recycle instead of deleting
add_action( 'wp_ajax_csc_img_chunk', 'csc_ajax_img_chunk' );
function csc_ajax_img_chunk() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $queue = get_transient( 'csc_img_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired — please start again.' ); }

    $chunk = array_splice( $queue, 0, CSC_CHUNK_IMAGES );
    set_transient( 'csc_img_queue', $queue, HOUR_IN_SECONDS );

    // Load existing manifest
    if ( ! csc_media_recycle_ensure_dir() ) {
        wp_send_json_error( 'Cannot create media recycle directory.' );
    }
    $manifest = csc_media_recycle_read_manifest();

    $lines = array();
    foreach ( $chunk as $id ) {
        $title = get_the_title( $id );
        try {
            $result = csc_media_recycle_save_attachment( $id );
            if ( ! empty( $result['error'] ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ' — ' . $result['error'] );
                continue;
            }
            $file_count = count( $result['files_moved'] );
            $err_count  = count( $result['errors'] );

            // Store in manifest keyed by attachment ID
            $manifest[ (string) $id ] = array(
                'post'        => $result['post'],
                'meta'        => $result['meta'],
                'files_moved' => $result['files_moved'],
                'recycled_at' => current_time( 'mysql' ),
            );

            // Now remove the attachment record from the database
            // (force=true skips trash and deletes immediately, but we already moved files)
            wp_delete_attachment( $id, true );

            $msg = '  [RECYCLED] ID ' . $id . ' — ' . esc_html( $title ) . ' (' . $file_count . ' file(s))';
            if ( $err_count > 0 ) {
                $msg .= ' ⚠ ' . $err_count . ' error(s): ' . implode( '; ', $result['errors'] );
            }
            $lines[] = array( 'type' => 'deleted', 'text' => $msg );
        } catch ( Exception $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [EXCEPTION] ID ' . $id . ' — ' . $e->getMessage() );
        } catch ( Throwable $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [FATAL] ID ' . $id . ' — ' . $e->getMessage() );
        }
    }

    // Save updated manifest
    if ( ! csc_media_recycle_write_manifest( $manifest ) ) {
        $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] Failed to write media recycle manifest.' );
    }

    wp_send_json_success( array( 'remaining' => count( $queue ), 'lines' => $lines ) );
}

// Step 3: finish
add_action( 'wp_ajax_csc_img_finish', 'csc_ajax_img_finish' );
function csc_ajax_img_finish() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    delete_transient( 'csc_img_queue' );
    update_option( 'csc_last_img_cleanup', current_time( 'mysql' ) );
    $recycle_count = csc_media_recycle_count();
    wp_send_json_success( array(
        'lines' => array(
            array( 'type' => 'success', 'text' => 'Unused media moved to recycle bin.' ),
            array( 'type' => 'info',    'text' => '  ♻️ ' . $recycle_count . ' item(s) in media recycle bin.' ),
            array( 'type' => 'info',    'text' => '  Use Restore to put them back, or Permanently Delete to remove them.' ),
        ),
        'recycle' => $recycle_count,
    ) );
}

// ─── Media Recycle: Status ───────────────────────────────────────────────────

add_action( 'wp_ajax_csc_media_recycle_status', 'csc_ajax_media_recycle_status' );
function csc_ajax_media_recycle_status() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    wp_send_json_success( array( 'recycle' => csc_media_recycle_count() ) );
}

// ─── Media Recycle: Browse ───────────────────────────────────────────────────

add_action( 'wp_ajax_csc_media_recycle_browse', 'csc_ajax_media_recycle_browse' );
function csc_ajax_media_recycle_browse() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $manifest = csc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) {
        wp_send_json_success( array( 'files' => array(), 'total' => 0, 'total_size' => 0 ) );
        return;
    }

    $recycle    = csc_media_recycle_dir() . 'files/';
    $files      = array();
    $total_size = 0;

    foreach ( $manifest as $att_id => $entry ) {
        $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'Untitled';
        $size  = 0;
        foreach ( $entry['files_moved'] as $rel ) {
            $path = $recycle . $rel;
            if ( file_exists( $path ) ) { $size += filesize( $path ); }
        }
        $total_size += $size;
        $files[] = array(
            'id'        => $att_id,
            'name'      => $title,
            'file_count' => count( $entry['files_moved'] ),
            'size'      => $size,
            'size_fmt'  => size_format( $size ),
            'recycled'  => isset( $entry['recycled_at'] ) ? $entry['recycled_at'] : '',
        );
    }

    wp_send_json_success( array(
        'files'      => $files,
        'total'      => count( $files ),
        'total_size' => size_format( $total_size ),
    ) );
}

// ─── Media Recycle: Restore All ──────────────────────────────────────────────

add_action( 'wp_ajax_csc_media_restore', 'csc_ajax_media_restore' );
function csc_ajax_media_restore() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => '=== RESTORING MEDIA FROM RECYCLE BIN ===' );

    $manifest = csc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Media recycle bin is empty.' );
        wp_send_json_success( array( 'lines' => $lines, 'restored' => 0, 'recycle' => 0 ) );
        return;
    }

    $recycle  = csc_media_recycle_dir() . 'files/';
    $base     = trailingslashit( wp_upload_dir()['basedir'] );
    $restored = 0;
    $errors   = 0;

    foreach ( $manifest as $att_id => $entry ) {
        try {
            $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'ID ' . $att_id;

            // 1. Move files back
            $file_errors = array();
            foreach ( $entry['files_moved'] as $rel ) {
                $src  = $recycle . $rel;
                $dest = $base . $rel;
                if ( ! file_exists( $src ) ) {
                    $file_errors[] = 'Missing: ' . $rel;
                    continue;
                }
                if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
                    $file_errors[] = 'Cannot create dir for: ' . $rel;
                    continue;
                }
                if ( ! @rename( $src, $dest ) ) {
                    if ( ! ( @copy( $src, $dest ) && @unlink( $src ) ) ) {
                        $file_errors[] = 'Move failed: ' . $rel;
                    }
                }
            }

            // 2. Re-insert the attachment post
            $post_data = $entry['post'];
            unset( $post_data['ID'] ); // let WP assign a new ID
            $post_data['import_id'] = intval( $att_id ); // try to keep original ID
            $new_id = wp_insert_post( $post_data, true );

            if ( is_wp_error( $new_id ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $att_id . ' — ' . esc_html( $title ) . ': ' . $new_id->get_error_message() );
                $errors++;
                continue;
            }

            // 3. Restore meta rows
            if ( ! empty( $entry['meta'] ) ) {
                global $wpdb;
                foreach ( $entry['meta'] as $row ) {
                    $wpdb->insert( $wpdb->postmeta, array(
                        'post_id'    => $new_id,
                        'meta_key'   => $row['meta_key'],
                        'meta_value' => $row['meta_value'],
                    ) );
                }
            }

            $msg = '  [RESTORED] ID ' . $att_id . ' — ' . esc_html( $title );
            if ( ! empty( $file_errors ) ) {
                $msg .= ' ⚠ ' . implode( '; ', $file_errors );
            }
            $lines[] = array( 'type' => 'success', 'text' => $msg );
            unset( $manifest[ $att_id ] );
            $restored++;

        } catch ( Exception $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [EXCEPTION] ID ' . $att_id . ' — ' . $e->getMessage() );
            $errors++;
        } catch ( Throwable $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [FATAL] ID ' . $att_id . ' — ' . $e->getMessage() );
            $errors++;
        }
    }

    // Update or remove manifest
    if ( empty( $manifest ) ) {
        @unlink( csc_media_recycle_manifest() );
        csc_rmdir_recursive( csc_media_recycle_dir() );
    } else {
        csc_media_recycle_write_manifest( $manifest );
    }

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Restored ' . $restored . ' attachment(s).' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );
    wp_send_json_success( array( 'lines' => $lines, 'restored' => $restored, 'recycle' => count( $manifest ) ) );
}

// ─── Media Recycle: Restore Single ───────────────────────────────────────────

add_action( 'wp_ajax_csc_media_restore_single', 'csc_ajax_media_restore_single' );
function csc_ajax_media_restore_single() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $att_id = sanitize_text_field( $_POST['att_id'] ?? '' );
    if ( empty( $att_id ) ) { wp_send_json_error( 'No attachment ID specified.' ); }

    $manifest = csc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) { wp_send_json_error( 'Media recycle bin is empty.' ); }
    if ( ! isset( $manifest[ $att_id ] ) ) { wp_send_json_error( 'Attachment not found in recycle bin.' ); }

    $entry   = $manifest[ $att_id ];
    $recycle = csc_media_recycle_dir() . 'files/';
    $base    = trailingslashit( wp_upload_dir()['basedir'] );

    try {
        // Move files back
        foreach ( $entry['files_moved'] as $rel ) {
            $src  = $recycle . $rel;
            $dest = $base . $rel;
            if ( file_exists( $src ) ) {
                wp_mkdir_p( dirname( $dest ) );
                if ( ! @rename( $src, $dest ) ) {
                    @copy( $src, $dest ) && @unlink( $src );
                }
            }
        }

        // Re-insert post
        $post_data = $entry['post'];
        unset( $post_data['ID'] );
        $post_data['import_id'] = intval( $att_id );
        $new_id = wp_insert_post( $post_data, true );
        if ( is_wp_error( $new_id ) ) {
            wp_send_json_error( 'Failed to restore post: ' . $new_id->get_error_message() );
        }

        // Restore meta
        if ( ! empty( $entry['meta'] ) ) {
            global $wpdb;
            foreach ( $entry['meta'] as $row ) {
                $wpdb->insert( $wpdb->postmeta, array(
                    'post_id'    => $new_id,
                    'meta_key'   => $row['meta_key'],
                    'meta_value' => $row['meta_value'],
                ) );
            }
        }

        unset( $manifest[ $att_id ] );
        if ( empty( $manifest ) ) {
            @unlink( csc_media_recycle_manifest() );
            csc_rmdir_recursive( csc_media_recycle_dir() );
        } else {
            csc_media_recycle_write_manifest( $manifest );
        }

        $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'ID ' . $att_id;
        wp_send_json_success( array( 'restored' => esc_html( $title ), 'remaining' => count( $manifest ) ) );

    } catch ( Exception $e ) {
        wp_send_json_error( 'Exception: ' . $e->getMessage() );
    } catch ( Throwable $e ) {
        wp_send_json_error( 'Fatal: ' . $e->getMessage() );
    }
}

// ─── Media Recycle: Permanently Delete ───────────────────────────────────────

add_action( 'wp_ajax_csc_media_purge', 'csc_ajax_media_purge' );
function csc_ajax_media_purge() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => '=== PERMANENTLY DELETING MEDIA RECYCLE BIN ===' );

    $manifest = csc_media_recycle_read_manifest();
    if ( empty( $manifest ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Media recycle bin is empty.' );
        wp_send_json_success( array( 'lines' => $lines, 'deleted' => 0, 'recycle' => 0 ) );
        return;
    }
    $recycle  = csc_media_recycle_dir() . 'files/';
    $deleted  = 0;
    $errors   = 0;
    $freed    = 0;

    foreach ( $manifest as $att_id => $entry ) {
        try {
            $title = isset( $entry['post']['post_title'] ) ? $entry['post']['post_title'] : 'ID ' . $att_id;
            $file_deleted = 0;
            foreach ( $entry['files_moved'] as $rel ) {
                $path = $recycle . $rel;
                if ( file_exists( $path ) ) {
                    $freed += filesize( $path );
                    if ( @unlink( $path ) ) {
                        $file_deleted++;
                    } else {
                        $errors++;
                    }
                } else {
                    $file_deleted++; // already gone
                }
            }
            $lines[] = array( 'type' => 'deleted', 'text' => '  [DELETED] ID ' . $att_id . ' — ' . esc_html( $title ) . ' (' . $file_deleted . ' file(s))' );
            $deleted++;
        } catch ( Exception $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [EXCEPTION] ID ' . $att_id . ' — ' . $e->getMessage() );
            $errors++;
        } catch ( Throwable $e ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [FATAL] ID ' . $att_id . ' — ' . $e->getMessage() );
            $errors++;
        }
    }

    // Wipe the entire recycle directory
    csc_rmdir_recursive( csc_media_recycle_dir() );

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Permanently deleted ' . $deleted . ' attachment(s). Freed ' . size_format( $freed ) . '.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );
    wp_send_json_success( array( 'lines' => $lines, 'deleted' => $deleted, 'recycle' => 0 ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// ORPHAN FILE SCAN
// ═════════════════════════════════════════════════════════════════════════════

// ── Orphan helpers ────────────────────────────────────────────────────────────

function csc_recycle_dir(): string {
    return trailingslashit( wp_upload_dir()['basedir'] ) . '.csc-recycle/';
}

function csc_recycle_manifest(): string {
    return csc_recycle_dir() . 'manifest.json';
}

function csc_orphan_ext_sets(): array {
    return array(
        'all'       => array(),
        'images'    => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'svg' ),
        'documents' => array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'rtf', 'odt', 'ods', 'odp' ),
        'video'     => array( 'mp4', 'mov', 'avi', 'wmv', 'mkv', 'webm', 'flv', 'm4v' ),
        'audio'     => array( 'mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a', 'wma' ),
    );
}

function csc_get_orphan_files( string $type = 'all' ): array {
    $ext_sets   = csc_orphan_ext_sets();
    $exts       = isset( $ext_sets[ $type ] ) ? $ext_sets[ $type ] : array();
    return csc_scan_orphan_files_with_exts( $exts );
}

function csc_get_orphan_files_multi( array $types ): array {
    $ext_sets = csc_orphan_ext_sets();
    $exts = array();
    foreach ( $types as $type ) {
        if ( isset( $ext_sets[ $type ] ) ) {
            $exts = array_merge( $exts, $ext_sets[ $type ] );
        }
    }
    return csc_scan_orphan_files_with_exts( array_unique( $exts ) );
}

/**
 * Core orphan scanner shared by both single-type and multi-type functions.
 * Builds a whitelist of known files from: attachment metadata, thumbnail sizes,
 * AND any image/file URLs referenced in published post content.
 */
function csc_scan_orphan_files_with_exts( array $exts ): array {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $base       = trailingslashit( $upload_dir['basedir'] );
    $recycle    = csc_recycle_dir();

    // 1. Files registered in attachment metadata
    $db_files = array();
    foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'" ) as $f ) {
        $db_files[ $base . $f ] = true;
    }
    foreach ( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attachment_metadata'" ) as $raw ) {
        $data = maybe_unserialize( $raw );
        if ( ! is_array( $data ) || ! isset( $data['file'] ) ) { continue; }
        $dir = trailingslashit( $base . dirname( $data['file'] ) );
        if ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
            foreach ( $data['sizes'] as $sz ) {
                if ( isset( $sz['file'] ) ) { $db_files[ $dir . $sz['file'] ] = true; }
            }
        }
    }

    // 2. Files referenced by URL in published post content
    $contents = $wpdb->get_col(
        "SELECT post_content FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type NOT IN ('attachment','revision')
         AND post_content LIKE '%wp-content/uploads/%'"
    );
    foreach ( $contents as $c ) {
        if ( preg_match_all( '/wp-content\/uploads\/([^"\'<>\s\)]+)/i', $c, $m ) ) {
            foreach ( $m[1] as $rel ) {
                $rel = strtok( $rel, '?' ); // strip query strings
                $db_files[ $base . $rel ] = true;
            }
        }
    }

    // 3. Files referenced in widget options and theme mods
    $opts = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget_%' OR option_name LIKE 'theme_mods_%'" );
    foreach ( $opts as $v ) {
        if ( preg_match_all( '/wp-content\/uploads\/([^"\'<>\s\)]+)/i', $v, $m ) ) {
            foreach ( $m[1] as $rel ) {
                $rel = strtok( $rel, '?' );
                $db_files[ $base . $rel ] = true;
            }
        }
    }

    // 4. Scan filesystem for orphans
    $orphans = array();
    try {
        $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ) );
        foreach ( $iter as $file ) {
            if ( ! $file->isFile() ) { continue; }
            $path = $file->getRealPath();
            if ( strpos( $path, $recycle ) === 0 ) { continue; }
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            if ( ! empty( $exts ) && ! in_array( $ext, $exts, true ) ) { continue; }
            if ( ! isset( $db_files[ $path ] ) ) {
                $orphans[] = array( 'path' => $path, 'size' => $file->getSize() );
            }
        }
    } catch ( Exception $e ) {
        return array();
    }
    return $orphans;
}

function csc_recycle_count(): int {
    $manifest = csc_recycle_manifest();
    if ( ! file_exists( $manifest ) ) { return 0; }
    $data = json_decode( file_get_contents( $manifest ), true );
    return is_array( $data ) ? count( $data ) : 0;
}

// ── Scan ─────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_csc_scan_orphan_files', 'csc_ajax_scan_orphan_files' );
function csc_ajax_scan_orphan_files() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $raw_types  = sanitize_text_field( $_POST['file_type'] ?? '' );
    $ext_sets   = csc_orphan_ext_sets();
    if ( empty( $raw_types ) ) {
        wp_send_json_error( 'No file type selected. Please select at least one file type.' );
        return;
    }
    $selected_types = array_filter( array_map( 'sanitize_key', explode( ',', $raw_types ) ), fn($t) => array_key_exists( $t, $ext_sets ) );
    if ( empty( $selected_types ) ) {
        wp_send_json_error( 'Invalid file type selection.' );
        return;
    }
    $type_label = implode( ' + ', array_map( fn($t) => ucfirst($t), $selected_types ) );
    $orphans    = csc_get_orphan_files_multi( $selected_types );
    $base      = trailingslashit( wp_upload_dir()['basedir'] );
    $recycle_n = csc_recycle_count();
    $lines     = array();
    $lines[]   = array( 'type' => 'section', 'text' => 'Orphaned Files on Disk — ' . $type_label );

    if ( empty( $orphans ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  No orphaned files found.' );
    } else {
        // Split into three groups
        $group_trash   = array();
        $group_bak     = array();
        $group_regular = array();

        foreach ( $orphans as $o ) {
            $rel = str_replace( $base, '', $o['path'] );
            if ( strpos( $rel, 'wpmc-trash/' ) === 0 || strpos( $rel, '.trash/' ) === 0 ) {
                $group_trash[] = array_merge( $o, array( 'rel' => $rel ) );
            } elseif ( preg_match( '/\.bak\.[a-z0-9]+$/i', $rel ) ) {
                $group_bak[] = array_merge( $o, array( 'rel' => $rel ) );
            } else {
                $group_regular[] = array_merge( $o, array( 'rel' => $rel ) );
            }
        }

        $render_group = function( array $items, string $tag, string $label ) use ( &$lines ) {
            if ( empty( $items ) ) { return; }
            $size = array_sum( array_column( $items, 'size' ) );
            $lines[] = array( 'type' => 'section', 'text' => '  ── ' . $label . ' (' . count( $items ) . ' files, ' . size_format( $size ) . ')' );
            foreach ( $items as $o ) {
                $lines[] = array( 'type' => 'item', 'text' => '    [' . $tag . '] ' . $o['rel'] . ' (' . size_format( $o['size'] ) . ')' );
            }
        };

        $render_group( $group_trash,   'TRASH',  'Plugin Trash Folder' );
        $render_group( $group_bak,     'BACKUP', 'Backup Files (.bak)' );
        $render_group( $group_regular, 'ORPHAN', 'Unregistered Uploads' );

        $total_size = array_sum( array_column( $orphans, 'size' ) );
        $lines[] = array( 'type' => 'count', 'text' => '  Total: ' . count( $orphans ) . ' files — ' . size_format( $total_size ) . ' recoverable' );
    }

    if ( $recycle_n > 0 ) {
        $lines[] = array( 'type' => 'info', 'text' => '  ♻️ Recycle bin: ' . $recycle_n . ' file(s) awaiting permanent deletion or restore.' );
    }

    wp_send_json_success( array( 'lines' => $lines, 'found' => count( $orphans ), 'recycle' => $recycle_n ) );
}

// ── Move to Recycle ───────────────────────────────────────────────────────────

add_action( 'wp_ajax_csc_recycle_orphan_files', 'csc_ajax_recycle_orphan_files' );
function csc_ajax_recycle_orphan_files() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $raw_types = sanitize_text_field( $_POST['file_type'] ?? '' );
    $ext_sets  = csc_orphan_ext_sets();
    if ( empty( $raw_types ) ) {
        wp_send_json_error( 'No file type selected.' );
        return;
    }
    $selected_types = array_filter( array_map( 'sanitize_key', explode( ',', $raw_types ) ), fn($t) => array_key_exists( $t, $ext_sets ) );
    if ( empty( $selected_types ) ) { wp_send_json_error( 'Invalid file type.' ); return; }
    $orphans = csc_get_orphan_files_multi( $selected_types );
    $recycle = csc_recycle_dir();
    $lines   = array();
    $lines[] = array( 'type' => 'section', 'text' => '=== MOVING ORPHANS TO RECYCLE BIN ===' );

    if ( empty( $orphans ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  No orphaned files found.' );
        wp_send_json_success( array( 'lines' => $lines, 'moved' => 0 ) );
        return;
    }

    if ( ! wp_mkdir_p( $recycle ) ) {
        wp_send_json_error( 'Could not create recycle directory: ' . $recycle );
        return;
    }

    // Load existing manifest if recycle bin already has files
    $manifest_path = csc_recycle_manifest();
    $manifest = array();
    if ( file_exists( $manifest_path ) ) {
        $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array();
    }

    $moved = 0;
    $errors = 0;
    $base = trailingslashit( wp_upload_dir()['basedir'] );

    foreach ( $orphans as $o ) {
        $rel         = str_replace( $base, '', $o['path'] );
        $dest        = $recycle . $rel;
        $dest_dir    = dirname( $dest );

        if ( ! wp_mkdir_p( $dest_dir ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  Could not create dir for: ' . $rel );
            $errors++;
            continue;
        }

        if ( rename( $o['path'], $dest ) ) {
            $manifest[ $rel ] = $o['path'];
            $lines[] = array( 'type' => 'deleted', 'text' => '  [RECYCLED] ' . $rel . ' (' . size_format( $o['size'] ) . ')' );
            $moved++;
        } else {
            $lines[] = array( 'type' => 'error', 'text' => '  Failed to move: ' . $rel );
            $errors++;
        }
    }

    file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Moved ' . $moved . ' file(s) to recycle bin.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );
    $lines[] = array( 'type' => 'info',    'text' => '  Files are in: wp-content/uploads/.csc-recycle/' );
    $lines[] = array( 'type' => 'info',    'text' => '  Use Restore to put them back, or Permanently Delete to wipe them.' );

    wp_send_json_success( array( 'lines' => $lines, 'moved' => $moved, 'recycle' => count( $manifest ) ) );
}

// ── Restore from Recycle ──────────────────────────────────────────────────────

add_action( 'wp_ajax_csc_restore_orphan_files', 'csc_ajax_restore_orphan_files' );
function csc_ajax_restore_orphan_files() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $manifest_path = csc_recycle_manifest();
    $lines         = array();
    $lines[]       = array( 'type' => 'section', 'text' => '=== RESTORING FILES FROM RECYCLE BIN ===' );

    if ( ! file_exists( $manifest_path ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Recycle bin is empty — nothing to restore.' );
        wp_send_json_success( array( 'lines' => $lines, 'restored' => 0 ) );
        return;
    }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array();
    if ( empty( $manifest ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Recycle bin is empty — nothing to restore.' );
        wp_send_json_success( array( 'lines' => $lines, 'restored' => 0 ) );
        return;
    }

    $recycle  = csc_recycle_dir();
    $restored = 0;
    $errors   = 0;

    foreach ( $manifest as $rel => $original_path ) {
        $recycle_path = $recycle . $rel;
        if ( ! file_exists( $recycle_path ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  Missing from recycle bin: ' . $rel );
            $errors++;
            continue;
        }

        $dest_dir = dirname( $original_path );
        if ( ! wp_mkdir_p( $dest_dir ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  Could not create dir for: ' . $rel );
            $errors++;
            continue;
        }

        if ( rename( $recycle_path, $original_path ) ) {
            $lines[] = array( 'type' => 'success', 'text' => '  [RESTORED] ' . $rel );
            $restored++;
            unset( $manifest[ $rel ] );
        } else {
            $lines[] = array( 'type' => 'error', 'text' => '  Failed to restore: ' . $rel );
            $errors++;
        }
    }

    // Update or remove manifest
    if ( empty( $manifest ) ) {
        @unlink( $manifest_path );
        // Clean up empty recycle dirs
        csc_rmdir_recursive( $recycle );
    } else {
        file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
    }

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Restored ' . $restored . ' file(s) to original locations.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );

    wp_send_json_success( array( 'lines' => $lines, 'restored' => $restored, 'recycle' => count( $manifest ) ) );
}

// ── Permanently Delete Recycle Bin ────────────────────────────────────────────

add_action( 'wp_ajax_csc_purge_orphan_files', 'csc_ajax_purge_orphan_files' );
function csc_ajax_purge_orphan_files() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $manifest_path = csc_recycle_manifest();
    $lines         = array();
    $lines[]       = array( 'type' => 'section', 'text' => '=== PERMANENTLY DELETING RECYCLE BIN ===' );

    if ( ! file_exists( $manifest_path ) ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Recycle bin is empty — nothing to delete.' );
        wp_send_json_success( array( 'lines' => $lines, 'deleted' => 0 ) );
        return;
    }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array();
    $recycle  = csc_recycle_dir();
    $deleted  = 0;
    $errors   = 0;
    $freed    = 0;

    foreach ( $manifest as $rel => $original_path ) {
        $recycle_path = $recycle . $rel;
        if ( file_exists( $recycle_path ) ) {
            $freed += filesize( $recycle_path );
            if ( @unlink( $recycle_path ) ) {
                $lines[] = array( 'type' => 'deleted', 'text' => '  [DELETED] ' . $rel );
                $deleted++;
            } else {
                $lines[] = array( 'type' => 'error', 'text' => '  Failed to delete: ' . $rel );
                $errors++;
            }
        } else {
            $lines[] = array( 'type' => 'info', 'text' => '  Already gone: ' . $rel );
            $deleted++;
        }
    }

    csc_rmdir_recursive( $recycle );

    $lines[] = array( 'type' => 'success', 'text' => '  ✅ Permanently deleted ' . $deleted . ' file(s). Freed ' . size_format( $freed ) . '.' . ( $errors ? ' ' . $errors . ' error(s).' : '' ) );

    wp_send_json_success( array( 'lines' => $lines, 'deleted' => $deleted, 'recycle' => 0 ) );
}

// ── Recycle bin status (for page load) ───────────────────────────────────────

add_action( 'wp_ajax_csc_recycle_status', 'csc_ajax_recycle_status' );

// ── Browse Recycle Bin ──────────────────────────────────────────────────────

add_action( 'wp_ajax_csc_recycle_browse', 'csc_ajax_recycle_browse' );
function csc_ajax_recycle_browse() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $manifest_path = csc_recycle_manifest();
    if ( ! file_exists( $manifest_path ) ) {
        wp_send_json_success( array( 'files' => array(), 'total' => 0, 'total_size' => 0 ) );
        return;
    }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array();
    $recycle  = csc_recycle_dir();
    $files    = array();
    $total_size = 0;

    foreach ( $manifest as $rel => $original_path ) {
        $recycle_path = $recycle . $rel;
        $size = file_exists( $recycle_path ) ? filesize( $recycle_path ) : 0;
        $total_size += $size;
        $files[] = array(
            'rel'      => $rel,
            'name'     => basename( $rel ),
            'original' => $original_path,
            'size'     => $size,
            'size_fmt' => size_format( $size ),
            'date'     => dirname( $rel ),
        );
    }

    wp_send_json_success( array(
        'files'      => $files,
        'total'      => count( $files ),
        'total_size' => size_format( $total_size ),
    ) );
}

// ── Restore Single File from Recycle Bin ─────────────────────────────────────

add_action( 'wp_ajax_csc_recycle_restore_single', 'csc_ajax_recycle_restore_single' );
function csc_ajax_recycle_restore_single() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $rel = sanitize_text_field( $_POST['rel'] ?? '' );
    if ( empty( $rel ) ) { wp_send_json_error( 'No file specified.' ); }

    $manifest_path = csc_recycle_manifest();
    if ( ! file_exists( $manifest_path ) ) { wp_send_json_error( 'Recycle bin is empty.' ); }

    $manifest = json_decode( file_get_contents( $manifest_path ), true ) ?: array();
    if ( ! isset( $manifest[ $rel ] ) ) { wp_send_json_error( 'File not found in manifest.' ); }

    $original_path = $manifest[ $rel ];
    $recycle_path  = csc_recycle_dir() . $rel;

    if ( ! file_exists( $recycle_path ) ) { wp_send_json_error( 'File missing from recycle bin.' ); }

    $dest_dir = dirname( $original_path );
    if ( ! wp_mkdir_p( $dest_dir ) ) { wp_send_json_error( 'Could not create destination directory.' ); }

    if ( ! rename( $recycle_path, $original_path ) ) { wp_send_json_error( 'Failed to move file.' ); }

    unset( $manifest[ $rel ] );
    if ( empty( $manifest ) ) {
        @unlink( $manifest_path );
        csc_rmdir_recursive( csc_recycle_dir() );
    } else {
        file_put_contents( $manifest_path, json_encode( $manifest, JSON_PRETTY_PRINT ) );
    }

    wp_send_json_success( array(
        'restored' => basename( $rel ),
        'remaining' => count( $manifest ),
    ) );
}
function csc_ajax_recycle_status() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    wp_send_json_success( array( 'recycle' => csc_recycle_count() ) );
}

// ── Recursive directory removal ───────────────────────────────────────────────

function csc_rmdir_recursive( string $dir ): void {
    if ( ! is_dir( $dir ) ) { return; }
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iter as $f ) {
            $f->isDir() ? @rmdir( $f->getRealPath() ) : @unlink( $f->getRealPath() );
        }
    } catch ( Exception $e ) {}
    @rmdir( $dir );
}

// ═════════════════════════════════════════════════════════════════════════════
// IMAGE OPTIMISATION
// ═════════════════════════════════════════════════════════════════════════════

// Dry run scan
add_action( 'wp_ajax_csc_scan_optimise', 'csc_ajax_scan_optimise' );
function csc_ajax_scan_optimise() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $max_w       = intval( get_option( 'csc_img_max_width',  1920 ) );
    $max_h       = intval( get_option( 'csc_img_max_height', 1080 ) );
    $quality     = intval( get_option( 'csc_img_quality',    82 ) );
    $convert_png = get_option( 'csc_convert_png_to_jpg', '0' ) === '1';

    $lines = array();
    $lines[] = array( 'type' => 'section', 'text' => 'Image Optimisation Scan — max ' . $max_w . 'x' . $max_h . 'px · JPEG quality ' . $quality );

    $attachments = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png' ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $lines[] = array( 'type' => 'info', 'text' => '  Total JPEG/PNG attachments: ' . count( $attachments ) );

    $needs_work  = 0;
    $total_saved = 0;

    foreach ( $attachments as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) { continue; }
        $mime     = mime_content_type( $file );
        $size_now = filesize( $file );
        $dims     = @getimagesize( $file );
        if ( ! $dims ) { continue; }
        list( $w, $h ) = $dims;

        $flags  = array();
        $saving = 0;

        if ( $w > $max_w || $h > $max_h ) { $flags[] = 'oversized (' . $w . 'x' . $h . ')'; }

        if ( in_array( $mime, array( 'image/jpeg', 'image/jpg' ), true ) ) {
            $est = (int) ( $size_now * 0.22 );
            if ( $est > 1024 ) { $flags[] = 'recompressible (~' . size_format( $est ) . ' saving)'; $saving += $est; }
        }

        if ( $convert_png && $mime === 'image/png' ) {
            $est = (int) ( $size_now * 0.55 );
            $flags[] = 'PNG→JPEG (~' . size_format( $est ) . ' saving)';
            $saving += $est;
        }

        if ( ! empty( $flags ) ) {
            $needs_work++;
            $total_saved += $saving;
            // Build label: title.ext so the file type is always visible
            $ext   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            $label = esc_html( get_the_title( $id ) );
            if ( $ext ) { $label .= '.' . $ext; }
            $lines[] = array( 'type' => 'item', 'text' => '  [OPTIMISE] ID ' . $id . ' — ' . $label . ' (' . size_format( $size_now ) . ') — ' . implode( ', ', $flags ) );
        }
    }

    $lines[] = array( 'type' => 'count', 'text' => '  Images to optimise: ' . $needs_work );
    $lines[] = array( 'type' => 'count', 'text' => '  Estimated total saving: ' . size_format( $total_saved ) );
    if ( $convert_png ) {
        $lines[] = array( 'type' => 'info', 'text' => '  Note: PNG→JPEG conversion is ON — all PNGs above will be converted.' );
    }
    wp_send_json_success( $lines );
}

// Chunked run — Step 1: build queue
add_action( 'wp_ajax_csc_optimise_start', 'csc_ajax_optimise_start' );
function csc_ajax_optimise_start() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $max_w       = intval( get_option( 'csc_img_max_width',  1920 ) );
    $max_h       = intval( get_option( 'csc_img_max_height', 1080 ) );
    $convert_png = get_option( 'csc_convert_png_to_jpg', '0' ) === '1';

    $all   = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => array( 'image/jpeg', 'image/jpg', 'image/png' ), 'posts_per_page' => -1, 'fields' => 'ids' ) );
    $queue = array();

    foreach ( $all as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) { continue; }
        $mime = mime_content_type( $file );
        $dims = @getimagesize( $file );
        if ( ! $dims ) { continue; }
        list( $w, $h ) = $dims;

        $needs = false;
        if ( $w > $max_w || $h > $max_h )                                         { $needs = true; }
        if ( in_array( $mime, array( 'image/jpeg', 'image/jpg' ), true ) )         { $needs = true; }
        if ( $convert_png && $mime === 'image/png' )                               { $needs = true; }

        if ( $needs ) { $queue[] = intval( $id ); }
    }

    set_transient( 'csc_optimise_queue', $queue, 2 * HOUR_IN_SECONDS );
    set_transient( 'csc_optimise_saved', 0,       2 * HOUR_IN_SECONDS );
    set_transient( 'csc_optimise_count', 0,       2 * HOUR_IN_SECONDS );

    wp_send_json_success( array(
        'total'     => count( $queue ),
        'remaining' => count( $queue ),
        'lines'     => array( array( 'type' => 'info', 'text' => '  ' . count( $queue ) . ' images queued. Processing ' . CSC_CHUNK_OPTIMISE . ' per request.' ) ),
    ) );
}

// Step 2: process a chunk
add_action( 'wp_ajax_csc_optimise_chunk', 'csc_ajax_optimise_chunk' );
function csc_ajax_optimise_chunk() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $queue = get_transient( 'csc_optimise_queue' );
    if ( ! is_array( $queue ) ) { wp_send_json_error( 'Session expired — please start again.' ); }

    $max_w       = intval( get_option( 'csc_img_max_width',  1920 ) );
    $max_h       = intval( get_option( 'csc_img_max_height', 1080 ) );
    $quality     = intval( get_option( 'csc_img_quality',    82 ) );
    $convert_png = get_option( 'csc_convert_png_to_jpg', '0' ) === '1';

    $chunk       = array_splice( $queue, 0, CSC_CHUNK_OPTIMISE );
    $total_saved = (int) get_transient( 'csc_optimise_saved' );
    $total_count = (int) get_transient( 'csc_optimise_count' );

    set_transient( 'csc_optimise_queue', $queue, 2 * HOUR_IN_SECONDS );

    $lines = array();

    foreach ( $chunk as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            $lines[] = array( 'type' => 'info', 'text' => '  Skipped ID ' . $id . ' — file not found.' );
            continue;
        }

        $mime     = mime_content_type( $file );
        $size_old = filesize( $file );
        $title    = get_the_title( $id );
        $dims     = @getimagesize( $file );
        if ( ! $dims ) {
            $lines[] = array( 'type' => 'info', 'text' => '  Skipped ID ' . $id . ' — cannot read image dimensions.' );
            continue;
        }
        list( $w, $h ) = $dims;

        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ': ' . $editor->get_error_message() );
            continue;
        }

        $editor->set_quality( $quality );

        if ( $w > $max_w || $h > $max_h ) {
            $editor->resize( $max_w, $max_h, false );
        }

        // PNG to JPEG conversion path
        if ( $convert_png && $mime === 'image/png' ) {
            $new_file = preg_replace( '/\.png$/i', '.jpg', $file );
            $result   = $editor->save( $new_file, 'image/jpeg' );
            if ( is_wp_error( $result ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ' PNG→JPEG: ' . $result->get_error_message() );
                continue;
            }
            // WP image editor may save to a different filename with dimensions appended.
            // If so, rename back to the intended filename to preserve URL integrity.
            $actual_path = $result['path'];
            if ( $actual_path !== $new_file ) {
                @rename( $actual_path, $new_file );
            }
            @unlink( $file );
            update_attached_file( $id, $new_file );
            $meta = wp_generate_attachment_metadata( $id, $new_file );
            wp_update_attachment_metadata( $id, $meta );
            wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/jpeg' ) );
            csc_update_image_references( $id, $file, $new_file );
            $size_new = file_exists( $new_file ) ? filesize( $new_file ) : 0;
        } else {
            // Recompress / resize in place — must overwrite original, not create a new file.
            $result = $editor->save( $file );
            if ( is_wp_error( $result ) ) {
                $lines[] = array( 'type' => 'error', 'text' => '  [ERROR] ID ' . $id . ': ' . $result->get_error_message() );
                continue;
            }
            // WP image editor appends dimensions to the filename (e.g. photo-1024x768.jpg).
            // This breaks all existing image URLs in posts. Rename back to the original.
            $actual_path = $result['path'];
            if ( $actual_path !== $file ) {
                @unlink( $file );
                @rename( $actual_path, $file );
            }
            $meta = wp_generate_attachment_metadata( $id, $file );
            wp_update_attachment_metadata( $id, $meta );
            $size_new = file_exists( $file ) ? filesize( $file ) : $size_old;
        }

        $saved        = max( 0, $size_old - $size_new );
        $total_saved += $saved;
        $total_count++;

        $lines[] = array( 'type' => 'deleted', 'text' => '  [OPTIMISED] ID ' . $id . ' — ' . esc_html( $title ) . ' ' . size_format( $size_old ) . ' → ' . size_format( $size_new ) . ' (saved ' . size_format( $saved ) . ')' );
    }

    set_transient( 'csc_optimise_saved', $total_saved, 2 * HOUR_IN_SECONDS );
    set_transient( 'csc_optimise_count', $total_count, 2 * HOUR_IN_SECONDS );

    wp_send_json_success( array( 'remaining' => count( $queue ), 'total_saved' => $total_saved, 'lines' => $lines ) );
}

// Step 3: finish
add_action( 'wp_ajax_csc_optimise_finish', 'csc_ajax_optimise_finish' );
function csc_ajax_optimise_finish() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $total_saved = (int) get_transient( 'csc_optimise_saved' );
    $total_count = (int) get_transient( 'csc_optimise_count' );

    delete_transient( 'csc_optimise_queue' );
    delete_transient( 'csc_optimise_saved' );
    delete_transient( 'csc_optimise_count' );

    update_option( 'csc_last_img_optimise', current_time( 'mysql' ) );
    wp_send_json_success( array( 'lines' => array(
        array( 'type' => 'count',   'text' => '  Images processed: ' . $total_count ),
        array( 'type' => 'count',   'text' => '  Total disk space saved: ' . size_format( $total_saved ) ),
        array( 'type' => 'success', 'text' => 'Image optimisation complete.' ),
    ) ) );
}

// Helper: update image URL references after PNG → JPEG conversion
function csc_update_image_references( $attachment_id, $old_file, $new_file ) {
    global $wpdb;
    $upload_dir = wp_upload_dir();
    $base       = trailingslashit( $upload_dir['basedir'] );
    $old_url    = $upload_dir['baseurl'] . '/' . ltrim( str_replace( $base, '', $old_file ), '/' );
    $new_url    = $upload_dir['baseurl'] . '/' . ltrim( str_replace( $base, '', $new_file ), '/' );

    $wpdb->query( $wpdb->prepare(
        "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
        $old_url, $new_url, '%' . $wpdb->esc_like( basename( $old_file ) ) . '%'
    ) );
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET guid = %s WHERE ID = %d", $new_url, $attachment_id ) );
}

// ═════════════════════════════════════════════════════════════════════════════
// PNG TO JPEG CONVERTER
// ═════════════════════════════════════════════════════════════════════════════

function csc_get_cspj_chunk_mb() {
    $val = floatval( get_option( CSPJ_OPTION_CHUNK_MB, CSPJ_DEFAULT_CHUNK_MB ) );
    if ( $val <= 0 ) { $val = CSPJ_DEFAULT_CHUNK_MB; }
    return max( 0.25, min( 1.95, $val ) );
}

function csc_get_cspj_server_max_mb() {
    $parse = function( $val ) {
        $val  = trim( $val );
        $last = strtolower( substr( $val, -1 ) );
        $num  = intval( $val );
        switch ( $last ) {
            case 'g': $num *= 1073741824; break;
            case 'm': $num *= 1048576;    break;
            case 'k': $num *= 1024;       break;
        }
        return $num;
    };
    $upload = $parse( ini_get( 'upload_max_filesize' ) );
    $post   = $parse( ini_get( 'post_max_size' ) );
    return intval( floor( min( $upload, $post ) / 1048576 ) );
}

function csc_cspj_chunk_root_dir() {
    $u = wp_upload_dir();
    return trailingslashit( $u['basedir'] ) . 'cspj-chunks';
}

function csc_cspj_chunk_dir( $upload_id ) {
    return trailingslashit( csc_cspj_chunk_root_dir() ) . sanitize_file_name( $upload_id );
}

function csc_cspj_delete_dir( $dir ) {
    if ( ! file_exists( $dir ) ) { return; }
    $it    = new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        $file->isDir() ? @rmdir( $file->getRealPath() ) : @unlink( $file->getRealPath() );
    }
    @rmdir( $dir );
}

function csc_cspj_resolve_dimensions( $size, $ow, $oh, $cw, $ch, $constrain ) {
    if ( $size === 'original' ) return array( $ow, $oh );
    if ( $size === 'custom' ) {
        $w = $cw > 0 ? $cw : $ow;
        $h = $ch > 0 ? $ch : $oh;
        if ( $constrain && $cw > 0 && $ch === 0 ) $h = intval( $oh * ( $w / $ow ) );
        if ( $constrain && $ch > 0 && $cw === 0 ) $w = intval( $ow * ( $h / $oh ) );
        return array( max( 1, $w ), max( 1, $h ) );
    }
    $parts = explode( 'x', $size );
    if ( count( $parts ) === 2 ) return array( intval( $parts[0] ), intval( $parts[1] ) );
    return array( $ow, $oh );
}

function csc_cspj_convert_png_to_jpeg( $png_path, $quality, $size, $custom_w, $custom_h, $constrain, $original_name ) {
    $src = @imagecreatefrompng( $png_path );
    if ( ! $src ) {
        return new WP_Error( 'cspj_decode', 'GD failed to decode the PNG. The file may be corrupt or use an unsupported subtype (e.g. 16 bit).' );
    }
    $orig_w = imagesx( $src );
    $orig_h = imagesy( $src );
    list( $tw, $th ) = csc_cspj_resolve_dimensions( $size, $orig_w, $orig_h, $custom_w, $custom_h, $constrain );

    if ( $tw !== $orig_w || $th !== $orig_h ) {
        $dst = imagecreatetruecolor( $tw, $th );
        imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
        imagecopyresampled( $dst, $src, 0, 0, 0, 0, $tw, $th, $orig_w, $orig_h );
        imagedestroy( $src );
        $src = $dst;
    } else {
        $dst = imagecreatetruecolor( $orig_w, $orig_h );
        imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
        imagecopy( $dst, $src, 0, 0, 0, 0, $orig_w, $orig_h );
        imagedestroy( $src );
        $src = $dst;
    }

    $upload_dir = wp_upload_dir();
    $out_dir    = trailingslashit( $upload_dir['basedir'] ) . 'cspj-converted';
    wp_mkdir_p( $out_dir );

    $base = pathinfo( $original_name, PATHINFO_FILENAME );
    $base = sanitize_file_name( $base );
    if ( $base === '' ) { $base = 'converted'; }

    $name     = $base . '-' . wp_generate_password( 6, false, false ) . '.jpg';
    $out_path = trailingslashit( $out_dir ) . $name;

    if ( ! imagejpeg( $src, $out_path, $quality ) ) {
        imagedestroy( $src );
        return new WP_Error( 'cspj_encode', 'Failed to encode JPEG.' );
    }
    imagedestroy( $src );

    $url = trailingslashit( $upload_dir['baseurl'] ) . 'cspj-converted/' . $name;
    return array(
        'url'    => $url,
        'name'   => $name,
        'path'   => $out_path,
        'width'  => $tw,
        'height' => $th,
        'size'   => size_format( filesize( $out_path ) ),
    );
}

// AJAX: Save CSPJ chunk size setting
add_action( 'wp_ajax_csc_pj_save_settings', 'csc_ajax_cspj_save_settings' );
function csc_ajax_cspj_save_settings() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }
    $chunk_mb = floatval( $_POST['chunk_mb'] ?? CSPJ_DEFAULT_CHUNK_MB );
    if ( $chunk_mb <= 0 ) { $chunk_mb = CSPJ_DEFAULT_CHUNK_MB; }
    $chunk_mb = max( 0.25, min( 1.95, $chunk_mb ) );
    update_option( CSPJ_OPTION_CHUNK_MB, $chunk_mb );
    wp_send_json_success( array( 'chunk_mb' => $chunk_mb, 'server_max' => csc_get_cspj_server_max_mb() ) );
}

// AJAX: Chunked upload — start session
add_action( 'wp_ajax_csc_pj_chunk_start', 'csc_ajax_cspj_chunk_start' );
function csc_ajax_cspj_chunk_start() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $filename   = sanitize_file_name( $_POST['filename'] ?? '' );
    $total_size = intval( $_POST['total_size'] ?? 0 );
    $total      = intval( $_POST['total_chunks'] ?? 0 );

    if ( $filename === '' || $total_size <= 0 || $total <= 0 ) {
        wp_send_json_error( 'Invalid upload session parameters.' );
    }
    if ( $total_size > CSPJ_MAX_TOTAL_MB * 1048576 ) {
        wp_send_json_error( 'File exceeds the maximum allowed size of ' . CSPJ_MAX_TOTAL_MB . ' MB.' );
    }

    $upload_id = wp_generate_uuid4();
    $dir       = csc_cspj_chunk_dir( $upload_id );
    wp_mkdir_p( $dir );

    $meta = array(
        'filename'     => $filename,
        'total_size'   => $total_size,
        'total_chunks' => $total,
        'created'      => time(),
        'user_id'      => get_current_user_id(),
    );
    file_put_contents( trailingslashit( $dir ) . 'meta.json', wp_json_encode( $meta ) );
    wp_send_json_success( array( 'upload_id' => $upload_id ) );
}

// AJAX: Chunked upload — receive a chunk
add_action( 'wp_ajax_csc_pj_chunk_upload', 'csc_ajax_cspj_chunk_upload' );
function csc_ajax_cspj_chunk_upload() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $upload_id = sanitize_text_field( $_POST['upload_id'] ?? '' );
    $index     = intval( $_POST['chunk_index'] ?? -1 );
    $total     = intval( $_POST['total_chunks'] ?? 0 );

    if ( $upload_id === '' || $index < 0 || $total <= 0 ) {
        wp_send_json_error( 'Invalid chunk parameters.' );
    }
    if ( empty( $_FILES['chunk'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
        wp_send_json_error( 'No chunk received by the server.' );
    }

    $dir = csc_cspj_chunk_dir( $upload_id );
    if ( ! file_exists( $dir ) ) {
        wp_send_json_error( 'Upload session not found (expired or invalid).' );
    }

    $chunk_mb    = csc_get_cspj_chunk_mb();
    $chunk_bytes = intval( floor( $chunk_mb * 1048576 ) );
    if ( $_FILES['chunk']['size'] > $chunk_bytes + 4096 ) {
        wp_send_json_error( 'Chunk exceeds configured chunk size of ' . $chunk_mb . ' MB.' );
    }

    $part = trailingslashit( $dir ) . sprintf( 'chunk-%06d.part', $index );
    if ( ! move_uploaded_file( $_FILES['chunk']['tmp_name'], $part ) ) {
        wp_send_json_error( 'Server failed to write chunk to disk.' );
    }
    wp_send_json_success( array( 'index' => $index ) );
}

// AJAX: Chunked upload — finish and convert
add_action( 'wp_ajax_csc_pj_chunk_finish', 'csc_ajax_cspj_chunk_finish' );
function csc_ajax_cspj_chunk_finish() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $upload_id  = sanitize_text_field( $_POST['upload_id'] ?? '' );
    $quality    = max( 1, min( 100, intval( $_POST['quality'] ?? 90 ) ) );
    $size       = sanitize_text_field( $_POST['size'] ?? 'original' );
    $custom_w   = intval( $_POST['custom_w'] ?? 0 );
    $custom_h   = intval( $_POST['custom_h'] ?? 0 );
    $constrain  = isset( $_POST['constrain'] ) && $_POST['constrain'] === '1';

    $dir       = csc_cspj_chunk_dir( $upload_id );
    $meta_path = trailingslashit( $dir ) . 'meta.json';
    if ( $upload_id === '' || ! file_exists( $meta_path ) ) {
        wp_send_json_error( 'Upload session not found (expired or invalid).' );
    }

    $meta = json_decode( file_get_contents( $meta_path ), true );
    if ( empty( $meta['total_chunks'] ) ) {
        wp_send_json_error( 'Upload session metadata missing.' );
    }
    if ( intval( $meta['user_id'] ) !== get_current_user_id() ) {
        wp_send_json_error( 'Upload session owner mismatch.' );
    }

    $total = intval( $meta['total_chunks'] );
    for ( $i = 0; $i < $total; $i++ ) {
        $part = trailingslashit( $dir ) . sprintf( 'chunk-%06d.part', $i );
        if ( ! file_exists( $part ) ) {
            wp_send_json_error( 'Missing chunk ' . $i . ' of ' . $total . '.' );
        }
    }

    $assembled = trailingslashit( $dir ) . 'assembled.png';
    $out = fopen( $assembled, 'wb' );
    if ( ! $out ) { wp_send_json_error( 'Failed to create assembled file.' ); }

    for ( $i = 0; $i < $total; $i++ ) {
        $part = trailingslashit( $dir ) . sprintf( 'chunk-%06d.part', $i );
        $in   = fopen( $part, 'rb' );
        if ( ! $in ) { fclose( $out ); wp_send_json_error( 'Failed to read chunk ' . $i . '.' ); }
        stream_copy_to_stream( $in, $out );
        fclose( $in );
    }
    fclose( $out );

    $finfo = finfo_open( FILEINFO_MIME_TYPE );
    $mime  = finfo_file( $finfo, $assembled );
    finfo_close( $finfo );
    if ( $mime !== 'image/png' ) {
        csc_cspj_delete_dir( $dir );
        wp_send_json_error( 'Assembled file is not a valid PNG (detected: ' . esc_html( $mime ) . ').' );
    }

    $result = csc_cspj_convert_png_to_jpeg( $assembled, $quality, $size, $custom_w, $custom_h, $constrain, $meta['filename'] );
    csc_cspj_delete_dir( $dir );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    // Track conversions
    $count = intval( get_option( 'csc_total_png_conversions', 0 ) );
    update_option( 'csc_total_png_conversions', $count + 1 );

    wp_send_json_success( $result );
}

// AJAX: Add converted file to Media Library
add_action( 'wp_ajax_csc_pj_add_to_library', 'csc_ajax_cspj_add_to_library' );
function csc_ajax_cspj_add_to_library() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $path     = sanitize_text_field( $_POST['path']     ?? '' );
    $url      = esc_url_raw(         $_POST['url']      ?? '' );
    $new_name = sanitize_text_field( $_POST['new_name'] ?? '' );

    if ( ! file_exists( $path ) ) { wp_send_json_error( 'File not found on disk.' ); }

    $upload_dir = wp_upload_dir();
    if ( strpos( $path, $upload_dir['basedir'] ) !== 0 ) {
        wp_send_json_error( 'Invalid file path.' );
    }

    $final_path = $path;
    $final_url  = $url;

    if ( $new_name !== '' ) {
        $clean    = sanitize_file_name( $new_name );
        $clean    = preg_replace( '/\.jpe?g$/i', '', $clean ) . '.jpg';
        $dir      = trailingslashit( dirname( $path ) );
        $proposed = $dir . $clean;

        if ( file_exists( $proposed ) && $proposed !== $path ) {
            $base    = pathinfo( $clean, PATHINFO_FILENAME );
            $counter = 1;
            while ( file_exists( $dir . $base . '-' . $counter . '.jpg' ) ) { $counter++; }
            $clean    = $base . '-' . $counter . '.jpg';
            $proposed = $dir . $clean;
        }

        if ( $proposed !== $path ) {
            if ( ! rename( $path, $proposed ) ) {
                wp_send_json_error( 'Could not rename the file on disk.' );
            }
            $final_path = $proposed;
            $final_url  = trailingslashit( $upload_dir['url'] ) . $clean;
        }
    }

    $title      = pathinfo( $final_path, PATHINFO_FILENAME );
    $attachment  = array(
        'post_mime_type' => 'image/jpeg',
        'post_title'     => $title,
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    $attach_id = wp_insert_attachment( $attachment, $final_path );
    if ( is_wp_error( $attach_id ) ) { wp_send_json_error( $attach_id->get_error_message() ); }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $final_path ) );

    wp_send_json_success( array(
        'attach_id'  => $attach_id,
        'edit_url'   => admin_url( 'post.php?post=' . $attach_id . '&action=edit' ),
        'final_name' => basename( $final_path ),
        'final_url'  => $final_url,
    ) );
}

// AJAX: Scan for broken image links in post content
add_action( 'wp_ajax_csc_scan_broken_images', 'csc_ajax_scan_broken_images' );
function csc_ajax_scan_broken_images() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $offset = intval( $_POST['offset'] ?? 0 );
    $batch  = 50;

    global $wpdb;
    $upload_dir = wp_upload_dir();
    $upload_url = trailingslashit( $upload_dir['baseurl'] );
    $upload_base = trailingslashit( $upload_dir['basedir'] );

    // Get total count on first call
    $total = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type IN ('post', 'page')
         AND post_content LIKE '%<img%'"
    );

    // Get batch of posts containing images
    $posts = $wpdb->get_results( $wpdb->prepare(
        "SELECT ID, post_title, post_content FROM {$wpdb->posts}
         WHERE post_status = 'publish'
         AND post_type IN ('post', 'page')
         AND post_content LIKE '%%<img%%'
         ORDER BY ID ASC
         LIMIT %d OFFSET %d",
        $batch, $offset
    ) );

    $broken = array();

    foreach ( $posts as $post ) {
        // Extract all img src URLs
        if ( ! preg_match_all( '/src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
            continue;
        }

        foreach ( $matches[1] as $url ) {
            // Only check URLs from our uploads directory
            if ( strpos( $url, 'wp-content/uploads/' ) === false ) {
                continue;
            }

            // Convert URL to file path
            $rel_path = preg_replace( '#^https?://[^/]+/#', '', $url );
            $file_path = ABSPATH . $rel_path;

            // Also try matching via upload_url
            if ( strpos( $url, $upload_url ) === 0 ) {
                $file_path = $upload_base . substr( $url, strlen( $upload_url ) );
            }

            if ( ! file_exists( $file_path ) ) {
                $broken[] = array(
                    'post_id'    => $post->ID,
                    'post_title' => $post->post_title,
                    'edit_url'   => admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
                    'image_url'  => $url,
                    'expected'   => $file_path,
                );
            }
        }
    }

    $next_offset = $offset + $batch;
    $has_more    = $next_offset < $total;

    wp_send_json_success( array(
        'broken'   => $broken,
        'offset'   => $next_offset,
        'total'    => (int) $total,
        'has_more' => $has_more,
    ) );
}

// AJAX: Delete a converted JPEG file from disk
add_action( 'wp_ajax_csc_pj_delete_converted', 'csc_ajax_cspj_delete_converted' );
function csc_ajax_cspj_delete_converted() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Permission denied.' ); }

    $path = sanitize_text_field( $_POST['path'] ?? '' );
    if ( empty( $path ) ) { wp_send_json_error( 'No path provided.' ); }

    $upload_dir = wp_upload_dir();
    if ( strpos( $path, $upload_dir['basedir'] ) !== 0 ) {
        wp_send_json_error( 'Invalid file path.' );
    }
    // Only allow deletion from the cspj-converted directory
    if ( strpos( $path, 'cspj-converted' ) === false ) {
        wp_send_json_error( 'Can only delete files from the cspj-converted folder.' );
    }

    if ( file_exists( $path ) ) {
        @unlink( $path );
        wp_send_json_success( 'File deleted.' );
    } else {
        wp_send_json_error( 'File not found.' );
    }
}

// Cron: cleanup stale chunks
add_action( 'cspj_cleanup_chunks', 'csc_cspj_cron_cleanup' );
function csc_cspj_cron_cleanup() {
    $root = csc_cspj_chunk_root_dir();
    if ( ! file_exists( $root ) ) { return; }
    $now     = time();
    $max_age = 6 * 3600;
    foreach ( glob( trailingslashit( $root ) . '*' ) as $dir ) {
        if ( ! is_dir( $dir ) ) { continue; }
        $meta    = trailingslashit( $dir ) . 'meta.json';
        $created = file_exists( $meta ) ? intval( json_decode( file_get_contents( $meta ), true )['created'] ?? 0 ) : 0;
        $age     = $created > 0 ? ( $now - $created ) : ( $now - filemtime( $dir ) );
        if ( $age > $max_age ) { csc_cspj_delete_dir( $dir ); }
    }
}

// Schedule chunk cleanup cron on activation
register_activation_hook( __FILE__, function() {
    if ( ! wp_next_scheduled( 'cspj_cleanup_chunks' ) ) {
        wp_schedule_event( time() + 3600, 'hourly', 'cspj_cleanup_chunks' );
    }
} );

// ═════════════════════════════════════════════════════════════════════════════
// SITE HEALTH METRICS
// ═════════════════════════════════════════════════════════════════════════════

// Option keys for stored metrics
define( 'CSC_HEALTH_HOURLY_KEY',  'csc_health_hourly_metrics' );
define( 'CSC_HEALTH_WEEKLY_KEY',  'csc_health_weekly_snapshots' );
define( 'CSC_HEALTH_MAX_AGE',     180 ); // days — expire data older than 6 months

// ─── Metric collection helpers ────────────────────────────────────────────────

/**
 * Get total disk usage of the WordPress installation in bytes.
 * Falls back to du on the wp-content directory if available.
 */
function csc_health_get_disk_usage_bytes(): int {
    $wp_root = rtrim( ABSPATH, '/' );

    // Try df for the partition containing WP (total used on partition)
    // But for site-specific tracking, wp-content is more useful
    $content_dir = WP_CONTENT_DIR;

    // Try shell du first (most accurate)
    if ( function_exists( 'exec' ) ) {
        $output = array();
        @exec( 'du -sb ' . escapeshellarg( $content_dir ) . ' 2>/dev/null', $output );
        if ( ! empty( $output[0] ) ) {
            $parts = preg_split( '/\s+/', trim( $output[0] ) );
            if ( isset( $parts[0] ) && is_numeric( $parts[0] ) ) {
                return intval( $parts[0] );
            }
        }
        // macOS fallback: du -sk (kilobytes)
        $output = array();
        @exec( 'du -sk ' . escapeshellarg( $content_dir ) . ' 2>/dev/null', $output );
        if ( ! empty( $output[0] ) ) {
            $parts = preg_split( '/\s+/', trim( $output[0] ) );
            if ( isset( $parts[0] ) && is_numeric( $parts[0] ) ) {
                return intval( $parts[0] ) * 1024;
            }
        }
    }

    // Fallback: PHP recursive directory size (slower but always works)
    return csc_health_dir_size( $content_dir );
}

/**
 * Recursive directory size in bytes (PHP fallback).
 */
function csc_health_dir_size( string $dir ): int {
    $size = 0;
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $iter as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }
    } catch ( Exception $e ) {
        error_log( '[CSC] health dir_size error: ' . $e->getMessage() );
    }
    return $size;
}

/**
 * Get free disk space on the partition containing wp-content.
 */
function csc_health_get_disk_free_bytes(): int {
    $free = @disk_free_space( WP_CONTENT_DIR );
    return $free !== false ? intval( $free ) : 0;
}

/**
 * Get total disk space on the partition containing wp-content.
 */
function csc_health_get_disk_total_bytes(): int {
    $total = @disk_total_space( WP_CONTENT_DIR );
    return $total !== false ? intval( $total ) : 0;
}

/**
 * Get current CPU load average (1 min).
 * Uses sys_getloadavg() on Linux, falls back to /proc/loadavg.
 */
function csc_health_get_cpu_load(): float {
    if ( function_exists( 'sys_getloadavg' ) ) {
        $load = @sys_getloadavg();
        if ( is_array( $load ) && isset( $load[0] ) ) {
            return round( floatval( $load[0] ), 2 );
        }
    }
    // Fallback: read /proc/loadavg
    if ( is_readable( '/proc/loadavg' ) ) {
        $raw = @file_get_contents( '/proc/loadavg' );
        if ( $raw !== false ) {
            $parts = explode( ' ', trim( $raw ) );
            return round( floatval( $parts[0] ), 2 );
        }
    }
    return -1; // unavailable
}

/**
 * Get current memory usage in bytes.
 * Reads /proc/meminfo on Linux, falls back to shell free command.
 */
function csc_health_get_memory_used_bytes(): int {
    // Linux: parse /proc/meminfo
    if ( is_readable( '/proc/meminfo' ) ) {
        $raw = @file_get_contents( '/proc/meminfo' );
        if ( $raw !== false ) {
            $mem_total    = 0;
            $mem_free     = 0;
            $mem_buffers  = 0;
            $mem_cached   = 0;
            foreach ( explode( "\n", $raw ) as $line ) {
                if ( preg_match( '/^MemTotal:\s+(\d+)\s+kB/', $line, $m ) )  { $mem_total   = intval( $m[1] ) * 1024; }
                if ( preg_match( '/^MemFree:\s+(\d+)\s+kB/', $line, $m ) )   { $mem_free    = intval( $m[1] ) * 1024; }
                if ( preg_match( '/^Buffers:\s+(\d+)\s+kB/', $line, $m ) )   { $mem_buffers = intval( $m[1] ) * 1024; }
                if ( preg_match( '/^Cached:\s+(\d+)\s+kB/', $line, $m ) )    { $mem_cached  = intval( $m[1] ) * 1024; }
            }
            if ( $mem_total > 0 ) {
                return $mem_total - $mem_free - $mem_buffers - $mem_cached;
            }
        }
    }
    return -1; // unavailable
}

/**
 * Get total system memory in bytes.
 */
function csc_health_get_memory_total_bytes(): int {
    if ( is_readable( '/proc/meminfo' ) ) {
        $raw = @file_get_contents( '/proc/meminfo' );
        if ( $raw !== false && preg_match( '/^MemTotal:\s+(\d+)\s+kB/m', $raw, $m ) ) {
            return intval( $m[1] ) * 1024;
        }
    }
    return -1;
}

/**
 * Find the sar binary path. Apache's PATH is often restricted, so we check common locations.
 */
/**
 * Get the system timezone for sar queries. WordPress may override PHP's timezone
 * via date_default_timezone_set(), but sar uses the OS timezone. We need to match.
 */
function csc_health_system_time( string $fmt, int $ts = 0 ): string {
    static $sys_tz = null;
    if ( $sys_tz === null ) {
        // Read system timezone, not WordPress timezone
        $tz_file = @file_get_contents( '/etc/timezone' );
        if ( $tz_file ) {
            $sys_tz = trim( $tz_file );
        } else {
            $link = @readlink( '/etc/localtime' );
            if ( $link && preg_match( '#zoneinfo/(.+)$#', $link, $m ) ) {
                $sys_tz = $m[1];
            } else {
                // Fallback: ask the OS
                $out = array();
                @exec( 'date +%Z 2>/dev/null', $out );
                $sys_tz = ! empty( $out[0] ) ? trim( $out[0] ) : 'UTC';
            }
        }
    }
    $old_tz = date_default_timezone_get();
    date_default_timezone_set( $sys_tz );
    $result = date( $fmt, $ts ?: time() );
    date_default_timezone_set( $old_tz );
    return $result;
}

function csc_health_find_sar(): string {
    static $cached = null;
    if ( $cached !== null ) { return $cached; }
    $paths = array( '/usr/bin/sar', '/usr/sbin/sar', '/usr/local/bin/sar' );
    foreach ( $paths as $p ) {
        if ( @is_executable( $p ) ) { $cached = $p; return $p; }
    }
    // Last resort: try which
    if ( function_exists( 'exec' ) ) {
        $out = array();
        @exec( 'which sar 2>/dev/null', $out );
        if ( ! empty( $out[0] ) && @is_executable( trim( $out[0] ) ) ) {
            $cached = trim( $out[0] );
            return $cached;
        }
    }
    $cached = '';
    return '';
}

/**
 * Get the number of CPU cores.
 */
function csc_health_get_cpu_count(): int {
    if ( is_readable( '/proc/cpuinfo' ) ) {
        $raw = @file_get_contents( '/proc/cpuinfo' );
        if ( $raw !== false ) {
            return max( 1, substr_count( $raw, 'processor' ) );
        }
    }
    if ( function_exists( 'exec' ) ) {
        $output = array();
        @exec( 'nproc 2>/dev/null', $output );
        if ( ! empty( $output[0] ) && is_numeric( $output[0] ) ) {
            return max( 1, intval( $output[0] ) );
        }
    }
    return 1;
}

/**
 * Get MAX CPU usage percentage over the last hour.
 *
 * Uses sar (sysstat) if available to read per minute samples from the last 60 minutes
 * and returns the highest CPU% seen. This captures spikes that a single point in time
 * snapshot would miss. Falls back to instantaneous load average conversion if sar
 * is not installed.
 *
 * sar -u output lines look like:
 *   14:01:01  all  0.50  0.00  0.25  0.00  0.00  99.25
 * Columns: time, CPU, %user, %nice, %system, %iowait, %steal, %idle
 * CPU% = 100 - %idle
 */
function csc_health_get_cpu_pct(): float {
    $sar = csc_health_find_sar();
    if ( $sar !== '' && function_exists( 'exec' ) ) {
        // Try sar first: get last 60 minutes of CPU data
        $output = array();
        $end   = csc_health_system_time( 'H:i:s' );
        $start = csc_health_system_time( 'H:i:s', time() - 3600 );
        @exec( 'LC_ALL=C ' . $sar . ' -u -s ' . escapeshellarg( $start ) . ' -e ' . escapeshellarg( $end ) . ' 2>/dev/null', $output );

        $max_cpu = -1;
        foreach ( $output as $line ) {
            $line = trim( $line );
            // Skip headers, averages, and empty lines
            if ( $line === '' || strpos( $line, 'Average' ) !== false || strpos( $line, '%idle' ) !== false || strpos( $line, 'Linux' ) !== false ) {
                continue;
            }
            // Parse: HH:MM:SS  all  %user  %nice  %system  %iowait  %steal  %idle
            $parts = preg_split( '/\s+/', $line );
            if ( count( $parts ) >= 8 && is_numeric( $parts[7] ) ) {
                $idle = floatval( $parts[7] );
                $cpu  = round( 100 - $idle, 1 );
                if ( $cpu > $max_cpu ) { $max_cpu = $cpu; }
            }
        }
        if ( $max_cpu >= 0 ) {
            return $max_cpu;
        }
    }

    // Fallback: instantaneous load average to percentage
    $load = csc_health_get_cpu_load();
    if ( $load < 0 ) { return -1; }
    $cpus = csc_health_get_cpu_count();
    return round( min( 100, ( $load / $cpus ) * 100 ), 1 );
}

/**
 * Get MAX memory usage percentage over the last hour.
 *
 * Uses sar -r (sysstat) if available to read per minute memory samples and returns
 * the highest memory% seen. Falls back to instantaneous /proc/meminfo reading.
 *
 * sar -r output lines look like:
 *   14:01:01  1024000  512000  50.00  128000  384000  ...
 * Columns: time, kbmemfree, kbavail, %memused, kbbuffers, kbcached, ...
 * We use the %memused column directly.
 */
function csc_health_get_mem_pct(): float {
    $sar = csc_health_find_sar();
    if ( $sar !== '' && function_exists( 'exec' ) ) {
        $output = array();
        $end   = csc_health_system_time( 'H:i:s' );
        $start = csc_health_system_time( 'H:i:s', time() - 3600 );
        @exec( 'LC_ALL=C ' . $sar . ' -r -s ' . escapeshellarg( $start ) . ' -e ' . escapeshellarg( $end ) . ' 2>/dev/null', $output );

        $max_mem = -1;
        foreach ( $output as $line ) {
            $line = trim( $line );
            if ( $line === '' || strpos( $line, 'Average' ) !== false || strpos( $line, '%memused' ) !== false || strpos( $line, 'Linux' ) !== false ) {
                continue;
            }
            // Parse: HH:MM:SS  kbmemfree  kbavail  %memused  kbbuffers  kbcached  ...
            $parts = preg_split( '/\s+/', $line );
            if ( count( $parts ) >= 5 && is_numeric( $parts[4] ) ) {
                $mem_pct = floatval( $parts[4] );
                if ( $mem_pct > $max_mem ) { $max_mem = $mem_pct; }
            }
        }
        if ( $max_mem >= 0 ) {
            return round( $max_mem, 1 );
        }
    }

    // Fallback: instantaneous reading
    $used  = csc_health_get_memory_used_bytes();
    $total = csc_health_get_memory_total_bytes();
    if ( $used < 0 || $total <= 0 ) { return -1; }
    return round( ( $used / $total ) * 100, 1 );
}

/**
 * Get database size in bytes.
 */
function csc_health_get_db_size_bytes(): int {
    global $wpdb;
    $db_name = DB_NAME;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = %s",
        $db_name
    ) );
    return ( $row && $row->size ) ? intval( $row->size ) : 0;
}

// ─── Hourly metric collection cron ───────────────────────────────────────────

add_action( 'csc_health_hourly_collect', 'csc_health_collect_hourly' );
function csc_health_collect_hourly() {
    $metrics = get_option( CSC_HEALTH_HOURLY_KEY, array() );

    $cpu_pct = csc_health_get_cpu_pct();
    $mem_pct = csc_health_get_mem_pct();
    $max_resource = max( $cpu_pct, $mem_pct );

    // Record whether sar was used (true peak) or fallback (instantaneous)
    $sar_available = ( csc_health_find_sar() !== '' );

    $entry = array(
        'ts'               => current_time( 'mysql' ),
        'ts_unix'          => time(),
        'disk_used'        => csc_health_get_disk_usage_bytes(),
        'disk_free'        => csc_health_get_disk_free_bytes(),
        'cpu_load'         => csc_health_get_cpu_load(),
        'cpu_pct'          => $cpu_pct,
        'mem_used'         => csc_health_get_memory_used_bytes(),
        'mem_total'        => csc_health_get_memory_total_bytes(),
        'mem_pct'          => $mem_pct,
        'max_resource_pct' => $max_resource,
        'source'           => $sar_available ? 'sar' : 'snapshot',
        'db_size'          => csc_health_get_db_size_bytes(),
    );

    $metrics[] = $entry;

    // Expire entries older than 6 months
    $cutoff = time() - ( CSC_HEALTH_MAX_AGE * DAY_IN_SECONDS );
    $metrics = array_values( array_filter( $metrics, function( $m ) use ( $cutoff ) {
        return isset( $m['ts_unix'] ) && $m['ts_unix'] >= $cutoff;
    } ) );

    update_option( CSC_HEALTH_HOURLY_KEY, $metrics, false ); // autoload=false (can be large)
}

// ─── Weekly disk snapshot cron ───────────────────────────────────────────────

add_action( 'csc_health_weekly_snapshot', 'csc_health_collect_weekly' );
function csc_health_collect_weekly() {
    $snapshots = get_option( CSC_HEALTH_WEEKLY_KEY, array() );

    $snapshots[] = array(
        'ts'         => current_time( 'mysql' ),
        'ts_unix'    => time(),
        'disk_used'  => csc_health_get_disk_usage_bytes(),
        'disk_free'  => csc_health_get_disk_free_bytes(),
        'disk_total' => csc_health_get_disk_total_bytes(),
        'db_size'    => csc_health_get_db_size_bytes(),
    );

    // Expire entries older than 6 months
    $cutoff = time() - ( CSC_HEALTH_MAX_AGE * DAY_IN_SECONDS );
    $snapshots = array_values( array_filter( $snapshots, function( $s ) use ( $cutoff ) {
        return isset( $s['ts_unix'] ) && $s['ts_unix'] >= $cutoff;
    } ) );

    update_option( CSC_HEALTH_WEEKLY_KEY, $snapshots, false );
}

// ─── Schedule health crons on activation ─────────────────────────────────────

register_activation_hook( __FILE__, 'csc_health_schedule_crons' );
add_action( 'admin_init', 'csc_health_ensure_crons' );

function csc_health_schedule_crons() {
    if ( ! wp_next_scheduled( 'csc_health_hourly_collect' ) ) {
        wp_schedule_event( time(), 'hourly', 'csc_health_hourly_collect' );
    }
    if ( ! wp_next_scheduled( 'csc_health_weekly_snapshot' ) ) {
        wp_schedule_event( time(), 'weekly', 'csc_health_weekly_snapshot' );
    }
}

function csc_health_ensure_crons() {
    if ( ! wp_next_scheduled( 'csc_health_hourly_collect' ) ) {
        wp_schedule_event( time(), 'hourly', 'csc_health_hourly_collect' );
    }
    if ( ! wp_next_scheduled( 'csc_health_weekly_snapshot' ) ) {
        wp_schedule_event( time(), 'weekly', 'csc_health_weekly_snapshot' );
    }
}

// Register 'weekly' interval (WordPress doesn't have one by default)
add_filter( 'cron_schedules', 'csc_health_cron_schedules' );
function csc_health_cron_schedules( $schedules ) {
    if ( ! isset( $schedules['weekly'] ) ) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => 'Once Weekly',
        );
    }
    return $schedules;
}

// ─── Health score calculation ────────────────────────────────────────────────

/**
 * Calculate site health summary.
 * Returns array with disk, cpu, memory stats and RAG status.
 */
function csc_health_calculate(): array {
    $hourly    = get_option( CSC_HEALTH_HOURLY_KEY, array() );
    $weekly    = get_option( CSC_HEALTH_WEEKLY_KEY, array() );
    $now       = time();

    // Current disk state
    $disk_used  = csc_health_get_disk_usage_bytes();
    $disk_free  = csc_health_get_disk_free_bytes();
    $disk_total = csc_health_get_disk_total_bytes();
    $db_size    = csc_health_get_db_size_bytes();

    // Weekly growth rate: max disk_used across any rolling 7 day window over last 3 months
    // Then compare oldest vs newest weekly snapshot
    $growth_per_week = 0;
    $weeks_of_data   = 0;
    if ( count( $weekly ) >= 2 ) {
        // Use the 3 month window (13 weeks) for growth calculation
        $three_months_ago = $now - ( 90 * DAY_IN_SECONDS );
        $recent = array_filter( $weekly, function( $s ) use ( $three_months_ago ) {
            return $s['ts_unix'] >= $three_months_ago;
        } );
        $recent = array_values( $recent );

        if ( count( $recent ) >= 2 ) {
            $oldest = $recent[0];
            $newest = end( $recent );
            $elapsed_weeks = max( 1, ( $newest['ts_unix'] - $oldest['ts_unix'] ) / WEEK_IN_SECONDS );
            $weeks_of_data = round( $elapsed_weeks, 1 );

            // Find the maximum disk_used in any weekly snapshot (worst case growth)
            $max_used = 0;
            foreach ( $recent as $s ) {
                if ( $s['disk_used'] > $max_used ) { $max_used = $s['disk_used']; }
            }

            // Growth = (max_used - oldest) / elapsed weeks
            // This captures worst case peak, not just current
            $total_growth = $max_used - $oldest['disk_used'];
            if ( $total_growth > 0 ) {
                $growth_per_week = $total_growth / $elapsed_weeks;
            }
        }
    }

    // Weeks remaining until disk full
    $weeks_remaining = -1; // -1 = insufficient data
    if ( $growth_per_week > 0 && $disk_free > 0 ) {
        $weeks_remaining = $disk_free / $growth_per_week;
    }

    // RAG status for disk
    $disk_rag = 'grey'; // insufficient data
    if ( $weeks_remaining > 0 ) {
        if ( $weeks_remaining < 13 ) {       // < 3 months
            $disk_rag = 'red';
        } elseif ( $weeks_remaining < 26 ) { // < 6 months
            $disk_rag = 'amber';
        } else {
            $disk_rag = 'green';
        }
    } elseif ( count( $weekly ) >= 2 && $growth_per_week <= 0 ) {
        // Disk is shrinking or stable — green
        $disk_rag = 'green';
    }

    // CPU and Memory: max percentages over last 24h and 7d
    $cpu_max_24h     = -1;
    $cpu_max_7d      = -1;
    $cpu_pct_max_24h = -1;
    $cpu_pct_max_7d  = -1;
    $mem_max_24h     = -1;
    $mem_max_7d      = -1;
    $mem_pct_max_24h = -1;
    $mem_pct_max_7d  = -1;
    $max_res_24h     = -1;
    $max_res_7d      = -1;
    $cutoff_24h      = $now - DAY_IN_SECONDS;
    $cutoff_7d       = $now - WEEK_IN_SECONDS;
    $mem_total       = csc_health_get_memory_total_bytes();

    foreach ( $hourly as $h ) {
        $in_24h = isset( $h['ts_unix'] ) && $h['ts_unix'] >= $cutoff_24h;
        $in_7d  = isset( $h['ts_unix'] ) && $h['ts_unix'] >= $cutoff_7d;

        // CPU load average (raw)
        if ( isset( $h['cpu_load'] ) && $h['cpu_load'] >= 0 ) {
            if ( $in_24h && $h['cpu_load'] > $cpu_max_24h ) { $cpu_max_24h = $h['cpu_load']; }
            if ( $in_7d  && $h['cpu_load'] > $cpu_max_7d )  { $cpu_max_7d  = $h['cpu_load']; }
        }
        // CPU percentage
        if ( isset( $h['cpu_pct'] ) && $h['cpu_pct'] >= 0 ) {
            if ( $in_24h && $h['cpu_pct'] > $cpu_pct_max_24h ) { $cpu_pct_max_24h = $h['cpu_pct']; }
            if ( $in_7d  && $h['cpu_pct'] > $cpu_pct_max_7d )  { $cpu_pct_max_7d  = $h['cpu_pct']; }
        }
        // Memory bytes (raw)
        if ( isset( $h['mem_used'] ) && $h['mem_used'] >= 0 ) {
            if ( $in_24h && $h['mem_used'] > $mem_max_24h ) { $mem_max_24h = $h['mem_used']; }
            if ( $in_7d  && $h['mem_used'] > $mem_max_7d )  { $mem_max_7d  = $h['mem_used']; }
        }
        // Memory percentage
        if ( isset( $h['mem_pct'] ) && $h['mem_pct'] >= 0 ) {
            if ( $in_24h && $h['mem_pct'] > $mem_pct_max_24h ) { $mem_pct_max_24h = $h['mem_pct']; }
            if ( $in_7d  && $h['mem_pct'] > $mem_pct_max_7d )  { $mem_pct_max_7d  = $h['mem_pct']; }
        }
        // Max resource percentage (whichever is higher: cpu or memory)
        if ( isset( $h['max_resource_pct'] ) && $h['max_resource_pct'] >= 0 ) {
            if ( $in_24h && $h['max_resource_pct'] > $max_res_24h ) { $max_res_24h = $h['max_resource_pct']; }
            if ( $in_7d  && $h['max_resource_pct'] > $max_res_7d )  { $max_res_7d  = $h['max_resource_pct']; }
        }
    }

    return array(
        'disk_used'          => $disk_used,
        'disk_free'          => $disk_free,
        'disk_total'         => $disk_total,
        'db_size'            => $db_size,
        'growth_per_week'    => $growth_per_week,
        'weeks_remaining'    => $weeks_remaining,
        'weeks_of_data'      => $weeks_of_data,
        'disk_rag'           => $disk_rag,
        'cpu_load_now'       => csc_health_get_cpu_load(),
        'cpu_pct_now'        => csc_health_get_cpu_pct(),
        'cpu_max_24h'        => $cpu_max_24h,
        'cpu_max_7d'         => $cpu_max_7d,
        'cpu_pct_max_24h'    => $cpu_pct_max_24h,
        'cpu_pct_max_7d'     => $cpu_pct_max_7d,
        'mem_used_now'       => csc_health_get_memory_used_bytes(),
        'mem_pct_now'        => csc_health_get_mem_pct(),
        'mem_total'          => $mem_total,
        'mem_max_24h'        => $mem_max_24h,
        'mem_max_7d'         => $mem_max_7d,
        'mem_pct_max_24h'    => $mem_pct_max_24h,
        'mem_pct_max_7d'     => $mem_pct_max_7d,
        'max_resource_now'   => max( csc_health_get_cpu_pct(), csc_health_get_mem_pct() ),
        'max_resource_24h'   => $max_res_24h,
        'max_resource_7d'    => $max_res_7d,
        'hourly_count'       => count( $hourly ),
        'weekly_count'       => count( $weekly ),
        'last_hourly'        => ! empty( $hourly ) ? end( $hourly )['ts'] : null,
        'last_weekly'        => ! empty( $weekly ) ? end( $weekly )['ts'] : null,
    );
}

// ─── AJAX: Force collect now (for first run / testing) ───────────────────────

add_action( 'wp_ajax_csc_health_collect_now', 'csc_ajax_health_collect_now' );
function csc_ajax_health_collect_now() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    csc_health_collect_hourly();
    csc_health_collect_weekly();
    wp_send_json_success( array( 'message' => 'Metrics collected.', 'health' => csc_health_calculate() ) );
}

// ─── AJAX: Get health data ───────────────────────────────────────────────────

add_action( 'wp_ajax_csc_health_get', 'csc_ajax_health_get' );
function csc_ajax_health_get() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    wp_send_json_success( csc_health_calculate() );
}

// ─── AJAX: Get raw hourly data for charts ────────────────────────────────────

add_action( 'wp_ajax_csc_health_hourly_data', 'csc_ajax_health_hourly_data' );
function csc_ajax_health_hourly_data() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $days    = intval( $_POST['days'] ?? 7 );
    $days    = max( 1, min( 180, $days ) );
    $cutoff  = time() - ( $days * DAY_IN_SECONDS );
    $hourly  = get_option( CSC_HEALTH_HOURLY_KEY, array() );
    $filtered = array_values( array_filter( $hourly, function( $h ) use ( $cutoff ) {
        return isset( $h['ts_unix'] ) && $h['ts_unix'] >= $cutoff;
    } ) );

    wp_send_json_success( array( 'data' => $filtered, 'total' => count( $filtered ) ) );
}

// ─── AJAX: Get weekly snapshots for charts ───────────────────────────────────

add_action( 'wp_ajax_csc_health_weekly_data', 'csc_ajax_health_weekly_data' );
function csc_ajax_health_weekly_data() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }
    wp_send_json_success( array( 'data' => get_option( CSC_HEALTH_WEEKLY_KEY, array() ) ) );
}

// ─── AJAX: Test sysstat availability ─────────────────────────────────────────

add_action( 'wp_ajax_csc_health_sysstat_test', 'csc_ajax_health_sysstat_test' );
function csc_ajax_health_sysstat_test() {
    check_ajax_referer( 'csc_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Insufficient permissions.' ); }

    $result = array(
        'exec_available' => function_exists( 'exec' ),
        'sar_installed'  => false,
        'sar_path'       => '',
        'sar_version'    => '',
        'sysstat_active' => false,
        'sar_has_data'   => false,
        'cpu_count'      => csc_health_get_cpu_count(),
        'cpu_pct_now'    => csc_health_get_cpu_pct(),
        'mem_pct_now'    => csc_health_get_mem_pct(),
        'source'         => 'snapshot',
        'instructions'   => '',
    );

    if ( ! function_exists( 'exec' ) ) {
        $result['instructions'] = 'PHP exec() is disabled. Enable it in php.ini to allow sysstat metric collection.';
        wp_send_json_success( $result );
    }

    // Check if sar is installed using full path detection
    $sar_path = csc_health_find_sar();
    if ( $sar_path !== '' ) {
        $result['sar_installed'] = true;
        $result['sar_path']     = $sar_path;

        // Get version
        $ver = array();
        @exec( $sar_path . ' -V 2>&1', $ver );
        if ( ! empty( $ver[0] ) ) {
            $result['sar_version'] = trim( $ver[0] );
        }

        // Check if sysstat service is collecting data
        $active = array();
        @exec( 'systemctl is-active sysstat 2>/dev/null', $active );
        $result['sysstat_active'] = ( ! empty( $active[0] ) && trim( $active[0] ) === 'active' );

        // Try reading sar data to confirm it works
        $test = array();
        $end   = csc_health_system_time( 'H:i:s' );
        $start = csc_health_system_time( 'H:i:s', time() - 3600 );
        @exec( 'LC_ALL=C ' . $sar_path . ' -u -s ' . escapeshellarg( $start ) . ' -e ' . escapeshellarg( $end ) . ' 2>&1', $test );
        $data_lines = 0;
        foreach ( $test as $line ) {
            $line = trim( $line );
            if ( $line === '' || strpos( $line, 'Average' ) !== false || strpos( $line, '%idle' ) !== false || strpos( $line, 'Linux' ) !== false ) { continue; }
            $parts = preg_split( '/\s+/', $line );
            if ( count( $parts ) >= 8 && is_numeric( $parts[7] ) ) { $data_lines++; }
        }
        $result['sar_has_data']  = $data_lines > 0;
        $result['sar_samples']   = $data_lines;
        $result['source']        = $data_lines > 0 ? 'sar' : 'snapshot';
        $result['sar_raw_output'] = implode( "\n", array_slice( $test, 0, 10 ) );

        $enable_cmds = 'sudo systemctl enable sysstat && sudo systemctl start sysstat && sudo systemctl enable sysstat-collect.timer && sudo systemctl start sysstat-collect.timer';
        $kick_cmd    = 'sudo /usr/lib64/sa/sa1 1 1';
        if ( ! $result['sysstat_active'] ) {
            $result['instructions'] = 'Run: ' . $enable_cmds . ' && ' . $kick_cmd;
        } elseif ( ! $result['sar_has_data'] ) {
            $result['instructions'] = 'Enable collection timer: sudo systemctl enable sysstat-collect.timer && sudo systemctl start sysstat-collect.timer && ' . $kick_cmd;
        }
    } else {
        // Detect OS for install instructions
        $os_info = array();
        @exec( 'cat /etc/os-release 2>/dev/null | head -3', $os_info );
        $os_str = implode( ' ', $os_info );
        $enable_cmds = 'sudo systemctl enable sysstat && sudo systemctl start sysstat && sudo systemctl enable sysstat-collect.timer && sudo systemctl start sysstat-collect.timer && sudo /usr/lib64/sa/sa1 1 1';
        if ( stripos( $os_str, 'Amazon' ) !== false || stripos( $os_str, 'rhel' ) !== false || stripos( $os_str, 'centos' ) !== false ) {
            $result['instructions'] = 'sudo yum install sysstat -y && ' . $enable_cmds;
        } elseif ( stripos( $os_str, 'ubuntu' ) !== false || stripos( $os_str, 'debian' ) !== false ) {
            $result['instructions'] = 'sudo apt install sysstat -y && ' . $enable_cmds;
        } else {
            $result['instructions'] = 'Install sysstat with your package manager, then run: ' . $enable_cmds;
        }
    }

    // Debug: timezone info for diagnosing sar time window mismatches
    $result['debug_wp_tz']      = date_default_timezone_get();
    $result['debug_sys_time']   = csc_health_system_time( 'H:i:s' );
    $result['debug_php_time']   = date( 'H:i:s' );
    $result['debug_sar_window'] = csc_health_system_time( 'H:i:s', time() - 3600 ) . ' to ' . csc_health_system_time( 'H:i:s' );

    wp_send_json_success( $result );
}

// ═════════════════════════════════════════════════════════════════════════════
// ADMIN PAGE
// ═════════════════════════════════════════════════════════════════════════════


// ─── Explain modal helper ────────────────────────────────────────────────────

function csc_explain_btn( string $id, string $title, array $items, string $color = 'rgba(255,255,255,0.2)' ): void {
    $btn_id   = 'csc-explain-btn-' . $id;
    $modal_id = 'csc-explain-modal-' . $id;
    ?>
    <button type="button" id="<?php echo esc_attr( $btn_id ); ?>"
        data-color="<?php echo esc_attr( $color ); ?>"
        onclick="document.getElementById('<?php echo esc_attr( $modal_id ); ?>').style.display='flex'"
        style="background:<?php echo esc_attr( $color ); ?>!important;border:1px solid rgba(255,255,255,0.5)!important;border-radius:5px!important;color:#fff!important;font-size:12px!important;font-weight:600!important;padding:5px 14px!important;cursor:pointer!important;margin-left:auto!important;flex-shrink:0!important;display:block!important;box-shadow:none!important;text-shadow:none!important;text-transform:none!important;letter-spacing:normal!important;line-height:1.4!important">
        Explain...
    </button>
    <div id="<?php echo esc_attr( $modal_id ); ?>" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;padding:16px">
        <div style="background:#fff;border-radius:10px;max-width:640px;width:100%;max-height:88vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.4)">
            <div style="background:#1a2a3a;border-radius:10px 10px 0 0;padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
                <strong style="color:#fff;font-size:15px"><?php echo esc_html( $title ); ?></strong>
                <button type="button" onclick="document.getElementById('<?php echo esc_attr( $modal_id ); ?>').style.display='none'"
                    style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);border-radius:5px;color:#fff;font-size:16px;font-weight:700;padding:2px 10px;cursor:pointer;line-height:1">&#x2715;</button>
            </div>
            <div style="padding:20px 24px;font-size:13px;line-height:1.6;color:#1d2327">
                <?php foreach ( $items as $item ) :
                    $rec    = $item['rec'];
                    $is_on  = strpos( $rec, 'Recommended' ) !== false;
                    $is_opt = strpos( $rec, 'Optional' ) !== false;
                    $bg     = $is_on ? '#edfaef' : ( $is_opt ? '#f6f7f7' : '#f0f6fc' );
                    $col    = $is_on ? '#1a7a34' : ( $is_opt ? '#50575e' : '#1a4a7a' );
                    $bdr    = $is_on ? '#1a7a34' : ( $is_opt ? '#c3c4c7' : '#2271b1' );
                ?>
                <div style="border:1px solid #e0e0e0;border-radius:6px;padding:12px 14px;margin-bottom:10px">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:5px;flex-wrap:wrap">
                        <strong style="font-size:13px"><?php echo esc_html( $item['name'] ); ?></strong>
                        <span style="background:<?php echo esc_attr( $bg ); ?>;color:<?php echo esc_attr( $col ); ?>;border:1px solid <?php echo esc_attr( $bdr ); ?>;border-radius:4px;font-size:11px;font-weight:600;padding:1px 8px;white-space:nowrap"><?php echo esc_html( $rec ); ?></span>
                    </div>
                    <p style="margin:0;color:#50575e;font-size:12px;line-height:1.5;white-space:pre-line"><?php echo esc_html( $item['desc'] ); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="padding:12px 24px 20px;text-align:right">
                <button type="button" onclick="document.getElementById('<?php echo esc_attr( $modal_id ); ?>').style.display='none'"
                    style="background:#1a2a3a;border:none;border-radius:6px;color:#fff;font-size:13px;font-weight:600;padding:8px 24px;cursor:pointer">
                    Got it
                </button>
            </div>
        </div>
    </div>
    <?php
}

function csc_render_page() {
    $dow            = array( 'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun' );
    $db_sched_days  = (array) get_option( 'csc_schedule_db_days',  array( 'mon', 'wed', 'fri' ) );
    $img_sched_days = (array) get_option( 'csc_schedule_img_days', array( 'mon', 'wed', 'fri' ) );
    ?>
    <div class="csc-wrap">

        <script>
        /* Fallback: ensure CSC is always available even if wp_localize_script fails */
        if (typeof CSC === 'undefined' || !CSC.ajax_url) {
            window.CSC = window.CSC || {};
            CSC.ajax_url        = CSC.ajax_url        || '<?php echo esc_js( admin_url( "admin-ajax.php" ) ); ?>';
            CSC.nonce            = CSC.nonce            || '<?php echo esc_js( wp_create_nonce( "csc_nonce" ) ); ?>';
            CSC.cspj_chunk_mb    = CSC.cspj_chunk_mb    || '<?php echo esc_js( csc_get_cspj_chunk_mb() ); ?>';
            CSC.cspj_server_max_mb = CSC.cspj_server_max_mb || '<?php echo esc_js( csc_get_cspj_server_max_mb() ); ?>';
            CSC.cspj_max_total_mb  = CSC.cspj_max_total_mb  || '<?php echo esc_js( CSPJ_MAX_TOTAL_MB ); ?>';
            CSC.version          = CSC.version          || '<?php echo esc_js( CLOUDSCALE_CLEANUP_VERSION ); ?>';
            console.log('[CSC] Fallback CSC injected inline. wp_localize_script may not have fired.');
        }
        </script>

        <div class="csc-header">
            <div class="csc-header-inner">
                <div class="csc-header-title">
                    <span class="csc-logo">⚡</span>
                    <div>
                        <h1>CloudScale Cleanup</h1>
                        <p>Database and Media Library Cleanup &middot; Free and Open Source &middot; <a href="https://andrewbaker.ninja" target="_blank">andrewbaker.ninja</a></p>
                    </div>
                </div>
                <div class="csc-header-version">v<?php echo esc_html( CLOUDSCALE_CLEANUP_VERSION ); ?></div>
            </div>
        </div>

        <div class="csc-tabs">
            <button class="csc-tab active" data-tab="site-health">Site Health</button>
            <button class="csc-tab" data-tab="db-cleanup">Database Cleanup</button>
            <button class="csc-tab" data-tab="img-cleanup">Media Cleanup</button>
            <button class="csc-tab" data-tab="img-optimise">Image Optimisation</button>
            <button class="csc-tab" data-tab="png-to-jpeg">PNG to JPEG</button>
            <button class="csc-tab" data-tab="settings">Settings</button>
        </div>

        <!-- ═══ Database Cleanup ═══ -->
        <div class="csc-tab-content" id="tab-db-cleanup">
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-blue"><span>Database Cleanup</span> <?php csc_explain_btn(
            'db-cleanup',
            'Database Cleanup — What it does',
            [
            [ 'rec' => '✅ Recommended', 'name' => 'Post Revisions', 'desc' => 'Every time you save or update a post, WordPress stores a complete copy. On an active blog this can mean hundreds of revision rows per post. They consume significant database space over time.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Draft Posts', 'desc' => 'Posts saved as drafts but never published. The threshold controls how old a draft must be before deletion — fresh drafts are never touched.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Trashed Posts', 'desc' => 'Posts moved to the trash. WordPress keeps them indefinitely by default. This removes them permanently after the configured number of days.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Auto-Drafts', 'desc' => 'WordPress creates an auto-draft record when you open Add New Post. If you navigate away without saving, the empty record remains. These accumulate silently.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Expired Transients', 'desc' => 'Temporary cached values stored in your options table by plugins and themes. After expiry WordPress should delete them, but many accumulate. Completely safe to delete.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Orphaned Post Meta', 'desc' => 'Post meta rows referencing a post ID that no longer exists. Left behind when posts are deleted without their metadata being cleaned up.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'Orphaned User Meta', 'desc' => 'Metadata rows referencing deleted user accounts. Accumulates when users are removed from the system.' ],
            [ 'rec' => '💡 Tip', 'name' => 'Always dry run first', 'desc' => 'Press Dry Run to preview what will be removed, then review the output log carefully before running cleanup. No changes are made until you press the cleanup button.' ],
            ],
            '#00e676'
        ); ?></div>
                    <div class="csc-card-body">
                        <p class="csc-options-intro">Select which items to include in every cleanup run. Toggle off anything you want to preserve.</p>
                        <div class="csc-options-grid" id="csc-db-toggles">
                            <?php
                            $db_toggles = array(
                                'csc_clean_revisions'      => array( 'Post Revisions',    'Old revision copies saved every time a post is edited. On active blogs these accumulate into thousands of rows.' ),
                                'csc_clean_drafts'         => array( 'Draft Posts',        'Unpublished drafts older than the configured threshold. Fresh drafts are never touched.' ),
                                'csc_clean_trashed'        => array( 'Trashed Posts',      'Posts in the WordPress trash older than the threshold. WordPress keeps them indefinitely by default.' ),
                                'csc_clean_autodrafts'     => array( 'Auto-Drafts',        'Empty placeholder records left when the editor is abandoned without saving.' ),
                                'csc_clean_transients'     => array( 'Expired Transients', 'Stale cached values stored in wp_options past their expiry date. Completely safe to delete.' ),
                                'csc_clean_orphan_post'    => array( 'Orphaned Post Meta', 'Post meta rows whose parent post has been deleted. Left behind when posts are removed without proper cleanup.' ),
                                'csc_clean_orphan_user'    => array( 'Orphaned User Meta', 'Meta rows referencing deleted user accounts. Accumulates when users are removed from the system.' ),
                                'csc_clean_spam_comments'  => array( 'Spam Comments',      'Comments flagged as spam older than the configured threshold. Safe to remove after the review window.' ),
                                'csc_clean_trash_comments' => array( 'Trashed Comments',   'Comments moved to the WordPress comment trash older than the threshold.' ),
                            );
                            foreach ( $db_toggles as $opt => $info ) :
                                $is_on = get_option( $opt, '1' ) === '1';
                            ?>
                            <div class="csc-option-row" style="display:flex;align-items:center;justify-content:space-between;gap:16px;padding:10px 14px;border-radius:6px;background:#fff;border:1px solid transparent;transition:background 0.12s;"
                                 onmouseover="this.style.background='#f0f6fc';this.style.borderColor='#c5d9f0'"
                                 onmouseout="this.style.background='#fff';this.style.borderColor='transparent'">
                                <div style="display:flex;flex-direction:column;gap:2px;flex:1;min-width:0;">
                                    <span style="font-size:13px;font-weight:700;color:#1d2327;"><?php echo esc_html( $info[0] ); ?></span>
                                    <span style="font-size:12px;color:#787c82;line-height:1.5;"><?php echo esc_html( $info[1] ); ?></span>
                                </div>
                                <!-- Hidden input holds the real value for form submission -->
                                <input type="hidden" name="<?php echo esc_attr( $opt ); ?>" value="<?php echo $is_on ? '1' : '0'; ?>" data-csc-toggle="1">
                                <!-- Pure-div toggle — zero CSS class dependencies, all inline -->
                                <div data-csc-toggle-track="1"
                                     data-on="<?php echo $is_on ? '1' : '0'; ?>"
                                     onclick="cscToggle(this)"
                                     style="position:relative;display:inline-block;width:44px;height:24px;min-width:44px;border-radius:24px;background:<?php echo $is_on ? '#00a32a' : '#c3c4c7'; ?>;cursor:pointer;transition:background 0.2s;flex-shrink:0;">
                                    <span style="position:absolute;top:3px;left:<?php echo $is_on ? '23px' : '3px'; ?>;width:18px;height:18px;background:#fff;border-radius:50%;box-shadow:0 1px 4px rgba(0,0,0,0.3);transition:left 0.2s;"></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <script>
                        function cscToggle(track) {
                            var isOn = track.getAttribute('data-on') === '1';
                            var newOn = !isOn;
                            track.setAttribute('data-on', newOn ? '1' : '0');
                            track.style.background = newOn ? '#00a32a' : '#c3c4c7';
                            track.querySelector('span').style.left = newOn ? '23px' : '3px';
                            // Update the hidden input
                            var row = track.parentNode;
                            var hidden = row.querySelector('input[type="hidden"][data-csc-toggle]');
                            if (hidden) hidden.value = newOn ? '1' : '0';
                        }
                        </script>
                        <div class="csc-button-row" style="margin-top:18px">
                            <button class="csc-btn csc-btn-primary csc-save-btn" data-group="db-types">Save Selection</button>
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-db">🔍 Dry Run — Preview</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-run-db">🗑 Run Cleanup Now</button>
                        </div>
                        <div class="csc-progress-outer" id="db-progress-outer" style="display:none">
                            <div class="csc-progress-bar"><div class="csc-progress-fill" id="db-progress-fill"></div></div>
                            <div class="csc-progress-label" id="db-progress-label">Preparing…</div>
                        </div>
                    </div>
                </div>
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-teal"><span>Cleanup Thresholds</span> <?php csc_explain_btn(
            'thresholds',
            'Cleanup Thresholds — What each setting means',
            [
                [ 'rec' => 'ℹ️ Info', 'name' => 'Why thresholds exist', 'desc' => 'Every threshold prevents the cleanup from touching items that are too recent to be considered safe. This protects you from accidentally deleting a draft you were actively working on or a comment that arrived in spam yesterday.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Post revisions older than N days', 'desc' => 'Only revisions created more than N days ago are deleted. Very recent revisions are kept so you can still roll back recent edits. Default: 30 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Draft posts older than N days', 'desc' => 'Only posts in Draft status whose last-modified date is older than N days are deleted. A draft you edited yesterday will never be touched. Default: 90 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Trashed posts older than N days', 'desc' => 'Posts moved to the WordPress trash older than N days are permanently deleted. Default: 30 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Auto-drafts older than N days', 'desc' => 'The empty placeholder records WordPress creates when you open the editor. These are nearly always safe to delete immediately — a threshold of 7 days gives you a buffer. Default: 7 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Spam comments older than N days', 'desc' => 'Comments flagged as spam by Akismet or manually. Keeping them for 30 days lets you review false positives before permanent deletion. Default: 30 days.' ],
                [ 'rec' => '✅ Recommended', 'name' => 'Trashed comments older than N days', 'desc' => 'Comments you have manually moved to the WordPress comment trash. The threshold gives you a safety window to change your mind. Default: 30 days.' ],
            ],
            '#ff6d00'
        ); ?></div>
                    <div class="csc-card-body csc-settings-inline">
                        <label>Post revisions older than   <input type="number" class="csc-setting" name="csc_post_revisions_age" value="<?php echo esc_attr( get_option( 'csc_post_revisions_age', 30 ) ); ?>" min="1"> days</label>
                        <label>Draft posts older than       <input type="number" class="csc-setting" name="csc_drafts_age"         value="<?php echo esc_attr( get_option( 'csc_drafts_age',         90 ) ); ?>" min="1"> days</label>
                        <label>Trashed posts older than     <input type="number" class="csc-setting" name="csc_trash_age"          value="<?php echo esc_attr( get_option( 'csc_trash_age',          30 ) ); ?>" min="1"> days</label>
                        <label>Auto-drafts older than       <input type="number" class="csc-setting" name="csc_autodraft_age"      value="<?php echo esc_attr( get_option( 'csc_autodraft_age',       7 ) ); ?>" min="1"> days</label>
                        <label>Spam comments older than     <input type="number" class="csc-setting" name="csc_spam_comments_age"  value="<?php echo esc_attr( get_option( 'csc_spam_comments_age',  30 ) ); ?>" min="1"> days</label>
                        <label>Trashed comments older than  <input type="number" class="csc-setting" name="csc_trash_comments_age" value="<?php echo esc_attr( get_option( 'csc_trash_comments_age', 30 ) ); ?>" min="1"> days</label>
                        <div style="margin-top:8px"><button class="csc-btn csc-btn-primary csc-save-btn" data-group="db">Save Thresholds</button></div>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-slate-db"><span>Scheduled Database Cleanup</span> <?php csc_explain_btn(
            'db-schedule',
            'Scheduled Database Cleanup — How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What scheduling does', 'desc' => 'When enabled, the plugin registers a WordPress Cron job that automatically runs the full database cleanup on the selected days at the configured hour — without you having to log in manually.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Day and hour selection', 'desc' => 'You can select multiple days per week. The hour is in your server local time, not your browser timezone. Most VPS hosts default to UTC.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'How WordPress Cron works', 'desc' => 'WordPress Cron is triggered by page visits, not a real system clock. On low-traffic sites a job scheduled for 3AM may not run until the first visitor arrives. For precise scheduling, disable WP-Cron in wp-config.php and add a real server cron.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'After each scheduled run', 'desc' => 'The plugin automatically schedules the next run after each execution, so the schedule remains active indefinitely without any manual intervention.' ],
            ],
            '#f48fb1'
        ); ?></div>
                <div class="csc-card-body">
                    <label class="csc-toggle-label">
                        <input type="checkbox" name="csc_schedule_db_enabled" value="1" <?php checked( get_option( 'csc_schedule_db_enabled', '0' ), '1' ); ?>>
                        Enable automatic scheduled cleanup
                    </label>
                    <div class="csc-schedule-row">
                        <?php foreach ( $dow as $val => $label ) : ?>
                        <label class="csc-day-label">
                            <input type="checkbox" name="csc_schedule_db_days[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, $db_sched_days, true ), true ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="csc-hour-label">at hour <input type="number" name="csc_schedule_db_hour" class="csc-small-num" value="<?php echo esc_attr( get_option( 'csc_schedule_db_hour', 3 ) ); ?>" min="0" max="23"> (server time)</label>
                    </div>
                    <button class="csc-btn csc-btn-primary csc-save-btn" data-group="db-schedule">Save Schedule</button>
                    <?php
                    $next_db_sched = wp_next_scheduled( 'csc_scheduled_db_cleanup' );
                    $last_db_sched = get_option( 'csc_last_scheduled_db_cleanup', '' );
                    ?>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#00c853 0%,#69f0ae 100%);color:#003d00;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(0,200,83,0.3)">✅ Last Run: <?php echo $last_db_sched ? esc_html( date_i18n( 'D j M Y H:i', strtotime( $last_db_sched ) ) ) : 'Never'; ?></span>
                        <?php if ( $next_db_sched ) : ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#2979ff 0%,#82b1ff 100%);color:#fff;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(41,121,255,0.3)">⏰ Next Run: <?php echo esc_html( date_i18n( 'D j M Y H:i', $next_db_sched ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark">Output Log</div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#0d1b2a;border-bottom:2px solid #00e5ff;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#00e5ff;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#00e5ff;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#00e5ff;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#00e5ff;color:#0d1b2a;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">⚙ Database Console</span></div>
                    <pre class="csc-terminal" id="db-terminal">Ready. Press Dry Run to preview what will be removed, then review the output log before running cleanup.</pre>
                </div>
            </div>
        </div>

        <!-- ═══ Image Cleanup ═══ -->
        <div class="csc-tab-content" id="tab-img-cleanup">
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-purple"><span>Unused Media</span> <?php csc_explain_btn(
            'unused-images',
            'Unused Media — How detection works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What unused means', 'desc' => 'An attachment is considered unused if it cannot be found in post content, featured images, widget settings, theme mods, the site logo, or the site icon. These are media files registered in WordPress but not referenced anywhere on your site.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'What is always protected', 'desc' => 'The site logo and site icon set in Appearance Customize are never flagged as unused, regardless of whether they appear in post content.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'The recycle workflow', 'desc' => 'Unused media is moved to a recycle bin rather than deleted immediately. The recycle bin stores the full attachment record, metadata, and all physical files so they can be completely restored if needed. Only Permanently Delete removes files for good.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Chunked processing', 'desc' => 'Moves are processed in batches of 25 per request so the operation never risks hitting PHP timeout limits, even on shared hosting with libraries of thousands of images.' ],
            [ 'rec' => '💡 Tip', 'name' => 'Always dry run first', 'desc' => 'Press Dry Run to preview which media will be flagged as unused, then review the output log carefully before moving to recycle. No files are touched until you press the recycle button.' ],
            ],
            '#00e5ff'
        ); ?></div>
                    <div class="csc-card-body">
                        <p>Finds media library attachments not referenced in any post, page, featured image, widget, or theme setting. These are registered in WordPress but nothing links to them. Moves are chunked at <?php echo CSC_CHUNK_IMAGES; ?> per request with a live progress bar.</p>
                        <div class="csc-button-row">
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-img">🔍 Dry Run — Preview</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-run-img">♻️ Move to Recycle</button>
                        </div>
                        <div class="csc-progress-outer" id="img-progress-outer" style="display:none">
                            <div class="csc-progress-bar"><div class="csc-progress-fill" id="img-progress-fill"></div></div>
                            <div class="csc-progress-label" id="img-progress-label">Preparing…</div>
                        </div>
                        <div id="media-recycle-actions" style="margin-top:12px;padding:12px 14px;background:#f3e5f5;border:1px solid #ce93d8;border-radius:6px">
                            <strong style="font-size:12px;color:#4a148c">♻️ Media Recycle Bin</strong>
                            <span id="media-recycle-count" style="font-size:12px;color:#4a148c;margin-left:6px">— checking…</span>
                            <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                                <button class="csc-btn" id="btn-restore-media"
                                    style="background:#43a047;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    ↩️ Restore All
                                </button>
                                <button class="csc-btn" id="btn-purge-media"
                                    style="background:#b71c1c;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    🗑 Permanently Delete
                                </button>
                                <button class="csc-btn" id="btn-browse-media-recycle"
                                    style="background:#5c6bc0;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    📂 View Recycle Bin
                                </button>
                            </div>
                        </div>

                        <!-- Media Recycle Bin Browser Modal -->
                        <div id="csc-media-recycle-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100000;background:rgba(0,0,0,0.6);padding:20px;overflow-y:auto">
                            <div style="max-width:900px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);overflow:hidden">
                                <div style="background:linear-gradient(135deg,#7b1fa2 0%,#9c27b0 100%);color:#fff;padding:16px 24px;display:flex;align-items:center;justify-content:space-between">
                                    <div>
                                        <div style="font-size:18px;font-weight:700">♻️ Media Recycle Bin</div>
                                        <div id="media-recycle-modal-summary" style="font-size:12px;opacity:0.8;margin-top:2px">Loading…</div>
                                    </div>
                                    <button id="btn-media-recycle-modal-close"
                                        style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:20px;width:32px;height:32px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
                                        ✕
                                    </button>
                                </div>
                                <div id="media-recycle-modal-list" style="max-height:500px;overflow-y:auto;padding:0 24px"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-amber"><span>Unregistered Files</span> <?php csc_explain_btn(
            'orphan-files',
            'Unregistered Files — What they are',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What an unregistered file is', 'desc' => 'A file that exists physically on disk inside wp-content/uploads but has no corresponding WordPress media library record in the database. Unlike unused media (which has a database record but no references), these files have no database record at all.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'How they accumulate', 'desc' => 'Files uploaded via FTP without being registered in WordPress, partially completed uploads, images imported without the media importer, or files left behind by deleted plugins.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'The recycle workflow', 'desc' => 'Scan finds unregistered files. Move to Recycle places them in wp-content/uploads/.csc-recycle/ with a manifest recording their original paths. Restore moves them back exactly. Permanently Delete wipes the recycle bin.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'What the scan checks', 'desc' => 'Every file found recursively under the uploads directory is checked against files registered in _wp_attached_file and _wp_attachment_metadata, plus any file URLs referenced in published post content. Files not in any of these sets are reported as unregistered.' ],
            ],
            '#ff4081'
        ); ?></div>
                    <div class="csc-card-body">
                        <p>Finds files sitting in your uploads folder that have no matching record in the WordPress media library. Typically from FTP uploads, failed imports, or plugins that wrote files directly. Use the recycle workflow to safely remove them.</p>
                        <script>
                        var cscPillOff = 'display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #c3c4c7;font-size:12px;font-weight:700;cursor:pointer;background:#fff;color:#50575e;margin:0 4px 4px 0';
                        var cscPillOn  = 'display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0';
                        window.cscOrphanTypes = ['images','documents','video','audio'];
                        function cscOrphanToggle(el, type) {
                            var idx = window.cscOrphanTypes.indexOf(type);
                            if (idx === -1) {
                                window.cscOrphanTypes.push(type);
                                el.style.cssText = cscPillOn;
                            } else {
                                window.cscOrphanTypes.splice(idx, 1);
                                el.style.cssText = cscPillOff;
                            }
                            var joined = window.cscOrphanTypes.join(',');
                            document.getElementById('btn-scan-orphan').setAttribute('data-ftype', joined);
                            document.getElementById('btn-recycle-orphan').setAttribute('data-ftype', joined);
                        }
                        </script>
                        <div style="display:flex;gap:0;flex-wrap:wrap;margin-bottom:14px">
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'images')"    style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">🖼 Images</span>
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'documents')" style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">📄 Documents</span>
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'video')"     style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">🎬 Video</span>
                            <span class="csc-orphan-pill" onclick="cscOrphanToggle(this,'audio')"     style="display:inline-block;padding:6px 14px;border-radius:20px;border:2px solid #00a32a;font-size:12px;font-weight:700;cursor:pointer;background:#00a32a;color:#fff;margin:0 4px 4px 0">🎵 Audio</span>
                        </div>
                        <div class="csc-button-row" style="flex-wrap:wrap;gap:10px">
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-orphan">🔍 Scan Unregistered Files</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-recycle-orphan">♻️ Move to Recycle</button>
                        </div>
                        <div id="orphan-recycle-actions" style="margin-top:12px;padding:12px 14px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px">
                            <strong style="font-size:12px;color:#5d4037">♻️ Recycle Bin</strong>
                            <span id="orphan-recycle-count" style="font-size:12px;color:#5d4037;margin-left:6px">— checking…</span>
                            <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
                                <button class="csc-btn" id="btn-restore-orphan"
                                    style="background:#43a047;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    ↩️ Restore All
                                </button>
                                <button class="csc-btn" id="btn-purge-orphan"
                                    style="background:#b71c1c;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    🗑 Permanently Delete
                                </button>
                                <button class="csc-btn" id="btn-browse-recycle"
                                    style="background:#5c6bc0;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">
                                    📂 View Recycle Bin
                                </button>
                            </div>
                        </div>

                        <!-- Recycle Bin Browser Modal -->
                        <div id="csc-recycle-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:100000;background:rgba(0,0,0,0.6);padding:20px;overflow-y:auto">
                            <div style="max-width:900px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);overflow:hidden">
                                <div style="background:linear-gradient(135deg,#5c6bc0 0%,#3949ab 100%);color:#fff;padding:16px 24px;display:flex;align-items:center;justify-content:space-between">
                                    <div>
                                        <div style="font-size:18px;font-weight:700">♻️ Recycle Bin Browser</div>
                                        <div id="recycle-modal-summary" style="font-size:12px;opacity:0.8;margin-top:2px">Loading…</div>
                                    </div>
                                    <div style="display:flex;gap:8px;align-items:center">
                                        <button id="btn-recycle-copy-all"
                                            style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:6px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer">
                                            📋 Copy All to Clipboard
                                        </button>
                                        <button id="btn-recycle-modal-close"
                                            style="background:rgba(255,255,255,0.2);color:#fff;border:1px solid rgba(255,255,255,0.3);border-radius:20px;width:32px;height:32px;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center">
                                            ✕
                                        </button>
                                    </div>
                                </div>
                                <div style="padding:16px 24px;border-bottom:1px solid #e0e0e0">
                                    <input type="text" id="recycle-search" placeholder="Search files…" style="width:100%;padding:10px 14px;border:2px solid #e0e0e0;border-radius:8px;font-size:14px;outline:none;transition:border-color 0.15s" onfocus="this.style.borderColor='#5c6bc0'" onblur="this.style.borderColor='#e0e0e0'">
                                </div>
                                <div id="recycle-modal-list" style="max-height:500px;overflow-y:auto;padding:0 24px"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-slate-img"><span>Scheduled Media Cleanup</span> <?php csc_explain_btn(
            'img-schedule',
            'Scheduled Media Cleanup — How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it does', 'desc' => 'Runs the unused media cleanup automatically on the selected days and hour. Unused attachments are moved to the media recycle bin (not deleted). The same detection logic as the manual cleanup is used — the site logo and site icon are always protected.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Use with caution on active sites', 'desc' => 'Automated cleanup is most appropriate for sites where images are always attached to posts via the standard WordPress editor. Review a manual dry run before enabling the schedule.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'After each run', 'desc' => 'The next scheduled run is automatically registered, so the schedule stays active indefinitely. You can review and restore recycled media at any time from the Media Cleanup tab.' ],
            ],
            '#69f0ae'
        ); ?></div>
                <div class="csc-card-body">
                    <label class="csc-toggle-label">
                        <input type="checkbox" name="csc_schedule_img_enabled" value="1" <?php checked( get_option( 'csc_schedule_img_enabled', '0' ), '1' ); ?>>
                        Enable automatic scheduled cleanup
                    </label>
                    <div class="csc-schedule-row">
                        <?php foreach ( $dow as $val => $label ) : ?>
                        <label class="csc-day-label">
                            <input type="checkbox" name="csc_schedule_img_days[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, $img_sched_days, true ), true ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </label>
                        <?php endforeach; ?>
                        <label class="csc-hour-label">at hour <input type="number" name="csc_schedule_img_hour" class="csc-small-num" value="<?php echo esc_attr( get_option( 'csc_schedule_img_hour', 4 ) ); ?>" min="0" max="23"> (server time)</label>
                    </div>
                    <button class="csc-btn csc-btn-primary csc-save-btn" data-group="img-schedule">Save Schedule</button>
                    <?php
                    $next_img_sched = wp_next_scheduled( 'csc_scheduled_img_cleanup' );
                    $last_img_sched = get_option( 'csc_last_scheduled_img_cleanup', '' );
                    ?>
                    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:10px">
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#ff6d00 0%,#ffab40 100%);color:#3e1c00;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(255,109,0,0.3)">✅ Last Run: <?php echo $last_img_sched ? esc_html( date_i18n( 'D j M Y H:i', strtotime( $last_img_sched ) ) ) : 'Never'; ?></span>
                        <?php if ( $next_img_sched ) : ?>
                        <span style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#aa00ff 0%,#d500f9 100%);color:#fff;font-size:11.5px;font-weight:700;padding:5px 14px;border-radius:20px;letter-spacing:0.3px;box-shadow:0 2px 8px rgba(170,0,255,0.3)">⏰ Next Run: <?php echo esc_html( date_i18n( 'D j M Y H:i', $next_img_sched ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#d84315 0%,#ff5722 100%)"><span>🔗 Broken Image Links</span> <?php csc_explain_btn(
            'broken-images',
            'Broken Image Links — How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it does', 'desc' => 'Scans all published posts and pages for image URLs (img src attributes) that reference files in your uploads directory. For each URL found it checks whether the file actually exists on disk.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it finds', 'desc' => 'Detects broken images caused by deleted files, renamed files (e.g. dimensions appended like photo-1024x768.jpg), moved files, or failed optimisations.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Safe and read only', 'desc' => 'This scan does not modify anything. It only reports what it finds so you can fix the issues manually or with a search and replace plugin.' ],
            ],
            '#ff8a65'
        ); ?></div>
                <div class="csc-card-body">
                    <p style="margin:0 0 12px;font-size:13px;color:#50575e;line-height:1.5">Scan all published content for image URLs pointing to files that no longer exist on disk.</p>
                    <div class="csc-button-row" style="flex-wrap:wrap;gap:10px">
                        <button class="csc-btn csc-btn-secondary" id="btn-scan-broken-images">🔍 Scan for Broken Images</button>
                        <button class="csc-btn" id="btn-copy-broken-images" style="display:none;background:#5c6bc0;color:#fff;border:none;border-radius:6px;padding:7px 18px;font-size:13px;font-weight:600;cursor:pointer">📋 Copy Results</button>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark">Output Log</div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#1a0533;border-bottom:2px solid #e040fb;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#e040fb;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#e040fb;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#e040fb;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#e040fb;color:#fff;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">🖼 Image Console</span></div>
                    <pre class="csc-terminal" id="img-terminal">Ready. Press Dry Run to preview which media will be flagged as unused, then review the output log before moving to recycle.</pre>
                </div>
            </div>
        </div>

        <!-- ═══ Image Optimisation ═══ -->
        <div class="csc-tab-content" id="tab-img-optimise">
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-red"><span>Image Optimisation</span> <?php csc_explain_btn(
            'img-optimise',
            'Image Optimisation — How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'What it does', 'desc' => 'Processes your original uploaded images in two ways: Resize (scales down images exceeding your configured maximum, preserving aspect ratio) and Recompress (re-saves JPEG files at the configured quality level).' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Thumbnail regeneration', 'desc' => 'After each image is processed, all registered WordPress thumbnail sizes are regenerated from the new optimised original to ensure consistency.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'PNG to JPEG conversion', 'desc' => 'When enabled, PNG files without transparency are converted to JPEG. Photographic PNGs typically shrink by 40-70% when converted. PNG files with transparency are never converted.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Chunked processing', 'desc' => 'Images are processed 5 at a time per request to keep each request well under 30 seconds. Always take a full site backup before running on a production site.' ],
            [ 'rec' => '💡 Tip', 'name' => 'Always dry run first', 'desc' => 'Press Dry Run to preview potential savings, then review the output log carefully before optimising. No files are modified until you press the optimise button.' ],
            ],
            '#ff1744'
        ); ?></div>
                    <div class="csc-card-body">
                        <p>Resizes oversized originals and recompresses JPEGs to the configured quality target. Processes <?php echo CSC_CHUNK_OPTIMISE; ?> images per request — chunked to stay well within any server's PHP timeout limit regardless of media library size. All WordPress thumbnail sizes are regenerated after each image is processed.</p>
                        <div class="csc-button-row">
                            <button class="csc-btn csc-btn-secondary" id="btn-scan-optimise">🔍 Dry Run — Preview Savings</button>
                            <button class="csc-btn csc-btn-danger"    id="btn-run-optimise">⚡ Optimise Images Now</button>
                        </div>
                        <div class="csc-progress-outer" id="opt-progress-outer" style="display:none">
                            <div class="csc-progress-bar"><div class="csc-progress-fill" id="opt-progress-fill"></div></div>
                            <div class="csc-progress-label" id="opt-progress-label">Preparing…</div>
                        </div>
                        <p class="csc-note">This modifies original image files on disk. Take a full backup first. WordPress 5.3+ preserves a scaled backup original automatically.</p>
                    </div>
                </div>
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-green"><span>Optimisation Settings</span> <?php csc_explain_btn(
            'opt-settings',
            'Optimisation Settings — What each option does',
            [
            [ 'rec' => '✅ Recommended', 'name' => 'Maximum width and height (px)', 'desc' => 'Any image whose width or height exceeds the maximum will be scaled down proportionally. Default: 1920x1080. If your theme never displays images wider than 1200px, setting the max to 1200 will produce better storage savings.' ],
            [ 'rec' => '✅ Recommended', 'name' => 'JPEG quality (1-100)', 'desc' => 'Controls compression when saving JPEG files. 80-85 is the sweet spot — excellent quality with significant size reduction. Default: 82.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Convert non-transparent PNGs to JPEG', 'desc' => 'Converts eligible PNGs (those with no transparency) to JPEG. A PNG screenshot at 200KB will typically become a 40-80KB JPEG with no visible difference at screen resolution.' ],
            ],
            '#ffd600'
        ); ?></div>
                    <div class="csc-card-body csc-settings-inline">
                        <label>Maximum width (px)   <input type="number" class="csc-setting" name="csc_img_max_width"  value="<?php echo esc_attr( get_option( 'csc_img_max_width',  1920 ) ); ?>" min="200"></label>
                        <label>Maximum height (px)  <input type="number" class="csc-setting" name="csc_img_max_height" value="<?php echo esc_attr( get_option( 'csc_img_max_height', 1080 ) ); ?>" min="200"></label>
                        <label>JPEG quality (1–100) <input type="number" class="csc-setting" name="csc_img_quality"    value="<?php echo esc_attr( get_option( 'csc_img_quality',    82   ) ); ?>" min="1" max="100"></label>
                        <label class="csc-toggle-label">
                            <input type="checkbox" name="csc_convert_png_to_jpg" value="1" <?php checked( get_option( 'csc_convert_png_to_jpg', '1' ), '1' ); ?>>
                            Convert non-transparent PNGs to JPEG
                        </label>
                        <p class="csc-note">Recommended defaults: 1920&times;1080 · quality 82. PNG conversion yields 40–70% size reduction on photographic images.</p>
                        <button class="csc-btn csc-btn-primary csc-save-btn" data-group="optimise">Save Settings</button>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark">Output Log</div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#0a1f00;border-bottom:2px solid #76ff03;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#76ff03;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#76ff03;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#76ff03;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#76ff03;color:#0a1f00;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">⚡ Optimisation Console</span></div>
                    <pre class="csc-terminal" id="optimise-terminal">Ready. Press Dry Run to preview savings, then review the output log before optimising.</pre>
                </div>
            </div>
        </div>

        <!-- ═══ PNG to JPEG Converter ═══ -->
        <div class="csc-tab-content" id="tab-png-to-jpeg">
            <?php
            $cspj_chunk_mb     = csc_get_cspj_chunk_mb();
            $cspj_server_max   = csc_get_cspj_server_max_mb();
            ?>
            <div class="csc-cards-row">
                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-purple"><span>Upload PNG Files</span> <?php csc_explain_btn(
            'png-upload',
            'PNG to JPEG Upload — How it works',
            [
            [ 'rec' => 'ℹ️ Info', 'name' => 'Drag and drop', 'desc' => 'Drag PNG files onto the upload area, or click Browse to select files from your computer. Multiple files can be queued at once.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Transparency handling', 'desc' => 'PNG transparency is composited onto a white background before conversion to JPEG.' ],
            [ 'rec' => 'ℹ️ Info', 'name' => 'Chunked uploads', 'desc' => 'Large files are automatically split into chunks to stay within your server upload limits. Each chunk is reassembled server side before conversion.' ],
            ],
            '#00e5ff'
        ); ?></div>
                    <div class="csc-card-body">
                        <div class="cspj-upload-area" id="cspj-drop-zone">
                            <div class="cspj-upload-icon">📷</div>
                            <p>Drag & drop PNG files here</p>
                            <label for="cspj-file-input" id="cspj-browse-btn">📂 Browse to Select</label>
                            <p class="csc-note" id="cspj-limit-hint" style="margin-top:14px;text-align:center">Server request limit: <strong><?php echo esc_html( $cspj_server_max ); ?> MB</strong> · Chunk size: <strong><?php echo esc_html( $cspj_chunk_mb ); ?> MB</strong></p>
                            <input type="file" id="cspj-file-input" accept="image/png" multiple style="position:absolute; left:-9999px;">
                        </div>
                    </div>
                </div>

                <div class="csc-card">
                    <div class="csc-card-header csc-card-header-green"><span>Conversion Settings</span> <?php csc_explain_btn(
            'png-settings',
            'PNG to JPEG Settings — What each option does',
            [
            [ 'rec' => '✅ Recommended', 'name' => 'JPEG Quality (1 to 100)', 'desc' => 'Controls compression when saving JPEG files. 80 to 92 is the sweet spot — excellent visual quality with significant size reduction. Use 95+ for print, 60 to 75 for web thumbnails.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Output Size', 'desc' => 'Choose a preset resolution or Custom dimensions. Enable Constrain proportions to auto calculate the missing dimension.' ],
            [ 'rec' => '⬜ Optional', 'name' => 'Chunk Size', 'desc' => 'Controls how large each upload chunk is. Keep below your server request limit. Default 1.5 MB works on most hosts.' ],
            ],
            '#ffd600'
        ); ?></div>
                    <div class="csc-card-body csc-settings-inline">
                        <label>JPEG Quality
                            <div style="display:flex;align-items:center;gap:12px;width:100%">
                                <input type="range" id="cspj-quality" min="1" max="100" value="90" style="flex:1;accent-color:var(--csc-accent2)">
                                <span id="cspj-quality-val" style="font-size:20px;font-weight:800;color:var(--csc-accent);min-width:36px;text-align:center">90</span>
                            </div>
                        </label>
                        <label>Output Size
                            <select id="cspj-size" style="width:100%;padding:8px 10px;border:1px solid var(--csc-border);border-radius:5px;font-size:13px;background:#f8f9fc">
                                <option value="original">Original size (unchanged)</option>
                                <option value="3840x2160">4K — 3840 × 2160</option>
                                <option value="2560x1440">2K / QHD — 2560 × 1440</option>
                                <option value="1920x1080">Full HD — 1920 × 1080</option>
                                <option value="1280x720">HD — 1280 × 720</option>
                                <option value="1024x768">XGA — 1024 × 768</option>
                                <option value="800x600">SVGA — 800 × 600</option>
                                <option value="640x480">VGA — 640 × 480</option>
                                <option value="512x512">Square — 512 × 512</option>
                                <option value="256x256">Square — 256 × 256</option>
                                <option value="custom">Custom…</option>
                            </select>
                        </label>
                        <div id="cspj-custom-size" style="display:none;margin-top:4px">
                            <label style="gap:4px">
                                <input type="number" id="cspj-custom-w" placeholder="Width px" class="csc-setting" style="width:90px">
                                ×
                                <input type="number" id="cspj-custom-h" placeholder="Height px" class="csc-setting" style="width:90px">
                            </label>
                            <label class="csc-toggle-label" style="margin-top:6px">
                                <input type="checkbox" id="cspj-constrain" checked> Constrain proportions
                            </label>
                        </div>
                        <label>Chunk Size
                            <div style="display:flex;align-items:center;gap:8px">
                                <input type="number" id="cspj-chunk-mb" min="0.25" max="1.95" step="0.05" value="<?php echo esc_attr( $cspj_chunk_mb ); ?>" class="csc-setting" style="width:80px">
                                <span style="font-size:12px;color:var(--csc-muted)">MB</span>
                                <button class="csc-btn csc-btn-primary" id="cspj-save-chunkmb" style="padding:6px 14px;font-size:12px">Save</button>
                                <span id="cspj-save-status" style="font-size:12px"></span>
                            </div>
                        </label>
                        <p class="csc-note">Server limit: <?php echo esc_html( $cspj_server_max ); ?> MB. Keep chunk size below the server limit. Default is 1.5 MB.</p>
                    </div>
                </div>
            </div>

            <div class="csc-card" id="cspj-queue-card" style="display:none">
                <div class="csc-card-header csc-card-header-amber"><span>File Queue</span></div>
                <div class="csc-card-body">
                    <div id="cspj-file-list"></div>
                    <div class="csc-button-row">
                        <button class="csc-btn csc-btn-danger" id="cspj-convert-all">⚡ Convert All</button>
                        <button class="csc-btn csc-btn-secondary" id="cspj-clear-all">Clear Queue</button>
                    </div>
                </div>
            </div>

            <div class="csc-card" id="cspj-results" style="display:none">
                <div class="csc-card-header csc-card-header-green"><span>Converted Files</span></div>
                <div class="csc-card-body">
                    <div id="cspj-results-list"></div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-dark">Debug Console</div>
                <div class="csc-card-body csc-terminal-wrap">
                    <div style="display:flex;align-items:center;gap:6px;padding:4px 12px;background:#1a0533;border-bottom:2px solid #ce93d8;border-radius:6px 6px 0 0"><span style="width:7px;height:7px;border-radius:50%;background:#ce93d8;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#ce93d8;opacity:.5;display:inline-block;flex-shrink:0"></span><span style="width:7px;height:7px;border-radius:50%;background:#ce93d8;opacity:.25;display:inline-block;flex-shrink:0"></span><span style="margin-left:8px;background:#ce93d8;color:#1a0533;font-family:monospace;font-size:10px;font-weight:800;letter-spacing:.1em;padding:2px 10px;border-radius:20px;text-transform:uppercase">📷 Converter Console</span>
                        <span style="margin-left:auto;display:flex;gap:6px">
                            <button id="cspj-debug-copy" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:6px;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;cursor:pointer">📋 Copy</button>
                            <button id="cspj-debug-clear" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:6px;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;cursor:pointer">🗑 Clear</button>
                            <button id="cspj-debug-toggle" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:6px;color:#fff;font-size:11px;font-weight:600;padding:3px 10px;cursor:pointer">▼</button>
                        </span>
                    </div>
                    <div id="cspj-debug-env" style="padding:10px 14px;background:#f8f9fc;border-bottom:1px solid var(--csc-border);font-size:11px;color:var(--csc-muted);font-family:monospace;line-height:1.7"></div>
                    <pre class="csc-terminal" id="cspj-debug-log" style="min-height:120px;max-height:320px">Ready. Drop PNG files above to get started.</pre>
                </div>
            </div>

            <!-- Rename popup -->
            <div id="cspj-popup-rename" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.6);align-items:center;justify-content:center;padding:16px">
                <div style="background:#fff;border-radius:10px;max-width:480px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,0.4)">
                    <div style="background:#1a2a3a;border-radius:10px 10px 0 0;padding:14px 20px;display:flex;justify-content:space-between;align-items:center">
                        <strong style="color:#fff;font-size:14px">💾 Save to Media Library</strong>
                        <button type="button" id="cspj-rename-cancel" style="background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);border-radius:5px;color:#fff;font-size:16px;font-weight:700;padding:2px 10px;cursor:pointer;line-height:1">✕</button>
                    </div>
                    <div style="padding:20px 24px">
                        <p style="margin:0 0 10px;font-size:13px;color:#1d2327">Enter a filename for this image in the Media Library. The <code>.jpg</code> extension will be added automatically.</p>
                        <input type="text" id="cspj-rename-input" style="width:100%;padding:9px 12px;border:2px solid var(--csc-border);border-radius:6px;font-size:14px;box-sizing:border-box" placeholder="e.g. my-product-hero">
                        <div id="cspj-rename-error" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:8px 12px;font-size:13px;color:#991b1b;margin-top:8px"></div>
                        <div style="display:flex;gap:10px;margin-top:14px">
                            <button id="cspj-rename-confirm" class="csc-btn csc-btn-primary">💾 Add to Library</button>
                            <button id="cspj-rename-cancel-2" class="csc-btn" style="background:#f1f5f9;color:var(--csc-muted);border:1px solid var(--csc-border)">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Site Health ═══ -->
        <div class="csc-tab-content active" id="tab-site-health">
            <div class="csc-card">
                <div class="csc-card-header" style="background:linear-gradient(135deg,#1b5e20 0%,#43a047 100%)">
                    <span>📊 Site Health Overview</span>
                    <?php csc_explain_btn( 'site-health', 'Site Health Metrics', array(
                        array(
                            'name' => 'Disk Storage Tracking',
                            'rec'  => 'Recommended',
                            'desc' => 'Measures wp-content disk usage weekly. Calculates growth rate over 3 months and estimates weeks until disk full. Green = 6+ months remaining, Amber = 3 to 6 months, Red = under 3 months.',
                        ),
                        array(
                            'name' => 'CPU Peak Monitoring',
                            'rec'  => 'Recommended',
                            'desc' => 'Records the maximum CPU usage percentage each hour. With sysstat installed, captures per minute peaks from sar (so brief spikes are caught). Without sysstat, falls back to an instantaneous load average snapshot.',
                        ),
                        array(
                            'name' => 'Memory Peak Monitoring',
                            'rec'  => 'Recommended',
                            'desc' => 'Records the maximum memory usage percentage each hour. With sysstat installed, captures per minute peaks from sar. Without sysstat, falls back to an instantaneous /proc/meminfo reading.',
                        ),
                        array(
                            'name' => 'sysstat (sar)',
                            'rec'  => 'Recommended',
                            'desc' => 'sysstat continuously samples CPU and memory every 10 minutes in the background, giving accurate peak values rather than single point snapshots. Without sysstat, the plugin falls back to instantaneous readings which may show 0% on lightly loaded servers.\n\nStep 1: Install sysstat.\nAmazon Linux / RHEL: sudo yum install sysstat -y\nUbuntu / Debian: sudo apt install sysstat -y\n\nStep 2: Enable the service and the collection timer.\nsudo systemctl enable sysstat && sudo systemctl start sysstat\nsudo systemctl enable sysstat-collect.timer && sudo systemctl start sysstat-collect.timer\n\nStep 3: Force an immediate first collection (optional, otherwise wait 10 minutes).\nsudo /usr/lib64/sa/sa1 1 1\n\nStep 4: Verify data is being collected.\nLC_ALL=C /usr/bin/sar -u 2>&1 | tail -5\n\nYou should see CPU usage lines with timestamps. If you only see a header with no data, the collection timer is not running. The sysstat-collect.timer is the part that actually triggers data collection every 10 minutes. On Amazon Linux 2023, enabling sysstat alone is not enough; you must also enable sysstat-collect.timer separately.\n\nUse the Test Sysstat button below to verify the plugin can read sar data.',
                        ),
                        array(
                            'name' => 'Data Retention',
                            'rec'  => 'Info',
                            'desc' => 'Hourly metrics and weekly snapshots are automatically expired after 6 months (180 days). Stored in wp_options with autoload disabled to avoid memory overhead.',
                        ),
                        array(
                            'name' => 'Max Resource Percentage',
                            'rec'  => 'Info',
                            'desc' => 'Each hourly sample stores max(cpu%, mem%) as the single worst case resource metric. This helps identify whether the server is becoming constrained by CPU, memory, or both.',
                        ),
                    ) ); ?>
                </div>
                <div class="csc-card-body" id="csc-health-overview">
                    <div id="csc-health-loading" style="text-align:center;padding:30px;color:#888">Loading health metrics…</div>
                    <div id="csc-health-content" style="display:none">
                        <!-- RAG indicator -->
                        <div id="csc-health-rag-bar" style="display:flex;align-items:center;gap:16px;padding:16px 20px;border-radius:8px;margin-bottom:20px">
                            <div id="csc-health-rag-dot" style="width:24px;height:24px;border-radius:50%;flex-shrink:0"></div>
                            <div>
                                <div id="csc-health-rag-label" style="font-size:16px;font-weight:800"></div>
                                <div id="csc-health-rag-detail" style="font-size:13px;margin-top:2px;opacity:0.8"></div>
                            </div>
                        </div>

                        <!-- Row 1: Disk Storage (blue theme) -->
                        <div style="background:linear-gradient(135deg,#e3f2fd 0%,#f3f8ff 100%);border:1px solid #90caf9;border-radius:10px;padding:16px 20px;margin-bottom:14px">
                            <div style="font-size:13px;font-weight:800;color:#1565c0;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px">💾 Disk Storage</div>
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px">
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">wp-content</div>
                                    <div class="csc-health-metric-value" id="hm-disk-used" style="color:#0d47a1">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Disk Free</div>
                                    <div class="csc-health-metric-value" id="hm-disk-free" style="color:#1b5e20">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Disk Total</div>
                                    <div class="csc-health-metric-value" id="hm-disk-total" style="color:#263238">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Database</div>
                                    <div class="csc-health-metric-value" id="hm-db-size" style="color:#4a148c">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Growth / Week</div>
                                    <div class="csc-health-metric-value" id="hm-growth" style="color:#bf360c">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Est. Time to Storage Full</div>
                                    <div class="csc-health-metric-value" id="hm-weeks-left" style="color:#0d47a1">—</div>
                                </div>
                            </div>
                        </div>

                        <!-- Row 2: CPU (orange theme) -->
                        <div style="background:linear-gradient(135deg,#fff3e0 0%,#fffaf4 100%);border:1px solid #ffcc80;border-radius:10px;padding:16px 20px;margin-bottom:14px">
                            <div style="font-size:13px;font-weight:800;color:#e65100;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px">⚡ CPU</div>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Current</div>
                                    <div class="csc-health-metric-value" id="hm-cpu-now" style="color:#bf360c">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (24h)</div>
                                    <div class="csc-health-metric-value" id="hm-cpu-24h" style="color:#bf360c">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (7d)</div>
                                    <div class="csc-health-metric-value" id="hm-cpu-7d" style="color:#bf360c">—</div>
                                </div>
                            </div>
                        </div>

                        <!-- Row 3: Memory (purple theme) -->
                        <div style="background:linear-gradient(135deg,#f3e5f5 0%,#faf5fc 100%);border:1px solid #ce93d8;border-radius:10px;padding:16px 20px;margin-bottom:20px">
                            <div style="font-size:13px;font-weight:800;color:#7b1fa2;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:12px">🧠 Memory</div>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Current</div>
                                    <div class="csc-health-metric-value" id="hm-mem-now" style="color:#4a148c">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (24h)</div>
                                    <div class="csc-health-metric-value" id="hm-mem-24h" style="color:#4a148c">—</div>
                                </div>
                                <div class="csc-health-metric" style="background:transparent;border-color:transparent;border-radius:8px">
                                    <div class="csc-health-metric-label" style="color:inherit!important;font-size:12px!important;opacity:0.7">Peak (7d)</div>
                                    <div class="csc-health-metric-value" id="hm-mem-7d" style="color:#4a148c">—</div>
                                </div>
                            </div>
                        </div>

                        <!-- Data collection status -->
                        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:12px 16px;background:#f8f9fc;border-radius:6px;border:1px solid #e0e0e0;margin-bottom:10px">
                            <span style="font-size:12px;color:#50575e">📈 Hourly samples: <strong id="hm-hourly-count">0</strong></span>
                            <span style="font-size:12px;color:#50575e">📅 Weekly snapshots: <strong id="hm-weekly-count">0</strong></span>
                            <span style="font-size:12px;color:#50575e">🕐 Last hourly: <strong id="hm-last-hourly">—</strong></span>
                            <span style="font-size:12px;color:#50575e">📅 Last weekly: <strong id="hm-last-weekly">—</strong></span>
                            <span style="font-size:12px;color:#50575e">📊 Data span: <strong id="hm-data-span">—</strong> weeks</span>
                        </div>

                        <!-- sysstat status -->
                        <div id="csc-sysstat-status" style="padding:12px 16px;border-radius:6px;border:1px solid #e0e0e0;margin-bottom:16px;font-size:12px;display:none">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                                <span id="csc-sysstat-icon" style="font-size:14px">—</span>
                                <strong id="csc-sysstat-label">Checking sysstat...</strong>
                                <span id="csc-sysstat-detail" style="color:#50575e"></span>
                            </div>
                            <div id="csc-sysstat-instructions" style="display:none;margin-top:8px;padding:8px 12px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:4px;font-family:monospace;font-size:11px;word-break:break-all"></div>
                        </div>

                        <div class="csc-button-row" style="gap:10px">
                            <button class="csc-btn csc-btn-secondary" id="btn-health-refresh">🔄 Refresh</button>
                            <button class="csc-btn csc-btn-primary" id="btn-health-collect">📊 Collect Now</button>
                            <button class="csc-btn csc-btn-secondary" id="btn-sysstat-test">🔧 Test Sysstat</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Settings ═══ -->
        <div class="csc-tab-content" id="tab-settings">
            <div class="csc-card">
                <div class="csc-card-header csc-card-header-blue">About CloudScale Cleanup</div>
                <div class="csc-card-body csc-about">
                    <p><strong>CloudScale Cleanup</strong> is a free, open source WordPress plugin by <a href="https://andrewbaker.ninja" target="_blank">Andrew Baker</a> — Chief Information Officer at Capitec Bank and author of a popular technology blog covering cloud architecture, banking technology, and enterprise software.</p>
                    <p>No accounts. No API keys. No subscriptions. No data leaves your server. All processing uses standard WordPress APIs.</p>
                    <div class="csc-about-links">
                        <a href="https://andrewbaker.ninja" target="_blank" class="csc-btn csc-btn-primary">andrewbaker.ninja</a>
                        <a href="https://andrewbaker.ninja/2026/02/24/cloudscale-free-backup-and-restore-a-wordpress-backup-plugin-that-does-exactly-what-it-says/" target="_blank" class="csc-btn csc-btn-secondary">CloudScale Backup Plugin</a>
                        <a href="https://andrewbaker.ninja/2026/02/24/cloudscale-seo-ai-optimiser-enterprise-grade-wordpress-seo-completely-free/" target="_blank" class="csc-btn csc-btn-secondary">CloudScale SEO Plugin</a>
                    </div>
                    <div class="csc-stats-grid" style="grid-template-columns:repeat(5,1fr)">
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Last DB Cleanup</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'csc_last_db_cleanup', 'Never' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Last Media Cleanup</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'csc_last_img_cleanup', 'Never' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Last Optimisation</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'csc_last_img_optimise', 'Never' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">PNG Conversions</div>
                            <div class="csc-stat-value"><?php echo esc_html( get_option( 'csc_total_png_conversions', '0' ) ); ?></div>
                        </div>
                        <div class="csc-stat-box">
                            <div class="csc-stat-label">Version</div>
                            <div class="csc-stat-value"><?php echo esc_html( CLOUDSCALE_CLEANUP_VERSION ); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="csc-card">
                <div class="csc-card-header csc-card-header-teal">WordPress Cron Status</div>
                <div class="csc-card-body">
                    <?php
                    $next_db  = wp_next_scheduled( 'csc_scheduled_db_cleanup' );
                    $next_img = wp_next_scheduled( 'csc_scheduled_img_cleanup' );
                    $db_en    = get_option( 'csc_schedule_db_enabled',  '0' ) === '1';
                    $img_en   = get_option( 'csc_schedule_img_enabled', '0' ) === '1';
                    ?>
                    <table class="csc-status-table">
                        <tr>
                            <td>DB Cleanup</td>
                            <td><span class="csc-badge <?php echo $db_en  ? 'csc-badge-green' : 'csc-badge-grey'; ?>"><?php echo $db_en  ? 'Enabled' : 'Disabled'; ?></span></td>
                            <td><?php echo $next_db  ? esc_html( 'Next: ' . date_i18n( 'D j M Y H:i', $next_db  ) ) : '—'; ?></td>
                        </tr>
                        <tr>
                            <td>Media Cleanup</td>
                            <td><span class="csc-badge <?php echo $img_en ? 'csc-badge-green' : 'csc-badge-grey'; ?>"><?php echo $img_en ? 'Enabled' : 'Disabled'; ?></span></td>
                            <td><?php echo $next_img ? esc_html( 'Next: ' . date_i18n( 'D j M Y H:i', $next_img ) ) : '—'; ?></td>
                        </tr>
                    </table>
                    <p class="csc-note">WordPress Cron fires when someone visits your site. On low-traffic sites scheduled jobs may run a few minutes after the configured time. For exact timing, add a real server cron job calling <code>wp-cron.php</code> directly.</p>
                </div>
            </div>
        </div>

        <div id="csc-save-notice" class="csc-save-notice" style="display:none">Settings saved.</div>

    </div>

    <style>
    /* Inline fallback: ensures 6th tab (Settings) always has brown background even if cached CSS lacks it */
    .csc-tab:nth-child(6) { background: linear-gradient(135deg, #5d4037 0%, #8d6e63 100%) !important; border-top-color: #bcaaa4 !important; }
    .csc-tab:nth-child(6).active, .csc-tab:nth-child(6):hover { border-top-color: #bcaaa4 !important; }
    /* Force all metric cards inside coloured sections to be transparent with themed labels */
    div[style*="#fff3e0"] .csc-health-metric,
    div[style*="#e3f2fd"] .csc-health-metric,
    div[style*="#f3e5f5"] .csc-health-metric { background: transparent !important; border-color: transparent !important; }
    div[style*="#fff3e0"] .csc-health-metric-label { color: #e65100 !important; }
    div[style*="#fff3e0"] .csc-health-metric-value { color: #bf360c !important; }
    div[style*="#e3f2fd"] .csc-health-metric-label { color: #1565c0 !important; }
    div[style*="#f3e5f5"] .csc-health-metric-label { color: #7b1fa2 !important; }
    </style>

    <script>
    /* Inline: health render, auto load, and button handlers (cache proof) */
    jQuery(function($) {
        var fmt = function(b) { if (b >= 1073741824) return (b/1073741824).toFixed(2)+' GB'; if (b >= 1048576) return (b/1048576).toFixed(1)+' MB'; return (b/1024).toFixed(0)+' KB'; };
        var ragColors = {green:'#2e7d32',amber:'#e65100',red:'#c62828',grey:'#78909c'};
        var ragBgs = {green:'#e8f5e9',amber:'#fff3e0',red:'#ffebee',grey:'#f5f5f5'};
        var ragLabels = {green:'6+ months of disk space remaining',amber:'3 to 6 months of disk space remaining',red:'Less than 3 months of disk space remaining',grey:'Collecting weekly data to calculate trend'};

        function cscHealthRender(d) {
            var rag = d.disk_rag || 'grey';
            $('#csc-health-rag-bar').css('background', ragBgs[rag]);
            $('#csc-health-rag-dot').css('background', ragColors[rag]);
            $('#csc-health-rag-label').text(rag === 'grey' ? 'Collecting Data' : rag.charAt(0).toUpperCase()+rag.slice(1)).css('color', ragColors[rag]);
            $('#csc-health-rag-detail').text(ragLabels[rag] || '').css('color', ragColors[rag]);
            $('#hm-disk-used').text(fmt(d.disk_used));
            $('#hm-disk-free').text(fmt(d.disk_free));
            $('#hm-disk-total').text(fmt(d.disk_total));
            $('#hm-db-size').text(fmt(d.db_size));
            $('#hm-growth').text(d.growth_per_week > 0 ? fmt(d.growth_per_week)+'/wk' : (d.weekly_count >= 2 ? 'Stable' : 'Collecting…'));
            if (d.weeks_remaining > 104) {
                $('#hm-weeks-left').text('>> 2 Years').css('color', '#2e7d32');
            } else if (d.weeks_remaining > 0) {
                var wl = Math.round(d.weeks_remaining);
                var wlColor = d.disk_rag === 'red' ? '#c62828' : (d.disk_rag === 'amber' ? '#e65100' : '#2e7d32');
                $('#hm-weeks-left').text(wl + ' weeks').css('color', wlColor);
            } else if (d.growth_per_week <= 0 && d.weekly_count >= 2) {
                $('#hm-weeks-left').text('Stable').css('color', '#2e7d32');
            } else { $('#hm-weeks-left').text('—').css('color',''); }
            var cpuNow = d.cpu_pct_now >= 0 ? d.cpu_pct_now+'%' : '—';
            if (d.cpu_load_now >= 0) cpuNow += ' (load '+d.cpu_load_now.toFixed(2)+')';
            $('#hm-cpu-now').text(cpuNow);
            $('#hm-cpu-24h').text(d.cpu_pct_max_24h >= 0 ? d.cpu_pct_max_24h+'%' : '—');
            $('#hm-cpu-7d').text(d.cpu_pct_max_7d >= 0 ? d.cpu_pct_max_7d+'%' : '—');
            var memNow = d.mem_pct_now >= 0 ? d.mem_pct_now+'%' : '—';
            if (d.mem_used_now >= 0 && d.mem_total > 0) memNow += ' ('+fmt(d.mem_used_now)+' / '+fmt(d.mem_total)+')';
            $('#hm-mem-now').text(memNow);
            $('#hm-mem-24h').text(d.mem_pct_max_24h >= 0 ? d.mem_pct_max_24h+'%' : '—');
            $('#hm-mem-7d').text(d.mem_pct_max_7d >= 0 ? d.mem_pct_max_7d+'%' : '—');
            $('#hm-hourly-count').text(d.hourly_count);
            $('#hm-weekly-count').text(d.weekly_count);
            $('#hm-last-hourly').text(d.last_hourly || 'Never');
            $('#hm-last-weekly').text(d.last_weekly || 'Never');
            $('#hm-data-span').text(d.weeks_of_data > 0 ? d.weeks_of_data : '0');
            $('#csc-health-loading').hide();
            $('#csc-health-content').show();
        }

        /* Auto load health data */
        if ($('#csc-health-loading').is(':visible')) {
            $.post(CSC.ajax_url, { action: 'csc_health_get', nonce: CSC.nonce }, function(resp) {
                if (resp.success) cscHealthRender(resp.data);
            });
        }

        /* Refresh button */
        $(document).on('click', '#btn-health-refresh', function() {
            var $b = $(this).prop('disabled',true).html('⏳ Loading…');
            $.post(CSC.ajax_url, { action: 'csc_health_get', nonce: CSC.nonce }, function(resp) {
                $b.prop('disabled',false).html('🔄 Refresh');
                if (resp.success) cscHealthRender(resp.data);
            }).fail(function(){ $b.prop('disabled',false).html('🔄 Refresh'); });
        });

        /* Collect Now button */
        $(document).on('click', '#btn-health-collect', function() {
            var $b = $(this).prop('disabled',true).html('⏳ Collecting…');
            $.post(CSC.ajax_url, { action: 'csc_health_collect_now', nonce: CSC.nonce }, function(resp) {
                $b.prop('disabled',false).html('📊 Collect Now');
                if (resp.success && resp.data.health) cscHealthRender(resp.data.health);
            }).fail(function(){ $b.prop('disabled',false).html('📊 Collect Now'); });
        });

        /* Test Sysstat button */
        $(document).on('click', '#btn-sysstat-test', function() {
            var $b = $(this).prop('disabled',true).html('⏳ Testing...');
            var blue = {background:'#e3f2fd',borderColor:'#90caf9'};
            var $box = $('#csc-sysstat-status').show().css(blue);
            $('#csc-sysstat-label').text('Testing sysstat...').css('color','#1565c0');
            $('#csc-sysstat-icon').text('⏳');
            $('#csc-sysstat-detail').text('').css('color','#1565c0');
            $('#csc-sysstat-instructions').hide();
            $.post(CSC.ajax_url, { action: 'csc_health_sysstat_test', nonce: CSC.nonce }, function(resp) {
                $b.prop('disabled',false).html('🔧 Test Sysstat');
                $box.css(blue);
                if (!resp.success) { $('#csc-sysstat-icon').text('❌'); $('#csc-sysstat-label').text('Test failed'); return; }
                var d = resp.data;
                if (!d.exec_available) {
                    $('#csc-sysstat-icon').text('❌'); $('#csc-sysstat-label').text('exec() disabled in php.ini');
                } else if (!d.sar_installed) {
                    $('#csc-sysstat-icon').text('❌'); $('#csc-sysstat-label').text('sysstat not installed');
                    if (d.instructions) $('#csc-sysstat-detail').html('<code style="font-size:11px">'+d.instructions.replace(/Run: /, '')+'</code>');
                } else if (!d.sysstat_active) {
                    $('#csc-sysstat-icon').text('⚠️'); $('#csc-sysstat-label').text('sysstat installed but service inactive');
                    $('#csc-sysstat-detail').html(d.sar_version+' at '+d.sar_path+' &mdash; <code style="font-size:11px">sudo systemctl enable sysstat && sudo systemctl start sysstat</code>');
                } else if (!d.sar_has_data) {
                    $('#csc-sysstat-icon').text('🔵'); $('#csc-sysstat-label').text('sysstat v'+d.sar_version+' active, waiting for first samples');
                    $('#csc-sysstat-detail').text('Collects every 10 minutes. Refresh after 10 mins.');
                } else {
                    $('#csc-sysstat-icon').text('✅'); $('#csc-sysstat-label').text('sysstat v'+d.sar_version+' working');
                    $('#csc-sysstat-detail').text(d.sar_samples+' samples/hr | CPU '+d.cpu_pct_now+'% | Mem '+d.mem_pct_now+'%');
                }
            }).fail(function(){ $b.prop('disabled',false).html('🔧 Test Sysstat'); $('#csc-sysstat-icon').text('❌'); $('#csc-sysstat-label').text('Network error'); });
        });
    });
    </script>

    <?php
}

