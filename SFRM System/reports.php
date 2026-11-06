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

const REPORT_MAX_ROWS = 5000;

[$f, $whereSql, $params] = transaction_filters($_GET);
$categories = $pdo->query('SELECT id, name FROM fee_categories ORDER BY name')->fetchAll();

$stmt = $pdo->prepare(
    'SELECT ' . TX_COLUMNS . TX_FROM . $whereSql
    . ' ORDER BY t.transaction_date DESC, t.id DESC LIMIT ' . (REPORT_MAX_ROWS + 1)
);
$stmt->execute($params);
$rows      = $stmt->fetchAll();
$truncated = count($rows) > REPORT_MAX_ROWS;
$rows      = array_slice($rows, 0, REPORT_MAX_ROWS);

/* ---------- totals ---------- */
$totals = ['paid' => 0.0, 'pending' => 0.0, 'voided' => 0.0];
$counts = ['paid' => 0,   'pending' => 0,   'voided' => 0];
$byCategory = [];
$byMethod   = [];
foreach ($rows as $r) {
    $st = $r['status'];
    $totals[$st] += (float)$r['amount'];
    $counts[$st]++;
    if ($st === 'paid') {
        $byCategory[$r['category']]['count'] = ($byCategory[$r['category']]['count'] ?? 0) + 1;
        $byCategory[$r['category']]['total'] = ($byCategory[$r['category']]['total'] ?? 0) + (float)$r['amount'];
        $m = method_label($r['payment_method']);
        $byMethod[$m]['count'] = ($byMethod[$m]['count'] ?? 0) + 1;
        $byMethod[$m]['total'] = ($byMethod[$m]['total'] ?? 0) + (float)$r['amount'];
    }
}
uasort($byCategory, static fn($a, $b) => $b['total'] <=> $a['total']);
uasort($byMethod,   static fn($a, $b) => $b['total'] <=> $a['total']);

/* ---------- a readable description of the filters used ---------- */
$labels = [];
if ($f['date_from'] !== '' || $f['date_to'] !== '') {
    $labels[] = 'Date: ' . ($f['date_from'] !== '' ? date('M j, Y', strtotime($f['date_from'])) : 'beginning')
              . ' to ' . ($f['date_to'] !== '' ? date('M j, Y', strtotime($f['date_to'])) : 'today');
}
if ($f['category_id'] > 0) {
    foreach ($categories as $c) {
        if ((int)$c['id'] === $f['category_id']) { $labels[] = 'Category: ' . $c['name']; }
    }
}
if ($f['status'] !== '') { $labels[] = 'Status: ' . ucfirst($f['status']); }
if ($f['method'] !== '') { $labels[] = 'Method: ' . method_label($f['method']); }
if ($f['q'] !== '')      { $labels[] = 'Search: ' . $f['q']; }
$filterText = $labels ? implode('  |  ', $labels) : 'All transactions';

/** The summary and detail tables, used on screen and in the print preview. */
function report_body(array $rows, array $totals, array $counts, array $byCategory, array $byMethod, bool $truncated): string
{
    ob_start();
    ?>
    <div class="report-summary">
      <div><span>Total collected (paid)</span><strong><?= peso($totals['paid']) ?></strong><small><?= (int)$counts['paid'] ?> payments</small></div>
      <div><span>Pending</span><strong><?= peso($totals['pending']) ?></strong><small><?= (int)$counts['pending'] ?> payments</small></div>
      <div><span>Voided</span><strong><?= peso($totals['voided']) ?></strong><small><?= (int)$counts['voided'] ?> payments</small></div>
    </div>

    <div class="report-split">
      <div>
        <h3>Collected by category</h3>
        <table>
          <thead><tr><th>Category</th><th class="num">Payments</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php foreach ($byCategory as $name => $d): ?>
            <tr><td><?= e($name) ?></td><td class="num"><?= (int)$d['count'] ?></td><td class="num"><?= peso($d['total']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byCategory): ?><tr><td colspan="3" class="muted">No paid transactions.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <div>
        <h3>Collected by payment method</h3>
        <table>
          <thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Total</th></tr></thead>
          <tbody>
          <?php foreach ($byMethod as $name => $d): ?>
            <tr><td><?= e($name) ?></td><td class="num"><?= (int)$d['count'] ?></td><td class="num"><?= peso($d['total']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$byMethod): ?><tr><td colspan="3" class="muted">No paid transactions.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <h3>Transaction details</h3>
    <div class="table-wrap">
      <table class="detail-table">
        <thead>
          <tr><th>Date</th><th>Receipt no.</th><th>Student</th><th>Category</th><th>Method</th><th class="num">Amount</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e(date('M j, Y', strtotime($r['transaction_date']))) ?></td>
            <td><?= e($r['receipt_no']) ?></td>
            <td><?= e($r['student']) ?> (<?= e($r['student_no']) ?>)</td>
            <td><?= e($r['category']) ?></td>
            <td><?= e(method_label($r['payment_method'])) ?></td>
            <td class="num"><?= peso($r['amount']) ?></td>
            <td><?= e(ucfirst($r['status'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="muted">No transactions match these filters.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
          <tfoot><tr><th colspan="5">Total collected (paid only)</th><th class="num"><?= peso($totals['paid']) ?></th><th></th></tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php if ($truncated): ?>
      <p class="muted">Only the first <?= number_format(REPORT_MAX_ROWS) ?> rows are shown. Narrow the filters to see the rest.</p>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

/* ---------- PRINT PREVIEW (standalone page, opens in a new tab) ---------- */
if (str_param($_GET, 'print') === '1') {
    log_activity((int)$user['id'], $user['username'], 'REPORT',
        'Generated collection report (' . count($rows) . ' records; ' . $filterText . ')');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Collection report | Student Financial Records</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="print-preview">
  <div class="preview-toolbar no-print">
    <strong>Print preview</strong>
    <div>
      <button class="btn" type="button" onclick="window.print()">Print</button>
      <button class="btn-outline" type="button" onclick="window.close()">Close</button>
    </div>
  </div>

  <article class="sheet">
    <header class="sheet-head">
      <h1>Student Financial Records</h1>
      <h2>Collection Report</h2>
      <p><?= e($filterText) ?></p>
      <p class="muted">Generated by <?= e($user['full_name']) ?> on <?= e(date('F j, Y \a\t g:i A')) ?></p>
    </header>

    <?= report_body($rows, $totals, $counts, $byCategory, $byMethod, $truncated) ?>

    <div class="signatures">
      <div><span></span>Prepared by</div>
      <div><span></span>Checked by</div>
      <div><span></span>Approved by</div>
    </div>
  </article>
</body>
</html>
    <?php
    exit;
}

/* ---------- normal screen ---------- */
$pageTitle  = 'Reports';
$activePage = 'reports';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Collection Report</h1>
  <a class="btn btn-yellow" target="_blank" rel="noopener"
     href="<?= e(page_url('reports.php', array_merge($f, ['print' => '1']))) ?>">Print preview</a>
</div>

<?= tx_filter_form($f, $categories, 'reports.php') ?>

<section class="card">
  <p class="result-count"><?= e($filterText) ?></p>
  <?= report_body($rows, $totals, $counts, $byCategory, $byMethod, $truncated) ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
