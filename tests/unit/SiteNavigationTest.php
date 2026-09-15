<?php
/**
 * Ligase - SiteNavigationElement type
 *
 * @package Ligase\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SiteNavigationTest extends TestCase {

	protected function setUp(): void {
		MockData::reset();
		MockData::set( 'home_url', 'https://example.com' );
	}

	/**
	 * @param array<int, array{url: string, title: string, parent?: string}> $items
	 */
	private function menu( int $menu_id, array $items, string $name = 'nowe' ): void {
		$objects = array();
		$order   = 1;
		foreach ( $items as $item ) {
			$objects[] = (object) array(
				'url'              => $item['url'],
				'title'            => $item['title'],
				'menu_item_parent' => $item['parent'] ?? '0',
				'menu_order'       => $order++,
			);
		}
		MockData::set( 'nav_menu_items_' . $menu_id, $objects );
		MockData::set( 'nav_menu_object_' . $menu_id, (object) array( 'name' => $name ) );
	}

	public function test_non_web_schemes_are_dropped_not_prefixed_with_home_url(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5 ) );
		$this->menu(
			5,
			array(
				array( 'url' => 'https://example.com/oferta/', 'title' => 'Oferta' ),
				array( 'url' => 'mailto:biuro@example.com', 'title' => 'Napisz' ),
				array( 'url' => 'tel:+48221234567', 'title' => 'Zadzwoń' ),
				array( 'url' => '#kontakt', 'title' => 'Kontakt' ),
				array( 'url' => 'javascript:void(0)', 'title' => 'Menu' ),
			)
		);

		$nav = ( new Ligase_Type_SiteNavigationElement() )->build();
		$this->assertCount( 1, $nav );

		$urls = array_column( $nav[0]['hasPart'], 'url' );
		$this->assertSame( array( 'https://example.com/oferta/' ), $urls );

		foreach ( $urls as $url ) {
			$this->assertStringNotContainsString( 'mailto', $url );
			$this->assertStringNotContainsString( 'tel:', $url );
		}
	}

	public function test_relative_urls_become_absolute_and_positions_are_consecutive(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5 ) );
		$this->menu(
			5,
			array(
				array( 'url' => '/oferta/', 'title' => 'Oferta' ),
				array( 'url' => 'mailto:x@example.com', 'title' => 'Mail' ),
				array( 'url' => '/kontakt/', 'title' => 'Kontakt' ),
			)
		);

		$parts = ( new Ligase_Type_SiteNavigationElement() )->build()[0]['hasPart'];

		$this->assertSame( 'https://example.com/oferta/', $parts[0]['url'] );
		$this->assertSame( 'https://example.com/kontakt/', $parts[1]['url'] );
		// The skipped item must not leave a hole in the sequence.
		$this->assertSame( array( 1, 2 ), array_column( $parts, 'position' ) );
	}

	public function test_same_menu_in_two_locations_emits_one_node(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5, 'mobile' => 5, 'footer' => 7 ) );
		$this->menu( 5, array( array( 'url' => '/a/', 'title' => 'A' ) ) );
		$this->menu( 7, array( array( 'url' => '/b/', 'title' => 'B' ) ) );

		$nav = ( new Ligase_Type_SiteNavigationElement() )->build();

		$this->assertCount( 2, $nav );
		$this->assertSame( 'https://example.com/#nav-primary', $nav[0]['@id'] );
		$this->assertSame( 'https://example.com/#nav-footer', $nav[1]['@id'] );
	}

	public function test_name_prefers_registered_location_label_over_admin_menu_name(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5 ) );
		MockData::set( 'registered_nav_menus', array( 'primary' => 'Menu główne' ) );
		$this->menu( 5, array( array( 'url' => '/a/', 'title' => 'A' ) ), 'nowe' );

		$nav = ( new Ligase_Type_SiteNavigationElement() )->build();

		$this->assertSame( 'Menu główne', $nav[0]['name'] );
	}

	public function test_falls_back_to_menu_object_name(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5 ) );
		$this->menu( 5, array( array( 'url' => '/a/', 'title' => 'A' ) ), 'Menu witryny' );

		$this->assertSame( 'Menu witryny', ( new Ligase_Type_SiteNavigationElement() )->build()[0]['name'] );
	}

	public function test_html_entities_in_titles_are_decoded(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5 ) );
		$this->menu( 5, array( array( 'url' => '/cennik/', 'title' => 'Cennik &#8211; 2026' ) ) );

		$parts = ( new Ligase_Type_SiteNavigationElement() )->build()[0]['hasPart'];

		$this->assertSame( 'Cennik – 2026', $parts[0]['name'] );
	}

	public function test_submenu_items_are_skipped(): void {
		MockData::set( 'nav_menu_locations', array( 'primary' => 5 ) );
		$this->menu(
			5,
			array(
				array( 'url' => '/a/', 'title' => 'A' ),
				array( 'url' => '/a/sub/', 'title' => 'Sub', 'parent' => '101' ),
			)
		);

		$this->assertCount( 1, ( new Ligase_Type_SiteNavigationElement() )->build()[0]['hasPart'] );
	}
}
