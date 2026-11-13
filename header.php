<?php
$pageTitle = $pageTitle ?? setting('site_name', 'V-Dey');
$description = $pageDescription ?? setting('site_description', '');
$canonical = $canonical ?? url();
$image = $socialImage ?? '';
$extraHead = setting('theme_header_code');
$themeCss = setting('theme_css');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,minimum-scale=1,initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($description) ?>">
<?php if (!empty($preview)): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<link rel="icon" href="<?= e(setting('favicon_url', url('favicon.ico'))) ?>">
<meta property="og:site_name" content="<?= e(setting('site_name')) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($description) ?>">
<meta property="og:type" content="<?= isset($isArticle) && $isArticle ? 'article' : 'website' ?>">
<?php if ($image !== ''): ?><meta property="og:image" content="<?= e($image) ?>"><?php endif; ?>
<meta property="og:locale" content="id_ID">
<?php if (setting('google_site_verification') !== ''): ?><meta name="google-site-verification" content="<?= e(setting('google_site_verification')) ?>"><?php endif; ?>
<meta name="geo.placename" content="Indonesia">
<meta name="geo.country" content="id">
<meta name="rating" content="general">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($pageTitle) ?>">
<meta name="twitter:description" content="<?= e($description) ?>">
<?php if ($image !== ''): ?><meta name="twitter:image" content="<?= e($image) ?>"><?php endif; ?>
<?php if (!empty($preview) || in_array($route ?? '', ['search', 'category'], true)): ?><meta name="robots" content="noindex,noarchive"><?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= e(setting('site_name')) ?> RSS" href="<?= e(url('feed.xml')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/template.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/cms-adapter.css')) ?>">
<?php if ($themeCss !== ''): ?><style><?= $themeCss ?></style><?php endif; ?>
<?= $extraHead ?>
<?php
$structuredData = !empty($isArticle)
    ? ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'] ?? $pageTitle, 'description' => $description, 'mainEntityOfPage' => $canonical, 'image' => $image]
    : ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => setting('site_name'), 'url' => url(), 'potentialAction' => ['@type' => 'SearchAction', 'target' => url('search?q={search_term_string}'), 'query-input' => 'required name=search_term_string']];
if (!empty($isArticle) && isset($post)) {
    $structuredData['datePublished'] = date(DATE_W3C, strtotime((string)$post['published_at']));
    $structuredData['dateModified'] = date(DATE_W3C, strtotime((string)$post['updated_at']));
    $structuredData['author'] = ['@type' => 'Person', 'name' => $post['author'] ?? ''];
}
?>
<script type="application/ld+json"><?= json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<meta name="google-site-verification" content="Ywb_bgigpT6qygjJqnjcnOEkWCMkKyRBwq7qgy8V7h4" />
<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-T3RB7HJN"></script>
<meta name="ahrefs-site-verification" content="1b84a9104fdcc5a1c02505c210894d17bd334e4e92dcdcdcfc9c4b8c04a1392b">
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
</script>
</head>
<body class="index">
