<?php
/**
 * Plugin Name: WA Atlas CRM Pro
 * Plugin URI:  https://wpseoatlas.com
 * Description: SaaS-level WhatsApp CRM powered by Evolution API with WooCommerce automation, campaigns, OTP, and message quota control.
 * Version:     1.0.0
 * Author:      WP SEO Atlas
 * Author URI:  https://wpseoatlas.com
 * Text Domain: wa-atlas-crm
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WACRM_VERSION',    '1.0.0' );
define( 'WACRM_FILE',       __FILE__ );
define( 'WACRM_DIR',        plugin_dir_path( __FILE__ ) );
define( 'WACRM_URL',        plugin_dir_url( __FILE__ ) );
define( 'WACRM_SLUG',       'wa-atlas-crm' );
define( 'WACRM_QUOTA_MAX',  5000 );

require_once WACRM_DIR . 'class-w-a-a-t-l-a-s-s-e-n-d-e-r-base.php';
require_once WACRM_DIR . 'database/class-wacrm-db.php';
require_once WACRM_DIR . 'includes/class-wacrm-helpers.php';
require_once WACRM_DIR . 'includes/class-wacrm-crypto.php';
require_once WACRM_DIR . 'api/class-wacrm-evolution.php';
require_once WACRM_DIR . 'includes/class-wacrm-quota.php';
require_once WACRM_DIR . 'includes/class-wacrm-contacts.php';
require_once WACRM_DIR . 'includes/class-wacrm-campaigns.php';
require_once WACRM_DIR . 'includes/class-wacrm-automations.php';
require_once WACRM_DIR . 'includes/class-wacrm-templates.php';
require_once WACRM_DIR . 'includes/class-wacrm-otp.php';
require_once WACRM_DIR . 'includes/class-wacrm-queue.php';
require_once WACRM_DIR . 'woocommerce/class-wacrm-woocommerce.php';
require_once WACRM_DIR . 'cron/class-wacrm-cron.php';
require_once WACRM_DIR . 'admin/class-wacrm-admin.php';

// ── License gate ──────────────────────────────────────────────────────────────
$licenseCode  = get_option( 'wacrm_license_key', '' );
$licenseEmail = get_option( 'wacrm_license_email', '' );

W_A_A_T_L_A_S_S_E_N_D_E_R_Base::add_on_delete( function () {
    delete_option( 'wacrm_license_key' );
    delete_option( 'wacrm_license_email' );
} );

if ( W_A_A_T_L_A_S_S_E_N_D_E_R_Base::check_wp_plugin( $licenseCode, $licenseEmail, $wacrm_lic_error, $wacrm_lic_obj, __FILE__ ) ) {
    // License valid – boot the full plugin
    WACRM_Admin::init( $wacrm_lic_obj );
    WACRM_Cron::init();
    WACRM_WooCommerce::init();
    WACRM_OTP::init();
    WACRM_Queue::init();
} else {
    // License invalid – show only the license page
    WACRM_Admin::init_license_only( $wacrm_lic_error );
}

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'WACRM_DB', 'install' ] );
register_deactivation_hook( __FILE__, [ 'WACRM_Cron', 'deactivate' ] );
