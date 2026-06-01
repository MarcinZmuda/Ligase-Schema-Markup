<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Cache {
    const PREFIX = 'ligase_schema_';
    const TTL    = HOUR_IN_SECONDS * 12;

    public static function get( string $key ): mixed {
        return get_transient( self::PREFIX . md5( $key ) );
    }

    public static function set( string $key, string $value ): void {
        set_transient( self::PREFIX . md5( $key ), $value, self::TTL );
    }

    public static function invalidate_post( int $post_id ): void {
        $locales = [ get_locale() ];
        foreach ( $locales as $locale ) {
            delete_transient( self::PREFIX . md5( 'ligase_' . $post_id . '_' . $locale . '_' . LIGASE_VERSION ) );
        }

        if ( class_exists( 'Ligase_Logger' ) ) {
            Ligase_Logger::debug( 'Cache invalidated for post', [ 'post_id' => $post_id ] );
        }
    }

    /**
     * Invalidate the post cache AND every archive/listing cache that depends on it.
     *
     * Triggered from `save_post`. The per-post `invalidate_post()` only busted the single
     * post key, leaving category/tag/author/home/blog listings stale up to 12h. This
     * helper widens the bust: post key + its term archives + author archive + home/front +
     * blog listing + ItemList cache. Each cache key is reconstructed from the same format
     * used in Ligase_Output::render() (`ligase_*_<id>_<locale>_<version>`).
     *
     * Locale dimension: bust current locale + any active WPML/Polylang locales + en_US
     * fallback. This ensures multilingual setups don't keep a stale node in another lang.
     */
    public static function invalidate_post_and_related( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        // Resolve locales to bust (multilingual aware).
        $locales = self::active_locales();

        foreach ( $locales as $locale ) {
            // 1. The post's own cache.
            delete_transient( self::PREFIX . md5( 'ligase_' . $post_id . '_' . $locale . '_' . LIGASE_VERSION ) );

            // 2. Home + front-page caches.
            delete_transient( self::PREFIX . md5( 'ligase_ctx_home_'   . $locale . '_' . LIGASE_VERSION ) );
            delete_transient( self::PREFIX . md5( 'ligase_ctx_front_'  . $locale . '_' . LIGASE_VERSION ) );
            delete_transient( self::PREFIX . md5( 'ligase_ctx_search_' . $locale . '_' . LIGASE_VERSION ) );
            delete_transient( self::PREFIX . md5( 'ligase_ctx_date_'   . $locale . '_' . LIGASE_VERSION ) );
            delete_transient( self::PREFIX . md5( 'ligase_ctx_other_'  . $locale . '_' . LIGASE_VERSION ) );

            // 3. Static blog listing page (when a Page is set as posts page).
            $page_for_posts = (int) get_option( 'page_for_posts' );
            if ( $page_for_posts > 0 ) {
                delete_transient( self::PREFIX . md5( 'ligase_' . $page_for_posts . '_' . $locale . '_' . LIGASE_VERSION ) );
            }

            // 4. Author archive cache.
            $author_id = (int) $post->post_author;
            if ( $author_id > 0 ) {
                delete_transient( self::PREFIX . md5( 'ligase_user_' . $author_id . '_' . $locale . '_' . LIGASE_VERSION ) );
            }

            // 5. Every attached taxonomy term archive (category, tag, custom-tax).
            $taxonomies = get_object_taxonomies( $post->post_type );
            if ( is_array( $taxonomies ) ) {
                foreach ( $taxonomies as $taxonomy ) {
                    $terms = get_the_terms( $post_id, $taxonomy );
                    if ( is_array( $terms ) ) {
                        foreach ( $terms as $term ) {
                            if ( $term instanceof WP_Term ) {
                                delete_transient( self::PREFIX . md5( 'ligase_term_' . (int) $term->term_id . '_' . $locale . '_' . LIGASE_VERSION ) );
                            }
                        }
                    }
                }
            }
        }

        if ( class_exists( 'Ligase_Logger' ) ) {
            Ligase_Logger::debug( 'Cache invalidated for post + related archives', [
                'post_id' => $post_id,
                'locales' => $locales,
            ] );
        }
    }

    /**
     * Return the list of locales whose caches must be busted on a write.
     * Always includes the current locale and en_US fallback; merges WPML/Polylang locales when active.
     */
    private static function active_locales(): array {
        $locales = [ get_locale(), 'en_US' ];

        // WPML
        if ( function_exists( 'icl_get_languages' ) ) {
            $langs = icl_get_languages( 'skip_missing=0' );
            if ( is_array( $langs ) ) {
                foreach ( $langs as $lang ) {
                    if ( ! empty( $lang['default_locale'] ) ) {
                        $locales[] = (string) $lang['default_locale'];
                    }
                }
            }
        }

        // Polylang
        if ( function_exists( 'pll_languages_list' ) ) {
            $pl_locales = pll_languages_list( [ 'fields' => 'locale' ] );
            if ( is_array( $pl_locales ) ) {
                foreach ( $pl_locales as $pl ) {
                    if ( is_string( $pl ) && $pl !== '' ) {
                        $locales[] = $pl;
                    }
                }
            }
        }

        return array_values( array_unique( array_filter( $locales ) ) );
    }

    public static function invalidate_all(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_' . self::PREFIX . '%',
            '_transient_timeout_' . self::PREFIX . '%'
        ) );

        if ( class_exists( 'Ligase_Logger' ) ) {
            Ligase_Logger::info( 'All schema cache invalidated' );
        }
    }
}
