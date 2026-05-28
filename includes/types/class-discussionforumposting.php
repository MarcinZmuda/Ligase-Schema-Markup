<?php
/**
 * Ligase - DiscussionForumPosting schema type
 *
 * Powers Google's "Discussions and Forums" SERP feature (active since Nov 2023).
 * Auto-applies to bbPress topics and BuddyPress activity (best-effort detection),
 * and to any post explicitly opted in via the `_ligase_enable_forum` flag.
 *
 * The schema also nests `Comment` objects when comments are enabled on the post
 * — Google uses the comment thread to score the discussion's depth.
 *
 * Note: Google's docs recommend Microdata or RDFa over JSON-LD for large text
 * bodies in forum schema (to avoid duplicating body text). We emit JSON-LD because
 * Ligase's whole architecture is JSON-LD-first; users with very long threads can
 * filter via `ligase_discussionforumposting` to swap to a body-less representation.
 *
 * @package Ligase
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_DiscussionForumPosting {

    public function build(): ?array {
        if ( ! is_singular() ) {
            return null;
        }

        $post_id = get_the_ID();

        // Auto-detect bbPress topics, BuddyPress activity, or manual opt-in.
        $pt   = get_post_type( $post_id );
        $cpt  = in_array( $pt, array( 'topic', 'reply', 'forum' ), true ); // bbPress
        $flag = get_post_meta( $post_id, '_ligase_enable_forum', true ) === '1';
        $rule = Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_forum', $post_id );

        if ( ! $cpt && ! $flag && ! $rule ) {
            return null;
        }

        if ( ! class_exists( 'Ligase_Field_Resolver' ) ) {
            return null;
        }

        $resolved = ( new Ligase_Field_Resolver() )->resolve( 'DiscussionForumPosting', $post_id );
        $node     = $resolved['node'];

        if ( empty( $node['headline'] ) ) {
            return null;
        }

        // Promote @type from default 'DiscussionForumPosting' (already set by resolver
        // via type argument); pin @id.
        $node['@id'] = esc_url( get_permalink( $post_id ) ) . '#discussion';

        // text field: when the resolver couldn't autofill (no manual override),
        // fall back to the post content stripped of shortcodes/HTML.
        if ( empty( $node['text'] ) ) {
            $content = (string) get_post_field( 'post_content', $post_id );
            if ( $content !== '' ) {
                $node['text'] = wp_kses_post( $content );
            }
        }

        // Nested comments — up to 50, schema.org Comment nodes for thread depth.
        $node['comment'] = $this->build_comments( $post_id );
        if ( empty( $node['comment'] ) ) {
            unset( $node['comment'] );
        }

        return apply_filters( 'ligase_discussionforumposting', $node, $post_id );
    }

    /**
     * Build a list of Comment nodes from the post's approved comments.
     *
     * @return array<int,array>
     */
    private function build_comments( int $post_id ): array {
        $comments = get_comments( array(
            'post_id' => $post_id,
            'status'  => 'approve',
            'number'  => 50,
            'order'   => 'ASC',
        ) );
        if ( empty( $comments ) ) {
            return array();
        }
        return array_map(
            function ( $c ) {
                return array(
                    '@type'         => 'Comment',
                    'datePublished' => gmdate( 'c', strtotime( $c->comment_date_gmt ) ),
                    'text'          => wp_strip_all_tags( $c->comment_content ),
                    'author'        => array(
                        '@type' => 'Person',
                        'name'  => wp_strip_all_tags( $c->comment_author ),
                    ),
                );
            },
            $comments
        );
    }
}
