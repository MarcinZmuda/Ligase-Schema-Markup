<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Suppressor {

    private array $suppressed = [];
    private static bool $is_active = false;

    /**
     * Known SEO plugins and their schema output filters.
     * Updated dynamically via get_active_seo_plugins().
     */
    /**
     * Filter hooks per competitor are listed in order of preference. Multiple per plugin
     * because each plugin has changed hook names across versions — we register all known
     * hook names; only the ones that exist will fire.
     *
     * Belt-and-suspenders: when none of these neutralizes the output, the runtime
     * output-buffer scrubber in scrub_head_jsonld() strips remaining competitor
     * JSON-LD <script> tags from wp_head as a final fallback.
     */
    const KNOWN_PLUGINS = [
        'yoast' => [
            'name'    => 'Yoast SEO',
            'detect'  => [ 'WPSEO_VERSION', 'Yoast\\WP\\SEO\\Main' ],
            'filters' => [
                // Modern (v14+) — returns the graph array
                [ 'wpseo_schema_graph', '__return_empty_array' ],
                // Modern — returns pieces before graph assembly
                [ 'wpseo_schema_graph_pieces', '__return_empty_array' ],
                // Legacy — returns final <script> string
                [ 'wpseo_json_ld_output', '__return_false' ],
                // Yoast 21+ — BreadcrumbList survived wpseo_schema_graph cut in some
                // production sites (theme override / TEC integration). Belt the
                // breadcrumb-specific generators directly.
                [ 'wpseo_schema_breadcrumb', '__return_empty_array' ],
                [ 'wpseo_schema_breadcrumb_list_show', '__return_false' ],
                [ 'wpseo_should_output_breadcrumbs_schema', '__return_false' ],
                // Yoast 27.x explicit per-type generator hook
                [ 'wpseo_schema_BreadcrumbList', '__return_empty_array' ],
            ],
            'jsonld_marker' => 'yoast', // for scrubber
        ],
        'aioseo' => [
            'name'    => 'All in One SEO',
            'detect'  => [ 'AIOSEO_VERSION', 'AIOSEO\\Plugin\\AIOSEO' ],
            'filters' => [
                // AIOSEO v4 — recommended way to fully disable
                [ 'aioseo_schema_disable', '__return_true' ],
                // Defensive — filter that returns the graph
                [ 'aioseo_schema_graph', '__return_empty_array' ],
                // Legacy
                [ 'aioseo_schema_output', '__return_false' ],
            ],
            'jsonld_marker' => 'aioseo',
        ],
        'rankmath' => [
            'name'    => 'Rank Math',
            'detect'  => [ 'RANK_MATH_VERSION', 'RankMath' ],
            'filters' => [
                // Modern — returns the full JSON-LD array
                [ 'rank_math/json_ld', '__return_empty_array' ],
                // Legacy
                [ 'rank_math/json_ld/disable', '__return_true' ],
                // Per-type
                [ 'rank_math/snippet/rich_snippet_blogposting_entity', '__return_empty_array' ],
                [ 'rank_math/snippet/rich_snippet_article_entity', '__return_empty_array' ],
            ],
            'jsonld_marker' => 'rank-math',
        ],
        'seopress' => [
            'name'    => 'SEOPress',
            'detect'  => [ 'SEOPRESS_VERSION' ],
            'filters' => [
                [ 'seopress_schemas_single_json', '__return_empty_array' ],
                [ 'seopress_schemas_archive_json', '__return_empty_array' ],
                [ 'seopress_pro_schemas_single_json', '__return_empty_array' ],
                [ 'seopress_schemas_output', '__return_false' ],
            ],
            'jsonld_marker' => 'seopress',
        ],
        'the_events_calendar' => [
            'name'    => 'The Events Calendar',
            'detect'  => [ 'TEC_VERSION', 'Tribe__Events__Main' ],
            'filters' => [
                [ 'tribe_events_jsonld_enabled', '__return_false' ],
                [ 'tribe_json_ld_data', '__return_empty_array' ],
            ],
            'jsonld_marker' => 'tribe',
        ],
        'the_seo_framework' => [
            'name'    => 'The SEO Framework',
            'detect'  => [ 'THE_SEO_FRAMEWORK_VERSION', 'The_SEO_Framework\\Bootstrap' ],
            'filters' => [
                // TSF v5 returns the full LD-JSON array
                [ 'the_seo_framework_ld_json_scripts', '__return_empty_array' ],
                [ 'the_seo_framework_ld_json_output', '__return_false' ],
                // Older
                [ 'the_seo_framework_schema_output', '__return_false' ],
            ],
            'jsonld_marker' => 'the_seo_framework',
        ],
        'slim_seo' => [
            'name'    => 'Slim SEO',
            'detect'  => [ 'SLIM_SEO_VER', 'SlimSEO\\Slim_SEO' ],
            'filters' => [
                [ 'slim_seo_schema_graph', '__return_empty_array' ],
                [ 'slim_seo_schema', '__return_empty_array' ],
            ],
            'jsonld_marker' => 'slim-seo',
        ],
    ];

    /**
     * Detect which SEO plugins are active using constants and class checks.
     * More reliable than hardcoded file paths.
     */
    public function get_active_seo_plugins(): array {
        $active = [];
        foreach ( self::KNOWN_PLUGINS as $id => $plugin ) {
            $detected = false;
            foreach ( $plugin['detect'] as $indicator ) {
                if ( defined( $indicator ) || class_exists( $indicator ) ) {
                    $detected = true;
                    break;
                }
            }
            if ( $detected ) {
                $version = 'unknown';
                foreach ( $plugin['detect'] as $indicator ) {
                    if ( defined( $indicator ) ) {
                        $version = constant( $indicator );
                        break;
                    }
                }
                $active[ $id ] = [
                    'name'    => $plugin['name'],
                    'version' => $version,
                ];
            }
        }
        return $active;
    }

    /**
     * Suppress schema output from detected plugins.
     * Returns list of suppressed plugin IDs.
     */
    public function suppress_all(): array {
        $active = $this->get_active_seo_plugins();

        foreach ( $active as $id => $info ) {
            if ( ! isset( self::KNOWN_PLUGINS[ $id ]['filters'] ) ) {
                continue;
            }
            foreach ( self::KNOWN_PLUGINS[ $id ]['filters'] as $filter ) {
                add_filter( $filter[0], $filter[1], 999 );
            }
            $this->suppressed[] = $id;
        }

        self::$is_active = true;

        if ( ! empty( $this->suppressed ) && class_exists( 'Ligase_Logger' ) ) {
            Ligase_Logger::info( 'Suppressed schema from plugins', [ 'plugins' => $this->suppressed ] );
        }

        return $this->suppressed;
    }

    /**
     * Restore schema output from suppressed plugins.
     * Call this to undo suppress_all().
     */
    public function restore_all(): void {
        foreach ( $this->suppressed as $id ) {
            if ( ! isset( self::KNOWN_PLUGINS[ $id ]['filters'] ) ) {
                continue;
            }
            foreach ( self::KNOWN_PLUGINS[ $id ]['filters'] as $filter ) {
                remove_filter( $filter[0], $filter[1], 999 );
            }
        }

        self::$is_active = false;
        $this->suppressed = [];

        if ( class_exists( 'Ligase_Logger' ) ) {
            Ligase_Logger::info( 'Restored schema output for all plugins' );
        }
    }

    public function get_suppressed(): array {
        return $this->suppressed;
    }

    /**
     * `Standalone Mode` is the user-controlled flag that actually drives output
     * decisions. Reading it fresh on every call avoids the FPM-worker leak of the
     * legacy `self::$is_active` static (which kept stale values across requests
     * if OPcache or persistent processes preserved class state).
     */
    public static function is_active(): bool {
        $opts = (array) get_option( 'ligase_options', array() );
        return ! empty( $opts['standalone_mode'] );
    }

    /**
     * Register the wp_head output-buffer scrubber for duplicate BreadcrumbList.
     *
     * Filters only catch hooks the competing emitter actually fires through. Many
     * WooCommerce themes (XStore / Flatsome / Woodmart / Avada) inject their own
     * `<script type="application/ld+json">{"@type":"BreadcrumbList",...}</script>`
     * directly via `wp_footer` or template parts — no filter exists to intercept.
     *
     * Strategy: wrap the page render in an output buffer, then on shutdown scan
     * for ALL JSON-LD blocks. Keep:
     *   - Ligase's BreadcrumbList (identified by `@id` ending in `#breadcrumb`)
     *   - All other JSON-LD (@graph blocks, Article, Product, etc.)
     * Strip:
     *   - Any other standalone BreadcrumbList scripts (the theme duplicates)
     *
     * Only active in standalone_mode — user has explicitly chosen Ligase as the
     * canonical schema source. Without that flag we wouldn't dare touch foreign
     * scripts.
     */
    public static function register_breadcrumb_scrubber(): void {
        if ( ! self::is_active() ) {
            return;
        }
        // template_redirect runs after WP knows the request type but before any
        // header is sent — the safest point to open a page-wide ob.
        add_action( 'template_redirect', array( __CLASS__, 'start_breadcrumb_buffer' ), 0 );
    }

    public static function start_breadcrumb_buffer(): void {
        // Skip admin / REST / AJAX / feeds — only frontend HTML gets scrubbed.
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
            return;
        }
        ob_start( array( __CLASS__, 'dedupe_breadcrumb_jsonld' ) );
    }

    /**
     * Output-buffer callback. Receives the full rendered HTML, returns it with
     * duplicate BreadcrumbList JSON-LD scripts stripped. Defensive against
     * unparseable JSON (skipped untouched) and against multi-node @graph blocks
     * (passed through unchanged).
     */
    public static function dedupe_breadcrumb_jsonld( string $html ): string {
        $pattern = '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is';
        $kept_ligase = false;
        return (string) preg_replace_callback( $pattern, function ( array $m ) use ( &$kept_ligase ) {
            $body = trim( $m[1] );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                return $m[0]; // unparseable or non-JSON — leave alone
            }
            // @graph: don't touch — Ligase's main payload uses this shape
            if ( isset( $data['@graph'] ) ) {
                return $m[0];
            }
            // Inline single-node BreadcrumbList
            if ( isset( $data['@type'] ) && $data['@type'] === 'BreadcrumbList' ) {
                $id = (string) ( $data['@id'] ?? '' );
                $is_ligase = $id !== '' && substr( $id, -11 ) === '#breadcrumb';
                if ( $is_ligase && ! $kept_ligase ) {
                    $kept_ligase = true;
                    return $m[0];
                }
                // Either: duplicate of Ligase BreadcrumbList, or a theme/plugin
                // BreadcrumbList we don't recognise. In standalone_mode we drop.
                return '';
            }
            // Any other single-type node passes through.
            return $m[0];
        }, $html );
    }
}
