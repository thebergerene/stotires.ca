<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_admin();

$db = get_db();
$flash = null;

function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'package';
}

/** Reads the shared fields (everything but slug/id) from a submitted package form. */
function read_package_form(array $post): array
{
    $price = filter_var($post['price'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($price === false || $price === null || $price < 0) {
        throw new InvalidArgumentException('Please enter a valid price (0 or more).');
    }

    $monthlyRateCents = null;
    $rateRaw = trim((string)($post['monthly_rate'] ?? ''));
    if ($rateRaw !== '') {
        $rate = filter_var($rateRaw, FILTER_VALIDATE_FLOAT);
        if ($rate === false || $rate < 0) {
            throw new InvalidArgumentException('Please enter a valid monthly rate, or leave it blank.');
        }
        $monthlyRateCents = (int)round($rate * 100);
    }

    $tireLimit = filter_var($post['tire_limit'] ?? null, FILTER_VALIDATE_INT);
    if (!$tireLimit || $tireLimit < 1 || $tireLimit > 8) {
        throw new InvalidArgumentException('Tires per set should be a number between 1 and 8.');
    }

    $sortOrder = filter_var($post['sort_order'] ?? 0, FILTER_VALIDATE_INT);

    $name     = trim((string)($post['name'] ?? ''));
    $tagline  = trim((string)($post['tagline'] ?? ''));
    $features = trim((string)($post['features'] ?? ''));

    if ($name === '' || mb_strlen($name) > 80) {
        throw new InvalidArgumentException('Please enter a package name.');
    }
    if ($tagline === '' || mb_strlen($tagline) > 160) {
        throw new InvalidArgumentException('Please enter a tagline (under 160 characters).');
    }
    if ($features === '') {
        throw new InvalidArgumentException('Please list at least one feature (one per line).');
    }

    return [
        'name'               => $name,
        'tagline'            => $tagline,
        'features'           => $features,
        'tire_limit'         => $tireLimit,
        'price_cents'        => (int)round($price * 100),
        'monthly_rate_cents' => $monthlyRateCents,
        'is_featured'        => isset($post['is_featured']) ? 1 : 0,
        'is_active'          => isset($post['is_active']) ? 1 : 0,
        'sort_order'         => $sortOrder !== false ? $sortOrder : 0,
    ];
}

// Update an existing package.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_package'])) {
    $packageId = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT);
    try {
        if (!$packageId) {
            throw new InvalidArgumentException('Missing package.');
        }
        $fields = read_package_form($_POST);

        // Only one package should show the "MOST BOOKED" badge at a time.
        if ($fields['is_featured']) {
            $db->prepare('UPDATE packages SET is_featured = 0 WHERE id <> ?')->execute([$packageId]);
        }

        $db->prepare(
            'UPDATE packages SET name=?, tagline=?, features=?, tire_limit=?, price_cents=?, monthly_rate_cents=?, is_featured=?, is_active=?, sort_order=? WHERE id=?'
        )->execute([
            $fields['name'], $fields['tagline'], $fields['features'], $fields['tire_limit'],
            $fields['price_cents'], $fields['monthly_rate_cents'], $fields['is_featured'],
            $fields['is_active'], $fields['sort_order'], $packageId,
        ]);

        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "\"{$fields['name']}\" saved."];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Package update error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not save the package: ' . $e->getMessage()];
    }
    header('Location: packages.php');
    exit;
}

// Add a new package.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_package'])) {
    try {
        $fields = read_package_form($_POST);

        $baseSlug = slugify($fields['name']);
        $slug = $baseSlug;
        $suffix = 2;
        $stmt = $db->prepare('SELECT COUNT(*) FROM packages WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() === 0) break;
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        if ($fields['is_featured']) {
            $db->exec('UPDATE packages SET is_featured = 0');
        }
        if (!$fields['sort_order']) {
            $fields['sort_order'] = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM packages')->fetchColumn();
        }

        $db->prepare(
            'INSERT INTO packages (slug, name, tagline, features, tire_limit, price_cents, monthly_rate_cents, is_featured, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $slug, $fields['name'], $fields['tagline'], $fields['features'], $fields['tire_limit'],
            $fields['price_cents'], $fields['monthly_rate_cents'], $fields['is_featured'],
            $fields['is_active'], $fields['sort_order'],
        ]);

        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "\"{$fields['name']}\" added."];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Package create error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not add the package: ' . $e->getMessage()];
    }
    header('Location: packages.php');
    exit;
}

