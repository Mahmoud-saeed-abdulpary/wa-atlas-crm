<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Contacts {

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public static function all( array $args = [] ): array {
        global $wpdb;
        $where   = '1=1';
        $params  = [];
        if ( ! empty( $args['search'] ) ) {
            $s      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR email LIKE %s)";
            $params = array_merge( $params, [ $s, $s, $s, $s ] );
        }
        if ( ! empty( $args['tag'] ) ) {
            $t      = '%' . $wpdb->esc_like( $args['tag'] ) . '%';
            $where .= ' AND tags LIKE %s';
            $params[] = $t;
        }
        $order  = sanitize_sql_orderby( $args['orderby'] ?? 'id DESC' ) ?: 'id DESC';
        $limit  = isset( $args['limit'] ) ? absint( $args['limit'] ) : 50;
        $offset = isset( $args['offset'] ) ? absint( $args['offset'] ) : 0;

        $sql = "SELECT * FROM " . WACRM_DB::contacts() . " WHERE $where ORDER BY $order LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
    }

    public static function count( array $args = [] ): int {
        global $wpdb;
        $where  = '1=1';
        $params = [];
        if ( ! empty( $args['search'] ) ) {
            $s      = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR phone LIKE %s OR email LIKE %s)";
            $params = array_merge( $params, [ $s, $s, $s, $s ] );
        }
        $sql = "SELECT COUNT(*) FROM " . WACRM_DB::contacts() . " WHERE $where";
        return (int) ( empty( $params ) ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . WACRM_DB::contacts() . " WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }

    public static function insert( array $data ): int {
        global $wpdb;
        $wpdb->insert( WACRM_DB::contacts(), self::sanitize( $data ) );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update( WACRM_DB::contacts(), self::sanitize( $data ), [ 'id' => $id ], null, [ '%d' ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( WACRM_DB::contact_meta(), [ 'contact_id' => $id ], [ '%d' ] );
        $wpdb->delete( WACRM_DB::list_contacts(), [ 'contact_id' => $id ], [ '%d' ] );
        return (bool) $wpdb->delete( WACRM_DB::contacts(), [ 'id' => $id ], [ '%d' ] );
    }

    private static function sanitize( array $d ): array {
        return [
            'first_name' => sanitize_text_field( $d['first_name'] ?? '' ),
            'last_name'  => sanitize_text_field( $d['last_name']  ?? '' ),
            'phone'      => sanitize_text_field( $d['phone']      ?? '' ),
            'whatsapp'   => sanitize_text_field( $d['whatsapp']   ?? '' ),
            'email'      => sanitize_email(      $d['email']      ?? '' ),
            'tags'       => sanitize_text_field( $d['tags']       ?? '' ),
            'wa_status'  => sanitize_text_field( $d['wa_status']  ?? 'unknown' ),
        ];
    }

    // ── Custom field meta ─────────────────────────────────────────────────────

    public static function get_meta( int $contact_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT field_key, field_value FROM " . WACRM_DB::contact_meta() . " WHERE contact_id=%d", $contact_id ),
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ( $rows as $r ) $out[ $r['field_key'] ] = $r['field_value'];
        return $out;
    }

    public static function save_meta( int $contact_id, string $key, string $value ): void {
        global $wpdb;
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM " . WACRM_DB::contact_meta() . " WHERE contact_id=%d AND field_key=%s",
            $contact_id, $key
        ) );
        if ( $exists ) {
            $wpdb->update( WACRM_DB::contact_meta(), [ 'field_value' => $value ], [ 'contact_id' => $contact_id, 'field_key' => $key ] );
        } else {
            $wpdb->insert( WACRM_DB::contact_meta(), [ 'contact_id' => $contact_id, 'field_key' => $key, 'field_value' => $value ] );
        }
    }

    // ── CSV Import ────────────────────────────────────────────────────────────

    public static function import_csv( string $filepath ): array {
        $added  = 0;
        $errors = [];
        if ( ( $handle = fopen( $filepath, 'r' ) ) === false ) {
            return [ 'added' => 0, 'errors' => [ 'Cannot open file' ] ];
        }
        $headers = fgetcsv( $handle );
        if ( ! $headers ) { fclose( $handle ); return [ 'added' => 0, 'errors' => [ 'Empty CSV' ] ]; }
        $headers = array_map( 'strtolower', array_map( 'trim', $headers ) );

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $data = array_combine( $headers, array_pad( $row, count( $headers ), '' ) );
            $phone = $data['phone'] ?? $data['whatsapp'] ?? '';
            if ( empty( $phone ) ) { $errors[] = 'Row skipped – no phone'; continue; }
            $id = self::insert( [
                'first_name' => $data['first_name'] ?? $data['name'] ?? '',
                'last_name'  => $data['last_name']  ?? '',
                'phone'      => $phone,
                'whatsapp'   => $data['whatsapp']   ?? $phone,
                'email'      => $data['email']       ?? '',
                'tags'       => $data['tags']        ?? '',
            ] );
            if ( $id ) $added++;
        }
        fclose( $handle );
        return [ 'added' => $added, 'errors' => $errors ];
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    public static function assign_to_list( int $contact_id, int $list_id ): void {
        global $wpdb;
        $wpdb->replace( WACRM_DB::list_contacts(), [ 'list_id' => $list_id, 'contact_id' => $contact_id ], [ '%d', '%d' ] );
    }

    public static function by_list( int $list_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.* FROM " . WACRM_DB::contacts() . " c
                 INNER JOIN " . WACRM_DB::list_contacts() . " lc ON lc.contact_id = c.id
                 WHERE lc.list_id = %d",
                $list_id
            ), ARRAY_A
        ) ?: [];
    }
}
