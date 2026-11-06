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

/** Next receipt number for this year, e.g. SFR-2026-00001 */
function next_receipt_no(PDO $pdo): string
{
    $prefix = 'SFR-' . date('Y') . '-';
    $start  = strlen($prefix) + 1;
    $stmt   = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(receipt_no, $start) AS UNSIGNED)), 0)
         FROM transactions WHERE receipt_no LIKE ?"
    );
    $stmt->execute([$prefix . '%']);
    return $prefix . str_pad((string)((int)$stmt->fetchColumn() + 1), 5, '0', STR_PAD_LEFT);
}

$id      = (int)str_param($_GET, 'id');
$editing = $id > 0;
$old     = null;

$tx = [
    'student_id' => '', 'category_id' => '', 'amount' => '', 'payment_method' => 'cash',
    'transaction_date' => date('Y-m-d'), 'status' => 'paid', 'remarks' => '',
];

if ($editing) {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
    $stmt->execute([$id]);
    $old = $stmt->fetch();
    if (!$old) {
        flash_set('error', 'That transaction was not found.');
        redirect('transactions.php');
    }
    if ($old['status'] === 'voided') {
        flash_set('error', 'Voided payments cannot be edited.');
        redirect('transaction_view.php?id=' . $id);
    }
    $tx = array_merge($tx, array_map(static fn($v) => (string)($v ?? ''), $old));
}

/* dropdown data: enrolled students (plus the current one when editing), active categories */
$studentStmt = $pdo->prepare(
    "SELECT id, student_no, last_name, first_name FROM students
     WHERE status = 'enrolled' OR id = ? ORDER BY last_name, first_name"
);
$studentStmt->execute([(int)($old['student_id'] ?? 0)]);
$students = $studentStmt->fetchAll();

$catStmt = $pdo->prepare('SELECT id, name, default_amount FROM fee_categories WHERE is_active = 1 OR id = ? ORDER BY name');
$catStmt->execute([(int)($old['category_id'] ?? 0)]);
$categories = $catStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errors['form'] = 'Your page expired. Please try again.';
    } else {
        $in = [
            'student_id'       => trim(str_param($_POST, 'student_id')),
            'category_id'      => trim(str_param($_POST, 'category_id')),
            'amount'           => str_replace(',', '', trim(str_param($_POST, 'amount'))),
            'payment_method'   => trim(str_param($_POST, 'payment_method')),
            'transaction_date' => trim(str_param($_POST, 'transaction_date')),
            'status'           => trim(str_param($_POST, 'status')),
            'remarks'          => trim(str_param($_POST, 'remarks')),
        ];
        $tx = array_merge($tx, $in);

        if (!in_array((int)$in['student_id'], array_map('intval', array_column($students, 'id')), true)) {
            $errors['student_id'] = 'Choose a student.';
        }
        if (!in_array((int)$in['category_id'], array_map('intval', array_column($categories, 'id')), true)) {
            $errors['category_id'] = 'Choose a fee category.';
        }
        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $in['amount']) || (float)$in['amount'] <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero (up to 2 decimal places).';
        }
        if (!in_array($in['payment_method'], TX_METHODS, true)) {
            $errors['payment_method'] = 'Choose a payment method.';
        }
        $date = valid_date($in['transaction_date']);
        if ($date === null) {
            $errors['transaction_date'] = 'Enter a valid date.';
        } elseif ($date > date('Y-m-d')) {
            $errors['transaction_date'] = 'The date cannot be in the future.';
        }
        if (!in_array($in['status'], ['paid', 'pending'], true)) {
            $errors['status'] = 'Choose paid or pending.';
        }
        if (mb_strlen($in['remarks']) > 255) {
            $errors['remarks'] = 'Remarks must be 255 characters or less.';
        }

        if (!$errors) {
            $amount = number_format((float)$in['amount'], 2, '.', '');
            $remark = $in['remarks'] !== '' ? $in['remarks'] : null;

            if ($editing) {
                $pdo->prepare(
                    'UPDATE transactions SET student_id = ?, category_id = ?, amount = ?, payment_method = ?,
                            transaction_date = ?, status = ?, remarks = ? WHERE id = ?'
                )->execute([(int)$in['student_id'], (int)$in['category_id'], $amount, $in['payment_method'],
                            $date, $in['status'], $remark, $id]);

                $changes = [];
                if ((float)$old['amount'] !== (float)$amount) {
                    $changes[] = 'amount ' . number_format((float)$old['amount'], 2) . ' to ' . number_format((float)$amount, 2);
                }
                if ($old['status'] !== $in['status']) {
                    $changes[] = 'status ' . $old['status'] . ' to ' . $in['status'];
                }
                log_activity((int)$user['id'], $user['username'], 'UPDATE',
                    'Updated payment ' . $old['receipt_no'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
                flash_set('success', 'Payment updated.');
                redirect('transaction_view.php?id=' . $id);
            }

            // new payment: generate the receipt number, retry if two cashiers collide
            for ($try = 0; $try < 3; $try++) {
                $receipt = next_receipt_no($pdo);
                try {
                    $pdo->prepare(
                        'INSERT INTO transactions (receipt_no, student_id, category_id, amount, payment_method,
                                transaction_date, status, remarks, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$receipt, (int)$in['student_id'], (int)$in['category_id'], $amount,
                                $in['payment_method'], $date, $in['status'], $remark, (int)$user['id']]);
                    $newId = (int)$pdo->lastInsertId();
                    break;
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000' || $try === 2) {
                        throw $e;
                    }
                }
            }
            log_activity((int)$user['id'], $user['username'], 'CREATE',
                'Recorded payment ' . $receipt . ' (' . number_format((float)$amount, 2) . ')');
            flash_set('success', 'Payment recorded. Receipt ' . $receipt . '.');
            redirect('transaction_view.php?id=' . $newId);
        }
    }
}

