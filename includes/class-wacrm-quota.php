<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Quota {

    /** Return current usage for active license */
    public static function used(): int {
        global $wpdb;
        $key = get_option( 'wacrm_license_key', '' );
        if ( empty( $key ) ) return 0;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT messages_sent FROM " . WACRM_DB::quota() . " WHERE license_key = %s ORDER BY id DESC LIMIT 1",
                $key
            ), ARRAY_A
        );
        return $row ? (int) $row['messages_sent'] : 0;
    }

    /** Remaining messages before quota exhausted */
    public static function remaining(): int {
        return max( 0, WACRM_QUOTA_MAX - self::used() );
    }

    /** Check if quota is exhausted */
    public static function exhausted(): bool {
        return self::remaining() <= 0;
    }

    /** Increment quota by $count. Returns false if quota was already exhausted. */
    public static function increment( int $count = 1 ): bool {
        global $wpdb;
        if ( self::exhausted() ) {
            self::trigger_exhaustion();
            return false;
        }
        $key = get_option( 'wacrm_license_key', '' );
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, messages_sent FROM " . WACRM_DB::quota() . " WHERE license_key = %s ORDER BY id DESC LIMIT 1",
                $key
            ), ARRAY_A
        );
        if ( $row ) {
            $wpdb->update(
                WACRM_DB::quota(),
                [ 'messages_sent' => $row['messages_sent'] + $count ],
                [ 'id' => $row['id'] ],
                [ '%d' ], [ '%d' ]
            );
        } else {
            $wpdb->insert(
                WACRM_DB::quota(),
                [ 'license_key' => $key, 'messages_sent' => $count, 'period_start' => current_time( 'mysql' ) ],
                [ '%s', '%d', '%s' ]
            );
        }
        // re-check after increment
        if ( self::exhausted() ) {
            self::trigger_exhaustion();
        }
        return true;
    }

    /** Mark license as quota-exhausted */
    private static function trigger_exhaustion(): void {
        update_option( 'wacrm_quota_exhausted', 1 );
    }

    /** Called when admin adds a new license key */
    public static function reset(): void {
        delete_option( 'wacrm_quota_exhausted' );
        global $wpdb;
        $key = get_option( 'wacrm_license_key', '' );
        if ( $key ) {
            $wpdb->delete( WACRM_DB::quota(), [ 'license_key' => $key ], [ '%s' ] );
        }
    }

    public static function is_blocked(): bool {
        return (bool) get_option( 'wacrm_quota_exhausted', 0 );
    }
}
