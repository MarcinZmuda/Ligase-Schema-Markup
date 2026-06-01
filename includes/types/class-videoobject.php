<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_VideoObject {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        $meta = get_post_meta( $post_id, '_ligase_video', true );
        if ( ! empty( $meta ) && is_array( $meta ) && ! empty( $meta['embed_url'] ) ) {
            return $this->build_from_meta( $meta, $post_id );
        }

        $content    = get_the_content();
        $youtube_id = $this->extract_youtube_id( $content ?: '' );
        if ( $youtube_id ) {
            return $this->build_youtube( $youtube_id, $post_id );
        }

        return null;
    }

    private function extract_youtube_id( string $content ): ?string {
        if ( preg_match(
            '~(?:youtube\.com/(?:watch\?v=|embed/)|youtu\.be/)([\w-]{11})~',
            $content,
            $m
        ) ) {
            return preg_match( '/^[\w-]{11}$/', $m[1] ) ? $m[1] : null;
        }
        return null;
    }

    private function build_youtube( string $vid, int $post_id ): array {
        // hqdefault.jpg is guaranteed to exist for every YouTube video; maxresdefault.jpg
        // is 404 for ~30% of videos (only generated when source >= 1280x720). Use both as
        // an array so consumers can fall back, with hqdefault listed first as the safe default.
        $thumbnails = [
            "https://img.youtube.com/vi/{$vid}/hqdefault.jpg",
            "https://img.youtube.com/vi/{$vid}/maxresdefault.jpg",
        ];

        $schema = [
            '@type'        => 'VideoObject',
            '@id'          => esc_url( get_permalink( $post_id ) ) . '#video',
            'name'         => wp_strip_all_tags( get_the_title( $post_id ) ),
            'description'  => wp_strip_all_tags( wp_strip_all_tags( get_the_excerpt( $post_id ) ) ),
            'inLanguage'   => str_replace( '_', '-', get_locale() ),
            'thumbnailUrl' => $thumbnails,
            'uploadDate'   => get_the_date( 'c', $post_id ),
            'embedUrl'     => "https://www.youtube.com/embed/{$vid}",
            'contentUrl'   => "https://www.youtube.com/watch?v={$vid}",
        ];

        // Try to get duration from post meta (set by entity pipeline or manually)
        $duration = get_post_meta( $post_id, '_ligase_video_duration', true );
        if ( $duration && preg_match( '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/', $duration ) ) {
            $schema['duration'] = $duration;
        }

        return $schema;
    }

    private function build_from_meta( array $meta, int $post_id ): array {
        // Name + thumbnailUrl + uploadDate are required for VideoObject rich result.
        // Previously this method emitted empty strings when meta was missing — Google
        // marks the schema invalid AND it's an SEO-spam signal ("we said we have a video
        // but we don't"). Now: only emit fields that have real values; if essentials
        // missing → return null and let Generator drop the node.
        $name      = wp_strip_all_tags( (string) ( $meta['name'] ?? get_the_title( $post_id ) ) );
        $thumbnail = (string) ( $meta['thumbnail'] ?? '' );
        $embed     = (string) ( $meta['embed_url'] ?? '' );
        $content   = (string) ( $meta['content_url'] ?? '' );

        if ( $name === '' || $thumbnail === '' || ( $embed === '' && $content === '' ) ) {
            return array();
        }

        $schema = array(
            '@type'        => 'VideoObject',
            '@id'          => esc_url( get_permalink( $post_id ) ) . '#video',
            'name'         => $name,
            'thumbnailUrl' => esc_url( $thumbnail ),
            'uploadDate'   => wp_strip_all_tags( (string) ( $meta['upload_date'] ?? get_the_date( 'c', $post_id ) ) ),
        );
        if ( $embed !== '' )   { $schema['embedUrl']   = esc_url( $embed ); }
        if ( $content !== '' ) { $schema['contentUrl'] = esc_url( $content ); }
        if ( ! empty( $meta['description'] ) ) {
            $schema['description'] = wp_strip_all_tags( (string) $meta['description'] );
        }
        if ( ! empty( $meta['duration'] ) && preg_match( '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/', (string) $meta['duration'] ) ) {
            $schema['duration'] = wp_strip_all_tags( (string) $meta['duration'] );
        }
        return $schema;
    }
}
