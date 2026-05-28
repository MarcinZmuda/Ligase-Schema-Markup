<?php
/**
 * Ligase - ClaimReview
 *
 * NICHE TYPE — verified fact-checkers ONLY. Google deprecated the fact-check
 * rich result in June 2025; the markup is now only honoured for publishers
 * approved by Google's Fact Check Tools program. For a regular blog this
 * emits a valid schema.org graph node but produces no SERP enhancement.
 *
 * The opt-in remains so verified fact-checkers can still mark up their work,
 * but admin UI should clearly state that no rich result will appear for
 * non-approved publishers.
 *
 * @package Ligase
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_ClaimReview {

    const VERDICTS = [
        'True',
        'Mostly True',
        'Half True',
        'Mostly False',
        'False',
        'Unproven',
    ];

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_claimreview', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_claimreview', $post_id ) ) {
            return null;
        }

        $claim   = get_post_meta( $post_id, '_ligase_claim_text', true );
        $verdict = get_post_meta( $post_id, '_ligase_claim_verdict', true );
        $source  = get_post_meta( $post_id, '_ligase_claim_source', true );

        if ( empty( $claim ) || empty( $verdict ) ) {
            return null;
        }

        if ( ! in_array( $verdict, self::VERDICTS, true ) ) {
            return null;
        }

        $author_id = (int) get_post_field( 'post_author', $post_id );

        $schema = [
            '@type'         => 'ClaimReview',
            '@id'           => esc_url( get_permalink() ) . '#claimreview',
            'url'           => esc_url( get_permalink() ),
            'datePublished' => get_the_date( 'c' ),
            'author'        => [ '@id' => home_url( '/#author-' . $author_id ) ],
            'publisher'     => [ '@id' => home_url( '/#org' ) ],
            'claimReviewed' => wp_strip_all_tags( $claim ),
            'reviewRating'  => [
                '@type'          => 'Rating',
                'ratingValue'    => (string) $this->verdict_to_rating( $verdict ),
                'bestRating'     => '5',
                'worstRating'    => '1',
                'alternateName'  => $verdict,
            ],
        ];

        if ( ! empty( $source ) ) {
            $schema['itemReviewed'] = [
                '@type'       => 'Claim',
                'author'      => [ '@type' => 'Organization', 'name' => wp_strip_all_tags( $source ) ],
                'datePublished' => get_the_date( 'c' ),
            ];
        }

        return $schema;
    }

    private function verdict_to_rating( string $verdict ): int {
        return match ( $verdict ) {
            'True'         => 5,
            'Mostly True'  => 4,
            'Half True'    => 3,
            'Mostly False' => 2,
            'False'        => 1,
            'Unproven'     => 3,
            default        => 3,
        };
    }
}
