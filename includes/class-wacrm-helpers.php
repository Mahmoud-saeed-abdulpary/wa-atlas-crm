<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Helpers {

    /** Sanitise a phone number – strip spaces, dashes, keep + prefix */
    public static function clean_phone( string $phone ): string {
        $phone = preg_replace( '/[^\d+]/', '', $phone );
        return ltrim( $phone, '0' );  // Evolution API uses international format without leading 0
    }

    /** Replace dynamic tags in a message body */
    public static function parse_tags( string $body, array $tags ): string {
        foreach ( $tags as $tag => $value ) {
            $body = str_replace( '{{' . $tag . '}}', esc_html( (string) $value ), $body );
        }
        return $body;
    }

    /** Build tags array from a WooCommerce order */
    public static function order_tags( $order ): array {
        if ( ! $order ) return [];
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        return [
            'order_id'       => $order->get_id(),
            'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'order_total'    => $order->get_formatted_order_total(),
            'order_status'   => wc_get_order_status_name( $order->get_status() ),
            'billing_phone'  => $order->get_billing_phone(),
            'billing_state'  => $order->get_billing_state(),
            'order_items'    => implode( ', ', $items ),
        ];
    }

    /** Build tags from a contact row */
    public static function contact_tags( array $contact ): array {
        return [
            'first_name'  => $contact['first_name'] ?? '',
            'last_name'   => $contact['last_name']  ?? '',
            'phone'       => $contact['phone']       ?? '',
            'whatsapp'    => $contact['whatsapp']    ?? '',
            'email'       => $contact['email']       ?? '',
        ];
    }

    /** Return the active Evolution instance name (first enabled) */
    public static function active_instance(): string {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT instance_name FROM " . WACRM_DB::instances() . " WHERE enabled=1 AND status='open' LIMIT 1",
            ARRAY_A
        );
        return $row ? $row['instance_name'] : '';
    }

    /** Check if sending is currently within the allowed schedule window */
    public static function in_schedule( string $from = '09:00', string $to = '20:00' ): bool {
        $now  = current_time( 'H:i' );
        return ( $now >= $from && $now <= $to );
    }

    /** Generate a secure numeric OTP */
    public static function generate_otp( int $len = 6 ): string {
        $otp = '';
        for ( $i = 0; $i < $len; $i++ ) {
            $otp .= wp_rand( 0, 9 );
        }
        return $otp;
    }

    /** AJAX nonce check wrapper */
    public static function verify_nonce( string $action ): void {
        if ( ! check_ajax_referer( $action, '_nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed' ], 403 );
        }
    }

    /** Capability check wrapper */
    public static function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions' ], 403 );
        }
    }
}
