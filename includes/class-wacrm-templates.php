<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Templates {

    public static function all( string $category = '' ): array {
        global $wpdb;
        if ( $category ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM " . WACRM_DB::templates() . " WHERE category=%s ORDER BY id DESC", $category
            ), ARRAY_A ) ?: [];
        }
        return $wpdb->get_results( "SELECT * FROM " . WACRM_DB::templates() . " ORDER BY id DESC", ARRAY_A ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . WACRM_DB::templates() . " WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert( WACRM_DB::templates(), [
            'tpl_name' => sanitize_text_field( $data['tpl_name'] ),
            'category' => sanitize_text_field( $data['category'] ?? 'manual' ),
            'body'     => sanitize_textarea_field( $data['body'] ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( WACRM_DB::templates(), [
            'tpl_name' => sanitize_text_field( $data['tpl_name'] ?? '' ),
            'category' => sanitize_text_field( $data['category'] ?? 'manual' ),
            'body'     => sanitize_textarea_field( $data['body'] ?? '' ),
        ], [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( WACRM_DB::templates(), [ 'id' => $id ], [ '%d' ] );
    }

    public static function categories(): array {
        return [ 'order_confirmation', 'otp', 'campaign', 'manual', 'automation' ];
    }
}
