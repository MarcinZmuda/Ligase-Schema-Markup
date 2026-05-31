<?php
/**
 * Plugin Name:       Ligase
 * Plugin URI:        https://marcinzmuda.com/ligase
 * Description:       Complete schema.org JSON-LD for WordPress blogs. BlogPosting, Person,
 *                    Organization, BreadcrumbList, FAQPage, HowTo, VideoObject, and more.
 *                    Schema Auditor replaces weak markup. Compliant with Google guidelines March 2026.
 * Version:           2.4.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Marcin Żmuda
 * Author URI:        https://marcinzmuda.com
 * License:           GPL v2 or later
 * Text Domain:       ligase
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LIGASE_VERSION', '2.4.2' );
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
} );

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
