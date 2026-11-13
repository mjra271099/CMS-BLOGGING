<section class="main" id="main">
<div class="status-msg-wrap"><?= $term !== '' ? 'Hasil pencarian: ' . e($term) : 'Masukkan kata pencarian.' ?></div>
<?php
$perPage = max(1, min(50, (int)setting('posts_per_page', '7')));
$current = max(1, (int)($_GET['page'] ?? 1));
$results = [];
$total = 0;
if ($term !== '') {
    $needle = '%' . $term . '%';
    $stmt = db()->prepare('SELECT p.*, u.username AS author FROM posts p JOIN users u ON u.id=p.author_id WHERE p.status="published" AND p.published_at<=NOW() AND (p.title LIKE ? OR p.body LIKE ? OR p.excerpt LIKE ?) ORDER BY p.published_at DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $needle);
    $stmt->bindValue(2, $needle);
    $stmt->bindValue(3, $needle);
    $stmt->bindValue(4, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(5, ($current - 1) * $perPage, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
    $count = db()->prepare('SELECT COUNT(*) FROM posts WHERE status="published" AND published_at<=NOW() AND (title LIKE ? OR body LIKE ? OR excerpt LIKE ?)');
    $count->execute([$needle, $needle, $needle]);
    $total = (int)$count->fetchColumn();
}
foreach ($results as $item) {
    render_post_card($item);
}
if ($term !== '' && !$results) {
    echo '<div class="status-msg-wrap">Tidak ada hasil yang cocok.</div>';
}
pagination($current, (int)ceil($total / $perPage), $term);
?>
</section>
