<?php
/**
 * Ligase - Product schema type
 *
 * Generates Product + Offer JSON-LD for two distinct experiences:
 *  - Product snippet (review/ranking pages without sales) — needs only one of:
 *    review / aggregateRating / offers.
 *  - Merchant listing (pages where the product can be bought) — needs the full
 *    Offer with availability, price, return policy, and shipping details.
 *
 * The merchant_mode flag in _ligase_product controls which set of fields is
 * required. Both modes share the same Product container.
 *
 * @package Ligase
 * @since   2.0.2
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Product {

    public function build(): ?array {
        if ( ! is_singular() ) {
            return null;
        }

        $post_id = get_the_ID();

        // Auto-detect WooCommerce products in addition to the manual enable flag.
        // This lets stores get Product schema without ticking a per-post checkbox.
        $is_wc_product = get_post_type( $post_id ) === 'product' && function_exists( 'wc_get_product' );
        $manual_enabled = get_post_meta( $post_id, '_ligase_enable_product', true ) === '1';
        $rules_enabled  = class_exists( 'Ligase_Schema_Rules' ) ? Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_product', $post_id ) : false;

        if ( ! $is_wc_product && ! $manual_enabled && ! $rules_enabled ) {
            return null;
        }

        $data = (array) get_post_meta( $post_id, '_ligase_product', true );

        // CONTRACT-DRIVEN PATH: when we have a usable contract for Product, the resolver
        // is the single source of truth for the node. It handles override-vs-auto, WC
        // autofill, and the eligibility gate. The legacy manual-only path below stays
        // as a fallback for sites that disabled the contract via filter or that lack
        // any usable source data.
        if ( class_exists( 'Ligase_Field_Resolver' ) ) {
            $resolver = new Ligase_Field_Resolver();
            $resolved = $resolver->resolve( 'Product', $post_id );
            $node     = $resolved['node'];

            // No name → no Product node. Google requires name for any Product rich result.
            if ( empty( $node['name'] ) ) {
                return null;
            }

            // Downgrade: if Offer is missing required fields, drop the whole offers
            // block. Result is a valid Product snippet without merchant-listing claims —
            // better than emitting an incomplete Offer that Search Console flags.
            $offer_required = array(
                'offers.price', 'offers.priceCurrency', 'offers.availability',
            );
            $offer_incomplete = false;
            foreach ( $offer_required as $key ) {
                if ( in_array( $key, $resolved['missing_required'], true ) ) {
                    $offer_incomplete = true;
                    break;
                }
            }
            if ( $offer_incomplete ) {
                unset( $node['offers'] );
            }

            // returnPolicyCountry is required for MerchantReturnPolicy since March 2025.
            // If missing, drop the entire return-policy object.
            if ( in_array( 'offers.hasMerchantReturnPolicy.returnPolicyCountry', $resolved['missing_required'], true )
                 && isset( $node['offers']['hasMerchantReturnPolicy'] ) ) {
                unset( $node['offers']['hasMerchantReturnPolicy'] );
            }

            // Legacy variant path — if manual product data declares variants, use the
            // ProductGroup builder (resolver doesn't model variants today).
            if ( ! empty( $data['variants'] ) && is_array( $data['variants'] ) ) {
                $merged = array_merge(
                    array(
                        'name'        => $node['name'] ?? '',
                        'description' => $node['description'] ?? '',
                        'image'       => $node['image'] ?? '',
                    ),
                    $data
                );
                return $this->build_product_group( $merged, $post_id );
            }

            // Pin the @id to the legacy fragment for graph linking stability.
            $node['@id'] = esc_url( get_permalink( $post_id ) ) . '#product';
            $node['url'] = esc_url( get_permalink( $post_id ) );

            // Seller reference is graph-linked (existing convention).
            if ( isset( $node['offers'] ) && is_array( $node['offers'] ) ) {
                $node['offers']['seller'] = array( '@id' => home_url( '/#org' ) );
            }

            return apply_filters( 'ligase_product', $node, $post_id );
        }

        // Legacy fallback (resolver unavailable / contract filtered to empty).
        if ( empty( $data ) || ! is_array( $data ) || empty( $data['name'] ) ) {
            return null;
        }

        // Variant products → emit ProductGroup with hasVariant array. Each variant is
        // a Product with its own SKU/GTIN/Offer. ProductGroup is the schema.org pattern
        // for size/color/etc.; without it Google can't surface variant-specific stock
        // or pricing in merchant listings.
        if ( ! empty( $data['variants'] ) && is_array( $data['variants'] ) ) {
            return $this->build_product_group( $data, $post_id );
        }

        $schema = [
            '@type' => 'Product',
            '@id'   => esc_url( get_permalink() ) . '#product',
            'name'  => wp_strip_all_tags( $data['name'] ),
            'url'   => esc_url( get_permalink() ),
        ];

        if ( ! empty( $data['description'] ) ) {
            $schema['description'] = wp_strip_all_tags( mb_substr( $data['description'], 0, 5000 ) );
        }

        // Image: explicit field → post thumbnail → null. Google strongly prefers
        // at least one image for Product rich results.
        $image_url = '';
        if ( ! empty( $data['image'] ) ) {
            $image_url = $data['image'];
        } else {
            $tid = get_post_thumbnail_id( $post_id );
            if ( $tid ) {
                $img = wp_get_attachment_image_src( $tid, 'full' );
                if ( $img ) {
                    $image_url = $img[0];
                }
            }
        }
        if ( $image_url ) {
            $schema['image'] = esc_url( $image_url );
        }

        // Identifiers
        foreach ( [ 'sku', 'gtin', 'mpn' ] as $id_key ) {
            if ( ! empty( $data[ $id_key ] ) ) {
                $schema[ $id_key ] = wp_strip_all_tags( $data[ $id_key ] );
            }
        }
        if ( ! empty( $data['brand'] ) ) {
            $schema['brand'] = [
                '@type' => 'Brand',
                'name'  => wp_strip_all_tags( $data['brand'] ),
            ];
        }

        // Offer
        $offer = $this->build_offer( $data, (string) $post_id );
        if ( $offer ) {
            $schema['offers'] = $offer;
        }

        // aggregateRating — only from genuine user reviews. Fake ratings = manual action.
        if ( ! empty( $data['rating_value'] ) && ! empty( $data['rating_count'] ) ) {
            $value = (float) $data['rating_value'];
            $count = (int) $data['rating_count'];
            if ( $value >= 1 && $value <= 5 && $count >= 1 ) {
                $schema['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => (string) $value,
                    'reviewCount' => $count,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ];
            }
        }

        // Editorial review (Product snippet): pros / cons must be a real editorial
        // verdict, not a sales pitch.
        if ( ! empty( $data['editorial_review'] ) && is_array( $data['editorial_review'] ) ) {
            $review = $data['editorial_review'];
            $review_node = [
                '@type'        => 'Review',
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => (string) (float) ( $review['rating'] ?? 0 ),
                    'bestRating'  => '5',
                    'worstRating' => '1',
                ],
                'author' => [
                    '@type' => 'Person',
                    'name'  => wp_strip_all_tags( $review['author'] ?? get_the_author() ),
                ],
            ];
            if ( ! empty( $review['body'] ) ) {
                $review_node['reviewBody'] = wp_strip_all_tags( mb_substr( $review['body'], 0, 5000 ) );
            }

            $pros = isset( $review['pros'] ) ? array_values( array_filter( (array) $review['pros'] ) ) : [];
            $cons = isset( $review['cons'] ) ? array_values( array_filter( (array) $review['cons'] ) ) : [];

            if ( ! empty( $pros ) ) {
                $review_node['positiveNotes'] = [
                    '@type'           => 'ItemList',
                    'itemListElement' => $this->notes_to_listitems( $pros ),
                ];
            }
            if ( ! empty( $cons ) ) {
                $review_node['negativeNotes'] = [
                    '@type'           => 'ItemList',
                    'itemListElement' => $this->notes_to_listitems( $cons ),
                ];
            }
            if ( count( $pros ) + count( $cons ) >= 2 ) {
                // Google requires a minimum of 2 statements total across positive+negative
                // for pros/cons to display in product snippets.
                $schema['review'] = $review_node;
            }
        }

        return apply_filters( 'ligase_product', $schema, $post_id );
    }

    /**
     * Build the Offer node. For merchant listings, includes return policy and
     * shipping details (both required by Google since March 2025).
     *
     * @return array|null  Null if no usable price data.
     */
    private function build_offer( array $data, string $post_id ): ?array {
        if ( ! isset( $data['price'] ) || $data['price'] === '' ) {
            return null;
        }

        $allowed_availability = [
            'InStock', 'OutOfStock', 'PreOrder', 'BackOrder',
            'Discontinued', 'SoldOut', 'LimitedAvailability',
            'OnlineOnly', 'InStoreOnly', 'PreSale',
        ];
        $availability = $data['availability'] ?? 'InStock';
        if ( ! in_array( $availability, $allowed_availability, true ) ) {
            $availability = 'InStock';
        }

        $allowed_conditions = [ 'NewCondition', 'UsedCondition', 'RefurbishedCondition', 'DamagedCondition' ];
        $condition = $data['item_condition'] ?? 'NewCondition';
        if ( ! in_array( $condition, $allowed_conditions, true ) ) {
            $condition = 'NewCondition';
        }

        $currency = wp_strip_all_tags( $data['currency'] ?? 'PLN' );

        $offer = [
            '@type'         => 'Offer',
            'price'         => (string) (float) $data['price'],
            'priceCurrency' => $currency,
            'availability'  => 'https://schema.org/' . $availability,
            'itemCondition' => 'https://schema.org/' . $condition,
            'url'           => esc_url( get_permalink() ),
            'seller'        => [ '@id' => home_url( '/#org' ) ],
        ];

        // Sale price — when both regular and sale prices exist, emit priceSpecification
        // with SalePrice + StrikethroughPrice so Google shows a strikethrough in the SERP.
        // The base `price` stays as the sale price so price filters in Shopping work.
        if ( isset( $data['regular_price'] ) && (float) $data['regular_price'] > (float) $data['price'] ) {
            $offer['priceSpecification'] = [
                [
                    '@type'         => 'UnitPriceSpecification',
                    'priceType'     => 'https://schema.org/SalePrice',
                    'price'         => (string) (float) $data['price'],
                    'priceCurrency' => $currency,
                ],
                [
                    '@type'         => 'UnitPriceSpecification',
                    'priceType'     => 'https://schema.org/StrikethroughPrice',
                    'price'         => (string) (float) $data['regular_price'],
                    'priceCurrency' => $currency,
                ],
            ];
        }

        // priceValidUntil — only emit if future date; past date suppresses snippet.
        if ( ! empty( $data['price_valid_until'] ) ) {
            $ts = strtotime( (string) $data['price_valid_until'] );
            if ( $ts && $ts > time() ) {
                $offer['priceValidUntil'] = gmdate( 'Y-m-d', $ts );
            }
        }

        // Merchant return policy — per-product override, or @id reference to the
        // store-level policy emitted by OnlineStore (saves payload + maintenance).
        if ( ! empty( $data['return_country'] ) ) {
            $return_country = strtoupper( wp_strip_all_tags( $data['return_country'] ) );
            $return_days    = isset( $data['return_days'] ) ? max( 0, (int) $data['return_days'] ) : 14;
            $offer['hasMerchantReturnPolicy'] = [
                '@type'                => 'MerchantReturnPolicy',
                'applicableCountry'    => $return_country,
                'returnPolicyCountry'  => $return_country,
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays'   => $return_days,
                'returnMethod'         => 'https://schema.org/ReturnByMail',
                'returnFees'           => 'https://schema.org/' . ( $data['return_fees'] ?? 'FreeReturn' ),
            ];
        } else {
            $opts = (array) get_option( 'ligase_options', [] );
            if ( ! empty( $opts['store_return_country'] ) ) {
                $offer['hasMerchantReturnPolicy'] = [ '@id' => home_url( '/#return-policy' ) ];
            }
        }

        // Shipping details — per-product override, or @id reference to store-level shipping
        if ( ! empty( $data['shipping_country'] ) && isset( $data['shipping_rate'] ) ) {
            $shipping_country  = strtoupper( wp_strip_all_tags( $data['shipping_country'] ) );
            $shipping_currency = wp_strip_all_tags( $data['shipping_currency'] ?? ( $data['currency'] ?? 'PLN' ) );
            $handling_min = isset( $data['handling_days_min'] ) ? max( 0, (int) $data['handling_days_min'] ) : 0;
            $handling_max = isset( $data['handling_days_max'] ) ? max( $handling_min, (int) $data['handling_days_max'] ) : max( 1, $handling_min );
            $transit_min  = isset( $data['transit_days_min'] )  ? max( 0, (int) $data['transit_days_min'] )  : 1;
            $transit_max  = isset( $data['transit_days_max'] )  ? max( $transit_min, (int) $data['transit_days_max'] ) : max( 3, $transit_min );

            $offer['shippingDetails'] = [
                '@type'              => 'OfferShippingDetails',
                'shippingRate'       => [
                    '@type'    => 'MonetaryAmount',
                    'value'    => (string) (float) $data['shipping_rate'],
                    'currency' => $shipping_currency,
                ],
                'shippingDestination' => [
                    '@type'        => 'DefinedRegion',
                    'addressCountry' => $shipping_country,
                ],
                'deliveryTime' => [
                    '@type'               => 'ShippingDeliveryTime',
                    'handlingTime'        => [
                        '@type'    => 'QuantitativeValue',
                        'minValue' => $handling_min,
                        'maxValue' => $handling_max,
                        'unitCode' => 'DAY',
                    ],
                    'transitTime'         => [
                        '@type'    => 'QuantitativeValue',
                        'minValue' => $transit_min,
                        'maxValue' => $transit_max,
                        'unitCode' => 'DAY',
                    ],
                ],
            ];
        } else {
            $opts = (array) get_option( 'ligase_options', [] );
            if ( ! empty( $opts['store_shipping_country'] ) ) {
                $offer['shippingDetails'] = [ '@id' => home_url( '/#shipping-policy' ) ];
            }
        }

        return $offer;
    }

    /**
     * Build a ProductGroup node for variant products.
     *
     * $data must contain:
     *   - name            (string)
     *   - variants        (list of arrays, each with at minimum: sku, price, attrs)
     *   - varies_by       (list of schema.org property URIs, e.g. ['https://schema.org/size'])
     *
     * Each variant becomes a child Product with its own SKU, GTIN (if provided), Offer,
     * and attribute values. The parent ProductGroup carries the shared attributes (brand,
     * description, image) so they don't repeat per-variant.
     */
    private function build_product_group( array $data, int $post_id ): array {
        $group = [
            '@type'    => 'ProductGroup',
            '@id'      => esc_url( get_permalink() ) . '#productgroup',
            'name'     => wp_strip_all_tags( $data['name'] ),
            'url'      => esc_url( get_permalink() ),
            'variesBy' => array_values( array_filter( array_map(
                fn( $v ) => esc_url_raw( (string) $v ),
                (array) ( $data['varies_by'] ?? [ 'https://schema.org/size', 'https://schema.org/color' ] )
            ) ) ),
        ];

        if ( ! empty( $data['description'] ) ) {
            $group['description'] = wp_strip_all_tags( mb_substr( $data['description'], 0, 5000 ) );
        }
        if ( ! empty( $data['brand'] ) ) {
            $group['brand'] = [
                '@type' => 'Brand',
                'name'  => wp_strip_all_tags( $data['brand'] ),
            ];
        }
        if ( ! empty( $data['productGroupID'] ) ) {
            $group['productGroupID'] = wp_strip_all_tags( $data['productGroupID'] );
        }

        // Parent image (falls back to post thumbnail)
        $image_url = ! empty( $data['image'] ) ? $data['image'] : '';
        if ( ! $image_url ) {
            $tid = get_post_thumbnail_id( $post_id );
            if ( $tid ) {
                $img = wp_get_attachment_image_src( $tid, 'full' );
                if ( $img ) {
                    $image_url = $img[0];
                }
            }
        }
        if ( $image_url ) {
            $group['image'] = esc_url( $image_url );
        }

        $variants = [];
        foreach ( $data['variants'] as $idx => $variant ) {
            if ( ! is_array( $variant ) || empty( $variant['sku'] ) ) {
                continue;
            }
            $v_data = array_merge( $data, $variant ); // variant inherits group defaults
            unset( $v_data['variants'], $v_data['varies_by'] );

            $v_node = [
                '@type' => 'Product',
                '@id'   => esc_url( get_permalink() ) . '#variant-' . sanitize_key( (string) $variant['sku'] ),
                'name'  => wp_strip_all_tags( $variant['name'] ?? $data['name'] ),
                'sku'   => wp_strip_all_tags( $variant['sku'] ),
            ];
            if ( ! empty( $variant['gtin'] ) ) { $v_node['gtin'] = wp_strip_all_tags( $variant['gtin'] ); }
            if ( ! empty( $variant['mpn'] ) )  { $v_node['mpn']  = wp_strip_all_tags( $variant['mpn'] );  }
            if ( ! empty( $variant['image'] ) ) {
                $v_node['image'] = esc_url( $variant['image'] );
            }
            // Per-variant attribute values (size, color, ...) inferred from variant keys.
            if ( ! empty( $variant['size'] ) )  { $v_node['size']  = wp_strip_all_tags( $variant['size'] );  }
            if ( ! empty( $variant['color'] ) ) { $v_node['color'] = wp_strip_all_tags( $variant['color'] ); }

            $offer = $this->build_offer( $v_data, (string) $post_id );
            if ( $offer ) {
                $v_node['offers'] = $offer;
            }

            $variants[] = $v_node;
        }

        if ( ! empty( $variants ) ) {
            $group['hasVariant'] = $variants;
        }

        return apply_filters( 'ligase_productgroup', $group, $post_id );
    }

    private function notes_to_listitems( array $items ): array {
        $out = [];
        $pos = 1;
        foreach ( $items as $item ) {
            $name = wp_strip_all_tags( is_array( $item ) ? ( $item['name'] ?? '' ) : (string) $item );
            if ( $name === '' ) {
                continue;
            }
            $out[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $name,
            ];
        }
        return $out;
    }
}
