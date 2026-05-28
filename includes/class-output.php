<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Output {

    public static function render(): void {
        if ( is_404() ) {
            return;
        }

        if ( ! self::should_render() ) {
            return;
        }

        // Build a unique cache key from the QUERIED OBJECT (set once when the main
        // query parses) rather than get_the_ID() (which reads $GLOBALS['post'] and
        // can be hijacked by themes/plugins calling query_posts() before wp_head).
        // Without this, makumi.eu-style "queried object is product but get_the_ID
        // returns 0 because XStore corrupted globals" produced an archive cache key
        // for the category, then served stale category schema on every product hit.
        $queried = get_queried_object();
        if ( $queried instanceof WP_Post ) {
            $cache_key = 'ligase_' . (int) $queried->ID . '_' . get_locale() . '_' . LIGASE_VERSION;
        } elseif ( $queried instanceof WP_Term ) {
            $cache_key = 'ligase_term_' . (int) $queried->term_id . '_' . get_locale() . '_' . LIGASE_VERSION;
        } elseif ( $queried instanceof WP_User ) {
            $cache_key = 'ligase_user_' . (int) $queried->ID . '_' . get_locale() . '_' . LIGASE_VERSION;
        } else {
            $context = is_search() ? 'search'
                : ( is_home() ? 'home'
                    : ( is_front_page() ? 'front'
                        : ( is_date() ? 'date' : 'other' ) ) );
            $cache_key = 'ligase_ctx_' . $context . '_' . get_locale() . '_' . LIGASE_VERSION;
        }
        $post_id = ( $queried instanceof WP_Post ) ? (int) $queried->ID : 0;
        $cached    = Ligase_Cache::get( $cache_key );

        if ( false !== $cached ) {
            echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        // Check if auditor flagged this post for own schema generation
        if ( $post_id && self::needs_own_schema( $post_id ) ) {
            Ligase_Logger::info( 'Generating replacement schema for audited post', [ 'post_id' => $post_id ] );
        }

        $graph = ( new Ligase_Generator() )->get_graph();
        if ( empty( $graph ) ) {
            return;
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        ];

        // Intentionally NOT using JSON_UNESCAPED_SLASHES — that flag leaves "/" un-escaped,
        // which means "</script>" inside any string value would close the JSON-LD container.
        // Escaping slashes is the cheapest defense; the str_replace below is belt-and-braces.
        $json = wp_json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        if ( false === $json || json_last_error() !== JSON_ERROR_NONE ) {
            Ligase_Logger::error( 'Schema JSON encoding failed', [
                'post_id' => $post_id,
                'error'   => json_last_error_msg(),
            ] );
            return;
        }

        // Prevent JSON-LD container break-out via literal "</script>" in any text field.
        // wp_json_encode does not escape this substring; without the replace, a stored post
        // body containing </script> escapes the JSON-LD <script> tag and becomes a stored XSS.
        $json = str_replace( [ '</', '<!--' ], [ '<\/', '<\!--' ], $json );

        $html = sprintf(
            "<script type=\"application/ld+json\">\n%s\n</script>\n",
            $json
        );

        Ligase_Cache::set( $cache_key, $html );
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Check if auditor flagged this post to need own schema (replacement).
     */
    private static function needs_own_schema( int $post_id ): bool {
        $flag = get_post_meta( $post_id, '_ligase_needs_own_schema', true );
        return $flag === '1';
    }

    private static function should_render(): bool {
        $opts = get_option( 'ligase_options', [] );

        // Force output — always render regardless of conflicts
        if ( ! empty( $opts['force_output'] ) ) {
            return true;
        }

        // Standalone mode — suppress other plugins, always render
        if ( ! empty( $opts['standalone_mode'] ) ) {
            return true;
        }

        // Default mode — don't render if another SEO plugin outputs schema
        if ( class_exists( 'Ligase_Suppressor' ) ) {
            $suppressor = new Ligase_Suppressor();
            $active = $suppressor->get_active_seo_plugins();
            if ( ! empty( $active ) ) {
                Ligase_Logger::info( 'Schema output skipped — active SEO plugins detected', [
                    'plugins' => array_column( $active, 'name' ),
                ] );
                return false;
            }
        }

        return true;
    }

    /**
     * Run suppressor early (called from init_hooks at plugins_loaded).
     * Must run before other SEO plugins register their wp_head output.
     */
    public static function maybe_suppress_early(): void {
        $opts = get_option( 'ligase_options', [] );

        if ( empty( $opts['standalone_mode'] ) ) {
            return;
        }

        if ( class_exists( 'Ligase_Suppressor' ) ) {
            $suppressor = new Ligase_Suppressor();
            $suppressor->suppress_all();
        }
    }
}
