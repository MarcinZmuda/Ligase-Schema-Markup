<?php
/**
 * Ligase Schema Rules
 *
 * Conditional schema automation: map categories, tags, post types,
 * or authors to schema types — without editing each post individually.
 *
 * Rules are evaluated at render time and act as if the user had manually
 * enabled the schema type in the post metabox. Post meta always takes
 * precedence (explicit per-post settings override global rules).
 *
 * @package Ligase
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Schema_Rules {

	/** Option key for stored rules. */
	const OPTION_KEY = 'ligase_schema_rules';

	/**
	 * Schema types that can be enabled by rules.
	 * Maps display label → post meta key used by each type's build().
	 */
	const SCHEMA_TYPES = array(
		'FAQPage'             => '_ligase_enable_faq',
		'HowTo'               => '_ligase_enable_howto',
		'Review'              => '_ligase_enable_review',
		'QAPage'              => '_ligase_enable_qapage',
		'ClaimReview'         => '_ligase_enable_claimreview',
		'DefinedTerm'         => '_ligase_enable_glossary',
		'SoftwareApplication' => '_ligase_enable_software',
		'AudioObject'         => '_ligase_enable_audio',
		'Course'              => '_ligase_enable_course',
		'Event'               => '_ligase_enable_event',
	);

	/**
	 * Per-request cache: post_id → array of enabled meta keys from rules.
	 *
	 * @var array<int, array<string>>
	 */
	private static array $cache = array();

	// =========================================================================
	// Rule storage
	// =========================================================================

	/**
	 * Get all saved rules.
	 *
	 * @return array<int, array{
	 *   id: string,
	 *   name: string,
	 *   condition_type: string,
	 *   condition_value: string,
	 *   schema_keys: string[],
	 *   enabled: bool
	 * }>
	 */
	public static function get_rules(): array {
		$rules = get_option( self::OPTION_KEY, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Save rules array (full replace).
	 *
	 * @param array $rules
	 * @return bool
	 */
	public static function save_rules( array $rules ): bool {
		self::$cache = array(); // bust cache
		return update_option( self::OPTION_KEY, $rules );
	}

	// =========================================================================
	// Rule evaluation
	// =========================================================================

	/**
	 * Get meta keys that rules say should be enabled for a given post.
	 * Result is cached per request (called multiple times per graph build).
	 *
	 * @param int $post_id
	 * @return array<string>  e.g. ['_ligase_enable_faq', '_ligase_enable_review']
	 */
	public static function get_enabled_keys_for_post( int $post_id ): array {
		if ( isset( self::$cache[ $post_id ] ) ) {
			return self::$cache[ $post_id ];
		}

		$enabled = array();

		foreach ( self::get_rules() as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( self::rule_matches( $rule, $post_id ) ) {
				foreach ( (array) ( $rule['schema_keys'] ?? array() ) as $key ) {
					$enabled[] = sanitize_key( $key );
				}
			}
		}

		self::$cache[ $post_id ] = array_unique( $enabled );
		return self::$cache[ $post_id ];
	}

	/**
	 * Check if a specific meta key is enabled for a post by any rule.
	 * Called from each type's build() method as fallback when post meta is '0'.
	 *
	 * @param string $meta_key  e.g. '_ligase_enable_faq'
	 * @param int    $post_id
	 */
	public static function is_enabled_for_post( string $meta_key, int $post_id ): bool {
		return in_array( $meta_key, self::get_enabled_keys_for_post( $post_id ), true );
	}

	/**
	 * Test whether a single rule matches the given post.
	 *
	 * @param array $rule
	 * @param int   $post_id
	 */
	private static function rule_matches( array $rule, int $post_id ): bool {
		$type  = $rule['condition_type']  ?? '';
		$value = $rule['condition_value'] ?? '';

		switch ( $type ) {

			case 'always':
				// Applies to any public single post type (post, page, CPT)
				$public_types = array_keys( get_post_types( array( 'public' => true ) ) );
				return in_array( get_post_type( $post_id ), $public_types, true );

			case 'category':
				// value = term_id (int) or slug (string)
				$cats = get_the_category( $post_id );
				if ( empty( $cats ) ) {
					return false;
				}
				foreach ( $cats as $cat ) {
					if ( (string) $cat->term_id === (string) $value
						|| $cat->slug === $value ) {
						return true;
					}
				}
				return false;

			case 'tag':
				$tags = get_the_tags( $post_id );
				if ( empty( $tags ) || ! is_array( $tags ) ) {
					return false;
				}
				foreach ( $tags as $tag ) {
					if ( (string) $tag->term_id === (string) $value
						|| $tag->slug === $value ) {
						return true;
					}
				}
				return false;

			case 'post_type':
				return get_post_type( $post_id ) === $value;

			case 'author':
				return (string) get_post_field( 'post_author', $post_id ) === (string) $value;

			case 'slug_contains':
				$slug = get_post_field( 'post_name', $post_id );
				return $value !== '' && str_contains( $slug, $value );

			default:
				return false;
		}
	}

	// =========================================================================
	// Helpers for UI
	// =========================================================================

	/**
	 * Human-readable condition summary for a rule.
	 *
	 * @param array $rule
	 * @return string
	 */
	public static function describe_condition( array $rule ): string {
		$type  = $rule['condition_type']  ?? '';
		$value = $rule['condition_value'] ?? '';

		switch ( $type ) {
			case 'always':
				return __( 'All blog posts', 'ligase' );
			case 'category':
				$term = get_term( (int) $value );
				$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
				return sprintf( __( 'Category: %s', 'ligase' ), $name );
			case 'tag':
				$term = get_term( (int) $value );
				$name = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
				return sprintf( __( 'Tag: %s', 'ligase' ), $name );
			case 'post_type':
				return sprintf( __( 'Post type: %s', 'ligase' ), $value );
			case 'author':
				$user = get_userdata( (int) $value );
				$name = $user ? $user->display_name : $value;
				return sprintf( __( 'Author: %s', 'ligase' ), $name );
			case 'slug_contains':
				return sprintf( __( 'Slug contains: "%s"', 'ligase' ), $value );
			default:
				return $type . ': ' . $value;
		}
	}

	/**
	 * Generate a unique rule ID.
	 */
	public static function generate_id(): string {
		return 'rule_' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}
}
