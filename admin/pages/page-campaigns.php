<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'Campaigns';
$page_id       = 'campaigns';
$header_actions = '<button class="wacrm-btn wacrm-btn-primary" id="btn-add-campaign">+ New Campaign</button>';
require __DIR__ . '/partials/header.php';
?>
<div class="wacrm-card">
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead><tr><th>Name</th><th>Status</th><th>Rate</th><th>Schedule</th><th>Actions</th></tr></thead>
            <tbody id="campaigns-tbody"><tr><td colspan="5"><span class="wacrm-spinner"></span></td></tr></tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
