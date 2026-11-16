<?php
declare(strict_types=1);

/**
 * Halaman penjualan script CMS — layout landing independen.
 *
 * Semua teks berasal dari Admin → Tools & Landing (App\Services\ToolConfig).
 * Struktur dipertahankan agar halaman tetap rapi walau sebagian bagian
 * disembunyikan dari admin.
 *
 * @var array  $cfg      ToolConfig::all()
 * @var array  $features daftar [judul, deskripsi]
 * @var array  $faq      daftar [tanya, jawab]
 * @var array  $testimonials daftar [nama, peran, kutipan]
 * @var array  $shots    ['dashboard'=>url, 'frontend'=>url]
 * @var array  $tech     daftar teknologi
 * @var array|null $flash
 * @var string $csrf
 * @var int    $orderAmount
 */

$on = static fn(string $key): bool => (string)($cfg[$key] ?? '0') === '1';
$v = static fn(string $key): string => (string)($cfg[$key] ?? '');
$home = (string)($home ?? url());
$siteName = (string)($siteName ?? 'CMS');
$buyUrl = (string)($buy ?? $home);
$externalBuy = preg_match('~^https?://~i', $buyUrl) === 1;
?>
<main class="lp">

    <?php if ($on('price_hero')): ?>
    <section class="lp-hero">
        <div class="sa-shell">
            <span class="lp-kicker">Script CMS · PHP + MySQL</span>
            <h1><?= e($v('price_headline')) ?></h1>
            <p class="lp-lead"><?= e($v('price_subheadline')) ?></p>
            <?php if ($v('price_description') !== ''): ?>
                <p class="lp-desc"><?= e($v('price_description')) ?></p>
            <?php endif; ?>
            <div class="lp-cta">
                <a class="lp-btn primary" href="<?= e((string)($demo ?? $home)) ?>"<?= preg_match('~^https?://~i', (string)($demo ?? '')) ? ' target="_blank" rel="noopener"' : '' ?>>Lihat Demo</a>
                <a class="lp-btn" href="<?= e($buyUrl) ?>"<?= $externalBuy ? ' target="_blank" rel="noopener"' : '' ?>>Beli Script CMS</a>
                <?php if ($v('price_contact_url') !== ''): ?>
                    <a class="lp-btn ghost" href="<?= e($v('price_contact_url')) ?>" target="_blank" rel="noopener">Hubungi Kami</a>
                <?php endif; ?>
            </div>
            <?php if ($on('price_pricing') && $v('price_amount') !== ''): ?>
                <div class="lp-price-chip">
                    <strong><?= e($v('price_amount')) ?></strong>
                    <?php if ($v('price_original') !== ''): ?><s><?= e($v('price_original')) ?></s><?php endif; ?>
                    <span><?= e($v('price_note')) ?></span>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_show_screens') && (($shots['dashboard'] ?? '') !== '' || ($shots['frontend'] ?? '') !== '')): ?>
    <section class="lp-shots">
        <div class="sa-shell lp-grid-2">
            <?php if (($shots['dashboard'] ?? '') !== ''): ?>
                <figure><img src="<?= e($shots['dashboard']) ?>" alt="Screenshot dashboard admin" loading="lazy"><figcaption>Dashboard admin</figcaption></figure>
            <?php endif; ?>
            <?php if (($shots['frontend'] ?? '') !== ''): ?>
                <figure><img src="<?= e($shots['frontend']) ?>" alt="Tampilan frontend blog" loading="lazy"><figcaption>Tampilan frontend</figcaption></figure>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_show_features')): ?>
    <section class="lp-section" id="fitur">
        <div class="sa-shell">
            <h2>Fitur unggulan</h2>
            <div class="lp-features">
                <?php foreach ($features as $i => [$title, $desc]): ?>
                    <article class="lp-feature">
                        <span class="lp-num"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
                        <h3><?= e($title) ?></h3>
                        <?php if ($desc !== ''): ?><p><?= e($desc) ?></p><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_show_tech') && $tech !== []): ?>
    <section class="lp-section lp-alt" id="teknologi">
        <div class="sa-shell">
            <h2>Teknologi</h2>
            <ul class="lp-tech">
                <?php foreach ($tech as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_show_testimonials') && ($testimonials ?? []) !== []): ?>
    <section class="lp-section" id="testimoni">
        <div class="sa-shell">
            <h2>Apa kata pengguna</h2>
            <div class="lp-testimonials">
                <?php foreach ($testimonials as [$who, $role, $quote]): ?>
                    <figure class="lp-testimonial">
                        <blockquote><?= nl2br(e($quote)) ?></blockquote>
                        <figcaption>
                            <?php if ($who !== ''): ?><strong><?= e($who) ?></strong><?php endif; ?>
                            <?php if ($role !== ''): ?><span><?= e($role) ?></span><?php endif; ?>
                        </figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_show_license') && $v('price_license') !== ''): ?>
    <section class="lp-section" id="lisensi">
        <div class="sa-shell">
            <h2>Lisensi</h2>
            <p class="lp-license"><?= nl2br(e($v('price_license'))) ?></p>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_pricing')): ?>
    <section class="lp-section lp-price" id="harga">
        <div class="sa-shell">
            <h2>Harga</h2>
            <div class="lp-price-card">
                <p class="lp-price-amount"><?= e($v('price_amount')) ?></p>
                <?php if ($v('price_original') !== ''): ?><p class="lp-price-old"><?= e($v('price_original')) ?></p><?php endif; ?>
                <p class="lp-price-note"><?= e($v('price_note')) ?></p>
                <ul class="lp-price-list">
                    <li>Lisensi satu instalasi</li>
                    <li>Update 1 tahun</li>
                    <li>Bantuan instalasi</li>
                </ul>
                <a class="lp-btn primary block" href="<?= e($buyUrl) ?>"<?= $externalBuy ? ' target="_blank" rel="noopener"' : '' ?>>Beli Sekarang</a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($on('price_show_faq') && $faq !== []): ?>
    <section class="lp-section lp-alt" id="faq">
        <div class="sa-shell">
            <h2>FAQ</h2>
            <div class="lp-faq">
                <?php foreach ($faq as [$q, $a]): ?>
                    <details>
                        <summary><?= e($q) ?></summary>
                        <p><?= nl2br(e($a)) ?></p>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <section class="lp-section" id="pesan">
        <div class="sa-shell lp-grid-2">
            <div>
                <h2>Pesan sekarang</h2>
                <p>Isi data berikut untuk mendapat instruksi pembayaran dan akses unduhan. Tidak ada pembayaran otomatis — kami verifikasi manual.</p>
                <?php if ($on('price_show_contacts')): ?>
                    <ul class="lp-contact">
                        <?php if ($v('price_contact_email') !== ''): ?><li>Email: <a href="mailto:<?= e($v('price_contact_email')) ?>"><?= e($v('price_contact_email')) ?></a></li><?php endif; ?>
                        <?php if ($v('price_contact_whatsapp') !== ''): ?><li>WhatsApp: <a href="https://wa.me/<?= e(preg_replace('/\D+/', '', $v('price_contact_whatsapp'))) ?>" target="_blank" rel="noopener"><?= e($v('price_contact_whatsapp')) ?></a></li><?php endif; ?>
                        <?php if ($v('price_contact_address') !== ''): ?><li><?= nl2br(e($v('price_contact_address'))) ?></li><?php endif; ?>
                    </ul>
                <?php endif; ?>
        </div>
            <div class="lp-form-wrap">
                <?php if (is_array($flash ?? null)): ?>
                    <p class="lp-flash <?= e((string)($flash['type'] ?? 'success')) ?>"><?= e((string)($flash['message'] ?? '')) ?></p>
                <?php endif; ?>
                <form method="post" action="<?= e(url('jual-script-cms')) ?>" class="lp-form">
                    <?= $csrf ?>
                    <input type="text" name="url_extra" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="hp">
                    <label for="lp-name">Nama</label>
                    <input id="lp-name" type="text" name="name" required maxlength="120" autocomplete="name">
                    <label for="lp-email">Email</label>
                    <input id="lp-email" type="email" name="email" required maxlength="180" autocomplete="email">
                    <label for="lp-wa">WhatsApp (opsional)</label>
                    <input id="lp-wa" type="tel" name="whatsapp" maxlength="40" autocomplete="tel">
                    <label for="lp-note">Catatan (opsional)</label>
                    <textarea id="lp-note" name="note" rows="3" maxlength="2000"></textarea>
                    <input type="hidden" name="amount" value="<?= (int)($orderAmount ?? 0) ?>">
                    <input type="hidden" name="package" value="CMS Script">
                    <button class="lp-btn primary block" type="submit">Kirim Pesanan</button>
                    <p class="lp-small">Dengan mengirim, Anda setuju dihubungi lewat email/WhatsApp untuk proses pembelian.</p>
                </form>
            </div>
        </div>
    </section>

    <footer class="lp-foot">
        <div class="sa-shell">
            <p>&copy; <?= date('Y') ?> <?= e($siteName) ?>. <a href="<?= e($home) ?>">Kembali ke situs</a></p>
        </div>
    </footer>
</main>
