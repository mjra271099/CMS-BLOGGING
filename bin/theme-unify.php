<?php
declare(strict_types=1);

/**
 * One-off maintenance script: normalise every bundled theme's theme.json so all
 * themes share the AMP design language (same palette, radius, spacing, fonts).
 * Dark themes keep a dark surface set. Run with:
 *   CMS_CONFIG=config.local.php /opt/lampp/bin/php bin/theme-unify.php
 */

$root = dirname(__DIR__);
$themesDir = $root . '/themes';

$fontPair = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap';
$fontFamily = '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
$fontHeading = '"Plus Jakarta Sans", "Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

$light = [
    'primary_color' => '#2563eb',
    'primary_dark' => '#1d4ed8',
    'accent_color' => '#0891b2',
    'background_color' => '#f4f7fb',
    'surface_color' => '#ffffff',
    'text_color' => '#172554',
    'muted_color' => '#64748b',
    'border_color' => '#e2e8f0',
];

$dark = [
    'primary_color' => '#2563eb',
    'primary_dark' => '#1d4ed8',
    'accent_color' => '#0891b2',
    'background_color' => '#0a0f1a',
    'surface_color' => '#111a2b',
    'text_color' => '#e8eefb',
    'muted_color' => '#94a3b8',
    'border_color' => '#22304a',
];

$darkThemes = ['circuit', 'frame', 'reel', 'spectrum'];

foreach (glob($themesDir . '/*/theme.json') ?: [] as $file) {
    $slug = basename(dirname($file));
    $meta = json_decode((string)file_get_contents($file), true);
    if (!is_array($meta)) {
        fwrite(STDERR, "skip {$slug}: invalid json\n");
        continue;
    }
    $palette = in_array($slug, $darkThemes, true) ? $dark : $light;

    $overrides = $palette + [
        'font_pair' => $fontPair,
        'font_family' => $fontFamily,
        'font_heading' => $fontHeading,
        'font_size' => 17,
        'radius' => 5,
        'content_width' => 1180,
        'spacing' => 26,
        'sidebar_position' => 'right',
        'header_style' => 'compact',
        'footer_style' => 'dark',
        'card_style' => 'feature',
        'show_hero' => 1,
        'show_breadcrumbs' => 1,
        'show_reading_time' => 1,
    ];

    foreach ((array)($meta['settings'] ?? []) as $i => $definition) {
        $key = (string)($definition['key'] ?? '');
        if ($key === '' || !array_key_exists($key, $overrides)) {
            continue;
        }
        $meta['settings'][$i]['default'] = $overrides[$key];
    }

    if ($slug === 'amp-theme') {
        $meta['description'] = 'Tema profesional bergaya bersih: radius tegas 5px, garis 1px, bayangan tipis, '
            . 'tipografi Inter + Plus Jakarta Sans, dan layout stabil tanpa pergeseran.';
        $meta['tags'] = ['amp', 'cepat', 'professional', 'magazine', 'responsive', 'aksesibel', 'seo-ready'];
    }

    $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($file, $json);
    echo "updated {$slug} (" . (in_array($slug, $darkThemes, true) ? 'dark' : 'light') . ")\n";
}
