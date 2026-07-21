<?php
/** @var CodeVault\View $view */
/** @var string $title */
/** @var string $content */
/** @var string|null $canonicalUrl */
/** @var string|null $metaDescription */
/** @var array<int, array<string, mixed>> $jsonLd */
/** @var array<int, array<string, mixed>>|null $currencies */
/** @var array<string, mixed>|null $selectedCurrency */
/** @var CodeVault\Localization\Translation|null $t */
/** @var array<int, array<string, mixed>>|null $languages */
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string}|null $theme */
$canonicalUrl ??= null;
$metaDescription ??= null;
$jsonLd ??= [];
$currencies ??= null;
$selectedCurrency ??= null;
$t ??= null;
$languages ??= null;
$theme ??= ['brandName' => 'CodeVault', 'logoUrl' => null, 'primaryColor' => '#2f6fed', 'primaryColorDark' => '#26569c'];
?>
<!doctype html>
<html lang="<?= e($t?->code() ?? 'en') ?>" dir="<?= e($t?->dir() ?? 'ltr') ?>" data-skin="client">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? $theme['brandName']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;800&family=Inter:wght@400;600&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
    <script src="/assets/js/theme-init.js"></script>
    <?php if ($metaDescription !== null): ?>
        <meta name="description" content="<?= e($metaDescription) ?>">
    <?php endif; ?>
    <?php if ($canonicalUrl !== null): ?>
        <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <?php if ($t?->isRtl()): ?>
        <link rel="stylesheet" href="/assets/css/rtl.css">
    <?php endif; ?>
    <style>:root { --cv-color-brand-500: <?= e($theme['primaryColor']) ?>; --cv-color-brand-600: <?= e($theme['primaryColorDark']) ?>; }</style>
    <?php foreach ($jsonLd as $schema): ?>
        <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endforeach; ?>
    <script src="<?= asset('assets/js/app.js') ?>" defer></script>
</head>
<body data-skin="client">
<?php if (!empty($_SESSION['original_admin_id'])): ?>
    <div style="background:var(--cv-color-brand-500);color:#ffffff;padding:var(--cv-space-2) var(--cv-space-6);display:flex;justify-content:space-between;align-items:center;font-size:var(--cv-text-sm);font-weight:600;z-index:9999;position:relative;">
        <span>👤 You are logged in as a client.</span>
        <a href="/client/return-to-admin" style="color:#ffffff;text-decoration:underline;font-weight:700;">Return to Admin Panel &rarr;</a>
    </div>
<?php endif; ?>
<?= $view->partial('partials.header', [
    'title' => $title ?? $theme['brandName'],
    'currencies' => $currencies,
    'selectedCurrency' => $selectedCurrency,
    't' => $t,
    'languages' => $languages,
    'theme' => $theme,
]) ?>
<main class="cv-shell__main">
    <?= $content ?>
</main>
<?= $view->partial('partials.footer', ['t' => $t, 'theme' => $theme]) ?>
</body>
</html>
