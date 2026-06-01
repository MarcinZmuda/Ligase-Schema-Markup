<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_DefinedTerm {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_glossary', true ) !== '1' && ! ( class_exists( 'Ligase_Schema_Rules' ) && Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_glossary', $post_id ) ) ) {
            return null;
        }

        $terms = get_post_meta( $post_id, '_ligase_glossary_terms', true );

        if ( empty( $terms ) || ! is_array( $terms ) ) {
            return null;
        }

        $defined_terms = [];
        foreach ( $terms as $term ) {
            if ( empty( $term['name'] ) || empty( $term['description'] ) ) {
                continue;
            }
            $defined_terms[] = [
                '@type'       => 'DefinedTerm',
                'name'        => wp_strip_all_tags( $term['name'] ),
                'description' => wp_strip_all_tags( $term['description'] ),
                'inDefinedTermSet' => esc_url( get_permalink() ) . '#glossary',
            ];
        }

        if ( empty( $defined_terms ) ) {
            return null;
        }

        return [
            '@type'      => 'DefinedTermSet',
            '@id'        => esc_url( get_permalink() ) . '#glossary',
            'name'       => wp_strip_all_tags( get_the_title() ),
            'url'        => esc_url( get_permalink() ),
            'inLanguage' => str_replace( '_', '-', get_locale() ),
            'hasDefinedTerm' => $defined_terms,
        ];
    }
}
