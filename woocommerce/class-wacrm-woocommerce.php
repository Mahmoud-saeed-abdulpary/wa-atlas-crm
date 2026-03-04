<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_WooCommerce {

    public static function init(): void {
        add_action( 'woocommerce_new_order',              [ __CLASS__, 'on_order_created' ], 10, 1 );
        add_action( 'woocommerce_order_status_changed',   [ __CLASS__, 'on_status_changed' ], 10, 3 );
    }

    public static function on_order_created( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) return;

        WACRM_Automations::fire( 'woocommerce_order_created', array_merge(
            WACRM_Helpers::order_tags( $order ),
            [ 'phone' => $phone ]
        ) );
    }

    public static function on_status_changed( int $order_id, string $old_status, string $new_status ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) return;

        $context = array_merge(
            WACRM_Helpers::order_tags( $order ),
            [ 'phone' => $phone, 'old_status' => $old_status, 'new_status' => $new_status ]
        );

        WACRM_Automations::fire( 'woocommerce_order_updated', $context );

        if ( $new_status === 'completed' ) {
            WACRM_Automations::fire( 'woocommerce_order_completed', $context );
        }
    }

    /**
     * Manually send a WhatsApp message to an order's customer.
     * Called from AJAX.
     */
    public static function manual_send( int $order_id, string $message ): array {
        if ( WACRM_Quota::is_blocked() ) {
            return [ 'error' => 'Message quota exceeded.' ];
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) return [ 'error' => 'Order not found.' ];

        $phone = $order->get_billing_phone();
        if ( empty( $phone ) ) return [ 'error' => 'No phone number on order.' ];

        $instance = WACRM_Helpers::active_instance();
        if ( empty( $instance ) ) return [ 'error' => 'No connected WhatsApp instance.' ];

        $tags    = WACRM_Helpers::order_tags( $order );
        $message = WACRM_Helpers::parse_tags( $message, $tags );

        $res = WACRM_Evolution::get()->send_text( $instance, $phone, $message );
        if ( isset( $res['error'] ) ) return [ 'error' => $res['error'] ];

        WACRM_Quota::increment();

        global $wpdb;
        $wpdb->insert( WACRM_DB::message_logs(), [
            'phone'         => $phone,
            'instance_name' => $instance,
            'message_type'  => 'text',
            'status'        => 'sent',
            'sent_at'       => current_time( 'mysql' ),
            'created_at'    => current_time( 'mysql' ),
        ] );
        return [ 'success' => true ];
    }
}
