<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_QAPage {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_qapage', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_qapage', $post_id ) ) {
            return null;
        }

        $question = get_post_meta( $post_id, '_ligase_qa_question', true );
        $answer   = get_post_meta( $post_id, '_ligase_qa_answer', true );

        if ( empty( $question ) || empty( $answer ) ) {
            return null;
        }

        $author_id = (int) get_post_field( 'post_author', $post_id );

        return [
            '@type'       => 'QAPage',
            '@id'         => esc_url( get_permalink() ) . '#qapage',
            'mainEntity'  => [
                '@type'          => 'Question',
                'name'           => esc_html( $question ),
                'text'           => esc_html( $question ),
                'dateCreated'    => get_the_date( 'c' ),
                'author'         => [ '@id' => home_url( '/#author-' . $author_id ) ],
                'answerCount'    => 1,
                'acceptedAnswer' => [
                    '@type'       => 'Answer',
                    'text'        => wp_kses_post( $answer ),
                    'dateCreated' => get_the_date( 'c' ),
                    'author'      => [ '@id' => home_url( '/#author-' . $author_id ) ],
                    'upvoteCount' => (int) get_comments_number( $post_id ),
                ],
            ],
        ];
    }
}
