<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'Contact Lists';
$page_id       = 'lists';
$header_actions = '<button class="wacrm-btn wacrm-btn-primary" id="btn-add-list">+ New List</button>';
require __DIR__ . '/partials/header.php';
?>
<div class="wacrm-card">
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead><tr><th>List Name</th><th>Description</th><th>Contacts</th><th>Actions</th></tr></thead>
            <tbody id="lists-tbody"><tr><td colspan="4"><span class="wacrm-spinner"></span></td></tr></tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
