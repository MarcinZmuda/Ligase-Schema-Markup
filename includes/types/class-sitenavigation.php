<?php
/**
 * Ligase SiteNavigationElement Schema Type
 *
 * Generates SiteNavigationElement schema for the WordPress navigation menus.
 * Only top-level items are included — submenus are skipped because Google
 * cannot reliably resolve nested nav schemas.
 *
 * Output: one SiteNavigationElement per distinct nav menu, each holding the
 * menu's top-level items in `hasPart` with absolute URLs.
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
		$seen    = array();

		foreach ( $locations as $location => $menu_id ) {
			$menu_id = (int) $menu_id;

			if ( empty( $menu_id ) ) {
				continue;
			}

			// One node per MENU, not per location. Themes routinely assign the same
			// menu to several locations (primary + mobile + offcanvas); emitting it
			// once per location put byte-identical duplicates in the graph that the
			// @id de-dupe in finalize_graph() could not collapse.
			if ( isset( $seen[ $menu_id ] ) ) {
				continue;
			}
			$seen[ $menu_id ] = true;

			$schema = $this->build_for_menu( $menu_id, (string) $location );
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
			$url = $this->resolve_url( (string) $item->url );

			if ( $url === '' ) {
				continue;
			}

			$list_items[] = array(
				'@type'    => 'SiteNavigationElement',
				'position' => $position++,
				'name'     => $this->clean_text( (string) $item->title ),
				'url'      => $url,
			);
		}

		if ( empty( $list_items ) ) {
			return null;
		}

		$schema = array(
			'@type'   => 'SiteNavigationElement',
			'@id'     => home_url( '/#nav-' . sanitize_key( $location ) ),
			'name'    => $this->menu_name( $menu_id, $location ),
			'url'     => esc_url_raw( home_url( '/' ) ),
			'hasPart' => $list_items,
		);

		return apply_filters( 'ligase_site_navigation_element', $schema, $location, $menu_id );
	}

	/**
	 * Turn a menu item URL into an absolute http(s) URL fit for JSON-LD.
	 *
	 * Returns '' for anything that shouldn't be published as a navigation
	 * target: in-page anchors, javascript: pseudo-links, and non-web schemes
	 * (mailto:, tel:, sms:). Those used to fall through the "is it absolute?"
	 * test and get home_url()-prefixed, producing dead URLs in the graph
	 * (https://example.com/mailto:biuro@example.com).
	 *
	 * Note esc_url_raw(), not esc_url(): esc_url() is an HTML-attribute escaper
	 * and rewrites & into &#038;, which would land verbatim inside the JSON.
	 */
	private function resolve_url( string $raw ): string {
		$raw = trim( $raw );

		if ( $raw === '' || str_starts_with( $raw, '#' ) ) {
			return '';
		}

		// Protocol-relative (//cdn.example.com/...) — adopt the site scheme.
		if ( str_starts_with( $raw, '//' ) ) {
			return (string) esc_url_raw( set_url_scheme( $raw ) );
		}

		// Already absolute http(s).
		if ( preg_match( '#^https?://#i', $raw ) ) {
			return (string) esc_url_raw( $raw );
		}

		// Any other scheme (mailto:, tel:, javascript:, ftp:) is not a page.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $raw ) ) {
			return '';
		}

		return (string) esc_url_raw( home_url( $raw ) );
	}

	/**
	 * Human-readable name for this navigation.
	 *
	 * Prefers the theme's registered label for the location ("Menu główne"),
	 * because the WP menu object name is an editor-facing working title —
	 * sites shipped nav schema named "nowe" or "menu 2 kopia" to Google.
	 */
	private function menu_name( int $menu_id, string $location ): string {
		if ( function_exists( 'get_registered_nav_menus' ) ) {
			$registered = get_registered_nav_menus();
			if ( ! empty( $registered[ $location ] ) ) {
				return $this->clean_text( (string) $registered[ $location ] );
			}
		}

		$menu_obj = wp_get_nav_menu_object( $menu_id );

		return $this->clean_text( $menu_obj ? (string) $menu_obj->name : $location );
	}

	/**
	 * Strip tags and decode HTML entities — JSON-LD carries text, not markup,
	 * so a title stored as "Cennik &#8211; 2026" must not reach Google that way.
	 */
	private function clean_text( string $text ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
