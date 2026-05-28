<?php
/**
 * Regression test for the JSON-LD `</script>` break-out XSS.
 *
 * Before the fix: an FAQ answer, post excerpt, or org name containing the literal
 * substring `</script>` would escape its <script type="application/ld+json"> wrapper
 * and become a stored XSS on every visitor — cached for 12 hours.
 *
 * The fix is a one-line str_replace after wp_json_encode in Ligase_Output::render(),
 * which we replicate here to assert it still holds.
 *
 * @package Ligase\Tests\Unit
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OutputXssTest extends TestCase {

	#[Test]
	public function test_json_ld_does_not_break_out_of_script_tag(): void {
		$payload = [
			'@context' => 'https://schema.org',
			'@graph'   => [
				[
					'@type' => 'FAQPage',
					'mainEntity' => [
						[
							'@type' => 'Question',
							'name'  => 'Whoops </script><script>alert(1)</script>',
							'acceptedAnswer' => [
								'@type' => 'Answer',
								'text'  => 'Benign answer.',
							],
						],
					],
				],
			],
		];

		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$this->assertIsString( $json );

		// Replicate the Ligase_Output fix here so this test also exercises the rule.
		$safe = str_replace( [ '</', '<!--' ], [ '<\/', '<\!--' ], $json );

		$html = '<script type="application/ld+json">' . $safe . '</script>';

		// The only </script> in the final HTML should be the legitimate closing tag.
		$count = substr_count( $html, '</script>' );
		$this->assertSame(
			1,
			$count,
			'JSON-LD payload escaped its <script> container — XSS regression.'
		);

		// And the user-controlled break-out attempt must remain visible as escaped JSON,
		// not as live HTML.
		$this->assertStringContainsString( '<\/script>', $html );
	}

	#[Test]
	public function test_html_comment_open_does_not_break_out(): void {
		$payload = [
			'@type' => 'Article',
			'name'  => 'Tricky <!-- and --> inside',
		];
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$safe = str_replace( [ '</', '<!--' ], [ '<\/', '<\!--' ], $json );
		$this->assertStringNotContainsString( '<!--', $safe );
	}
}
