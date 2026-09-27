<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['is_admin'])) {
    header('Location: login.php');
    exit;
}

$bookingId = filter_input(INPUT_GET, 'booking', FILTER_VALIDATE_INT);
if (!$bookingId) {
    http_response_code(400);
    die('Missing or invalid booking id.');
}

$db = get_db();
$stmt = $db->prepare(
    'SELECT b.*, p.name AS package_name, z.name AS zone_name
     FROM bookings b
     JOIN packages p ON p.id = b.package_id
     JOIN zones z ON z.id = b.zone_id
     WHERE b.id = ?'
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    die('Booking not found.');
}

// What actually gets encoded in the barcode. Change the prefix/padding here
// if you'd rather scan something else (e.g. just the raw booking id).
$barcodeValue = 'DTS' . str_pad((string)$booking['id'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Barcode labels — Booking #<?= (int)$booking['id'] ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/JsBarcode/3.11.5/JsBarcode.all.min.js"></script>
<style>
    :root { --ink:#14130f; }
    * { box-sizing: border-box; }
    body { font-family: Arial, Helvetica, sans-serif; margin: 0; background: #ddd; color: var(--ink); }

    .toolbar { padding: 16px; text-align: center; background:#fff; border-bottom:1px solid #ccc; }
    .toolbar button, .toolbar a {
        font-family: inherit; font-size: 0.95rem; padding: 10px 18px; margin: 0 6px;
        border: 1px solid var(--ink); background:#fff; color:var(--ink); cursor:pointer;
        text-decoration:none; display:inline-block;
    }
    .toolbar button:hover, .toolbar a:hover { background: var(--ink); color:#fff; }

    /* One US Letter sheet, 4 identical labels in a 2x2 grid. */
    .sheet {
        width: 8.5in;
        min-height: 11in;
        margin: 20px auto;
        background: #fff;
        padding: 0.4in;
        display: grid;
        grid-template-columns: 1fr 1fr;
        grid-template-rows: 1fr 1fr;
        box-shadow: 0 0 8px rgba(0,0,0,0.2);
    }
    .label {
        border: 1px dashed #999;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 0.25in;
        text-align: center;
        overflow: hidden;
    }
    .label .biz { font-size: 0.85rem; font-weight: bold; letter-spacing: 0.05em; margin-bottom: 4px; text-transform: uppercase; }
    .label svg { max-width: 100%; }
    .label .meta { margin-top: 6px; font-size: 0.78rem; line-height: 1.45; }
    .label .meta strong { font-size: 0.86rem; }

    @media print {
        body { background: #fff; }
        .toolbar { display: none; }
        .sheet { margin: 0; box-shadow: none; width: auto; min-height: auto; }
        @page { size: letter; margin: 0.4in; }
    }
</style>
</head>
<body>

<div class="toolbar">
    <button onclick="window.print()">Print this sheet</button>
    <a href="dashboard.php">Back to bookings</a>
</div>

<div class="sheet">
    <?php for ($i = 0; $i < 4; $i++): ?>
        <div class="label">
            <div class="biz"><?= h(SITE_NAME) ?></div>
            <svg class="barcode"></svg>
            <div class="meta">
                <strong><?= h($booking['full_name']) ?></strong><br>
                <?= h($booking['package_name']) ?> &times;<?= (int)$booking['quantity'] ?> &middot; <?= h($booking['zone_name']) ?><br>
                Pickup: <?= h(date('M j, Y', strtotime($booking['pickup_date']))) ?>
                <?php if ($booking['bin_number']): ?> &middot; Bin: <strong><?= h($booking['bin_number']) ?></strong><?php endif; ?>
            </div>
        </div>
    <?php endfor; ?>
</div>

<script>
    const barcodeValue = <?= json_encode($barcodeValue) ?>;
    document.querySelectorAll('.label svg.barcode').forEach(function (svg) {
        if (typeof JsBarcode === 'undefined') {
            svg.outerHTML = '<p style="color:#a03f14;font-size:0.8rem;">Barcode library failed to load \u2014 check your internet connection.</p>';
            return;
        }
        JsBarcode(svg, barcodeValue, {
            format: 'CODE128',
            width: 2,
            height: 50,
            fontSize: 14,
            margin: 4,
        });
    });
</script>

</body>
</html>
