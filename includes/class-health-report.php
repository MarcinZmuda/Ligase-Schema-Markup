<?php
/**
 * Ligase Health Report
 *
 * Weekly WP-Cron job that scans all posts and sends
 * a summary email to the site admin.
 *
 * @package Ligase
 */

defined( 'ABSPATH' ) || exit;

class Ligase_Health_Report {

    const CRON_HOOK = 'ligase_weekly_health_report';

    /**
     * Schedule the weekly cron event.
     */
    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
        }
    }

    /**
     * Unschedule on deactivation.
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
        }
    }

    /**
     * Run the health report scan and send email.
     */
    public static function run(): void {
        $opts = get_option( 'ligase_options', [] );

        // Only run if enabled
        if ( empty( $opts['health_report_enabled'] ) ) {
            return;
        }

        Ligase_Logger::info( 'Running weekly health report.' );

        $score_calc = new Ligase_Score();
        $site_score = $score_calc->calculate();

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ] );

        $issues = [
            'low_score'       => [],
            'no_image'        => [],
            'small_image'     => [],
            'old_modified'    => [],
            'no_excerpt'      => [],
            'no_tags'         => [],
        ];

        $one_year_ago = strtotime( '-1 year' );

        foreach ( $posts as $pid ) {
            $post_score = $score_calc->calculate_for_post( $pid );

            if ( $post_score['score'] < 50 ) {
                $issues['low_score'][] = $pid;
            }

            if ( ! has_post_thumbnail( $pid ) ) {
                $issues['no_image'][] = $pid;
            } else {
                $img = wp_get_attachment_image_src( get_post_thumbnail_id( $pid ), 'full' );
                if ( $img && (int) $img[1] < 1200 ) {
                    $issues['small_image'][] = $pid;
                }
            }

            $modified = get_the_modified_date( 'U', $pid );
            if ( $modified && (int) $modified < $one_year_ago ) {
                $issues['old_modified'][] = $pid;
            }

            $post = get_post( $pid );
            if ( $post && empty( $post->post_excerpt ) ) {
                $issues['no_excerpt'][] = $pid;
            }

            $tags = wp_get_post_tags( $pid, [ 'fields' => 'ids' ] );
            if ( empty( $tags ) ) {
                $issues['no_tags'][] = $pid;
            }
        }

        $total = count( $posts );
        $has_issues = false;
        foreach ( $issues as $list ) {
            if ( ! empty( $list ) ) {
                $has_issues = true;
                break;
            }
        }

        if ( ! $has_issues ) {
            Ligase_Logger::info( 'Health report: no issues found.' );
            return;
        }

        // Build email
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_bloginfo( 'name' );
        $subject     = sprintf( '[Ligase] Tygodniowy raport schema — %s', $site_name );

        $body = "Ligase — Tygodniowy raport schema\n";
        $body .= str_repeat( '=', 50 ) . "\n\n";
        $body .= sprintf( "Witryna: %s\n", $site_name );
        $body .= sprintf( "AI Search Readiness Score: %d/100\n", $site_score['score'] );
        $body .= sprintf( "Laczna liczba postow: %d\n\n", $total );

        $labels = [
            'low_score'    => 'Posty z wynikiem ponizej 50',
            'no_image'     => 'Posty bez obrazu wyrozniajacego',
            'small_image'  => 'Posty z obrazem ponizej 1200px',
            'old_modified' => 'Posty nieaktualizowane od ponad roku',
            'no_excerpt'   => 'Posty bez zajawki (excerpt)',
            'no_tags'      => 'Posty bez tagow',
        ];

        foreach ( $issues as $key => $pids ) {
            if ( empty( $pids ) ) {
                continue;
            }
            $body .= sprintf( "%s: %d\n", $labels[ $key ], count( $pids ) );
            foreach ( array_slice( $pids, 0, 5 ) as $pid ) {
                $body .= sprintf( "  - %s (%s)\n", get_the_title( $pid ), get_permalink( $pid ) );
            }
            if ( count( $pids ) > 5 ) {
                $body .= sprintf( "  ... i %d wiecej\n", count( $pids ) - 5 );
            }
            $body .= "\n";
        }

        if ( ! empty( $site_score['recommendations'] ) ) {
            $body .= "Rekomendacje:\n";
            foreach ( $site_score['recommendations'] as $rec ) {
                $body .= "  - {$rec}\n";
            }
            $body .= "\n";
        }

        $body .= sprintf( "Panel Ligase: %s\n", admin_url( 'admin.php?page=ligase' ) );
        $body .= "\n-- \nWygenerowane automatycznie przez Ligase v" . LIGASE_VERSION;

        wp_mail( $admin_email, $subject, $body );

        Ligase_Logger::info( 'Health report email sent.', [
            'total_posts' => $total,
            'issues'      => array_map( 'count', $issues ),
        ] );
    }
}
