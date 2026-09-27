<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_admin();

$db = get_db();
$flash = null;

const WEEKDAY_NAMES = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

/** Turns [2,3] into "Tuesday & Wednesday", [1,3,5] into "Monday, Wednesday & Friday", etc. */
function format_weekday_label(array $days): string
{
    sort($days);
    $labels = array_values(array_filter(array_map(fn($d) => WEEKDAY_NAMES[$d] ?? null, $days)));
    if (count($labels) === 0) return '';
    if (count($labels) === 1) return $labels[0];
    $last = array_pop($labels);
    return implode(', ', $labels) . ' & ' . $last;
}

function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'zone';
}

// Handle updating an existing zone.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_zone'])) {
    $zoneId    = filter_input(INPUT_POST, 'zone_id', FILTER_VALIDATE_INT);
    $name      = trim((string)($_POST['name'] ?? ''));
    $areas     = trim((string)($_POST['areas'] ?? ''));
    $surcharge = filter_input(INPUT_POST, 'surcharge', FILTER_VALIDATE_FLOAT);
    $days      = array_map('intval', $_POST['weekdays'] ?? []);
    $days      = array_values(array_intersect($days, range(1, 7)));

    try {
        if (!$zoneId) {
            throw new InvalidArgumentException('Missing zone.');
        }
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Please enter a zone name.');
        }
        if ($areas === '' || mb_strlen($areas) > 200) {
            throw new InvalidArgumentException('Please describe the area covered.');
        }
        if ($surcharge === false || $surcharge === null || $surcharge < 0) {
            throw new InvalidArgumentException('Please enter a valid surcharge amount (0 or more).');
        }
        if (empty($days)) {
            throw new InvalidArgumentException('Select at least one pickup day.');
        }

        $db->prepare('UPDATE zones SET name = ?, areas = ?, surcharge_cents = ?, service_days = ?, weekday_csv = ? WHERE id = ?')
           ->execute([$name, $areas, (int)round($surcharge * 100), format_weekday_label($days), implode(',', $days), $zoneId]);

        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "Zone \"$name\" saved."];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Zone update error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not save the zone: ' . $e->getMessage()];
    }

    header('Location: zones.php');
    exit;
}

