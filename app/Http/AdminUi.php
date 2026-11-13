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

        // Shell / navigation
        'sun' => 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41',
        'moon' => 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
        'chevron-down' => 'M6 9l6 6 6-6',
        'chevron-right' => 'M9 6l6 6-6 6',
        'chevron-left' => 'M15 6l-6 6 6 6',
        'chevron-up' => 'M6 15l6-6 6 6',
        'log-out' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
        'panel-left' => 'M3 3h18v18H3zM9 3v18',
        'filter' => 'M22 3H2l8 9.5V19l4 2v-8.5z',
        'more-horizontal' => 'M12 12h.01M19 12h.01M5 12h.01',
        'calendar' => 'M3 5h18v16H3zM3 10h18M8 2v4M16 2v4',
        'folder' => 'M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z',
        'copy' => 'M9 9h11v11H9zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
        'loader' => 'M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8',
        'alert' => 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
        'info' => 'M12 16v-4M12 8h.01M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
        'check-circle' => 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4l-10 10-3-3',
        'x-circle' => 'M15 9l-6 6M9 9l6 6M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20z',
        'bell' => 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
        'trending-up' => 'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
        'zap' => 'M13 2 3 14h9l-1 8 10-12h-9z',
        'save' => 'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2zM17 21v-8H7v8M7 3v5h8',
        'star' => 'M12 2l3 7 7 .5-5.4 4.7 1.7 7.3L12 17.5 5.7 21.5l1.7-7.3L2 9.5 9 9z',
        'pin' => 'M12 17v5M9 3h6l-1 7 3 3H7l3-3z',
        'lock' => 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4',
        'link' => 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.5 1.5M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.5-1.5',
        'send' => 'M22 2 11 13M22 2l-7 20-4-9-9-4z',
        'minus' => 'M5 12h14',

        // Editor toolbar
        'bold' => 'M6 4h8a4 4 0 0 1 0 8H6zM6 12h9a4 4 0 0 1 0 8H6z',
        'italic' => 'M19 4h-9M14 20H5M15 4L9 20',
        'underline' => 'M6 3v7a6 6 0 0 0 12 0V3M4 21h16',
        'strikethrough' => 'M16 4H9a3 3 0 0 0-2.8 4M14 12a4 4 0 0 1 0 8H6M4 12h16',
        'code' => 'M16 18l6-6-6-6M8 6l-6 6 6 6',
        'remove-format' => 'M4 7V4h16v3M5 20h6M13 4 8 20M15 15l5 5M20 15l-5 5',
        'list' => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'list-ordered' => 'M10 6h11M10 12h11M10 18h11M4 6h1v4M4 10h2M6 18H4c0-1 2-2 2-3a1 1 0 0 0-2-.6',
        'quote' => 'M6 17h3l2-4V7H5v6h3zM14 17h3l2-4V7h-6v6h3z',
        'align-left' => 'M17 10H3M21 6H3M21 14H3M17 18H3',
        'align-center' => 'M18 10H6M21 6H3M21 14H3M18 18H6',
        'align-right' => 'M21 10H7M21 6H3M21 14H3M21 18H7',
        'align-justify' => 'M21 6H3M21 10H3M21 14H3M21 18H3',
        'table' => 'M3 3h18v18H3zM3 9h18M3 15h18M9 3v18M15 3v18',
        'text-color' => 'M6 16 12 4l6 12M8.5 12h7M4 20h16',
        'highlighter' => 'M15 3l6 6-9 9H6v-6zM3 21h18',
        'undo' => 'M3 7v6h6M3 13a9 9 0 1 0 3-7.7L3 7',
        'redo' => 'M21 7v6h-6M21 13a9 9 0 1 1-3-7.7L21 7',
        'type' => 'M4 7V4h16v3M9 20h6M12 4v16',
        'sliders-h' => 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6',
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
     * Toolbar markup for the rich text editor (Tiptap). Rendered in PHP so the
     * SVG icons stay identical to the rest of the dashboard; the runtime wires
     * each button through its `data-editor-cmd` attribute.
     *
     * @param array{media?:bool} $options
     */
    public static function editorToolbar(array $options = []): string
    {
        $withMedia = (bool)($options['media'] ?? true);

        $button = static function (string $cmd, string $icon, string $label, array $attrs = []): string {
            $extra = '';
            foreach ($attrs as $key => $value) {
                $extra .= ' ' . htmlspecialchars($key, ENT_QUOTES) . '="' . htmlspecialchars((string)$value, ENT_QUOTES) . '"';
            }
            return '<button type="button" class="rt-btn" data-editor-cmd="' . htmlspecialchars($cmd, ENT_QUOTES) . '"'
                . ' title="' . htmlspecialchars($label, ENT_QUOTES) . '"'
                . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"' . $extra . '>'
                . self::icon($icon, 17) . '</button>';
        };

        $group = static fn(string $inner): string => '<div class="rt-group">' . $inner . '</div>';
        $sep = '<span class="rt-sep" aria-hidden="true"></span>';

        $blocks = '<select class="rt-select" data-editor-block aria-label="Gaya teks">'
            . '<option value="p">Paragraf</option>'
            . '<option value="1">Judul 1</option><option value="2">Judul 2</option>'
            . '<option value="3">Judul 3</option><option value="4">Judul 4</option>'
            . '<option value="5">Judul 5</option><option value="6">Judul 6</option>'
            . '</select>';

        $inline = $button('bold', 'bold', 'Tebal (Ctrl+B)')
            . $button('italic', 'italic', 'Miring (Ctrl+I)')
            . $button('underline', 'underline', 'Garis bawah (Ctrl+U)')
            . $button('strike', 'strikethrough', 'Coret')
            . $button('code', 'code', 'Kode sebaris')
            . $button('clear', 'remove-format', 'Hapus format');

        $structure = $button('bulletList', 'list', 'Daftar butir')
            . $button('orderedList', 'list-ordered', 'Daftar nomor')
            . $button('blockquote', 'quote', 'Kutipan')
            . $button('codeBlock', 'code', 'Blok kode')
            . $button('horizontalRule', 'minus', 'Pembatas');

        $align = $button('align', 'align-left', 'Rata kiri', ['data-value' => 'left'])
            . $button('align', 'align-center', 'Rata tengah', ['data-value' => 'center'])
            . $button('align', 'align-right', 'Rata kanan', ['data-value' => 'right'])
            . $button('align', 'align-justify', 'Rata penuh', ['data-value' => 'justify']);

        $tableMenu = '<details class="rt-dropdown" data-editor-dropdown>'
            . '<summary class="rt-btn" data-editor-cmd="table" title="Tabel" aria-label="Tabel">'
            . self::icon('table', 17) . '<span style="font-size:9px;opacity:.7">▾</span></summary>'
            . '<div class="rt-menu">'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="insert">Sisipkan tabel 3×3</button>'
            . '<div class="rt-menu-sep"></div>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="addRowBefore">Tambah baris di atas</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="addRowAfter">Tambah baris di bawah</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="addColumnBefore">Tambah kolom kiri</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="addColumnAfter">Tambah kolom kanan</button>'
            . '<div class="rt-menu-sep"></div>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="mergeCells">Gabung sel</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="splitCell">Pisah sel</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="toggleHeaderRow">Baris judul</button>'
            . '<div class="rt-menu-sep"></div>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="deleteRow">Hapus baris</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="deleteColumn">Hapus kolom</button>'
            . '<button type="button" data-editor-cmd="table" data-editor-table-op="delete">Hapus tabel</button>'
            . '</div></details>';

        $insert = $button('link', 'link', 'Sisipkan tautan')
            . $tableMenu
            . '<button type="button" class="rt-btn" data-media-open data-media-target="body" title="Sisipkan gambar" aria-label="Sisipkan gambar">'
            . self::icon('image', 17) . '</button>';

        $colors = '<label class="rt-color" title="Warna teks">' . self::icon('text-color', 17)
            . '<input type="color" data-editor-color="color" value="#111827" aria-label="Warna teks"></label>'
            . '<label class="rt-color" title="Sorotan">' . self::icon('highlighter', 17)
            . '<input type="color" data-editor-color="highlight" value="#fde68a" aria-label="Warna sorotan"></label>'
            . $button('unsetColor', 'x', 'Hapus warna')
            . $button('unhighlight', 'x-circle', 'Hapus sorotan');

        $history = $button('undo', 'undo', 'Urungkan (Ctrl+Z)')
            . $button('redo', 'redo', 'Ulangi (Ctrl+Y)');

        $tail = '<button type="button" class="rt-btn rt-text" data-editor-cmd="source" title="Mode HTML" aria-label="Mode HTML">&lt;/&gt;</button>';
        if ($withMedia) {
            $tail .= '<button type="button" class="rt-btn" data-media-open data-media-target="body" title="Pustaka media" aria-label="Pustaka media">'
                . self::icon('folder', 17) . '</button>';
        }

        return '<div class="rt-toolbar" data-editor-toolbar role="toolbar" aria-label="Format teks">'
            . $group($blocks) . $sep
            . $group($inline) . $sep
            . $group($structure) . $sep
            . $group($align) . $sep
            . $group($insert) . $sep
            . $group($colors) . $sep
            . $group($history)
            . '<span class="rt-spacer"></span>'
            . $group($tail)
            . '</div>';
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
