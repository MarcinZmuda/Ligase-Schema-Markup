<?php
/**
 * Ligase - foreign microdata scrubber (standalone mode)
 *
 * @package Ligase\Tests
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SuppressorMicrodataTest extends TestCase {

	protected function setUp(): void {
		MockData::reset();
		MockData::set_option( 'ligase_options', array( 'standalone_mode' => '1' ) );
	}

	/**
	 * The real-world case: a theme breadcrumb with one ListItem and no `position`,
	 * which Search Console reports as an invalid "Menu nawigacyjne" item.
	 */
	public function test_strips_theme_breadcrumb_microdata(): void {
		$html = '<div class="breadcrumbs" itemscope itemtype="https://schema.org/BreadcrumbList">'
			. '<span itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">'
			. '<a href="https://example.com/" itemprop="item" class="home"><span itemprop="name">Home</span></a>'
			. '</span> <span class="sep">&rsaquo;</span> <span class="current">Kontakt</span></div>';

		$out = Ligase_Suppressor::strip_foreign_microdata( $html );

		$this->assertStringNotContainsString( 'itemscope', $out );
		$this->assertStringNotContainsString( 'itemtype', $out );
		$this->assertStringNotContainsString( 'itemprop', $out );

		// Visible markup survives untouched.
		$this->assertStringContainsString( 'class="breadcrumbs"', $out );
		$this->assertStringContainsString( 'class="home"', $out );
		$this->assertStringContainsString( '<span class="current">Kontakt</span>', $out );
		$this->assertStringContainsString( 'href="https://example.com/"', $out );
	}

	public function test_leaves_pages_without_microdata_untouched(): void {
		$html = '<div class="breadcrumbs"><a href="/">Home</a></div>';

		$this->assertSame( $html, Ligase_Suppressor::strip_foreign_microdata( $html ) );
	}

	public function test_leaves_foreign_vocabularies_untouched(): void {
		$html = '<div itemscope itemtype="https://schema.org/WebPage">x</div>'
			. '<div itemscope itemtype="http://data-vocabulary.org/Breadcrumb">y</div>';

		$out = Ligase_Suppressor::strip_foreign_microdata( $html );

		$this->assertStringNotContainsString( 'schema.org/WebPage', $out );
		$this->assertStringContainsString( 'itemtype="http://data-vocabulary.org/Breadcrumb"', $out );
	}

	public function test_does_not_touch_script_style_or_textarea_bodies(): void {
		$html = '<div itemscope itemtype="https://schema.org/WebPage">x</div>'
			. '<script>var tpl = \'<span itemprop="name">A</span>\';</script>'
			. '<textarea><span itemprop="name">B</span></textarea>';

		$out = Ligase_Suppressor::strip_foreign_microdata( $html );

		$this->assertStringContainsString( 'var tpl = \'<span itemprop="name">A</span>\';', $out );
		$this->assertStringContainsString( '<textarea><span itemprop="name">B</span></textarea>', $out );
		$this->assertStringNotContainsString( 'schema.org/WebPage', $out );
	}

	public function test_setting_off_keeps_markup_and_flags_the_page(): void {
		MockData::set_option(
			'ligase_options',
			array(
				'standalone_mode' => '1',
				'strip_microdata' => '',
			)
		);

		$html = '<div itemscope itemtype="https://schema.org/BreadcrumbList">x</div>';

		$this->assertSame( $html, Ligase_Suppressor::strip_foreign_microdata( $html ) );

		$flag = get_transient( Ligase_Suppressor::MICRODATA_FLAG );
		$this->assertIsArray( $flag );
		$this->assertArrayHasKey( 'url', $flag );
	}

	/**
	 * An install upgraded from an older version has no `strip_microdata` key at all —
	 * the fix has to apply anyway.
	 */
	public function test_missing_setting_defaults_to_stripping(): void {
		$html = '<p itemscope itemtype="https://schema.org/WebPage">x</p>';

		$this->assertStringNotContainsString( 'itemtype', Ligase_Suppressor::strip_foreign_microdata( $html ) );
	}

	public function test_handles_unquoted_and_self_closing_attributes(): void {
		$html = '<div itemscope itemtype="https://schema.org/Product">'
			. '<meta itemprop=price content="99.00" />'
			. '<img itemprop="image" src="/a.jpg" /></div>';

		$out = Ligase_Suppressor::strip_foreign_microdata( $html );

		$this->assertStringNotContainsString( 'itemprop', $out );
		$this->assertStringContainsString( 'content="99.00"', $out );
		$this->assertStringContainsString( 'src="/a.jpg"', $out );
	}
}
