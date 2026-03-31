<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_FAQPage {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        if ( get_post_meta( get_the_ID(), '_ligase_enable_faq', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_faq', get_the_ID() ) ) {
            return null;
        }

        $post_id  = get_the_ID();
        $faq_data = get_post_meta( $post_id, '_ligase_faq_items', true );

        if ( empty( $faq_data ) || ! is_array( $faq_data ) ) {
            return null;
        }

        $entities = [];
        foreach ( $faq_data as $item ) {
            if ( ! is_array( $item ) || empty( $item['question'] ) || empty( $item['answer'] ) ) {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name'  => esc_html( $item['question'] ),
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => wp_kses_post( $item['answer'] ),
                ],
            ];
        }

        if ( count( $entities ) < 2 ) {
            return null;
        }

        return [
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }
}
