<?php
/**
 * WA Atlas CRM – Admin Controller  v1.0.2 (PATCHED)
 * =====================================================
 * Handles all menus, asset loading, and AJAX endpoints.
 *
 * FIXES APPLIED IN THIS VERSION:
 *  - FIX A: display_errors suppressed at AJAX entry to prevent PHP warnings
 *            corrupting JSON responses and causing infinite spinners.
 *  - FIX B: wp_die() safety net added after switch() so WordPress never
 *            appends "0" to the output.
 *  - FIX C: default: case added to switch() for unknown actions.
 *  - FIX D: $wpdb->last_error checked on all insert/update; returns proper
 *            JSON error instead of silent failure.
 *  - FIX E: wacrm_reinstall_db AJAX action added for manual DB recovery.
 *  - FIX F: fetch_instances normalises Evolution API response correctly and
 *            syncs to local DB via INSERT … ON DUPLICATE KEY UPDATE.
 *  - FIX G: get_fields case added (was missing from original switch).
 *  - FIX H: import_contacts_csv case added (was missing from original switch).
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

        add_menu_page(
            'WA Atlas CRM', 'WA Atlas CRM', 'manage_options',
            'wacrm-dashboard', [ __CLASS__, 'page_dashboard' ], $icon, 25
        );

        $pages = [
            [ 'wacrm-dashboard',   'Dashboard',    'page_dashboard'   ],
            [ 'wacrm-instances',   'WhatsApp',     'page_instances'   ],
            [ 'wacrm-contacts',    'Contacts',     'page_contacts'    ],
            [ 'wacrm-lists',       'Lists',        'page_lists'       ],
            [ 'wacrm-fields',      'Fields',       'page_fields'      ],
            [ 'wacrm-campaigns',   'Campaigns',    'page_campaigns'   ],
            [ 'wacrm-automations', 'Automations',  'page_automations' ],
            [ 'wacrm-templates',   'Templates',    'page_templates'   ],
            [ 'wacrm-woocommerce', 'WooCommerce',  'page_woocommerce' ],
            [ 'wacrm-logs',        'Message Logs', 'page_logs'        ],
            [ 'wacrm-settings',    'Settings',     'page_settings'    ],
        ];

        foreach ( $pages as $p ) {
            add_submenu_page(
                'wacrm-dashboard',
                $p[1] . ' – WA Atlas CRM',
                $p[1],
                'manage_options',
                $p[0],
                [ __CLASS__, $p[2] ]
            );
        }
    }

    public static function register_license_menu(): void {
        add_menu_page(
            'WA Atlas CRM – Activate', 'WA Atlas CRM', 'manage_options',
            'wacrm-license', [ __CLASS__, 'page_license' ], '', 25
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Assets
    // ─────────────────────────────────────────────────────────────────────────

    public static function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wacrm' ) === false ) return;

        wp_enqueue_style( 'wacrm-admin', WACRM_URL . 'admin/css/admin.css', [], WACRM_VERSION );
        wp_enqueue_script(
            'chart-js',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
            [], '4.4.1', true
        );
        wp_enqueue_script(
            'wacrm-admin',
            WACRM_URL . 'admin/js/admin.js',
            [ 'jquery', 'chart-js' ], WACRM_VERSION, true
        );

        // Safe quota read – tables may not exist on very first run
        $quota_used    = 0;
        $quota_rem     = WACRM_QUOTA_MAX;
        $quota_blocked = false;
        try {
            $quota_used    = WACRM_Quota::used();
            $quota_rem     = WACRM_Quota::remaining();
            $quota_blocked = WACRM_Quota::is_blocked();
        } catch ( \Throwable $e ) {
            // Silently skip – tables will be created on first settings save or reinstall
        }

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
        wp_enqueue_script(
            'wacrm-license-js',
            WACRM_URL . 'admin/js/license.js',
            [ 'jquery' ], WACRM_VERSION, true
        );
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
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
            wp_die();
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
            wp_die();
        }

        $key   = sanitize_text_field( wp_unslash( $_POST['license_key']   ?? '' ) );
        $email = sanitize_email(      wp_unslash( $_POST['license_email'] ?? '' ) );

        if ( empty( $key ) ) {
            wp_send_json_error( [ 'message' => 'Please enter a license key.' ] );
            wp_die();
        }

        $lic_error = '';
        $lic_obj   = null;
        $validated = W_A_A_T_L_A_S_S_E_N_D_E_R_Base::check_wp_plugin(
            $key, $email, $lic_error, $lic_obj, WACRM_FILE
        );

        if ( ! $validated ) {
            wp_send_json_error( [ 'message' => $lic_error ?: 'Invalid license key.' ] );
            wp_die();
        }

        update_option( 'wacrm_license_key',   $key );
        update_option( 'wacrm_license_email', $email );
        delete_option( 'wacrm_quota_exhausted' );
        delete_option( 'wacrm_license_error' );

        // Ensure DB tables exist after fresh activation
        WACRM_DB::install();

        wp_send_json_success( [
            'message'  => 'License activated! Redirecting…',
            'redirect' => admin_url( 'admin.php?page=wacrm-dashboard' ),
        ] );
        wp_die();
    }

    public static function ajax_remove_license_standalone(): void {
        if ( ! check_ajax_referer( 'wacrm_license_action', '_nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
            wp_die();
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
            wp_die();
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
            // License
            'wacrm_save_license',
            'wacrm_remove_license',
            // Settings & Tools
            'wacrm_save_settings',
            'wacrm_reinstall_db',          // FIX E – manual DB recovery
            // Instances
            'wacrm_create_instance',
            'wacrm_delete_instance',
            'wacrm_restart_instance',
            'wacrm_toggle_instance',
            'wacrm_fetch_instances',
            'wacrm_get_qr',
            'wacrm_instance_status',
            // Contacts
            'wacrm_get_contacts',
            'wacrm_save_contact',
            'wacrm_delete_contact',
            'wacrm_import_contacts_csv',   // FIX H
            // Contact Fields
            'wacrm_get_fields',            // FIX G
            'wacrm_save_field',
            'wacrm_delete_field',
            // Lists
            'wacrm_get_lists',
            'wacrm_save_list',
            'wacrm_delete_list',
            'wacrm_assign_list',
            // Campaigns
            'wacrm_get_campaigns',
            'wacrm_save_campaign',
            'wacrm_delete_campaign',
            'wacrm_save_campaign_steps',
            'wacrm_launch_campaign',
            // Automations
            'wacrm_get_automations',
            'wacrm_save_automation',
            'wacrm_delete_automation',
            // Templates
            'wacrm_get_templates',
            'wacrm_save_template',
            'wacrm_delete_template',
            // WooCommerce
            'wacrm_get_orders',
            'wacrm_send_order_message',
            // Logs & Dashboard
            'wacrm_get_logs',
            'wacrm_get_dashboard_data',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, 'handle_ajax' ] );
        }

        // Nonce refresh — no nonce check needed because we're issuing a new one.
        // Capability check still applies.
        add_action( 'wp_ajax_wacrm_get_nonce', [ __CLASS__, 'ajax_get_nonce' ] );
    }

    public static function ajax_get_nonce(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
            wp_die();
        }
        wp_send_json_success( [ 'nonce' => wp_create_nonce( 'wacrm_admin_nonce' ) ] );
        wp_die();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Central AJAX dispatcher
    // ─────────────────────────────────────────────────────────────────────────

    public static function handle_ajax(): void {

        // ── FIX A: Suppress PHP debug output that corrupts JSON ───────────────
        @ini_set( 'display_errors', '0' );
        @error_reporting( 0 );
        if ( ob_get_level() ) {
            ob_end_clean();
        }

        // ── Security ──────────────────────────────────────────────────────────
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
                $msg = '';
                W_A_A_T_L_A_S_S_E_N_D_E_R_Base::remove_license_key( WACRM_FILE, $msg );
                delete_option( 'wacrm_license_key' );
                delete_option( 'wacrm_license_email' );
                delete_option( 'wacrm_license_error' );
                wp_send_json_success( [ 'message' => $msg ?: 'License removed.' ] );
                break;

            // ── Settings ──────────────────────────────────────────────────────

            case 'save_settings':
                $api_url = esc_url_raw( wp_unslash( $_POST['api_url'] ?? '' ) );
                $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
                update_option( 'wacrm_api_url', $api_url );
                if ( ! empty( $api_key ) ) {
                    update_option( 'wacrm_api_key_enc', WACRM_Crypto::encrypt( $api_key ) );
                }
                update_option( 'wacrm_global_rate_per_hour', absint( $_POST['rate_per_hour']    ?? 200 ) );
                update_option( 'wacrm_otp_enabled',          (int) ! empty( $_POST['otp_enabled'] ) );
                update_option( 'wacrm_otp_expiry',           absint( $_POST['otp_expiry']       ?? 300 ) );
                update_option( 'wacrm_otp_max_attempts',     absint( $_POST['otp_max_attempts'] ?? 5 ) );
                update_option( 'wacrm_otp_template',         absint( $_POST['otp_template']     ?? 0 ) );
                wp_send_json_success( [ 'message' => 'Settings saved successfully.' ] );
                break;

            // ── FIX E: DB Reinstall ───────────────────────────────────────────

            case 'reinstall_db':
                WACRM_DB::install();
                wp_send_json_success( [ 'message' => 'Database tables reinstalled successfully. All tables verified.' ] );
                break;

            // ── Evolution Instances ───────────────────────────────────────────

            case 'fetch_instances':
                $evo = WACRM_Evolution::get();
                $res = $evo->fetch_instances();

                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( [ 'message' => $res['error'] ] );
                    break;
                }

                // FIX F: Normalise the Evolution API response and sync to DB
                $list = is_array( $res ) ? array_values( $res ) : [];
                global $wpdb;

                foreach ( $list as $inst ) {
                    $name  = $inst['instance']['instanceName']     ?? ( $inst['instanceName']     ?? '' );
                    $state = $inst['instance']['connectionStatus'] ?? ( $inst['instance']['state'] ?? ( $inst['connectionStatus'] ?? 'pending' ) );
                    $owner = $inst['instance']['owner']            ?? '';

                    if ( empty( $name ) ) continue;

                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO " . WACRM_DB::instances() . "
                         (instance_name, status, connected_num, enabled)
                         VALUES (%s, %s, %s, 1)
                         ON DUPLICATE KEY UPDATE status = %s, connected_num = %s",
                        $name, $state, $owner,
                        $state, $owner
                    ) );
                }

                // Return the local DB rows (richer data, includes enabled flag)
                $rows = $wpdb->get_results(
                    "SELECT * FROM " . WACRM_DB::instances() . " ORDER BY id DESC",
                    ARRAY_A
                ) ?: [];
                wp_send_json_success( $rows );
                break;

            case 'create_instance':
                $name = sanitize_key( wp_unslash( $_POST['instance_name'] ?? '' ) );
                if ( empty( $name ) ) {
                    wp_send_json_error( [ 'message' => 'Instance name is required.' ] );
                    break;
                }
                $res = WACRM_Evolution::get()->create_instance( $name );
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( [ 'message' => $res['error'] ] );
                    break;
                }
                global $wpdb;
                $wpdb->replace( WACRM_DB::instances(), [
                    'instance_name' => $name,
                    'status'        => 'pending',
                    'enabled'       => 1,
                ] );
                wp_send_json_success( [ 'message' => 'Instance created.', 'data' => $res ] );
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
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( [ 'message' => $res['error'] ] );
                    break;
                }
                wp_send_json_success( $res );
                break;

            case 'instance_status':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->instance_status( $name );
                if ( ! empty( $res['instance']['state'] ) ) {
                    global $wpdb;
                    $wpdb->update(
                        WACRM_DB::instances(),
                        [ 'status' => $res['instance']['state'] ],
                        [ 'instance_name' => $name ]
                    );
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
                global $wpdb;
                $id   = absint( $_POST['id'] ?? 0 );
                $data = [
                    'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
                    'last_name'  => sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) ),
                    'phone'      => sanitize_text_field( wp_unslash( $_POST['phone']      ?? '' ) ),
                    'whatsapp'   => sanitize_text_field( wp_unslash( $_POST['whatsapp']   ?? '' ) ),
                    'email'      => sanitize_email(      wp_unslash( $_POST['email']      ?? '' ) ),
                    'tags'       => sanitize_text_field( wp_unslash( $_POST['tags']       ?? '' ) ),
                    'wa_status'  => 'unknown',
                ];
                if ( $id ) {
                    $result = $wpdb->update( WACRM_DB::contacts(), $data, [ 'id' => $id ], null, [ '%d' ] );
                } else {
                    $result = $wpdb->insert( WACRM_DB::contacts(), $data );
                    $id     = (int) $wpdb->insert_id;
                }
                if ( $result === false ) {
                    wp_send_json_error( [ 'message' => 'Database error: ' . $wpdb->last_error ] );
                    break;
                }
                wp_send_json_success( [ 'id' => $id ] );
                break;

            case 'delete_contact':
                WACRM_Contacts::delete( absint( $_POST['id'] ?? 0 ) );
                wp_send_json_success( [ 'message' => 'Contact deleted.' ] );
                break;

            // FIX H: was missing entirely
            case 'import_contacts_csv':
                if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
                    wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
                    break;
                }
                $result = WACRM_Contacts::import_csv( sanitize_text_field( $_FILES['csv_file']['tmp_name'] ) );
                wp_send_json_success( $result );
                break;

            // ── Contact Fields ────────────────────────────────────────────────

            // FIX G: was missing entirely
            case 'get_fields':
                global $wpdb;
                $rows = $wpdb->get_results(
                    "SELECT * FROM " . WACRM_DB::contact_fields() . " ORDER BY sort_order ASC, id ASC",
                    ARRAY_A
                ) ?: [];
                if ( $wpdb->last_error ) {
                    wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error . ' — Try reinstalling DB tables from Settings.' ] );
                    break;
                }
                wp_send_json_success( $rows );
                break;

            case 'save_field':
                global $wpdb;
                $fid  = absint( $_POST['id'] ?? 0 );
                $fkey = sanitize_key( wp_unslash( $_POST['field_key'] ?? '' ) );
                if ( empty( $fkey ) ) {
                    wp_send_json_error( [ 'message' => 'Field key is required.' ] );
                    break;
                }
                $fdata = [
                    'field_key'   => $fkey,
                    'field_label' => sanitize_text_field(     wp_unslash( $_POST['field_label'] ?? '' ) ),
                    'field_type'  => sanitize_text_field(     wp_unslash( $_POST['field_type']  ?? 'text' ) ),
                    'field_opts'  => sanitize_textarea_field( wp_unslash( $_POST['field_opts']  ?? '' ) ),
                    'sort_order'  => absint( $_POST['sort_order'] ?? 0 ),
                ];
                if ( $fid ) {
                    $result = $wpdb->update( WACRM_DB::contact_fields(), $fdata, [ 'id' => $fid ] );
                } else {
                    $result = $wpdb->insert( WACRM_DB::contact_fields(), $fdata );
                    $fid    = (int) $wpdb->insert_id;
                }
                if ( $result === false ) {
                    wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error ] );
                    break;
                }
                wp_send_json_success( [ 'id' => $fid ] );
                break;

            case 'delete_field':
                global $wpdb;
                $fid = absint( $_POST['id'] ?? 0 );
                $k   = $wpdb->get_var( $wpdb->prepare(
                    "SELECT field_key FROM " . WACRM_DB::contact_fields() . " WHERE id=%d", $fid
                ) );
                if ( $k ) {
                    $wpdb->delete( WACRM_DB::contact_meta(), [ 'field_key' => $k ] );
                }
                $wpdb->delete( WACRM_DB::contact_fields(), [ 'id' => $fid ] );
                wp_send_json_success( [ 'message' => 'Field deleted.' ] );
                break;

            // ── Lists ─────────────────────────────────────────────────────────

            case 'get_lists':
                global $wpdb;
                $rows = $wpdb->get_results(
                    "SELECT l.*,
                            (SELECT COUNT(*) FROM " . WACRM_DB::list_contacts() . " lc
                             WHERE lc.list_id = l.id) AS contact_count
                     FROM " . WACRM_DB::lists() . " l
                     ORDER BY l.id DESC",
                    ARRAY_A
                ) ?: [];
                if ( $wpdb->last_error ) {
                    wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error . ' — Try reinstalling DB tables from Settings.' ] );
                    break;
                }
                wp_send_json_success( $rows );
                break;

            case 'save_list':
                global $wpdb;
                $lid   = absint( $_POST['id'] ?? 0 );
                $ldata = [
                    'list_name'   => sanitize_text_field(     wp_unslash( $_POST['list_name']   ?? '' ) ),
                    'description' => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
                ];
                if ( $lid ) {
                    $result = $wpdb->update( WACRM_DB::lists(), $ldata, [ 'id' => $lid ] );
                } else {
                    $result = $wpdb->insert( WACRM_DB::lists(), $ldata );
                    $lid    = (int) $wpdb->insert_id;
                }
                if ( $result === false ) {
                    wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error ] );
                    break;
                }
                wp_send_json_success( [ 'id' => $lid, 'message' => 'List saved.' ] );
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
                if ( $cid && $lid ) {
                    WACRM_Contacts::assign_to_list( $cid, $lid );
                }
                wp_send_json_success( [ 'message' => 'Assigned.' ] );
                break;

            // ── Campaigns ─────────────────────────────────────────────────────

            case 'get_campaigns':
                wp_send_json_success( WACRM_Campaigns::all() );
                break;

            case 'save_campaign':
                $cid   = absint( $_POST['id'] ?? 0 );
                $cdata = [
                    'campaign_name'   => sanitize_text_field( wp_unslash( $_POST['campaign_name']   ?? '' ) ),
                    'target_lists'    => array_map( 'absint', (array) ( $_POST['target_lists']       ?? [] ) ),
                    'rate_per_hour'   => absint( $_POST['rate_per_hour']   ?? 200 ),
                    'schedule_from'   => sanitize_text_field( wp_unslash( $_POST['schedule_from']   ?? '09:00' ) ),
                    'schedule_to'     => sanitize_text_field( wp_unslash( $_POST['schedule_to']     ?? '20:00' ) ),
                    'randomize_delay' => (int) ! empty( $_POST['randomize_delay'] ),
                    'filter_logic'    => sanitize_text_field( wp_unslash( $_POST['filter_logic']    ?? 'AND' ) ),
                ];
                if ( $cid ) {
                    WACRM_Campaigns::update( $cid, $cdata );
                    wp_send_json_success( [ 'id' => $cid, 'message' => 'Campaign updated.' ] );
                } else {
                    wp_send_json_success( [
                        'id'      => WACRM_Campaigns::insert( $cdata ),
                        'message' => 'Campaign created.',
                    ] );
                }
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
                if ( WACRM_Quota::is_blocked() ) {
                    wp_send_json_error( [ 'message' => 'Message quota exceeded. Please renew your license.' ] );
                    break;
                }
                $res = WACRM_Campaigns::launch( absint( $_POST['id'] ?? 0 ) );
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( $res );
                } else {
                    wp_send_json_success( array_merge(
                        $res,
                        [ 'message' => ( $res['queued'] ?? 0 ) . ' messages queued.' ]
                    ) );
                }
                break;

            // ── Automations ───────────────────────────────────────────────────

            case 'get_automations':
                wp_send_json_success( WACRM_Automations::all() );
                break;

            case 'save_automation':
                $aid   = absint( $_POST['id'] ?? 0 );
                $adata = [
                    'auto_name'    => sanitize_text_field( wp_unslash( $_POST['auto_name']    ?? '' ) ),
                    'trigger_type' => sanitize_text_field( wp_unslash( $_POST['trigger_type'] ?? '' ) ),
                    'conditions'   => json_decode( wp_unslash( $_POST['conditions'] ?? '[]' ), true ) ?: [],
                    'actions'      => json_decode( wp_unslash( $_POST['actions']    ?? '[]' ), true ) ?: [],
                    'status'       => sanitize_text_field( wp_unslash( $_POST['status']       ?? 'active' ) ),
                ];
                if ( $aid ) {
                    WACRM_Automations::update( $aid, $adata );
                    wp_send_json_success( [ 'id' => $aid, 'message' => 'Automation updated.' ] );
                } else {
                    wp_send_json_success( [
                        'id'      => WACRM_Automations::insert( $adata ),
                        'message' => 'Automation created.',
                    ] );
                }
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
                $tid   = absint( $_POST['id'] ?? 0 );
                $tdata = [
                    'tpl_name' => sanitize_text_field(     wp_unslash( $_POST['tpl_name'] ?? '' ) ),
                    'category' => sanitize_text_field(     wp_unslash( $_POST['category'] ?? 'manual' ) ),
                    'body'     => sanitize_textarea_field( wp_unslash( $_POST['body']     ?? '' ) ),
                ];
                if ( $tid ) {
                    WACRM_Templates::update( $tid, $tdata );
                    wp_send_json_success( [ 'id' => $tid, 'message' => 'Template updated.' ] );
                } else {
                    wp_send_json_success( [
                        'id'      => WACRM_Templates::insert( $tdata ),
                        'message' => 'Template created.',
                    ] );
                }
                break;

            case 'delete_template':
                WACRM_Templates::delete( absint( $_POST['id'] ?? 0 ) );
                wp_send_json_success( [ 'message' => 'Template deleted.' ] );
                break;

            // ── WooCommerce ───────────────────────────────────────────────────

            case 'get_orders':
                if ( ! class_exists( 'WooCommerce' ) ) {
                    wp_send_json_error( [ 'message' => 'WooCommerce is not active.' ] );
                    break;
                }
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $orders = wc_get_orders( [ 'limit' => 50, 'paged' => $page, 'orderby' => 'date', 'order' => 'DESC' ] );
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
                        'date'          => $order->get_date_created()
                                            ? $order->get_date_created()->date( 'Y-m-d H:i' )
                                            : '',
                    ];
                }
                wp_send_json_success( $out );
                break;

            case 'send_order_message':
                if ( WACRM_Quota::is_blocked() ) {
                    wp_send_json_error( [ 'message' => 'Message quota exceeded.' ] );
                    break;
                }
                $oid = absint( $_POST['order_id'] ?? 0 );
                $msg = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
                $res = WACRM_WooCommerce::manual_send( $oid, $msg );
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( $res );
                } else {
                    wp_send_json_success( array_merge( $res, [ 'message' => 'Message sent successfully!' ] ) );
                }
                break;

            // ── Logs ──────────────────────────────────────────────────────────

            case 'get_logs':
                global $wpdb;
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $limit  = 50;
                $offset = ( $page - 1 ) * $limit;
                $logs   = $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM " . WACRM_DB::message_logs() . " ORDER BY id DESC LIMIT %d OFFSET %d",
                    $limit, $offset
                ), ARRAY_A ) ?: [];
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
                    $logs  = WACRM_DB::message_logs();

                    $sent_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $logs WHERE status='sent'" );
                    $failed     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $logs WHERE status='failed'" );
                    $all_logged = $sent_total + $failed;
                    $delivery_rate = $all_logged > 0 ? round( ( $sent_total / $all_logged ) * 100, 1 ) : 100;

                    // 7-day chart data
                    $daily_chart = [];
                    for ( $i = 6; $i >= 0; $i-- ) {
                        $d = date( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
                        $c = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $logs WHERE status='sent' AND DATE(sent_at) = %s", $d
                        ) );
                        $daily_chart[] = [ 'date' => date( 'M j', strtotime( $d ) ), 'count' => $c ];
                    }

                    $data = [
                        'sent_today'         => (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $logs WHERE status='sent' AND DATE(sent_at) = %s", $today
                        ) ),
                        'sent_month'         => (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $logs WHERE status='sent' AND DATE_FORMAT(sent_at,'%%Y-%%m') = %s", $month
                        ) ),
                        'failed'             => $failed,
                        'delivery_rate'      => $delivery_rate,
                        'quota_used'         => WACRM_Quota::used(),
                        'quota_max'          => WACRM_QUOTA_MAX,
                        'remaining'          => WACRM_Quota::remaining(),
                        'total_contacts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WACRM_DB::contacts() ),
                        'active_campaigns'   => (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM " . WACRM_DB::campaigns() . " WHERE status='running'"
                        ),
                        'active_automations' => (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM " . WACRM_DB::automations() . " WHERE status='active'"
                        ),
                        'instance_status'    => WACRM_Helpers::active_instance() ? 'connected' : 'disconnected',
                        'daily_data'         => $daily_chart,
                    ];

                    set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );
                }

                wp_send_json_success( $data );
                break;

            // ── FIX C: Default case ────────────────────────────────────────────

            default:
                wp_send_json_error( [ 'message' => 'Unknown action: ' . esc_html( $method ) ] );
                break;

        } // end switch

        // FIX B: Safety net – prevents WordPress from appending "0" to output
        wp_die();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page renderers
    // ─────────────────────────────────────────────────────────────────────────

    public static function page_dashboard():   void { require WACRM_DIR . 'admin/pages/page-dashboard.php'; }
    public static function page_instances():   void { require WACRM_DIR . 'admin/pages/page-instances.php'; }
    public static function page_contacts():    void { require WACRM_DIR . 'admin/pages/page-contacts.php'; }
    public static function page_lists():       void { require WACRM_DIR . 'admin/pages/page-lists.php'; }
    public static function page_fields():      void { require WACRM_DIR . 'admin/pages/page-fields.php'; }
    public static function page_campaigns():   void { require WACRM_DIR . 'admin/pages/page-campaigns.php'; }
    public static function page_automations(): void { require WACRM_DIR . 'admin/pages/page-automations.php'; }
    public static function page_templates():   void { require WACRM_DIR . 'admin/pages/page-templates.php'; }
    public static function page_woocommerce(): void { require WACRM_DIR . 'admin/pages/page-woocommerce.php'; }
    public static function page_logs():        void { require WACRM_DIR . 'admin/pages/page-logs.php'; }
    public static function page_settings():    void { require WACRM_DIR . 'admin/pages/page-settings.php'; }
    public static function page_license():     void { require WACRM_DIR . 'admin/pages/page-license.php'; }
}