<?php
/**
 * Ligase_Readiness
 *
 * Editor-facing API + AJAX endpoint that reports field-by-field readiness for
 * each schema @type that's relevant to a post. Powers the in-editor panel and
 * is the single source of "why isn't my rich result showing".
 *
 * @package Ligase
 * @since   2.2.0
 */

defined( 'ABSPATH' ) || exit;

final class Ligase_Readiness {

	/**
	 * Compute readiness for a single post. If $type is null, returns readiness
	 * for every relevant type the post can emit.
	 *
	 * @return array<string,array>  keyed by type
	 */
	public static function for_post( int $post_id, ?string $type = null ): array {
		$types    = $type ? array( $type ) : self::relevant_types( $post_id );
		$resolver = new Ligase_Field_Resolver();
		$out      = array();

		foreach ( $types as $t ) {
			$contract = Ligase_Field_Contract::get( $t );
			$res      = $resolver->resolve( $t, $post_id );

			$fields = array();
			$counts = array(
				'required'    => array( 'filled' => 0, 'missing' => 0 ),
				'recommended' => array( 'filled' => 0, 'missing' => 0 ),
				'optional'    => array( 'filled' => 0, 'missing' => 0 ),
			);

			foreach ( $contract['fields'] ?? array() as $key => $def ) {
				$s     = $res['status'][ $key ] ?? array(
					'level' => $def['level'] ?? 'optional',
					'state' => 'missing_' . ( $def['level'] ?? 'optional' ),
					'source'=> null, 'value' => null,
					'label' => $def['label'] ?? $key,
				);
				$level    = $s['level'];
				$is_filled = in_array( $s['state'], array( 'auto', 'manual' ), true );

				$bucket = isset( $counts[ $level ] ) ? $level : 'optional';
				$counts[ $bucket ][ $is_filled ? 'filled' : 'missing' ]++;

				$fields[] = array(
					'key'    => $key,
					'label'  => $s['label'],
					'level'  => $level,
					'state'  => $s['state'],
					'source' => $s['source'],
					'value'  => $s['value'],
				);
			}

			$out[ $t ] = array(
				'type'             => $t,
				'label'            => $contract['_meta']['label'] ?? $t,
				'experience'       => $contract['_meta']['experience'] ?? null,
				'deprecated'       => (bool) ( $contract['_meta']['deprecated'] ?? false ),
				'eligible'         => $res['eligible'],
				'missing_required' => $res['missing_required'],
				'summary'          => $counts,
				'fields'           => $fields,
			);
		}
		return $out;
	}

	/**
	 * Decide which contract types are relevant for the given post. The default
	 * heuristic: WooCommerce product → Product; regular post → BlogPosting (or
	 * NewsArticle for `news` CPT / mapped categories).
	 *
	 * @return string[]
	 */
	public static function relevant_types( int $post_id ): array {
		$types = array();
		$pt    = get_post_type( $post_id );

		if ( $pt === 'product' && function_exists( 'wc_get_product' ) ) {
			$types[] = 'Product';
		}
		if ( $pt === 'post' ) {
			$types[] = 'BlogPosting';
		}
		if ( $pt === 'news' ) {
			$types[] = 'NewsArticle';
		}

		/**
		 * Filter which schema types the readiness panel surfaces for a post.
		 *
		 * @param string[] $types   Type keys, must exist in Ligase_Field_Contract::types().
		 * @param int      $post_id
		 */
		$types = (array) apply_filters( 'ligase_readiness_panel_types', $types, $post_id );
		$known = Ligase_Field_Contract::types();
		return array_values( array_intersect( $types, $known ) );
	}

	/**
	 * Register AJAX endpoint. Capability: edit_posts + per-post edit_post.
	 */
	public static function register_ajax(): void {
		add_action( 'wp_ajax_ligase_readiness', array( __CLASS__, 'handle_ajax' ) );
	}

	public static function handle_ajax(): void {
		check_ajax_referer( 'ligase_admin', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'ligase' ) ), 403 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post or insufficient permissions for this post.', 'ligase' ) ), 403 );
		}

		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : null;
		$type = $type ?: null;

		wp_send_json_success( self::for_post( $post_id, $type ) );
	}
}

/**
 * Convenience helper for templates / WP-CLI:
 *   wp eval 'var_export( ligase_readiness( 123 ) );'
 */
if ( ! function_exists( 'ligase_readiness' ) ) {
	function ligase_readiness( int $post_id, ?string $type = null ): array {
		return Ligase_Readiness::for_post( $post_id, $type );
	}
}
