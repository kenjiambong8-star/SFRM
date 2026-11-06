<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$pdo = db();
$id  = (int)str_param($_GET, 'id');

$stmt = $pdo->prepare(
    'SELECT t.*, s.student_no, s.first_name, s.last_name, s.program, s.year_level,
            c.name AS category, u.full_name AS cashier
     FROM transactions t
     JOIN students s       ON s.id = t.student_id
     JOIN fee_categories c ON c.id = t.category_id
     JOIN users u          ON u.id = t.created_by
     WHERE t.id = ?'
);
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) {
    flash_set('error', 'That transaction was not found.');
    redirect('transactions.php');
}

$pageTitle  = 'Receipt ' . $t['receipt_no'];
$activePage = 'transactions';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head no-print">
  <h1>Payment details</h1>
  <a href="transactions.php" class="back-link">Back to transactions</a>
</div>

<article class="receipt-sheet card">
  <?php if ($t['status'] === 'voided'): ?><div class="void-mark" aria-hidden="true">VOID</div><?php endif; ?>

  <header class="receipt-head">
    <div>
      <p class="muted">Official receipt</p>
      <h2><?= e($t['receipt_no']) ?></h2>
    </div>
    <div class="receipt-status"><?= status_badge($t['status']) ?></div>
  </header>

  <dl class="receipt-list">
    <div><dt>Date</dt><dd><?= e(date('F j, Y', strtotime($t['transaction_date']))) ?></dd></div>
    <div><dt>Student</dt><dd><?= e($t['last_name'] . ', ' . $t['first_name']) ?></dd></div>
    <div><dt>Student no.</dt><dd><?= e($t['student_no']) ?></dd></div>
    <div><dt>Program</dt><dd><?= e($t['program']) ?>, year <?= (int)$t['year_level'] ?></dd></div>
    <div><dt>Fee category</dt><dd><?= e($t['category']) ?></dd></div>
    <div><dt>Payment method</dt><dd><?= e(method_label($t['payment_method'])) ?></dd></div>
    <?php if ($t['remarks']): ?><div><dt>Remarks</dt><dd><?= e($t['remarks']) ?></dd></div><?php endif; ?>
    <div><dt>Received by</dt><dd><?= e($t['cashier']) ?></dd></div>
    <div><dt>Recorded on</dt><dd><?= e(date('M j, Y g:i A', strtotime($t['created_at']))) ?></dd></div>
  </dl>

  <div class="receipt-amount">
    <span>Amount</span>
    <strong><?= peso($t['amount']) ?></strong>
  </div>
</article>

<div class="form-actions no-print">
  <button class="btn" type="button" onclick="window.print()">Print receipt</button>
  <?php if ($t['status'] !== 'voided'): ?>
    <a class="btn-outline" href="transaction_form.php?id=<?= (int)$t['id'] ?>">Edit</a>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
