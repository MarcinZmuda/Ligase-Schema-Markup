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

        // Merge flat meta written by the metabox UI on top of resolver output.
        // The UI saves `ligase_recipe[field]` → `_ligase_recipe[field]`; we apply it
        // here AFTER resolver so manual values win over post:* auto sources, but the
        // contract (validation, sanitization) still runs on the auto path. Whitelist
        // is mirrored from the contract field list.
        $manual = (array) ( get_post_meta( $post_id, '_ligase_recipe', true ) ?: array() );
        if ( ! empty( $manual ) ) {
            $allowed = array(
                'name', 'description', 'prepTime', 'cookTime', 'totalTime',
                'recipeYield', 'recipeCategory', 'recipeCuisine',
                'recipeIngredient', 'recipeInstructions',
            );
            foreach ( $allowed as $key ) {
                if ( isset( $manual[ $key ] ) && $manual[ $key ] !== '' && $manual[ $key ] !== array() ) {
                    $node[ $key ] = $manual[ $key ];
                }
            }
            // calories → nutrition.calories (nested NutritionInformation)
            if ( ! empty( $manual['calories'] ) ) {
                $node['nutrition'] = array(
                    '@type'    => 'NutritionInformation',
                    'calories' => wp_strip_all_tags( (string) $manual['calories'] ),
                );
            }
        }

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
