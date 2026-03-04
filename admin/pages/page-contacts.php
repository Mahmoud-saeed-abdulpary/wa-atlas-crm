<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'Contacts';
$page_id       = 'contacts';
$header_actions = '<button class="wacrm-btn wacrm-btn-primary" id="btn-add-contact">+ Add Contact</button>
                   <button class="wacrm-btn wacrm-btn-outline" id="btn-import-csv">⬆ Import CSV</button>
                   <input type="file" id="csv-file-input" accept=".csv" style="display:none">';
require __DIR__ . '/partials/header.php';
?>

<div class="wacrm-card">
    <div class="wacrm-search-bar">
        <input type="text" class="wacrm-input" id="contact-search" placeholder="🔍 Search contacts…">
    </div>
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all-contacts"></th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>WhatsApp</th>
                    <th>Tags</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="contacts-tbody">
                <tr><td colspan="7"><span class="wacrm-spinner"></span></td></tr>
            </tbody>
        </table>
    </div>
    <div id="contacts-pagination"></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
