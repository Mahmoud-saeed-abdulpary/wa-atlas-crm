<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'WhatsApp Instances';
$page_id       = 'instances';
$header_actions = '<button class="wacrm-btn wacrm-btn-green" id="btn-create-instance">+ New Instance</button>';
require __DIR__ . '/partials/header.php';
?>

<div class="wacrm-instance-grid" id="wacrm-instances-grid">
    <div class="wacrm-empty"><span class="wacrm-spinner"></span></div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
