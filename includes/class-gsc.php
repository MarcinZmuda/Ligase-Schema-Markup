<?php
/**
 * Ligase Google Search Console Integration
 *
 * Service Account JWT auth (same pattern as Loom).
 * No OAuth redirect — user pastes Service Account JSON.
 * AES-256-CBC encrypted credential storage.
 *
 * @package Ligase
 */

defined( 'ABSPATH' ) || exit;

class Ligase_GSC {

    const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    const API_BASE      = 'https://searchconsole.googleapis.com/v1/';
    const SCOPE         = 'https://www.googleapis.com/auth/webmasters.readonly';
    const OPTION_KEY    = 'ligase_gsc_service_account';
    const SITE_OPTION   = 'ligase_gsc_site_url';
    const TOKEN_CACHE   = 'ligase_gsc_access_token';

    // =========================================================================
    // Credential Storage (AES-256-CBC)
    // =========================================================================

    /**
     * Save Service Account JSON (encrypted).
     *
     * @param string $json_string Raw JSON from Google Cloud Console.
     * @return true|WP_Error
     */
    public static function save_service_account( string $json_string ) {
        $data = json_decode( $json_string, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new \WP_Error( 'invalid_json', 'Nieprawidlowy format JSON.' );
        }

        if ( ( $data['type'] ?? '' ) !== 'service_account' ) {
            return new \WP_Error( 'wrong_type', 'Plik nie jest kluczem Service Account (type != service_account).' );
        }

        if ( empty( $data['client_email'] ) || empty( $data['private_key'] ) ) {
            return new \WP_Error( 'missing_fields', 'Brak client_email lub private_key w pliku.' );
        }

        // Store only what we need
        $to_store = wp_json_encode( [
            'client_email' => $data['client_email'],
            'private_key'  => $data['private_key'],
            'project_id'   => $data['project_id'] ?? '',
        ] );

        $encrypted = self::encrypt( $to_store );
        update_option( self::OPTION_KEY, $encrypted );
        delete_transient( self::TOKEN_CACHE );

        Ligase_Logger::info( 'GSC Service Account saved.', [ 'email' => $data['client_email'] ] );

        return true;
    }

    /**
     * Get decrypted Service Account data.
     *
     * @return array{client_email: string, private_key: string, project_id: string}
     */
    private static function get_service_account(): array {
        $stored = get_option( self::OPTION_KEY, '' );
        if ( empty( $stored ) ) {
            return [];
        }

        $decrypted = self::decrypt( $stored );
        if ( ! $decrypted ) {
            return [];
        }

        return json_decode( $decrypted, true ) ?: [];
    }

