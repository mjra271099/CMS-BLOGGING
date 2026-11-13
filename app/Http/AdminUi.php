<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Presentation helpers shared by the admin views: inline SVG icons,
 * status pills, date formatting and small formatting utilities.
 */
final class AdminUi
{
    /** Path data for the icon set used across the dashboard. */
    private const ICONS = [
        'grid' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        'file' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
        'layers' => 'M12 2 2 7l10 5 10-5zM2 12l10 5 10-5M2 17l10 5 10-5',
        'image' => 'M3 3h18v18H3zM8.5 10.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zM21 15l-5-5L5 21',
        'message' => 'M21 11.5a8.4 8.4 0 0 1-9 8.4 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z',
        'tag' => 'M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1-.6-1.4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 1.4.6l6.4 6.4a2 2 0 0 1 0 2.8zM7 7h.01',
        'hash' => 'M4 9h16M4 15h16M10 3l-2 18M16 3l-2 18',
        'palette' => 'M12 3a9 9 0 1 0 0 18h1.5a2.5 2.5 0 0 0 0-5H12a2 2 0 0 1 0-4h5a4 4 0 0 0 4-4 4 4 0 0 0-4-3zM7 9h.01M7 15h.01M13 7h.01',
        'sliders' => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
        'columns' => 'M3 3h18v18H3zM9 3v18M15 3v18',
        'menu' => 'M3 6h18M3 12h18M3 18h18',
        'blocks' => 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
        'search' => 'M11 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16zM21 21l-4.3-4.3',
        'arrow' => 'M5 12h14M13 6l6 6-6 6',
        'mail' => 'M3 5h18v14H3zM3 6l9 7 9-7',
        'globe' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20',
        'users' => 'M17 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9.5 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
        'settings' => 'M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zM19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
        'database' => 'M12 2c4 0 7 1.3 7 3s-3 3-7 3-7-1.3-7-3 3-3 7-3zM5 5v7c0 1.7 3 3 7 3s7-1.3 7-3V5M5 12v7c0 1.7 3 3 7 3s7-1.3 7-3v-7',
        'activity' => 'M22 12h-4l-3 8L9 4l-3 8H2',
        'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
        'plus' => 'M12 5v14M5 12h14',
        'edit' => 'M11 4H4v16h16v-7M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z',
        'trash' => 'M3 6h18M8 6V4h8v2M6 6l1 15h10l1-15M10 11v6M14 11v6',
        'check' => 'M20 6L9 17l-5-5',
        'x' => 'M18 6L6 18M6 6l12 12',
        'eye' => 'M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z',
        'upload' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
        'download' => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
        'refresh' => 'M23 4v6h-6M1 20v-6h6M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15',
        'external' => 'M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3',
        'clock' => 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM12 6v6l4 2',
        'logo' => 'M12 2l3 7 7 .5-5.4 4.7 1.7 7.3L12 17.5 5.7 21.5l1.7-7.3L2 9.5 9 9z',
    ];

    /**
     * Render an inline SVG icon. Unknown names fall back to a dot.
     */
    public static function icon(string $name, int $size = 18, string $class = ''): string
    {
        $path = self::ICONS[$name] ?? 'M12 12h.01';
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';
        return '<svg' . $classAttr . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none"'
            . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
    }

    /**
     * Status pill markup for posts, pages, comments and users.
     */
    public static function pill(string $status): string
    {
        $map = [
            'published' => ['success', 'Terbit'],
            'approved' => ['success', 'Disetujui'],
            'active' => ['success', 'Aktif'],
            'draft' => ['warning', 'Draf'],
            'pending' => ['warning', 'Menunggu'],
            'scheduled' => ['info', 'Terjadwal'],
            'trash' => ['danger', 'Sampah'],
            'spam' => ['danger', 'Spam'],
            'suspended' => ['danger', 'Ditangguhkan'],
            'unsubscribed' => ['danger', 'Berhenti'],
            'confirmed' => ['success', 'Terkonfirmasi'],
            'private' => ['info', 'Privat'],
            'password' => ['info', 'Sandi'],
        ];
        [$tone, $label] = $map[$status] ?? ['', ucfirst($status)];
        return '<span class="pill ' . $tone . '">' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
    }

    public static function active(string $current, string $view): string
    {
        return $current === $view ? ' class="active"' : '';
    }

    public static function dateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $time = strtotime($value);
        return $time === false ? '—' : date('d M Y, H:i', $time);
    }

    public static function dateShort(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $time = strtotime($value);
        return $time === false ? '—' : date('d M Y', $time);
    }

    /**
     * Value for an <input type="datetime-local">.
     */
    public static function dateInput(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $time = strtotime($value);
        return $time === false ? '' : date('Y-m-d\TH:i', $time);
    }

    public static function bytes(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return round((float)$bytes, $index > 1 ? 1 : 0) . ' ' . $units[$index];
    }

    public static function excerpt(string $value, int $length = 90): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . '…';
    }

    public static function percent(int $value, int $total): int
    {
        return $total > 0 ? (int)round(($value / $total) * 100) : 0;
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $letters !== '' ? $letters : '?';
    }

    /** Inline bar chart for the dashboard trend card. */
    public static function sparkline(array $series, string $key = 'published'): string
    {
        $values = array_map(static fn($row): int => (int)($row[$key] ?? 0), $series);
        $max = max(1, max($values ?: [0]));
        $bars = '';
        foreach ($values as $index => $value) {
            $height = (int)round(($value / $max) * 100);
            $bars .= '<span class="spark-bar" style="height:' . max(4, $height) . '%" title="'
                . htmlspecialchars((string)($series[$index]['label'] ?? ''), ENT_QUOTES) . ': ' . $value . '"></span>';
        }
        return '<div class="sparkline">' . $bars . '</div>';
    }
}
