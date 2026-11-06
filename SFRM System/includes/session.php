<?php
declare(strict_types=1);

/**
 * Secure session start + small security helpers used by every page.
 * Include this at the very top of each page, before any output.
 */

const SESSION_IDLE_SECONDS = 1800;   // auto logout after 30 minutes of inactivity

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

// Basic security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

// Never show PHP errors to visitors on a live server (they can leak paths and queries).
if (!in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true)) {
    ini_set('display_errors', '0');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('SFRMSSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => is_https(),   // true automatically once you have SSL
        'httponly' => true,         // JavaScript cannot read the cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_SECONDS) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

/* ---------- helpers ---------- */

/** Escape output (prevents XSS). Use on every value printed into HTML. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function client_ip(): string
{
    // REMOTE_ADDR cannot be faked by the visitor, unlike X-Forwarded-For.
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

/* ---------- CSRF protection ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/* ---------- access control ---------- */

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/** Example: require_role('admin'); */
function require_role(string ...$roles): void
{
    require_login();
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to open this page.');
    }
}
