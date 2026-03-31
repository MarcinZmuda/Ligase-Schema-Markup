<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Suppressor {

    private array $suppressed = [];
    private static bool $is_active = false;

    /**
     * Known SEO plugins and their schema output filters.
     * Updated dynamically via get_active_seo_plugins().
     */
    const KNOWN_PLUGINS = [
        'yoast' => [
            'name'    => 'Yoast SEO',
            'detect'  => [ 'WPSEO_VERSION', 'Yoast\\WP\\SEO\\Main' ],
            'filters' => [
                [ 'wpseo_json_ld_output', '__return_false' ],
                [ 'wpseo_schema_graph_pieces', '__return_empty_array' ],
            ],
        ],
        'aioseo' => [
            'name'    => 'All in One SEO',
            'detect'  => [ 'AIOSEO_VERSION', 'AIOSEO\\Plugin\\AIOSEO' ],
            'filters' => [
                [ 'aioseo_schema_output', '__return_false' ],
                [ 'aioseo_schema_graph', '__return_empty_array' ],
            ],
        ],
        'rankmath' => [
            'name'    => 'Rank Math',
            'detect'  => [ 'RANK_MATH_VERSION', 'RankMath' ],
            'filters' => [
                [ 'rank_math/json_ld/disable', '__return_true' ],
                [ 'rank_math/schema/post_schemas', '__return_empty_array' ],
            ],
        ],
        'seopress' => [
            'name'    => 'SEOPress',
            'detect'  => [ 'SEOPRESS_VERSION' ],
            'filters' => [
                [ 'seopress_schemas_output', '__return_false' ],
            ],
        ],
        'the_events_calendar' => [
            'name'    => 'The Events Calendar',
            'detect'  => [ 'TEC_VERSION', 'Tribe__Events__Main' ],
            'filters' => [
                [ 'tribe_events_jsonld_enabled', '__return_false' ],
            ],
        ],
        'the_seo_framework' => [
            'name'    => 'The SEO Framework',
            'detect'  => [ 'THE_SEO_FRAMEWORK_VERSION', 'The_SEO_Framework\Bootstrap' ],
            'filters' => [
                [ 'the_seo_framework_schema_output', '__return_false' ],
            ],
        ],
        'slim_seo' => [
            'name'    => 'Slim SEO',
            'detect'  => [ 'SLIM_SEO_VER', 'SlimSEO\Slim_SEO' ],
            'filters' => [],
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
