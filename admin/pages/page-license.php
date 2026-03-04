<?php
/**
 * License activation page.
 * Shown when the plugin has no valid license.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$page_id       = 'license';
$current_key   = get_option( 'wacrm_license_key', '' );
$current_email = get_option( 'wacrm_license_email', '' );
?>
<div id="wacrm-app" data-wacrm-page="license">
<div class="wacrm-content">
<div class="wacrm-license-page">

    <div class="brand">
        <h1>
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="#25d366" style="vertical-align:middle;margin-right:8px">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>
                <path d="M12 0C5.373 0 0 5.373 0 12c0 2.117.553 4.103 1.524 5.827L.057 23.5l5.845-1.53A11.942 11.942 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.854 0-3.612-.502-5.124-1.382l-.368-.218-3.467.908.924-3.374-.24-.389A9.938 9.938 0 012 12C2 6.486 6.486 2 12 2s10 4.486 10 10-4.486 10-10 10z"/>
            </svg>
            WA Atlas CRM
        </h1>
        <p>Enter your license key to activate the plugin</p>
    </div>

    <?php
    $lic_error = get_option( 'wacrm_license_error', '' );
    if ( ! empty( $lic_error ) ) : ?>
    <div class="wacrm-alert error" style="margin-bottom:20px">
        ⚠️ <?php echo esc_html( $lic_error ); ?>
    </div>
    <?php endif; ?>

    <div class="wacrm-card">
        <form id="license-form" action="#" method="post">
            <div class="wacrm-form-row">
                <label for="lic-key">License Key</label>
                <input
                    class="wacrm-input"
                    id="lic-key"
                    type="text"
                    placeholder="XXXX-XXXX-XXXX-XXXX"
                    value="<?php echo esc_attr( $current_key ); ?>"
                    autocomplete="off"
                    spellcheck="false"
                >
            </div>
            <div class="wacrm-form-row">
                <label for="lic-email">Registered Email</label>
                <input
                    class="wacrm-input"
                    id="lic-email"
                    type="email"
                    placeholder="you@example.com"
                    value="<?php echo esc_attr( $current_email ); ?>"
                    autocomplete="email"
                >
            </div>

            <div id="lic-message" class="wacrm-alert" style="display:none;margin-bottom:14px"></div>

            <button type="submit" class="wacrm-btn wacrm-btn-green" style="width:100%;justify-content:center;padding:12px;font-size:15px">
                Activate License
            </button>
        </form>
    </div>

    <?php if ( $current_key ) : ?>
    <div style="text-align:center;margin-top:12px">
        <button class="wacrm-btn wacrm-btn-danger wacrm-btn-sm" id="btn-remove-license">
            Remove Current License
        </button>
    </div>
    <?php endif; ?>

    <p style="text-align:center;margin-top:20px;font-size:12.5px;color:var(--muted)">
        Need a license? <a href="https://wpseoatlas.com" target="_blank">Purchase at wpseoatlas.com</a>
    </p>

</div>
</div>
</div>
