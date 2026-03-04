<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$page_title = 'Settings';
$page_id    = 'settings';
require __DIR__ . '/partials/header.php';

$api_url      = get_option( 'wacrm_api_url', '' );
$rate         = get_option( 'wacrm_global_rate_per_hour', 200 );
$otp_enabled  = get_option( 'wacrm_otp_enabled', 0 );
$otp_expiry   = get_option( 'wacrm_otp_expiry', 300 );
$otp_attempts = get_option( 'wacrm_otp_max_attempts', 5 );
$otp_template = get_option( 'wacrm_otp_template', 0 );
$lic_key      = get_option( 'wacrm_license_key', '' );
$lic_email    = get_option( 'wacrm_license_email', '' );
$otp_tpls     = WACRM_Templates::all( 'otp' );
?>

<!-- action="#" and explicit IDs prevent any native form submission -->
<form id="settings-form" action="#" method="post" onsubmit="return false;">

    <!-- Evolution API -->
    <div class="wacrm-card">
        <div class="wacrm-card-header"><h2>📡 Evolution API</h2></div>
        <div class="wacrm-form-grid">
            <div class="wacrm-form-row" style="grid-column:1/-1">
                <label for="set-api-url">API Base URL</label>
                <input class="wacrm-input" id="set-api-url" type="url"
                    value="<?php echo esc_attr( $api_url ); ?>"
                    placeholder="https://your-evolution-api.com/">
                <div class="field-hint">Your Evolution API server URL (include trailing slash)</div>
            </div>
            <div class="wacrm-form-row" style="grid-column:1/-1">
                <label for="set-api-key">API Key <small style="font-weight:normal;color:var(--muted)">(AUTHENTICATION_API_KEY)</small></label>
                <input class="wacrm-input" id="set-api-key" type="password"
                    placeholder="Leave blank to keep the existing key"
                    autocomplete="new-password">
                <div class="field-hint">Stored AES-256 encrypted. Leave blank if you don't want to change it.</div>
            </div>
        </div>
    </div>

    <!-- Rate limiting -->
    <div class="wacrm-card">
        <div class="wacrm-card-header"><h2>🛡 Rate Limiting &amp; Anti-Ban</h2></div>
        <div class="wacrm-form-grid">
            <div class="wacrm-form-row">
                <label for="set-rate">Max Messages / Hour (global)</label>
                <input class="wacrm-input" id="set-rate" type="number"
                    value="<?php echo esc_attr( $rate ); ?>" min="1" max="1000">
                <div class="field-hint">Applies to all campaigns and automations combined</div>
            </div>
        </div>
    </div>

    <!-- OTP -->
    <div class="wacrm-card">
        <div class="wacrm-card-header"><h2>🔐 OTP Verification (WooCommerce Checkout)</h2></div>
        <div class="wacrm-form-grid">
            <div class="wacrm-form-row" style="grid-column:1/-1">
                <label class="wacrm-toggle">
                    <input type="checkbox" id="set-otp-enabled" value="1" <?php checked( $otp_enabled, 1 ); ?>>
                    <span class="track"></span>
                    Enable WhatsApp OTP verification at checkout
                </label>
            </div>
            <div class="wacrm-form-row">
                <label for="set-otp-expiry">OTP Expiry (seconds)</label>
                <input class="wacrm-input" id="set-otp-expiry" type="number"
                    value="<?php echo esc_attr( $otp_expiry ); ?>" min="60">
            </div>
            <div class="wacrm-form-row">
                <label for="set-otp-attempts">Max Attempts</label>
                <input class="wacrm-input" id="set-otp-attempts" type="number"
                    value="<?php echo esc_attr( $otp_attempts ); ?>" min="1" max="10">
            </div>
            <div class="wacrm-form-row">
                <label for="set-otp-template">OTP Message Template</label>
                <select class="wacrm-select" id="set-otp-template">
                    <option value="0">— Default (sends plain code) —</option>
                    <?php foreach ( $otp_tpls as $t ) : ?>
                    <option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( $otp_template, $t['id'] ); ?>>
                        <?php echo esc_html( $t['tpl_name'] ); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint">Use <code>{{otp}}</code> in the template body</div>
            </div>
        </div>
    </div>

    <!-- License info (read-only) -->
    <div class="wacrm-card">
        <div class="wacrm-card-header"><h2>🔑 License</h2></div>
        <div class="wacrm-form-grid">
            <div class="wacrm-form-row">
                <label>License Key</label>
                <input class="wacrm-input" type="text" readonly
                    value="<?php echo $lic_key ? esc_attr( substr( $lic_key, 0, 6 ) . '••••••••••••' . substr( $lic_key, -4 ) ) : 'Not activated'; ?>"
                    style="background:var(--bg);color:var(--muted)">
            </div>
            <div class="wacrm-form-row">
                <label>Licensed Email</label>
                <input class="wacrm-input" type="text" readonly
                    value="<?php echo esc_attr( $lic_email ?: '—' ); ?>"
                    style="background:var(--bg);color:var(--muted)">
            </div>
            <div class="wacrm-form-row">
                <label>Message Quota</label>
                <div style="display:flex;align-items:center;gap:14px;padding:9px 0">
                    <div class="wacrm-quota-badge">
                        <span class="quota-bar">
                            <span class="quota-bar-fill" style="width:<?php echo esc_attr( min(100, round((WACRM_Quota::used()/WACRM_QUOTA_MAX)*100)) ); ?>%"></span>
                        </span>
                        <span class="quota-text"><?php echo number_format( WACRM_Quota::used() ); ?> / <?php echo number_format( WACRM_QUOTA_MAX ); ?> used</span>
                    </div>
                    <span style="color:var(--muted);font-size:12.5px"><?php echo number_format( WACRM_Quota::remaining() ); ?> remaining</span>
                </div>
            </div>
        </div>
        <div style="margin-top:8px">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wacrm-license' ) ); ?>"
               class="wacrm-btn wacrm-btn-outline wacrm-btn-sm">Change License Key</a>
        </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:12px;margin-top:4px">
        <button type="submit" class="wacrm-btn wacrm-btn-primary" style="padding:10px 28px">
            Save Settings
        </button>
    </div>

</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
