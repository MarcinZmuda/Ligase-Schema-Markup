<?php
/**
 * Ligase Service Schema Type
 *
 * For static pages describing a service offered by the organization. Targets
 * legal services / accounting / consulting / agency services / home services —
 * any business selling a non-physical-product offering.
 *
 * Supports location-targeted service pages like "Adwokat rozwód Warszawa" by:
 *   - linking provider via @id to LocalBusiness/Attorney (preferred over Organization
 *     for local SEO — Google's local pack matches against LocalBusiness nodes)
 *   - emitting `areaServed` as an array of City/AdministrativeArea nodes (not plain
 *     strings, which Google ignores for local relevance signals)
 *   - including `eligibleRegion` on the Offer for region-restricted pricing
 *
 * @package Ligase
 * @since   2.1.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Service {

	public function build(): ?array {
		if ( ! is_page() ) {
			return null;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return null;
		}

		if ( get_post_meta( $post_id, '_ligase_enable_service', true ) !== '1' ) {
			return null;
		}

		$opts = (array) get_option( 'ligase_options', array() );
		$meta = (array) ( get_post_meta( $post_id, '_ligase_service', true ) ?: array() );

		$name = wp_strip_all_tags( $meta['name'] ?? get_the_title( $post_id ) );
		if ( $name === '' ) {
			return null;
		}

		$schema = array(
			'@type'       => 'Service',
			'@id'         => esc_url( get_permalink( $post_id ) ) . '#service',
			'name'        => $name,
			'url'         => esc_url( get_permalink( $post_id ) ),
			'provider'    => $this->resolve_provider( $meta ),
			'description' => wp_strip_all_tags( $meta['description'] ?? (string) get_the_excerpt( $post_id ) ),
		);

		// serviceType — short label like "Reprezentacja w sprawach rozwodowych".
		if ( ! empty( $meta['service_type'] ) ) {
			$schema['serviceType'] = wp_strip_all_tags( (string) $meta['service_type'] );
		}

		// category — for Service.category (broader umbrella, e.g. "Legal Services").
		if ( ! empty( $meta['category'] ) ) {
			$schema['category'] = wp_strip_all_tags( (string) $meta['category'] );
		}

		// areaServed — accepts:
		//   - single string (legacy) → wrap as Place
		//   - newline-separated list: "Warszawa", "Łódź", "Kraków" → array of City nodes
		//   - or "Polska" → AdministrativeArea
		// Multi-city service pages are the bread-and-butter of local SEO.
		$area = $this->parse_area_served( $meta );
		if ( $area !== null ) {
			$schema['areaServed'] = $area;
		}

		// Audience
		if ( ! empty( $meta['audience'] ) ) {
			$schema['audience'] = array(
				'@type'        => 'Audience',
				'audienceType' => wp_strip_all_tags( (string) $meta['audience'] ),
			);
		}

		// Offers — supports either flat price OR price range (typical for legal services).
		$offer = $this->build_offer( $meta, $opts, $post_id );
		if ( $offer !== null ) {
			$schema['offers'] = $offer;
		}

		// Featured image
		if ( has_post_thumbnail( $post_id ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			if ( $img ) {
				$schema['image'] = esc_url( (string) $img[0] );
			}
		}

		return apply_filters( 'ligase_service', $schema, $post_id );
	}

	/**
	 * Resolve provider reference. Priority order:
	 *   1. Explicit `provider_id` from meta (e.g. "#attorney", "#localbusiness")
	 *   2. LocalBusiness if configured site-wide (better local SEO than Organization)
	 *   3. Organization fallback
	 */
	private function resolve_provider( array $meta ): array {
		$explicit = trim( (string) ( $meta['provider_id'] ?? '' ) );
		if ( $explicit !== '' ) {
			// Normalize: accept "#attorney" or "https://site.tld/#attorney" or "attorney"
			if ( strpos( $explicit, '#' ) === false ) {
				$explicit = '#' . $explicit;
			}
			if ( strpos( $explicit, 'http' ) !== 0 ) {
				$explicit = home_url( '/' . $explicit );
			}
			return array( '@id' => esc_url( $explicit ) );
		}

		if ( class_exists( 'Ligase_Type_LocalBusiness' )
		     && method_exists( 'Ligase_Type_LocalBusiness', 'is_configured' )
		     && Ligase_Type_LocalBusiness::is_configured() ) {
			return array( '@id' => home_url( '/#localbusiness' ) );
		}

		return array( '@id' => home_url( '/#org' ) );
	}

	/**
	 * Parse areaServed from meta. Returns:
	 *   - null if nothing usable
	 *   - single Place/City/Country node when 1 area given
	 *   - array of nodes when multiple
	 *
	 * Stored as multi-line text (one location per line). Format per line:
	 *   "Warszawa"                      → City
	 *   "Warszawa | City"               → City
	 *   "Mazowieckie | AdministrativeArea" → AdministrativeArea
	 *   "Polska | Country"              → Country
	 */
	private function parse_area_served( array $meta ): mixed {
		$raw = $meta['area_served'] ?? '';
		if ( $raw === '' ) {
			return null;
		}
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$allowed_types = array( 'City', 'AdministrativeArea', 'State', 'Country', 'Place' );
		$nodes = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$parts = array_map( 'trim', explode( '|', $line ) );
			$name  = (string) ( $parts[0] ?? '' );
			$type  = (string) ( $parts[1] ?? 'City' );
			if ( $name === '' ) {
				continue;
			}
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'City';
			}
			$nodes[] = array(
				'@type' => $type,
				'name'  => wp_strip_all_tags( $name ),
			);
		}

		if ( empty( $nodes ) ) {
			return null;
		}
		return count( $nodes ) === 1 ? $nodes[0] : $nodes;
	}

	/**
	 * Build the Offer node for a Service page.
	 *
	 * Two pricing modes:
	 *   - flat: `price` + `priceCurrency`
	 *   - range: `price_low` + `price_high` + `priceCurrency` →
	 *            emits PriceSpecification with `minPrice`/`maxPrice`
	 *
	 * Optional `eligibleRegion` from `area_served` (so the Offer is bound to the same
	 * geography as the service itself).
	 */
	private function build_offer( array $meta, array $opts, int $post_id ): ?array {
		$currency = wp_strip_all_tags( (string) ( $meta['price_currency'] ?? ( $opts['store_currency'] ?? 'PLN' ) ) );
		$price       = trim( (string) ( $meta['price']      ?? '' ) );
		$price_low   = trim( (string) ( $meta['price_low']  ?? '' ) );
		$price_high  = trim( (string) ( $meta['price_high'] ?? '' ) );

		// Nothing usable → no offers node.
		if ( $price === '' && $price_low === '' ) {
			return null;
		}

		$offer = array(
			'@type'         => 'Offer',
			'priceCurrency' => $currency,
			'url'           => esc_url( get_permalink( $post_id ) ),
			'seller'        => array( '@id' => home_url( '/#org' ) ),
		);

		if ( $price !== '' ) {
			$offer['price'] = (string) (float) $price;
		} else {
			// Range — use PriceSpecification with min/max
			$range_spec = array(
				'@type'         => 'PriceSpecification',
				'priceCurrency' => $currency,
			);
			if ( $price_low !== '' ) {
				$range_spec['minPrice'] = (string) (float) $price_low;
				// Also set `price` to the low end so Google has a baseline figure.
				$offer['price'] = (string) (float) $price_low;
			}
			if ( $price_high !== '' ) {
				$range_spec['maxPrice'] = (string) (float) $price_high;
			}
			$offer['priceSpecification'] = $range_spec;
		}

		// eligibleRegion — bind the offer to the service's geographic area.
		$area = $this->parse_area_served( $meta );
		if ( $area !== null ) {
			$offer['eligibleRegion'] = $area;
		}

		// availability — services are typically continuously available; let the user override.
		$avail = (string) ( $meta['availability'] ?? 'InStock' );
		$allowed_avail = array( 'InStock', 'OutOfStock', 'PreOrder', 'LimitedAvailability', 'OnlineOnly' );
		if ( in_array( $avail, $allowed_avail, true ) ) {
			$offer['availability'] = 'https://schema.org/' . $avail;
		}

		return $offer;
	}
}
