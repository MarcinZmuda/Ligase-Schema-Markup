<?php

defined( 'ABSPATH' ) || exit;

class Ligase_Type_Event {

    public function build(): ?array {
        if ( ! is_single() ) {
            return null;
        }

        $post_id = get_the_ID();

        if ( get_post_meta( $post_id, '_ligase_enable_event', true ) !== '1' && ! Ligase_Schema_Rules::is_enabled_for_post( '_ligase_enable_event', $post_id ) ) {
            return null;
        }

        $data = get_post_meta( $post_id, '_ligase_event', true );

        if ( empty( $data ) || ! is_array( $data ) || empty( $data['name'] ) || empty( $data['start_date'] ) ) {
            return null;
        }

        $schema = [
            '@type'     => 'Event',
            '@id'       => esc_url( get_permalink() ) . '#event',
            'name'      => wp_strip_all_tags( $data['name'] ),
            'startDate' => wp_strip_all_tags( $data['start_date'] ),
            'organizer' => [ '@id' => home_url( '/#org' ) ],
            'url'       => esc_url( get_permalink() ),
        ];

        if ( ! empty( $data['end_date'] ) ) {
            $schema['endDate'] = wp_strip_all_tags( $data['end_date'] );
        }

        if ( ! empty( $data['description'] ) ) {
            $schema['description'] = wp_strip_all_tags( mb_substr( $data['description'], 0, 300 ) );
        }

        // Location — online or physical
        $is_online = ! empty( $data['is_online'] );
        if ( $is_online ) {
            $schema['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
            $schema['location'] = [
                '@type' => 'VirtualLocation',
                'url'   => esc_url( $data['online_url'] ?? get_permalink() ),
            ];
        } else {
            // Google requires `location` whenever attendanceMode is Offline. If venue_name
            // is missing, return null rather than emitting an invalid Event (which would
            // produce a Search Console warning + lose the rich result).
            if ( empty( $data['venue_name'] ) ) {
                return null;
            }
            $schema['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
            $location = [
                '@type' => 'Place',
                'name'  => wp_strip_all_tags( $data['venue_name'] ),
            ];
            if ( ! empty( $data['venue_address'] ) ) {
                $location['address'] = [
                    '@type'          => 'PostalAddress',
                    'streetAddress'  => wp_strip_all_tags( $data['venue_address'] ),
                ];
            }
            $schema['location'] = $location;
        }

        // Status
        $allowed_statuses = [
            'EventScheduled', 'EventMovedOnline', 'EventPostponed',
            'EventRescheduled', 'EventCancelled',
        ];
        $status = $data['status'] ?? 'EventScheduled';
        if ( in_array( $status, $allowed_statuses, true ) ) {
            $schema['eventStatus'] = 'https://schema.org/' . $status;
        }

        // Ticket / offers
        if ( ! empty( $data['ticket_url'] ) ) {
            $schema['offers'] = [
                '@type'         => 'Offer',
                'url'           => esc_url( $data['ticket_url'] ),
                'price'         => wp_strip_all_tags( $data['price'] ?? '0' ),
                'priceCurrency' => wp_strip_all_tags( $data['currency'] ?? 'PLN' ),
                'availability'  => 'https://schema.org/InStock',
            ];
        }

        // Image
        $tid = get_post_thumbnail_id( $post_id );
        if ( $tid ) {
            $img = wp_get_attachment_image_src( $tid, 'full' );
            if ( $img ) {
                $schema['image'] = esc_url( $img[0] );
            }
        }

        return $schema;
    }
}
