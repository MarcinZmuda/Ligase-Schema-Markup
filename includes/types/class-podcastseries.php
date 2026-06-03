<?php
/**
 * Ligase - PodcastSeries schema type
 *
 * Emits PodcastSeries for a page/post that hosts a podcast hub (e.g.
 * /update-time-by-marcin-zmuda/). Designed for the "podcast landing page"
 * shape — one PodcastSeries per WordPress page, not one PodcastEpisode per
 * post. Per-episode markup belongs in a dedicated AudioObject or future
 * PodcastEpisode type if the user needs it.
 *
 * Author is the site's primary Person (the page's WP author), publisher
 * defaults to the site Organization. sameAs collects external podcast
 * platforms (Spotify, Apple Podcasts, YouTube, Pocket Casts, etc.)
 * one URL per line.
 *
 * @package Ligase
 * @since   2.4.19
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_PodcastSeries {

    public function build(): ?array {
        // PodcastSeries lives on a single page or post — not archives.
        if ( ! is_singular() ) {
            return null;
        }

        $post_id = get_the_ID();
        if ( ! $post_id ) {
            return null;
        }

        if ( get_post_meta( $post_id, '_ligase_enable_podcast_series', true ) !== '1'
            && ! ( class_exists( 'Ligase_Schema_Rules' )
                   && Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_podcast_series', $post_id ) ) ) {
            return null;
        }

        $data = (array) ( get_post_meta( $post_id, '_ligase_podcast', true ) ?: array() );

        $name = wp_strip_all_tags( (string) ( $data['name'] ?? get_the_title( $post_id ) ) );
        if ( $name === '' ) {
            return null;
        }

        $description = wp_strip_all_tags( (string) ( $data['description'] ?? get_the_excerpt( $post_id ) ?: '' ) );
        $permalink   = get_permalink( $post_id );

        $schema = array(
            '@type' => 'PodcastSeries',
            '@id'   => esc_url( (string) $permalink ) . '#podcast',
            'name'  => $name,
            'url'   => esc_url( (string) $permalink ),
        );

        if ( $description !== '' ) {
            $schema['description'] = mb_substr( $description, 0, 500 );
        }

        // Author — the WP page author (the host). Routes through the centralized
        // author_ref_id so org_author_mode + ligase_is_redakcja decisions apply.
        $author_id = (int) get_post_field( 'post_author', $post_id );
        if ( $author_id > 0 && class_exists( 'Ligase_Type_BlogPosting' ) ) {
            $schema['author'] = array( '@id' => Ligase_Type_BlogPosting::author_ref_id( $author_id ) );
        }

        // Publisher — site Organization
        $schema['publisher'] = array( '@id' => home_url( '/#org' ) );

        // Language code — accepts ISO 639-1 ('pl') or BCP-47 ('pl-PL'). Defaults
        // to site locale converted to BCP-47 (e.g. pl_PL → pl-PL).
        $lang = trim( (string) ( $data['language'] ?? '' ) );
        if ( $lang === '' ) {
            $lang = str_replace( '_', '-', get_locale() );
        }
        if ( $lang !== '' ) {
            $schema['inLanguage'] = $lang;
        }

        // Featured image — falls back to post thumbnail. PodcastSeries image
        // recommendations: square, ≥ 1400×1400 px (Apple Podcasts min).
        $image = '';
        if ( ! empty( $data['image'] ) ) {
            $image = esc_url_raw( (string) $data['image'] );
        } elseif ( has_post_thumbnail( $post_id ) ) {
            $tid = get_post_thumbnail_id( $post_id );
            $src = $tid ? wp_get_attachment_image_src( $tid, 'full' ) : false;
            if ( $src && ! empty( $src[0] ) ) {
                $image = (string) $src[0];
            }
        }
        if ( $image !== '' ) {
            $schema['image'] = esc_url( $image );
        }

        // sameAs — external platforms one URL per line. Filtered through
        // esc_url_raw so javascript:/data: schemes are dropped.
        $same_as_raw = (string) ( $data['same_as'] ?? '' );
        if ( $same_as_raw !== '' ) {
            $urls = array();
            foreach ( preg_split( '/\r\n|\r|\n/', $same_as_raw ) ?: array() as $line ) {
                $line = trim( $line );
                if ( $line === '' ) {
                    continue;
                }
                $u = esc_url_raw( $line );
                if ( $u !== '' ) {
                    $urls[] = $u;
                }
            }
            if ( ! empty( $urls ) ) {
                $schema['sameAs'] = $urls;
            }
        }

        // webFeed — RSS / Atom feed URL (Apple Podcasts directory ingestion).
        if ( ! empty( $data['feed_url'] ) ) {
            $feed = esc_url_raw( (string) $data['feed_url'] );
            if ( $feed !== '' ) {
                $schema['webFeed'] = $feed;
            }
        }

        // numberOfEpisodes — optional integer hint.
        if ( isset( $data['number_of_episodes'] ) && $data['number_of_episodes'] !== '' ) {
            $n = (int) $data['number_of_episodes'];
            if ( $n > 0 ) {
                $schema['numberOfEpisodes'] = $n;
            }
        }

        return apply_filters( 'ligase_podcastseries', $schema, $post_id );
    }
}
