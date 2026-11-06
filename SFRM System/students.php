<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_login();

$pdo  = db();
$user = current_user();

/* ---------- DELETE (POST only, admin only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Your page expired. Please try again.');
        redirect('students.php');
    }

    if (str_param($_POST, 'action') === 'delete') {
        if ($user['role'] !== 'admin') {
            flash_set('error', 'Only an administrator can delete students.');
            redirect('students.php');
        }

        $id   = (int)str_param($_POST, 'id');
        $stmt = $pdo->prepare('SELECT student_no, first_name, last_name FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $s = $stmt->fetch();

        if (!$s) {
            flash_set('error', 'That student no longer exists.');
        } else {
            $count = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE student_id = ?');
            $count->execute([$id]);
            if ((int)$count->fetchColumn() > 0) {
                flash_set('error', 'This student has payment records and cannot be deleted. Change the status to Dropped or Graduated instead.');
            } else {
                $pdo->prepare('DELETE FROM students WHERE id = ?')->execute([$id]);
                log_activity((int)$user['id'], $user['username'], 'DELETE',
                    'Deleted student ' . $s['student_no'] . ' - ' . $s['last_name'] . ', ' . $s['first_name']);
                flash_set('success', 'Student deleted.');
            }
        }
    }
    redirect('students.php');
}

/* ---------- LIST + SEARCH ---------- */
$q       = mb_substr(trim(str_param($_GET, 'q')), 0, 60);
$status  = in_array(str_param($_GET, 'status'), ['enrolled', 'graduated', 'dropped'], true) ? str_param($_GET, 'status') : '';
$page    = max(1, (int)str_param($_GET, 'page'));
$perPage = 10;

$where  = [];
$params = [];
if ($q !== '') {
    $like    = '%' . $q . '%';
    $where[] = "(s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.program LIKE ?
                 OR CONCAT_WS(' ', s.first_name, s.last_name) LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status !== '') {
    $where[]  = 's.status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s $whereSql");
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT s.*, (SELECT COUNT(*) FROM transactions t WHERE t.student_id = s.id) AS tx_count
     FROM students s $whereSql
     ORDER BY s.last_name, s.first_name
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$students = $listStmt->fetchAll();

$pageTitle  = 'Students';
$activePage = 'students';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Students</h1>
  <a class="btn btn-yellow" href="student_form.php">Add student</a>
</div>

<form class="filters card" method="get" action="students.php">
  <div class="field grow">
    <label for="q">Search</label>
    <input id="q" name="q" type="search" maxlength="60" value="<?= e($q) ?>" placeholder="Student no., name, or program">
  </div>
  <div class="field">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <?php foreach (['enrolled', 'graduated', 'dropped'] as $st): ?>
        <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-actions">
    <button class="btn" type="submit">Search</button>
    <a class="btn-outline" href="students.php">Reset</a>
  </div>
</form>

<section class="card">
  <p class="result-count"><?= number_format($total) ?> student<?= $total === 1 ? '' : 's' ?> found</p>

  <?php if ($students): ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Student no.</th><th>Name</th><th>Program</th><th>Year</th><th>Status</th><th class="num">Payments</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
          <tr>
            <td><?= e($s['student_no']) ?></td>
            <td><?= e($s['last_name'] . ', ' . $s['first_name']) ?></td>
            <td><?= e($s['program']) ?></td>
            <td><?= (int)$s['year_level'] ?></td>
            <td><span class="badge badge-<?= e($s['status']) ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td class="num"><?= (int)$s['tx_count'] ?></td>
            <td class="row-actions">
              <a href="student_form.php?id=<?= (int)$s['id'] ?>">Edit</a>
              <?php if ($user['role'] === 'admin' && (int)$s['tx_count'] === 0): ?>
                <form method="post" action="students.php" class="inline"
                      onsubmit="return confirm('Delete this student record? This cannot be undone.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                  <button type="submit" class="link-danger">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pager($total, $perPage, $page, 'students.php', ['q' => $q, 'status' => $status]) ?>
  <?php else: ?>
    <p class="empty">No students match your search.</p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
