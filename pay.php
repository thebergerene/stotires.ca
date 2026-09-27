<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$bookingId = filter_input(INPUT_GET, 'booking', FILTER_VALIDATE_INT);
$token     = (string)($_GET['token'] ?? '');

$booking = null;
if ($bookingId && $token !== '') {
    $db = get_db();
    $stmt = $db->prepare(
        'SELECT b.*, p.name AS package_name, z.name AS zone_name
         FROM bookings b
         JOIN packages p ON p.id = b.package_id
         JOIN zones z ON z.id = b.zone_id
         WHERE b.id = ?'
    );
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if ($row && hash_equals($row['access_token'], $token)) {
        $booking = $row;
    }
}

$payment = null;
if ($booking) {
    $stmt = $db->prepare('SELECT * FROM payments WHERE booking_id = ? AND status <> ? ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$booking['id'], 'FAILED']);
    $payment = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your booking — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="css/style.css">
</head>
<body>

<div class="topbar">
    <div class="wrap">
        <a class="brand" href="index.php"><span class="dot"></span><?= h(SITE_NAME) ?></a>
    </div>
</div>

<section class="pay-section">
    <div class="wrap wrap-narrow">

    <?php if (!$booking): ?>

        <div class="alert alert-error">
            We couldn't find that booking. If you followed a link from your confirmation email, please check it's
            copied in full, or <a href="index.php#book">start a new booking</a>.
        </div>

    <?php else: ?>

        <div class="section-head">
            <span class="kicker">BOOKING #<?= (int)$booking['id'] ?></span>
            <h2>You're all set, <?= h(explode(' ', $booking['full_name'])[0]) ?></h2>
            <p>Here's a summary of your storage booking. Bookmark this page or keep the emailed link handy.</p>
        </div>

        <div class="summary-card">
            <div class="summary-row"><span>Package</span><strong><?= h($booking['package_name']) ?> &times;<?= (int)$booking['quantity'] ?></strong></div>
            <div class="summary-row"><span>Area</span><strong><?= h($booking['zone_name']) ?></strong></div>
            <div class="summary-row"><span>Pickup</span><strong><?= h(date('D, M j, Y', strtotime($booking['pickup_date']))) ?></strong></div>
            <div class="summary-row"><span>Delivery</span><strong><?= h(date('D, M j, Y', strtotime($booking['delivery_date']))) ?></strong></div>
            <div class="summary-row"><span>Status</span><strong><span class="status-pill status-<?= h($booking['status']) ?>"><?= h(str_replace('_', ' ', $booking['status'])) ?></span></strong></div>
            <div class="summary-row total">
                <span><?= $booking['payment_status'] === 'paid' ? 'Paid' : 'Amount due' ?></span>
                <strong>$<?= number_format($booking['amount_due_cents'] / 100, 2) ?> <?= h(SQUARE_CURRENCY) ?></strong>
            </div>
        </div>

        <?php if ($booking['payment_status'] === 'paid'): ?>
            <div class="alert alert-success">
                Payment received &mdash; your bay is confirmed.
                <?php if ($payment && $payment['receipt_url']): ?>
                    <br><a href="<?= h($payment['receipt_url']) ?>" target="_blank" rel="noopener">View your Square receipt</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-error">
                This booking hasn't been paid yet. Please contact us at <?= h(SITE_EMAIL) ?> or <?= h(SITE_PHONE) ?>
                and we'll help you sort it out.
            </div>
        <?php endif; ?>

        <p style="font-size:0.9rem; color:#5b564a;">Questions about your booking? Reach us at <?= h(SITE_EMAIL) ?> or <?= h(SITE_PHONE) ?>.</p>

    <?php endif; ?>

    </div>
</section>

<footer>
    <div class="wrap">
        <div><?= h(SITE_NAME) ?> &middot; <?= h(SITE_ADDRESS) ?></div>
        <div><?= h(SITE_PHONE) ?> &middot; <?= h(SITE_EMAIL) ?></div>
    </div>
</footer>

</body>
</html>
