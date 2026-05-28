<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_WebSite {

    public function build(): array {
        $schema = [
            '@type'     => 'WebSite',
            '@id'       => home_url( '/#website' ),
            'name'      => wp_strip_all_tags( get_bloginfo( 'name' ) ),
            'url'       => esc_url( home_url( '/' ) ),
            'inLanguage'=> str_replace( '_', '-', get_locale() ),
            'publisher' => [ '@id' => home_url( '/#org' ) ],
        ];

        $search_url = home_url( '/?s={search_term_string}' );
        $schema['potentialAction'] = [
            '@type'        => 'SearchAction',
            'target'       => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => $search_url,
            ],
            'query-input'  => 'required name=search_term_string',
        ];

        return apply_filters( 'ligase_website', $schema );
    }
}
