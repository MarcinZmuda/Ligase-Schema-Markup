<?php
/**
 * Ligase - JobPosting schema type
 *
 * Powers Google Jobs (search.google.com/jobs) rich result. Required by 2026:
 *   title, description, datePosted, validThrough, hiringOrganization.name,
 *   jobLocation.address.addressLocality + addressCountry  (or jobLocationType=TELECOMMUTE).
 *
 * Google auto-removes JobPostings whose `validThrough` is in the past. This class
 * also returns null after `validThrough` so the schema disappears the moment the
 * job expires — important to avoid stale Job search results.
 *
 * @package Ligase
 * @since   2.3.0
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Type_JobPosting {

    public function build(): ?array {
        if ( ! is_singular() ) {
            return null;
        }

        $post_id = get_the_ID();

        // Auto-detect a `job_listing` / `job` / `jobs` CPT in addition to the manual flag.
        $pt   = get_post_type( $post_id );
        $cpt  = in_array( $pt, array( 'job_listing', 'job', 'jobs' ), true );
        $flag = get_post_meta( $post_id, '_ligase_enable_jobposting', true ) === '1';
        $rule = Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_jobposting', $post_id );

        if ( ! $cpt && ! $flag && ! $rule ) {
            return null;
        }

        if ( ! class_exists( 'Ligase_Field_Resolver' ) ) {
            return null;
        }

        $resolved = ( new Ligase_Field_Resolver() )->resolve( 'JobPosting', $post_id );
        $node     = $resolved['node'];

        // Apply flat-meta UI overrides on top of resolver output (see Recipe class
        // for the same pattern). Whitelisted keys mirror the metabox fields.
        $manual = (array) ( get_post_meta( $post_id, '_ligase_jobposting', true ) ?: array() );
        if ( ! empty( $manual ) ) {
            // Top-level scalars
            foreach ( array( 'title', 'description', 'datePosted', 'validThrough', 'employmentType', 'jobLocationType' ) as $k ) {
                if ( ! empty( $manual[ $k ] ) ) {
                    $node[ $k ] = is_string( $manual[ $k ] ) ? wp_strip_all_tags( $manual[ $k ] ) : $manual[ $k ];
                }
            }
            // directApply → real boolean
            if ( isset( $manual['directApply'] ) && $manual['directApply'] !== '' ) {
                $node['directApply'] = filter_var( $manual['directApply'], FILTER_VALIDATE_BOOLEAN );
            }
            // hiringOrganization
            $org = array();
            if ( ! empty( $manual['hiringOrgName'] ) ) {
                $org['@type'] = 'Organization';
                $org['name']  = wp_strip_all_tags( (string) $manual['hiringOrgName'] );
            }
            if ( ! empty( $manual['hiringOrgUrl'] ) ) {
                $org['@type'] = $org['@type'] ?? 'Organization';
                $org['sameAs'] = esc_url_raw( (string) $manual['hiringOrgUrl'] );
            }
            if ( $org ) {
                $node['hiringOrganization'] = $org;
            }
            // jobLocation
            if ( ! empty( $manual['jobLocationCity'] ) || ! empty( $manual['jobLocationCountry'] ) ) {
                $addr = array( '@type' => 'PostalAddress' );
                if ( ! empty( $manual['jobLocationCity'] ) ) {
                    $addr['addressLocality'] = wp_strip_all_tags( (string) $manual['jobLocationCity'] );
                }
                if ( ! empty( $manual['jobLocationCountry'] ) ) {
                    $addr['addressCountry'] = strtoupper( substr( (string) $manual['jobLocationCountry'], 0, 2 ) );
                }
                $node['jobLocation'] = array(
                    '@type'   => 'Place',
                    'address' => $addr,
                );
            }
            // baseSalary range
            $sal_min = (string) ( $manual['salaryMin'] ?? '' );
            $sal_max = (string) ( $manual['salaryMax'] ?? '' );
            if ( $sal_min !== '' || $sal_max !== '' ) {
                $currency = strtoupper( (string) ( $manual['salaryCurrency'] ?? 'PLN' ) );
                $unit     = strtoupper( (string) ( $manual['salaryUnit'] ?? 'MONTH' ) );
                $value    = array( '@type' => 'QuantitativeValue', 'unitText' => $unit );
                if ( $sal_min !== '' ) {
                    $value['minValue'] = (float) $sal_min;
                }
                if ( $sal_max !== '' ) {
                    $value['maxValue'] = (float) $sal_max;
                }
                if ( $sal_min !== '' && $sal_max === '' ) {
                    $value['value'] = (float) $sal_min;
                }
                $node['baseSalary'] = array(
                    '@type'    => 'MonetaryAmount',
                    'currency' => $currency,
                    'value'    => $value,
                );
            }
        }

        if ( empty( $node['title'] ) || empty( $node['description'] ) ) {
            return null;
        }

        // Expire automatically when validThrough is in the past.
        if ( ! empty( $node['validThrough'] ) ) {
            $vt = strtotime( (string) $node['validThrough'] );
            if ( $vt && $vt < time() ) {
                if ( class_exists( 'Ligase_Logger' ) ) {
                    Ligase_Logger::info( 'JobPosting expired — schema omitted', [
                        'post_id'      => $post_id,
                        'valid_through' => $node['validThrough'],
                    ] );
                }
                return null;
            }
        }

        // Direct apply flag must be a real bool in the JSON (schema.org accepts true/false).
        if ( isset( $node['directApply'] ) ) {
            $node['directApply'] = filter_var( $node['directApply'], FILTER_VALIDATE_BOOLEAN );
        }

        $node['@id'] = esc_url( get_permalink( $post_id ) ) . '#jobposting';

        return apply_filters( 'ligase_jobposting', $node, $post_id );
    }
}
