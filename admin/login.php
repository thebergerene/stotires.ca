<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $stmt = get_db()->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // Always run password_verify even on a missing user, against a dummy hash,
    // so a login attempt takes roughly the same time either way and doesn't
    // leak which usernames exist via a timing difference.
    $hashToCheck = $user['password_hash'] ?? '$2y$10$invalidinvalidinvaliduinvalidinvalidinvalidinvalidu';
    $passwordOk  = password_verify($password, $hashToCheck);

    if ($user && $passwordOk && (int)$user['is_active'] === 1) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int)$user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];
        header('Location: dashboard.php');
        exit;
    }

    $error = ($user && !$user['is_active'])
        ? 'This account has been deactivated. Contact an admin.'
        : 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin login — <?= htmlspecialchars(SITE_NAME) ?></title>
<link rel="stylesheet" href="../css/style.css">
</head>
<body class="admin-body">
<div class="admin-login">
    <h2 style="margin-bottom:20px;">Admin login</h2>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <div style="margin-bottom:16px;">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus>
        </div>
        <div style="margin-bottom:20px;">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">Log in</button>
    </form>
</div>
</body>
</html>
