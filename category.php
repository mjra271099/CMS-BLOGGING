<section class="main" id="main">
<div class="status-msg-wrap"><?= e($category['name']) ?></div>
<?php
$perPage = max(1, min(50, (int)setting('posts_per_page', '7')));
$current = max(1, (int)($_GET['page'] ?? 1));
$countStmt = db()->prepare(
    'SELECT COUNT(DISTINCT p.id) FROM posts p JOIN post_categories pc ON pc.post_id=p.id
     JOIN categories c ON c.id=pc.category_id WHERE c.slug=? AND p.status="published" AND p.published_at<=NOW()'
);
$countStmt->execute([$category['slug']]);
$total = (int)$countStmt->fetchColumn();
foreach (published_posts($perPage, ($current - 1) * $perPage, $category['slug']) as $item) {
    render_post_card($item);
}
pagination($current, (int)ceil($total / $perPage), '');
?>
</section>
