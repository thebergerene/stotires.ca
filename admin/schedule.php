<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login();
$db = get_db();

$dateParam = (string)($_GET['date'] ?? '');
$selectedDate = DateTime::createFromFormat('Y-m-d', $dateParam);
if (!$selectedDate) {
    $selectedDate = new DateTime('today');
}
$dateStr = $selectedDate->format('Y-m-d');

$prevDate = (clone $selectedDate)->modify('-1 day')->format('Y-m-d');
$nextDate = (clone $selectedDate)->modify('+1 day')->format('Y-m-d');
$todayStr = (new DateTime('today'))->format('Y-m-d');

$pickupStmt = $db->prepare(
    "SELECT b.*, p.name AS package_name, z.name AS zone_name
     FROM bookings b
     JOIN packages p ON p.id = b.package_id
     JOIN zones z ON z.id = b.zone_id
     WHERE b.pickup_date = ? AND b.status = 'confirmed'
     ORDER BY z.sort_order ASC, b.full_name ASC"
);
$pickupStmt->execute([$dateStr]);
$pickups = $pickupStmt->fetchAll();

$deliveryStmt = $db->prepare(
    "SELECT b.*, p.name AS package_name, z.name AS zone_name
     FROM bookings b
     JOIN packages p ON p.id = b.package_id
     JOIN zones z ON z.id = b.zone_id
     WHERE b.delivery_date = ? AND b.status = 'dropped_off'
     ORDER BY z.sort_order ASC, b.full_name ASC"
);
$deliveryStmt->execute([$dateStr]);
$deliveries = $deliveryStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Schedule — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
<style>
    .sched-nav { display:flex; align-items:center; justify-content:center; gap:14px; margin-bottom:22px; }
    .sched-nav a, .sched-nav .sched-date-form { display:inline-block; }
    .sched-nav input[type="date"] { padding:8px; }
    .sched-section { margin-bottom:32px; }
    .sched-section h3 { font-size:1.1rem; margin-bottom:12px; display:flex; align-items:center; gap:10px; }
    .sched-count { font-size:0.8rem; font-weight:600; background:var(--paper-2); color:var(--ink); padding:2px 10px; border-radius:20px; }
    .stop-card { background:#fff; border:1px solid #d8d2c3; border-left:4px solid var(--rust); padding:16px 18px; margin-bottom:10px; }
    .stop-card.delivery { border-left-color: var(--steel-blue); }
    .stop-top { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
    .stop-name { font-weight:700; font-size:1.02rem; }
    .stop-zone { font-size:0.78rem; color:#6b6659; text-transform:uppercase; letter-spacing:0.03em; font-weight:600; }
    .stop-address { font-size:0.95rem; margin-bottom:6px; }
    .stop-address.missing { color:#a03f14; font-style:italic; }
    .stop-meta { font-size:0.85rem; color:#4a463e; display:flex; flex-wrap:wrap; gap:14px; }
    .stop-meta a { color: var(--rust-dark); }
    .stop-bin { font-weight:700; }
    .empty-note { color:#6b6659; font-size:0.9rem; padding: 10px 0; }
    @media print {
        .sched-nav, .topbar, footer, .btn { display:none !important; }
    }
</style>
</head>
<body class="admin-body">
<div class="admin-shell">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h2>Warehouse schedule</h2>
        <div>
            <a href="dashboard.php" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">Back to bookings</a>
            <button type="button" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);" onclick="window.print()">Print this day</button>
        </div>
    </div>
    <p style="font-size:0.85rem; color:#6b6659; margin-bottom:20px;">
        Logged in as <strong><?= h($currentUser['full_name']) ?></strong> (<?= h(ucfirst($currentUser['role'])) ?>)
    </p>

    <div class="sched-nav">
        <a href="?date=<?= h($prevDate) ?>" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">&lsaquo; Prev day</a>
        <form class="sched-date-form" method="get">
            <input type="date" name="date" value="<?= h($dateStr) ?>" onchange="this.form.submit()">
        </form>
        <a href="?date=<?= h($nextDate) ?>" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Next day &rsaquo;</a>
        <?php if ($dateStr !== $todayStr): ?>
            <a href="?date=<?= h($todayStr) ?>" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Today</a>
        <?php endif; ?>
    </div>

    <h2 style="text-align:center; margin-bottom:28px;"><?= h($selectedDate->format('l, F j, Y')) ?></h2>

    <div class="sched-section">
        <h3>Pickups due <span class="sched-count"><?= count($pickups) ?></span></h3>
        <?php if (!$pickups): ?>
            <p class="empty-note">No pickups scheduled for this day.</p>
        <?php endif; ?>
        <?php foreach ($pickups as $b): ?>
            <div class="stop-card">
                <div class="stop-top">
                    <span class="stop-name">#<?= (int)$b['id'] ?> &middot; <?= h($b['full_name']) ?></span>
                    <span class="stop-zone"><?= h($b['zone_name']) ?></span>
                </div>
                <?php if ($b['address']): ?>
                    <div class="stop-address"><?= h($b['address']) ?></div>
                <?php else: ?>
                    <div class="stop-address missing">No address on file &mdash; call the customer before heading out.</div>
                <?php endif; ?>
                <div class="stop-meta">
                    <span>&#128222; <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $b['phone'])) ?>"><?= h($b['phone']) ?></a></span>
                    <span><?= h($b['package_name']) ?> &times;<?= (int)$b['quantity'] ?></span>
                    <span><?= h($b['vehicle_info']) ?></span>
                    <?php if ($b['notes']): ?><span>Note: <?= h($b['notes']) ?></span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="sched-section">
        <h3>Deliveries due <span class="sched-count"><?= count($deliveries) ?></span></h3>
        <?php if (!$deliveries): ?>
            <p class="empty-note">No deliveries scheduled for this day.</p>
        <?php endif; ?>
        <?php foreach ($deliveries as $b): ?>
            <div class="stop-card delivery">
                <div class="stop-top">
                    <span class="stop-name">#<?= (int)$b['id'] ?> &middot; <?= h($b['full_name']) ?></span>
                    <span class="stop-zone"><?= h($b['zone_name']) ?></span>
                </div>
                <?php if ($b['address']): ?>
                    <div class="stop-address"><?= h($b['address']) ?></div>
                <?php else: ?>
                    <div class="stop-address missing">No address on file &mdash; call the customer before heading out.</div>
                <?php endif; ?>
                <div class="stop-meta">
                    <span>&#128222; <a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $b['phone'])) ?>"><?= h($b['phone']) ?></a></span>
                    <span><?= h($b['package_name']) ?> &times;<?= (int)$b['quantity'] ?></span>
                    <span>Bin: <span class="stop-bin"><?= h($b['bin_number'] ?: 'Not assigned') ?></span></span>
                    <?php if ($b['notes']): ?><span>Note: <?= h($b['notes']) ?></span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <p style="font-size:0.8rem; color:#8a8578;">
        Pickups shown here have status "confirmed"; deliveries shown have status "dropped_off". Update a
        booking's status on the <a href="dashboard.php">bookings page</a> once it's done so it drops off
        this list.
    </p>
</div>
</body>
</html>
