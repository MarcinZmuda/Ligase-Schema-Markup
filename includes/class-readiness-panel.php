<?php
/**
 * Ligase_Readiness_Panel
 *
 * In-editor metabox that surfaces schema readiness for the current post.
 * Renders a stub container; JS at assets/js/ligase-readiness-panel.js fetches
 * data from the ligase_readiness AJAX endpoint and renders the live list.
 *
 * @package Ligase
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

final class Ligase_Readiness_Panel {

	public static function register(): void {
		add_action( 'add_meta_boxes',        array( __CLASS__, 'add_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function add_metabox(): void {
		if ( ! class_exists( 'Ligase_Field_Contract' ) ) {
			return;
		}
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'ligase_readiness_panel',
				__( 'Ligase — gotowość schematu', 'ligase' ),
				array( __CLASS__, 'render' ),
				$post_type,
				'side',
				'default'
			);
		}
	}

	public static function render( WP_Post $post ): void {
		printf(
			'<div id="ligase-readiness-panel" data-post-id="%d"><p class="description">%s</p></div>',
			(int) $post->ID,
			esc_html__( 'Ładowanie…', 'ligase' )
		);
	}

	public static function enqueue( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$asset_url = plugins_url( 'assets/js/ligase-readiness-panel.js', LIGASE_FILE );
		wp_enqueue_script(
			'ligase-readiness-panel',
			$asset_url,
			array( 'wp-i18n', 'wp-data' ),
			defined( 'LIGASE_VERSION' ) ? LIGASE_VERSION : '2.2.0',
			true
		);
		wp_localize_script(
			'ligase-readiness-panel',
			'LIGASE_READINESS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ligase_admin' ),
				'i18n'    => array(
					'loading'        => __( 'Ładowanie…', 'ligase' ),
					'eligible'       => __( 'Kwalifikuje się do rich resultu', 'ligase' ),
					'ineligible'     => __( 'Nie kwalifikuje się — brakuje pól wymaganych', 'ligase' ),
					'deprecated'     => __( 'Typ wycofany przez Google — brak rich resultu', 'ligase' ),
					'refresh'        => __( 'Odśwież', 'ligase' ),
					'required'       => __( 'wymagane', 'ligase' ),
					'recommended'    => __( 'zalecane', 'ligase' ),
					'optional'       => __( 'opcjonalne', 'ligase' ),
					'auto'           => __( 'auto', 'ligase' ),
					'manual'         => __( 'ręczne', 'ligase' ),
					'missing'        => __( 'brak', 'ligase' ),
					'fromSource'     => __( 'źródło:', 'ligase' ),
					'noTypes'        => __( 'Brak typów schema dla tego wpisu.', 'ligase' ),
					'fetchError'     => __( 'Błąd pobrania danych.', 'ligase' ),
				),
			)
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'ligase-readiness-panel',
				'ligase',
				defined( 'LIGASE_DIR' ) ? rtrim( LIGASE_DIR, '/\\' ) . '/languages' : ''
			);
		}
	}
}
