<?php
declare(strict_types=1);

/**
 * Transaction filters shared by the Transactions list and the Reports page,
 * so both always filter the same way (date, category, status, method, search).
 */

const TX_METHODS  = ['cash', 'gcash', 'bank_transfer', 'check'];
const TX_STATUSES = ['paid', 'pending', 'voided'];

const TX_FROM = " FROM transactions t
    JOIN students s       ON s.id = t.student_id
    JOIN fee_categories c ON c.id = t.category_id
    JOIN users u          ON u.id = t.created_by ";

const TX_COLUMNS = "t.id, t.receipt_no, t.transaction_date, t.amount, t.payment_method, t.status, t.remarks,
    s.student_no, CONCAT(s.last_name, ', ', s.first_name) AS student,
    c.name AS category, u.full_name AS cashier";

/**
 * Returns [clean filter values, "WHERE ..." sql, bound parameters].
 */
function transaction_filters(array $src): array
{
    $status = str_param($src, 'status');
    $method = str_param($src, 'method');
    $catRaw = str_param($src, 'category_id');

    $f = [
        'date_from'   => valid_date(str_param($src, 'date_from')) ?? '',
        'date_to'     => valid_date(str_param($src, 'date_to')) ?? '',
        'category_id' => ctype_digit($catRaw) ? (int)$catRaw : 0,
        'status'      => in_array($status, TX_STATUSES, true) ? $status : '',
        'method'      => in_array($method, TX_METHODS, true) ? $method : '',
        'q'           => mb_substr(trim(str_param($src, 'q')), 0, 60),
    ];

    $where  = [];
    $params = [];

    if ($f['date_from'] !== '') { $where[] = 't.transaction_date >= ?'; $params[] = $f['date_from']; }
    if ($f['date_to'] !== '')   { $where[] = 't.transaction_date <= ?'; $params[] = $f['date_to']; }
    if ($f['category_id'] > 0)  { $where[] = 't.category_id = ?';       $params[] = $f['category_id']; }
    if ($f['status'] !== '')    { $where[] = 't.status = ?';            $params[] = $f['status']; }
    if ($f['method'] !== '')    { $where[] = 't.payment_method = ?';    $params[] = $f['method']; }
    if ($f['q'] !== '') {
        $like    = '%' . $f['q'] . '%';
        $where[] = '(t.receipt_no LIKE ? OR s.student_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }

    return [$f, $where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

/** The filter form (date range, category, status, method, search). */
function tx_filter_form(array $f, array $categories, string $action): string
{
    ob_start();
    ?>
    <form class="filters card" method="get" action="<?= e($action) ?>">
      <div class="field">
        <label for="date_from">From</label>
        <input id="date_from" name="date_from" type="date" value="<?= e($f['date_from']) ?>">
      </div>
      <div class="field">
        <label for="date_to">To</label>
        <input id="date_to" name="date_to" type="date" value="<?= e($f['date_to']) ?>">
      </div>
      <div class="field">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id">
          <option value="">All</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $f['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="status">Status</label>
        <select id="status" name="status">
          <option value="">All</option>
          <?php foreach (TX_STATUSES as $s): ?>
            <option value="<?= e($s) ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="method">Payment method</label>
        <select id="method" name="method">
          <option value="">All</option>
          <?php foreach (TX_METHODS as $m): ?>
            <option value="<?= e($m) ?>" <?= $f['method'] === $m ? 'selected' : '' ?>><?= e(method_label($m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field grow">
        <label for="q">Search</label>
        <input id="q" name="q" type="search" maxlength="60" value="<?= e($f['q']) ?>" placeholder="Receipt no., student no., or name">
      </div>
      <div class="filter-actions">
        <button class="btn" type="submit">Apply filters</button>
        <a class="btn-outline" href="<?= e($action) ?>">Reset</a>
      </div>
    </form>
    <?php
    return (string)ob_get_clean();
}
