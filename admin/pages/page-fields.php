<?php
/**
 * WA Atlas CRM – Contact Fields Page
 * Allows admin to create custom CRM fields usable as dynamic tags in campaigns.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$page_title     = 'Contact Fields';
$page_id        = 'fields';
$header_actions = '<button class="wacrm-btn wacrm-btn-primary" id="btn-add-field">+ New Field</button>';

require __DIR__ . '/partials/header.php';
?>

<div class="wacrm-card">
    <p style="color:var(--muted);font-size:13px;margin:0 0 16px">
        Custom fields are saved per-contact and can be used as dynamic tags in any message body using
        <code style="background:var(--bg);padding:2px 6px;border-radius:4px;font-size:12px">{{field_key}}</code> syntax.
    </p>
    <div class="wacrm-table-wrap">
        <table class="wacrm-table">
            <thead>
                <tr>
                    <th>Field Key</th>
                    <th>Label</th>
                    <th>Type</th>
                    <th>Dynamic Tag</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="fields-tbody">
                <tr>
                    <td colspan="5" style="text-align:center;padding:32px">
                        <span class="wacrm-spinner"></span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>