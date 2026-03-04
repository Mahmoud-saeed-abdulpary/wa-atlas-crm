<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Queue {

    public static function init(): void {
        add_action( 'wacrm_process_queue', [ __CLASS__, 'process' ] );
    }

    public static function push( array $data ): void {
        global $wpdb;
        $wpdb->insert( WACRM_DB::queue(), [
            'phone'         => sanitize_text_field( $data['phone'] ),
            'instance_name' => sanitize_text_field( $data['instance_name'] ?? '' ),
            'message_type'  => sanitize_text_field( $data['message_type'] ?? 'text' ),
            'payload'       => $data['payload'] ?? '',
            'campaign_id'   => ! empty( $data['campaign_id'] ) ? absint( $data['campaign_id'] ) : null,
            'automation_id' => ! empty( $data['automation_id'] ) ? absint( $data['automation_id'] ) : null,
            'contact_id'    => ! empty( $data['contact_id'] ) ? absint( $data['contact_id'] ) : null,
            'step_order'    => absint( $data['step_order'] ?? 0 ),
            'scheduled_at'  => $data['scheduled_at'] ?? current_time( 'mysql' ),
            'status'        => 'pending',
            'attempts'      => 0,
        ] );
    }

    /**
     * Process pending queue items.
     * Respects quota and per-hour rate limit.
     */
    public static function process(): void {
        global $wpdb;

        if ( WACRM_Quota::is_blocked() ) return;

        $rate_per_hour = (int) get_option( 'wacrm_global_rate_per_hour', 200 );
        $batch_limit   = min( $rate_per_hour, 50 ); // process at most 50 per cron run

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . WACRM_DB::queue() . "
             WHERE status='pending' AND scheduled_at <= %s
             ORDER BY scheduled_at ASC LIMIT %d",
            current_time( 'mysql' ), $batch_limit
        ), ARRAY_A );

        if ( empty( $rows ) ) return;

        $evo = WACRM_Evolution::get();

        foreach ( $rows as $row ) {
            // Hourly rate check
            $sent_this_hour = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . WACRM_DB::message_logs() . " WHERE sent_at >= %s AND status='sent'",
                date( 'Y-m-d H:00:00', current_time( 'timestamp' ) )
            ) );
            if ( $sent_this_hour >= $rate_per_hour ) break;

            if ( WACRM_Quota::is_blocked() ) break;

            $result = self::dispatch( $evo, $row );
            $status = $result['success'] ? 'sent' : 'failed';
            $error  = $result['error']   ?? null;

            // Update queue row
            $wpdb->update( WACRM_DB::queue(), [
                'status'   => $status,
                'attempts' => (int) $row['attempts'] + 1,
            ], [ 'id' => $row['id'] ] );

            // Log the message
            $wpdb->insert( WACRM_DB::message_logs(), [
                'contact_id'    => $row['contact_id'] ?: null,
                'phone'         => $row['phone'],
                'instance_name' => $row['instance_name'],
                'message_type'  => $row['message_type'],
                'campaign_id'   => $row['campaign_id'] ?: null,
                'automation_id' => $row['automation_id'] ?: null,
                'status'        => $status,
                'error_msg'     => $error,
                'sent_at'       => $result['success'] ? current_time( 'mysql' ) : null,
                'created_at'    => current_time( 'mysql' ),
            ] );

            if ( $result['success'] ) {
                WACRM_Quota::increment();
            }
        }
    }

    private static function dispatch( WACRM_Evolution $evo, array $row ): array {
        $instance = $row['instance_name'];
        $phone    = $row['phone'];
        $payload  = json_decode( $row['payload'], true ) ?: [];
        $type     = $row['message_type'];

        // resolve message body from template if needed
        $body = $payload['message_body'] ?? '';
        if ( ! empty( $payload['template_id'] ) ) {
            global $wpdb;
            $tpl  = $wpdb->get_row( $wpdb->prepare( "SELECT body FROM " . WACRM_DB::templates() . " WHERE id=%d", $payload['template_id'] ), ARRAY_A );
            if ( $tpl ) $body = $tpl['body'];
        }

        switch ( $type ) {
            case 'image':
                $res = $evo->send_image( $instance, $phone, $payload['media_url'] ?? '', $body );
                break;
            case 'voice':
            case 'audio':
                $res = $evo->send_audio( $instance, $phone, $payload['media_url'] ?? '' );
                break;
            default:
                $res = $evo->send_text( $instance, $phone, $body );
        }

        if ( isset( $res['error'] ) ) {
            return [ 'success' => false, 'error' => $res['error'] ];
        }
        return [ 'success' => true ];
    }
}
