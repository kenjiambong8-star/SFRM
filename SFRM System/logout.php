<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/audit.php';

// Logout is POST-only with a CSRF token, so another site cannot log users out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? null)) {
    redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
}

if (is_logged_in()) {
    $u = current_user();
    log_activity((int)$u['id'], $u['username'], 'LOGOUT', 'User logged out');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

redirect('login.php');
