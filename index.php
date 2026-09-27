<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = get_db();
$packages = $db->query('SELECT * FROM packages WHERE is_active = 1 ORDER BY sort_order ASC')->fetchAll();
$zones    = $db->query('SELECT * FROM zones ORDER BY sort_order ASC')->fetchAll();

$preselect = isset($_GET['package']) ? (string)$_GET['package'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(SITE_NAME) ?> — 6-Month Tire Storage</title>
<meta name="description" content="Secure 6-month tire storage in a monitored shipping container yard. Drop off, we tag and store, pick up when you're ready.">
<link rel="stylesheet" href="css/style.css">
<script>
    // Package prices and zone rules, for client-side estimate + pickup-day enforcement.
    const PACKAGE_PRICES = <?= json_encode(array_column($packages, 'price_cents', 'id')) ?>;
    const ZONE_SURCHARGES = <?= json_encode(array_column($zones, 'surcharge_cents', 'id')) ?>;
    const ZONE_WEEKDAYS = <?= json_encode(array_map(
        fn($z) => array_map('intval', explode(',', $z['weekday_csv'])),
        array_column($zones, null, 'id')
    )) ?>;
    const ZONE_DAY_LABELS = <?= json_encode(array_column($zones, 'service_days', 'id')) ?>;
    // Square's public application/location IDs — safe to expose client-side (the access token stays server-only).
    const SQUARE_APPLICATION_ID = <?= json_encode(SQUARE_APPLICATION_ID) ?>;
    const SQUARE_LOCATION_ID    = <?= json_encode(SQUARE_LOCATION_ID) ?>;
</script>
<script src="<?= h(SQUARE_ENVIRONMENT === 'production' ? 'https://web.squarecdn.com/v1/square.js' : 'https://sandbox.web.squarecdn.com/v1/square.js') ?>"></script>
</head>
<body>

<div class="topbar">
    <div class="wrap">
        <a class="brand" href="#top"><span class="dot"></span><?= h(SITE_NAME) ?></a>
        <nav>
            <a href="#how">How it works</a>
            <a href="#packages">Packages</a>
            <a href="#book">Book a bay</a>
        </nav>
    </div>
</div>

<header class="hero" id="top">
    <div class="container-walls"></div>
    <div class="wrap hero-inner">
        <div class="hero-copy">
            <span class="eyebrow">6-MONTH SEASONAL STORAGE</span>
            <h1>Your tires spend the <span>off-season</span> in our container. Not your garage.</h1>
            <p>Drop your winter or summer set with us for six months. Every tire is tagged, logged, and stacked off the ground in a locked, monitored sea container &mdash; ready the moment you text us for pickup.</p>
            <a href="#packages" class="btn btn-primary">See packages</a>
            <a href="#book" class="btn btn-outline">Book a bay</a>
        </div>
        <div class="rig">
            <div class="bulb-glow"></div>
            <div class="cord"></div>
            <div class="bulb"></div>
            <div class="floor-glow"></div>
            <div class="tire-stack">
                <div class="tire"></div>
                <div class="tire"></div>
                <div class="tire"></div>
                <div class="tire"></div>
            </div>
        </div>
    </div>
</header>

<section id="how">
    <div class="wrap">
        <div class="section-head">
            <span class="kicker">THE PROCESS</span>
            <h2>Three steps, six months apart</h2>
            <p>No appointments to juggle mid-season &mdash; just a drop-off and a pickup, whenever you're ready for either.</p>
        </div>
        <div class="steps">
            <div class="step">
                <div class="num">1</div>
                <h3>Book your bay</h3>
                <p>Pick a package below and tell us when you're dropping off. We hold the space.</p>
            </div>
            <div class="step">
                <div class="num">2</div>
                <h3>Drop off &amp; tag</h3>
                <p>Bring your tires to the yard. We log the vehicle, tag each tire, and rack them.</p>
            </div>
            <div class="step">
                <div class="num">3</div>
                <h3>Pick up anytime</h3>
                <p>Within your 6-month window, just give us a heads-up and swing by to grab them.</p>
            </div>
        </div>
    </div>
</section>

<section id="packages">
    <div class="wrap">
        <div class="section-head">
            <span class="kicker">PACKAGES</span>
            <h2>Three ways to store a set</h2>
            <p>All packages cover a full 6-month term, however the price is shown.</p>
        </div>
        <div class="packages">
            <?php foreach ($packages as $i => $pkg): ?>
                <?php $featured = (bool)$pkg['is_featured']; ?>
                <div class="tag <?= $featured ? 'featured' : '' ?>">
                    <?php if ($featured): ?><span class="badge">MOST BOOKED</span><?php endif; ?>
                    <h3><?= h($pkg['name']) ?></h3>
                    <?php if ($pkg['monthly_rate_cents']): ?>
                        <div class="price">$<?= number_format($pkg['monthly_rate_cents'] / 100, 0) ?> <small>/mo per tire</small></div>
                        <div class="price-total">$<?= number_format($pkg['price_cents'] / 100, 0) ?> total for 6 months, up to 4 tires</div>
                    <?php else: ?>
                        <div class="price">$<?= number_format($pkg['price_cents'] / 100, 0) ?> <small>/ 6 months</small></div>
                    <?php endif; ?>
                    <div class="tagline"><?= h($pkg['tagline']) ?></div>
                    <ul>
                        <?php foreach (explode("\n", $pkg['features']) as $feature): ?>
                            <li><?= h($feature) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="#book" class="btn <?= $featured ? 'btn-primary' : 'btn-outline' ?>" onclick="selectPackage('<?= (int)$pkg['id'] ?>')">Book this package</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="booking" id="book">
    <div class="wrap">
        <div class="section-head">
            <span class="kicker">RESERVE A BAY</span>
            <h2>Book your storage bay</h2>
            <p>Fill this in and pay securely by card &mdash; your bay is confirmed the moment payment clears.</p>
        </div>

        <div id="booking-error" class="alert alert-error" hidden></div>
        <div id="booking-success" class="alert alert-success" hidden></div>

        <form class="form-grid" id="booking-form">
            <input type="hidden" name="package_id" id="package_id" value="<?= h($preselect) ?>">

            <div>
                <label for="full_name">Full name</label>
                <input type="text" id="full_name" name="full_name" required maxlength="120">
            </div>
            <div>
                <label for="package_select">Package</label>
                <select id="package_select" name="package_select" required onchange="document.getElementById('package_id').value=this.value; updateEstimate();">
                    <option value="">Choose a package&hellip;</option>
                    <?php foreach ($packages as $pkg): ?>
                        <option value="<?= (int)$pkg['id'] ?>" <?= ((string)$pkg['id'] === $preselect) ? 'selected' : '' ?>>
                            <?= h($pkg['name']) ?> &mdash; $<?= number_format($pkg['price_cents'] / 100, 0) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required maxlength="160">
            </div>
            <div>
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" required maxlength="40">
            </div>

            <div class="full">
                <label for="address">Pickup &amp; delivery address</label>
                <input type="text" id="address" name="address" required maxlength="200" placeholder="e.g. 123 Main St, Ottawa, ON K1A 0B1">
                <p class="form-note">Where we'll collect and return your tires.</p>
            </div>

            <div>
                <label for="vehicle_info">Vehicle (year / make / model)</label>
                <input type="text" id="vehicle_info" name="vehicle_info" required maxlength="160" placeholder="e.g. 2019 Honda CR-V">
            </div>
            <div>
                <label for="tire_size">Tire size (optional)</label>
                <input type="text" id="tire_size" name="tire_size" maxlength="60" placeholder="e.g. 225/65R17">
            </div>

            <div class="full">
                <label for="quantity">Number of sets</label>
                <select id="quantity" name="quantity" required onchange="updateEstimate()">
                    <option value="1">1 set (up to 4 tires)</option>
                    <option value="2">2 sets (up to 8 tires)</option>
                    <option value="3">3 sets (up to 12 tires)</option>
                </select>
                <p class="form-note">Maximum 3 sets per booking. Same price per set, whichever package you choose.</p>
            </div>

            <div class="full">
                <label>Your area (sets pickup &amp; delivery days)</label>
                <div class="zone-options">
                    <?php foreach ($zones as $i => $zone): ?>
                        <label class="zone-option" for="zone_<?= (int)$zone['id'] ?>">
                            <input type="radio" id="zone_<?= (int)$zone['id'] ?>" name="zone_id" value="<?= (int)$zone['id'] ?>" required onchange="onZoneChange()" <?= $i === 0 ? 'checked' : '' ?>>
                            <span class="zone-name"><?= h($zone['name']) ?></span>
                            <span class="zone-areas"><?= h($zone['areas']) ?></span>
                            <span class="zone-meta"><?= h($zone['service_days']) ?> &middot; <?= $zone['surcharge_cents'] > 0 ? '+$' . number_format($zone['surcharge_cents'] / 100, 0) : 'No surcharge' ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="position:relative;">
                <label for="pickup_date_display">Pickup date</label>
                <input type="text" id="pickup_date_display" readonly required placeholder="Choose a date" autocomplete="off" onclick="toggleCalendar()">
                <input type="hidden" id="pickup_date" name="pickup_date">
                <div id="pickup-calendar" class="calendar-popup" hidden></div>
                <p class="form-note" id="pickup-hint">Choose your area above to see available pickup days.</p>
                <p class="form-error" id="pickup-error" hidden></p>
            </div>
            <div>
                <label for="delivery_date">Delivery date</label>
                <input type="date" id="delivery_date" name="delivery_date" required>
                <p class="form-note">Defaults to 6 months after pickup &mdash; feel free to adjust.</p>
            </div>

            <div class="full">
                <label for="notes">Notes (optional)</label>
                <input type="text" id="notes" name="notes" maxlength="255" placeholder="Anything we should know?">
            </div>

            <div class="full estimate" id="estimate" hidden>
                Total due today: <strong id="estimate-amount">$0</strong>
                <span id="estimate-breakdown"></span>
            </div>

            <div class="full">
                <label>Card details</label>
                <div id="card-container"></div>
            </div>

            <div class="full form-actions">
                <button type="button" id="book-button" class="btn btn-primary">Pay &amp; reserve my bay</button>
                <span class="form-note">Payments are processed securely by Square. We never see or store your card number.</span>
            </div>
        </form>
    </div>
</section>

<footer>
    <div class="wrap">
        <div><?= h(SITE_NAME) ?> &middot; <?= h(SITE_ADDRESS) ?></div>
        <div><?= h(SITE_PHONE) ?> &middot; <?= h(SITE_EMAIL) ?></div>
    </div>
</footer>


<script src="js/main.js"></script>
</body>
</html>
