<?php
$commentNotice = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_submit']) && setting('allow_comments', '1') === '1') {
    verify_csrf();
    $author = trim((string)($_POST['author'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    if ($author === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $body === '' || strlen($body) > 8000) {
        $commentNotice = 'Lengkapi nama, email yang valid, dan komentar (maksimal 8.000 karakter).';
    } else {
        $status = setting('comment_moderation', '1') === '1' ? 'pending' : 'approved';
        $stmt = db()->prepare('INSERT INTO comments (post_id, author, email, body, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([(int)$post['id'], $author, $email, $body, $status]);
        $commentNotice = $status === 'pending' ? 'Komentar tersimpan dan menunggu moderasi.' : 'Komentar berhasil dikirim.';
    }
}
?>
<section class="main" id="main">
<?php
$cats = post_categories((int)$post['id']);
if ($cats) {
    echo '<div class="breadcrumbs"><span><a class="homebread" href="' . e(url()) . '"><span>Home</span></a></span> › ';
    echo '<span><a href="' . e(category_url($cats[0])) . '">' . e($cats[0]['name']) . '</a></span> › ' . e($post['title']) . '</div>';
}
render_post_card($post, true);
$relatedStmt = db()->prepare(
    'SELECT DISTINCT p.*, u.username AS author FROM posts p JOIN users u ON u.id=p.author_id
     JOIN post_categories pc ON pc.post_id=p.id WHERE pc.category_id IN
     (SELECT category_id FROM post_categories WHERE post_id=?) AND p.id<>?
     AND p.status="published" AND p.published_at<=NOW() ORDER BY p.published_at DESC LIMIT 3'
);
$relatedStmt->execute([(int)$post['id'], (int)$post['id']]);
$related = $relatedStmt->fetchAll();
if ($related) {
    echo '<div class="widget PopularPosts"><h2>Artikel terkait</h2><ul class="popular-post-list">';
    foreach ($related as $item) {
        echo '<li><a href="' . e(post_url($item)) . '">' . e($item['title']) . '</a></li>';
    }
    echo '</ul></div>';
}
$commentStmt = db()->prepare('SELECT author, body, created_at FROM comments WHERE post_id=? AND status="approved" ORDER BY created_at');
$commentStmt->execute([(int)$post['id']]);
$comments = $commentStmt->fetchAll();
?>
<section class="widget" id="disqus_comments"><div id="comments"><h2>Komentar (<?= count($comments) ?>)</h2>
<?php if ($commentNotice !== ''): ?><p class="status-msg-wrap comment-notice"><?= e($commentNotice) ?></p><?php endif; ?>
<?php if (!$comments): ?><p class="comment-empty">Belum ada komentar.</p><?php endif; ?>
<?php foreach ($comments as $comment): ?><article class="post comment-item"><div class="post-inner"><strong><?= e($comment['author']) ?></strong> <small><?= e(date('d M Y', strtotime($comment['created_at']))) ?></small><p><?= nl2br(e($comment['body'])) ?></p></div></article><?php endforeach; ?>
<?php if (setting('allow_comments', '1') === '1'): ?>
<form method="post" action="<?= e($canonical) ?>" class="post">
<div class="post-inner"><h3>Tinggalkan komentar</h3>
<input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
<label>Nama <input name="author" required maxlength="120"></label>
<label>Email <input name="email" type="email" required maxlength="190"></label>
<label>Komentar <textarea name="body" required maxlength="8000" rows="5"></textarea></label>
<button class="comment-submit" type="submit" name="comment_submit" value="1">Kirim komentar</button></div>
</form>
<?php endif; ?>
</div></section>
</section>
