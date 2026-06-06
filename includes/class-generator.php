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
        if ( Ligase_Type_LocalBusiness::is_configured() ) {
            $graph[] = ( new Ligase_Type_LocalBusiness() )->build();
        }

        // ── Branch selection: derive page context from the QUERIED OBJECT, not
        //    from is_single()/is_tax(). Some themes (XStore, Divi, Avada) and
        //    related-products widgets call query_posts() before wp_head fires at
        //    priority 5, which makes is_single() return false and is_tax() return
        //    true on what is actually a single product page. The queried object
        //    is set ONCE when the main query is parsed and isn't affected by
        //    subsequent secondary queries.
        $queried = get_queried_object();
        $resolved_context = $this->resolve_context( $queried );

        switch ( $resolved_context ) {
            case 'single_post':
                $this->add_blog_post_graph( $graph, $queried );
                break;
            case 'single_cpt':
                $this->add_cpt_single_graph( $graph, $queried );
                break;
            case 'page':
                $graph[] = $this->build_webpage();
                $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
                // ProfilePage / Person opt-in: a static page (e.g. /o-mnie/, /zespol/lucyna/,
                // /lucyna-w-mediach/) can declare itself a Person profile by setting
                // _ligase_enable_profile_page=1 + _ligase_profile_user_id=<user_id>.
                // Emits Person (full E-E-A-T) + ProfilePage (mainEntity → Person).
                $profile_uid = $this->resolve_profile_user_id( $queried );
                if ( $profile_uid > 0 ) {
                    $graph[] = ( new Ligase_Type_Person( $profile_uid ) )->build();
                    $graph[] = $this->build_profile_page( $profile_uid );
                }
                // Optional types (PodcastSeries / Service / Event / FAQ / HowTo / Course /
                // SoftwareApplication / etc.) also opt-in via per-page _ligase_enable_*
                // meta. Each type's build() guards its own meta check and returns null
                // when not enabled, so iterating here is safe.
                foreach ( $this->get_optional_types() as $type ) {
                    $schema = $type->build();
                    if ( ! empty( $schema ) ) {
                        $graph[] = $schema;
                    }
                }
                break;
            case 'front_page_posts':
                $graph[] = $this->build_webpage( 'CollectionPage' );
                break;
            case 'blog_listing':
                $graph[] = $this->build_collection_page();
                $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
                $graph[] = ( new Ligase_Type_ItemList() )->build();
                break;
            case 'taxonomy_archive':
                $graph[] = $this->build_collection_page();
                $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();
                $graph[] = ( new Ligase_Type_ItemList() )->build(); // products / posts carousel
                break;
            case 'author_archive':
                $author_id = $queried instanceof WP_User ? (int) $queried->ID : (int) get_queried_object_id();
                $graph[]   = ( new Ligase_Type_Person( $author_id ) )->build();
                $graph[]   = $this->build_profile_page( $author_id );
                $graph[]   = $this->build_collection_page();
                $graph[]   = ( new Ligase_Type_ItemList() )->build();
                break;
            case 'date_or_search':
                $graph[] = $this->build_collection_page();
                break;
            // 'unknown' → no page-specific schema, just the site-wide entities above
        }

        // ItemList also for blog_listing (WP "Posts page") + WooCommerce shop archive
        // (`is_shop()`). ItemList class auto-detects context and returns null when
        // nothing matches, so it's safe to call here regardless of $resolved_context.
        if ( in_array( $resolved_context, [ 'blog_listing', 'unknown' ], true )
             || ( function_exists( 'is_shop' ) && is_shop() )
             || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
            $item_list = ( new Ligase_Type_ItemList() )->build();
            if ( $item_list ) {
                $graph[] = $item_list;
            }
        }

        $graph = apply_filters( 'ligase_schema_graph', $graph );

        return array_values( array_filter( $graph ) );
    }

    /**
     * Decide what kind of page this is from the queried object.
     *
     * Returns one of: single_post / single_cpt / page / front_page_posts /
     * blog_listing / taxonomy_archive / author_archive / date_or_search / unknown.
     *
     * Resilient against query_posts() corruption: get_queried_object() reads from
     * the original main query, not the current one.
     */
    private function resolve_context( $queried ): string {
        // is_page/is_front_page/is_home work off the ORIGINAL main query in modern WP
        // even after query_posts(), but we still cross-check queried_object for safety.
        if ( $queried instanceof WP_Post ) {
            $pt = $queried->post_type;
            if ( $pt === 'post' ) {
                // Posts page vs single post: when "Settings > Reading > Posts page"
                // is set, the queried object is a page but is_home() is true.
                if ( function_exists( 'is_home' ) && is_home() && ! is_front_page() ) {
                    return 'blog_listing';
                }
                return 'single_post';
            }
            if ( $pt === 'page' ) {
                return 'page';
            }
            return 'single_cpt';
        }

        if ( $queried instanceof WP_Term ) {
            return 'taxonomy_archive';
        }

        if ( $queried instanceof WP_User ) {
            return 'author_archive';
        }

        // No object set on main query — probably search/date/404/front-page-posts.
        if ( function_exists( 'is_front_page' ) && is_front_page() && ! is_page() ) {
            return 'front_page_posts';
        }
        if ( function_exists( 'is_home' ) && is_home() && ! is_front_page() ) {
            return 'blog_listing';
        }
        if ( ( function_exists( 'is_date' ) && is_date() )
             || ( function_exists( 'is_search' ) && is_search() ) ) {
            return 'date_or_search';
        }

        return 'unknown';
    }

    /**
     * Single regular post (post_type === 'post').
     */
    private function add_blog_post_graph( array &$graph, WP_Post $post ): void {
        $this->with_post_globals( $post, function () use ( &$graph, $post ) {
            $author_id = (int) $post->post_author;

            $graph[] = ( new Ligase_Type_BlogPosting() )->build();
            // Skip the Person node when this author is mapped to the Organization
            // (org_author_mode site-wide flag, or per-user ligase_is_redakcja meta).
            // BlogPosting.author already points at #org in that case.
            if ( ! $this->author_is_organization( $author_id ) ) {
                $graph[] = ( new Ligase_Type_Person( $author_id ) )->build();
            }
            $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();

            foreach ( $this->get_optional_types() as $type ) {
                $schema = $type->build();
                if ( ! empty( $schema ) ) {
                    $graph[] = $schema;
                }
            }
        } );
    }

    /**
     * True when the author should be represented as the site Organization
     * rather than a Person — controls Person-node suppression in the graph.
     */
    private function author_is_organization( int $author_id ): bool {
        $opts = (array) get_option( 'ligase_options', array() );
        if ( ! empty( $opts['org_author_mode'] ) ) {
            return true;
        }
        if ( $author_id > 0 && get_user_meta( $author_id, 'ligase_is_redakcja', true ) === '1' ) {
            return true;
        }
        return false;
    }

    /**
     * Single CPT (product, recipe, jobposting, custom CPT etc.).
     */
    private function add_cpt_single_graph( array &$graph, WP_Post $post ): void {
        $this->with_post_globals( $post, function () use ( &$graph, $post ) {
            $author_id = (int) $post->post_author;

            $graph[] = $this->build_webpage();
            if ( ! $this->author_is_organization( $author_id ) ) {
                $graph[] = ( new Ligase_Type_Person( $author_id ) )->build();
            }
            $graph[] = ( new Ligase_Type_BreadcrumbList() )->build();

            foreach ( $this->get_optional_types() as $type ) {
                $schema = $type->build();
                if ( ! empty( $schema ) ) {
                    $graph[] = $schema;
                }
            }
        } );
    }

    /**
     * Run a callback with $GLOBALS['post'] AND $wp_query->is_singular forced
     * for the queried post, then restored after. Type-class builders rely on
     * is_singular() / get_the_ID() / get_post_meta() — all of which fail when
     * a theme (XStore, Divi, Avada) or a related-products plugin called
     * query_posts() before wp_head priority 5.
     *
     * This is a localized override with try/finally so any throw still restores
     * the original state.
     */
    private function with_post_globals( WP_Post $post, callable $fn ): void {
        global $wp_query;

        // Edge case: AMP renderers, REST controllers, and a few page-builders call
        // do_action('wp_head') with $wp_query unset or set to a non-WP_Query object.
        // Without this guard the writes below fatal with "Attempt to assign property
        // on null". We still set $GLOBALS['post'] so the type classes work — they
        // mostly use get_the_*() which falls back to $post.
        $has_query = $wp_query instanceof WP_Query;

        $original_post     = $GLOBALS['post'] ?? null;
        $original_singular  = $has_query ? ( $wp_query->is_singular  ?? false ) : false;
        $original_is_single = $has_query ? ( $wp_query->is_single    ?? false ) : false;
        $original_is_page   = $has_query ? ( $wp_query->is_page      ?? false ) : false;
        $original_is_arch   = $has_query ? ( $wp_query->is_archive   ?? false ) : false;
        $original_is_tax    = $has_query ? ( $wp_query->is_tax       ?? false ) : false;
        $original_is_cat    = $has_query ? ( $wp_query->is_category  ?? false ) : false;
        $original_is_tag    = $has_query ? ( $wp_query->is_tag       ?? false ) : false;

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $GLOBALS['post'] = $post;
        setup_postdata( $post );

        // Force conditional tags to report the truth about the queried object.
        if ( $has_query ) {
            $wp_query->is_singular = true;
            $wp_query->is_single   = ( $post->post_type !== 'page' );
            $wp_query->is_page     = ( $post->post_type === 'page' );
            $wp_query->is_archive  = false;
            $wp_query->is_tax      = false;
            $wp_query->is_category = false;
            $wp_query->is_tag      = false;
        }

        try {
            $fn();
        } finally {
            if ( $has_query ) {
                $wp_query->is_singular = $original_singular;
                $wp_query->is_single   = $original_is_single;
                $wp_query->is_page     = $original_is_page;
                $wp_query->is_archive  = $original_is_arch;
                $wp_query->is_tax      = $original_is_tax;
                $wp_query->is_category = $original_is_cat;
                $wp_query->is_tag      = $original_is_tag;
            }

            if ( $original_post instanceof WP_Post ) {
                // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                $GLOBALS['post'] = $original_post;
                setup_postdata( $original_post );
            } else {
                wp_reset_postdata();
            }
        }
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
        $original_post   = $GLOBALS['post'] ?? null;
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
            'name'            => wp_strip_all_tags( get_the_title() ?: get_bloginfo( 'name' ) ),
            'url'             => esc_url( get_permalink() ?: home_url( '/' ) ),
            'inLanguage'      => str_replace( '_', '-', get_locale() ),
            'isPartOf'        => [ '@id' => home_url( '/#website' ) ],
            'publisher'       => [ '@id' => home_url( '/#org' ) ],
        ];

        $excerpt = $post_id ? wp_strip_all_tags( get_the_excerpt( $post_id ) ) : '';
        if ( $excerpt ) {
            $schema['description'] = wp_strip_all_tags( mb_substr( $excerpt, 0, 300 ) );
        }

        $modified = $post_id ? get_the_modified_date( 'c', $post_id ) : '';
        if ( $modified ) {
            $schema['dateModified'] = $modified;
        }

        return apply_filters( 'ligase_webpage', $schema, $post_id );
    }

    /**
     * Resolve which user (if any) the current page profiles. Used by /o-mnie/,
     * /zespol/lucyna/, /lucyna-w-mediach/ etc. Returns 0 when the page isn't a profile.
     */
    private function resolve_profile_user_id( $queried ): int {
        if ( ! ( $queried instanceof WP_Post ) ) {
            return 0;
        }
        $enabled = get_post_meta( $queried->ID, '_ligase_enable_profile_page', true ) === '1';
        if ( ! $enabled ) {
            return 0;
        }
        $explicit = (int) get_post_meta( $queried->ID, '_ligase_profile_user_id', true );
        if ( $explicit > 0 && get_userdata( $explicit ) ) {
            return $explicit;
        }
        // Fall back to the page's author when no explicit user is set.
        $author_id = (int) $queried->post_author;
        return $author_id > 0 && get_userdata( $author_id ) ? $author_id : 0;
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

        // Blog vs CollectionPage selection: blog archive (the WP "Posts page") is a
        // Blog node with mainEntity ItemList. Taxonomy archives, author archives, etc.
        // stay as CollectionPage. Google docs explicitly support Blog @type for the
        // top-level blog index page since 2023.
        $is_blog_index = function_exists( 'is_home' ) && is_home() && ! is_front_page();
        $page_type     = $is_blog_index ? 'Blog' : 'CollectionPage';

        $schema = [
            '@type'      => $page_type,
            '@id'        => esc_url( is_string( $url ) ? $url : home_url( '/' ) ),
            'name'       => wp_strip_all_tags( $name ),
            'url'        => esc_url( is_string( $url ) ? $url : home_url( '/' ) ),
            'inLanguage' => str_replace( '_', '-', get_locale() ),
            'isPartOf'   => [ '@id' => home_url( '/#website' ) ],
        ];

        if ( $desc ) {
            $schema['description'] = wp_strip_all_tags( $desc );
        }

        // For Blog index, link to ItemList of recent posts (Google uses mainEntity to
        // understand the page is the entry point for the blog archive).
        if ( $is_blog_index ) {
            $schema['mainEntity'] = [ '@id' => esc_url( is_string( $url ) ? $url : home_url( '/' ) ) . '#itemlist' ];
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
        $name        = $user ? wp_strip_all_tags( $user->display_name ) : '';
        $description = $user ? wp_strip_all_tags( $user->description ) : '';

        $schema = [
            '@type'       => 'ProfilePage',
            '@id'         => $author_url . '#profilepage',
            'url'         => $author_url,
            'name'        => $name . ' — ' . wp_strip_all_tags( get_bloginfo( 'name' ) ),
            'inLanguage'  => str_replace( '_', '-', get_locale() ),
            'isPartOf'    => [ '@id' => home_url( '/#website' ) ],
            'about'       => [ '@id' => Ligase_Type_BlogPosting::author_ref_id( (int) $author_id ) ],
            'mainEntity'  => [ '@id' => Ligase_Type_BlogPosting::author_ref_id( (int) $author_id ) ],
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
            new Ligase_Type_Product(),
            new Ligase_Type_Recipe(),
            new Ligase_Type_JobPosting(),
            new Ligase_Type_DiscussionForumPosting(),
            new Ligase_Type_PodcastSeries(),
        ];
    }


}
