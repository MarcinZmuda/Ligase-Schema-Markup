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

    public static function is_active(): bool {
        return self::$is_active;
    }
}
