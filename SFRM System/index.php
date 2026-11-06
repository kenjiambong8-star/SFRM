<?php
// Landing page. No database access needed here.
$appName = 'Student Financial Records';
$year    = date('Y');
$bars    = [38, 52, 44, 70, 62, 85, 58, 74, 90, 66, 80, 96];   // sample chart heights (%)
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($appName) ?> | Student fee payments in one place</title>
  <meta name="description" content="Record student fee payments, see totals on a dashboard, and print reports, with a full audit trail.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/landing.css">
</head>
<body>

<div class="top">
  <header class="site-header">
    <a class="brand" href="index.php"><img class="logo" src="assets/img/logo.png" alt=""><?= htmlspecialchars($appName) ?></a>
    <nav aria-label="Main">
      <a href="#how">How it works</a>
      <a href="#features">Features</a>
      <a href="#security">Security</a>
    </nav>
    <a class="btn btn-ghost" href="login.php">Sign in</a>
  </header>

  <section class="hero">
    <img class="hero-logo" src="assets/img/logo.png" alt="Student Financial Records logo">
    <h1>Keep every student payment in one place, ready to check and print.</h1>
    <p class="lead">Record fees, watch your totals on a dashboard, and print reports for the finance office.</p>
    <div class="actions">
      <a class="btn btn-yellow" href="login.php">Sign in to the system</a>
      <a class="link" href="#how">See how it works</a>
    </div>
  </section>
</div>

<!-- Dashboard preview -->
<div class="preview-band">
<section class="preview-wrap" aria-label="Dashboard preview with sample data">
  <div class="preview">
    <div class="preview-bar">
      <strong>Dashboard</strong>
      <span class="sample">Sample data</span>
    </div>

    <div class="stats">
      <div class="stat"><span>Total collected</span><strong>&#8369;482,300</strong></div>
      <div class="stat"><span>Payments this month</span><strong>126</strong></div>
      <div class="stat"><span>Pending payments</span><strong>14</strong></div>
    </div>

    <div class="preview-body">
      <div class="chart" aria-hidden="true">
        <p>Collections by month</p>
        <div class="bars">
          <?php foreach ($bars as $h): ?>
            <span style="--h: <?= (int)$h ?>%"></span>
          <?php endforeach; ?>
        </div>
      </div>

      <table class="recent">
        <caption>Recent payments</caption>
        <thead><tr><th>Student</th><th>Category</th><th class="num">Amount</th></tr></thead>
        <tbody>
          <tr><td>Maria Santos</td><td>Tuition</td><td class="num">&#8369;7,500</td></tr>
          <tr><td>Juan Dela Cruz</td><td>Registration</td><td class="num">&#8369;500</td></tr>
          <tr><td>Ana Reyes</td><td>Miscellaneous</td><td class="num">&#8369;2,500</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>
</div>

<main>
  <section class="how" id="how">
    <h2>Three steps from payment to report</h2>
    <ol class="steps">
      <li>
        <h3>Add the student</h3>
        <p>Save the student's number, name, program, and year level once.</p>
      </li>
      <li>
        <h3>Record the payment</h3>
        <p>Pick the fee category and payment method, then save the transaction.</p>
      </li>
      <li>
        <h3>Review and print</h3>
        <p>Filter the records, check the totals, and print the report from print preview.</p>
      </li>
    </ol>
  </section>

  <section class="features" id="features">
    <h2>Everything the finance office needs</h2>
    <div class="feature-grid">
      <article class="feat feat-blue">
        <h3>Dashboard</h3>
        <p>Totals, charts, and summaries update as payments are recorded.</p>
      </article>
      <article class="feat feat-yellow">
        <h3>Student and payment records</h3>
        <p>Add, view, edit, and delete records from one place.</p>
      </article>
      <article class="feat feat-yellow">
        <h3>Filters</h3>
        <p>Narrow the list by date range, fee category, payment method, or status.</p>
      </article>
      <article class="feat feat-blue">
        <h3>Reports</h3>
        <p>Generate collection reports and check them in print preview.</p>
      </article>
    </div>
  </section>

  <section class="security" id="security">
    <div class="security-inner">
      <div>
        <h2>Built so the records can be trusted</h2>
        <p>Money records must be accurate and accountable, so every action leaves a trace and accounts are protected.</p>
      </div>
      <ul class="check-list">
        <li>Passwords are stored hashed, never as plain text</li>
        <li>A math question and attempt limits protect the sign-in page</li>
        <li>Every login, add, edit, and delete is logged with user, date, and time</li>
        <li>Administrators can back up and restore data and files</li>
        <li>Pages are served over HTTPS</li>
      </ul>
    </div>
  </section>

  <section class="cta">
    <h2>Ready to record today's payments?</h2>
    <a class="btn btn-yellow" href="login.php">Sign in to the system</a>
  </section>
</main>

<footer class="site-footer">
  <span>&copy; <?= $year ?> <?= htmlspecialchars($appName) ?></span>
  <a href="login.php">Staff sign in</a>
</footer>

</body>
</html>
