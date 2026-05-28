<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin data from the database AND clears all scheduled cron events
 * + log files (including hidden protection files like .htaccess, .web.config, index.php).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// =============================================================================
// 1. Clear scheduled cron events
//
// Without this, ghost cron entries keep firing every wp-cron tick after
// uninstall, hitting nonexistent handlers and filling the error log.
// `wp_clear_scheduled_hook` removes ALL scheduled events for the given hook
// (single + recurring) regardless of args.
// =============================================================================
$cron_hooks = array(
    'ligase_weekly_health_report',
    'ligase_ner_api_extract',
    'ligase_wikidata_lookup',
);
foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}

// =============================================================================
// 2. Remove plugin options (explicit list + LIKE catch-all for new options
//    added after this file was last touched)
// =============================================================================
$options = array(
    'ligase_options',
    'ligase_gsc_service_account',
    'ligase_gsc_site_url',
    'ligase_last_health_report',
    'ligase_show_onboarding',
    'ligase_activated_at',
    'ligase_ner_bulk_done',
    'ligase_ner_bulk_total',
    'ligase_ner_bulk_errors',
    'ligase_ner_bulk_last_run',
    'ligase_schema_rules',
);
foreach ( $options as $option ) {
    delete_option( $option );
}

// Catch-all for any ligase_* option we may have missed (e.g. new keys added in
// future releases before this list is updated).
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( 'ligase_' ) . '%'
) );

// =============================================================================
// 3. Remove all post meta with _ligase_ prefix
// =============================================================================
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like( '_ligase_' ) . '%'
) );

// =============================================================================
// 4. Remove all user meta with ligase_ prefix
// =============================================================================
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like( 'ligase_' ) . '%'
) );

// =============================================================================
// 5. Remove all transients (both data and timeout rows)
//
// Explicit delete of the GSC access token transient up front — its key shape
// could change in the future and we want to be sure it's gone, not relying on
// the LIKE catch-all matching it.
// =============================================================================
delete_transient( 'ligase_gsc_access_token' );
delete_transient( 'ligase_site_score' );

$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_ligase_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_ligase_' ) . '%'
) );
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    $wpdb->esc_like( '_transient_ligase_schema_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_ligase_schema_' ) . '%'
) );

// =============================================================================
// 6. Remove log directory — including hidden protection files
//
// Default glob('*') does NOT match dotfiles (.htaccess, .web.config). Without
// the hidden-file pass, rmdir() fails silently and the directory persists.
// =============================================================================
$upload_dir = wp_upload_dir();
$log_dir    = $upload_dir['basedir'] . '/ligase-logs';
if ( is_dir( $log_dir ) ) {
    // Visible + hidden files in a single pass. The '.[!.]*' pattern matches
    // .htaccess, .web.config etc. without matching . or ..
    $visible = glob( $log_dir . '/*' )      ?: array();
    $hidden  = glob( $log_dir . '/.[!.]*' ) ?: array();
    foreach ( array_merge( $visible, $hidden ) as $file ) {
        if ( is_file( $file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink( $file );
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
    @rmdir( $log_dir );
}
