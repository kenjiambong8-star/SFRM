<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * Write one line to the audit trail (who, what, date, time).
 * Call this after every login, logout, add, edit, delete, backup, etc.
 *
 * $action must be one of: LOGIN, LOGOUT, LOGIN_FAILED, CREATE, UPDATE,
 * DELETE, BACKUP, RESTORE, REPORT, OTHER
 */
function log_activity(?int $userId, string $username, string $action, string $details): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, username, action, details, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            mb_substr($username, 0, 50),
            $action,
            mb_substr($details, 0, 500),
            substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (Throwable $e) {
        // Logging must never break the page, but we still want to know it failed.
        error_log('Audit log failed: ' . $e->getMessage());
    }
}