    /**
     * Check if GSC credentials are configured.
     */
    public static function is_configured(): bool {
        $sa = self::get_service_account();
        return ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] );
    }

    /**
     * Delete stored credentials.
     */
    public static function disconnect(): void {
        delete_option( self::OPTION_KEY );
        delete_option( self::SITE_OPTION );
        delete_transient( self::TOKEN_CACHE );
        Ligase_Logger::info( 'GSC disconnected.' );
    }

    // =========================================================================
    // JWT Bearer Token
    // =========================================================================

    /**
     * Get a valid access token (cached ~55 min).
     *
     * @return string|WP_Error
     */
    public static function get_access_token() {
        $cached = get_transient( self::TOKEN_CACHE );
        if ( $cached ) {
            return $cached;
        }

        $sa = self::get_service_account();
        if ( empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
            return new \WP_Error( 'no_credentials', 'Brak skonfigurowanych credentials GSC.' );
        }

        // Build JWT
        $now    = time();
        $header = self::base64url_encode( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) );
        $claim  = self::base64url_encode( wp_json_encode( [
            'iss'   => $sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'exp'   => $now + 3600,
            'iat'   => $now,
        ] ) );

        $signature_input = $header . '.' . $claim;

        // RS256 signature
        $signed = '';
        $ok = openssl_sign( $signature_input, $signed, $sa['private_key'], 'SHA256' );

        if ( ! $ok ) {
            Ligase_Logger::error( 'GSC JWT signing failed.' );
            return new \WP_Error( 'sign_failed', 'Blad podpisu JWT. Sprawdz private_key.' );
        }

        $jwt = $signature_input . '.' . self::base64url_encode( $signed );

        // Exchange JWT for access token
        $response = wp_remote_post( self::TOKEN_URL, [
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            Ligase_Logger::error( 'GSC token request failed.', [ 'error' => $response->get_error_message() ] );
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            $error_msg = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            Ligase_Logger::error( 'GSC token response error.', [ 'error' => $error_msg ] );
            return new \WP_Error( 'token_failed', $error_msg );
        }

        $token   = $body['access_token'];
        $expires = (int) ( $body['expires_in'] ?? 3600 );

        set_transient( self::TOKEN_CACHE, $token, $expires - 300 ); // Cache ~55 min

        return $token;
    }

    // =========================================================================
    // API Calls
    // =========================================================================

    /**
     * Get the configured site URL for GSC.
     */
    public static function get_site_url(): string {
        return get_option( self::SITE_OPTION, home_url( '/' ) );
    }

    /**
     * Set the site URL.
     */
    public static function set_site_url( string $url ): void {
        update_option( self::SITE_OPTION, esc_url_raw( $url ) );
    }

    /**
     * List all sites the Service Account has access to.
     *
     * @return array|WP_Error
     */
    public static function list_sites() {
        return self::api_get( 'sites' );
    }

    /**
     * Query search analytics (clicks, impressions, CTR, position).
     *
     * @param array $params Query parameters.
     * @return array|WP_Error
     */
    public static function search_analytics( array $params = [] ) {
        $site_url = self::get_site_url();

        $defaults = [
            'startDate'  => wp_date( 'Y-m-d', strtotime( '-28 days' ) ),
            'endDate'    => wp_date( 'Y-m-d', strtotime( '-1 day' ) ),
            'dimensions' => [ 'page' ],
            'rowLimit'   => 100,
            'type'       => 'web',
        ];

        $body = array_merge( $defaults, $params );

        return self::api_post(
            'sites/' . urlencode( $site_url ) . '/searchAnalytics/query',
            $body
        );
    }

    /**
     * Get rich results data (search appearance).
     *
     * @return array|WP_Error
     */
    public static function get_rich_results_data() {
        // The Search Console API rejects (HTTP 400) any request that groups
        // searchAppearance together with another dimension, and it also forbids
        // filtering by searchAppearance. The previous query did both, so this
        // report never returned data. searchAppearance can only be queried on its
        // own — which yields the rich-result breakdown per appearance type.
        return self::search_analytics( [
            'dimensions' => [ 'searchAppearance' ],
            'rowLimit'   => 500,
        ] );
    }

    /**
     * Get pages with their GSC metrics.
     *
     * @return array Associative array keyed by URL.
     */
    public static function get_page_metrics(): array {
        $result = self::search_analytics( [
            'dimensions' => [ 'page' ],
            'rowLimit'   => 1000,
        ] );

        if ( is_wp_error( $result ) || empty( $result['rows'] ) ) {
            return [];
        }

        $pages = [];
        foreach ( $result['rows'] as $row ) {
            $url = $row['keys'][0] ?? '';
            if ( $url ) {
                $pages[ $url ] = [
                    'clicks'      => (int) ( $row['clicks'] ?? 0 ),
                    'impressions' => (int) ( $row['impressions'] ?? 0 ),
                    'ctr'         => round( (float) ( $row['ctr'] ?? 0 ), 4 ),
                    'position'    => round( (float) ( $row['position'] ?? 0 ), 1 ),
                ];
            }
        }

        return $pages;
    }

    /**
     * Sync GSC data to post meta for dashboard use.
     *
     * @return array{synced: int, errors: int}
     */
    public static function sync_to_posts(): array {
        $pages = self::get_page_metrics();

        if ( empty( $pages ) ) {
            return [ 'synced' => 0, 'errors' => 0 ];
        }

        $synced = 0;
        $errors = 0;

        foreach ( $pages as $url => $metrics ) {
            $post_id = url_to_postid( $url );
            if ( ! $post_id ) {
                continue;
            }

            update_post_meta( $post_id, '_ligase_gsc_clicks', $metrics['clicks'] );
            update_post_meta( $post_id, '_ligase_gsc_impressions', $metrics['impressions'] );
            update_post_meta( $post_id, '_ligase_gsc_ctr', $metrics['ctr'] );
            update_post_meta( $post_id, '_ligase_gsc_position', $metrics['position'] );
            update_post_meta( $post_id, '_ligase_gsc_last_sync', wp_date( 'Y-m-d H:i:s' ) );
            $synced++;
        }

        Ligase_Logger::info( 'GSC sync complete.', [ 'synced' => $synced ] );

        return [ 'synced' => $synced, 'errors' => $errors ];
    }

    // =========================================================================
    // HTTP Helpers
    // =========================================================================

    private static function api_get( string $endpoint ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_get( self::API_BASE . $endpoint, [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
    }

    private static function api_post( string $endpoint, array $body ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $response = wp_remote_post( self::API_BASE . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
    }

    // =========================================================================
    // Encryption (AES-256-CBC, same pattern as Loom)
    // =========================================================================

    private static function encrypt( string $plaintext ): string {
        $key = wp_salt( 'auth' );
        $iv  = openssl_random_pseudo_bytes( 16 );
        $enc = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $enc );
    }

    private static function decrypt( string $stored ): string {
        $key = wp_salt( 'auth' );
        $raw = base64_decode( $stored );

        if ( strlen( $raw ) <= 16 ) {
            return '';
        }

        $iv     = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $dec    = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        return $dec !== false ? $dec : '';
    }

    private static function base64url_encode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
