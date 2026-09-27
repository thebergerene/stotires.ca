<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login();

$db = get_db();
$flash = null;

// Handle a status change.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $bookingId = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    $status    = (string)($_POST['status'] ?? '');
    $allowed   = ['pending', 'confirmed', 'dropped_off', 'picked_up', 'cancelled'];
    try {
        if ($bookingId && in_array($status, $allowed, true)) {
            $stmt = $db->prepare('UPDATE bookings SET status = ? WHERE id = ?');
            $stmt->execute([$status, $bookingId]);
        }
    } catch (Throwable $e) {
        error_log('Status update error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not update status: ' . $e->getMessage()];
    }
    header('Location: dashboard.php');
    exit;
}

// Handle a bin number assignment.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_bin'])) {
    $bookingId  = filter_input(INPUT_POST, 'booking_id', FILTER_VALIDATE_INT);
    $binNumber  = strtoupper(trim((string)($_POST['bin_number'] ?? '')));

    try {
        if ($bookingId) {
            if ($binNumber === '') {
                // Clearing the bin is always allowed.
                $db->prepare('UPDATE bookings SET bin_number = NULL WHERE id = ?')->execute([$bookingId]);
            } elseif (!preg_match('/^[A-Z][LR][0-9]{2}$/', $binNumber)) {
                $_SESSION['admin_flash'] = [
                    'type' => 'error',
                    'message' => "\"$binNumber\" isn't a valid bin (format: container letter + L/R + 2 digits, e.g. AL01).",
                ];
            } else {
                // Don't let the same bin be assigned to two bookings that are both still active.
                $stmt = $db->prepare(
                    "SELECT id, full_name FROM bookings
                     WHERE bin_number = ? AND id <> ? AND status NOT IN ('picked_up', 'cancelled')
                     LIMIT 1"
                );
                $stmt->execute([$binNumber, $bookingId]);
                $conflict = $stmt->fetch();

                if ($conflict) {
                    $_SESSION['admin_flash'] = [
                        'type' => 'error',
                        'message' => "Bin $binNumber is already assigned to booking #{$conflict['id']} ({$conflict['full_name']}), which hasn't been picked up yet.",
                    ];
                } else {
                    $db->prepare('UPDATE bookings SET bin_number = ? WHERE id = ?')->execute([$binNumber, $bookingId]);
                    $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "Bin $binNumber saved."];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Bin assignment error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = [
            'type' => 'error',
            'message' => 'Could not save the bin number: ' . $e->getMessage() .
                ' — if this mentions an unknown column, run migration_bins.sql against your database.',
        ];
    }
    header('Location: dashboard.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$bookings = $db->query(
    'SELECT b.*, p.name AS package_name, z.name AS zone_name
     FROM bookings b
     JOIN packages p ON p.id = b.package_id
     JOIN zones z ON z.id = b.zone_id
     ORDER BY b.created_at DESC'
)->fetchAll();

$statuses = ['pending', 'confirmed', 'dropped_off', 'picked_up', 'cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bookings — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="admin-body">
<div class="admin-shell">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <h2>Bookings (<?= count($bookings) ?>)</h2>
        <div>
            <a href="schedule.php" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">Schedule</a>
            <?php if ($currentUser['role'] === 'admin'): ?>
                <a href="zones.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage zones</a>
                <a href="packages.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage packages</a>
                <a href="users.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage users</a>
            <?php endif; ?>
            <a href="account.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">My account</a>
            <a href="logout.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Log out</a>
        </div>
    </div>
    <p style="font-size:0.85rem; color:#6b6659; margin-bottom:16px;">
        Logged in as <strong><?= h($currentUser['full_name']) ?></strong> (<?= h(ucfirst($currentUser['role'])) ?>)
    </p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <p style="font-size:0.85rem; color:#6b6659; margin-bottom:16px;">
        Bin format: container letter + L/R (side) + 2-digit slot &mdash; e.g. <strong>AL01</strong> = Container A, Left, slot 01.
    </p>

    <div style="overflow-x:auto;">
    <table class="bookings">
        <thead>
        <tr>
            <th>Booked</th>
            <th>Customer</th>
            <th>Vehicle</th>
            <th>Package</th>
            <th>Area</th>
            <th>Pickup</th>
            <th>Delivery</th>
            <th>Payment</th>
            <th>Bin</th>
            <th>Status</th>
            <th></th>
            <th>Label</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($bookings as $b): ?>
            <tr>
                <td><?= h(date('M j, Y', strtotime($b['created_at']))) ?></td>
                <td>
                    <strong><?= h($b['full_name']) ?></strong><br>
                    <span style="color:#6b6659;"><?= h($b['email']) ?> &middot; <?= h($b['phone']) ?></span>
                    <?php if ($b['address']): ?><br><span style="color:#6b6659; font-size:0.85em;"><?= h($b['address']) ?></span><?php endif; ?>
                </td>
                <td><?= h($b['vehicle_info']) ?><?= $b['tire_size'] ? '<br><span style="color:#6b6659;">' . h($b['tire_size']) . '</span>' : '' ?></td>
                <td><?= h($b['package_name']) ?> &times;<?= (int)$b['quantity'] ?></td>
                <td><?= h($b['zone_name']) ?></td>
                <td><?= h(date('M j, Y', strtotime($b['pickup_date']))) ?></td>
                <td><?= h(date('M j, Y', strtotime($b['delivery_date']))) ?></td>
                <td>
                    $<?= number_format($b['amount_due_cents'] / 100, 2) ?><br>
                    <span class="status-pill status-<?= $b['payment_status'] === 'paid' ? 'picked_up' : 'pending' ?>"><?= h($b['payment_status']) ?></span>
                    <br><a href="../pay.php?booking=<?= (int)$b['id'] ?>&token=<?= h($b['access_token']) ?>" target="_blank" style="font-size:0.8rem;">view booking</a>
                </td>
                <td>
                    <form method="post" style="display:flex; gap:6px;">
                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                        <input type="text" name="bin_number" value="<?= h($b['bin_number']) ?>" placeholder="AL01" maxlength="4"
                               pattern="[A-Za-z][LRlr][0-9]{2}" title="Container letter + L/R + 2 digits, e.g. AL01"
                               style="width:70px; text-transform:uppercase; padding:6px;">
                        <button type="submit" name="assign_bin" value="1" class="btn btn-outline" style="margin-left:0; padding:6px 12px; border-color:var(--ink); color:var(--ink); font-size:0.82rem;">Save</button>
                    </form>
                </td>
                <td><span class="status-pill status-<?= h($b['status']) ?>"><?= h(str_replace('_', ' ', $b['status'])) ?></span></td>
                <td>
                    <form method="post" style="display:flex; gap:6px;">
                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                        <select name="status" style="padding:6px;">
                            <?php foreach ($statuses as $s): ?>
                                <option value="<?= h($s) ?>" <?= $s === $b['status'] ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="update_status" value="1" class="btn btn-outline" style="margin-left:0; padding:6px 12px; border-color:var(--ink); color:var(--ink); font-size:0.82rem;">Save</button>
                    </form>
                </td>
                <td>
                    <a href="labels.php?booking=<?= (int)$b['id'] ?>" target="_blank" class="btn btn-outline" style="margin-left:0; padding:6px 12px; border-color:var(--ink); color:var(--ink); font-size:0.82rem; white-space:nowrap;">Print labels</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$bookings): ?>
            <tr><td colspan="12" style="text-align:center; color:#6b6659;">No bookings yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>
