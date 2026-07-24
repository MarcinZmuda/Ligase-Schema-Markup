<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Course {

    public function build(): ?array {
        if ( ! is_singular() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_course', true ) !== '1' && ! ( class_exists( 'Ligase_Schema_Rules' ) && Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_course', $post_id ) ) ) {
            return null;
        }

        $data = get_post_meta( $post_id, '_ligase_course', true );

        if ( empty( $data ) || ! is_array( $data ) || empty( $data['name'] ) ) {
            return null;
        }

        $schema = [
            '@type'       => 'Course',
            '@id'         => esc_url( get_permalink() ) . '#course',
            'name'        => wp_strip_all_tags( $data['name'] ),
            'url'         => esc_url( get_permalink() ),
            'inLanguage'  => str_replace( '_', '-', get_locale() ),
            'provider'    => [ '@id' => home_url( '/#org' ) ],
        ];

        if ( ! empty( $data['description'] ) ) {
            $schema['description'] = wp_strip_all_tags( mb_substr( $data['description'], 0, 300 ) );
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

        // startDate / endDate must be ISO-8601 (date or datetime). A free-text value
        // like "wrzesień 2026" disqualifies the CourseInstance, so validate and skip
        // the field when it does not match rather than emit an invalid date.
        $iso_re = '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?([+\-]\d{2}:\d{2}|Z)?)?$/';
        if ( ! empty( $data['start_date'] ) ) {
            $start_raw = wp_strip_all_tags( (string) $data['start_date'] );
            if ( preg_match( $iso_re, $start_raw ) ) {
                $instance['startDate'] = $start_raw;
            }
        }

        if ( ! empty( $data['end_date'] ) ) {
            $end_raw = wp_strip_all_tags( (string) $data['end_date'] );
            if ( preg_match( $iso_re, $end_raw ) ) {
                $instance['endDate'] = $end_raw;
            }
        }

        // Evergreen fallback: with no explicit start date, use the post's
        // last-modified date so hasCourseInstance always carries a (fresh)
        // startDate. Google needs a date/schedule on the CourseInstance for the
        // course to be eligible, and editorial course pages rarely have fixed dates.
        if ( empty( $instance['startDate'] ) ) {
            $instance['startDate'] = get_the_modified_date( 'c', $post_id );
        }

        if ( ! empty( $instance ) ) {
            $instance['@type'] = 'CourseInstance';
            $schema['hasCourseInstance'] = $instance;
        }

        // Price
        if ( isset( $data['price'] ) ) {
            $schema['offers'] = [
                '@type'         => 'Offer',
                'price'         => wp_strip_all_tags( $data['price'] ),
                'priceCurrency' => wp_strip_all_tags( $data['currency'] ?? 'PLN' ),
                'availability'  => 'https://schema.org/InStock',
            ];
        }

        return $schema;
    }
}
