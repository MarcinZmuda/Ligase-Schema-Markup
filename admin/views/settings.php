<?php
/**
 * Ligase Settings View — tabbed layout
 *
 * @package Ligase
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'organization';

$tabs = array(
	'organization' => array(
		'label'    => __( '🏢 Organizacja', 'ligase' ),
		'sections' => array( Ligase_Settings::SECTION_ORG ),
	),
	'social' => array(
		'label'    => __( '🔗 Social & Entity', 'ligase' ),
		'sections' => array( Ligase_Settings::SECTION_SOCIAL ),
	),
	'localbusiness' => array(
		'label'    => __( '📍 Local Business', 'ligase' ),
		'sections' => array( Ligase_Settings::SECTION_LOCAL ),
	),
	'ai' => array(
		'label'    => __( '🤖 AI / NER', 'ligase' ),
		'sections' => array( 'ligase_ner_section' ),
	),
	'behavior' => array(
		'label'    => __( '⚙️ Zachowanie', 'ligase' ),
		'sections' => array( Ligase_Settings::SECTION_BEHAVIOR ),
	),
);

$base_url = admin_url( 'admin.php?page=ligase-ustawienia' );
?>

<h1><?php esc_html_e( 'Ligase — Ustawienia', 'ligase' ); ?></h1>

<nav class="nav-tab-wrapper wp-clearfix" style="margin-bottom:0;">
	<?php foreach ( $tabs as $slug => $tab ) : ?>
		<a href="<?php echo esc_url( $base_url . '&tab=' . $slug ); ?>"
		   class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
			<?php echo esc_html( $tab['label'] ); ?>
		</a>
	<?php endforeach; ?>
</nav>

<div style="background:#fff;border:1px solid #c3c4c7;border-top:none;padding:20px 24px 8px;">

<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( isset( $_GET['settings-updated'] ) ) :
?>
	<div class="notice notice-success is-dismissible" style="margin:0 0 16px;">
		<p><?php esc_html_e( 'Ustawienia zostały zapisane.', 'ligase' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="options.php">
	<?php settings_fields( Ligase_Settings::GROUP ); ?>
	<?php
	$current_sections = $tabs[ $active_tab ]['sections'] ?? array();
	foreach ( $current_sections as $section_id ) {
		ligase_do_settings_section( 'ligase-ustawienia', $section_id );
	}
	?>
	<?php submit_button( __( 'Zapisz ustawienia', 'ligase' ) ); ?>
</form>

</div>
