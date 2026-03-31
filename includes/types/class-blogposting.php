<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_BlogPosting {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id   = get_the_ID();
        $author_id = (int) get_post_field( 'post_author', $post_id );

        $type = get_post_meta( $post_id, '_ligase_schema_type', true ) ?: 'BlogPosting';
        $allowed_types = [ 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'LiveBlogPosting' ];
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'BlogPosting';
        }

        $schema = [
            '@type'              => $type,
            '@id'                => esc_url( get_permalink() ) . '#posting',
            'mainEntityOfPage'   => [
                '@type' => 'WebPage',
                '@id'   => esc_url( get_permalink() ),
            ],
            'headline'           => esc_html( mb_substr( get_the_title(), 0, 110 ) ),
            'datePublished'      => get_the_date( 'c' ),
            'dateModified'       => get_the_modified_date( 'c' ),
            'inLanguage'         => str_replace( '_', '-', get_locale() ),
            'isAccessibleForFree'=> true,
            'author'             => [ [ '@id' => home_url( '/#author-' . $author_id ) ] ],
            'publisher'          => [ '@id' => home_url( '/#org' ) ],
            'isPartOf'           => [ '@id' => home_url( '/#website' ) ],
        ];

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
            $schema['keywords'] = $tags;
        }

        $cats = get_the_category( $post_id );
        if ( ! empty( $cats ) && is_array( $cats ) ) {
            $schema['articleSection'] = esc_html( $cats[0]->name );
        }

        $content = get_the_content();
        if ( $content ) {
            $wc = str_word_count( wp_strip_all_tags( $content ) );
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
            $schema['about'] = array_map( fn( $e ) => [
                '@type'  => 'Thing',
                'name'   => esc_html( $e['name'] ?? '' ),
                'sameAs' => esc_url( $e['sameAs'] ?? '' ),
            ], array_slice( $about_hints, 0, 5 ) );
        }

        // mentions — named entities detected in content
        $mentions = get_post_meta( $post_id, '_ligase_mentions', true );
        if ( ! empty( $mentions ) && is_array( $mentions ) ) {
            $schema['mentions'] = array_map( fn( $m ) => [
                '@type' => 'Thing',
                'name'  => esc_html( $m['name'] ?? '' ),
            ], array_slice( $mentions, 0, 10 ) );
        }

        // temporalCoverage — for news/history articles
        $temporal = get_post_meta( $post_id, '_ligase_temporal_coverage', true );
        if ( $temporal ) {
            $schema['temporalCoverage'] = esc_html( $temporal );
        }

        // isBasedOn — cited sources
        $sources = get_post_meta( $post_id, '_ligase_sources', true );
        if ( ! empty( $sources ) && is_array( $sources ) ) {
            $schema['isBasedOn'] = array_map( fn( $s ) => [
                '@type' => 'Article',
                'name'  => esc_html( $s['name'] ?? '' ),
                'url'   => esc_url( $s['url'] ?? '' ),
            ], array_filter( $sources, fn( $s ) => ! empty( $s['url'] ) ) );
        }

        // hasPart — article series
        $series_parts = get_post_meta( $post_id, '_ligase_series_parts', true );
        if ( ! empty( $series_parts ) && is_array( $series_parts ) ) {
            $schema['hasPart'] = array_map( fn( $part_id ) => [
                '@type'    => 'BlogPosting',
                'headline' => esc_html( get_the_title( (int) $part_id ) ),
                'url'      => esc_url( get_permalink( (int) $part_id ) ),
            ], $series_parts );
        }

        return apply_filters( 'ligase_blogposting', $schema, $post_id );
    }

    /**
     * Build image array with multiple aspect ratios for Google.
     * Returns up to 3 variants: original (16:9), 4:3, 1:1.
     */
    private function build_images( int $post_id ): array {
        $tid = get_post_thumbnail_id( $post_id );
        if ( ! $tid ) {
            return [];
        }

        $img = wp_get_attachment_image_src( $tid, 'full' );
        if ( ! $img || ! is_array( $img ) ) {
            return [];
        }

        $width  = (int) $img[1];
        $height = (int) $img[2];

        // Google minimum for rich results: 696px
        // Google recommendation for Top Stories / Discover: 1200px
        // We require 1200px to match the score.php threshold and optimise for Discover.
        if ( $width < 1200 ) {
            if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::warning( 'Post image below recommended 1200px — schema image omitted', [
                    'post_id' => $post_id,
                    'width'   => $width,
                ] );
            }
            return [];
        }

        $url    = esc_url( $img[0] );
        $images = [];

        // Primary image — original dimensions (likely 16:9 or wider)
        $images[] = [
            '@type'  => 'ImageObject',
            '@id'    => esc_url( get_permalink() ) . '#primaryimage',
            'url'    => $url,
            'width'  => $width,
            'height' => $height,
        ];

        // 4:3 variant — Google recommends this ratio for article rich results
        if ( $width >= 1200 && $height >= 900 ) {
            $images[] = [
                '@type'  => 'ImageObject',
                'url'    => $url,
                'width'  => 1200,
                'height' => 900,
            ];
        }

        // 1:1 variant — used in Google Discover and some Knowledge Panel views
        $square = min( $width, $height );
        if ( $square >= 1200 ) {
            $images[] = [
                '@type'  => 'ImageObject',
                'url'    => $url,
                'width'  => $square,
                'height' => $square,
            ];
        }

        return $images;
    }
}
