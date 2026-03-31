<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_BreadcrumbList {

    public function build(): ?array {
        // Skip homepage — 1-item breadcrumb [Home] is invalid per Google guidelines
        if ( is_front_page() ) {
            return null;
        }

        if ( ! is_single() && ! is_page() && ! is_category() && ! is_tag() && ! is_tax() ) {
            return null;
        }

        $items = [];
        $pos   = 1;

        $items[] = [
            '@type'    => 'ListItem',
            'position' => $pos++,
            'name'     => esc_html( get_bloginfo( 'name' ) ),
            'item'     => esc_url( home_url( '/' ) ),
        ];

        if ( is_single() && get_post_type() === 'post' ) {
            $cats = get_the_category();
            if ( ! empty( $cats ) && is_array( $cats ) ) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => esc_html( $cats[0]->name ),
                    'item'     => esc_url( get_category_link( $cats[0]->term_id ) ),
                ];
            }
        }

        // Nested pages: add ancestor hierarchy
        if ( is_page() ) {
            $ancestors = array_reverse( get_post_ancestors( get_the_ID() ) );
            foreach ( $ancestors as $ancestor_id ) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => esc_html( get_the_title( $ancestor_id ) ),
                    'item'     => esc_url( get_permalink( $ancestor_id ) ),
                ];
            }
        }

        // CPT single — add post type archive link if it exists
        if ( is_single() && get_post_type() !== 'post' && get_post_type() !== 'page' ) {
            $post_type     = get_post_type();
            $archive_link  = get_post_type_archive_link( $post_type );
            $post_type_obj = get_post_type_object( $post_type );
            if ( $archive_link && $post_type_obj ) {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos++,
                    'name'     => esc_html( $post_type_obj->labels->name ),
                    'item'     => esc_url( $archive_link ),
                ];
            }
        }

        // Taxonomy/tag archive — current term as last item
        if ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                // Parent terms
                if ( $term->parent ) {
                    $ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
                    foreach ( $ancestors as $ancestor_id ) {
                        $ancestor = get_term( $ancestor_id, $term->taxonomy );
                        if ( $ancestor && ! is_wp_error( $ancestor ) ) {
                            $items[] = [
                                '@type'    => 'ListItem',
                                'position' => $pos++,
                                'name'     => esc_html( $ancestor->name ),
                                'item'     => esc_url( get_term_link( $ancestor ) ),
                            ];
                        }
                    }
                }
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => $pos,
                    'name'     => esc_html( $term->name ),
                ];
            }

            $id  = is_category() || is_tag() || is_tax() ? get_term_link( get_queried_object() ) : home_url( '/' );
            $url = is_string( $id ) ? $id : home_url( '/' );

            return [
                '@type'           => 'BreadcrumbList',
                '@id'             => esc_url( $url ) . '#breadcrumb',
                'itemListElement' => $items,
            ];
        }

        $last_item = [
            '@type'    => 'ListItem',
            'position' => $pos,
            'name'     => esc_html( get_the_title() ),
        ];
        // Add URL to current page — Google recommends it for richer display
        $permalink = get_permalink();
        if ( $permalink ) {
            $last_item['item'] = esc_url( $permalink );
        }
        $items[] = $last_item;

        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => esc_url( get_permalink() ) . '#breadcrumb',
            'itemListElement' => $items,
        ];
    }
}
