<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$bookingId = filter_input(INPUT_GET, 'booking', FILTER_VALIDATE_INT);
if (!$bookingId) {
    http_response_code(400);
    die('Missing or invalid booking id.');
}

$db = get_db();
$stmt = $db->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    http_response_code(404);
    die('Booking not found.');
}

$returnDate = date('M j, Y', strtotime($booking['delivery_date']));
$binText    = $booking['bin_number'] ?: 'Not assigned yet';

// Plain text encoded in the QR itself -- readable by any generic QR scanner,
// no lookup system or internet connection required to make sense of it.
$qrText = SITE_NAME . "\n" .
    'Booking #' . $booking['id'] . "\n" .
    'Name: ' . $booking['full_name'] . "\n" .
    'Return: ' . $returnDate . "\n" .
    'Bin: ' . $binText;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR labels — Booking #<?= (int)$booking['id'] ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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
    .label .biz { font-size: 0.85rem; font-weight: bold; letter-spacing: 0.05em; margin-bottom: 8px; text-transform: uppercase; }
    .label .qr-box { width: 140px; height: 140px; }
    .label .meta { margin-top: 10px; font-size: 0.9rem; line-height: 1.5; }
    .label .meta .name { font-size: 1rem; font-weight: bold; }
    .label .meta .bin { font-weight: bold; }

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
            <div class="qr-box qr-target"></div>
            <div class="meta">
                <div class="name"><?= h($booking['full_name']) ?></div>
                Return: <?= h($returnDate) ?><br>
                Bin: <span class="bin"><?= h($binText) ?></span>
            </div>
        </div>
    <?php endfor; ?>
</div>

<script>
    const qrText = <?= json_encode($qrText) ?>;
    document.querySelectorAll('.qr-target').forEach(function (el) {
        if (typeof QRCode === 'undefined') {
            el.textContent = 'QR library failed to load — check your internet connection.';
            el.style.fontSize = '0.75rem';
            el.style.color = '#a03f14';
            return;
        }
        new QRCode(el, {
            text: qrText,
            width: 140,
            height: 140,
            colorDark: '#14130f',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M,
        });
    });
</script>

</body>
</html>
