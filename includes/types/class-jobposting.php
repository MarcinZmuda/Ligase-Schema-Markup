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
