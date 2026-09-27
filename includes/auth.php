<?php
/**
 * Include this after config.php and includes/db.php on any /admin page.
 * Call require_login() for pages any logged-in user (admin or logistics) can
 * see, or require_admin() for pages only the admin role can see.
 */

/** Returns the current session user's info, or redirects to login.php if not logged in. */
function require_login(): array
{
    if (empty($_SESSION['user_id'])) {
      //   header('Location: login.php');
        exit;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'username'  => (string)($_SESSION['username'] ?? ''),
        'full_name' => (string)($_SESSION['full_name'] ?? ''),
        'role'      => (string)($_SESSION['role'] ?? 'logistics'),
    ];
}

/** Same as require_login(), but also blocks anyone whose role isn't 'admin'. */
function require_admin(): array
{
    $user = require_login();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('This page is only available to admin accounts. <a href="dashboard.php">Back to bookings</a>');
    }
    return $user;
}
