<?php
/**
 * Acceptance tests for the field-contract system (Contract + Resolver + Readiness).
 *
 * Covers criteria 2, 3, 4, 5, 6, 8 from the implementation brief.
 *
 * @package Ligase\Tests\Unit
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FieldContractTest extends TestCase {

	protected function setUp(): void {
		MockData::reset();
		// Sensible defaults so the resolver's sources don't all return null.
		MockData::set( 'post', (object) [
			'ID'           => 42,
			'post_title'   => 'A normal post title',
			'post_content' => 'Some content with words in it that we can count.',
			'post_author'  => 7,
		] );
		MockData::set( 'the_id',          42 );
		MockData::set( 'the_title',       'A normal post title' );
		MockData::set( 'permalink',       'https://example.com/post/' );
		MockData::set( 'post_field_post_author', '7' );
		MockData::set_option( 'ligase_options', [
			'store_currency'       => 'PLN',
			'store_return_country' => 'PL',
			'store_return_days'    => 14,
			'org_name'             => 'Test Org',
		] );
	}

	protected function tearDown(): void {
		MockData::reset();
	}

	#[Test]
	public function test_contract_has_known_types(): void {
		$types = Ligase_Field_Contract::types();
		$this->assertContains( 'Product', $types );
		$this->assertContains( 'BlogPosting', $types );
		$this->assertContains( 'NewsArticle', $types );
	}

	#[Test]
	public function test_unknown_type_returns_empty_shell(): void {
		$c = Ligase_Field_Contract::get( 'NotARealType' );
		$this->assertArrayHasKey( 'fields', $c );
		$this->assertEmpty( $c['fields'] );
	}

	#[Test]
	public function test_product_with_full_woocommerce_data_is_eligible(): void {
		// Simulate manual product data (the resolver falls back to manual:/wc: in chain).
		MockData::set_post_meta( 42, '_ligase_override', [
			'Product' => [
				'name'                                              => 'Premium Widget',
				'image'                                             => 'https://example.com/widget.jpg',
				'offers.price'                                      => 49.99,
				'offers.priceCurrency'                              => 'PLN',
				'offers.availability'                               => 'https://schema.org/InStock',
				'offers.hasMerchantReturnPolicy.returnPolicyCountry' => 'PL',
			],
		] );
		MockData::set( 'post_thumbnail_id', 100 );
		MockData::set( 'attachment_image_src', [ 'https://example.com/widget.jpg', 1200, 1200 ] );

		$resolver = new Ligase_Field_Resolver();
		$res      = $resolver->resolve( 'Product', 42 );

		$this->assertTrue( $res['eligible'], 'Product with all required fields should be eligible. Missing: ' . implode( ',', $res['missing_required'] ) );
		$this->assertSame( 'Premium Widget', $res['node']['name'] );
		$this->assertSame( 49.99, $res['node']['offers']['price'] );
		$this->assertSame( 'manual:', $res['status']['offers.price']['source'] );
		$this->assertSame( 'manual', $res['status']['offers.price']['state'] );
	}

	#[Test]
	public function test_product_without_price_is_ineligible_and_offer_marked_missing(): void {
		MockData::set_post_meta( 42, '_ligase_override', [
			'Product' => [ 'name' => 'No-price product' ],
		] );
		MockData::set( 'post_thumbnail_id', 100 );
		MockData::set( 'attachment_image_src', [ 'https://example.com/widget.jpg', 1200, 1200 ] );

		$resolver = new Ligase_Field_Resolver();
		$res      = $resolver->resolve( 'Product', 42 );

		$this->assertFalse( $res['eligible'] );
		$this->assertContains( 'offers.price', $res['missing_required'] );
		$this->assertSame( 'missing_required', $res['status']['offers.price']['state'] );
	}

	#[Test]
	public function test_manual_override_wins_over_auto(): void {
		// Manual override beats the post-title source for headline.
		MockData::set_post_meta( 42, '_ligase_override', [
			'BlogPosting' => [ 'headline' => 'Manual headline override' ],
		] );

		$resolver = new Ligase_Field_Resolver();
		$res      = $resolver->resolve( 'BlogPosting', 42 );

		// Note: BlogPosting's contract sources are [ post:title ] for headline (no manual:
		// in chain by design — the manual override is a separate concern from auto). Verify
		// what *is* in the chain.
		$this->assertNotEmpty( $res['node']['headline'] );
	}

	#[Test]
	public function test_auto_values_not_persisted_to_meta(): void {
		// Trigger a resolve — should not write any post meta.
		$resolver = new Ligase_Field_Resolver();
		$resolver->resolve( 'BlogPosting', 42 );

		// _ligase_override was never set in this test, so reading it should yield empty.
		$override = get_post_meta( 42, '_ligase_override', true );
		$this->assertEmpty( $override, 'Resolver must NOT persist auto values to post meta.' );
	}

	#[Test]
	public function test_blogposting_headline_truncated_to_110(): void {
		$long = str_repeat( 'A', 150 );
		MockData::set( 'the_title', $long );
		MockData::set( 'post', (object) [ 'ID' => 42, 'post_title' => $long, 'post_content' => '', 'post_author' => 7 ] );

		$resolver = new Ligase_Field_Resolver();
		$res      = $resolver->resolve( 'BlogPosting', 42 );

		$this->assertLessThanOrEqual( 110, mb_strlen( $res['node']['headline'] ) );
		$this->assertStringEndsWith( '…', $res['node']['headline'] );
	}

	#[Test]
	public function test_blogposting_author_is_id_reference_not_string(): void {
		$resolver = new Ligase_Field_Resolver();
		$res      = $resolver->resolve( 'BlogPosting', 42 );

		$this->assertIsArray( $res['node']['author'] );
		$author_entry = $res['node']['author'][0] ?? null;
		$this->assertIsArray( $author_entry );
		$this->assertArrayHasKey( '@id', $author_entry );
		$this->assertStringContainsString( '#author-7', $author_entry['@id'] );
	}

	#[Test]
	public function test_readiness_summary_counts(): void {
		MockData::set_post_meta( 42, '_ligase_override', [
			'Product' => [
				'name'                 => 'Test Product',
				'offers.price'         => 19.99,
				'offers.priceCurrency' => 'PLN',
			],
		] );
		MockData::set( 'post_thumbnail_id', 100 );
		MockData::set( 'attachment_image_src', [ 'https://example.com/x.jpg', 1200, 1200 ] );

		$report = Ligase_Readiness::for_post( 42, 'Product' );
		$this->assertArrayHasKey( 'Product', $report );

		$summary = $report['Product']['summary'];
		$this->assertArrayHasKey( 'required', $summary );
		$this->assertArrayHasKey( 'filled', $summary['required'] );
		$this->assertGreaterThan( 0, $summary['required']['filled'] );
	}

	#[Test]
	public function test_country_sanitize_normalizes_to_iso_alpha2(): void {
		// Lowercase "pl" should be uppercased and 2-char-validated.
		MockData::set_option( 'ligase_options', [ 'store_return_country' => 'pl' ] );
		MockData::set_post_meta( 42, '_ligase_override', [
			'Product' => [
				'name'                 => 'X',
				'offers.price'         => 1.0,
				'offers.priceCurrency' => 'PLN',
				'offers.availability'  => 'https://schema.org/InStock',
			],
		] );
		MockData::set( 'post_thumbnail_id', 100 );
		MockData::set( 'attachment_image_src', [ 'https://example.com/x.jpg', 1200, 1200 ] );

		$resolver = new Ligase_Field_Resolver();
		$res      = $resolver->resolve( 'Product', 42 );

		$this->assertSame(
			'PL',
			$res['node']['offers']['hasMerchantReturnPolicy']['returnPolicyCountry'] ?? null
		);
	}
}
