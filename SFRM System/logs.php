<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_role('admin');

$pdo = db();

const LOG_ACTIONS = ['LOGIN', 'LOGOUT', 'LOGIN_FAILED', 'CREATE', 'UPDATE', 'DELETE', 'BACKUP', 'RESTORE', 'REPORT', 'OTHER'];

/* ---------- filters ---------- */
$from   = valid_date(str_param($_GET, 'date_from')) ?? '';
$to     = valid_date(str_param($_GET, 'date_to')) ?? '';
$action = in_array(str_param($_GET, 'action'), LOG_ACTIONS, true) ? str_param($_GET, 'action') : '';
$uname  = mb_substr(trim(str_param($_GET, 'username')), 0, 50);
$q      = mb_substr(trim(str_param($_GET, 'q')), 0, 60);
$page   = max(1, (int)str_param($_GET, 'page'));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];
if ($from !== '')   { $where[] = 'l.created_at >= ?';                         $params[] = $from . ' 00:00:00'; }
if ($to !== '')     { $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }
if ($action !== '') { $where[] = 'l.action = ?';                               $params[] = $action; }
if ($uname !== '')  { $where[] = 'l.username = ?';                             $params[] = $uname; }
if ($q !== '')      { $where[] = 'l.details LIKE ?';                           $params[] = '%' . $q . '%'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs l $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare(
    "SELECT l.id, l.username, l.action, l.details, l.ip_address, l.created_at
     FROM activity_logs l $whereSql
     ORDER BY l.id DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$logs = $listStmt->fetchAll();

$usernames = $pdo->query('SELECT DISTINCT username FROM activity_logs ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);

$query = ['date_from' => $from, 'date_to' => $to, 'action' => $action, 'username' => $uname, 'q' => $q];

$pageTitle  = 'Activity Log';
$activePage = 'logs';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Activity Log</h1>
  <p>Every login, change, backup, and report is recorded here.</p>
</div>

<form class="filters card" method="get" action="logs.php">
  <div class="field">
    <label for="date_from">From</label>
    <input id="date_from" name="date_from" type="date" value="<?= e($from) ?>">
  </div>
  <div class="field">
    <label for="date_to">To</label>
    <input id="date_to" name="date_to" type="date" value="<?= e($to) ?>">
  </div>
  <div class="field">
    <label for="username">User</label>
    <select id="username" name="username">
      <option value="">All users</option>
      <?php foreach ($usernames as $n): ?>
        <option value="<?= e($n) ?>" <?= $uname === $n ? 'selected' : '' ?>><?= e($n) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field">
    <label for="action">Activity</label>
    <select id="action" name="action">
      <option value="">All activity</option>
      <?php foreach (LOG_ACTIONS as $a): ?>
        <option value="<?= e($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= e(ucfirst(strtolower(str_replace('_', ' ', $a)))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field grow">
    <label for="q">Search details</label>
    <input id="q" name="q" type="search" maxlength="60" value="<?= e($q) ?>" placeholder="Receipt no., student no.">
  </div>
  <div class="filter-actions">
    <button class="btn" type="submit">Apply filters</button>
    <a class="btn-outline" href="logs.php">Reset</a>
  </div>
</form>

<section class="card">
  <p class="result-count"><?= number_format($total) ?> log entr<?= $total === 1 ? 'y' : 'ies' ?></p>

  <?php if ($logs): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Time</th><th>User</th><th>Activity</th><th>Details</th><th>IP address</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): $ts = strtotime($l['created_at']); ?>
          <tr>
            <td><?= e(date('M j, Y', $ts)) ?></td>
            <td><?= e(date('g:i:s A', $ts)) ?></td>
            <td><?= e($l['username']) ?></td>
            <td><span class="badge act-<?= e(strtolower($l['action'])) ?>"><?= e(ucfirst(strtolower(str_replace('_', ' ', $l['action'])))) ?></span></td>
            <td><?= e($l['details']) ?></td>
            <td><?= e($l['ip_address'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pager($total, $perPage, $page, 'logs.php', $query) ?>
  <?php else: ?>
    <p class="empty">No log entries match these filters.</p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
