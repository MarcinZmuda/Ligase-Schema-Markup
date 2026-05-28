<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_AudioObject {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        // Manual meta first
        $meta = get_post_meta( $post_id, '_ligase_audio', true );
        if ( ! empty( $meta ) && is_array( $meta ) && ! empty( $meta['content_url'] ) ) {
            return $this->build_from_meta( $meta, $post_id );
        }

        // Auto-detect Spotify/Buzzsprout/Anchor embeds
        $content  = get_the_content() ?: '';
        $detected = $this->detect_audio_embed( $content );
        if ( $detected ) {
            return $this->build_from_embed( $detected, $post_id );
        }

        return null;
    }

    private function detect_audio_embed( string $content ): ?array {
        // Spotify
        if ( preg_match( '~open\.spotify\.com/episode/([\w]+)~', $content, $m ) ) {
            return [
                'type'      => 'spotify',
                'id'        => $m[1],
                'embed_url' => 'https://open.spotify.com/embed/episode/' . $m[1],
                'url'       => 'https://open.spotify.com/episode/' . $m[1],
            ];
        }

        // Buzzsprout
        if ( preg_match( '~buzzsprout\.com/(\d+)/(\d+)~', $content, $m ) ) {
            return [
                'type'      => 'buzzsprout',
                'id'        => $m[2],
                'embed_url' => "https://www.buzzsprout.com/{$m[1]}/{$m[2]}?client_source=small_player",
                'url'       => "https://www.buzzsprout.com/{$m[1]}/{$m[2]}",
            ];
        }

        // Anchor.fm / Spotify for Podcasters
        if ( preg_match( '~anchor\.fm/[\w-]+/episodes/([\w-]+)~', $content, $m ) ) {
            return [
                'type'      => 'anchor',
                'id'        => $m[1],
                'embed_url' => 'https://' . $m[0],
                'url'       => 'https://' . $m[0],
            ];
        }

        return null;
    }

    private function build_from_embed( array $embed, int $post_id ): array {
        $schema = [
            '@type'       => 'AudioObject',
            '@id'         => esc_url( get_permalink( $post_id ) ) . '#audio',
            'name'        => wp_strip_all_tags( get_the_title( $post_id ) ),
            'description' => wp_strip_all_tags( wp_strip_all_tags( get_the_excerpt( $post_id ) ) ),
            'inLanguage'  => str_replace( '_', '-', get_locale() ),
            'uploadDate'  => get_the_date( 'c', $post_id ),
            'contentUrl'  => esc_url( $embed['url'] ),
            'embedUrl'    => esc_url( $embed['embed_url'] ),
        ];

        $duration = get_post_meta( $post_id, '_ligase_audio_duration', true );
        if ( $duration && preg_match( '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/', $duration ) ) {
            $schema['duration'] = $duration;
        }

        return $schema;
    }

    private function build_from_meta( array $meta, int $post_id ): array {
        $schema = [
            '@type'       => 'AudioObject',
            '@id'         => esc_url( get_permalink( $post_id ) ) . '#audio',
            'name'        => wp_strip_all_tags( $meta['name'] ?? get_the_title( $post_id ) ),
            'inLanguage'  => str_replace( '_', '-', get_locale() ),
            'uploadDate'  => wp_strip_all_tags( $meta['upload_date'] ?? get_the_date( 'c', $post_id ) ),
            'contentUrl'  => esc_url( $meta['content_url'] ),
        ];

        if ( ! empty( $meta['embed_url'] ) ) {
            $schema['embedUrl'] = esc_url( $meta['embed_url'] );
        }

        if ( ! empty( $meta['description'] ) ) {
            $schema['description'] = wp_strip_all_tags( $meta['description'] );
        }

        if ( ! empty( $meta['duration'] ) && preg_match( '/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])*)?$/', $meta['duration'] ) ) {
            $schema['duration'] = $meta['duration'];
        }

        return $schema;
    }
}
