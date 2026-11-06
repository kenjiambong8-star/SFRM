<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';

const MAX_ATTEMPTS = 5;     // failed tries per username + IP
const MAX_IP_ATTEMPTS = 20; // failed tries from one IP (any username)
const LOCK_MINUTES = 15;

if (is_logged_in()) {
    redirect('dashboard.php');
}

/* ---------- arithmetic CAPTCHA ---------- */

function new_captcha(): string
{
    $a  = random_int(1, 12);
    $b  = random_int(1, 12);
    $op = random_int(0, 1) ? '+' : '-';
    if ($op === '-' && $b > $a) {
        [$a, $b] = [$b, $a];       // keep the answer positive
    }
    $_SESSION['captcha_answer'] = $op === '+' ? $a + $b : $a - $b;
    return "$a $op $b";
}

function captcha_ok(string $input): bool
{
    $expected = $_SESSION['captcha_answer'] ?? null;
    unset($_SESSION['captcha_answer']);          // each question works only once
    $input = trim($input);
    return $expected !== null
        && preg_match('/^-?\d{1,3}$/', $input) === 1
        && (int)$input === (int)$expected;
}

/* ---------- attempt limiting ---------- */

function failed_attempts(string $username, string $ip): array
{
    $sql = 'SELECT
              SUM(username = ? AND ip_address = ?) AS by_user,
              COUNT(*)                             AS by_ip
            FROM login_attempts
            WHERE was_success = 0
              AND ip_address = ?
              AND attempted_at > (NOW() - INTERVAL ' . LOCK_MINUTES . ' MINUTE)';
    $stmt = db()->prepare($sql);
    $stmt->execute([$username, $ip, $ip]);
    $row = $stmt->fetch();
    return [(int)($row['by_user'] ?? 0), (int)($row['by_ip'] ?? 0)];
}

function record_attempt(string $username, string $ip, bool $success): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (username, ip_address, was_success) VALUES (?, ?, ?)');
    $stmt->execute([$username, $ip, $success ? 1 : 0]);
}

/* ---------- handle the form ---------- */

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 50);
    $password = (string)($_POST['password'] ?? '');
    $ip       = client_ip();

    [$byUser, $byIp] = failed_attempts($username, $ip);

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your page expired. Please try again.';
    } elseif ($byUser >= MAX_ATTEMPTS || $byIp >= MAX_IP_ATTEMPTS) {
        $error = 'Too many failed attempts. Please wait ' . LOCK_MINUTES . ' minutes and try again.';
    } elseif (!captcha_ok((string)($_POST['captcha'] ?? ''))) {
        $error = 'The answer to the math question was not correct. Please try the new question.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, full_name, username, password_hash, role, status
             FROM users WHERE username = ? LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // Always run password_verify, even for unknown usernames, so the
        // response time does not reveal which usernames exist.
        $hash  = $user['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        $valid = strlen($password) <= 255
              && password_verify($password, $hash)
              && $user
              && $user['status'] === 'active';

        if ($valid) {
            session_regenerate_id(true);             // stops session fixation
            $_SESSION['user'] = [
                'id'        => (int)$user['id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'],
                'role'      => $user['role'],
            ];
            $_SESSION['last_activity'] = time();

            // upgrade the hash if PHP's default algorithm/cost has improved
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }

            db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);
            db()->prepare('DELETE FROM login_attempts WHERE username = ? AND ip_address = ? AND was_success = 0')
                ->execute([$username, $ip]);
            record_attempt($username, $ip, true);
            log_activity((int)$user['id'], $user['username'], 'LOGIN', 'User logged in');

            redirect('dashboard.php');
        }

        // failed
        record_attempt($username, $ip, false);
        log_activity($user ? (int)$user['id'] : null, $username !== '' ? $username : '(blank)',
                     'LOGIN_FAILED', 'Failed login attempt');

        $left  = max(0, MAX_ATTEMPTS - ($byUser + 1));
        $error = 'Wrong username or password.'
               . ($left > 0 ? " You have $left more " . ($left === 1 ? 'try' : 'tries') . '.'
                            : ' Too many failed attempts. Please wait ' . LOCK_MINUTES . ' minutes.');
    }
}

$captchaQuestion = new_captcha();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sign in | Student Financial Records</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>
  <main class="auth">
    <a class="back" href="index.php">Back to home</a>

    <form class="auth-card" method="post" action="login.php" autocomplete="off" novalidate>
      <h1>Sign in</h1>
      <p class="sub">Use your staff account to open the system.</p>

      <div class="alert" role="alert" aria-live="assertive" <?= $error === '' ? 'hidden' : '' ?>>
        <?= e($error) ?>
      </div>

      <?= csrf_field() ?>

      <label for="username">Username</label>
      <input id="username" name="username" type="text" maxlength="50" required
             autocomplete="username" value="<?= e($username) ?>" autofocus>

      <label for="password">Password</label>
      <input id="password" name="password" type="password" maxlength="255" required
             autocomplete="current-password">

      <label for="captcha">Security question: what is <strong><?= e($captchaQuestion) ?></strong>?</label>
      <input id="captcha" name="captcha" type="text" inputmode="numeric" maxlength="3" required>

      <button type="submit" class="btn">Sign in</button>
    </form>
  </main>
</body>
</html>
