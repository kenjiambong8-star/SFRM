<?php
declare(strict_types=1);

/* ---------- formatting ---------- */

/** Format a number as pesos, e.g. ₱1,250.00 (safe to echo directly). */
function peso(float|int|string|null $amount): string
{
    return '&#8369;' . number_format((float)$amount, 2);
}

/** Small colored label for a transaction status. */
function status_badge(string $status): string
{
    $class = match ($status) {
        'paid'    => 'badge-paid',
        'pending' => 'badge-pending',
        default   => 'badge-voided',
    };
    return '<span class="badge ' . $class . '">' . e(ucfirst($status)) . '</span>';
}

function method_label(string $method): string
{
    return match ($method) {
        'cash'          => 'Cash',
        'gcash'         => 'GCash',
        'bank_transfer' => 'Bank transfer',
        'check'         => 'Check',
        default         => ucfirst($method),
    };
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024)    return $bytes . ' B';
    if ($bytes < 1048576) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / 1048576, 1) . ' MB';
}

/* ---------- reading user input safely ---------- */

/** Read a request value as a string (ignores arrays such as ?q[]=x). */
function str_param(array $src, string $key): string
{
    $v = $src[$key] ?? '';
    return is_string($v) ? $v : '';
}

/** Returns the date if it is a real YYYY-MM-DD date, otherwise null. */
function valid_date(?string $d): ?string
{
    if ($d === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) !== 1) {
        return null;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
}

/* ---------- flash messages (shown once after a redirect) ---------- */

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    $out = '';
    foreach ($_SESSION['flash'] ?? [] as $f) {
        $out .= '<div class="flash flash-' . e($f['type']) . '" role="status">' . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $out;
}

/* ---------- pagination ---------- */

function page_url(string $base, array $query, array $override = []): string
{
    $q = array_merge($query, $override);
    $q = array_filter($q, static fn($v) => $v !== '' && $v !== null && $v !== 0);
    return $base . ($q ? '?' . http_build_query($q) : '');
}

function pager(int $total, int $perPage, int $page, string $base, array $query): string
{
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) {
        return '';
    }
    $link = static function (int $p, string $label, bool $current = false) use ($base, $query): string {
        if ($current) {
            return '<span class="current" aria-current="page">' . $label . '</span>';
        }
        return '<a href="' . e(page_url($base, $query, ['page' => $p])) . '">' . $label . '</a>';
    };

    $html = '<nav class="pager" aria-label="Pages">';
    if ($page > 1) {
        $html .= $link($page - 1, 'Previous');
    }
    for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
        $html .= $link($p, (string)$p, $p === $page);
    }
    if ($page < $pages) {
        $html .= $link($page + 1, 'Next');
    }
    return $html . '</nav>';
}
