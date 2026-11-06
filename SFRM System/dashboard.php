<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_login();

$pdo = db();

/* ---------- headline numbers (only "paid" transactions count as collected) ---------- */
$stats = $pdo->query(
    "SELECT
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS total_collected,
        COALESCE(SUM(CASE WHEN status = 'paid' AND transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN amount END), 0) AS month_collected,
        COALESCE(SUM(CASE WHEN status = 'paid' AND transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 END), 0) AS month_count,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 END), 0) AS pending_count,
        COALESCE(SUM(CASE WHEN status = 'pending' THEN amount END), 0) AS pending_amount
     FROM transactions"
)->fetch();

$enrolled = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE status = 'enrolled'")->fetchColumn();

/* ---------- collections for the last 12 months (fill empty months with 0) ---------- */
$byMonth = $pdo->query(
    "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym, SUM(amount) AS total
     FROM transactions
     WHERE status = 'paid'
       AND transaction_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
     GROUP BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$monthLabels = [];
$monthValues = [];
for ($i = 11; $i >= 0; $i--) {
    $d = new DateTime('first day of this month');
    $d->modify("-$i month");
    $monthLabels[] = $d->format('M Y');
    $monthValues[] = (float)($byMonth[$d->format('Y-m')] ?? 0);
}

/* ---------- summary by fee category ---------- */
$categories = $pdo->query(
    "SELECT c.name, COUNT(t.id) AS cnt, COALESCE(SUM(t.amount), 0) AS total
     FROM fee_categories c
     LEFT JOIN transactions t ON t.category_id = c.id AND t.status = 'paid'
     GROUP BY c.id, c.name
     ORDER BY total DESC"
)->fetchAll();
$grandTotal = array_sum(array_column($categories, 'total'));

/* ---------- latest transactions ---------- */
$recent = $pdo->query(
    "SELECT t.receipt_no, t.transaction_date, t.amount, t.status,
            CONCAT(s.last_name, ', ', s.first_name) AS student,
            c.name AS category
     FROM transactions t
     JOIN students s       ON s.id = t.student_id
     JOIN fee_categories c ON c.id = t.category_id
     ORDER BY t.transaction_date DESC, t.id DESC
     LIMIT 8"
)->fetchAll();

$catLabels = array_column($categories, 'name');
$catValues = array_map('floatval', array_column($categories, 'total'));

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Dashboard</h1>
  <p><?= e(date('l, F j, Y')) ?></p>
</div>

<section class="cards" aria-label="Key figures">
  <article class="card stat">
    <span>Total collected</span>
    <strong><?= peso($stats['total_collected']) ?></strong>
    <small>All paid transactions</small>
  </article>
  <article class="card stat">
    <span>Collected this month</span>
    <strong><?= peso($stats['month_collected']) ?></strong>
    <small><?= (int)$stats['month_count'] ?> payments</small>
  </article>
  <article class="card stat">
    <span>Pending payments</span>
    <strong><?= (int)$stats['pending_count'] ?></strong>
    <small><?= peso($stats['pending_amount']) ?> not yet confirmed</small>
  </article>
  <article class="card stat">
    <span>Enrolled students</span>
    <strong><?= number_format($enrolled) ?></strong>
    <small>Active student records</small>
  </article>
</section>

<section class="grid-2">
  <article class="card">
    <h2>Collections, last 12 months</h2>
    <div class="chart-box"><canvas id="monthlyChart" aria-label="Bar chart of monthly collections" role="img"></canvas></div>
  </article>

  <article class="card">
    <h2>Collections by category</h2>
    <?php if ($grandTotal > 0): ?>
      <div class="chart-box small"><canvas id="categoryChart" aria-label="Chart of collections by fee category" role="img"></canvas></div>
    <?php else: ?>
      <p class="empty">No paid transactions yet.</p>
    <?php endif; ?>
  </article>
</section>

<section class="grid-2 even">
  <article class="card">
    <h2>Summary by category</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Category</th><th class="num">Payments</th><th class="num">Total</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($categories as $c): ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td class="num"><?= (int)$c['cnt'] ?></td>
            <td class="num"><?= peso($c['total']) ?></td>
            <td class="num"><?= $grandTotal > 0 ? number_format($c['total'] / $grandTotal * 100, 1) . '%' : '0%' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>Total</th><th></th><th class="num"><?= peso($grandTotal) ?></th><th></th></tr></tfoot>
      </table>
    </div>
  </article>

  <article class="card">
    <h2>Latest transactions</h2>
    <?php if ($recent): ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Student</th><th>Category</th><th class="num">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?= e(date('M j', strtotime($r['transaction_date']))) ?></td>
              <td><?= e($r['student']) ?></td>
              <td><?= e($r['category']) ?></td>
              <td class="num"><?= peso($r['amount']) ?></td>
              <td><?= status_badge($r['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="empty">No transactions yet. Payments you record will show up here.</p>
    <?php endif; ?>
  </article>
</section>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  const peso = v => '\u20B1' + Number(v).toLocaleString();

  new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($monthLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      datasets: [{
        label: 'Collected',
        data: <?= json_encode($monthValues) ?>,
        backgroundColor: '#3a63d8',
        borderRadius: 4
      }]
    },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => peso(c.parsed.y) } } },
      scales: { y: { beginAtZero: true, ticks: { callback: v => peso(v) } } }
    }
  });

  <?php if ($grandTotal > 0): ?>
  new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode($catLabels, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
      datasets: [{
        data: <?= json_encode($catValues) ?>,
        backgroundColor: ['#14275c', '#3a63d8', '#ffc83d', '#0e7c66', '#9aa8c7'],
        borderWidth: 2
      }]
    },
    options: {
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: c => c.label + ': ' + peso(c.parsed) } } }
    }
  });
  <?php endif; ?>
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
