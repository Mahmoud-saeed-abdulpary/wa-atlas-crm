<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Automations {

    public static function all(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM " . WACRM_DB::automations() . " ORDER BY id DESC", ARRAY_A ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . WACRM_DB::automations() . " WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert( WACRM_DB::automations(), [
            'auto_name'    => sanitize_text_field( $data['auto_name'] ),
            'trigger_type' => sanitize_text_field( $data['trigger_type'] ),
            'conditions'   => maybe_serialize( $data['conditions'] ?? [] ),
            'actions'      => maybe_serialize( $data['actions'] ?? [] ),
            'status'       => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        if ( isset( $data['conditions'] ) ) $data['conditions'] = maybe_serialize( $data['conditions'] );
        if ( isset( $data['actions'] ) )    $data['actions']    = maybe_serialize( $data['actions'] );
        return (bool) $wpdb->update( WACRM_DB::automations(), $data, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( WACRM_DB::automations(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Fire all automations matching a trigger type, evaluated against context data.
     */
    public static function fire( string $trigger_type, array $context = [] ): void {
        if ( WACRM_Quota::is_blocked() ) return;

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . WACRM_DB::automations() . " WHERE trigger_type=%s AND status='active'",
            $trigger_type
        ), ARRAY_A );

        foreach ( $rows as $auto ) {
            $conditions = maybe_unserialize( $auto['conditions'] ) ?: [];
            $actions    = maybe_unserialize( $auto['actions'] ) ?: [];

            if ( ! self::evaluate_conditions( $conditions, $context ) ) continue;

            self::run_actions( $actions, $context, (int) $auto['id'] );
        }
    }

    private static function evaluate_conditions( array $conditions, array $context ): bool {
        if ( empty( $conditions ) ) return true;
        foreach ( $conditions as $cond ) {
            $field    = $cond['field']    ?? '';
            $operator = $cond['operator'] ?? '==';
            $value    = $cond['value']    ?? '';
            $actual   = $context[ $field ] ?? '';
            $pass     = match ( $operator ) {
                '=='  => $actual == $value,
                '!='  => $actual != $value,
                '>'   => (float) $actual > (float) $value,
                '<'   => (float) $actual < (float) $value,
                default => true,
            };
            if ( ! $pass ) return false;
        }
        return true;
    }

    private static function run_actions( array $actions, array $context, int $auto_id ): void {
        $instance = WACRM_Helpers::active_instance();

        foreach ( $actions as $action ) {
            $type = $action['type'] ?? '';
            switch ( $type ) {
                case 'send_message':
                    $phone = $context['phone'] ?? '';
                    if ( empty( $phone ) ) break;
                    $body = $action['message_body'] ?? '';
                    if ( ! empty( $action['template_id'] ) ) {
                        global $wpdb;
                        $tpl  = $wpdb->get_row( $wpdb->prepare( "SELECT body FROM " . WACRM_DB::templates() . " WHERE id=%d", $action['template_id'] ), ARRAY_A );
                        if ( $tpl ) $body = $tpl['body'];
                    }
                    $body = WACRM_Helpers::parse_tags( $body, $context );
                    WACRM_Queue::push( [
                        'phone'         => $phone,
                        'instance_name' => $instance,
                        'message_type'  => 'text',
                        'payload'       => wp_json_encode( [ 'message_body' => $body ] ),
                        'automation_id' => $auto_id,
                    ] );
                    break;

                case 'add_to_list':
                    if ( ! empty( $context['contact_id'] ) && ! empty( $action['list_id'] ) ) {
                        WACRM_Contacts::assign_to_list( (int) $context['contact_id'], (int) $action['list_id'] );
                    }
                    break;

                case 'start_campaign':
                    if ( ! empty( $action['campaign_id'] ) ) {
                        WACRM_Campaigns::launch( (int) $action['campaign_id'] );
                    }
                    break;
            }
        }
    }
}