// Handle adding a new zone.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_zone'])) {
    $name      = trim((string)($_POST['name'] ?? ''));
    $areas     = trim((string)($_POST['areas'] ?? ''));
    $surcharge = filter_input(INPUT_POST, 'surcharge', FILTER_VALIDATE_FLOAT);
    $days      = array_map('intval', $_POST['weekdays'] ?? []);
    $days      = array_values(array_intersect($days, range(1, 7)));

    try {
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Please enter a zone name.');
        }
        if ($areas === '' || mb_strlen($areas) > 200) {
            throw new InvalidArgumentException('Please describe the area covered.');
        }
        if ($surcharge === false || $surcharge === null || $surcharge < 0) {
            throw new InvalidArgumentException('Please enter a valid surcharge amount (0 or more).');
        }
        if (empty($days)) {
            throw new InvalidArgumentException('Select at least one pickup day.');
        }

        // Make sure the slug is unique even if two zones would otherwise generate the same one.
        $baseSlug = slugify($name);
        $slug = $baseSlug;
        $suffix = 2;
        $stmt = $db->prepare('SELECT COUNT(*) FROM zones WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ((int)$stmt->fetchColumn() === 0) break;
            $slug = $baseSlug . '-' . $suffix;
            $suffix++;
        }

        $nextSort = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM zones')->fetchColumn();

        $db->prepare(
            'INSERT INTO zones (slug, name, areas, surcharge_cents, service_days, weekday_csv, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$slug, $name, $areas, (int)round($surcharge * 100), format_weekday_label($days), implode(',', $days), $nextSort]);

        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "Zone \"$name\" added."];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Zone create error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not add the zone: ' . $e->getMessage()];
    }

    header('Location: zones.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$zones = $db->query('SELECT * FROM zones ORDER BY sort_order ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage zones — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
<style>
    .zone-card { background:#fff; border:1px solid #d8d2c3; padding:20px; margin-bottom:18px; }
    .zone-card h3 { font-size:1.05rem; margin-bottom:14px; }
    .zone-form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    .zone-form-grid .full { grid-column: 1 / -1; }
    .weekday-checks { display:flex; flex-wrap:wrap; gap:12px; margin-top:6px; }
    .weekday-checks label { display:flex; align-items:center; gap:5px; font-weight:400; font-size:0.9rem; margin-bottom:0; }
    .weekday-checks input { width:auto; }
</style>
</head>
<body class="admin-body">
<div class="admin-shell">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <h2>Manage zones</h2>
        <div>
            <a href="dashboard.php" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">Back to bookings</a>
            <a href="packages.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage packages</a>
            <a href="users.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage users</a>
            <a href="account.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">My account</a>
            <a href="logout.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Log out</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <p style="font-size:0.85rem; color:#6b6659; margin-bottom:20px;">
        Changing a zone's pickup days here takes effect immediately for new bookings on the homepage.
        It does not change the pickup date on any booking already made.
    </p>

    <?php foreach ($zones as $zone): ?>
        <?php $zoneDays = $zone['weekday_csv'] !== '' ? array_map('intval', explode(',', $zone['weekday_csv'])) : []; ?>
        <div class="zone-card">
            <h3><?= h($zone['name']) ?> <span style="font-weight:400; color:#8a8578; font-size:0.85rem;">(<?= h($zone['slug']) ?>)</span></h3>
            <form method="post">
                <input type="hidden" name="zone_id" value="<?= (int)$zone['id'] ?>">
                <div class="zone-form-grid">
                    <div>
                        <label>Zone name</label>
                        <input type="text" name="name" value="<?= h($zone['name']) ?>" maxlength="80" required>
                    </div>
                    <div>
                        <label>Surcharge ($)</label>
                        <input type="number" name="surcharge" value="<?= number_format($zone['surcharge_cents'] / 100, 2, '.', '') ?>" min="0" step="0.01" required>
                    </div>
                    <div class="full">
                        <label>Area description (shown to customers)</label>
                        <input type="text" name="areas" value="<?= h($zone['areas']) ?>" maxlength="200" required>
                    </div>
                    <div class="full">
                        <label>Pickup days</label>
                        <div class="weekday-checks">
                            <?php foreach (WEEKDAY_NAMES as $num => $label): ?>
                                <label>
                                    <input type="checkbox" name="weekdays[]" value="<?= $num ?>" <?= in_array($num, $zoneDays, true) ? 'checked' : '' ?>>
                                    <?= h($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="full">
                        <button type="submit" name="update_zone" value="1" class="btn btn-primary">Save zone</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endforeach; ?>

    <div class="zone-card" style="border-style:dashed;">
        <h3>Add a new zone</h3>
        <form method="post">
            <div class="zone-form-grid">
                <div>
                    <label>Zone name</label>
                    <input type="text" name="name" placeholder="e.g. Zone E — North of the River" maxlength="80" required>
                </div>
                <div>
                    <label>Surcharge ($)</label>
                    <input type="number" name="surcharge" value="0.00" min="0" step="0.01" required>
                </div>
                <div class="full">
                    <label>Area description (shown to customers)</label>
                    <input type="text" name="areas" placeholder="e.g. North of the Ottawa River" maxlength="200" required>
                </div>
                <div class="full">
                    <label>Pickup days</label>
                    <div class="weekday-checks">
                        <?php foreach (WEEKDAY_NAMES as $num => $label): ?>
                            <label>
                                <input type="checkbox" name="weekdays[]" value="<?= $num ?>">
                                <?= h($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="full">
                    <button type="submit" name="add_zone" value="1" class="btn btn-primary">Add zone</button>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>
