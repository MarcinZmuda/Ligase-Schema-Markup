<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all plugin data from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Remove plugin options
delete_option( 'ligase_options' );
delete_option( 'ligase_gsc_service_account' );
delete_option( 'ligase_gsc_site_url' );
delete_option( 'ligase_last_health_report' );
delete_option( 'ligase_show_onboarding' );
delete_option( 'ligase_activated_at' );
delete_option( 'ligase_ner_bulk_done' );
delete_option( 'ligase_schema_rules' );

// Remove all post meta
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like( '_ligase_' ) . '%'
) );

// Remove all user meta
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
    $wpdb->esc_like( 'ligase_' ) . '%'
) );

// Remove all transients
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
    '_transient_ligase_%',
    '_transient_timeout_ligase_%'
) );

// Remove log directory
$upload_dir = wp_upload_dir();
$log_dir    = $upload_dir['basedir'] . '/ligase-logs';
if ( is_dir( $log_dir ) ) {
    $files = glob( $log_dir . '/*' );
    if ( $files ) {
        array_map( 'unlink', $files );
    }
    rmdir( $log_dir );
}
