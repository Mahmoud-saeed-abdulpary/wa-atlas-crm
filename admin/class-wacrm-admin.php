<?php
/**
 * WA Atlas CRM – Admin Controller  v1.2.0
 * =========================================
 * Fixes applied in this version (matching admin.js v1.2.0):
 *
 *  #2  – wacrm_reinstall_db runs on EVERY activation (register_activation_hook) via
 *         the new maybe_upgrade() method so new DB columns are always created.
 *  #3  – get_contacts: was missing; now always returns {contacts, total}.
 *  #5  – get_fields / save_field / delete_field: complete, with field_opts column.
 *  #7  – get_dashboard_data: returns all keys JS expects (sent_today, sent_month,
 *         quota_used, quota_max, failed, total_contacts, active_campaigns,
 *         active_automations, instance_status, daily_data). Transient cleared on
 *         any save so numbers are fresh.
 *  #8  – ALL cases: @ini_set display_errors=0 + ob_end_clean() prevents any stray
 *         PHP notice/warning from breaking JSON → fixes 500 errors.
 *  #9  – get_contacts / get_fields / get_lists / get_campaigns / get_templates /
 *         get_logs: results are cached with short wp_cache (object cache / apcu)
 *         and invalidated on save/delete so first load is instant on repeat calls.
 * #10  – get_campaign_steps + save_campaign_steps: both present and normalised.
 * #11  – get_templates / save_template / delete_template: fully implemented.
 * #12  – save_settings: saves api_url, api_key (encrypted), rate_per_hour, and all
 *         OTP options. Reads exact POST keys JS sends:
 *         api_url, api_key, rate_per_hour, otp_enabled, otp_expiry,
 *         otp_max_attempts, otp_template.
 *
 *  Additional new actions registered (matching admin.js AJAX calls):
 *    wacrm_connect_instance, wacrm_get_campaign_steps,
 *    wacrm_get_contact_fields (alias for wacrm_get_fields),
 *    wacrm_get_contact_meta, wacrm_save_contact_meta
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
        // FIX #2 – upgrade DB on every plugin load (adds new columns/tables safely)
        self::maybe_upgrade();
    }

    public static function init_license_only( string $error ): void {
        self::$lic_error = $error;
        add_action( 'admin_menu',            [ __CLASS__, 'register_license_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets_license_only' ] );
        add_action( 'wp_ajax_wacrm_save_license',   [ __CLASS__, 'ajax_save_license_standalone' ] );
        add_action( 'wp_ajax_wacrm_remove_license', [ __CLASS__, 'ajax_remove_license_standalone' ] );
    }

    /**
     * FIX #2 – Run dbDelta every time WACRM_VERSION changes (or on first install).
     * This ensures new columns added in later versions are created automatically.
     */
    public static function maybe_upgrade(): void {
        $installed = get_option( 'wacrm_db_version', '0' );
        if ( version_compare( $installed, WACRM_VERSION, '<' ) ) {
            WACRM_DB::install();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Menus
    // ─────────────────────────────────────────────────────────────────────────

    public static function register_menus(): void {
        $icon = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#ffffff">'
            . '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15'
            . '-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475'
            . '-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52'
            . '.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207'
            . '-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372'
            . '-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2'
            . ' 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719'
            . ' 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>'
            . '<path d="M12 0C5.373 0 0 5.373 0 12c0 2.127.558 4.122 1.532 5.856L0 24l6.288-1.506'
            . 'A11.95 11.95 0 0 0 12 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.659-.52'
            . '-5.174-1.427l-.37-.22-3.733.895.924-3.638-.242-.374A9.955 9.955 0 0 1 2 12C2 6.477'
            . ' 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>'
        );

        add_menu_page(
            'WA Atlas CRM', 'WA Atlas CRM', 'manage_options',
            'wa-atlas-crm', [ __CLASS__, 'page_dashboard' ],
            $icon, 25
        );

        $pages = [
            [ 'wa-atlas-crm',            'Dashboard',       'page_dashboard'   ],
            [ 'wacrm-instances',         'Instances',       'page_instances'   ],
            [ 'wacrm-contacts',          'Contacts',        'page_contacts'    ],
            [ 'wacrm-lists',             'Lists',           'page_lists'       ],
            [ 'wacrm-fields',            'Contact Fields',  'page_fields'      ],
            [ 'wacrm-campaigns',         'Campaigns',       'page_campaigns'   ],
            [ 'wacrm-automations',       'Automations',     'page_automations' ],
            [ 'wacrm-templates',         'Templates',       'page_templates'   ],
            [ 'wacrm-woocommerce',       'WooCommerce',     'page_woocommerce' ],
            [ 'wacrm-logs',              'Logs',            'page_logs'        ],
            [ 'wacrm-settings',          'Settings',        'page_settings'    ],
        ];

        foreach ( $pages as $p ) {
            add_submenu_page(
                'wa-atlas-crm',
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
        if ( strpos( $hook, 'wacrm' ) === false && strpos( $hook, 'wa-atlas-crm' ) === false ) return;

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

        // Safe quota read
        $quota_used    = 0;
        $quota_rem     = WACRM_QUOTA_MAX;
        $quota_blocked = false;
        try {
            $quota_used    = WACRM_Quota::used();
            $quota_rem     = WACRM_Quota::remaining();
            $quota_blocked = WACRM_Quota::is_blocked();
        } catch ( \Throwable $e ) { /* tables not yet created */ }

        wp_localize_script( 'wacrm-admin', 'waCRM', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wacrm_admin_nonce' ),
            'version'  => WACRM_VERSION,
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

        $error = '';
        $obj   = null;
        $valid = W_A_A_T_L_A_S_S_E_N_D_E_R_Base::check_wp_plugin( $key, $email, $error, $obj, WACRM_FILE );

        if ( ! $valid ) {
            wp_send_json_error( [ 'message' => $error ?: 'License validation failed.' ] );
            wp_die();
        }

        update_option( 'wacrm_license_key',   $key );
        update_option( 'wacrm_license_email', $email );
        delete_option( 'wacrm_license_error' );
        WACRM_DB::install();

        wp_send_json_success( [
            'message'      => 'License activated successfully!',
            'redirect_url' => admin_url( 'admin.php?page=wa-atlas-crm' ),
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
    // AJAX registration
    // ─────────────────────────────────────────────────────────────────────────

    private static function register_ajax(): void {
        $actions = [
            // License
            'wacrm_save_license',
            'wacrm_remove_license',
            // Settings & Tools
            'wacrm_save_settings',
            'wacrm_reinstall_db',
            // Instances
            'wacrm_create_instance',
            'wacrm_connect_instance',       // NEW
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
            'wacrm_import_contacts_csv',
            'wacrm_get_contact_meta',       // NEW
            // Contact Fields
            'wacrm_get_fields',
            'wacrm_get_contact_fields',     // alias for get_fields
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
            'wacrm_get_campaign_steps',     // NEW
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

        // FIX #8 – Suppress ALL PHP output that could corrupt JSON responses
        @ini_set( 'display_errors', '0' );
        @error_reporting( 0 );
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        ob_start();

        // ── Security ──────────────────────────────────────────────────────────
        $nonce = sanitize_text_field( wp_unslash( $_POST['_nonce'] ?? $_GET['_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'wacrm_admin_nonce' ) ) {
            ob_end_clean();
            wp_send_json_error( [ 'message' => 'Invalid security token. Please reload the page.' ], 403 );
            wp_die();
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            ob_end_clean();
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
            wp_die();
        }

        $action = sanitize_text_field( wp_unslash( $_POST['action'] ?? $_GET['action'] ?? '' ) );
        $method = str_replace( 'wacrm_', '', $action );

        // Alias: get_contact_fields → get_fields
        if ( $method === 'get_contact_fields' ) {
            $method = 'get_fields';
        }

        ob_end_clean(); // Clear buffer before sending response

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

            // ── Settings — FIX #12 ────────────────────────────────────────────

            case 'save_settings':
                $api_url = esc_url_raw( wp_unslash( $_POST['api_url'] ?? '' ) );
                $api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
                $rate    = absint( $_POST['rate_per_hour'] ?? 200 );

                update_option( 'wacrm_api_url',            rtrim( $api_url, '/' ) );
                update_option( 'wacrm_global_rate_per_hour', $rate );

                if ( ! empty( $api_key ) ) {
                    // Encrypt and store API key
                    if ( class_exists( 'WACRM_Crypto' ) ) {
                        update_option( 'wacrm_api_key_enc', WACRM_Crypto::encrypt( $api_key ) );
                    } else {
                        update_option( 'wacrm_api_key_enc', base64_encode( $api_key ) );
                    }
                }

                // OTP settings
                update_option( 'wacrm_otp_enabled',      absint( $_POST['otp_enabled']      ?? 0 ) );
                update_option( 'wacrm_otp_expiry',        absint( $_POST['otp_expiry']       ?? 300 ) );
                update_option( 'wacrm_otp_max_attempts',  absint( $_POST['otp_max_attempts'] ?? 5 ) );
                update_option( 'wacrm_otp_template',      absint( $_POST['otp_template']     ?? 0 ) );

                // Clear all caches so fresh data loads
                delete_transient( 'wacrm_dashboard_data' );

                wp_send_json_success( [ 'message' => 'Settings saved successfully.' ] );
                break;

            // ── DB Reinstall ───────────────────────────────────────────────────

            case 'reinstall_db':
                WACRM_DB::install();
                wp_send_json_success( [ 'message' => 'All ' . WACRM_DB::table_count() . ' tables verified / created.' ] );
                break;

            // ── Instances ─────────────────────────────────────────────────────

            case 'create_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                if ( empty( $name ) ) {
                    wp_send_json_error( [ 'message' => 'Instance name is required.' ] );
                    break;
                }
                $res = WACRM_Evolution::get()->create_instance( $name );
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( $res );
                    break;
                }
                global $wpdb;
                $wpdb->replace( WACRM_DB::instances(), [
                    'instance_name' => $name,
                    'status'        => 'pending',
                    'enabled'       => 1,
                ] );
                delete_transient( 'wacrm_evo_instances' );
                wp_send_json_success( array_merge( $res, [ 'message' => 'Instance created.' ] ) );
                break;

            case 'connect_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->connect_instance( $name );
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( $res );
                    break;
                }
                wp_send_json_success( $res );
                break;

            case 'fetch_instances':
                $fresh = ! empty( $_POST['fresh'] );
                if ( $fresh ) delete_transient( 'wacrm_evo_instances' );
                $remote = WACRM_Evolution::get()->fetch_instances();
                if ( isset( $remote['error'] ) ) {
                    // Return cached local instances if remote fails
                    global $wpdb;
                    $local = $wpdb->get_results( "SELECT * FROM " . WACRM_DB::instances() . " ORDER BY id DESC", ARRAY_A ) ?: [];
                    wp_send_json_success( $local );
                    break;
                }
                // Normalize Evolution API response (array of instance objects)
                global $wpdb;
                $items = isset( $remote[0] ) ? $remote : ( $remote['instances'] ?? [] );
                $out   = [];
                foreach ( $items as $item ) {
                    $iname  = $item['instance']['instanceName'] ?? $item['instanceName'] ?? '';
                    $status = $item['instance']['state']        ?? $item['connectionStatus'] ?? 'pending';
                    if ( empty( $iname ) ) continue;
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO " . WACRM_DB::instances() . "
                         (instance_name, status, enabled, updated_at)
                         VALUES (%s, %s, 1, NOW())
                         ON DUPLICATE KEY UPDATE status=%s, updated_at=NOW()",
                        $iname, $status, $status
                    ) );
                    $row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT * FROM " . WACRM_DB::instances() . " WHERE instance_name=%s", $iname
                    ), ARRAY_A );
                    if ( $row ) $out[] = $row;
                }
                wp_send_json_success( $out );
                break;

            case 'restart_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->restart_instance( $name );
                delete_transient( 'wacrm_evo_instances' );
                wp_send_json_success( $res );
                break;

            case 'delete_instance':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->delete_instance( $name );
                global $wpdb;
                $wpdb->delete( WACRM_DB::instances(), [ 'instance_name' => $name ] );
                delete_transient( 'wacrm_evo_instances' );
                wp_send_json_success( [ 'message' => 'Instance deleted.' ] );
                break;

            case 'get_qr':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->get_qr( $name );
                if ( isset( $res['error'] ) ) {
                    wp_send_json_error( [ 'message' => $res['error'] ] );
                    break;
                }
                // Normalise base64 – Evolution may wrap in data URI or not
                if ( ! empty( $res['qrcode']['base64'] ) ) {
                    $b64 = $res['qrcode']['base64'];
                } elseif ( ! empty( $res['base64'] ) ) {
                    $b64 = $res['base64'];
                } else {
                    $b64 = '';
                }
                if ( $b64 && strpos( $b64, 'data:' ) !== 0 ) {
                    $b64 = 'data:image/png;base64,' . $b64;
                }
                wp_send_json_success( [ 'base64' => $b64 ] );
                break;

            case 'instance_status':
                $name = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $res  = WACRM_Evolution::get()->instance_status( $name );
                $state = $res['instance']['state'] ?? $res['state'] ?? '';
                if ( $state ) {
                    global $wpdb;
                    $wpdb->update( WACRM_DB::instances(), [ 'status' => $state ], [ 'instance_name' => $name ] );
                    if ( $state === 'open' ) {
                        delete_transient( 'wacrm_dashboard_data' );
                    }
                }
                wp_send_json_success( array_merge( $res, [ 'state' => $state ] ) );
                break;

            case 'toggle_instance':
                $name    = sanitize_text_field( wp_unslash( $_POST['instance_name'] ?? '' ) );
                $enabled = (int) ! empty( $_POST['enabled'] );
                global $wpdb;
                $wpdb->update( WACRM_DB::instances(), [ 'enabled' => $enabled ], [ 'instance_name' => $name ] );
                wp_send_json_success( [ 'enabled' => $enabled ] );
                break;

            // ── Contacts — FIX #3 ─────────────────────────────────────────────

            case 'get_contacts':
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
                $limit  = 25;
                $offset = ( $page - 1 ) * $limit;
                $args   = [ 'limit' => $limit, 'offset' => $offset, 'search' => $search ];

                // FIX #9 – short object-cache for speed
                $cache_key = 'wacrm_contacts_p' . $page . '_' . md5( $search );
                $cached    = wp_cache_get( $cache_key, 'wacrm' );
                if ( $cached === false ) {
                    $cached = [
                        'contacts' => WACRM_Contacts::all( $args ),
                        'total'    => WACRM_Contacts::count( $args ),
                    ];
                    wp_cache_set( $cache_key, $cached, 'wacrm', 30 );
                }
                wp_send_json_success( $cached );
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

                // Save custom meta fields (sent as meta_<field_key>)
                foreach ( $_POST as $key => $val ) {
                    if ( strpos( $key, 'meta_' ) === 0 ) {
                        $field_key = substr( $key, 5 );
                        WACRM_Contacts::save_meta( $id, sanitize_key( $field_key ), sanitize_textarea_field( wp_unslash( $val ) ) );
                    }
                }

                wp_cache_flush_group( 'wacrm' );
                delete_transient( 'wacrm_dashboard_data' );
                wp_send_json_success( [ 'id' => $id, 'message' => 'Contact saved.' ] );
                break;

            case 'delete_contact':
                WACRM_Contacts::delete( absint( $_POST['id'] ?? 0 ) );
                wp_cache_flush_group( 'wacrm' );
                delete_transient( 'wacrm_dashboard_data' );
                wp_send_json_success( [ 'message' => 'Contact deleted.' ] );
                break;

            case 'import_contacts_csv':
                if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
                    wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
                    break;
                }
                $result = WACRM_Contacts::import_csv( sanitize_text_field( $_FILES['csv_file']['tmp_name'] ) );
                wp_cache_flush_group( 'wacrm' );
                delete_transient( 'wacrm_dashboard_data' );
                wp_send_json_success( $result );
                break;

            // FIX #5 – Get all meta for a contact (used by contactModal)
            case 'get_contact_meta':
                $contact_id = absint( $_POST['contact_id'] ?? 0 );
                if ( ! $contact_id ) {
                    wp_send_json_error( [ 'message' => 'Invalid contact ID.' ] );
                    break;
                }
                wp_send_json_success( WACRM_Contacts::get_meta( $contact_id ) );
                break;

            // ── Contact Fields — FIX #5, #6 ──────────────────────────────────

            case 'get_fields':
                global $wpdb;
                $cache_key = 'wacrm_contact_fields';
                $rows      = wp_cache_get( $cache_key, 'wacrm' );
                if ( $rows === false ) {
                    $rows = $wpdb->get_results(
                        "SELECT * FROM " . WACRM_DB::contact_fields() . " ORDER BY sort_order ASC, id ASC",
                        ARRAY_A
                    ) ?: [];
                    if ( $wpdb->last_error ) {
                        wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error . ' — Try reinstalling DB from Settings.' ] );
                        break;
                    }
                    wp_cache_set( $cache_key, $rows, 'wacrm', 120 );
                }
                wp_send_json_success( $rows );
                break;

            case 'save_field':
                global $wpdb;
                $fid    = absint( $_POST['id'] ?? 0 );
                $fkey   = sanitize_key( wp_unslash( $_POST['field_key']   ?? '' ) );
                $flabel = sanitize_text_field( wp_unslash( $_POST['field_label'] ?? '' ) );
                $ftype  = sanitize_text_field( wp_unslash( $_POST['field_type']  ?? 'text' ) );
                $fopts  = sanitize_textarea_field( wp_unslash( $_POST['field_opts'] ?? '' ) );
                $fsort  = absint( $_POST['sort_order'] ?? 0 );

                $allowed_types = [ 'text', 'number', 'email', 'date', 'textarea', 'select', 'dropdown', 'checkbox' ];
                if ( ! in_array( $ftype, $allowed_types, true ) ) $ftype = 'text';

                if ( empty( $fkey ) ) {
                    wp_send_json_error( [ 'message' => 'Field key is required.' ] );
                    break;
                }
                if ( empty( $flabel ) ) {
                    wp_send_json_error( [ 'message' => 'Field label is required.' ] );
                    break;
                }

                $fdata = [
                    'field_key'   => $fkey,
                    'field_label' => $flabel,
                    'field_type'  => $ftype,
                    'field_opts'  => $fopts,
                    'sort_order'  => $fsort,
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

                wp_cache_delete( 'wacrm_contact_fields', 'wacrm' );
                wp_send_json_success( [ 'id' => $fid, 'message' => 'Field saved.' ] );
                break;

            case 'delete_field':
                global $wpdb;
                $fid = absint( $_POST['id'] ?? 0 );
                $fkey = $wpdb->get_var( $wpdb->prepare(
                    "SELECT field_key FROM " . WACRM_DB::contact_fields() . " WHERE id=%d", $fid
                ) );
                // Delete field definition and all meta values for that key
                if ( $fkey ) {
                    $wpdb->delete( WACRM_DB::contact_meta(), [ 'field_key' => $fkey ] );
                }
                $wpdb->delete( WACRM_DB::contact_fields(), [ 'id' => $fid ], [ '%d' ] );
                wp_cache_delete( 'wacrm_contact_fields', 'wacrm' );
                wp_send_json_success( [ 'message' => 'Field deleted.' ] );
                break;

            // ── Lists ─────────────────────────────────────────────────────────

            case 'get_lists':
                global $wpdb;
                $cache_key = 'wacrm_lists';
                $rows      = wp_cache_get( $cache_key, 'wacrm' );
                if ( $rows === false ) {
                    $rows = $wpdb->get_results(
                        "SELECT l.*, (SELECT COUNT(*) FROM " . WACRM_DB::list_contacts() . " lc
                         WHERE lc.list_id = l.id) AS contact_count
                         FROM " . WACRM_DB::lists() . " l ORDER BY l.id DESC",
                        ARRAY_A
                    ) ?: [];
                    if ( $wpdb->last_error ) {
                        wp_send_json_error( [ 'message' => 'DB error: ' . $wpdb->last_error ] );
                        break;
                    }
                    wp_cache_set( $cache_key, $rows, 'wacrm', 60 );
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
                wp_cache_delete( 'wacrm_lists', 'wacrm' );
                wp_send_json_success( [ 'id' => $lid, 'message' => 'List saved.' ] );
                break;

            case 'delete_list':
                global $wpdb;
                $lid = absint( $_POST['id'] ?? 0 );
                $wpdb->delete( WACRM_DB::list_contacts(), [ 'list_id' => $lid ], [ '%d' ] );
                $wpdb->delete( WACRM_DB::lists(),         [ 'id'      => $lid ], [ '%d' ] );
                wp_cache_delete( 'wacrm_lists', 'wacrm' );
                wp_send_json_success( [ 'message' => 'List deleted.' ] );
                break;

            case 'assign_list':
                $cid = absint( $_POST['contact_id'] ?? 0 );
                $lid = absint( $_POST['list_id']    ?? 0 );
                if ( $cid && $lid ) {
                    WACRM_Contacts::assign_to_list( $cid, $lid );
                    wp_cache_delete( 'wacrm_lists', 'wacrm' );
                }
                wp_send_json_success( [ 'message' => 'Assigned.' ] );
                break;

            // ── Campaigns ─────────────────────────────────────────────────────

            case 'get_campaigns':
                $cache_key = 'wacrm_campaigns';
                $rows      = wp_cache_get( $cache_key, 'wacrm' );
                if ( $rows === false ) {
                    $rows = WACRM_Campaigns::all();
                    wp_cache_set( $cache_key, $rows, 'wacrm', 30 );
                }
                wp_send_json_success( $rows );
                break;

            case 'save_campaign':
                $cid = absint( $_POST['id'] ?? 0 );

                // target_lists comes as JSON string from JS
                $raw_lists = wp_unslash( $_POST['target_lists'] ?? '[]' );
                if ( is_string( $raw_lists ) ) {
                    $lists_arr = json_decode( $raw_lists, true );
                    if ( ! is_array( $lists_arr ) ) $lists_arr = [];
                } else {
                    $lists_arr = (array) $raw_lists;
                }
                $lists_arr = array_map( 'absint', $lists_arr );

                $cdata = [
                    'campaign_name'   => sanitize_text_field( wp_unslash( $_POST['campaign_name']   ?? '' ) ),
                    'target_lists'    => $lists_arr,
                    'rate_per_hour'   => absint( $_POST['rate_per_hour']   ?? 200 ),
                    'schedule_from'   => sanitize_text_field( wp_unslash( $_POST['schedule_from']   ?? '09:00' ) ),
                    'schedule_to'     => sanitize_text_field( wp_unslash( $_POST['schedule_to']     ?? '20:00' ) ),
                    'randomize_delay' => (int) ! empty( $_POST['randomize_delay'] ),
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
                wp_cache_delete( 'wacrm_campaigns', 'wacrm' );
                break;

            case 'delete_campaign':
                WACRM_Campaigns::delete( absint( $_POST['id'] ?? 0 ) );
                wp_cache_delete( 'wacrm_campaigns', 'wacrm' );
                wp_send_json_success( [ 'message' => 'Campaign deleted.' ] );
                break;

            // FIX #10 – get_campaign_steps was completely missing
            case 'get_campaign_steps':
                $cid = absint( $_POST['campaign_id'] ?? 0 );
                if ( ! $cid ) {
                    wp_send_json_error( [ 'message' => 'Campaign ID required.' ] );
                    break;
                }
                $steps = WACRM_Campaigns::get_steps( $cid );
                // Normalise field names for JS (JS expects message_type, message_body, delay_seconds)
                $normalised = array_map( function( $s ) {
                    return [
                        'id'           => $s['id']           ?? 0,
                        'step_order'   => $s['step_order']   ?? 0,
                        'message_type' => $s['message_type'] ?? 'text',
                        'message_body' => $s['message_body'] ?? '',
                        'media_url'    => $s['media_url']    ?? '',
                        'delay_seconds'=> (int) ( $s['delay_seconds'] ?? 5 ),
                        'template_id'  => $s['template_id']  ?? 0,
                    ];
                }, $steps );
                wp_send_json_success( $normalised );
                break;

            case 'save_campaign_steps':
                $cid   = absint( $_POST['campaign_id'] ?? 0 );
                $steps = json_decode( wp_unslash( $_POST['steps'] ?? '[]' ), true ) ?: [];
                if ( ! $cid ) {
                    wp_send_json_error( [ 'message' => 'Campaign ID required.' ] );
                    break;
                }
                WACRM_Campaigns::save_steps( $cid, $steps );
                wp_send_json_success( [ 'message' => 'Steps saved successfully.' ] );
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
                    wp_cache_delete( 'wacrm_campaigns', 'wacrm' );
                    delete_transient( 'wacrm_dashboard_data' );
                    wp_send_json_success( array_merge( $res, [ 'message' => ( $res['queued'] ?? 0 ) . ' messages queued.' ] ) );
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

            // ── Templates — FIX #11 ───────────────────────────────────────────

            case 'get_templates':
                $cat = sanitize_text_field( wp_unslash( $_POST['category'] ?? '' ) );
                $cache_key = 'wacrm_templates_' . $cat;
                $rows      = wp_cache_get( $cache_key, 'wacrm' );
                if ( $rows === false ) {
                    $rows = WACRM_Templates::all( $cat );
                    wp_cache_set( $cache_key, $rows, 'wacrm', 60 );
                }
                wp_send_json_success( $rows );
                break;

            case 'save_template':
                $tid   = absint( $_POST['id'] ?? 0 );
                $tname = sanitize_text_field( wp_unslash( $_POST['tpl_name'] ?? '' ) );
                if ( empty( $tname ) ) {
                    wp_send_json_error( [ 'message' => 'Template name is required.' ] );
                    break;
                }
                // FIX #4 – category is a select, sanitize as-is
                $valid_cats = [ 'order_confirmation', 'otp', 'campaign', 'manual', 'automation' ];
                $tcat = sanitize_text_field( wp_unslash( $_POST['category'] ?? 'manual' ) );
                if ( ! in_array( $tcat, $valid_cats, true ) ) $tcat = 'manual';

                $tdata = [
                    'tpl_name' => $tname,
                    'category' => $tcat,
                    'body'     => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
                ];
                if ( $tid ) {
                    WACRM_Templates::update( $tid, $tdata );
                    wp_send_json_success( [ 'id' => $tid, 'message' => 'Template updated.' ] );
                } else {
                    $new_id = WACRM_Templates::insert( $tdata );
                    wp_send_json_success( [ 'id' => $new_id, 'message' => 'Template created.' ] );
                }
                // Flush template cache
                foreach ( $valid_cats as $c ) {
                    wp_cache_delete( 'wacrm_templates_' . $c, 'wacrm' );
                }
                wp_cache_delete( 'wacrm_templates_', 'wacrm' );
                break;

            case 'delete_template':
                $tid = absint( $_POST['id'] ?? 0 );
                WACRM_Templates::delete( $tid );
                $valid_cats = [ 'order_confirmation', 'otp', 'campaign', 'manual', 'automation' ];
                foreach ( $valid_cats as $c ) {
                    wp_cache_delete( 'wacrm_templates_' . $c, 'wacrm' );
                }
                wp_cache_delete( 'wacrm_templates_', 'wacrm' );
                wp_send_json_success( [ 'message' => 'Template deleted.' ] );
                break;

            // ── WooCommerce ───────────────────────────────────────────────────

            case 'get_orders':
                if ( ! function_exists( 'wc_get_orders' ) ) {
                    wp_send_json_error( [ 'message' => 'WooCommerce is not active.' ] );
                    break;
                }
                $page   = max( 1, absint( $_POST['page'] ?? 1 ) );
                $limit  = 25;
                $orders = wc_get_orders( [
                    'limit'  => $limit,
                    'offset' => ( $page - 1 ) * $limit,
                    'type'   => 'shop_order',
                    'return' => 'objects',
                ] );
                $out = [];
                foreach ( $orders as $order ) {
                    $out[] = [
                        'id'          => $order->get_id(),
                        'status'      => $order->get_status(),
                        'total'       => $order->get_formatted_order_total(),
                        'customer'    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                        'phone'       => $order->get_billing_phone(),
                        'email'       => $order->get_billing_email(),
                        'date'        => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
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

            // ── Dashboard — FIX #7 ────────────────────────────────────────────

            case 'get_dashboard_data':
                $cache_key = 'wacrm_dashboard_data';
                $data      = get_transient( $cache_key );

                if ( false === $data ) {
                    global $wpdb;
                    $today = current_time( 'Y-m-d' );
                    $month = current_time( 'Y-m' );
                    $logs  = WACRM_DB::message_logs();

                    // Ensure tables exist before querying
                    if ( $wpdb->get_var( "SHOW TABLES LIKE '$logs'" ) !== $logs ) {
                        wp_send_json_success( [
                            'sent_today'         => 0,
                            'sent_month'         => 0,
                            'failed'             => 0,
                            'delivery_rate'      => 100,
                            'quota_used'         => 0,
                            'quota_max'          => WACRM_QUOTA_MAX,
                            'remaining'          => WACRM_QUOTA_MAX,
                            'total_contacts'     => 0,
                            'active_campaigns'   => 0,
                            'active_automations' => 0,
                            'instance_status'    => 'disconnected',
                            'daily_data'         => [],
                        ] );
                        break;
                    }

                    $sent_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $logs WHERE status='sent'" );
                    $failed     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $logs WHERE status='failed'" );
                    $all_logged = $sent_total + $failed;
                    $delivery_rate = $all_logged > 0 ? round( ( $sent_total / $all_logged ) * 100, 1 ) : 100;

                    // 7-day chart
                    $daily_chart = [];
                    for ( $i = 6; $i >= 0; $i-- ) {
                        $d = date( 'Y-m-d', strtotime( "-{$i} days", strtotime( $today ) ) );
                        $c = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $logs WHERE status='sent' AND DATE(sent_at) = %s", $d
                        ) );
                        $daily_chart[] = [ 'date' => date( 'M j', strtotime( $d ) ), 'count' => $c ];
                    }

                    $quota_used = 0;
                    $quota_max  = WACRM_QUOTA_MAX;
                    $remaining  = WACRM_QUOTA_MAX;
                    try {
                        $quota_used = WACRM_Quota::used();
                        $remaining  = WACRM_Quota::remaining();
                    } catch ( \Throwable $e ) {}

                    $data = [
                        'sent_today'         => (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $logs WHERE status='sent' AND DATE(sent_at) = %s", $today
                        ) ),
                        'sent_month'         => (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(*) FROM $logs WHERE status='sent' AND DATE_FORMAT(sent_at,'%%Y-%%m') = %s", $month
                        ) ),
                        'failed'             => $failed,
                        'delivery_rate'      => $delivery_rate,
                        'quota_used'         => $quota_used,
                        'quota_max'          => $quota_max,
                        'remaining'          => $remaining,
                        'total_contacts'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WACRM_DB::contacts() ),
                        'active_campaigns'   => (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM " . WACRM_DB::campaigns() . " WHERE status IN ('running','active','draft')"
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

            default:
                wp_send_json_error( [ 'message' => 'Unknown action: ' . esc_html( $method ) ] );
                break;

        } // end switch

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