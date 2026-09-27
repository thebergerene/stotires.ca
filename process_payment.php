<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/square.php';

header('Content-Type: application/json');

function json_fail(string $message, int $httpStatus = 400): void
{
    http_response_code($httpStatus);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Invalid request method.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_fail('Invalid request body.');
}

$bookingId      = filter_var($input['booking_id'] ?? null, FILTER_VALIDATE_INT);
$token          = (string)($input['token'] ?? '');
$sourceId       = (string)($input['source_id'] ?? '');
$idempotencyKey = (string)($input['idempotency_key'] ?? '');

if (!$bookingId || $token === '' || $sourceId === '' || $idempotencyKey === '') {
    json_fail('Missing required payment fields.');
}
if (strlen($idempotencyKey) > 45) {
    // Square caps idempotency keys at 45 characters.
    $idempotencyKey = substr(hash('sha256', $idempotencyKey), 0, 45);
}

$db = get_db();
$db->beginTransaction();

try {
    // Lock the row so a double-click or a retry can't charge the same booking twice.
    $stmt = $db->prepare(
        'SELECT b.*, p.name AS package_name
         FROM bookings b JOIN packages p ON p.id = b.package_id
         WHERE b.id = ? FOR UPDATE'
    );
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (!$booking || !hash_equals($booking['access_token'], $token)) {
        $db->rollBack();
        json_fail('Booking not found.', 404);
    }

    if ($booking['payment_status'] === 'paid') {
        $db->commit();
        echo json_encode(['success' => true, 'alreadyPaid' => true, 'message' => 'This booking is already paid.']);
        exit;
    }

    $result = square_create_payment(
        $sourceId,
        (int)$booking['amount_due_cents'],
        $idempotencyKey,
        (string)$booking['id'],
        'Drydock Tire Storage — ' . $booking['package_name'] . ' (Booking #' . $booking['id'] . ')'
    );

    if (!$result['ok']) {
        // Log the failed attempt for admin visibility, but leave the booking unpaid.
        $db->prepare(
            'INSERT INTO payments (booking_id, square_payment_id, amount_cents, currency, status, receipt_url)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $bookingId,
            $result['payment']['id'] ?? ('failed-' . substr($idempotencyKey, 0, 40)),
            (int)$booking['amount_due_cents'],
            SQUARE_CURRENCY,
            'FAILED',
            null,
        ]);
        $db->commit();
        json_fail(square_error_message($result['errors']), 402);
    }

    $payment = $result['payment'];

    $db->prepare(
        'INSERT INTO payments (booking_id, square_payment_id, amount_cents, currency, status, receipt_url)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $bookingId,
        $payment['id'],
        $payment['amount_money']['amount'] ?? $booking['amount_due_cents'],
        $payment['amount_money']['currency'] ?? SQUARE_CURRENCY,
        $payment['status'] ?? 'COMPLETED',
        $payment['receipt_url'] ?? null,
    ]);

    $newStatus = $booking['status'] === 'pending' ? 'confirmed' : $booking['status'];
    $db->prepare('UPDATE bookings SET payment_status = ?, status = ? WHERE id = ?')
       ->execute(['paid', $newStatus, $bookingId]);

    $db->commit();

    echo json_encode([
        'success'    => true,
        'receiptUrl' => $payment['receipt_url'] ?? null,
        'message'    => 'Payment received — your bay is confirmed.',
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Payment processing error: ' . $e->getMessage());
    json_fail('Something went wrong processing your payment. Please try again.', 500);
}
