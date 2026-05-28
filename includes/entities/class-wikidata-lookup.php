<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Wikidata_Lookup {

    const API_URL          = 'https://www.wikidata.org/w/api.php';
    const CACHE_KEY        = 'ligase_wiki_';
    const TTL              = WEEK_IN_SECONDS * 4;
    const NEGATIVE_TTL     = HOUR_IN_SECONDS * 6; // negative cache 6h, not 4 weeks
    const USER_AGENT       = 'Ligase/2.0 (WordPress plugin; https://marcinzmuda.com/ligase; entity linking for schema.org)';

    /**
     * Search Wikidata for an entity by name. Tries the site locale's language first,
     * then falls back to English so names like "Cloudflare" / "Stripe" that exist only
     * in en-Wikidata are still resolved on Polish sites.
     */
    public function search( string $name, ?string $language = null ): ?array {
        $name = mb_substr( trim( $name ), 0, 200 );
        if ( empty( $name ) ) {
            return null;
        }

        if ( null === $language ) {
            $language = strtolower( substr( str_replace( '_', '-', get_locale() ), 0, 2 ) ) ?: 'en';
        }

        // Build the language fallback chain once.
        $languages = array_values( array_unique( [ $language, 'en' ] ) );

        foreach ( $languages as $lang ) {
            $hits = $this->search_single_language( $name, $lang );
            if ( ! empty( $hits ) ) {
                return $hits;
            }
        }
        return [];
    }

    private function search_single_language( string $name, string $language ): ?array {
        $cache_key = self::CACHE_KEY . md5( $name . '|' . $language );
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return is_array( $cached ) ? $cached : null;
        }

        $response = wp_remote_get( add_query_arg( [
            'action'   => 'wbsearchentities',
            'search'   => $name,
            'language' => $language,
            'format'   => 'json',
            'limit'    => 5,
            'type'     => 'item',
        ], self::API_URL ), [
            'timeout' => 3,
            'headers' => [ 'User-Agent' => self::USER_AGENT ],
        ] );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::error( 'Wikidata API error', [
                    'name'  => $name,
                    'lang'  => $language,
                    'error' => $response->get_error_message(),
                ] );
            }
            // Negative cache is intentionally SHORT (6h, not 4 weeks) so transient errors
            // don't lock out a name for a month. Recoverable failures should re-try soon.
            set_transient( $cache_key, [], 300 );
            return null;
        }

        $raw_body = wp_remote_retrieve_body( $response );
        $body     = json_decode( $raw_body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::error( 'Wikidata API JSON decode error', [
                    'name'  => $name,
                    'lang'  => $language,
                    'error' => json_last_error_msg(),
                ] );
            }
            return null;
        }
        $results = array_slice( $body['search'] ?? [], 0, 3 );

        if ( empty( $results ) ) {
            set_transient( $cache_key, [], self::NEGATIVE_TTL );
            return [];
        }

        $mapped = array_map( fn( $r ) => [
            'id'          => $r['id'],
            'label'       => $r['label'] ?? '',
            'description' => $r['description'] ?? '',
            'url'         => "https://www.wikidata.org/wiki/{$r['id']}",
        ], $results );

        set_transient( $cache_key, $mapped, self::TTL );
        return $mapped;
    }

    public static function run_lookup( int $post_id, array $entity_names ): void {
        $lookup  = new self();
        $results = [];

        foreach ( $entity_names as $name ) {
            $hits = $lookup->search( $name );
            if ( ! empty( $hits ) ) {
                $results[ $name ] = $hits;
            }
        }

        if ( ! empty( $results ) ) {
            update_post_meta( $post_id, '_ligase_wikidata_suggestions', $results );
            if ( class_exists( 'Ligase_Logger' ) ) {
                Ligase_Logger::info( 'Wikidata lookup completed', [
                    'post_id'      => $post_id,
                    'entities'     => count( $entity_names ),
                    'matches'      => count( $results ),
                ] );
            }
        }
    }

    public function schedule( int $post_id, array $names ): void {
        if ( count( $names ) > 20 ) {
            $names = array_slice( $names, 0, 20 );
        }
        wp_schedule_single_event(
            time() + 5,
            'ligase_wikidata_lookup',
            [ $post_id, $names ]
        );
    }
}
