<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/backup.php';
require_role('admin');

$pdo  = db();
$user = current_user();

/* ---------- actions (POST only) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash_set('error', 'Your page expired. Please try again.');
        redirect('backup.php');
    }

    $action = str_param($_POST, 'action');
    $uid    = (int)$user['id'];

    try {
        if ($action === 'create') {
            $type    = str_param($_POST, 'type');
            $made    = [];
            if ($type === 'database' || $type === 'both') {
                [$name, $size] = backup_database();
                record_backup($name, 'database', $size, $uid);
                $made[] = $name;
            }
            if ($type === 'files' || $type === 'both') {
                [$name, $size] = backup_files();
                record_backup($name, 'files', $size, $uid);
                $made[] = $name;
            }
            if ($made) {
                log_activity($uid, $user['username'], 'BACKUP', 'Created backup: ' . implode(', ', $made));
                flash_set('success', 'Backup created: ' . implode(', ', $made));
            } else {
                flash_set('error', 'Choose what to back up.');
            }

        } elseif ($action === 'restore_db') {
            $row = $pdo->prepare("SELECT filename FROM backup_history WHERE id = ? AND backup_type = 'database'");
            $row->execute([(int)str_param($_POST, 'id')]);
            $file = $row->fetchColumn();
            if (!$file) {
                throw new RuntimeException('That backup was not found.');
            }

            // safety copy of the current data first, so a restore can itself be undone
            [$safeName, $safeSize] = backup_database();
            record_backup($safeName, 'database', $safeSize, $uid);

            restore_database((string)$file);

            // the restored data may not contain the current admin
            $still = $pdo->prepare('SELECT id FROM users WHERE id = ? AND status = \'active\'');
            $still->execute([$uid]);
            $stillThere = $still->fetchColumn() !== false;
            log_activity($stillThere ? $uid : null, $user['username'], 'RESTORE',
                'Restored database from ' . $file . ' (safety copy: ' . $safeName . ')');

            if (!$stillThere) {
                $_SESSION = [];
                session_regenerate_id(true);
                flash_set('success', 'Database restored. Please sign in again.');
                redirect('login.php');
            }
            flash_set('success', 'Database restored from ' . $file . '. A safety copy of the previous data was saved as ' . $safeName . '.');

        } elseif ($action === 'restore_files') {
            $row = $pdo->prepare("SELECT filename FROM backup_history WHERE id = ? AND backup_type = 'files'");
            $row->execute([(int)str_param($_POST, 'id')]);
            $file = $row->fetchColumn();
            if (!$file) {
                throw new RuntimeException('That backup was not found.');
            }
            $count = restore_files((string)$file);
            log_activity($uid, $user['username'], 'RESTORE', 'Restored ' . $count . ' files from ' . $file);
            flash_set('success', 'Restored ' . $count . ' files from ' . $file . '.');

        } elseif ($action === 'delete') {
            $row = $pdo->prepare('SELECT filename FROM backup_history WHERE id = ?');
            $row->execute([(int)str_param($_POST, 'id')]);
            $file = $row->fetchColumn();
            if ($file) {
                $p = backup_path((string)$file);
                if ($p !== null) {
                    unlink($p);
                }
                $pdo->prepare('DELETE FROM backup_history WHERE id = ?')->execute([(int)str_param($_POST, 'id')]);
                log_activity($uid, $user['username'], 'BACKUP', 'Deleted backup file ' . $file);
                flash_set('success', 'Backup deleted.');
            }
        }
    } catch (RuntimeException $e) {
        flash_set('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Backup/restore error: ' . $e->getMessage());
        flash_set('error', 'Something went wrong. Nothing was changed, or the operation stopped part way. Check the server error log.');
    }
    redirect('backup.php');
}

/* ---------- page data ---------- */
$backups = $pdo->query(
    'SELECT b.*, u.username FROM backup_history b
     LEFT JOIN users u ON u.id = b.created_by
     ORDER BY b.id DESC LIMIT 50'
)->fetchAll();

$lastBackup = $pdo->query("SELECT MAX(created_at) FROM backup_history")->fetchColumn();
$overdue    = !$lastBackup || strtotime((string)$lastBackup) < time() - 86400;

$pageTitle  = 'Backup & Restore';
$activePage = 'backup';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Backup &amp; Restore</h1>
  <p>
    <?= $lastBackup ? 'Last backup: ' . e(date('M j, Y g:i A', strtotime((string)$lastBackup))) : 'No backups yet' ?>
  </p>
</div>

<?php if ($overdue): ?>
  <div class="flash flash-warn">No backup in the last 24 hours. Financial systems should be backed up daily.</div>
<?php endif; ?>

<section class="card">
  <h2>Create a backup</h2>
  <div class="backup-buttons">
    <?php foreach (['database' => 'Back up database', 'files' => 'Back up system files', 'both' => 'Back up both'] as $type => $label): ?>
      <form method="post" action="backup.php" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="type" value="<?= e($type) ?>">
        <button class="btn <?= $type === 'both' ? 'btn-yellow' : '' ?>" type="submit"><?= e($label) ?></button>
      </form>
    <?php endforeach; ?>
  </div>
  <p class="hint">Database backups are .sql files you can also open in MySQL Workbench. File backups are .zip copies of the application. Backups are stored in the protected <code>storage/backups</code> folder.</p>
</section>

<section class="card">
  <h2>Saved backups</h2>
  <?php if ($backups): ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Created</th><th>Type</th><th>File</th><th class="num">Size</th><th>By</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b):
            $exists = backup_path($b['filename']) !== null;
            $isDb   = $b['backup_type'] === 'database'; ?>
          <tr>
            <td><?= e(date('M j, Y g:i A', strtotime($b['created_at']))) ?></td>
            <td><?= $isDb ? 'Database' : 'Files' ?></td>
            <td><?= e($b['filename']) ?></td>
            <td class="num"><?= e(format_bytes((int)$b['size_bytes'])) ?></td>
            <td><?= e($b['username'] ?? 'System (scheduled)') ?></td>
            <td class="row-actions">
              <?php if ($exists): ?>
                <a href="backup_download.php?id=<?= (int)$b['id'] ?>">Download</a>
                <form method="post" action="backup.php" class="inline"
                      onsubmit="return confirm('<?= $isDb
                          ? 'Restore the database from this backup? Current data will be replaced (a safety copy is made first).'
                          : 'Restore the application files from this backup? Existing files will be overwritten.' ?>');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="<?= $isDb ? 'restore_db' : 'restore_files' ?>">
                  <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                  <button type="submit" class="link-btn">Restore</button>
                </form>
              <?php else: ?>
                <span class="muted">File missing</span>
              <?php endif; ?>
              <form method="post" action="backup.php" class="inline"
                    onsubmit="return confirm('Delete this backup file?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="link-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="empty">No backups yet. Create one above.</p>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Automatic daily backup</h2>
  <p>The file <code>cron_backup.php</code> creates a database and file backup and removes backups older than 14 days. Schedule it to run every day.</p>
  <p><strong>On web hosting (cPanel &gt; Cron Jobs), daily at 2:00 AM:</strong></p>
  <pre class="code">0 2 * * * /usr/local/bin/php <?= e(__DIR__) ?>/cron_backup.php</pre>
  <p class="hint">The exact PHP path may differ on your host, so check with your hosting provider. The script only runs from the command line, not from a browser.</p>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
