<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'Automations';
$page_id       = 'automations';
$header_actions = '<button class="wacrm-btn wacrm-btn-primary" id="btn-add-automation">+ New Automation</button>';
require __DIR__ . '/partials/header.php';
?>
<div class="wacrm-card">
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead><tr><th>Name</th><th>Trigger</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="automations-tbody"><tr><td colspan="4"><span class="wacrm-spinner"></span></td></tr></tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
