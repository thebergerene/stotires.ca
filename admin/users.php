<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_admin();

$db = get_db();
$flash = null;

const ROLES = ['admin' => 'Admin', 'logistics' => 'Logistics'];

/** True if deactivating/demoting/deleting $userId would leave zero active admins. */
function would_remove_last_admin(PDO $db, int $userId): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND id <> ?");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn() === 0;
}

// Update an existing user.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $userId    = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $fullName  = trim((string)($_POST['full_name'] ?? ''));
    $role      = (string)($_POST['role'] ?? '');
    $isActive  = isset($_POST['is_active']) ? 1 : 0;
    $password  = (string)($_POST['password'] ?? '');

    try {
        if (!$userId) {
            throw new InvalidArgumentException('Missing user.');
        }
        if ($fullName === '' || mb_strlen($fullName) > 120) {
            throw new InvalidArgumentException('Please enter a full name.');
        }
        if (!array_key_exists($role, ROLES)) {
            throw new InvalidArgumentException('Please choose a valid role.');
        }
        if ($password !== '' && mb_strlen($password) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters (or leave it blank to keep the current one).');
        }

        $becomingNonAdminOrInactive = ($role !== 'admin' || !$isActive);
        if ($becomingNonAdminOrInactive && would_remove_last_admin($db, $userId)) {
            throw new InvalidArgumentException('Can\'t do that — this is the only active admin account. Promote or activate another admin first.');
        }

        if ($password !== '') {
            $db->prepare('UPDATE users SET full_name = ?, role = ?, is_active = ?, password_hash = ? WHERE id = ?')
               ->execute([$fullName, $role, $isActive, password_hash($password, PASSWORD_DEFAULT), $userId]);
        } else {
            $db->prepare('UPDATE users SET full_name = ?, role = ?, is_active = ? WHERE id = ?')
               ->execute([$fullName, $role, $isActive, $userId]);
        }

        // If this admin just changed their own role/status in a way that logged them out
        // of admin pages, that's caught naturally on their next page load via require_admin().
        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "\"$fullName\" saved."];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('User update error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not save the user: ' . $e->getMessage()];
    }
    header('Location: users.php');
    exit;
}

// Add a new user.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username  = trim((string)($_POST['username'] ?? ''));
    $fullName  = trim((string)($_POST['full_name'] ?? ''));
    $role      = (string)($_POST['role'] ?? '');
    $password  = (string)($_POST['password'] ?? '');

    try {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,60}$/', $username)) {
            throw new InvalidArgumentException('Username must be 3-60 characters: letters, numbers, dots, underscores, or hyphens only.');
        }
        if ($fullName === '' || mb_strlen($fullName) > 120) {
            throw new InvalidArgumentException('Please enter a full name.');
        }
        if (!array_key_exists($role, ROLES)) {
            throw new InvalidArgumentException('Please choose a valid role.');
        }
        if (mb_strlen($password) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("Username \"$username\" is already taken.");
        }

        $db->prepare('INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)')
           ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $role]);

        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => "User \"$username\" added."];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('User create error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not add the user: ' . $e->getMessage()];
    }
    header('Location: users.php');
    exit;
}

