<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_SoftwareApplication {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_software', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_software', $post_id ) ) {
            return null;
        }

        $data = get_post_meta( $post_id, '_ligase_software', true );

        if ( empty( $data ) || ! is_array( $data ) || empty( $data['name'] ) ) {
            return null;
        }

        $allowed_categories = [
            'WebApplication', 'MobileApplication', 'DesktopApplication',
            'GameApplication', 'SocialNetworkingApplication',
        ];
        $category = $data['category'] ?? 'WebApplication';
        if ( ! in_array( $category, $allowed_categories, true ) ) {
            $category = 'WebApplication';
        }

        $schema = [
            '@type'                => 'SoftwareApplication',
            '@id'                  => esc_url( get_permalink() ) . '#software',
            'name'                 => wp_strip_all_tags( $data['name'] ),
            'applicationCategory'  => $category,
            'publisher'            => [ '@id' => home_url( '/#org' ) ],
        ];

        // Google requires `image` for SoftwareApplication rich results.
        $image_url = '';
        if ( ! empty( $data['image'] ) ) {
            $image_url = $data['image'];
        } else {
            $tid = get_post_thumbnail_id( $post_id );
            if ( $tid ) {
                $img = wp_get_attachment_image_src( $tid, 'full' );
                if ( $img ) {
                    $image_url = $img[0];
                }
            }
        }
        if ( $image_url ) {
            $schema['image'] = esc_url( $image_url );
        }

        if ( ! empty( $data['os'] ) ) {
            $schema['operatingSystem'] = wp_strip_all_tags( $data['os'] );
        }

        if ( ! empty( $data['url'] ) ) {
            $schema['url'] = esc_url( $data['url'] );
        }

        // Price
        $price    = $data['price'] ?? '0';
        $currency = $data['currency'] ?? 'USD';
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => wp_strip_all_tags( $price ),
            'priceCurrency' => wp_strip_all_tags( $currency ),
        ];

        // Rating (if Review is also enabled, link them)
        if ( ! empty( $data['rating'] ) ) {
            $rating = (float) $data['rating'];
            if ( $rating >= 1 && $rating <= 5 ) {
                $schema['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => (string) $rating,
                    'bestRating'  => '5',
                    'ratingCount' => (string) ( $data['rating_count'] ?? '1' ),
                ];
            }
        }

        return $schema;
    }
}
