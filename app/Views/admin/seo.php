<?php
declare(strict_types=1);

/**
 * SEO cockpit: health score, issue list, verification keys and redirects.
 *
 * @var array $audit
 * @var array $redirects
 * @var array $settings
 */

use App\Http\AdminUi;

$audit = $audit ?? [];
$redirects = $redirects ?? [];
$settings = $settings ?? [];
$adminBase = url('admin/index.php');
$score = (int)($audit['score'] ?? 0);
$issues = (array)($audit['issues'] ?? []);
$counts = (array)($audit['counts'] ?? []);
?>
<div class="toolbar">
    <h1>SEO &amp; Audit</h1>
    <button type="button" class="secondary" data-seo-audit><?= AdminUi::icon('refresh', 16) ?> Audit Ulang</button>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2>Skor Kesehatan</h2>
        <div class="seo-score <?= $score >= 80 ? 'good' : ($score >= 60 ? 'warn' : 'bad') ?>">
            <span class="seo-number"><?= $score ?></span>
            <span class="seo-caption">/ 100</span>
        </div>
        <div class="pill-row">
            <span class="pill danger"><?= (int)($counts['error'] ?? 0) ?> error</span>
            <span class="pill warning"><?= (int)($counts['warning'] ?? 0) ?> peringatan</span>
            <span class="pill info"><?= (int)($counts['info'] ?? 0) ?> info</span>
        </div>
        <p class="help"><?= (int)($audit['checked'] ?? 0) ?> artikel terbit diperiksa.</p>
        <ul class="seo-check-list">
            <li>Meta title &amp; description unik di setiap artikel.</li>
            <li>Satu H1 per halaman dan struktur heading berurutan.</li>
            <li>Gambar memiliki atribut alt yang bermakna.</li>
            <li>URL kanonis, sitemap, dan feed RSS aktif.</li>
            <li>Data terstruktur JSON-LD untuk artikel dan situs.</li>
        </ul>
    </div>

    <div class="card">
        <h2>Temuan</h2>
        <?php if (!$issues): ?>
            <div class="empty-state"><p>Tidak ditemukan masalah. Kerja bagus! 🎉</p></div>
        <?php else: ?>
            <ul class="issue-list" data-issue-list>
                <?php foreach (array_slice($issues, 0, 40) as $issue): ?>
                    <li class="issue issue-<?= e((string)$issue['level']) ?>">
                        <span class="pill <?= $issue['level'] === 'error' ? 'danger' : ($issue['level'] === 'warning' ? 'warning' : 'info') ?>"><?= e((string)$issue['level']) ?></span>
                        <div style="flex:1">
                            <span><?= e((string)$issue['message']) ?></span>
                            <?php if (!empty($issue['id'])): ?>
                                <a href="<?= e($adminBase . '?view=post-edit&id=' . (int)$issue['id']) ?>">Perbaiki →</a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($issues) > 40): ?>
                <p class="help">Menampilkan 40 dari <?= count($issues) ?> temuan.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="dashboard-cols">
    <div class="card">
        <h2>Verifikasi &amp; Teknis</h2>
        <form method="post" action="<?= e($adminBase . '?view=settings') ?>">
            <input type="hidden" name="action" value="settings.save">
            <?= \App\Core\Csrf::field() ?>
            <label for="google_site_verification">Google Search Console</label>
            <input id="google_site_verification" type="text" name="google_site_verification" value="<?= e((string)($settings['google_site_verification'] ?? '')) ?>">
            <label for="bing_site_verification">Bing Webmaster</label>
            <input id="bing_site_verification" type="text" name="bing_site_verification" value="<?= e((string)($settings['bing_site_verification'] ?? '')) ?>">
            <label for="seo_title">Judul SEO default</label>
            <input id="seo_title" type="text" name="seo_title" value="<?= e((string)($settings['seo_title'] ?? '')) ?>">
            <label for="seo_description">Deskripsi SEO default</label>
            <textarea id="seo_description" name="seo_description" rows="3"><?= e((string)($settings['seo_description'] ?? '')) ?></textarea>
            <label for="robots_policy">Kebijakan robots</label>
            <select id="robots_policy" name="robots_policy">
                <?php foreach (['index,follow' => 'index,follow', 'noindex,follow' => 'noindex,follow', 'index,nofollow' => 'index,nofollow', 'noindex,nofollow' => 'noindex,nofollow'] as $value => $label): ?>
                    <option value="<?= e($value) ?>"<?= (string)($settings['robots_policy'] ?? '') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="checkbox"><input type="checkbox" name="sitemap_enabled" value="1"<?= ($settings['sitemap_enabled'] ?? '1') === '1' ? ' checked' : '' ?>> Aktifkan sitemap XML</label>
            <button type="submit" style="margin-top:14px">Simpan</button>
        </form>
        <p class="help">
            Sitemap: <a href="<?= e(url('sitemap.xml')) ?>" target="_blank" rel="noopener"><?= e(url('sitemap.xml')) ?></a><br>
            Robots: <a href="<?= e(url('robots.txt')) ?>" target="_blank" rel="noopener"><?= e(url('robots.txt')) ?></a><br>
            Feed: <a href="<?= e(url('feed/')) ?>" target="_blank" rel="noopener"><?= e(url('feed/')) ?></a>
        </p>
    </div>

    <div class="card">
        <h2>Redirect</h2>
        <?php if (!$redirects): ?>
            <p class="muted">Belum ada aturan redirect.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Dari</th><th>Ke</th><th>Kode</th><th>Hit</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($redirects, 0, 15) as $redirect): ?>
                    <tr>
                        <td><code><?= e((string)$redirect['from_path']) ?></code></td>
                        <td class="muted"><?= e((string)$redirect['to_path']) ?></td>
                        <td><?= (int)$redirect['status_code'] ?></td>
                        <td><?= (int)$redirect['hits'] ?></td>
                        <td>
                            <form method="post" action="<?= e($adminBase . '?view=redirects') ?>" class="inline">
                                <input type="hidden" name="action" value="redirect.delete">
                                <?= \App\Core\Csrf::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$redirect['id'] ?>">
                                <button type="submit" class="danger small">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <a class="btn secondary" style="margin-top:14px" href="<?= e($adminBase . '?view=redirects') ?>">Kelola redirect</a>
    </div>
</div>
