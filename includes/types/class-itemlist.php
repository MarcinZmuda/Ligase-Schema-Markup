<?php
/**
 * Ligase - ItemList schema type
 *
 * Emits ItemList on archive-style pages so Google can build a Carousel rich
 * result (host carousel for Recipe/Course/Movie/Restaurant — already alive —
 * plus the EEA Beta carousel for Product/Event/Hotel).
 *
 * Auto-detects context:
 *   - WooCommerce product category / tag / shop archive → ItemList of Products
 *   - WordPress taxonomy archive (category/tag/custom tax) → ItemList of Articles
 *   - Blog posts listing (`is_home()` when not front page) → ItemList of Articles
 *
 * Each item is a minimal ListItem with position + url. Embedded item nodes
 * (Product/Article) are pulled from the current archive's `the_loop` posts and
 * limited to the current page so item count matches what's visible.
 *
 * @package Ligase
 * @since   2.4.1
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_ItemList {

    /**
     * Maximum items emitted in a single ItemList. Beyond this Google starts
     * ignoring positions and the markup-to-content ratio degrades.
     */
    const MAX_ITEMS = 50;

    public function build(): ?array {
        $context = $this->detect_context();
        if ( $context === null ) {
            return null;
        }

        $items = $this->collect_items( $context );
        if ( empty( $items ) ) {
            return null;
        }

        $schema = array(
            '@type'           => 'ItemList',
            '@id'             => $this->current_url() . '#itemlist',
            'name'            => $context['name'],
            'numberOfItems'   => count( $items ),
            'itemListOrder'   => 'https://schema.org/ItemListOrderDescending',
            'itemListElement' => $items,
        );

        if ( ! empty( $context['url'] ) ) {
            $schema['url'] = $context['url'];
        }

        return apply_filters( 'ligase_itemlist', $schema, $context );
    }

    /**
     * Determine archive context. Returns null for non-archive pages.
     *
     * @return array{type:string,name:string,url:string,query_post_type:string}|null
     */
    private function detect_context(): ?array {
        // WooCommerce shop archive
        if ( function_exists( 'is_shop' ) && is_shop() ) {
            return array(
                'type'            => 'wc_shop',
                'name'            => function_exists( 'woocommerce_page_title' )
                    ? trim( (string) ( get_the_title( wc_get_page_id( 'shop' ) ) ?: 'Shop' ) )
                    : 'Shop',
                'url'             => esc_url( $this->current_url() ),
                'query_post_type' => 'product',
            );
        }

        // WooCommerce product category / tag
        if ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                return array(
                    'type'            => 'wc_taxonomy',
                    'name'            => $term->name,
                    'url'             => esc_url( get_term_link( $term ) ?: $this->current_url() ),
                    'query_post_type' => 'product',
                );
            }
        }

        // Regular WP taxonomy archive (category/tag/custom-tax that's NOT a product taxonomy)
        if ( ( is_category() || is_tag() || is_tax() ) && ! ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) ) {
            $term = get_queried_object();
            if ( $term instanceof WP_Term ) {
                return array(
                    'type'            => 'wp_taxonomy',
                    'name'            => $term->name,
                    'url'             => esc_url( get_term_link( $term ) ?: $this->current_url() ),
                    'query_post_type' => 'post',
                );
            }
        }

        // Blog posts listing (when home isn't a static page)
        if ( is_home() && ! is_front_page() ) {
            return array(
                'type'            => 'blog_home',
                'name'            => (string) ( get_bloginfo( 'name' ) ?: 'Blog' ),
                'url'             => esc_url( get_permalink( (int) get_option( 'page_for_posts' ) ) ?: home_url( '/' ) ),
                'query_post_type' => 'post',
            );
        }

        // Author archive — list of author's posts
        if ( is_author() ) {
            $user = get_queried_object();
            if ( $user instanceof WP_User ) {
                return array(
                    'type'            => 'author',
                    'name'            => $user->display_name,
                    'url'             => esc_url( get_author_posts_url( $user->ID ) ),
                    'query_post_type' => 'post',
                );
            }
        }

        return null;
    }

    /**
     * Build the itemListElement array from the current WP_Query. Uses the same
     * results the user sees on the page (respects paging, ordering, filters).
     *
     * @param array $context
     * @return array<int,array>
     */
    private function collect_items( array $context ): array {
        global $wp_query;
        if ( empty( $wp_query->posts ) || ! is_array( $wp_query->posts ) ) {
            return array();
        }

        $items = array();
        $pos   = 1;
        foreach ( $wp_query->posts as $p ) {
            if ( $pos > self::MAX_ITEMS ) {
                break;
            }
            if ( ! ( $p instanceof WP_Post ) ) {
                continue;
            }
            $item = $this->build_list_item( $pos, $p, $context );
            if ( $item !== null ) {
                $items[] = $item;
                ++$pos;
            }
        }
        return $items;
    }

    /**
     * Build a single ListItem. For WooCommerce products we embed a Product node
     * inline (Google's recommended pattern for product carousels); for posts we
     * just embed url + name (more SERP-friendly).
     */
    private function build_list_item( int $position, WP_Post $post, array $context ): ?array {
        $url  = get_permalink( $post );
        $name = wp_strip_all_tags( get_the_title( $post ) );
        if ( ! $url || $name === '' ) {
            return null;
        }

        $item = array(
            '@type'    => 'ListItem',
            'position' => $position,
            'url'      => esc_url( $url ),
        );

        if ( $context['query_post_type'] === 'product' && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product instanceof WC_Product ) {
                $item['item'] = $this->build_inline_product( $product, $url );
                return $item;
            }
        }

        // Article-shaped item (post / cpt)
        $item['item'] = $this->build_inline_article( $post, $url, $name );
        return $item;
    }

    /**
     * Inline Product node for ListItem.item — minimal but enough for Google to
     * render a Product carousel entry (name + image + offer + url).
     */
    private function build_inline_product( WC_Product $product, string $url ): array {
        $node = array(
            '@type' => 'Product',
            '@id'   => esc_url( $url ) . '#product',
            'name'  => wp_strip_all_tags( $product->get_name() ),
            'url'   => esc_url( $url ),
        );

        $img_id = $product->get_image_id();
        if ( $img_id ) {
            $src = wp_get_attachment_image_src( $img_id, 'large' );
            if ( $src ) {
                $node['image'] = esc_url( (string) $src[0] );
            }
        }

        $price = $product->get_price();
        if ( $price !== '' && $price !== null ) {
            $stock_status = $product->get_stock_status();
            $availability = array(
                'instock'     => 'https://schema.org/InStock',
                'outofstock'  => 'https://schema.org/OutOfStock',
                'onbackorder' => 'https://schema.org/BackOrder',
            )[ $stock_status ] ?? 'https://schema.org/InStock';

            $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'PLN';

            $node['offers'] = array(
                '@type'         => 'Offer',
                'price'         => (string) (float) $price,
                'priceCurrency' => $currency,
                'availability'  => $availability,
                'url'           => esc_url( $url ),
                'seller'        => array( '@id' => home_url( '/#org' ) ),
            );
        }

        return $node;
    }

    /**
     * Inline Article-shaped node for blog/category list items.
     */
    private function build_inline_article( WP_Post $post, string $url, string $name ): array {
        $node = array(
            '@type'         => 'Article',
            '@id'           => esc_url( $url ) . '#posting',
            'headline'      => $name,
            'url'           => esc_url( $url ),
            'datePublished' => get_post_time( 'c', true, $post ),
        );

        $thumb_id = get_post_thumbnail_id( $post );
        if ( $thumb_id ) {
            $src = wp_get_attachment_image_src( $thumb_id, 'large' );
            if ( $src ) {
                $node['image'] = esc_url( (string) $src[0] );
            }
        }

        $author_id = (int) $post->post_author;
        if ( $author_id > 0 ) {
            $node['author'] = array( '@id' => Ligase_Type_BlogPosting::author_ref_id( (int) $author_id ) );
        }

        return $node;
    }

    private function current_url(): string {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        return esc_url_raw( $scheme . $host . $uri );
    }
}
