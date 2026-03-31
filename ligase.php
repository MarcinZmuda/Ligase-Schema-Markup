<?php
/**
 * Plugin Name:       Ligase
 * Plugin URI:        https://marcinzmuda.com/ligase
 * Description:       Complete schema.org JSON-LD for WordPress blogs. BlogPosting, Person,
 *                    Organization, BreadcrumbList, FAQPage, HowTo, VideoObject, and more.
 *                    Schema Auditor replaces weak markup. Compliant with Google guidelines March 2026.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Marcin Żmuda
 * Author URI:        https://marcinzmuda.com
 * License:           GPL v2 or later
 * Text Domain:       ligase
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LIGASE_VERSION', '2.0.0' );
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
