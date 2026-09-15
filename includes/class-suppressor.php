<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Suppressor {

    private array $suppressed = [];
    private static bool $is_active = false;

    /** Transient holding the last front-end page seen with foreign schema.org microdata. */
    const MICRODATA_FLAG = 'ligase_foreign_microdata';

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
        'woocommerce_core' => [
            // WooCommerce since 7.2 emits its own JSON-LD @graph (Product +
            // AggregateOffer + BreadcrumbList) directly via wp_footer, not
            // through any SEO plugin's hooks. On stores where Ligase already
            // emits a richer Product node, this duplicates schema and triggers
            // "Zduplikowane pole brand" + "Nieprawidlowy typ obiektu w polu
            // offers" in Rich Results Test (when Ligase emits Offer while WC
            // emits AggregateOffer for variable products).
            //
            // The four wc_structured_data_* filters wired here let WC build an
            // empty array for each known schema type, so the actual <script>
            // is rendered with `[]` and Google ignores it. The output-buffer
            // scrubber then strips even that empty fragment in standalone mode.
            'name'    => 'WooCommerce (core JSON-LD)',
            'detect'  => [ 'WC_VERSION', 'WooCommerce' ],
            'filters' => [
                [ 'woocommerce_structured_data_product',        '__return_empty_array' ],
                [ 'woocommerce_structured_data_review',         '__return_empty_array' ],
                [ 'woocommerce_structured_data_breadcrumblist', '__return_empty_array' ],
                [ 'woocommerce_structured_data_website',        '__return_empty_array' ],
            ],
            'jsonld_marker' => 'woocommerce',
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
        ob_start( array( __CLASS__, 'scrub_foreign_schema' ) );
    }

    /**
     * Output-buffer callback: run every scrubbing pass over the rendered page.
     *
     * JSON-LD first (cheap, targeted), microdata second (only when the page
     * actually carries schema.org microdata).
     */
    public static function scrub_foreign_schema( string $html ): string {
        $html = self::dedupe_breadcrumb_jsonld( $html );
        $html = self::strip_foreign_microdata( $html );

        return $html;
    }

    /**
     * Output-buffer callback. Receives the full rendered HTML, returns it with
     * duplicate BreadcrumbList JSON-LD scripts stripped. Defensive against
     * unparseable JSON (skipped untouched) and against multi-node @graph blocks
     * (passed through unchanged).
     */
    public static function dedupe_breadcrumb_jsonld( string $html ): string {
        $pattern = '#<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is';
        $kept_breadcrumb = false;
        // Schema types we'll dedupe when emitted as a standalone (non-@graph) node
        // by a foreign theme/plugin. Ligase emits all these inside its @graph block,
        // never as standalones, so any standalone here is competition.
        $deduped_types = array(
            'BreadcrumbList', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage',
            'WebSite', 'Organization', 'Product', 'FAQPage', 'HowTo', 'Recipe',
        );
        return (string) preg_replace_callback( $pattern, function ( array $m ) use ( &$kept_breadcrumb, $deduped_types ) {
            $body = trim( $m[1] );
            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                return $m[0]; // unparseable or non-JSON — leave alone
            }
            // @graph: don't touch — Ligase's main payload uses this shape
            if ( isset( $data['@graph'] ) ) {
                return $m[0];
            }
            // Inline single-node typed entity.
            if ( isset( $data['@type'] ) && is_string( $data['@type'] ) ) {
                // BreadcrumbList — keep first Ligase one (id #breadcrumb), drop the rest.
                if ( $data['@type'] === 'BreadcrumbList' ) {
                    $id = (string) ( $data['@id'] ?? '' );
                    $is_ligase = $id !== '' && substr( $id, -11 ) === '#breadcrumb';
                    if ( $is_ligase && ! $kept_breadcrumb ) {
                        $kept_breadcrumb = true;
                        return $m[0];
                    }
                    return '';
                }
                // Other duped types — if a standalone-typed node is a Ligase type that
                // we always emit inside our @graph, anything ELSE outside the graph
                // is by definition a foreign duplicate. Strip in standalone_mode.
                if ( in_array( $data['@type'], $deduped_types, true ) ) {
                    return '';
                }
            }
            // Untyped or unrecognised node — leave alone (safer than over-stripping).
            return $m[0];
        }, $html );
    }

    /**
     * Strip foreign schema.org MICRODATA from the rendered page.
     *
     * The JSON-LD scrubber above only ever saw `<script type="application/ld+json">`.
     * Themes that render breadcrumbs (or products, or reviews) as inline microdata —
     * `<div class="breadcrumbs" itemscope itemtype="https://schema.org/BreadcrumbList">` —
     * sailed straight through it, so a site running standalone_mode still shipped a
     * second, competing structured-data source that Ligase had no control over.
     *
     * Those theme breadcrumbs are usually half-finished (one ListItem, no `position`,
     * current page not marked up at all), which Search Console reports as an invalid
     * item and which costs the page its rich result even though the Ligase graph is
     * perfectly valid.
     *
     * Contract: in standalone_mode the user has declared Ligase the single source of
     * structured data, so on any page that carries schema.org microdata we remove the
     * microdata attributes wholesale — itemscope/itemtype/itemprop/itemid/itemref.
     * Visible markup, classes and text are untouched; only the machine-readable layer
     * goes. All-or-nothing per page is deliberate: leaving descendant `itemprop`s
     * behind (they carry no type of their own) would produce exactly the orphaned
     * half-items we're trying to get rid of.
     *
     * Not touched: pages without schema.org microdata, other vocabularies
     * (data-vocabulary.org and friends), and the contents of script/style/textarea.
     *
     * Opt out per site with the `strip_microdata` setting, or per request with the
     * `ligase_strip_foreign_microdata` filter.
     */
    public static function strip_foreign_microdata( string $html ): string {
        $opts = (array) get_option( 'ligase_options', array() );
        // Default ON: an install that never saw this setting still gets the fix.
        $enabled = ! array_key_exists( 'strip_microdata', $opts ) || ! empty( $opts['strip_microdata'] );

        $enabled = (bool) apply_filters( 'ligase_strip_foreign_microdata', $enabled );

        // Cheap early exits — most pages carry no microdata at all.
        if ( stripos( $html, 'itemtype' ) === false ) {
            return $html;
        }
        if ( ! preg_match( '#itemtype=["\']\s*https?://schema\.org/#i', $html ) ) {
            return $html;
        }

        if ( ! $enabled ) {
            // Stripping is off, but the competing markup is real — leave a flag for the
            // admin notice, so the owner learns why Search Console keeps reporting an
            // invalid item on a page whose JSON-LD is perfectly fine.
            self::flag_foreign_microdata();

            return $html;
        }

        // Keep script/style/textarea bodies verbatim — markup quoted inside JS or a
        // form field is content, not structured data.
        $parts = preg_split(
            '#(<(?:script|style|textarea)\b[^>]*>.*?</(?:script|style|textarea)>)#is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ( ! is_array( $parts ) ) {
            return $html;
        }

        foreach ( $parts as $i => $part ) {
            if ( $i % 2 === 1 ) {
                continue; // captured script/style/textarea block
            }
            $parts[ $i ] = self::strip_microdata_attributes( (string) $part );
        }

        return implode( '', $parts );
    }

    /**
     * Remove microdata attributes from every start tag in one HTML chunk.
     *
     * A tag whose itemtype points at a non-schema.org vocabulary is left entirely
     * alone, attributes and all.
     */
    private static function strip_microdata_attributes( string $chunk ): string {
        if ( stripos( $chunk, 'item' ) === false ) {
            return $chunk;
        }

        $out = preg_replace_callback(
            '#<[a-z][a-z0-9:-]*\b[^>]*>#i',
            static function ( array $m ): string {
                $tag = $m[0];

                if ( ! preg_match( '#\sitem(?:scope|type|prop|id|ref)\b#i', $tag ) ) {
                    return $tag;
                }

                // Foreign vocabulary — not ours to clean up.
                if ( preg_match( '#\sitemtype=(["\'])(.*?)\1#i', $tag, $t )
                    && ! preg_match( '#^\s*https?://schema\.org/#i', $t[2] ) ) {
                    return $tag;
                }

                // Quoted values first, then bare ones (itemprop=name).
                $tag = (string) preg_replace( '#\sitem(?:type|prop|id|ref)=(["\']).*?\1#i', '', $tag );
                $tag = (string) preg_replace( '#\sitem(?:type|prop|id|ref)=[^\s>]+#i', '', $tag );
                $tag = (string) preg_replace( '#\sitemscope(?:=(["\'])[^"\']*\1)?(?=[\s/>])#i', '', $tag );

                return $tag;
            },
            $chunk
        );

        // preg_replace_callback returns null on backtrack limit — keep the original.
        return is_string( $out ) ? $out : $chunk;
    }

    /**
     * Record that a front-end page carries foreign schema.org microdata.
     *
     * Throttled to one write per hour, and only reached when stripping is switched
     * off — the hot path for every other site stays free of DB writes.
     */
    private static function flag_foreign_microdata(): void {
        if ( get_transient( self::MICRODATA_FLAG ) ) {
            return;
        }

        // $wp->request is the path relative to the site root — right on subdirectory
        // installs too, where REQUEST_URI would double the prefix.
        $request = isset( $GLOBALS['wp']->request ) ? (string) $GLOBALS['wp']->request : '';

        set_transient(
            self::MICRODATA_FLAG,
            array(
                'url'  => home_url( $request === '' ? '/' : '/' . ltrim( $request, '/' ) ),
                'time' => time(),
            ),
            HOUR_IN_SECONDS
        );
    }
}
