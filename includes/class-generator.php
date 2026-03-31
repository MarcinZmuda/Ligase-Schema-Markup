<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Generator {

    public function get_graph(): array {
        $graph = [];

        // WebSite + Organization always — on every page
        $graph[] = ( new Ligase_Type_WebSite() )->build();
        $graph[] = ( new Ligase_Type_Organization() )->build();

        // SiteNavigationElement — auto, for all registered WP menu locations
        foreach ( ( new Ligase_Type_SiteNavigationElement() )->build() as $nav ) {
            $graph[] = $nav;
        }

        // LocalBusiness — only when configured (address filled in Settings)
        // Added to all pages so entity is consistent across the site
        if ( Ligase_Type_LocalBusiness::is_configured() ) {
            $graph[] = ( new Ligase_Type_LocalBusiness() )->build();
        }

        // ── Blog post (single post) ─────────────────────────────────────────
        if ( is_single() && get_post_type() === 'post' ) {
            $author_id = (int) get_post_field( 'post_author' );

            $graph[] = ( new Ligase_Type_BlogPosting() )->build();
            $graph[] = ( new Ligase_Type_Person( $author_id ) )->build();
            $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();

            foreach ( $this->get_optional_types() as $type ) {
                $schema = $type->build();
                if ( ! empty( $schema ) ) {
                    $graph[] = $schema;
                }
            }
        }

        // ── Static page (is_page) ───────────────────────────────────────────
        if ( is_page() ) {
            $graph[] = $this->build_webpage();
            $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
        }

        // ── Homepage (front page) ───────────────────────────────────────────
        if ( is_front_page() && ! is_page() ) {
            // Static front page is handled by is_page() above.
            // This covers blog-posts-as-homepage (Settings > Reading > Latest posts).
            $graph[] = $this->build_webpage( 'CollectionPage' );
        }

        // ── Custom Post Type (single, not 'post') ───────────────────────────
        if ( is_single() && get_post_type() !== 'post' && get_post_type() !== 'page' ) {
            $author_id = (int) get_post_field( 'post_author' );
            $graph[]   = $this->build_webpage();
            $graph[]   = ( new Ligase_Type_Person( $author_id ) )->build();
            $graph[]   = ( new Ligase_Type_BreadcrumbList() )->build();

            // Optional types still apply (FAQ, HowTo, VideoObject etc.)
            foreach ( $this->get_optional_types() as $type ) {
                $schema = $type->build();
                if ( ! empty( $schema ) ) {
                    $graph[] = $schema;
                }
            }
        }

        // ── Blog posts listing page (is_home, not front page) ───────────────
        // Triggered when Settings > Reading > Posts page is set to a separate URL
        // e.g. corporate site with /blog/ page
        if ( is_home() && ! is_front_page() ) {
            $graph[] = $this->build_collection_page();
            $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
        }

        // ── Category / tag / taxonomy archive ──────────────────────────────
        if ( is_category() || is_tag() || is_tax() ) {
            $graph[] = $this->build_collection_page();
            $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
        }

        // ── Author archive ──────────────────────────────────────────────────
        if ( is_author() ) {
            $author_id = (int) get_queried_object_id();
            $graph[]   = ( new Ligase_Type_Person( $author_id ) )->build();
            $graph[]   = $this->build_profile_page( $author_id );
            $graph[]   = $this->build_collection_page();
        }

        // ── Date / search archives ──────────────────────────────────────────
        if ( is_date() || is_search() ) {
            $graph[] = $this->build_collection_page();
        }

        $graph = apply_filters( 'ligase_schema_graph', $graph );

        return array_values( array_filter( $graph ) );
    }

    /**
     * Generate schema for a specific post without outputting.
     * Used by AJAX preview and testing.
     */
    public function get_graph_for_post( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return [];
        }

        // Set up global post context so get_the_ID(), get_the_title() etc. work
        global $wp_query;
        $original_post  = $GLOBALS['post'] ?? null;
        $original_query = $wp_query->post ?? null;
        $GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
        setup_postdata( $post );

        $graph = [];

        $graph[] = ( new Ligase_Type_WebSite() )->build();
        $graph[] = ( new Ligase_Type_Organization() )->build();

        $author_id = (int) get_post_field( 'post_author', $post_id );

        if ( $post->post_type === 'post' ) {
            $graph[] = ( new Ligase_Type_BlogPosting() )->build();
        } else {
            $graph[] = $this->build_webpage();
        }

        $graph[] = ( new Ligase_Type_Person( $author_id ) )->build();
        $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();

        foreach ( $this->get_optional_types() as $type ) {
            $schema = $type->build();
            if ( ! empty( $schema ) ) {
                $graph[] = $schema;
            }
        }

        // Restore original post context
        if ( $original_post ) {
            $GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
            setup_postdata( $original_post );
        } else {
            wp_reset_postdata();
        }

        $graph = apply_filters( 'ligase_schema_graph', $graph );

        return array_values( array_filter( $graph ) );
    }

    /**
     * Build a WebPage schema for static pages and CPT.
     *
     * @param string $type  WebPage subtype: 'WebPage', 'AboutPage', 'ContactPage', 'CollectionPage'.
     */
    private function build_webpage( string $type = 'WebPage' ): array {
        $post_id = get_the_ID();

        // Auto-detect page type from common slugs
        if ( $type === 'WebPage' && $post_id ) {
            $slug = get_post_field( 'post_name', $post_id );
            $type = match ( true ) {
                in_array( $slug, [ 'about', 'o-nas', 'about-us', 'o-mnie' ], true ) => 'AboutPage',
                in_array( $slug, [ 'contact', 'kontakt', 'contact-us' ], true )     => 'ContactPage',
                default                                                               => 'WebPage',
            };
        }

        $schema = [
            '@type'           => $type,
            '@id'             => esc_url( get_permalink() ?: home_url( '/' ) ),
            'name'            => esc_html( get_the_title() ?: get_bloginfo( 'name' ) ),
            'url'             => esc_url( get_permalink() ?: home_url( '/' ) ),
            'inLanguage'      => str_replace( '_', '-', get_locale() ),
            'isPartOf'        => [ '@id' => home_url( '/#website' ) ],
            'publisher'       => [ '@id' => home_url( '/#org' ) ],
        ];

        $excerpt = $post_id ? wp_strip_all_tags( get_the_excerpt( $post_id ) ) : '';
        if ( $excerpt ) {
            $schema['description'] = esc_html( mb_substr( $excerpt, 0, 300 ) );
        }

        $modified = $post_id ? get_the_modified_date( 'c', $post_id ) : '';
        if ( $modified ) {
            $schema['dateModified'] = $modified;
        }

        return apply_filters( 'ligase_webpage', $schema, $post_id );
    }

    /**
     * Build a CollectionPage schema for archives.
     */
    private function build_collection_page(): array {
        if ( is_category() || is_tag() || is_tax() ) {
            $term   = get_queried_object();
            $name   = $term ? $term->name : get_bloginfo( 'name' );
            $url    = $term ? get_term_link( $term ) : home_url( '/' );
            $desc   = $term ? $term->description : '';
        } elseif ( is_author() ) {
            $author = get_queried_object();
            $name   = $author ? $author->display_name : get_bloginfo( 'name' );
            $url    = $author ? get_author_posts_url( $author->ID ) : home_url( '/' );
            $desc   = $author ? $author->description : '';
        } elseif ( is_home() && ! is_front_page() ) {
            // Blog archive page on corporate site (e.g. /blog/)
            $blog_page_id = (int) get_option( 'page_for_posts' );
            if ( $blog_page_id ) {
                $name = get_the_title( $blog_page_id );
                $url  = get_permalink( $blog_page_id );
                $desc = get_the_excerpt( $blog_page_id );
            } else {
                $name = get_bloginfo( 'name' ) . ' — Blog';
                $url  = home_url( '/' );
                $desc = '';
            }
        } else {
            $name = get_bloginfo( 'name' );
            $url  = home_url( '/' );
            $desc = '';
        }

        $schema = [
            '@type'      => 'CollectionPage',
            '@id'        => esc_url( is_string( $url ) ? $url : home_url( '/' ) ),
            'name'       => esc_html( $name ),
            'url'        => esc_url( is_string( $url ) ? $url : home_url( '/' ) ),
            'inLanguage' => str_replace( '_', '-', get_locale() ),
            'isPartOf'   => [ '@id' => home_url( '/#website' ) ],
        ];

        if ( $desc ) {
            $schema['description'] = esc_html( wp_strip_all_tags( $desc ) );
        }

        return apply_filters( 'ligase_collection_page', $schema );
    }

    /**
     * Build a ProfilePage schema for author archive pages.
     * Combines Person reference with ProfilePage — the recommended pattern for E-E-A-T.
     *
     * @param int $author_id  WP user ID.
     */
    private function build_profile_page( int $author_id ): array {
        $user        = get_userdata( $author_id );
        $author_url  = esc_url( get_author_posts_url( $author_id ) );
        $name        = $user ? esc_html( $user->display_name ) : '';
        $description = $user ? esc_html( $user->description ) : '';

        $schema = [
            '@type'       => 'ProfilePage',
            '@id'         => $author_url . '#profilepage',
            'url'         => $author_url,
            'name'        => $name . ' — ' . esc_html( get_bloginfo( 'name' ) ),
            'inLanguage'  => str_replace( '_', '-', get_locale() ),
            'isPartOf'    => [ '@id' => home_url( '/#website' ) ],
            'about'       => [ '@id' => home_url( '/#author-' . $author_id ) ],
            'mainEntity'  => [ '@id' => home_url( '/#author-' . $author_id ) ],
        ];

        if ( $description ) {
            $schema['description'] = $description;
        }

        // dateModified: when the author last published a post
        $last_post = get_posts( [
            'author'         => $author_id,
            'posts_per_page' => 1,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ] );
        if ( ! empty( $last_post ) ) {
            $schema['dateModified'] = get_the_modified_date( 'c', $last_post[0] );
        }

        return apply_filters( 'ligase_profile_page', $schema, $author_id );
    }

    /**
     * Get all optional schema type instances.
     * Each type checks its own enable flag internally.
     */
    private function get_optional_types(): array {
        return [
            new Ligase_Type_FAQPage(),
            new Ligase_Type_HowTo(),
            new Ligase_Type_VideoObject(),
            new Ligase_Type_Review(),
            new Ligase_Type_QAPage(),
            new Ligase_Type_DefinedTerm(),
            new Ligase_Type_ClaimReview(),
            new Ligase_Type_SoftwareApplication(),
            new Ligase_Type_AudioObject(),
            new Ligase_Type_Course(),
            new Ligase_Type_Event(),
            new Ligase_Type_Service(),
        ];
    }


}
