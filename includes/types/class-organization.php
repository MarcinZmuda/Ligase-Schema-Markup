<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Organization {

    public function build(): array {
        $opts = get_option( 'ligase_options', [] );
        $name = ! empty( $opts['org_name'] ) ? $opts['org_name'] : get_bloginfo( 'name' );

        // OnlineStore mode promotes the @type so site-level merchant policies (return,
        // shipping) attach to the OnlineStore node and product offers can reference them
        // by @id instead of repeating the full policy on every product.
        $is_store  = ! empty( $opts['store_mode'] ) || class_exists( 'WooCommerce' );
        $org_type  = $is_store ? 'OnlineStore' : 'Organization';

        $schema = [
            '@type' => $org_type,
            '@id'   => home_url( '/#org' ),
            'name'  => wp_strip_all_tags( $name ),
            'url'   => esc_url( home_url( '/' ) ),
        ];

        $logo = $this->build_logo( $opts );
        if ( $logo ) {
            $schema['logo'] = $logo;
        }

        $social_keys = [
            'social_wikidata', 'social_wikipedia', 'social_linkedin',
            'social_facebook', 'social_twitter', 'social_youtube',
        ];
        $same_as = [];
        foreach ( $social_keys as $k ) {
            $url = $opts[ $k ] ?? '';
            if ( empty( $url ) ) {
                continue;
            }
            $url = esc_url( $url );
            // Validate URL has proper scheme and host
            $parsed = wp_parse_url( $url );
            if ( ! empty( $parsed['scheme'] ) && ! empty( $parsed['host'] ) && in_array( $parsed['scheme'], [ 'http', 'https' ], true ) ) {
                $same_as[] = $url;
            } else if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::warning( 'Invalid sameAs URL skipped', [ 'key' => $k, 'url' => $url ] );
            }
        }
        if ( ! empty( $same_as ) ) {
            $schema['sameAs'] = $same_as;
        }

        $knows = $opts['knows_about'] ?? '';
        if ( $knows ) {
            $schema['knowsAbout'] = array_values( array_filter( array_map(
                fn( $t ) => wp_strip_all_tags( trim( $t ) ),
                explode( ',', $knows )
            ) ) );
        }

        if ( ! empty( $opts['org_email'] ) ) {
            $schema['email'] = sanitize_email( $opts['org_email'] );
        }

        if ( ! empty( $opts['org_phone'] ) ) {
            $schema['telephone'] = wp_strip_all_tags( $opts['org_phone'] );
            $schema['contactPoint'] = [
                '@type'       => 'ContactPoint',
                'telephone'   => wp_strip_all_tags( $opts['org_phone'] ),
                'contactType' => 'customer service',
            ];
        }

        if ( ! empty( $opts['org_description'] ) ) {
            $schema['description'] = wp_strip_all_tags( $opts['org_description'] );
        }

        // founder — linked Person @id
        if ( ! empty( $opts['org_founder_id'] ) ) {
            $founder_id = absint( $opts['org_founder_id'] );
            $schema['founder'] = [ '@id' => home_url( '/#author-' . $founder_id ) ];
        }

        // employee — published authors linked by @id.
        // `has_published_posts` expects an array of post_types since WP 6.4 (was boolean
        // in 4.7+ but deprecated). Plus cap at 20 entries — a 500-author employee[] is
        // payload bloat that helps no one and is cached for 12h.
        $authors = get_users( array(
            'has_published_posts' => array( 'post' ),
            'fields'              => 'ID',
            'number'              => 20,
            'orderby'             => 'post_count',
            'order'               => 'DESC',
        ) );
        if ( ! empty( $authors ) ) {
            $schema['employee'] = array_values( array_map(
                fn( $uid ) => [ '@id' => home_url( '/#author-' . (int) $uid ) ],
                $authors
            ) );
        }

        // Store-level merchant return policy — `hasMerchantReturnPolicy` IS a valid
        // property of OnlineStore (via the Organization → OnlineStore type chain), so
        // Google and schema.org Validator accept it here. Product Offers can reference
        // it by @id to save payload across large catalogs.
        //
        // `shippingDetails` is NOT a valid property of OnlineStore in schema.org — it
        // only exists on Offer / OfferShippingDetails. Emitting it on Organization
        // produces the "Property shippingDetails was not recognised by the schema (e.g.
        // schema.org) as part of an object of type OnlineStore" error. The site-level
        // values from settings are instead inlined into every Product Offer (see
        // Ligase_Type_Product::build_offer) so each Offer is self-contained.
        if ( $is_store ) {
            $return_policy = $this->build_store_return_policy( $opts );
            if ( $return_policy ) {
                $schema['hasMerchantReturnPolicy'] = $return_policy;
            }
        }

        return apply_filters( 'ligase_organization', $schema );
    }

    /**
     * Build the store-wide MerchantReturnPolicy. Returns null if return_country is
     * missing — Google requires `returnPolicyCountry` (since March 2025) and emitting
     * the policy without it produces a Search Console warning.
     */
    private function build_store_return_policy( array $opts ): ?array {
        $country = strtoupper( wp_strip_all_tags( (string) ( $opts['store_return_country'] ?? '' ) ) );
        if ( $country === '' || strlen( $country ) !== 2 ) {
            return null;
        }
        $days = isset( $opts['store_return_days'] ) ? max( 0, (int) $opts['store_return_days'] ) : 14;
        $fees = wp_strip_all_tags( (string) ( $opts['store_return_fees'] ?? 'FreeReturn' ) );
        return [
            '@type'                => 'MerchantReturnPolicy',
            '@id'                  => home_url( '/#return-policy' ),
            'applicableCountry'    => $country,
            'returnPolicyCountry'  => $country,
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => $days,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/' . $fees,
        ];
    }

    /**
     * Build the store-wide OfferShippingDetails. Returns null if no shipping country
     * configured — Google requires `shippingDestination.addressCountry` for any
     * shipping-enhanced rich result.
     */
    private function build_store_shipping( array $opts ): ?array {
        $country = strtoupper( wp_strip_all_tags( (string) ( $opts['store_shipping_country'] ?? '' ) ) );
        if ( $country === '' || strlen( $country ) !== 2 ) {
            return null;
        }
        $currency = wp_strip_all_tags( (string) ( $opts['store_currency'] ?? 'PLN' ) );
        $rate     = isset( $opts['store_shipping_rate'] ) ? (float) $opts['store_shipping_rate'] : 0.0;
        $h_min    = isset( $opts['store_handling_min'] ) ? max( 0, (int) $opts['store_handling_min'] ) : 0;
        $h_max    = isset( $opts['store_handling_max'] ) ? max( $h_min, (int) $opts['store_handling_max'] ) : 1;
        $t_min    = isset( $opts['store_transit_min'] )  ? max( 0, (int) $opts['store_transit_min'] )  : 1;
        $t_max    = isset( $opts['store_transit_max'] )  ? max( $t_min, (int) $opts['store_transit_max'] ) : 3;
        return [
            '@type'               => 'OfferShippingDetails',
            '@id'                 => home_url( '/#shipping-policy' ),
            'shippingRate'        => [
                '@type'    => 'MonetaryAmount',
                'value'    => (string) $rate,
                'currency' => $currency,
            ],
            'shippingDestination' => [
                '@type'          => 'DefinedRegion',
                'addressCountry' => $country,
            ],
            'deliveryTime'        => [
                '@type'        => 'ShippingDeliveryTime',
                'handlingTime' => [ '@type' => 'QuantitativeValue', 'minValue' => $h_min, 'maxValue' => $h_max, 'unitCode' => 'DAY' ],
                'transitTime'  => [ '@type' => 'QuantitativeValue', 'minValue' => $t_min, 'maxValue' => $t_max, 'unitCode' => 'DAY' ],
            ],
        ];
    }

    private function build_logo( array $opts ): ?array {
        $url = ! empty( $opts['org_logo'] ) ? $opts['org_logo'] : get_site_icon_url( 600 );
        if ( ! $url ) {
            return null;
        }

        // Google requirement (since 2025): logo must be a SQUARE of at least 112x112px,
        // recommended 600x600+. The old AMP-era 600x60 default produced thin banners
        // that Google now ignores for Knowledge Graph attribution.
        return [
            '@type'  => 'ImageObject',
            '@id'    => home_url( '/#logo' ),
            'url'    => esc_url( $url ),
            'width'  => (int) ( $opts['logo_width']  ?? 112 ),
            'height' => (int) ( $opts['logo_height'] ?? 112 ),
        ];
    }
}
