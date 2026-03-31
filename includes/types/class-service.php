<?php
/**
 * Ligase Service Schema Type
 *
 * For static pages describing a service offered by the organization.
 * Enabled per-page via metabox toggle.
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

		$opts     = (array) get_option( 'ligase_options', array() );
		$org_name = ! empty( $opts['org_name'] ) ? $opts['org_name'] : get_bloginfo( 'name' );

		$meta = (array) ( get_post_meta( $post_id, '_ligase_service', true ) ?: array() );

		$schema = array(
			'@type'       => 'Service',
			'@id'         => esc_url( get_permalink( $post_id ) ) . '#service',
			'name'        => esc_html( $meta['name'] ?? get_the_title( $post_id ) ),
			'url'         => esc_url( get_permalink( $post_id ) ),
			'provider'    => array( '@id' => home_url( '/#org' ) ),
			'description' => esc_html( $meta['description'] ?? wp_strip_all_tags( get_the_excerpt( $post_id ) ) ),
		);

		// Service type / category
		if ( ! empty( $meta['service_type'] ) ) {
			$schema['serviceType'] = esc_html( $meta['service_type'] );
		}

		// Area served
		if ( ! empty( $meta['area_served'] ) ) {
			$schema['areaServed'] = esc_html( $meta['area_served'] );
		}

		// Audience
		if ( ! empty( $meta['audience'] ) ) {
			$schema['audience'] = array(
				'@type'       => 'Audience',
				'audienceType' => esc_html( $meta['audience'] ),
			);
		}

		// Offers / pricing
		$price         = $meta['price'] ?? '';
		$price_currency = $meta['price_currency'] ?? 'PLN';
		if ( $price ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => esc_html( $price ),
				'priceCurrency' => esc_html( $price_currency ),
				'url'           => esc_url( get_permalink( $post_id ) ),
				'seller'        => array( '@id' => home_url( '/#org' ) ),
			);
		}

		// Image from featured image
		if ( has_post_thumbnail( $post_id ) ) {
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			if ( $img ) {
				$schema['image'] = esc_url( $img[0] );
			}
		}

		return apply_filters( 'ligase_service', $schema, $post_id );
	}
}
