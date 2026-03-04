<?php
/**
 * WA Atlas CRM – AJAX Diagnostic Tool
 * =====================================
 * TEMPORARY USE ONLY. Drop this file inside your plugin folder at:
 *   wp-content/plugins/wa-atlas-crm/wacrm-debug.php
 *
 * Then visit:
 *   https://yoursite.com/wp-content/plugins/wa-atlas-crm/wacrm-debug.php?key=wacrm_debug_2024
 *
 * DELETE THIS FILE after debugging. Never leave it on production.
 */

if ( ! isset( $_GET['key'] ) || $_GET['key'] !== 'wacrm_debug_2024' ) {
    http_response_code( 403 );
    exit( 'Forbidden.' );
}

// Load WordPress
$wp_load = dirname( __FILE__ );
for ( $i = 0; $i < 6; $i++ ) {
    $wp_load = dirname( $wp_load );
    if ( file_exists( $wp_load . '/wp-load.php' ) ) break;
}
require_once $wp_load . '/wp-load.php';

if ( ! current_user_can( 'manage_options' ) ) {
    exit( 'You must be logged in as admin.' );
}

global $wpdb;

$results = [];

// ── 1. Check DB Tables ────────────────────────────────────────────────────────
$prefix   = $wpdb->prefix . 'wacrm_';
$expected = [
    'contacts', 'contact_fields', 'contact_meta', 'lists',
    'list_contacts', 'campaigns', 'campaign_steps', 'automations',
    'templates', 'message_logs', 'otp_logs', 'instances', 'quota', 'queue',
];

$results['tables'] = [];
foreach ( $expected as $t ) {
    $table  = $prefix . $t;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table;
    $results['tables'][ $table ] = $exists ? '✅ EXISTS' : '❌ MISSING';
}

// ── 2. Check WordPress Options ────────────────────────────────────────────────
$results['options'] = [
    'wacrm_license_key'   => get_option( 'wacrm_license_key' )   ? '✅ Set'     : '❌ Empty',
    'wacrm_license_email' => get_option( 'wacrm_license_email' ) ? '✅ Set'     : '❌ Empty',
    'wacrm_api_url'       => get_option( 'wacrm_api_url' )       ? '✅ ' . esc_html( get_option( 'wacrm_api_url' ) ) : '❌ Empty',
    'wacrm_api_key_enc'   => get_option( 'wacrm_api_key_enc' )   ? '✅ Set (encrypted)' : '❌ Empty',
    'wacrm_quota_exhausted' => get_option( 'wacrm_quota_exhausted' ) ? '⚠️ QUOTA EXHAUSTED' : '✅ OK',
    'wacrm_db_version'    => get_option( 'wacrm_db_version' )    ?: '❌ Not set',
];

// ── 3. Test a sample AJAX call manually ──────────────────────────────────────
$results['ajax_nonce_test'] = 'Nonce generated: ' . wp_create_nonce( 'wacrm_admin_nonce' );

// ── 4. Check for PHP errors in last AJAX call ─────────────────────────────────
$results['php_version'] = 'PHP ' . PHP_VERSION;
$results['wp_version']  = 'WordPress ' . get_bloginfo( 'version' );
$results['wp_debug']    = defined( 'WP_DEBUG' ) && WP_DEBUG ? '⚠️ WP_DEBUG is ON (can corrupt AJAX JSON)' : '✅ WP_DEBUG is off';
$results['wp_debug_display'] = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ? '⚠️ WP_DEBUG_DISPLAY is ON — PHP errors will be printed to page/AJAX output and break JSON' : '✅ OK';

// ── 5. Quick direct query test ────────────────────────────────────────────────
$contacts_table = $prefix . 'contacts';
$test_query     = $wpdb->get_results( "SELECT COUNT(*) as cnt FROM `$contacts_table`", ARRAY_A );
if ( $wpdb->last_error ) {
    $results['direct_query_test'] = '❌ DB Error: ' . $wpdb->last_error;
} else {
    $results['direct_query_test'] = '✅ Contacts table OK — ' . ( $test_query[0]['cnt'] ?? '?' ) . ' rows';
}

