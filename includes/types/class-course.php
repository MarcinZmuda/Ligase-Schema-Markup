<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Course {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_course', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_course', $post_id ) ) {
            return null;
        }

        $data = get_post_meta( $post_id, '_ligase_course', true );

        if ( empty( $data ) || ! is_array( $data ) || empty( $data['name'] ) ) {
            return null;
        }

        $schema = [
            '@type'       => 'Course',
            '@id'         => esc_url( get_permalink() ) . '#course',
            'name'        => esc_html( $data['name'] ),
            'url'         => esc_url( get_permalink() ),
            'inLanguage'  => str_replace( '_', '-', get_locale() ),
            'provider'    => [ '@id' => home_url( '/#org' ) ],
        ];

        if ( ! empty( $data['description'] ) ) {
            $schema['description'] = esc_html( mb_substr( $data['description'], 0, 300 ) );
        } else {
            $excerpt = wp_strip_all_tags( get_the_excerpt() );
            if ( $excerpt ) {
                $schema['description'] = mb_substr( $excerpt, 0, 300 );
            }
        }

        if ( ! empty( $data['teaches'] ) ) {
            $schema['teaches'] = array_map( 'trim', explode( ',', $data['teaches'] ) );
        }

        // Course instance
        $instance = [];

        $mode = $data['mode'] ?? 'Online';
        $allowed_modes = [ 'Online', 'Onsite', 'Blended' ];
        if ( in_array( $mode, $allowed_modes, true ) ) {
            $instance['courseMode'] = $mode;
        }

        if ( ! empty( $data['start_date'] ) ) {
            $instance['startDate'] = esc_html( $data['start_date'] );
        }

        if ( ! empty( $data['end_date'] ) ) {
            $instance['endDate'] = esc_html( $data['end_date'] );
        }

        if ( ! empty( $instance ) ) {
            $instance['@type'] = 'CourseInstance';
            $schema['hasCourseInstance'] = $instance;
        }

        // Price
        if ( isset( $data['price'] ) ) {
            $schema['offers'] = [
                '@type'         => 'Offer',
                'price'         => esc_html( $data['price'] ),
                'priceCurrency' => esc_html( $data['currency'] ?? 'PLN' ),
                'availability'  => 'https://schema.org/InStock',
            ];
        }

        return $schema;
    }
}
