<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title = 'Message Logs';
$page_id    = 'logs';
require __DIR__ . '/partials/header.php';
?>
<div class="wacrm-card">
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead><tr><th>ID</th><th>Phone</th><th>Type</th><th>Source</th><th>Status</th><th>Sent At</th><th>Error</th></tr></thead>
            <tbody id="logs-tbody"><tr><td colspan="7"><span class="wacrm-spinner"></span></td></tr></tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
