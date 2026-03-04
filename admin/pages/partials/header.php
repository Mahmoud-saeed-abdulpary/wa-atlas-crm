<?php
// Shared header for WA Atlas CRM admin pages
// $page_title, $page_id, and optionally $header_actions must be set before including this.
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="wacrm-app" data-wacrm-page="<?php echo esc_attr( $page_id ?? '' ); ?>">

<?php if ( WACRM_Quota::is_blocked() ) : ?>
<div class="wacrm-alert error" style="margin:16px 32px 0;">
    ⚠️ <strong>Message quota exceeded.</strong> You have used all <?php echo WACRM_QUOTA_MAX; ?> messages included with your license. Please enter a new license key or contact support to renew.
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wacrm-settings' ) ); ?>" style="margin-left:10px;font-weight:700;">Manage License →</a>
</div>
<?php endif; ?>

<div class="wacrm-header">
    <h1>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25d366"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/></svg>
        <?php echo esc_html( $page_title ?? 'WA Atlas CRM' ); ?>
    </h1>
    <div class="header-actions">
        <div class="wacrm-quota-badge">
            <span class="quota-bar"><span class="quota-bar-fill" style="width:<?php echo esc_attr( min( 100, round( ( WACRM_Quota::used() / WACRM_QUOTA_MAX ) * 100 ) ) ); ?>%"></span></span>
            <span class="quota-text"><?php echo number_format( WACRM_Quota::remaining() ); ?> msgs left</span>
        </div>
        <?php if ( ! empty( $header_actions ) ) echo wp_kses_post( $header_actions ); ?>
    </div>
</div>

<div class="wacrm-content">
