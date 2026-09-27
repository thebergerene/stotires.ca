<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$currentUser = require_login();
$db = get_db();
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword     = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    try {
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$currentUser['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            throw new InvalidArgumentException('Your current password is incorrect.');
        }
        if (mb_strlen($newPassword) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('New password and confirmation don\'t match.');
        }

        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $currentUser['id']]);

        $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Password updated.'];
    } catch (InvalidArgumentException $e) {
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('Password change error: ' . $e->getMessage());
        $_SESSION['admin_flash'] = ['type' => 'error', 'message' => 'Could not change your password: ' . $e->getMessage()];
    }
    header('Location: account.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>My account — <?= h(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="admin-body">
<div class="admin-shell" style="max-width:520px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px;">
        <h2>My account</h2>
        <a href="dashboard.php" class="btn btn-outline" style="margin-left:0; border-color:var(--ink); color:var(--ink);">Back to bookings</a>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <div class="summary-card">
        <div class="summary-row"><span>Username</span><strong><?= h($currentUser['username']) ?></strong></div>
        <div class="summary-row"><span>Full name</span><strong><?= h($currentUser['full_name']) ?></strong></div>
        <div class="summary-row"><span>Role</span><strong><?= h(ucfirst($currentUser['role'])) ?></strong></div>
    </div>

    <h3 style="font-size:1rem; margin:24px 0 12px;">Change password</h3>
    <form method="post">
        <div style="margin-bottom:14px;">
            <label for="current_password">Current password</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div style="margin-bottom:14px;">
            <label for="new_password">New password</label>
            <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
        </div>
        <div style="margin-bottom:20px;">
            <label for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
        </div>
        <button type="submit" name="change_password" value="1" class="btn btn-primary">Update password</button>
    </form>
</div>
</body>
</html>
