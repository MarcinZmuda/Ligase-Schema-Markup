<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Person {
    private int $user_id;

    public function __construct( int $user_id ) {
        $this->user_id = $user_id;
    }

    public function build(): ?array {
        $user = get_userdata( $this->user_id );
        if ( ! $user ) {
            return null;
        }

        $schema = [
            '@type' => 'Person',
            '@id'   => home_url( '/#author-' . $this->user_id ),
            'name'  => wp_strip_all_tags( $user->display_name ),
            'url'   => esc_url( get_author_posts_url( $this->user_id ) ),
        ];

        if ( $user->description ) {
            $schema['description'] = wp_strip_all_tags( $user->description );
        }

        $job = get_user_meta( $this->user_id, 'ligase_job_title', true );
        if ( $job ) {
            $schema['jobTitle'] = wp_strip_all_tags( $job );
        }

        $knows = get_user_meta( $this->user_id, 'ligase_knows_about', true );
        if ( $knows ) {
            $schema['knowsAbout'] = array_values( array_filter( array_map(
                fn( $t ) => wp_strip_all_tags( trim( $t ) ),
                explode( ',', $knows )
            ) ) );
        }

        $raw_urls = [
            get_user_meta( $this->user_id, 'ligase_wikidata',  true ) ?: '',
            get_user_meta( $this->user_id, 'ligase_linkedin',  true ) ?: '',
            get_user_meta( $this->user_id, 'ligase_twitter',   true ) ?: '',
        ];
        $same_as = [];
        foreach ( $raw_urls as $url ) {
            if ( empty( $url ) ) {
                continue;
            }
            $url = esc_url( $url );
            $parsed = wp_parse_url( $url );
            if ( ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) && in_array( $parsed['scheme'], [ 'http', 'https' ], true ) ) {
                $same_as[] = $url;
            }
        }
        if ( ! empty( $same_as ) ) {
            $schema['sameAs'] = $same_as;
        }

        $avatar = get_avatar_url( $this->user_id, [ 'size' => 400 ] );
        if ( $avatar ) {
            $schema['image'] = [ '@type' => 'ImageObject', 'url' => esc_url( $avatar ) ];
        }

        $schema['worksFor'] = [ '@id' => home_url( '/#org' ) ];

        // mainEntityOfPage — author archive page
        $author_url = get_author_posts_url( $this->user_id );
        if ( $author_url ) {
            $schema['mainEntityOfPage'] = esc_url( $author_url );
        }

        // E-E-A-T credential fields
        $honorific = get_user_meta( $this->user_id, 'ligase_honorific', true );
        if ( $honorific ) {
            $schema['honorificPrefix'] = wp_strip_all_tags( $honorific );
        }

        $alumni = get_user_meta( $this->user_id, 'ligase_alumni_of', true );
        if ( $alumni ) {
            $schema['alumniOf'] = [
                '@type' => 'CollegeOrUniversity',
                'name'  => wp_strip_all_tags( $alumni ),
            ];
        }

        $credential = get_user_meta( $this->user_id, 'ligase_credential', true );
        if ( $credential ) {
            $schema['hasCredential'] = [
                '@type' => 'EducationalOccupationalCredential',
                'name'  => wp_strip_all_tags( $credential ),
            ];
        }

        return apply_filters( 'ligase_person', $schema, $this->user_id );
    }
}
