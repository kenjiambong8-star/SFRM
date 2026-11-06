<?php
declare(strict_types=1);

/**
 * Scheduled backup. Run from the command line / cron only:
 *   php cron_backup.php
 * Creates a database backup and a file backup, then deletes backups older than 14 days.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/backup.php';

const KEEP_DAYS = 14;

try {
    $made = [];

    [$name, $size] = backup_database();
    record_backup($name, 'database', $size, null);
    $made[] = $name;

    try {
        [$name, $size] = backup_files();
        record_backup($name, 'files', $size, null);
        $made[] = $name;
    } catch (RuntimeException $e) {
        echo 'File backup skipped: ' . $e->getMessage() . PHP_EOL;
    }

    $removed = prune_backups(KEEP_DAYS);
    log_activity(null, 'system', 'BACKUP',
        'Scheduled backup: ' . implode(', ', $made) . ($removed ? " ($removed old backups removed)" : ''));
    echo 'Backup complete: ' . implode(', ', $made) . PHP_EOL;
} catch (Throwable $e) {
    error_log('Scheduled backup failed: ' . $e->getMessage());
    echo 'Backup failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