// Delete a user.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    try {
        if (!$userId) {
            throw new InvalidArgumentException('Missing user.');
        }
        if ($userId === $currentUser['id']) {
            throw new InvalidArgumentException("You can't delete your own account while logged in as it.");
        }
        if (would_remove_last_admin($db, $userId)) {
            throw new InvalidArgumentException('Can\'t delete this account — it\'s the only active admin.');
        }
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'User deleted.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('User delete error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not delete the user: ' . $e->getMessage()];
    }
    header('Location: users.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$users = $db->query('SELECT * FROM users ORDER BY role = "admin" DESC, username ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage users — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
<style>
    .user-card { background:#fff; border:1px solid #d8d2c3; padding:20px; margin-bottom:18px; }
    .user-card.inactive { opacity: 0.65; }
    .user-card h3 { font-size:1.05rem; margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .user-badge { font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.03em; padding:2px 8px; border:1px solid #8a8578; color:#8a8578; }
    .user-badge.you { border-color: var(--rust); color: var(--rust); }
    .user-form-grid { display:grid; grid-template-columns: 1fr 1fr; gap:14px; }
    .user-form-grid .full { grid-column: 1 / -1; }
    .user-flags label { display:flex; align-items:center; gap:6px; font-weight:400; font-size:0.9rem; }
    .user-flags input { width:auto; }
</style>
</head>
<body class="admin-body">
<div class="admin-shell">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <h2>Manage users</h2>
        <div>
            <a href="dashboard.php" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">Back to bookings</a>
            <a href="zones.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage zones</a>
            <a href="packages.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Manage packages</a>
            <a href="account.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">My account</a>
            <a href="logout.php" class="btn btn-outline" style="border-color:var(--ink); color:var(--ink);">Log out</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <p style="font-size:0.85rem; color:#6b6659; margin-bottom:20px;">
        <strong>Admin</strong> can manage zones, packages, and users, plus everything logistics can do.
        <strong>Logistics</strong> can view bookings, assign bins, update status, and print labels, but can't
        change zones, pricing, or other users. Leave "New password" blank when saving an existing user to keep
        their current password. There must always be at least one active admin.
    </p>

    <?php foreach ($users as $u): ?>
        <div class="user-card <?= $u['is_active'] ? '' : 'inactive' ?>">
            <h3>
                <?= h($u['full_name']) ?>
                <span style="font-weight:400; color:#8a8578; font-size:0.85rem;">@<?= h($u['username']) ?></span>
                <?php if ((int)$u['id'] === $currentUser['id']): ?><span class="user-badge you">You</span><?php endif; ?>
                <?php if (!$u['is_active']): ?><span class="user-badge">Deactivated</span><?php endif; ?>
            </h3>
            <form method="post">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <div class="user-form-grid">
                    <div>
                        <label>Full name</label>
                        <input type="text" name="full_name" value="<?= h($u['full_name']) ?>" maxlength="120" required>
                    </div>
                    <div>
                        <label>Role</label>
                        <select name="role">
                            <?php foreach (ROLES as $value => $label): ?>
                                <option value="<?= h($value) ?>" <?= $u['role'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="full">
                        <label>New password (optional)</label>
                        <input type="password" name="password" minlength="8" placeholder="Leave blank to keep current password" autocomplete="new-password">
                    </div>
                    <div class="full user-flags">
                        <label><input type="checkbox" name="is_active" <?= $u['is_active'] ? 'checked' : '' ?>> Active</label>
                    </div>
                    <div class="full" style="display:flex; justify-content:space-between; align-items:center;">
                        <button type="submit" name="update_user" value="1" class="btn btn-primary">Save user</button>
                    </div>
                </div>
            </form>
            <?php if ((int)$u['id'] !== $currentUser['id']): ?>
                <form method="post" onsubmit="return confirm('Delete this user permanently?');" style="margin-top:10px;">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" name="delete_user" value="1" class="btn btn-outline" style="margin-left:0; border-color:var(--rust); color:var(--rust); font-size:0.82rem; padding:6px 14px;">Delete user</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <div class="user-card" style="border-style:dashed;">
        <h3>Add a new user</h3>
        <form method="post">
            <div class="user-form-grid">
                <div>
                    <label>Username</label>
                    <input type="text" name="username" placeholder="e.g. jsmith" maxlength="60" required>
                </div>
                <div>
                    <label>Full name</label>
                    <input type="text" name="full_name" placeholder="e.g. Jamie Smith" maxlength="120" required>
                </div>
                <div>
                    <label>Role</label>
                    <select name="role">
                        <?php foreach (ROLES as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $value === 'logistics' ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Password</label>
                    <input type="password" name="password" minlength="8" required autocomplete="new-password">
                </div>
                <div class="full">
                    <button type="submit" name="add_user" value="1" class="btn btn-primary">Add user</button>
                </div>
            </div>
        </form>
    </div>
</div>
</body>
</html>
