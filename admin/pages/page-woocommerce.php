<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title = 'WooCommerce Orders';
$page_id    = 'woocommerce';
require __DIR__ . '/partials/header.php';
?>
<?php if ( ! class_exists( 'WooCommerce' ) ) : ?>
    <div class="wacrm-alert warning">⚠️ WooCommerce is not installed or active.</div>
<?php else : ?>
<div class="wacrm-card">
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead><tr><th>#</th><th>Customer</th><th>Phone</th><th>Email</th><th>Total</th><th>Status</th><th>Items</th><th>Action</th></tr></thead>
            <tbody id="orders-tbody"><tr><td colspan="8"><span class="wacrm-spinner"></span></td></tr></tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
