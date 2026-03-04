<?php
/**
 * WA Atlas CRM – Campaigns  v1.2.0
 * ==================================
 * Fixes applied:
 *  - resolve_contacts: target_lists stored as JSON (not serialized), use json_decode
 *  - get_steps: returns fully normalised array with all keys JS expects
 *  - save_steps: accepts both message_type/message_body (from JS) correctly
 *  - sanitize: encodes target_lists as JSON for DB storage
 *  - launch: merges contact custom meta into tags for dynamic {{field}} replacement
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Campaigns {

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public static function all(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . WACRM_DB::campaigns() . " ORDER BY id DESC",
            ARRAY_A
        ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . WACRM_DB::campaigns() . " WHERE id=%d", $id ),
            ARRAY_A
        ) ?: null;
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert( WACRM_DB::campaigns(), self::sanitize( $data ) );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( WACRM_DB::campaigns(), self::sanitize( $data ), [ 'id' => $id ], null, [ '%d' ] );
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( WACRM_DB::campaign_steps(), [ 'campaign_id' => $id ], [ '%d' ] );
        $wpdb->delete( WACRM_DB::campaigns(),       [ 'id'          => $id ], [ '%d' ] );
    }

    private static function sanitize( array $d ): array {
        // target_lists: accepts array or JSON string, always stores as JSON
        $lists = $d['target_lists'] ?? [];
        if ( is_string( $lists ) ) {
            $decoded = json_decode( $lists, true );
            $lists = is_array( $decoded ) ? $decoded : [];
        }
        $lists = array_map( 'absint', (array) $lists );

        return [
            'campaign_name'   => sanitize_text_field( $d['campaign_name']   ?? '' ),
            'status'          => sanitize_text_field( $d['status']          ?? 'draft' ),
            'target_lists'    => wp_json_encode( $lists ),
            'target_filters'  => isset( $d['target_filters'] ) ? wp_json_encode( (array) $d['target_filters'] ) : null,
            'filter_logic'    => in_array( $d['filter_logic'] ?? 'AND', [ 'AND', 'OR' ], true ) ? $d['filter_logic'] : 'AND',
            'rate_per_hour'   => absint( $d['rate_per_hour']   ?? 200 ),
            'schedule_from'   => sanitize_text_field( $d['schedule_from']   ?? '09:00' ),
            'schedule_to'     => sanitize_text_field( $d['schedule_to']     ?? '20:00' ),
            'randomize_delay' => (int) ! empty( $d['randomize_delay'] ),
        ];
    }

    // ── Steps ─────────────────────────────────────────────────────────────────

    /**
     * Returns normalised steps array.
     * All keys JS expects: message_type, message_body, media_url, delay_seconds, template_id
     */
    public static function get_steps( int $campaign_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . WACRM_DB::campaign_steps() . " WHERE campaign_id=%d ORDER BY step_order ASC",
                $campaign_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map( function( $row ) {
            return [
                'id'            => (int) $row['id'],
                'campaign_id'   => (int) $row['campaign_id'],
                'step_order'    => (int) $row['step_order'],
                'message_type'  => $row['message_type'] ?? 'text',
                'message_body'  => $row['message_body'] ?? '',
                'media_url'     => $row['media_url']    ?? '',
                'delay_seconds' => (int) ( $row['delay_seconds'] ?? 5 ),
                'template_id'   => (int) ( $row['template_id']  ?? 0 ),
            ];
        }, $rows );
    }

    /**
     * Replace all steps for a campaign.
     * Accepts message_type / message_body from JS.
     */
    public static function save_steps( int $campaign_id, array $steps ): void {
        global $wpdb;
        $wpdb->delete( WACRM_DB::campaign_steps(), [ 'campaign_id' => $campaign_id ], [ '%d' ] );

        foreach ( $steps as $i => $step ) {
            // Accept both naming conventions (message_type and msg_type for safety)
            $msg_type = $step['message_type'] ?? $step['msg_type'] ?? 'text';
            $msg_body = $step['message_body'] ?? $step['msg_body'] ?? '';

            $wpdb->insert( WACRM_DB::campaign_steps(), [
                'campaign_id'   => $campaign_id,
                'step_order'    => $i,
                'message_type'  => sanitize_text_field( $msg_type ),
                'template_id'   => ! empty( $step['template_id'] ) ? absint( $step['template_id'] ) : null,
                'message_body'  => sanitize_textarea_field( $msg_body ),
                'media_url'     => esc_url_raw( $step['media_url'] ?? '' ),
                'delay_seconds' => absint( $step['delay_seconds'] ?? 5 ),
            ] );
        }
    }

    // ── Launch ────────────────────────────────────────────────────────────────

    public static function launch( int $campaign_id ): array {
        if ( WACRM_Quota::is_blocked() ) {
            return [ 'error' => 'Message quota exceeded.' ];
        }

        $campaign = self::get( $campaign_id );
        if ( ! $campaign ) return [ 'error' => 'Campaign not found.' ];

        $instance = WACRM_Helpers::active_instance();
        if ( empty( $instance ) ) return [ 'error' => 'No connected WhatsApp instance.' ];

        $steps = self::get_steps( $campaign_id );
        if ( empty( $steps ) ) return [ 'error' => 'No message steps defined for this campaign.' ];

        $contacts  = self::resolve_contacts( $campaign );
        if ( empty( $contacts ) ) return [ 'error' => 'No contacts found in target lists.' ];

        $queued    = 0;
        $skipped   = 0;
        $now       = current_time( 'mysql' );
        $delay_acc = 0;

        foreach ( $contacts as $contact ) {
            $phone = $contact['whatsapp'] ?: $contact['phone'];
            if ( empty( $phone ) ) {
                self::log_skip( $campaign_id, $contact );
                $skipped++;
                continue;
            }

            // FIX #5 – Merge custom meta fields into contact tags
            $meta      = WACRM_Contacts::get_meta( (int) $contact['id'] );
            $base_tags = WACRM_Helpers::contact_tags( $contact, $meta );

            foreach ( $steps as $step ) {
                $delay_acc += absint( $step['delay_seconds'] );
                if ( $campaign['randomize_delay'] ) {
                    $delay_acc += wp_rand( 1, 8 );
                }
                $sched = date( 'Y-m-d H:i:s', strtotime( $now ) + $delay_acc );

                // Replace dynamic tags in message body
                $body = WACRM_Helpers::parse_tags( $step['message_body'], $base_tags );

                WACRM_Queue::push( [
                    'phone'         => $phone,
                    'instance_name' => $instance,
                    'message_type'  => $step['message_type'],
                    'payload'       => wp_json_encode( array_merge( $step, [ 'message_body' => $body ] ) ),
                    'campaign_id'   => $campaign_id,
                    'contact_id'    => $contact['id'],
                    'step_order'    => $step['step_order'],
                    'scheduled_at'  => $sched,
                ] );
                $queued++;
            }
        }

        self::update( $campaign_id, [ 'status' => 'running', 'started_at' => $now ] );
        return [ 'queued' => $queued, 'skipped' => $skipped ];
    }

    /**
     * Decode target_lists from JSON and collect contacts from each list.
     * FIX: was using maybe_unserialize() on JSON data → now uses json_decode().
     */
    private static function resolve_contacts( array $campaign ): array {
        $raw = $campaign['target_lists'] ?? '';

        if ( is_array( $raw ) ) {
            $list_ids = $raw;
        } elseif ( is_string( $raw ) && ! empty( $raw ) ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $list_ids = $decoded;
            } else {
                // Fallback: try PHP unserialize (old data)
                $unserialized = maybe_unserialize( $raw );
                $list_ids = is_array( $unserialized ) ? $unserialized : [];
            }
        } else {
            $list_ids = [];
        }

        $contacts = [];
        foreach ( $list_ids as $list_id ) {
            $batch = WACRM_Contacts::by_list( (int) $list_id );
            foreach ( $batch as $c ) {
                $contacts[ $c['id'] ] = $c; // dedupe by ID
            }
        }

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