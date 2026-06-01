<?php
/**
 * Plugin Name:       Ligase
 * Plugin URI:        https://marcinzmuda.com/ligase
 * Description:       Complete schema.org JSON-LD for WordPress blogs. BlogPosting, Person,
 *                    Organization, BreadcrumbList, FAQPage, HowTo, VideoObject, and more.
 *                    Schema Auditor replaces weak markup. Compliant with Google guidelines March 2026.
 * Version:           2.4.13
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Marcin Żmuda
 * Author URI:        https://marcinzmuda.com
 * License:           GPL v2 or later
 * Text Domain:       ligase
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LIGASE_VERSION', '2.4.13' );
define( 'LIGASE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LIGASE_URL',     plugin_dir_url( __FILE__ ) );
define( 'LIGASE_FILE',    __FILE__ );

require_once LIGASE_DIR . 'includes/class-plugin.php';

add_action( 'plugins_loaded', function() {
    Ligase_Plugin::get_instance();
} );

// Activation hook — set flag to show onboarding notice
register_activation_hook( __FILE__, function() {
    update_option( 'ligase_show_onboarding', '1' );
    update_option( 'ligase_activated_at', time() );

    // Reset OPcache after install/activation. Without this, PHP-FPM workers on
    // shared hosts (Smarthost / cPanel / DirectAdmin) keep the OLD compiled
    // class-settings.php in memory — sanitize() ends up missing fields added
    // in the new version, and checkbox toggles silently revert on Save. This
    // bit users on 2.4.6 → 2.4.7 upgrades (org_author_mode never persisted)
    // and again on 2.4.7 → 2.4.8 (default_schema_type). Reset eliminates the
    // class of bug across upgrades.
    if ( function_exists( 'opcache_reset' ) ) {
        @opcache_reset();
    }
} );

// Same reset after WP's plugin upgrader finishes — covers the "Replace current
// with uploaded" path which doesn't always trigger the activation hook.
add_action( 'upgrader_process_complete', function( $upgrader, $hook_extra ) {
    if ( empty( $hook_extra['type'] ) || $hook_extra['type'] !== 'plugin' ) {
        return;
    }
    $plugins = $hook_extra['plugins'] ?? array();
    if ( ! is_array( $plugins ) ) {
        return;
    }
    foreach ( $plugins as $p ) {
        if ( strpos( (string) $p, 'ligase' ) !== false && function_exists( 'opcache_reset' ) ) {
            @opcache_reset();
            break;
        }
    }
}, 10, 2 );

// Deactivation hook — clear scheduled cron events so they don't keep firing
// against missing class handlers after deactivate. Full data cleanup happens
// only on uninstall.php (delete plugin), not on deactivate.
register_deactivation_hook( __FILE__, function() {
    // Load the health-report class to call its unschedule() if it isn't already
    // in memory (deactivation can happen on a request where it wasn't loaded).
    $hr_file = LIGASE_DIR . 'includes/class-health-report.php';
    if ( file_exists( $hr_file ) ) {
        require_once $hr_file;
        if ( class_exists( 'Ligase_Health_Report' ) && method_exists( 'Ligase_Health_Report', 'unschedule' ) ) {
            Ligase_Health_Report::unschedule();
        }
    }

    // Belt-and-braces: clear all known recurring + single-event cron hooks.
    // Single events scheduled with args still need explicit clear here because
    // wp_clear_scheduled_hook() with no args removes ALL events for the hook
    // regardless of how they were scheduled.
    foreach ( array(
        'ligase_weekly_health_report',
        'ligase_ner_api_extract',
        'ligase_wikidata_lookup',
    ) as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
} );
