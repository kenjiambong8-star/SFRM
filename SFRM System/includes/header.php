<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Shared page layout (top half). Before including, set:
 *   $pageTitle  - text for the browser tab and the page heading
 *   $activePage - key of the menu item to highlight (e.g. 'dashboard')
 * When you finish a module, change its 'ready' to true below.
 */
$user = current_user();

$menu = [
    ['key' => 'dashboard',    'label' => 'Dashboard',        'href' => 'dashboard.php',    'ready' => true,  'admin' => false],
    ['key' => 'students',     'label' => 'Students',         'href' => 'students.php',     'ready' => true , 'admin' => false],
    ['key' => 'transactions', 'label' => 'Transactions',     'href' => 'transactions.php', 'ready' => true , 'admin' => false],
    ['key' => 'reports',      'label' => 'Reports',          'href' => 'reports.php',      'ready' => true , 'admin' => false],
    ['key' => 'logs',         'label' => 'Activity Log',     'href' => 'logs.php',         'ready' => true , 'admin' => true],
    ['key' => 'backup',       'label' => 'Backup & Restore', 'href' => 'backup.php',       'ready' => true , 'admin' => true],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> | Student Financial Records</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=Figtree:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="app">

  <aside class="sidebar">
    <a class="side-brand" href="dashboard.php">
      <img src="assets/img/logo.png" alt="">
      <span>Student Financial Records</span>
    </a>
    <nav class="nav" aria-label="System menu">
      <?php foreach ($menu as $item): ?>
        <?php if ($item['admin'] && $user['role'] !== 'admin') continue; ?>
        <?php if ($item['ready']): ?>
          <a href="<?= e($item['href']) ?>" class="<?= $activePage === $item['key'] ? 'active' : '' ?>"
             <?= $activePage === $item['key'] ? 'aria-current="page"' : '' ?>><?= e($item['label']) ?></a>
        <?php else: ?>
          <span class="soon"><?= e($item['label']) ?> <small>Soon</small></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="who">
        <strong><?= e($user['full_name']) ?></strong>
        <span><?= e(ucfirst($user['role'])) ?></span>
      </div>
      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn-outline">Log out</button>
      </form>
    </header>

    <main class="content">
    <?= flash_render() ?>