// ── 6. Check registered AJAX actions ─────────────────────────────────────────
$expected_actions = [
    'wacrm_get_contacts', 'wacrm_save_contact', 'wacrm_get_lists',
    'wacrm_save_list', 'wacrm_get_campaigns', 'wacrm_get_templates',
    'wacrm_get_automations', 'wacrm_get_orders', 'wacrm_get_dashboard_data',
    'wacrm_fetch_instances', 'wacrm_get_fields', 'wacrm_get_logs',
];
$results['ajax_actions'] = [];
foreach ( $expected_actions as $act ) {
    $has = has_action( 'wp_ajax_' . $act );
    $results['ajax_actions'][ $act ] = $has ? '✅ Registered' : '❌ NOT registered — AJAX will return 0';
}

// ── Output ─────────────────────────────────────────────────────────────────────
header( 'Content-Type: text/html; charset=utf-8' );
?>
<!DOCTYPE html><html><head><meta charset="utf-8">
<title>WA Atlas CRM Diagnostic</title>
<style>
  body { font-family: monospace; background: #0f0f0f; color: #e0e0e0; padding: 24px; }
  h2   { color: #4ade80; border-bottom: 1px solid #333; padding-bottom: 8px; }
  h3   { color: #60a5fa; margin-top: 24px; }
  table { border-collapse: collapse; width: 100%; max-width: 900px; }
  td, th { border: 1px solid #333; padding: 6px 12px; text-align: left; }
  th { background: #1a1a1a; color: #94a3b8; }
  tr:hover td { background: #1a1a1a; }
  .warn { background: #2d1b00 !important; }
  .err  { background: #1a0000 !important; }
</style>
</head><body>
<h2>🔍 WA Atlas CRM – Diagnostic Report</h2>
<p style="color:#94a3b8">Generated: <?php echo esc_html( current_time( 'Y-m-d H:i:s' ) ); ?> | 
   <?php echo esc_html( $results['php_version'] ); ?> | 
   <?php echo esc_html( $results['wp_version'] ); ?></p>

<?php foreach ( $results as $section => $data ) : ?>
  <?php if ( $section === 'php_version' || $section === 'wp_version' ) continue; ?>
  <h3><?php echo esc_html( ucwords( str_replace( '_', ' ', $section ) ) ); ?></h3>
  <?php if ( is_string( $data ) ) : ?>
    <p><?php echo esc_html( $data ); ?></p>
  <?php elseif ( is_array( $data ) ) : ?>
    <table>
      <tr><th>Key</th><th>Status</th></tr>
      <?php foreach ( $data as $k => $v ) :
        $cls = strpos( $v, '❌' ) !== false ? 'err' : ( strpos( $v, '⚠️' ) !== false ? 'warn' : '' );
      ?>
        <tr class="<?php echo esc_attr( $cls ); ?>">
          <td><?php echo esc_html( $k ); ?></td>
          <td><?php echo esc_html( $v ); ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
<?php endforeach; ?>

<h3>📋 What to do with these results</h3>
<ul style="line-height:1.9">
  <li>Any <span style="color:#f87171">❌ MISSING table</span> → Run the DB Reinstall fix below</li>
  <li>Any <span style="color:#f87171">❌ NOT registered</span> AJAX action → Check that the plugin activated correctly and the license is active</li>
  <li><span style="color:#fbbf24">⚠️ WP_DEBUG_DISPLAY is ON</span> → This is almost certainly breaking your AJAX. Set <code>define('WP_DEBUG_DISPLAY', false);</code> in wp-config.php</li>
  <li><span style="color:#fbbf24">⚠️ QUOTA EXHAUSTED</span> → Enter a new license key to restore access</li>
</ul>

<p style="color:#f87171;margin-top:32px"><strong>⚠️ DELETE THIS FILE when done debugging.</strong></p>
</body></html>