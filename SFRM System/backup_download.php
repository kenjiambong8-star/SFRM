<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/backup.php';
require_role('admin');

$user = current_user();
$stmt = db()->prepare('SELECT filename FROM backup_history WHERE id = ?');
$stmt->execute([(int)str_param($_GET, 'id')]);
$file = $stmt->fetchColumn();
$path = $file ? backup_path((string)$file) : null;

if ($path === null) {
    flash_set('error', 'That backup file was not found.');
    redirect('backup.php');
}

log_activity((int)$user['id'], $user['username'], 'BACKUP', 'Downloaded backup file ' . $file);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
