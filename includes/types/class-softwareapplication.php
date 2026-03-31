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
            'name'                 => esc_html( $data['name'] ),
            'applicationCategory' => $category,
        ];

        if ( ! empty( $data['os'] ) ) {
            $schema['operatingSystem'] = esc_html( $data['os'] );
        }

        if ( ! empty( $data['url'] ) ) {
            $schema['url'] = esc_url( $data['url'] );
        }

        // Price
        $price    = $data['price'] ?? '0';
        $currency = $data['currency'] ?? 'USD';
        $schema['offers'] = [
            '@type'         => 'Offer',
            'price'         => esc_html( $price ),
            'priceCurrency' => esc_html( $currency ),
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
