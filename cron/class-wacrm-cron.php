<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Cron {

    public static function init(): void {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedules' ] );

        if ( ! wp_next_scheduled( 'wacrm_process_queue' ) ) {
            wp_schedule_event( time(), 'every_minute', 'wacrm_process_queue' );
        }
        if ( ! wp_next_scheduled( 'wacrm_daily_license_check' ) ) {
            wp_schedule_event( time(), 'daily', 'wacrm_daily_license_check' );
        }

        add_action( 'wacrm_daily_license_check', [ __CLASS__, 'revalidate_license' ] );
        add_action( 'wacrm_process_queue',        [ 'WACRM_Queue', 'process' ] );
    }

    public static function add_schedules( array $schedules ): array {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute', 'wa-atlas-crm' ),
        ];
        return $schedules;
    }

    public static function revalidate_license(): void {
        $key   = get_option( 'wacrm_license_key', '' );
        $email = get_option( 'wacrm_license_email', '' );
        if ( empty( $key ) ) return;

        W_A_A_T_L_A_S_S_E_N_D_E_R_Base::check_wp_plugin( $key, $email, $error, $obj, WACRM_FILE );

        if ( $error ) {
            update_option( 'wacrm_license_error', $error );
        } else {
            delete_option( 'wacrm_license_error' );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'wacrm_process_queue' );
        wp_clear_scheduled_hook( 'wacrm_daily_license_check' );
    }
}