// Delete a package, but only if no booking has ever referenced it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_package'])) {
    $packageId = filter_input(INPUT_POST, 'package_id', FILTER_VALIDATE_INT);
    try {
        if (!$packageId) {
            throw new InvalidArgumentException('Missing package.');
        }
        $stmt = $db->prepare('SELECT COUNT(*) FROM bookings WHERE package_id = ?');
        $stmt->execute([$packageId]);
        $bookingCount = (int)$stmt->fetchColumn();

        if ($bookingCount > 0) {
            throw new InvalidArgumentException(
                "Can't delete this package — $bookingCount booking(s) reference it. " .
                'Turn off "Visible on site" instead to retire it without losing that history.'
            );
        }

        $db->prepare('DELETE FROM packages WHERE id = ?')->execute([$packageId]);
        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Package deleted.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Package delete error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not delete the package: ' . $e->getMessage()];
    }
    header('Location: packages.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$packages = $db->query('SELECT * FROM packages ORDER BY sort_order ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage packages — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
<style>
    .pkg-card { background:#fff; border:1px solid #d8d2c3; padding:20px; margin-bottom:18px; }
    .pkg-card.inactive { opacity: 0.65; }
    .pkg-card h3 { font-size:1.05rem; margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .pkg-badge { font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; padding:2px 8px; border:1px solid var(--rust); color:var(--rust); }
    .pkg-badge.hidden-badge { border-color:#8a8578; color:#8a8578; }
    .pkg-form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    .pkg-form-grid .full { grid-column: 1 / -1; }
    .pkg-form-grid textarea { min-height: 110px; font-family: inherit; }
    .rate-preview { font-size:0.8rem; color:#6b6659; margin-top:4px; }
    .pkg-flags { display:flex; gap:20px; flex-wrap:wrap; }
    .pkg-flags label { display:flex; align-items:center; gap:6px; font-weight:400; font-size:0.9rem; margin-bottom:0; }
    .pkg-flags input { width:auto; }
    .pkg-actions { display:flex; justify-content:space-between; align-items:center; }
</style>
</head>
<body class="admin-body">
<div class="admin-shell">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <h2>Manage packages</h2>
        <div>
            <a href="zones.php" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">Manage zones</a>
            <a href="dashboard.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Back to bookings</a>
            <a href="users.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage users</a>
            <a href="account.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">My account</a>
            <a href="logout.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Log out</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <p style="font-size:0.85rem; color:#6b6659; margin-bottom:20px;">
        "Total price" is what's actually charged per set for the 6-month term — this is the number used in
        billing everywhere on the site. "Advertised monthly rate" is optional display copy only (shows as
        "$X/mo per tire" on the package card instead of a flat total); leave it blank to just show the flat
        total. Only one package can be "Most booked" at a time — checking it here unchecks it everywhere
        else automatically. Unchecking "Visible on site" hides a package from the homepage and blocks new
        bookings for it, without deleting its history. Changes only affect new bookings going forward.
    </p>

    <?php foreach ($packages as $pkg): ?>
        <div class="pkg-card <?= $pkg['is_active'] ? '' : 'inactive' ?>">
            <h3>
                <?= h($pkg['name']) ?>
                <span style="font-weight:400; color:#8a8578; font-size:0.85rem;">(<?= h($pkg['slug']) ?>)</span>
                <?php if ($pkg['is_featured']): ?><span class="pkg-badge">Most booked</span><?php endif; ?>
                <?php if (!$pkg['is_active']): ?><span class="pkg-badge hidden-badge">Hidden</span><?php endif; ?>
            </h3>
            <form method="post">
                <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                <div class="pkg-form-grid">
                    <div>
                        <label>Package name</label>
                        <input type="text" name="name" value="<?= h($pkg['name']) ?>" maxlength="80" required>
                    </div>
                    <div>
                        <label>Total price per set, 6 months ($)</label>
                        <input type="number" name="price" value="<?= number_format($pkg['price_cents'] / 100, 2, '.', '') ?>" min="0" step="0.01" required>
                    </div>
                    <div class="full">
                        <label>Tagline</label>
                        <input type="text" name="tagline" value="<?= h($pkg['tagline']) ?>" maxlength="160" required>
                    </div>
                    <div class="full">
                        <label>Features (one per line)</label>
                        <textarea name="features" required><?= h($pkg['features']) ?></textarea>
                    </div>
                    <div>
                        <label>Advertised monthly rate per tire ($, optional)</label>
                        <input type="number" name="monthly_rate" value="<?= $pkg['monthly_rate_cents'] !== null ? number_format($pkg['monthly_rate_cents'] / 100, 2, '.', '') : '' ?>" min="0" step="0.01" placeholder="leave blank for flat pricing">
                        <p class="rate-preview">4 tires &times; 6 months &times; this rate should equal the total price above, if you want the two to line up.</p>
                    </div>
                    <div>
                        <label>Tires per set</label>
                        <input type="number" name="tire_limit" value="<?= (int)$pkg['tire_limit'] ?>" min="1" max="8" required>
                    </div>
                    <div>
                        <label>Display order</label>
                        <input type="number" name="sort_order" value="<?= (int)$pkg['sort_order'] ?>" min="0" required>
                    </div>
                    <div class="full pkg-flags">
                        <label><input type="checkbox" name="is_featured" <?= $pkg['is_featured'] ? 'checked' : '' ?>> Most booked (badge)</label>
                        <label><input type="checkbox" name="is_active" <?= $pkg['is_active'] ? 'checked' : '' ?>> Visible on site</label>
                    </div>
                    <div class="full pkg-actions">
                        <button type="submit" name="update_package" value="1" class="btn btn-primary">Save package</button>
                    </div>
                </div>
            </form>
            <form method="post" onsubmit="return confirm('Delete this package permanently? This only works if it has no bookings.');" style="margin-top:10px;">
                <input type="hidden" name="package_id" value="<?= (int)$pkg['id'] ?>">
                <button type="submit" name="delete_package" value="1" class="btn btn-outline" style="margin-left:0; border-color:var(--rust); color:var(--rust); font-size:0.82rem; padding:6px 14px;">Delete package</button>
            </form>
        </div>
    <?php endforeach; ?>

    <div class="pkg-card" style="border-style:dashed;">
        <h3>Add a new package</h3>
        <form method="post">
            <div class="pkg-form-grid">
                <div>
                    <label>Package name</label>
                    <input type="text" name="name" placeholder="e.g. Weekend Warrior" maxlength="80" required>
                </div>
                <div>
                    <label>Total price per set, 6 months ($)</label>
                    <input type="number" name="price" value="0.00" min="0" step="0.01" required>
                </div>
                <div class="full">
                    <label>Tagline</label>
                    <input type="text" name="tagline" placeholder="One line describing this package" maxlength="160" required>
                </div>
                <div class="full">
                    <label>Features (one per line)</label>
                    <textarea name="features" placeholder="Up to 4 tires per set&#10;Individually tagged&#10;..." required></textarea>
                </div>
                <div>
                    <label>Advertised monthly rate per tire ($, optional)</label>
                    <input type="number" name="monthly_rate" min="0" step="0.01" placeholder="leave blank for flat pricing">
                </div>
                <div>
                    <label>Tires per set</label>
                    <input type="number" name="tire_limit" value="4" min="1" max="8" required>
                </div>
                <div>
                    <label>Display order</label>
                    <input type="number" name="sort_order" value="0" min="0">
                    <p class="rate-preview">Leave as 0 to add it at the end automatically.</p>
                </div>
                <div class="full pkg-flags">
                    <label><input type="checkbox" name="is_featured"> Most booked (badge)</label>
                    <label><input type="checkbox" name="is_active" checked> Visible on site</label>
                </div>
                <div class="full">
                    <button type="submit" name="add_package" value="1" class="btn btn-primary">Add package</button>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>
