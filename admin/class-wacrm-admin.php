<?php
/**
 * WA Atlas CRM – Admin Controller
 * Handles all menus, asset loading, and AJAX endpoints.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class WACRM_Admin {

    private static ?object $lic_obj   = null;
    private static string  $lic_error = '';

    // ─────────────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────────────

    public static function init( object $lic_obj ): void {
        self::$lic_obj = $lic_obj;
        add_action( 'admin_menu',            [ __CLASS__, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        self::register_ajax();
    }

    public static function init_license_only( string $error ): void {
        self::$lic_error = $error;
        add_action( 'admin_menu',            [ __CLASS__, 'register_license_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets_license_only' ] );
        add_action( 'wp_ajax_wacrm_save_license',   [ __CLASS__, 'ajax_save_license_standalone' ] );
        add_action( 'wp_ajax_wacrm_remove_license', [ __CLASS__, 'ajax_remove_license_standalone' ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Menus
    // ─────────────────────────────────────────────────────────────────────────

    public static function register_menus(): void {
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ffffff">'
            . '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>'
            . '<path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.553 4.103 1.524 5.827L.057 23.5l5.845-1.53A11.942 11.942 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.612-.502-5.124-1.382l-.368-.218-3.467.908.924-3.374-.24-.389A9.938 9.938 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/>'
            . '</svg>'
        );

        add_menu_page( 'WA Atlas CRM', 'WA Atlas CRM', 'manage_options', 'wacrm-dashboard',
            [ __CLASS__, 'page_dashboard' ], $icon, 25 );

        $pages = [
            [ 'wacrm-dashboard',   'Dashboard',    'page_dashboard'   ],
            [ 'wacrm-instances',   'WhatsApp',     'page_instances'   ],
            [ 'wacrm-contacts',    'Contacts',     'page_contacts'    ],
            [ 'wacrm-lists',       'Lists',        'page_lists'       ],
            [ 'wacrm-campaigns',   'Campaigns',    'page_campaigns'   ],
            [ 'wacrm-automations', 'Automations',  'page_automations' ],
            [ 'wacrm-templates',   'Templates',    'page_templates'   ],
            [ 'wacrm-woocommerce', 'WooCommerce',  'page_woocommerce' ],
            [ 'wacrm-logs',        'Message Logs', 'page_logs'        ],
            [ 'wacrm-settings',    'Settings',     'page_settings'    ],
        ];

        foreach ( $pages as $p ) {
            add_submenu_page( 'wacrm-dashboard', $p[1] . ' – WA Atlas CRM',
                $p[1], 'manage_options', $p[0], [ __CLASS__, $p[2] ] );
        }
    }

    public static function register_license_menu(): void {
        add_menu_page( 'WA Atlas CRM – Activate', 'WA Atlas CRM', 'manage_options',
            'wacrm-license', [ __CLASS__, 'page_license' ], '', 25 );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wacrm' ) === false ) return;

        wp_enqueue_style( 'wacrm-admin', WACRM_URL . 'admin/css/admin.css', [], WACRM_VERSION );
        wp_enqueue_script( 'chart-js',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
            [], '4.4.1', true );
        wp_enqueue_script( 'wacrm-admin', WACRM_URL . 'admin/js/admin.js',
            [ 'jquery', 'chart-js' ], WACRM_VERSION, true );

        // Safe quota read (tables may not exist on very first run)
        $quota_used = 0; $quota_rem = WACRM_QUOTA_MAX; $quota_blocked = false;
        try {
            $quota_used    = WACRM_Quota::used();
            $quota_rem     = WACRM_Quota::remaining();
            $quota_blocked = WACRM_Quota::is_blocked();
        } catch ( \Throwable $e ) {}

        wp_localize_script( 'wacrm-admin', 'waCRM', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wacrm_admin_nonce' ),
            'quota'    => [
                'used'      => $quota_used,
                'max'       => WACRM_QUOTA_MAX,
                'remaining' => $quota_rem,
                'blocked'   => $quota_blocked,
            ],
        ] );
    }

    public static function enqueue_assets_license_only( string $hook ): void {
        if ( strpos( $hook, 'wacrm' ) === false ) return;
        wp_enqueue_style( 'wacrm-admin', WACRM_URL . 'admin/css/admin.css', [], WACRM_VERSION );
        wp_enqueue_script( 'wacrm-license-js', WACRM_URL . 'admin/js/license.js',
            [ 'jquery' ], WACRM_VERSION, true );
        wp_localize_script( 'wacrm-license-js', 'waCRM', [
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'license_nonce' => wp_create_nonce( 'wacrm_license_action' ),
        ] );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Standalone license AJAX (runs before plugin is fully licensed)
    // ─────────────────────────────────────────────────────────────────────────

    public static function ajax_save_license_standalone(): void {
        if ( ! check_ajax_referer( 'wacrm_license_action', '_nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 ); wp_die();
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 ); wp_die();
        }
        $key   = sanitize_text_field( wp_unslash( $_POST['license_key']   ?? '' ) );
        $email = sanitize_email(      wp_unslash( $_POST['license_email'] ?? '' ) );

        if ( empty( $key ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a license key.' ] ); wp_die();
        }
        $validated = W_A_A_T_L_A_S_S_E_N_D_E_R_Base::check_wp_plugin( $key, $email, $lic_error, $lic_obj, WACRM_FILE );
        if ( ! $validated ) {
            wp_send_json_error( [ 'message' => $lic_error ?: 'Invalid license key.' ] ); wp_die();
        }
        update_option( 'wacrm_license_key',   $key );
        update_option( 'wacrm_license_email', $email );
        delete_option( 'wacrm_quota_exhausted' );
        delete_option( 'wacrm_license_error' );
        wp_send_json_success( [
            'message'  => 'License activated! Redirecting…',
            'redirect' => admin_url( 'admin.php?page=wacrm-dashboard' ),
        ] );
        wp_die();
    }

    public static function ajax_remove_license_standalone(): void {
        if ( ! check_ajax_referer( 'wacrm_license_action', '_nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 ); wp_die();
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 ); wp_die();
        }
        delete_option( 'wacrm_license_key' );
        delete_option( 'wacrm_license_email' );
        delete_option( 'wacrm_license_error' );
        wp_send_json_success( [ 'message' => 'License removed.' ] );
        wp_die();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX registration (full plugin)
    // ─────────────────────────────────────────────────────────────────────────

    private static function register_ajax(): void {
        $actions = [
            'wacrm_save_license', 'wacrm_remove_license', 'wacrm_save_settings',
            'wacrm_create_instance', 'wacrm_delete_instance', 'wacrm_restart_instance',
            'wacrm_get_qr', 'wacrm_instance_status', 'wacrm_toggle_instance', 'wacrm_fetch_instances',
            'wacrm_get_contacts', 'wacrm_save_contact', 'wacrm_delete_contact', 'wacrm_import_contacts_csv',
            'wacrm_get_lists', 'wacrm_save_list', 'wacrm_delete_list', 'wacrm_assign_list',
            'wacrm_get_campaigns', 'wacrm_save_campaign', 'wacrm_delete_campaign',
            'wacrm_save_campaign_steps', 'wacrm_launch_campaign',
            'wacrm_get_automations', 'wacrm_save_automation', 'wacrm_delete_automation',
            'wacrm_get_templates', 'wacrm_save_template', 'wacrm_delete_template',
            'wacrm_get_orders', 'wacrm_send_order_message',
            'wacrm_get_logs', 'wacrm_get_dashboard_data',
            'wacrm_get_fields', 'wacrm_save_field', 'wacrm_delete_field',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'handle_ajax' ] );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Central AJAX dispatcher
    // ─────────────────────────────────────────────────────────────────────────

    public static function handle_ajax(): void {
        // --- Security ---
        $nonce = sanitize_text_field( wp_unslash( $_POST['_nonce'] ?? $_GET['_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wacrm_admin_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid security token. Please reload the page.' ], 403 );
            wp_die();
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
            wp_die();
        }

        $action = sanitize_text_field( wp_unslash( $_POST['action'] ?? $_GET['action'] ?? '' ) );
        $method = str_replace( 'wacrm_', '', $action );

        switch ( $method ) {

            // ── License ───────────────────────────────────────────────────────
            case 'save_license':
                $key   = sanitize_text_field( wp_unslash( $_POST['license_key']   ?? '' ) );
                $email = sanitize_email(      wp_unslash( $_POST['license_email'] ?? '' ) );
                update_option( 'wacrm_license_key',   $key );
                update_option( 'wacrm_license_email', $email );
                WACRM_Quota::reset();
                wp_send_json_success( [ 'message' => 'License saved.' ] );
                break;

            case 'remove_license':
                W_A_A_T_L_A_S_S_E_N_D_E_R_Base::remove_license_key( WACRM_FILE, $msg );
                delete_option( 'wacrm_license_key' );
                delete_option( 'wacrm_license_email' );
                wp_send_json_success( [ 'message' => $msg ?: 'License removed.' ] );
                break;

            // ── Settings ──────────────────────────────────────────────────────
            case 'save_settings':
                $api_url = esc_url_raw( wp_unslash( $_POST['api_url'] ?? '' ) );
                $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
                update_option( 'wacrm_api_url', $api_url );
                // Only update encrypted key if a new one was provided
                if ( ! empty( $api_key ) ) {
                    update_option( 'wacrm_api_key_enc', WACRM_Crypto::encrypt( $api_key ) );
                }
                update_option( 'wacrm_global_rate_per_hour', absint( $_POST['rate_per_hour']    ?? 200 ) );
                update_option( 'wacrm_otp_enabled',          (int) ! empty( $_POST['otp_enabled'] ) );
                update_option( 'wacrm_otp_expiry',           absint( $_POST['otp_expiry']       ?? 300 ) );
                update_option( 'wacrm_otp_max_attempts',     absint( $_POST['otp_max_attempts'] ?? 5   ) );
                update_option( 'wacrm_otp_template',         absint( $_POST['otp_template']     ?? 0   ) );
                wp_send_json_success( [ 'message' => 'Settings saved successfully.' ] );
                break;

            // ── Instances ─────────────────────────────────────────────────────
            case 'fetch_instances':
                $res = WACRM_Evolution::get()->fetch_instances();
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( [ 'message' => $res['error'] ] );
                    break;
                }
                $list = is_array( $res ) ? array_values( $res ) : [];
                global $wpdb;
                foreach ( $list as &$inst ) {
                    $name = $inst['instance']['instanceName'] ?? $inst['instance_name'] ?? '';
                    if ( ! $name ) continue;
                    $inst['instance_name'] = $name;
                    $state = $inst['instance']['connectionStatus'] ?? $inst['instance']['state'] ?? 'pending';
                    $wpdb->replace( WACRM_DB::instances(), [
                        'instance_name' => $name,
                        'status'        => $state,
                        'enabled'       => 1,
                    ] );
                }
                unset( $inst );
                wp_send_json_success( $list );
                break;

            case 'create_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                if ( empty( $name ) ) { wp_send_json_error( [ 'message' => 'Instance name required.' ] ); break; }
                $res = WACRM_Evolution::get()->create_instance( $name );
                if ( isset( $res['error'] ) ) { wp_send_json_error( [ 'message' => $res['error'] ] ); break; }
                global $wpdb;
                $wpdb->replace( WACRM_DB::instances(), [ 'instance_name' => $name, 'status' => 'pending', 'enabled' => 1 ] );
                wp_send_json_success( $res );
                break;

            case 'delete_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                WACRM_Evolution::get()->delete_instance( $name );
                global $wpdb;
                $wpdb->delete( WACRM_DB::instances(), [ 'instance_name' => $name ] );
                wp_send_json_success( [ 'message' => 'Instance deleted.' ] );
                break;

            case 'restart_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->restart_instance( $name );
                wp_send_json_success( $res );
                break;

            case 'get_qr':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->get_qr( $name );
                if ( isset( $res['error'] ) ) { wp_send_json_error( [ 'message' => $res['error'] ] ); break; }
                wp_send_json_success( $res );
                break;

            case 'instance_status':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->instance_status( $name );
                if ( ! empty( $res['instance']['state'] ) ) {
                    global $wpdb;
                    $wpdb->update( WACRM_DB::instances(), [ 'status' => $res['instance']['state'] ], [ 'instance_name' => $name ] );
                }
                wp_send_json_success( $res );
                break;

            case 'toggle_instance':
                $name    = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $enabled = (int) ! empty( $_POST['enabled'] );
                global $wpdb;
                $wpdb->update( WACRM_DB::instances(), [ 'enabled' => $enabled ], [ 'instance_name' => $name ] );
                wp_send_json_success( [ 'enabled' => $enabled ] );
                break;

            // ── Contacts ──────────────────────────────────────────────────────
            case 'get_contacts':
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
                $limit  = 25;
                $offset = ( $page - 1 ) * $limit;
                $args   = [ 'limit' => $limit, 'offset' => $offset, 'search' => $search ];
                wp_send_json_success( [
                    'contacts' => WACRM_Contacts::all( $args ),
                    'total'    => WACRM_Contacts::count( $args ),
                ] );
                break;

            case 'save_contact':
                $id   = absint( $_POST['id'] ?? 0 );
                $data = [
                    'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
                    'last_name'  => sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) ),
                    'phone'      => sanitize_text_field( wp_unslash( $_POST['phone']      ?? '' ) ),
                    'whatsapp'   => sanitize_text_field( wp_unslash( $_POST['whatsapp']   ?? '' ) ),
                    'email'      => sanitize_email(      wp_unslash( $_POST['email']      ?? '' ) ),
                    'tags'       => sanitize_text_field( wp_unslash( $_POST['tags']       ?? '' ) ),
                ];
                if ( $id ) {
                    WACRM_Contacts::update( $id, $data );
                    wp_send_json_success( [ 'id' => $id, 'message' => 'Contact updated.' ] );
                } else {
                    $new_id = WACRM_Contacts::insert( $data );
                    WACRM_Automations::fire( 'new_contact_added', array_merge( $data, [ 'contact_id' => $new_id ] ) );
                    wp_send_json_success( [ 'id' => $new_id, 'message' => 'Contact added.' ] );
                }
                break;

            case 'delete_contact':
                $id = absint( $_POST['id'] ?? 0 );
                if ( ! $id ) { wp_send_json_error( [ 'message' => 'Invalid ID.' ] ); break; }
                WACRM_Contacts::delete( $id );
                wp_send_json_success( [ 'message' => 'Contact deleted.' ] );
                break;

            case 'import_contacts_csv':
                if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
                    wp_send_json_error( [ 'message' => 'No file uploaded.' ] ); break;
                }
                $result = WACRM_Contacts::import_csv( $_FILES['csv_file']['tmp_name'] );
                wp_send_json_success( $result );
                break;

            // ── Contact Fields ────────────────────────────────────────────────
            case 'get_fields':
                global $wpdb;
                wp_send_json_success( $wpdb->get_results(
                    "SELECT * FROM " . WACRM_DB::contact_fields() . " ORDER BY sort_order ASC", ARRAY_A ) ?: [] );
                break;

            case 'save_field':
                global $wpdb;
                $fid  = absint( $_POST['id'] ?? 0 );
                $fdata = [
                    'field_key'   => sanitize_key(        wp_unslash( $_POST['field_key']   ?? '' ) ),
                    'field_label' => sanitize_text_field( wp_unslash( $_POST['field_label'] ?? '' ) ),
                    'field_type'  => sanitize_text_field( wp_unslash( $_POST['field_type']  ?? 'text' ) ),
                    'field_opts'  => sanitize_textarea_field( wp_unslash( $_POST['field_opts'] ?? '' ) ),
                    'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
                ];
                if ( $fid ) { $wpdb->update( WACRM_DB::contact_fields(), $fdata, [ 'id' => $fid ] ); wp_send_json_success( [ 'id' => $fid ] ); }
                else        { $wpdb->insert( WACRM_DB::contact_fields(), $fdata ); wp_send_json_success( [ 'id' => $wpdb->insert_id ] ); }
                break;

            case 'delete_field':
                global $wpdb;
                $fid = absint( $_POST['id'] ?? 0 );
                $k   = $wpdb->get_var( $wpdb->prepare( "SELECT field_key FROM " . WACRM_DB::contact_fields() . " WHERE id=%d", $fid ) );
                if ( $k ) $wpdb->delete( WACRM_DB::contact_meta(), [ 'field_key' => $k ] );
                $wpdb->delete( WACRM_DB::contact_fields(), [ 'id' => $fid ] );
                wp_send_json_success( [ 'message' => 'Field deleted.' ] );
                break;

            // ── Lists ─────────────────────────────────────────────────────────
            case 'get_lists':
                global $wpdb;
                $rows = $wpdb->get_results(
                    "SELECT l.*, (SELECT COUNT(*) FROM " . WACRM_DB::list_contacts() . " lc WHERE lc.list_id=l.id) as contact_count
                     FROM " . WACRM_DB::lists() . " ORDER BY id DESC", ARRAY_A ) ?: [];
                wp_send_json_success( $rows );
                break;

            case 'save_list':
                global $wpdb;
                $lid   = absint( $_POST['id'] ?? 0 );
                $ldata = [
                    'list_name'   => sanitize_text_field(  wp_unslash( $_POST['list_name']   ?? '' ) ),
                    'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                ];
                if ( $lid ) { $wpdb->update( WACRM_DB::lists(), $ldata, [ 'id' => $lid ] ); wp_send_json_success( [ 'id' => $lid, 'message' => 'List updated.' ] ); }
                else        { $wpdb->insert( WACRM_DB::lists(), $ldata ); wp_send_json_success( [ 'id' => $wpdb->insert_id, 'message' => 'List created.' ] ); }
                break;

            case 'delete_list':
                global $wpdb;
                $lid = absint( $_POST['id'] ?? 0 );
                $wpdb->delete( WACRM_DB::list_contacts(), [ 'list_id' => $lid ], [ '%d' ] );
                $wpdb->delete( WACRM_DB::lists(),         [ 'id'      => $lid ], [ '%d' ] );
                wp_send_json_success( [ 'message' => 'List deleted.' ] );
                break;

            case 'assign_list':
                $cid = absint( $_POST['contact_id'] ?? 0 );
                $lid = absint( $_POST['list_id']    ?? 0 );
                if ( $cid && $lid ) WACRM_Contacts::assign_to_list( $cid, $lid );
                wp_send_json_success( [ 'message' => 'Assigned.' ] );
                break;

            // ── Campaigns ─────────────────────────────────────────────────────
            case 'get_campaigns':
                wp_send_json_success( WACRM_Campaigns::all() );
                break;

            case 'save_campaign':
                $cid  = absint( $_POST['id'] ?? 0 );
                $cdata = [
                    'campaign_name'   => sanitize_text_field( wp_unslash( $_POST['campaign_name'] ?? '' ) ),
                    'target_lists'    => array_map( 'absint', (array) ( $_POST['target_lists'] ?? [] ) ),
                    'rate_per_hour'   => absint( $_POST['rate_per_hour'] ?? 200 ),
                    'schedule_from'   => sanitize_text_field( wp_unslash( $_POST['schedule_from'] ?? '09:00' ) ),
                    'schedule_to'     => sanitize_text_field( wp_unslash( $_POST['schedule_to']   ?? '20:00' ) ),
                    'randomize_delay' => (int) ! empty( $_POST['randomize_delay'] ),
                    'filter_logic'    => sanitize_text_field( wp_unslash( $_POST['filter_logic']  ?? 'AND' ) ),
                ];
                if ( $cid ) { WACRM_Campaigns::update( $cid, $cdata ); wp_send_json_success( [ 'id' => $cid, 'message' => 'Campaign updated.' ] ); }
                else        { wp_send_json_success( [ 'id' => WACRM_Campaigns::insert( $cdata ), 'message' => 'Campaign created.' ] ); }
                break;

            case 'delete_campaign':
                WACRM_Campaigns::delete( absint( $_POST['id'] ?? 0 ) );
                wp_send_json_success( [ 'message' => 'Campaign deleted.' ] );
                break;

            case 'save_campaign_steps':
                $cid   = absint( $_POST['campaign_id'] ?? 0 );
                $steps = json_decode( wp_unslash( $_POST['steps'] ?? '[]' ), true ) ?: [];
                WACRM_Campaigns::save_steps( $cid, $steps );
                wp_send_json_success( [ 'message' => 'Steps saved.' ] );
                break;

            case 'launch_campaign':
                if ( WACRM_Quota::is_blocked() ) { wp_send_json_error( [ 'message' => 'Message quota exceeded.' ] ); break; }
                $res = WACRM_Campaigns::launch( absint( $_POST['id'] ?? 0 ) );
                if ( isset( $res['error'] ) ) wp_send_json_error( $res );
                else wp_send_json_success( $res );
                break;

            // ── Automations ───────────────────────────────────────────────────
            case 'get_automations':
                wp_send_json_success( WACRM_Automations::all() );
                break;

            case 'save_automation':
                $aid  = absint( $_POST['id'] ?? 0 );
                $adata = [
                    'auto_name'    => sanitize_text_field( wp_unslash( $_POST['auto_name']    ?? '' ) ),
                    'trigger_type' => sanitize_text_field( wp_unslash( $_POST['trigger_type'] ?? '' ) ),
                    'conditions'   => json_decode( wp_unslash( $_POST['conditions'] ?? '[]' ), true ) ?: [],
                    'actions'      => json_decode( wp_unslash( $_POST['actions']    ?? '[]' ), true ) ?: [],
                    'status'       => sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) ),
                ];
                if ( $aid ) { WACRM_Automations::update( $aid, $adata ); wp_send_json_success( [ 'id' => $aid, 'message' => 'Automation updated.' ] ); }
                else        { wp_send_json_success( [ 'id' => WACRM_Automations::insert( $adata ), 'message' => 'Automation created.' ] ); }
                break;

            case 'delete_automation':
                WACRM_Automations::delete( absint( $_POST['id'] ?? 0 ) );
                wp_send_json_success( [ 'message' => 'Automation deleted.' ] );
                break;

            // ── Templates ─────────────────────────────────────────────────────
            case 'get_templates':
                $cat = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
                wp_send_json_success( WACRM_Templates::all( $cat ) );
                break;

            case 'save_template':
                $tid  = absint( $_POST['id'] ?? 0 );
                $tdata = [
                    'tpl_name' => sanitize_text_field( wp_unslash( $_POST['tpl_name'] ?? '' ) ),
                    'category' => sanitize_text_field( wp_unslash( $_POST['category'] ?? 'manual' ) ),
                    'body'     => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
                ];
                if ( $tid ) { WACRM_Templates::update( $tid, $tdata ); wp_send_json_success( [ 'id' => $tid, 'message' => 'Template updated.' ] ); }
                else        { wp_send_json_success( [ 'id' => WACRM_Templates::insert( $tdata ), 'message' => 'Template created.' ] ); }
                break;

            case 'delete_template':
                WACRM_Templates::delete( absint( $_POST['id'] ?? 0 ) );
                wp_send_json_success( [ 'message' => 'Template deleted.' ] );
                break;

            // ── WooCommerce ───────────────────────────────────────────────────
            case 'get_orders':
                if ( ! class_exists( 'WooCommerce' ) ) { wp_send_json_error( [ 'message' => 'WooCommerce not active.' ] ); break; }
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $orders = wc_get_orders( [ 'limit' => 20, 'paged' => $page ] );
                $out    = [];
                foreach ( $orders as $order ) {
                    $items = [];
                    foreach ( $order->get_items() as $item ) {
                        $items[] = [ 'name' => $item->get_name(), 'qty' => $item->get_quantity() ];
                    }
                    $out[] = [
                        'id'            => $order->get_id(),
                        'customer_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
                        'phone'         => $order->get_billing_phone(),
                        'email'         => $order->get_billing_email(),
                        'total'         => $order->get_formatted_order_total(),
                        'status'        => wc_get_order_status_name( $order->get_status() ),
                        'items'         => $items,
                        'date'          => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
                    ];
                }
                wp_send_json_success( $out );
                break;

            case 'send_order_message':
                $oid = absint( $_POST['order_id'] ?? 0 );
                $msg = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
                $res = WACRM_WooCommerce::manual_send( $oid, $msg );
                if ( isset( $res['error'] ) ) wp_send_json_error( $res );
                else wp_send_json_success( array_merge( $res, [ 'message' => 'Message sent successfully!' ] ) );
                break;

            // ── Logs ──────────────────────────────────────────────────────────
            case 'get_logs':
                global $wpdb;
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $limit  = 30;
                $offset = ( $page - 1 ) * $limit;
                $logs   = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM " . WACRM_DB::message_logs() . " ORDER BY id DESC LIMIT %d OFFSET %d",
                    $limit, $offset ), ARRAY_A ) ?: [];
                wp_send_json_success( $logs );
                break;

            // ── Dashboard ─────────────────────────────────────────────────────
            case 'get_dashboard_data':
                $cache_key = 'wacrm_dashboard_data';
                $data      = get_transient( $cache_key );
                if ( ! $data ) {
                    global $wpdb;
                    $today = current_time( 'Y-m-d' );
                    $month = current_time( 'Y-m' );
                    $data  = [
                        'sent_today'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . WACRM_DB::message_logs() . " WHERE status='sent' AND DATE(sent_at)=%s", $today ) ),
                        'sent_month'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . WACRM_DB::message_logs() . " WHERE status='sent' AND DATE_FORMAT(sent_at,'%%Y-%%m')=%s", $month ) ),
                        'failed'             => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WACRM_DB::message_logs() . " WHERE status='failed'" ),
                        'quota_used'         => WACRM_Quota::used(),
                        'quota_max'          => WACRM_QUOTA_MAX,
                        'total_contacts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WACRM_DB::contacts() ),
                        'active_campaigns'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WACRM_DB::campaigns() . " WHERE status='running'" ),
                        'active_automations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WACRM_DB::automations() . " WHERE status='active'" ),
                        'instance_status'    => WACRM_Helpers::active_instance() ? 'connected' : 'disconnected',
                        'daily_chart'        => $wpdb->get_results(
                            "SELECT DATE(sent_at) as date, COUNT(*) as count FROM " . WACRM_DB::message_logs()
                            . " WHERE status='sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY DATE(sent_at) ORDER BY date ASC", ARRAY_A ),
                    ];
                    set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
                }
                wp_send_json_success( $data );
                break;

            default:
                wp_send_json_error( [ 'message' => 'Unknown action: ' . esc_html( $method ) ] );
        }

        wp_die();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page renderers
    // ─────────────────────────────────────────────────────────────────────────

    public static function page_dashboard():   void { require WACRM_DIR . 'admin/pages/page-dashboard.php'; }
    public static function page_instances():   void { require WACRM_DIR . 'admin/pages/page-instances.php'; }
    public static function page_contacts():    void { require WACRM_DIR . 'admin/pages/page-contacts.php'; }
    public static function page_lists():       void { require WACRM_DIR . 'admin/pages/page-lists.php'; }
    public static function page_campaigns():   void { require WACRM_DIR . 'admin/pages/page-campaigns.php'; }
    public static function page_automations(): void { require WACRM_DIR . 'admin/pages/page-automations.php'; }
    public static function page_templates():   void { require WACRM_DIR . 'admin/pages/page-templates.php'; }
    public static function page_woocommerce(): void { require WACRM_DIR . 'admin/pages/page-woocommerce.php'; }
    public static function page_logs():        void { require WACRM_DIR . 'admin/pages/page-logs.php'; }
    public static function page_settings():    void { require WACRM_DIR . 'admin/pages/page-settings.php'; }
    public static function page_license():     void { require WACRM_DIR . 'admin/pages/page-license.php'; }
}