$pageTitle  = $editing ? 'Edit payment' : 'Record payment';
$activePage = 'transactions';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1><?= e($pageTitle) ?><?= $editing ? ' ' . e($old['receipt_no']) : '' ?></h1>
  <a href="transactions.php" class="back-link">Back to transactions</a>
</div>

<form class="card form-card" method="post" action="transaction_form.php<?= $editing ? '?id=' . $id : '' ?>" novalidate>
  <?= csrf_field() ?>
  <?php if (isset($errors['form'])): ?><div class="flash flash-error"><?= e($errors['form']) ?></div><?php endif; ?>

  <div class="form-grid">
    <div class="field full">
      <label for="student_id">Student</label>
      <select id="student_id" name="student_id" required>
        <option value="">Choose a student</option>
        <?php foreach ($students as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $tx['student_id'] ? 'selected' : '' ?>>
            <?= e($s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['student_no'] . ')') ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!$students): ?><p class="hint">No enrolled students yet. <a href="student_form.php">Add a student first.</a></p><?php endif; ?>
      <?php if (isset($errors['student_id'])): ?><p class="field-error"><?= e($errors['student_id']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="category_id">Fee category</label>
      <select id="category_id" name="category_id" required>
        <option value="" data-default="0">Choose a category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int)$c['id'] ?>" data-default="<?= e((string)$c['default_amount']) ?>"
                  <?= (string)$c['id'] === $tx['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['category_id'])): ?><p class="field-error"><?= e($errors['category_id']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="amount">Amount (&#8369;)</label>
      <input id="amount" name="amount" type="text" inputmode="decimal" required value="<?= e($tx['amount']) ?>">
      <?php if (isset($errors['amount'])): ?><p class="field-error"><?= e($errors['amount']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="payment_method">Payment method</label>
      <select id="payment_method" name="payment_method">
        <?php foreach (TX_METHODS as $m): ?>
          <option value="<?= e($m) ?>" <?= $tx['payment_method'] === $m ? 'selected' : '' ?>><?= e(method_label($m)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['payment_method'])): ?><p class="field-error"><?= e($errors['payment_method']) ?></p><?php endif; ?>
    </div>
    <div class="field">
      <label for="transaction_date">Date</label>
      <input id="transaction_date" name="transaction_date" type="date" max="<?= e(date('Y-m-d')) ?>" required value="<?= e($tx['transaction_date']) ?>">
      <?php if (isset($errors['transaction_date'])): ?><p class="field-error"><?= e($errors['transaction_date']) ?></p><?php endif; ?>
    </div>

    <div class="field">
      <label for="status">Status</label>
      <select id="status" name="status">
        <?php foreach (['paid', 'pending'] as $st): ?>
          <option value="<?= e($st) ?>" <?= $tx['status'] === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['status'])): ?><p class="field-error"><?= e($errors['status']) ?></p><?php endif; ?>
    </div>
    <div class="field full">
      <label for="remarks">Remarks (optional)</label>
      <input id="remarks" name="remarks" type="text" maxlength="255" value="<?= e($tx['remarks']) ?>">
      <?php if (isset($errors['remarks'])): ?><p class="field-error"><?= e($errors['remarks']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-yellow" type="submit"><?= $editing ? 'Save changes' : 'Record payment' ?></button>
    <a class="btn-outline" href="transactions.php">Cancel</a>
  </div>
</form>

<script>
  // Suggest the category's usual amount when the amount box is empty or still holds the previous suggestion.
  (function () {
    var cat = document.getElementById('category_id');
    var amt = document.getElementById('amount');
    var lastDefault = cat.selectedOptions[0] ? Number(cat.selectedOptions[0].dataset.default) : 0;
    cat.addEventListener('change', function () {
      var d = Number(cat.selectedOptions[0].dataset.default || 0);
      var cur = Number(amt.value.replace(/,/g, '') || 0);
      if (d > 0 && (cur === 0 || cur === lastDefault)) { amt.value = d.toFixed(2); }
      lastDefault = d;
    });
  })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
