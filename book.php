<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

function back_with_error(string $message): void
{
    $_SESSION['flash'] = ['type' => 'error', 'message' => $message];
    header('Location: index.php#book');
    exit;
}

// A booking's storage term should stay reasonably close to 6 months even
// though the customer can nudge the delivery date around a bit.
const MIN_STORAGE_DAYS = 120; // ~4 months
const MAX_STORAGE_DAYS = 240; // ~8 months

$package_id   = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT);
$zone_id      = filter_input(INPUT_POST, 'zone_id', FILTER_VALIDATE_INT);
$full_name    = trim((string)($_POST['full_name'] ?? ''));
$email        = trim((string)($_POST['email'] ?? ''));
$phone        = trim((string)($_POST['phone'] ?? ''));
$vehicle_info = trim((string)($_POST['vehicle_info'] ?? ''));
$tire_size    = trim((string)($_POST['tire_size'] ?? ''));
$pickup_raw   = trim((string)($_POST['pickup_date'] ?? ''));
$delivery_raw = trim((string)($_POST['delivery_date'] ?? ''));
$notes        = trim((string)($_POST['notes'] ?? ''));

// ---- Validate ----
if (!$package_id) {
    back_with_error('Please choose a storage package.');
}
if (!$zone_id) {
    back_with_error('Please choose your area for pickup and delivery.');
}
if ($full_name === '' || mb_strlen($full_name) > 120) {
    back_with_error('Please enter your full name.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_with_error('Please enter a valid email address.');
}
if ($phone === '' || mb_strlen($phone) > 40) {
    back_with_error('Please enter a phone number.');
}
if ($vehicle_info === '' || mb_strlen($vehicle_info) > 160) {
    back_with_error('Please tell us the year, make, and model of your vehicle.');
}

$today       = new DateTime('today');
$pickupDate  = DateTime::createFromFormat('Y-m-d', $pickup_raw);
if (!$pickupDate || $pickupDate < $today) {
    back_with_error('Please choose a valid pickup date (today or later).');
}

$deliveryDate = DateTime::createFromFormat('Y-m-d', $delivery_raw);
if (!$deliveryDate || $deliveryDate <= $pickupDate) {
    back_with_error('Please choose a delivery date after your pickup date.');
}

$storageDays = (int)$pickupDate->diff($deliveryDate)->days;
if ($storageDays < MIN_STORAGE_DAYS || $storageDays > MAX_STORAGE_DAYS) {
    back_with_error('Your delivery date should be roughly 6 months after pickup (between 4 and 8 months).');
}

$db = get_db();

// Confirm the package and zone actually exist, and grab what we need to price the booking.
$stmt = $db->prepare('SELECT id, price_cents FROM packages WHERE id = ?');
$stmt->execute([$package_id]);
$package = $stmt->fetch();
if (!$package) {
    back_with_error('That package could not be found. Please choose one from the list.');
}

$stmt = $db->prepare('SELECT id, name, weekday_csv, service_days, surcharge_cents FROM zones WHERE id = ?');
$stmt->execute([$zone_id]);
$zone = $stmt->fetch();
if (!$zone) {
    back_with_error('That area could not be found. Please choose one from the list.');
}

// Enforce that the pickup date actually falls on a day this zone services,
// even though the form already snaps to a valid date client-side.
$allowedWeekdays = array_map('intval', explode(',', $zone['weekday_csv']));
if (!in_array((int)$pickupDate->format('N'), $allowedWeekdays, true)) {
    back_with_error("Pickup for {$zone['name']} is only available on {$zone['service_days']}. Please pick a matching date.");
}

$amountDueCents = (int)$package['price_cents'] + (int)$zone['surcharge_cents'];
$accessToken    = bin2hex(random_bytes(16));

$stmt = $db->prepare(
    'INSERT INTO bookings (package_id, zone_id, full_name, email, phone, vehicle_info, tire_size, pickup_date, delivery_date, notes, amount_due_cents, access_token)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([
    $package_id,
    $zone_id,
    $full_name,
    $email,
    $phone,
    $vehicle_info,
    $tire_size !== '' ? $tire_size : null,
    $pickupDate->format('Y-m-d'),
    $deliveryDate->format('Y-m-d'),
    $notes !== '' ? $notes : null,
    $amountDueCents,
    $accessToken,
]);

$bookingId = (int)$db->lastInsertId();

// The booking is saved but not yet paid — send the customer straight to the
// Square payment page to finish reserving their bay.
header('Location: pay.php?booking=' . $bookingId . '&token=' . $accessToken);
exit;
