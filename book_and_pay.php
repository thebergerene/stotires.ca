<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/square.php';

header('Content-Type: application/json');

function json_fail(string $message, int $httpStatus = 400, array $extra = []): void
{
    http_response_code($httpStatus);
    echo json_encode(array_merge(['success' => false, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_fail('Invalid request method.', 405);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    json_fail('Invalid request body.');
}

/**
 * Adds whole calendar months to a date, clamping to the last valid day of the
 * target month when the original day doesn't exist there (e.g. Aug 31 + 6
 * months lands on Feb 28/29, not an overflowed "Mar 3").
 */
function add_calendar_months(DateTime $date, int $months): DateTime
{
    $year  = (int)$date->format('Y');
    $month = (int)$date->format('n') + $months;
    $day   = (int)$date->format('j');

    while ($month > 12) {
        $month -= 12;
        $year++;
    }

    $daysInTargetMonth = (int)(new DateTime(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $day = min($day, $daysInTargetMonth);

    return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

const MAX_SETS = 3;

$package_id     = filter_var($input['package_id'] ?? null, FILTER_VALIDATE_INT);
$zone_id        = filter_var($input['zone_id'] ?? null, FILTER_VALIDATE_INT);
$quantity       = filter_var($input['quantity'] ?? 1, FILTER_VALIDATE_INT);
$full_name      = trim((string)($input['full_name'] ?? ''));
$email          = trim((string)($input['email'] ?? ''));
$phone          = trim((string)($input['phone'] ?? ''));
$address        = trim((string)($input['address'] ?? ''));
$vehicle_info   = trim((string)($input['vehicle_info'] ?? ''));
$tire_size      = trim((string)($input['tire_size'] ?? ''));
$pickup_raw     = trim((string)($input['pickup_date'] ?? ''));
$delivery_raw   = trim((string)($input['delivery_date'] ?? ''));
$notes          = trim((string)($input['notes'] ?? ''));
$sourceId       = (string)($input['source_id'] ?? '');
$idempotencyKey = (string)($input['idempotency_key'] ?? '');

// ---- Validate booking fields ----
if (!$package_id) {
    json_fail('Please choose a storage package.');
}
if (!$zone_id) {
    json_fail('Please choose your area for pickup and delivery.');
}
if (!$quantity || $quantity < 1 || $quantity > MAX_SETS) {
    json_fail('You can store between 1 and ' . MAX_SETS . ' sets of tires per booking.');
}
if ($full_name === '' || mb_strlen($full_name) > 120) {
    json_fail('Please enter your full name.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_fail('Please enter a valid email address.');
}
if ($phone === '' || mb_strlen($phone) > 40) {
    json_fail('Please enter a phone number.');
}
if ($address === '' || mb_strlen($address) > 200) {
    json_fail('Please enter the address where we should pick up and deliver your tires.');
}
if ($vehicle_info === '' || mb_strlen($vehicle_info) > 160) {
    json_fail('Please tell us the year, make, and model of your vehicle.');
}

$today      = new DateTime('today');
$pickupDate = DateTime::createFromFormat('Y-m-d', $pickup_raw);
if (!$pickupDate || $pickupDate < $today) {
    json_fail('Please choose a valid pickup date (today or later).');
}

// Delivery date defaults to exactly 6 calendar months after pickup on the
// form, but the customer can adjust it — allow a reasonable window around
// that default rather than requiring the exact date.
$deliveryDate = DateTime::createFromFormat('Y-m-d', $delivery_raw);
if (!$deliveryDate || $deliveryDate <= $pickupDate) {
    json_fail('Please choose a delivery date after your pickup date.');
}

$defaultDelivery = add_calendar_months($pickupDate, 6);
$minDelivery     = add_calendar_months($pickupDate, 4);
$maxDelivery     = add_calendar_months($pickupDate, 8);
if ($deliveryDate < $minDelivery || $deliveryDate > $maxDelivery) {
    json_fail('Your delivery date should be roughly 6 months after pickup (between 4 and 8 months) — the default is ' . $defaultDelivery->format('F j, Y') . '.');
}

// ---- Validate payment fields ----
if ($sourceId === '' || $idempotencyKey === '') {
    json_fail('Missing card details. Please try again.');
}
if (strlen($idempotencyKey) > 45) {
    // Square caps idempotency keys at 45 characters.
    $idempotencyKey = substr(hash('sha256', $idempotencyKey), 0, 45);
}

$db = get_db();

$stmt = $db->prepare('SELECT id, name, price_cents FROM packages WHERE id = ? AND is_active = 1');
$stmt->execute([$package_id]);
$package = $stmt->fetch();
if (!$package) {
    json_fail('That package could not be found. Please choose one from the list.');
}

$stmt = $db->prepare('SELECT id, name, weekday_csv, service_days, surcharge_cents FROM zones WHERE id = ?');
$stmt->execute([$zone_id]);
$zone = $stmt->fetch();
if (!$zone) {
    json_fail('That area could not be found. Please choose one from the list.');
}

// Enforce that the pickup date actually falls on a day this zone services,
// even though the form already snaps to a valid date client-side.
$allowedWeekdays = array_map('intval', explode(',', $zone['weekday_csv']));
if (!in_array((int)$pickupDate->format('N'), $allowedWeekdays, true)) {
    json_fail("Pickup for {$zone['name']} is only available on {$zone['service_days']}. Please pick a matching date.");
}

$amountDueCents = ((int)$package['price_cents'] * $quantity) + (int)$zone['surcharge_cents'];
$accessToken    = bin2hex(random_bytes(16));

// ---- Book and charge atomically: the booking only persists if the charge succeeds ----
$db->beginTransaction();
$bookingId = null;
$payment   = null;

try {
    $stmt = $db->prepare(
        'INSERT INTO bookings (package_id, zone_id, full_name, email, phone, address, vehicle_info, tire_size, quantity, pickup_date, delivery_date, notes, amount_due_cents, access_token)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $package_id,
        $zone_id,
        $full_name,
        $email,
        $phone,
        $address,
        $vehicle_info,
        $tire_size !== '' ? $tire_size : null,
        $quantity,
        $pickupDate->format('Y-m-d'),
        $deliveryDate->format('Y-m-d'),
        $notes !== '' ? $notes : null,
        $amountDueCents,
        $accessToken,
    ]);
    $bookingId = (int)$db->lastInsertId();

    $result = square_create_payment(
        $sourceId,
        $amountDueCents,
        $idempotencyKey,
        (string)$bookingId,
        'Drydock Tire Storage — ' . $package['name'] . ' x' . $quantity . ' set' . ($quantity > 1 ? 's' : '') . ' (Booking #' . $bookingId . ')'
    );

    if (!$result['ok']) {
        // Card declined or otherwise rejected — roll back so no unpaid booking is left behind.
        $db->rollBack();
        json_fail(square_error_message($result['errors']), 402);
    }

    $payment = $result['payment'];

    $db->prepare(
        'INSERT INTO payments (booking_id, square_payment_id, amount_cents, currency, status, receipt_url)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $bookingId,
        $payment['id'],
        $payment['amount_money']['amount'] ?? $amountDueCents,
        $payment['amount_money']['currency'] ?? SQUARE_CURRENCY,
        $payment['status'] ?? 'COMPLETED',
        $payment['receipt_url'] ?? null,
    ]);

    $db->prepare('UPDATE bookings SET payment_status = ?, status = ? WHERE id = ?')
       ->execute(['paid', 'confirmed', $bookingId]);

    $db->commit();

    echo json_encode([
        'success'     => true,
        'bookingId'   => $bookingId,
        'accessToken' => $accessToken,
        'receiptUrl'  => $payment['receipt_url'] ?? null,
    ]);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        try { $db->rollBack(); } catch (Throwable $ignore) { /* best effort */ }
    }

    if ($payment && !empty($payment['id'])) {
        // Square already charged the card, but we couldn't persist the booking —
        // this must never be silently lost. Log everything needed to reconcile by hand.
        error_log(sprintf(
            'CRITICAL: Square payment %s for %s <%s> (attempted booking #%s) succeeded but the booking could not be saved: %s',
            $payment['id'], $full_name, $email, $bookingId ?? 'unknown', $e->getMessage()
        ));
        json_fail(
            'Your payment went through, but we hit a technical problem saving your booking. ' .
            'Please email ' . SITE_EMAIL . ' or call ' . SITE_PHONE . ' with this reference: ' .
            $payment['id'] . ' and we will confirm your bay right away.',
            200,
            ['paymentSucceededNoBooking' => true, 'reference' => $payment['id']]
        );
    }

    error_log('Booking/payment error: ' . $e->getMessage());
    json_fail('Something went wrong processing your booking. Please try again.', 500);
}
