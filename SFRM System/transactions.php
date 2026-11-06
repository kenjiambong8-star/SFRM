<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/filters.php';
require_once __DIR__ . '/includes/audit.php';
require_login();

$pdo  = db();
$user = current_user();

/* ---------- VOID / DELETE (POST only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Your page expired. Please try again.');
        redirect('transactions.php');
    }

    $action = str_param($_POST, 'action');
    $id     = (int)str_param($_POST, 'id');
    $stmt   = $pdo->prepare('SELECT id, receipt_no, amount, status FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    $tx = $stmt->fetch();

    if (!$tx) {
        flash_set('error', 'That transaction was not found.');
    } elseif ($action === 'void') {
        if ($tx['status'] === 'voided') {
            flash_set('error', 'This payment is already voided.');
        } else {
            $pdo->prepare("UPDATE transactions SET status = 'voided' WHERE id = ?")->execute([$id]);
            log_activity((int)$user['id'], $user['username'], 'UPDATE',
                'Voided payment ' . $tx['receipt_no'] . ' (' . number_format((float)$tx['amount'], 2) . ')');
            flash_set('success', 'Payment ' . $tx['receipt_no'] . ' was voided.');
        }
    } elseif ($action === 'delete') {
        if ($user['role'] !== 'admin') {
            flash_set('error', 'Only an administrator can delete transactions.');
        } elseif ($tx['status'] !== 'voided') {
            flash_set('error', 'Only voided payments can be deleted. Void the payment first.');
        } else {
            $pdo->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
            log_activity((int)$user['id'], $user['username'], 'DELETE',
                'Deleted voided payment ' . $tx['receipt_no'] . ' (' . number_format((float)$tx['amount'], 2) . ')');
            flash_set('success', 'Payment ' . $tx['receipt_no'] . ' was deleted.');
        }
    }
    redirect('transactions.php');
}

/* ---------- LIST + FILTERS ---------- */
[$f, $whereSql, $params] = transaction_filters($_GET);

$page    = max(1, (int)str_param($_GET, 'page'));
$perPage = 10;
$offset  = ($page - 1) * $perPage;

$sumStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt,
            COALESCE(SUM(CASE WHEN t.status = 'paid' THEN t.amount END), 0) AS paid_total"
    . TX_FROM . $whereSql
);
$sumStmt->execute($params);
$sum = $sumStmt->fetch();
$total = (int)$sum['cnt'];

$listStmt = $pdo->prepare(
    'SELECT ' . TX_COLUMNS . TX_FROM . $whereSql
    . " ORDER BY t.transaction_date DESC, t.id DESC LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

$categories = $pdo->query('SELECT id, name FROM fee_categories ORDER BY name')->fetchAll();

$pageTitle  = 'Transactions';
$activePage = 'transactions';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Transactions</h1>
  <a class="btn btn-yellow" href="transaction_form.php">Record payment</a>
</div>

<?= tx_filter_form($f, $categories, 'transactions.php') ?>

<section class="card">
  <p class="result-count">
    <?= number_format($total) ?> transaction<?= $total === 1 ? '' : 's' ?> found
    &middot; Paid total: <strong><?= peso($sum['paid_total']) ?></strong>
  </p>

  <?php if ($rows): ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th>Receipt no.</th><th>Student</th><th>Category</th><th>Method</th>
              <th class="num">Amount</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
            <td><?= e($r['receipt_no']) ?></td>
            <td><?= e($r['student']) ?><br><small class="muted"><?= e($r['student_no']) ?></small></td>
            <td><?= e($r['category']) ?></td>
            <td><?= e(method_label($r['payment_method'])) ?></td>
            <td class="num"><?= peso($r['amount']) ?></td>
            <td><?= status_badge($r['status']) ?></td>
            <td class="row-actions">
              <a href="transaction_view.php?id=<?= (int)$r['id'] ?>">View</a>
              <?php if ($r['status'] !== 'voided'): ?>
                <a href="transaction_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" action="transactions.php" class="inline"
                      onsubmit="return confirm('Void this payment? It will stay on record but will no longer count as collected.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="void">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="link-danger">Void</button>
                </form>
              <?php elseif ($user['role'] === 'admin'): ?>
                <form method="post" action="transactions.php" class="inline"
                      onsubmit="return confirm('Permanently delete this voided payment? This cannot be undone.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="link-danger">Delete</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= pager($total, $perPage, $page, 'transactions.php', $f) ?>
  <?php else: ?>
    <p class="empty">No transactions match these filters.</p>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
