<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_HowTo {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        if ( get_post_meta( get_the_ID(), '_ligase_enable_howto', true ) !== '1' && ! ( class_exists( 'Ligase_Schema_Rules' ) && Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_howto', get_the_ID() ) ) ) {
            return null;
        }

        $post_id    = get_the_ID();
        $howto_data = get_post_meta( $post_id, '_ligase_howto', true );

        if ( empty( $howto_data ) || ! is_array( $howto_data ) || empty( $howto_data['steps'] ) || ! is_array( $howto_data['steps'] ) ) {
            return null;
        }

        $steps = [];
        foreach ( $howto_data['steps'] as $i => $step ) {
            if ( ! is_array( $step ) || empty( $step['name'] ) || empty( $step['text'] ) ) {
                continue;
            }
            $steps[] = [
                '@type'    => 'HowToStep',
                'position' => $i + 1,
                'name'     => wp_strip_all_tags( $step['name'] ),
                'text'     => wp_strip_all_tags( $step['text'] ),
                'url'      => esc_url( get_permalink() ) . '#krok-' . ( $i + 1 ),
            ];
        }

        if ( empty( $steps ) ) {
            return null;
        }

        $schema = [
            '@type'      => 'HowTo',
            '@id'        => esc_url( get_permalink() ) . '#howto',
            'name'       => wp_strip_all_tags( $howto_data['name'] ?? get_the_title() ),
            'inLanguage' => str_replace( '_', '-', get_locale() ),
            'step'       => $steps,
        ];

        // Google requires `image` for HowTo rich results. Use explicit howto image, then
        // post thumbnail, then organization logo as last-ditch fallback. Without ANY image
        // Google silently drops the rich result.
        $image_url = '';
        if ( ! empty( $howto_data['image'] ) ) {
            $image_url = $howto_data['image'];
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

        if ( ! empty( $howto_data['totalTime'] ) && $this->is_iso8601_duration( $howto_data['totalTime'] ) ) {
            $schema['totalTime'] = wp_strip_all_tags( $howto_data['totalTime'] );
        }

        return $schema;
    }

    private function is_iso8601_duration( string $duration ): bool {
        return (bool) preg_match( '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/', $duration );
    }
}
