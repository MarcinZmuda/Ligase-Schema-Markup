<?php
/**
 * Ligase - Recipe schema type
 *
 * Builds Recipe via the contract resolver. Recipe is one of only four host-carousel-
 * eligible Google rich result types (alongside Course, Movie, Restaurant) and remains
 * fully alive in 2026. Critical for food blogs.
 *
 * Required fields (per Google docs Dec 2025): name + image; recipeIngredient and
 * recipeInstructions are realistically required for any useful rich result.
 *
 * @package Ligase
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Recipe {

    public function build(): ?array {
        if ( ! is_singular() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_recipe', true ) !== '1'
            && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_recipe', $post_id ) ) {
            return null;
        }

        if ( ! class_exists( 'Ligase_Field_Resolver' ) ) {
            return null;
        }

        $resolved = ( new Ligase_Field_Resolver() )->resolve( 'Recipe', $post_id );
        $node     = $resolved['node'];

        // Recipe needs name + image. Without those there's no useful rich result;
        // skip the node rather than emit half a Recipe that Search Console flags.
        if ( empty( $node['name'] ) || empty( $node['image'] ) ) {
            return null;
        }

        // Convert recipeInstructions from a flat list of strings into HowToStep nodes
        // when the user provided plain strings. Google accepts both, but HowToStep
        // unlocks the "step-by-step" rich result enhancement.
        if ( ! empty( $node['recipeInstructions'] ) && is_array( $node['recipeInstructions'] ) ) {
            $node['recipeInstructions'] = array_map(
                function ( $step, $i ) use ( $post_id ) {
                    if ( is_array( $step ) ) {
                        return $step; // already a HowToStep-shaped node
                    }
                    return array(
                        '@type'    => 'HowToStep',
                        'position' => (int) $i + 1,
                        'text'     => wp_strip_all_tags( (string) $step ),
                        'url'      => esc_url( get_permalink( $post_id ) ) . '#step-' . ( (int) $i + 1 ),
                    );
                },
                $node['recipeInstructions'],
                array_keys( $node['recipeInstructions'] )
            );
        }

        // Pin @id to a stable fragment.
        $node['@id'] = esc_url( get_permalink( $post_id ) ) . '#recipe';

        return apply_filters( 'ligase_recipe', $node, $post_id );
    }
}
