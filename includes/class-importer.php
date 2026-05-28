<?php
/**
 * Ligase Importer
 *
 * One-click import of settings from Yoast SEO, Rank Math, and All in One SEO.
 * Maps their options to Ligase format.
 *
 * @package Ligase
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Importer {

    /**
     * Available import sources.
     */
    const SOURCES = [
        'yoast'    => 'Yoast SEO',
        'rankmath' => 'Rank Math',
        'aioseo'   => 'All in One SEO',
    ];

    /**
     * Detect which importable plugins have data.
     *
     * @return array<string, array{name: string, available: bool}>
     */
    public function detect_sources(): array {
        $sources = [];

        // Yoast
        $yoast_social = get_option( 'wpseo_social', [] );
        $yoast_titles = get_option( 'wpseo_titles', [] );
        $sources['yoast'] = [
            'name'      => 'Yoast SEO',
            'available' => ! empty( $yoast_social ) || ! empty( $yoast_titles ),
        ];

        // Rank Math
        $rm_titles = get_option( 'rank-math-options-titles', [] );
        $rm_general = get_option( 'rank-math-options-general', [] );
        $sources['rankmath'] = [
            'name'      => 'Rank Math',
            'available' => ! empty( $rm_titles ) || ! empty( $rm_general ),
        ];

        // AIOSEO
        $aioseo = get_option( 'aioseo_options', '' );
        $sources['aioseo'] = [
            'name'      => 'All in One SEO',
            'available' => ! empty( $aioseo ),
        ];

        return $sources;
    }

    /**
     * Resolve an image value (which may be a URL, attachment ID, or attachment array)
     * into a URL. Yoast/Rank Math/AIOSEO all store logos differently across versions:
     *   - Yoast v13-: string URL
     *   - Yoast v14+: attachment ID (numeric string)
     *   - Rank Math v1.0.49-: string URL
     *   - Rank Math v1.0.50+: array [ 'id' => N, 'url' => '...' ]
     *   - AIOSEO: usually URL string but sometimes attachment ID
     */
    private function resolve_image_url( $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        if ( is_array( $value ) ) {
            if ( ! empty( $value['url'] ) ) {
                return (string) $value['url'];
            }
            $id_candidate = $value['id'] ?? $value['ID'] ?? null;
            if ( $id_candidate && is_numeric( $id_candidate ) ) {
                $url = wp_get_attachment_image_url( (int) $id_candidate, 'full' );
                return $url ?: '';
            }
            return '';
        }
        if ( is_numeric( $value ) ) {
            $url = wp_get_attachment_image_url( (int) $value, 'full' );
            return $url ?: '';
        }
        return (string) $value;
    }

    /**
     * Run import from a given source.
     *
     * @param string $source Source key (yoast, rankmath, aioseo).
     * @return array{imported: int, skipped: int, details: array}
     */
    public function import( string $source ): array {
        return match ( $source ) {
            'yoast'    => $this->import_yoast(),
            'rankmath' => $this->import_rankmath(),
            'aioseo'   => $this->import_aioseo(),
            default    => [ 'imported' => 0, 'skipped' => 0, 'details' => [ 'Unknown source.' ] ],
        };
    }

    private function import_yoast(): array {
        $social  = get_option( 'wpseo_social', [] );
        $titles  = get_option( 'wpseo_titles', [] );
        $opts    = get_option( 'ligase_options', [] );
        $details = [];
        $imported = 0;
        $skipped  = 0;

        // Organization name
        if ( ! empty( $titles['company_name'] ) && empty( $opts['org_name'] ) ) {
            $opts['org_name'] = sanitize_text_field( $titles['company_name'] );
            $details[] = 'Nazwa organizacji: ' . $opts['org_name'];
            $imported++;
        } else { $skipped++; }

        // Logo — Yoast v14+ stores attachment ID, not URL.
        if ( ! empty( $titles['company_logo'] ) && empty( $opts['org_logo'] ) ) {
            $logo_url = $this->resolve_image_url( $titles['company_logo'] );
            if ( $logo_url ) {
                $opts['org_logo'] = esc_url_raw( $logo_url );
                $details[] = 'Logo organizacji zaimportowane.';
                $imported++;
            } else { $skipped++; }
        } else { $skipped++; }

        // Social links -> sameAs
        $social_map = [
            'facebook_site'  => 'social_facebook',
            'twitter_site'   => 'social_twitter',
            'linkedin_url'   => 'social_linkedin',
            'youtube_url'    => 'social_youtube',
            'wikipedia_url'  => 'social_wikipedia',
        ];

        foreach ( $social_map as $yoast_key => $ligase_key ) {
            $value = $social[ $yoast_key ] ?? '';
            if ( ! empty( $value ) && empty( $opts[ $ligase_key ] ) ) {
                // Twitter might be just username
                if ( $yoast_key === 'twitter_site' && ! str_starts_with( $value, 'http' ) ) {
                    $value = 'https://twitter.com/' . ltrim( $value, '@' );
                }
                $opts[ $ligase_key ] = esc_url_raw( $value );
                $details[] = ucfirst( str_replace( 'social_', '', $ligase_key ) ) . ': ' . $opts[ $ligase_key ];
                $imported++;
            } else { $skipped++; }
        }

        // Author meta from Yoast user meta
        $authors = get_users( [ 'has_published_posts' => true, 'fields' => 'ID' ] );
        foreach ( $authors as $uid ) {
            $tw = get_user_meta( $uid, 'twitter', true );
            if ( $tw && ! get_user_meta( $uid, 'ligase_twitter', true ) ) {
                $url = str_starts_with( $tw, 'http' ) ? $tw : 'https://twitter.com/' . ltrim( $tw, '@' );
                update_user_meta( $uid, 'ligase_twitter', esc_url_raw( $url ) );
                $imported++;
            }
            $fb = get_user_meta( $uid, 'facebook', true );
            if ( $fb && ! get_user_meta( $uid, 'ligase_linkedin', true ) ) {
                // Yoast stores Facebook, we map to whatever is available
            }
        }

        update_option( 'ligase_options', $opts );

        Ligase_Logger::info( 'Yoast SEO import completed', [ 'imported' => $imported, 'skipped' => $skipped ] );

        return compact( 'imported', 'skipped', 'details' );
    }

    private function import_rankmath(): array {
        $titles  = get_option( 'rank-math-options-titles', [] );
        $general = get_option( 'rank-math-options-general', [] );
        $opts    = get_option( 'ligase_options', [] );
        $details = [];
        $imported = 0;
        $skipped  = 0;

        // Org name
        if ( ! empty( $titles['knowledgegraph_name'] ) && empty( $opts['org_name'] ) ) {
            $opts['org_name'] = sanitize_text_field( $titles['knowledgegraph_name'] );
            $details[] = 'Nazwa organizacji: ' . $opts['org_name'];
            $imported++;
        } else { $skipped++; }

        // Logo — Rank Math v1.0.50+ stores [ 'id' => N, 'url' => '...' ] array.
        if ( ! empty( $titles['knowledgegraph_logo'] ) && empty( $opts['org_logo'] ) ) {
            $logo_url = $this->resolve_image_url( $titles['knowledgegraph_logo'] );
            if ( $logo_url ) {
                $opts['org_logo'] = esc_url_raw( $logo_url );
                $details[] = 'Logo organizacji zaimportowane.';
                $imported++;
            } else { $skipped++; }
        } else { $skipped++; }

        // Phone
        if ( ! empty( $titles['phone'] ) && empty( $opts['org_phone'] ) ) {
            $opts['org_phone'] = sanitize_text_field( $titles['phone'] );
            $details[] = 'Telefon: ' . $opts['org_phone'];
            $imported++;
        } else { $skipped++; }

        // Social
        $social_map = [
            'social_url_facebook'  => 'social_facebook',
            'social_url_twitter'   => 'social_twitter',
            'social_url_linkedin'  => 'social_linkedin',
            'social_url_youtube'   => 'social_youtube',
            'social_url_wikipedia' => 'social_wikipedia',
        ];

        foreach ( $social_map as $rm_key => $ligase_key ) {
            $value = $titles[ $rm_key ] ?? '';
            if ( ! empty( $value ) && empty( $opts[ $ligase_key ] ) ) {
                $opts[ $ligase_key ] = esc_url_raw( $value );
                $details[] = ucfirst( str_replace( 'social_', '', $ligase_key ) ) . ': ' . $opts[ $ligase_key ];
                $imported++;
            } else { $skipped++; }
        }

        update_option( 'ligase_options', $opts );

        Ligase_Logger::info( 'Rank Math import completed', [ 'imported' => $imported, 'skipped' => $skipped ] );

        return compact( 'imported', 'skipped', 'details' );
    }

    private function import_aioseo(): array {
        $raw    = get_option( 'aioseo_options', '' );
        // AIOSEO v3.x stores serialized PHP arrays; v4.x stores JSON. maybe_unserialize handles
        // both transparently (returns the array unchanged if it's not serialized).
        if ( is_string( $raw ) ) {
            $maybe_serialized = maybe_unserialize( $raw );
            if ( is_array( $maybe_serialized ) ) {
                $aioseo = $maybe_serialized;
            } else {
                $decoded = json_decode( $raw, true );
                $aioseo  = is_array( $decoded ) ? $decoded : [];
            }
        } else {
            $aioseo = is_array( $raw ) ? $raw : [];
        }
        $opts    = get_option( 'ligase_options', [] );
        $details = [];
        $imported = 0;
        $skipped  = 0;

        if ( empty( $aioseo ) ) {
            return [ 'imported' => 0, 'skipped' => 0, 'details' => [ 'Brak danych AIOSEO.' ] ];
        }

        // Org name
        $org_name = $aioseo['searchAppearance']['global']['schema']['organizationName'] ?? '';
        if ( $org_name && empty( $opts['org_name'] ) ) {
            $opts['org_name'] = sanitize_text_field( $org_name );
            $details[] = 'Nazwa organizacji: ' . $opts['org_name'];
            $imported++;
        } else { $skipped++; }

        // Logo — AIOSEO v4 may store URL or attachment ID. Fall back to schemaLogo path in v3.
        $logo = $aioseo['searchAppearance']['global']['schema']['organizationLogo']
            ?? ( $aioseo['schema']['organizationLogo'] ?? '' );
        if ( ! empty( $logo ) && empty( $opts['org_logo'] ) ) {
            $logo_url = $this->resolve_image_url( $logo );
            if ( $logo_url ) {
                $opts['org_logo'] = esc_url_raw( $logo_url );
                $details[] = 'Logo zaimportowane.';
                $imported++;
            } else { $skipped++; }
        } else { $skipped++; }

        // Social — AIOSEO v4.5+ moved profiles under social.profiles.urls.{platform}Url
        $social = $aioseo['social'] ?? [];
        $profiles_v45 = $social['profiles']['urls'] ?? null;
        $social_map = [
            'facebookUrl'  => 'social_facebook',
            'twitterUrl'   => 'social_twitter',
            'linkedinUrl'  => 'social_linkedin',
            'youtubeUrl'   => 'social_youtube',
            'wikipediaUrl' => 'social_wikipedia',
        ];

        foreach ( $social_map as $aio_key => $ligase_key ) {
            $value = '';
            if ( is_array( $profiles_v45 ) ) {
                $value = $profiles_v45[ $aio_key ] ?? '';
            }
            if ( ! $value ) {
                $profiles_legacy = $social['profiles'] ?? $social;
                $value           = is_array( $profiles_legacy ) ? ( $profiles_legacy[ $aio_key ] ?? '' ) : '';
            }
            if ( ! empty( $value ) && empty( $opts[ $ligase_key ] ) ) {
                $opts[ $ligase_key ] = esc_url_raw( $value );
                $details[] = ucfirst( str_replace( 'social_', '', $ligase_key ) ) . ': ' . $opts[ $ligase_key ];
                $imported++;
            } else { $skipped++; }
        }

        update_option( 'ligase_options', $opts );

        Ligase_Logger::info( 'AIOSEO import completed', [ 'imported' => $imported, 'skipped' => $skipped ] );

        return compact( 'imported', 'skipped', 'details' );
    }
}
