<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Review {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        if ( get_post_meta( get_the_ID(), '_ligase_enable_review', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_review', get_the_ID() ) ) {
            return null;
        }

        $post_id     = get_the_ID();
        $author_id   = (int) get_post_field( 'post_author', $post_id );
        $review_data = get_post_meta( $post_id, '_ligase_review', true );

        if ( empty( $review_data ) || ! is_array( $review_data ) || empty( $review_data['rating'] ) ) {
            return null;
        }

        $rating = (float) $review_data['rating'];
        if ( $rating < 1 || $rating > 5 ) {
            if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::warning( 'Invalid review rating value', [
                    'post_id' => $post_id,
                    'rating'  => $rating,
                ] );
            }
            return null;
        }

        $schema = [
            '@type'       => 'Review',
            'author'      => [ '@id' => home_url( '/#author-' . $author_id ) ],
            'publisher'   => [ '@id' => home_url( '/#org' ) ],
            'datePublished' => get_the_date( 'c', $post_id ),
            'reviewRating' => [
                '@type'       => 'Rating',
                'ratingValue' => (string) $rating,
                'bestRating'  => '5',
                'worstRating' => '1',
            ],
        ];

        $schema['name'] = esc_html( $review_data['name'] ?? get_the_title( $post_id ) );

        if ( ! empty( $review_data['body'] ) ) {
            $schema['reviewBody'] = esc_html( mb_substr( $review_data['body'], 0, 500 ) );
        }

        if ( ! empty( $review_data['item_name'] ) ) {
            $allowed_types = [ 'Thing', 'Product', 'SoftwareApplication', 'Book', 'Course', 'Movie', 'Restaurant', 'LocalBusiness' ];
            $item_type = $review_data['item_type'] ?? 'Thing';
            if ( ! in_array( $item_type, $allowed_types, true ) ) {
                $item_type = 'Thing';
            }
            $schema['itemReviewed'] = [
                '@type' => $item_type,
                'name'  => esc_html( $review_data['item_name'] ),
            ];
        }

        return $schema;
    }
}
