<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_BlogPosting {

    /**
     * Resolve which @id should appear in BlogPosting.author. Two override paths:
     *
     *   1. Site-wide `org_author_mode` setting → every post's author becomes
     *      the OnlineStore/Organization node (`#org`). For redakcyjne sites
     *      where individual WP accounts are workflow users, not real bylines.
     *
     *   2. Per-user `ligase_is_redakcja` meta → only this specific user is
     *      treated as Organization. For mixed sites where most authors are
     *      real people but one or two accounts represent a team byline like
     *      "Redakcja MAKUMI" or "Sales Team".
     *
     * Both paths route the author to `#org` so the graph stays consistent:
     * author = publisher = Organization. The corresponding Person node is
     * suppressed in Ligase_Generator::add_blog_post_graph() (same flag check).
     */
    public static function author_ref_id( int $author_id ): string {
        $opts = (array) get_option( 'ligase_options', array() );
        if ( ! empty( $opts['org_author_mode'] ) ) {
            return home_url( '/#org' );
        }
        // Orphaned posts (author deleted) or invalid author_id → fallback to Organization
        // rather than emitting a dangling `#author-0` reference that points to nothing.
        if ( $author_id <= 0 || ! get_userdata( $author_id ) ) {
            return home_url( '/#org' );
        }
        if ( get_user_meta( $author_id, 'ligase_is_redakcja', true ) === '1' ) {
            return home_url( '/#org' );
        }
        return home_url( '/#author-' . $author_id );
    }


    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id   = get_the_ID();
        $author_id = (int) get_post_field( 'post_author', $post_id );

        $opts           = (array) get_option( 'ligase_options', array() );
        $global_default = $opts['default_schema_type'] ?? 'BlogPosting';
        $type           = $this->resolve_article_type( $post_id, $global_default );

        // Headline ≤ 110 chars. Use ellipsis indicator (single char) rather than a
        // hard cut, so users skimming SERP know the title was truncated.
        $raw_headline = wp_strip_all_tags( get_the_title( $post_id ) );
        $headline     = mb_strlen( $raw_headline ) > 110
            ? mb_substr( $raw_headline, 0, 109 ) . '…'
            : $raw_headline;

        $schema = [
            '@type'              => $type,
            '@id'                => esc_url( get_permalink() ) . '#posting',
            'mainEntityOfPage'   => [
                '@type' => 'WebPage',
                '@id'   => esc_url( get_permalink() ),
            ],
            'headline'           => $headline,
            'datePublished'      => get_the_date( 'c' ),
            'inLanguage'         => str_replace( '_', '-', get_locale() ),
            'author'             => [ [ '@id' => Ligase_Type_BlogPosting::author_ref_id( $author_id ) ] ],
            'publisher'          => [ '@id' => home_url( '/#org' ) ],
            'isPartOf'           => [ '@id' => home_url( '/#website' ) ],
        ];

        // dateModified discipline: only emit when modification is meaningful (>=5 min
        // after publish OR explicitly forced via post meta). Prevents Google from
        // seeing inflated "freshness" from trivial metadata-only saves and reduces
        // the risk of a manual action for misleading recency signals.
        $pub_ts = (int) get_post_time( 'U', true, $post_id );
        $mod_ts = (int) get_post_modified_time( 'U', true, $post_id );
        $force_modified = get_post_meta( $post_id, '_ligase_force_date_modified', true ) === '1';
        if ( $force_modified || ( $mod_ts - $pub_ts ) >= 5 * MINUTE_IN_SECONDS ) {
            $schema['dateModified'] = get_the_modified_date( 'c', $post_id );
        }

        // Paywall / subscription content (Google's anti-cloaking spec).
        // Stored in a local variable; merged with optional series hasPart later so we
        // emit hasPart as an array when both apply.
        $is_paywalled  = get_post_meta( $post_id, '_ligase_paywalled', true ) === '1';
        $paywall_parts = [];
        if ( $is_paywalled ) {
            $schema['isAccessibleForFree'] = false;
            $selector = get_post_meta( $post_id, '_ligase_paywall_selector', true );
            $paywall_parts[] = [
                '@type'               => 'WebPageElement',
                'isAccessibleForFree' => false,
                'cssSelector'         => $selector ?: '.paywall',
            ];
        } else {
            $schema['isAccessibleForFree'] = true;
        }

        $excerpt = wp_strip_all_tags( get_the_excerpt() );
        if ( $excerpt ) {
            $schema['description'] = mb_substr( $excerpt, 0, 300 );
        }

        // Google recommends multiple image ratios: 16:9, 4:3, 1:1
        $images = $this->build_images( $post_id );
        if ( ! empty( $images ) ) {
            $schema['image'] = $images;
        }

        $tags = wp_get_post_tags( $post_id, [ 'fields' => 'names' ] );
        if ( ! empty( $tags ) && is_array( $tags ) ) {
            // Strip tags + control chars per tag name. wp_strip_all_tags is the right
            // choice here (not esc_html) because the values flow into wp_json_encode,
            // which itself escapes JSON-unsafe characters — HTML-entity encoding would
            // double-encode (e.g. "M&M" → "M&amp;M") in the rendered JSON-LD.
            $schema['keywords'] = array_map( 'wp_strip_all_tags', $tags );
        }

        $cats = get_the_category( $post_id );
        if ( ! empty( $cats ) && is_array( $cats ) ) {
            $schema['articleSection'] = wp_strip_all_tags( $cats[0]->name );
        }

        $content = get_the_content();
        if ( $content ) {
            // str_word_count is byte-level and undercounts Polish/UTF-8 words with diacritics.
            // Use Unicode-aware regex matching any letter/digit run.
            $wc = preg_match_all( '/[\p{L}\p{N}_]+/u', wp_strip_all_tags( $content ) );
            if ( $wc > 0 ) {
                $schema['wordCount'] = $wc;
            }
        }

        $cc = (int) get_comments_number( $post_id );
        if ( $cc > 0 ) {
            $schema['commentCount'] = $cc;
        }

        // accessMode
        $schema['accessMode'] = ! empty( $images ) ? [ 'textual', 'visual' ] : [ 'textual' ];

        // AI search signals
        $schema['potentialAction'] = [
            '@type'   => 'ReadAction',
            'target'  => esc_url( get_permalink() ),
        ];

        // Speakable — AI synthesis + voice
        $opts = get_option( 'ligase_options', [] );
        $speakable_css = $opts['speakable_selectors'] ?? '';
        $selectors = array_filter( array_map( 'trim', explode( ',', $speakable_css ) ) );
        if ( ! empty( $selectors ) ) {
            $schema['speakable'] = [
                '@type'       => 'SpeakableSpecification',
                'cssSelector' => $selectors,
            ];
        }

        // about — from entity pipeline hints (Wikidata-linked topics)
        $about_hints = get_post_meta( $post_id, '_ligase_about_entities', true );
        if ( ! empty( $about_hints ) && is_array( $about_hints ) ) {
            $schema['about'] = array_values( array_map( fn( $e ) => [
                '@type'  => 'Thing',
                'name'   => wp_strip_all_tags( $e['name'] ?? '' ),
                'sameAs' => esc_url( $e['sameAs'] ?? '' ),
            ], array_slice( $about_hints, 0, 5 ) ) );
        }

        // mentions — named entities detected in content
        $mentions = get_post_meta( $post_id, '_ligase_mentions', true );
        if ( ! empty( $mentions ) && is_array( $mentions ) ) {
            $schema['mentions'] = array_values( array_map( fn( $m ) => [
                '@type' => 'Thing',
                'name'  => wp_strip_all_tags( $m['name'] ?? '' ),
            ], array_slice( $mentions, 0, 10 ) ) );
        }

        // temporalCoverage — for news/history articles
        $temporal = get_post_meta( $post_id, '_ligase_temporal_coverage', true );
        if ( $temporal ) {
            $schema['temporalCoverage'] = wp_strip_all_tags( $temporal );
        }

        // isBasedOn — cited sources
        $sources = get_post_meta( $post_id, '_ligase_sources', true );
        if ( ! empty( $sources ) && is_array( $sources ) ) {
            $schema['isBasedOn'] = array_values( array_map( fn( $s ) => [
                '@type' => 'Article',
                'name'  => wp_strip_all_tags( $s['name'] ?? '' ),
                'url'   => esc_url( $s['url'] ?? '' ),
            ], array_filter( $sources, fn( $s ) => ! empty( $s['url'] ) ) ) );
        }

        // hasPart — article series, merged with optional paywall WebPageElement above.
        $series_parts = get_post_meta( $post_id, '_ligase_series_parts', true );
        $series_nodes = [];
        if ( ! empty( $series_parts ) && is_array( $series_parts ) ) {
            $series_nodes = array_values( array_map( fn( $part_id ) => [
                '@type'    => 'BlogPosting',
                'headline' => wp_strip_all_tags( get_the_title( (int) $part_id ) ),
                'url'      => esc_url( get_permalink( (int) $part_id ) ),
            ], $series_parts ) );
        }
        $all_parts = array_values( array_merge( $paywall_parts, $series_nodes ) );
        if ( ! empty( $all_parts ) ) {
            $schema['hasPart'] = count( $all_parts ) === 1 ? $all_parts[0] : $all_parts;
        }

        // NewsArticle-specific: citation (sources) + dateline. Major AI-citation
        // signal — Google and LLMs heavily favour news with explicit source attribution.
        if ( $type === 'NewsArticle' ) {
            $dateline = get_post_meta( $post_id, '_ligase_dateline', true );
            if ( $dateline ) {
                $schema['dateline'] = wp_strip_all_tags( $dateline );
            }
            $citations = get_post_meta( $post_id, '_ligase_citations', true );
            if ( ! empty( $citations ) && is_array( $citations ) ) {
                $schema['citation'] = array_values( array_map(
                    fn( $c ) => [
                        '@type' => 'CreativeWork',
                        'name'  => wp_strip_all_tags( $c['name'] ?? '' ),
                        'url'   => esc_url( $c['url'] ?? '' ),
                    ],
                    array_filter(
                        $citations,
                        fn( $c ) => is_array( $c ) && ! empty( $c['url'] )
                    )
                ) );
            }
        }

        return apply_filters( 'ligase_blogposting', $schema, $post_id );
    }

    /**
     * Resolve which Article variant to emit for a post.
     *
     * Resolution order:
     *   1. Explicit per-post override via `_ligase_schema_type` meta.
     *   2. Category-to-type mapping from settings (`category_article_type_map`).
     *      Stored as `[ category_slug_or_id => 'NewsArticle' ]`. Matched against
     *      every category attached to the post; first matching wins (sorted by
     *      category ID for determinism).
     *   3. Custom-post-type-name match (e.g. CPT 'news' → NewsArticle).
     *   4. Plugin default (typically 'BlogPosting').
     */
    private function resolve_article_type( int $post_id, string $global_default ): string {
        $allowed_types = [ 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'LiveBlogPosting' ];

        // 1. Explicit override
        $explicit = get_post_meta( $post_id, '_ligase_schema_type', true );
        if ( $explicit && in_array( $explicit, $allowed_types, true ) ) {
            return $explicit;
        }

        // 2. Category mapping from settings
        $opts        = (array) get_option( 'ligase_options', [] );
        $category_map = $opts['category_article_type_map'] ?? [];
        if ( is_array( $category_map ) && ! empty( $category_map ) ) {
            $cats = get_the_category( $post_id );
            if ( ! empty( $cats ) && is_array( $cats ) ) {
                usort( $cats, fn( $a, $b ) => $a->term_id <=> $b->term_id );
                foreach ( $cats as $cat ) {
                    $candidate = $category_map[ $cat->slug ]
                              ?? $category_map[ (string) $cat->term_id ]
                              ?? null;
                    if ( $candidate && in_array( $candidate, $allowed_types, true ) ) {
                        return $candidate;
                    }
                }
            }
        }

        // 3. CPT name match — a `news` post type implies NewsArticle.
        $post_type = get_post_type( $post_id );
        if ( $post_type === 'news' ) {
            return 'NewsArticle';
        }

        // 4. Plugin default, with allowed-list filter
        return in_array( $global_default, $allowed_types, true ) ? $global_default : 'BlogPosting';
    }

    /**
     * Build image array with multiple aspect ratios for Google Article rich results.
     *
     * Emits up to 3 ImageObject variants resolved to actual cropped files generated
     * via the `ligase_1x1` / `ligase_4x3` / `ligase_16x9` image sizes registered in
     * Ligase_Plugin::register_image_sizes(). Falls back to the original if WP
     * couldn't generate a crop (small upload). Adds optional license / credit /
     * acquireLicensePage from post meta — produces the "Licensable" badge in Google
     * Images for publishers/photo blogs.
     */
    private function build_images( int $post_id ): array {
        $tid = get_post_thumbnail_id( $post_id );
        if ( ! $tid ) {
            return [];
        }

        $full = wp_get_attachment_image_src( $tid, 'full' );
        if ( ! $full || ! is_array( $full ) ) {
            return [];
        }

        $orig_width  = (int) ( $full[1] ?? 0 );
        $orig_height = (int) ( $full[2] ?? 0 );

        // Google's minimum for rich results is 696px; recommendation for Top Stories /
        // Discover is 1200px. We require 1200 so the variants below can be true crops.
        if ( $orig_width < 1200 || $orig_height < 675 ) {
            if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::warning( 'Featured image too small for Article ratios — needs ≥ 1200×675', [
                    'post_id' => $post_id,
                    'width'   => $orig_width,
                    'height'  => $orig_height,
                ] );
            }
            return [];
        }

        // Optional license / credit metadata (per-post overrides global defaults).
        $opts        = (array) get_option( 'ligase_options', [] );
        $credit      = wp_strip_all_tags( (string) ( get_post_meta( $post_id, '_ligase_image_credit', true )
                          ?: ( $opts['image_default_credit'] ?? '' ) ) );
        $license_url = esc_url_raw( (string) ( get_post_meta( $post_id, '_ligase_image_license', true )
                          ?: ( $opts['image_default_license'] ?? '' ) ) );
        $acquire_url = esc_url_raw( (string) ( get_post_meta( $post_id, '_ligase_image_acquire', true )
                          ?: ( $opts['image_default_acquire'] ?? '' ) ) );

        $variants = [
            'ligase_16x9' => [ 1200, 675,  '#primaryimage' ],
            'ligase_4x3'  => [ 1200, 900,  null ],
            'ligase_1x1'  => [ 1200, 1200, null ],
        ];

        $images   = [];
        $seen_url = [];
        foreach ( $variants as $size => [ $w, $h, $id_suffix ] ) {
            $src = wp_get_attachment_image_src( $tid, $size );
            if ( $src && ! empty( $src[0] ) && ! isset( $seen_url[ $src[0] ] ) ) {
                $node = [
                    '@type'      => 'ImageObject',
                    'url'        => esc_url( $src[0] ),
                    'contentUrl' => esc_url( $src[0] ),
                    'width'      => (int) $src[1],
                    'height'     => (int) $src[2],
                ];
                if ( $id_suffix ) {
                    $node['@id'] = esc_url( get_permalink() ) . $id_suffix;
                }
                if ( $credit )      { $node['creditText']         = $credit; }
                if ( $license_url ) { $node['license']            = $license_url; }
                if ( $acquire_url ) { $node['acquireLicensePage'] = $acquire_url; }
                $images[]              = $node;
                $seen_url[ $src[0] ]   = true;
            }
        }

        // If WP didn't have the crops (old upload, etc.) fall back to original full image.
        if ( empty( $images ) ) {
            $node = [
                '@type'      => 'ImageObject',
                '@id'        => esc_url( get_permalink() ) . '#primaryimage',
                'url'        => esc_url( $full[0] ),
                'contentUrl' => esc_url( $full[0] ),
                'width'      => $orig_width,
                'height'     => $orig_height,
            ];
            if ( $credit )      { $node['creditText']         = $credit; }
            if ( $license_url ) { $node['license']            = $license_url; }
            if ( $acquire_url ) { $node['acquireLicensePage'] = $acquire_url; }
            $images[] = $node;
        }

        return $images;
    }
}
