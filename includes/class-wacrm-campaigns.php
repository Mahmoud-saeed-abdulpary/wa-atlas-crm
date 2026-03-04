<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Campaigns {

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public static function all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM " . WACRM_DB::campaigns() . " ORDER BY id DESC", ARRAY_A ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . WACRM_DB::campaigns() . " WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert( WACRM_DB::campaigns(), [
            'campaign_name'   => sanitize_text_field( $data['campaign_name'] ),
            'status'          => 'draft',
            'target_lists'    => maybe_serialize( $data['target_lists'] ?? [] ),
            'target_filters'  => maybe_serialize( $data['target_filters'] ?? [] ),
            'filter_logic'    => in_array( $data['filter_logic'] ?? 'AND', [ 'AND', 'OR' ] ) ? $data['filter_logic'] : 'AND',
            'rate_per_hour'   => absint( $data['rate_per_hour'] ?? 200 ),
            'schedule_from'   => sanitize_text_field( $data['schedule_from'] ?? '09:00' ),
            'schedule_to'     => sanitize_text_field( $data['schedule_to'] ?? '20:00' ),
            'randomize_delay' => (int) ( ! empty( $data['randomize_delay'] ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        unset( $data['id'] );
        if ( isset( $data['target_lists'] ) )   $data['target_lists']   = maybe_serialize( $data['target_lists'] );
        if ( isset( $data['target_filters'] ) ) $data['target_filters'] = maybe_serialize( $data['target_filters'] );
        return (bool) $wpdb->update( WACRM_DB::campaigns(), $data, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( WACRM_DB::campaign_steps(), [ 'campaign_id' => $id ], [ '%d' ] );
        return (bool) $wpdb->delete( WACRM_DB::campaigns(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    public static function get_steps( int $campaign_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM " . WACRM_DB::campaign_steps() . " WHERE campaign_id=%d ORDER BY step_order ASC", $campaign_id ),
            ARRAY_A
        ) ?: [];
    }

    public static function save_steps( int $campaign_id, array $steps ): void {
        global $wpdb;
        $wpdb->delete( WACRM_DB::campaign_steps(), [ 'campaign_id' => $campaign_id ], [ '%d' ] );
        foreach ( $steps as $i => $step ) {
            $wpdb->insert( WACRM_DB::campaign_steps(), [
                'campaign_id'   => $campaign_id,
                'step_order'    => $i,
                'message_type'  => sanitize_text_field( $step['message_type'] ?? 'text' ),
                'template_id'   => ! empty( $step['template_id'] ) ? absint( $step['template_id'] ) : null,
                'message_body'  => wp_kses_post( $step['message_body'] ?? '' ),
                'media_url'     => esc_url_raw( $step['media_url'] ?? '' ),
                'delay_seconds' => absint( $step['delay_seconds'] ?? 0 ),
            ] );
        }
    }

    // ── Launch ────────────────────────────────────────────────────────────────

    /**
     * Resolve target contacts from campaign config and enqueue messages.
     * Returns [ 'queued' => int, 'skipped' => int ]
     */
    public static function launch( int $campaign_id ): array {
        if ( WACRM_Quota::is_blocked() ) {
            return [ 'error' => 'Message quota exceeded.' ];
        }

        $campaign = self::get( $campaign_id );
        if ( ! $campaign ) return [ 'error' => 'Campaign not found.' ];

        $instance = WACRM_Helpers::active_instance();
        if ( empty( $instance ) ) return [ 'error' => 'No connected WhatsApp instance.' ];

        $steps   = self::get_steps( $campaign_id );
        if ( empty( $steps ) ) return [ 'error' => 'No message steps defined.' ];

        $contacts = self::resolve_contacts( $campaign );
        $queued   = 0;
        $skipped  = 0;
        $now      = current_time( 'mysql' );
        $delay_acc = 0;

        foreach ( $contacts as $contact ) {
            $phone = $contact['whatsapp'] ?: $contact['phone'];
            if ( empty( $phone ) ) {
                self::log_skip( $campaign_id, $contact );
                $skipped++;
                continue;
            }

            foreach ( $steps as $step ) {
                $delay_acc += absint( $step['delay_seconds'] );
                if ( $campaign['randomize_delay'] ) {
                    $delay_acc += wp_rand( 1, 8 );
                }
                $sched = date( 'Y-m-d H:i:s', strtotime( $now ) + $delay_acc );
                WACRM_Queue::push( [
                    'phone'         => $phone,
                    'instance_name' => $instance,
                    'message_type'  => $step['message_type'],
                    'payload'       => wp_json_encode( $step ),
                    'campaign_id'   => $campaign_id,
                    'contact_id'    => $contact['id'],
                    'step_order'    => $step['step_order'],
                    'scheduled_at'  => $sched,
                ] );
                $queued++;
            }
        }

        // Mark campaign as running
        self::update( $campaign_id, [ 'status' => 'running', 'started_at' => $now ] );
        return [ 'queued' => $queued, 'skipped' => $skipped ];
    }

    private static function resolve_contacts( array $campaign ): array {
        $lists   = maybe_unserialize( $campaign['target_lists'] ) ?: [];
        $filters = maybe_unserialize( $campaign['target_filters'] ) ?: [];
        $contacts = [];

        foreach ( (array) $lists as $list_id ) {
            $batch = WACRM_Contacts::by_list( (int) $list_id );
            foreach ( $batch as $c ) {
                $contacts[ $c['id'] ] = $c;
            }
        }

        // TODO: advanced field filter matching (AND/OR)

        return array_values( $contacts );
    }

    private static function log_skip( int $campaign_id, array $contact ): void {
        global $wpdb;
        $wpdb->insert( WACRM_DB::message_logs(), [
            'contact_id'    => $contact['id'],
            'phone'         => $contact['phone'],
            'instance_name' => '',
            'message_type'  => 'text',
            'campaign_id'   => $campaign_id,
            'status'        => 'whatsapp_not_connected',
            'created_at'    => current_time( 'mysql' ),
        ] );
    }
}
