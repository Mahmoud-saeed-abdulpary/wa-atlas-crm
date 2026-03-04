<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'Message Templates';
$page_id       = 'templates';
$header_actions = '<button class="wacrm-btn wacrm-btn-primary" id="btn-add-template">+ New Template</button>';
require __DIR__ . '/partials/header.php';
?>
<div class="wacrm-card">
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead><tr><th>Name</th><th>Category</th><th>Preview</th><th>Actions</th></tr></thead>
            <tbody id="templates-tbody"><tr><td colspan="4"><span class="wacrm-spinner"></span></td></tr></tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
