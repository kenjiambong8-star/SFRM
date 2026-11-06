<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Backup and restore helpers.
 *  - Database backups are plain .sql files (readable in MySQL Workbench).
 *  - File backups are .zip archives of the whole application folder.
 * Both are kept in storage/backups/, which is blocked from direct web access.
 */

const BACKUP_TABLES = ['users', 'students', 'fee_categories', 'transactions', 'activity_logs'];

function backup_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/backups';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('The backup folder could not be created. Check folder permissions.');
    }
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!file_exists($dir . '/index.html')) {
        file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** Full path of a backup file, only if the name is one we created. */
function backup_path(string $filename): ?string
{
    if (preg_match('/^(db_\d{8}_\d{6}\.sql|files_\d{8}_\d{6}\.zip)$/', $filename) !== 1) {
        return null;
    }
    $path = backup_dir() . '/' . $filename;
    return is_file($path) ? $path : null;
}

/** Dump the database to a .sql file. Returns [filename, size]. */
function backup_database(): array
{
    $pdo  = db();
    $name = 'db_' . date('Ymd_His') . '.sql';
    $path = backup_dir() . '/' . $name;

    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('The backup file could not be written. Check folder permissions.');
    }

    fwrite($fh, "-- Student Financial Records database backup\n-- Created: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach (BACKUP_TABLES as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        // keep each statement on one line so the restore can read it line by line
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n" . str_replace(["\r", "\n"], ' ', $create) . ";\n");

        $stmt = $pdo->query("SELECT * FROM `$table`");
        $cols = null;
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = '`' . implode('`, `', array_keys($row)) . '`';
            }
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
            }
            $batch[] = '(' . implode(', ', $vals) . ')';
            if (count($batch) >= 100) {
                fwrite($fh, "INSERT INTO `$table` ($cols) VALUES " . implode(', ', $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($fh, "INSERT INTO `$table` ($cols) VALUES " . implode(', ', $batch) . ";\n");
        }
        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    return [$name, (int)filesize($path)];
}

/** Zip all application files (not the storage folder). Returns [filename, size]. */
function backup_files(): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP "zip" extension is not enabled, so file backups are not available on this server.');
    }

    $root = realpath(dirname(__DIR__));
    $name = 'files_' . date('Ymd_His') . '.zip';
    $path = backup_dir() . '/' . $name;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The zip file could not be created.');
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if (!$file->isFile() || $file->isLink()) {
            continue;
        }
        $real = $file->getRealPath();
        $rel  = str_replace('\\', '/', substr($real, strlen($root) + 1));
        if (str_starts_with($rel, 'storage/') || str_starts_with($rel, '.git/') || str_starts_with($rel, 'node_modules/')
            || basename($rel) === '.DS_Store') {
            continue;
        }
        $zip->addFile($real, $rel);
    }
    $zip->close();

    return [$name, (int)filesize($path)];
}

function record_backup(string $filename, string $type, int $size, ?int $userId): void
{
    db()->prepare('INSERT INTO backup_history (filename, backup_type, size_bytes, created_by) VALUES (?, ?, ?, ?)')
        ->execute([$filename, $type, $size, $userId]);
}

/** Replace the database with the contents of a backup made by backup_database(). */
function restore_database(string $filename): void
{
    $path = backup_path($filename);
    if ($path === null || !str_ends_with($filename, '.sql')) {
        throw new RuntimeException('That database backup file was not found.');
    }

    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        $buffer = '';
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trim = rtrim($line);
            if ($buffer === '' && ($trim === '' || str_starts_with($trim, '--'))) {
                continue;
            }
            $buffer .= $line;
            if (str_ends_with($trim, ';')) {
                $pdo->exec($buffer);
                $buffer = '';
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
}

/** Put the application files from a zip backup back in place. */
function restore_files(string $filename): int
{
    $path = backup_path($filename);
    if ($path === null || !str_ends_with($filename, '.zip')) {
        throw new RuntimeException('That file backup was not found.');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP "zip" extension is not enabled on this server.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('The zip file could not be opened.');
    }

    $safe = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string)$zip->getNameIndex($i);
        // refuse absolute paths, parent folders, and anything touching storage/
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '..')
            || str_contains($entry, ':') || str_starts_with($entry, 'storage/')) {
            continue;
        }
        $safe[] = $entry;
    }
    $zip->extractTo(dirname(__DIR__), $safe);
    $zip->close();

    return count($safe);
}

/** Delete backups (files and history rows) older than $days days. Returns how many were removed. */
function prune_backups(int $days): int
{
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT id, filename FROM backup_history WHERE created_at < (NOW() - INTERVAL ' . (int)$days . ' DAY)');
    $stmt->execute();
    $removed = 0;
    foreach ($stmt->fetchAll() as $b) {
        $p = backup_path($b['filename']);
        if ($p !== null) {
            unlink($p);
        }
        $pdo->prepare('DELETE FROM backup_history WHERE id = ?')->execute([$b['id']]);
        $removed++;
    }
    return $removed;
}
