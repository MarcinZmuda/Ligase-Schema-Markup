<?php
/**
 * Ligase SiteNavigationElement Schema Type
 *
 * Generates ItemList-based SiteNavigationElement schema for the primary
 * WordPress navigation menu. Only top-level items are included — submenus
 * are skipped because Google cannot reliably resolve nested nav schemas.
 *
 * Output: one SiteNavigationElement per registered nav menu location,
 * each containing an ItemList of absolute URLs.
 *
 * Activation: auto — fires whenever at least one nav menu is registered
 * and has items assigned. No user configuration needed.
 *
 * @package Ligase
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_SiteNavigationElement {

	/**
	 * Build SiteNavigationElement schemas for all registered menu locations
	 * that have a menu assigned.
	 *
	 * Returns an array of schema objects (one per menu), or an empty array
	 * if no menus are configured.
	 *
	 * @return array<int, array>
	 */
	public function build(): array {
		$locations = get_nav_menu_locations();

		if ( empty( $locations ) ) {
			return array();
		}

		$schemas = array();

		foreach ( $locations as $location => $menu_id ) {
			if ( empty( $menu_id ) ) {
				continue;
			}

			$schema = $this->build_for_menu( (int) $menu_id, $location );
			if ( ! empty( $schema ) ) {
				$schemas[] = $schema;
			}
		}

		return $schemas;
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Build a single SiteNavigationElement for one menu.
	 *
	 * @param int    $menu_id   WP menu object ID.
	 * @param string $location  Theme location slug (e.g. 'primary', 'footer').
	 * @return array|null
	 */
	private function build_for_menu( int $menu_id, string $location ): ?array {
		$items = wp_get_nav_menu_items( $menu_id );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return null;
		}

		// Only top-level items (menu_item_parent === '0')
		$top_level = array_filter(
			$items,
			fn( $item ) => (string) $item->menu_item_parent === '0'
		);

		if ( empty( $top_level ) ) {
			return null;
		}

		// Sort by menu_order
		usort( $top_level, fn( $a, $b ) => $a->menu_order <=> $b->menu_order );

		$list_items = array();
		$position   = 1;

		foreach ( $top_level as $item ) {
			$url = esc_url( $item->url );

			// Skip empty, anchor-only, or javascript: links
			if ( empty( $url )
				|| str_starts_with( $item->url, '#' )
				|| str_starts_with( $item->url, 'javascript:' )
			) {
				continue;
			}

			// Ensure absolute URL
			if ( ! preg_match( '#^https?://#i', $url ) ) {
				$url = home_url( $url );
			}

			$list_items[] = array(
				'@type'    => 'SiteNavigationElement',
				'position' => $position++,
				'name'     => esc_html( $item->title ),
				'url'      => $url,
			);
		}

		if ( empty( $list_items ) ) {
			return null;
		}

		// Human-readable name for this navigation
		$menu_obj = wp_get_nav_menu_object( $menu_id );
		$nav_name = $menu_obj ? $menu_obj->name : $location;

		$schema = array(
			'@type'           => 'SiteNavigationElement',
			'@id'             => home_url( '/#nav-' . sanitize_key( $location ) ),
			'name'            => esc_html( $nav_name ),
			'url'             => esc_url( home_url( '/' ) ),
			'hasPart'         => $list_items,
		);

		return apply_filters( 'ligase_site_navigation_element', $schema, $location, $menu_id );
	}
}
