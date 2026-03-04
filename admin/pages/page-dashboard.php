<?php if ( ! defined( 'ABSPATH' ) ) exit;
$page_title    = 'Dashboard';
$page_id       = 'dashboard';
require __DIR__ . '/partials/header.php';
?>

<div class="wacrm-stats" id="wacrm-stats">
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#d1fae5">📱</div>
        <div class="stat-value" id="stat-instance">–</div>
        <div class="stat-label">WhatsApp Status</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#ede9fe">📤</div>
        <div class="stat-value" id="stat-sent-today">–</div>
        <div class="stat-label">Sent Today</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#dbeafe">📅</div>
        <div class="stat-value" id="stat-sent-month">–</div>
        <div class="stat-label">Sent This Month</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#fef3c7">🎯</div>
        <div class="stat-value" id="stat-quota-used">–</div>
        <div class="stat-label">Quota Used</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#fee2e2">❌</div>
        <div class="stat-value" id="stat-failed">–</div>
        <div class="stat-label">Failed Messages</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#e0f2fe">👤</div>
        <div class="stat-value" id="stat-contacts">–</div>
        <div class="stat-label">Total Contacts</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#f0fdf4">📣</div>
        <div class="stat-value" id="stat-campaigns">–</div>
        <div class="stat-label">Active Campaigns</div>
    </div>
    <div class="wacrm-stat-card">
        <div class="stat-icon" style="background:#faf5ff">⚡</div>
        <div class="stat-value" id="stat-automations">–</div>
        <div class="stat-label">Active Automations</div>
    </div>
</div>

<div class="wacrm-card">
    <div class="wacrm-card-header"><h2>Messages – Last 7 Days</h2></div>
    <div class="wacrm-chart-wrap">
        <canvas id="wacrm-daily-chart"></canvas>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
