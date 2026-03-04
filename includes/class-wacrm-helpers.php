<?php
/**
 * WA Atlas CRM – Helpers  v1.2.0
 * ================================
 * Fixes applied:
 *  #5  – contact_tags() now accepts $meta array, merges custom fields as {{field_key}} tags
 *  #5  – all_dynamic_tags() returns full tag reference for the JS tag panel
 *  #5  – order_tags() expanded to 30+ WooCommerce fields
 *  #6  – (used by admin.js) tag panel shows {{key}} for every custom field
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Helpers {

    /** Sanitise a phone number – strip spaces, dashes, keep + prefix */
    public static function clean_phone( string $phone ): string {
        $phone = preg_replace( '/[^\d+]/', '', $phone );
        return ltrim( $phone, '0' );
    }

    /** Replace dynamic {{tag}} placeholders in a message body */
    public static function parse_tags( string $body, array $tags ): string {
        foreach ( $tags as $tag => $value ) {
            $body = str_replace( '{{' . $tag . '}}', (string) $value, $body );
        }
        return $body;
    }

    /**
     * Build tags array from a WooCommerce order.
     * FIX #5 – expanded to 30+ fields.
     */
    public static function order_tags( $order ): array {
        if ( ! $order ) return [];

        $items     = [];
        $item_names = [];
        $item_count = 0;
        $subtotal   = 0;

        foreach ( $order->get_items() as $item ) {
            $item_names[] = $item->get_name() . ' x' . $item->get_quantity();
            $item_count  += (int) $item->get_quantity();
            $subtotal    += (float) $item->get_subtotal();
        }

        // Shipping method
        $shipping_method = '';
        foreach ( $order->get_items( 'shipping' ) as $shipping ) {
            $shipping_method = $shipping->get_name();
            break;
        }

        // Coupon codes
        $coupons = implode( ', ', $order->get_coupon_codes() );

        // Tracking (custom meta – common field names)
        $tracking_number = $order->get_meta( '_tracking_number' ) ?: $order->get_meta( 'tracking_number' ) ?: '';
        $tracking_url    = $order->get_meta( '_tracking_url'    ) ?: $order->get_meta( 'tracking_url'    ) ?: '';

        return [
            // Order core
            'order_id'          => $order->get_id(),
            'order_number'      => $order->get_order_number(),
            'order_status'      => wc_get_order_status_name( $order->get_status() ),
            'order_total'       => $order->get_formatted_order_total(),
            'order_subtotal'    => wc_price( $subtotal ),
            'order_date'        => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '',
            'order_time'        => $order->get_date_created() ? $order->get_date_created()->date( 'H:i' )   : '',
            'product_names'     => implode( ', ', $item_names ),
            'item_count'        => $item_count,
            // Billing
            'customer_name'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'billing_first_name'=> $order->get_billing_first_name(),
            'billing_last_name' => $order->get_billing_last_name(),
            'billing_phone'     => $order->get_billing_phone(),
            'billing_email'     => $order->get_billing_email(),
            'billing_address'   => $order->get_billing_address_1() . ( $order->get_billing_address_2() ? ', ' . $order->get_billing_address_2() : '' ),
            'billing_city'      => $order->get_billing_city(),
            'billing_state'     => $order->get_billing_state(),
            'billing_postcode'  => $order->get_billing_postcode(),
            'billing_country'   => $order->get_billing_country(),
            'billing_company'   => $order->get_billing_company(),
            // Shipping
            'shipping_name'     => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'shipping_address'  => $order->get_shipping_address_1() . ( $order->get_shipping_address_2() ? ', ' . $order->get_shipping_address_2() : '' ),
            'shipping_city'     => $order->get_shipping_city(),
            'shipping_state'    => $order->get_shipping_state(),
            'shipping_postcode' => $order->get_shipping_postcode(),
            'shipping_method'   => $shipping_method,
            'shipping_total'    => wc_price( (float) $order->get_shipping_total() ),
            // Payment
            'payment_method'    => $order->get_payment_method_title(),
            'transaction_id'    => $order->get_transaction_id(),
            'coupon_code'       => $coupons,
            'discount_amount'   => wc_price( (float) $order->get_discount_total() ),
            'tax_total'         => wc_price( (float) $order->get_total_tax() ),
            // Tracking
            'tracking_number'   => $tracking_number,
            'tracking_url'      => $tracking_url,
        ];
    }

    /**
     * Build tags from a contact row.
     * FIX #5 – accepts $meta array so custom fields become {{field_key}} tags.
     */
    public static function contact_tags( array $contact, array $meta = [] ): array {
        $tags = [
            'first_name'   => $contact['first_name'] ?? '',
            'last_name'    => $contact['last_name']   ?? '',
            'full_name'    => trim( ( $contact['first_name'] ?? '' ) . ' ' . ( $contact['last_name'] ?? '' ) ),
            'phone'        => $contact['phone']        ?? '',
            'whatsapp'     => $contact['whatsapp']     ?? '',
            'email'        => $contact['email']        ?? '',
            'tags'         => $contact['tags']         ?? '',
        ];

        // Merge custom meta fields – each becomes a usable {{key}} tag
        foreach ( $meta as $key => $value ) {
            $tags[ sanitize_key( $key ) ] = (string) $value;
        }

        return $tags;
    }

    /**
     * Return ALL dynamic tags available – used by JS tag panel (buildTagPanel).
     * Returns [ 'key' => 'Description' ]
     */
    public static function all_dynamic_tags(): array {
        $tags = [
            // Contact tags
            'first_name'         => 'Contact first name',
            'last_name'          => 'Contact last name',
            'full_name'          => 'Contact full name',
            'phone'              => 'Contact phone number',
            'whatsapp'           => 'Contact WhatsApp number',
            'email'              => 'Contact email',
            'tags'               => 'Contact tag list',
            // Order tags
            'order_id'           => 'WooCommerce order ID',
            'order_number'       => 'WooCommerce order number',
            'order_status'       => 'Order status label',
            'order_total'        => 'Formatted order total',
            'order_subtotal'     => 'Order subtotal (before tax/shipping)',
            'order_date'         => 'Order date (Y-m-d)',
            'order_time'         => 'Order time (H:i)',
            'product_names'      => 'Comma-separated product names',
            'item_count'         => 'Total item quantity',
            'customer_name'      => 'Customer full name (billing)',
            'billing_first_name' => 'Billing first name',
            'billing_last_name'  => 'Billing last name',
            'billing_phone'      => 'Billing phone number',
            'billing_email'      => 'Billing email address',
            'billing_address'    => 'Billing street address',
            'billing_city'       => 'Billing city',
            'billing_state'      => 'Billing state / region',
            'billing_postcode'   => 'Billing postcode / ZIP',
            'billing_country'    => 'Billing country code',
            'billing_company'    => 'Billing company name',
            'shipping_name'      => 'Shipping full name',
            'shipping_address'   => 'Shipping street address',
            'shipping_city'      => 'Shipping city',
            'shipping_state'     => 'Shipping state',
            'shipping_postcode'  => 'Shipping postcode',
            'shipping_method'    => 'Shipping method name',
            'shipping_total'     => 'Shipping cost',
            'payment_method'     => 'Payment method name',
            'transaction_id'     => 'Payment transaction ID',
            'coupon_code'        => 'Applied coupon code(s)',
            'discount_amount'    => 'Total discount amount',
            'tax_total'          => 'Total tax amount',
            'tracking_number'    => 'Shipment tracking number',
            'tracking_url'       => 'Shipment tracking URL',
        ];

        // Merge in any custom contact fields from DB
        global $wpdb;
        $fields = [];
        try {
            $fields = $wpdb->get_results(
                "SELECT field_key, field_label FROM " . WACRM_DB::contact_fields() . " ORDER BY sort_order ASC",
                ARRAY_A
            ) ?: [];
        } catch ( \Throwable $e ) {}

        foreach ( $fields as $f ) {
            $tags[ $f['field_key'] ] = 'Custom: ' . $f['field_label'];
        }

        return $tags;
    }

    /** Return the active Evolution instance name (first connected+enabled) */
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
        $now = current_time( 'H:i' );
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
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
            wp_die();
        }
    }

    /** Capability check wrapper */
    public static function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
            wp_die();
        }
    }
}