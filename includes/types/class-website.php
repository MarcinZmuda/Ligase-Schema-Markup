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

        // SearchAction target must contain a LITERAL {search_term_string} placeholder.
        // home_url() URL-encodes braces ({/}) producing %7Bsearch_term_string%7D which
        // Google's parser can't read — the Sitelinks Search Box is broken if you do that.
        // Construct the URL manually: scheme + host + path from home_url(), then append
        // the literal placeholder. esc_url() on the schema array would re-encode again,
        // so we DON'T pipe this through esc_url; wp_json_encode in Output handles JSON escaping.
        $search_url = rtrim( (string) home_url( '/' ), '/' ) . '/?s={search_term_string}';
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
