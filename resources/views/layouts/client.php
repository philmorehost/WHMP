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

// A reseller's website gets the storefront chrome — premium header with the
// SERVICES menu, page banner, four-column footer — instead of the platform's
// client-area bar. Decided by the host that matched (CurrentReseller), never by
// anything the visitor sends. $storefrontStore can be passed to force it (tests).
$storefrontHome ??= false;
$storefrontStore ??= current_storefront();
$isStorefront = is_array($storefrontStore);
$storefrontPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
// The client area keeps its own page headings; the public pages and the sign-in
// pages get the storefront's title banner.
$storefrontBanner = $isStorefront && !$storefrontHome && (
    !str_starts_with($storefrontPath, '/client/')
    || in_array(rtrim($storefrontPath, '/'), ['/client/login', '/client/register', '/client/forgot-password', '/client/reset-password'], true)
);
?>
<!doctype html>
<html lang="<?= e($t?->code() ?? 'en') ?>" dir="<?= e($t?->dir() ?? 'ltr') ?>" data-skin="client">
<head>
    <meta charset="utf-8">
    <!-- Without this, a phone renders the page at a ~980px virtual viewport and
         scales it down: every CSS media query below that width never matches,
         so the whole client area shows its desktop layout shrunk to fit. The
         admin and installer layouts have always carried this; the client one
         did not. -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    // Was a str_replace list of exact "CodeVault " prefixes, which missed a
    // bare 'CodeVault' title — the client login and register pages pass
    // exactly that, so their tabs read "CodeVault — Brand". page_title()
    // substitutes the brand for the product name wherever it appears.
    ?>
    <title><?php
        echo e(page_title($title ?? null, $theme['brandName'] ?? null));
    ?></title>
    <?php if (!empty($theme['faviconUrl'])): ?>
        <link rel="icon" href="<?= e($theme['faviconUrl']) ?>">
    <?php endif; ?>
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
    <?php if ($isStorefront): ?>
        <link rel="stylesheet" href="<?= asset('assets/css/storefront.css') ?>">
        <script src="<?= asset('assets/js/storefront.js') ?>" defer></script>
    <?php endif; ?>
    <?php foreach ($jsonLd as $schema): ?>
        <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endforeach; ?>
    <script src="<?= asset('assets/js/app.js') ?>" defer></script>
</head>
<body data-skin="client"<?= $isStorefront ? ' class="sf' . ($storefrontHome ? ' sf--home' : '') . '"' : '' ?>>
<?= $view->partial('partials.promo-banner') ?>
<?php if (!empty($_SESSION['original_admin_id'])): ?>
    <div class="cv-no-print" style="background:var(--cv-color-brand-500);color:#ffffff;padding:var(--cv-space-2) var(--cv-space-6);display:flex;justify-content:space-between;align-items:center;font-size:var(--cv-text-sm);font-weight:600;z-index:9999;position:relative;">
        <span>👤 You are logged in as a client.</span>
        <a href="/client/return-to-admin" style="color:#ffffff;text-decoration:underline;font-weight:700;">Return to Admin Panel &rarr;</a>
    </div>
<?php endif; ?>
<?php if ($isStorefront): ?>
    <?= $view->partial('partials.storefront-header', [
        'store' => $storefrontStore,
        'home' => $storefrontHome,
        'currencies' => $currencies,
        'selectedCurrency' => $selectedCurrency,
        't' => $t,
        'languages' => $languages,
        'theme' => $theme,
    ]) ?>
    <?php if ($storefrontBanner): ?>
        <?= $view->partial('partials.storefront-page-banner', ['title' => $title ?? null, 'breadcrumbs' => $breadcrumbs ?? null]) ?>
    <?php endif; ?>
    <main class="cv-shell__main sf-main<?= $storefrontHome ? ' sf-main--home' : '' ?>">
        <?= $content ?>
    </main>
    <?= $view->partial('partials.storefront-footer', ['store' => $storefrontStore, 't' => $t, 'theme' => $theme]) ?>
<?php else: ?>
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
<?php endif; ?>
<?php
// Which chat this page gets is a decision, not an include: a host that matched a
// store must show the STORE's chat and never the platform's. That rule lives in
// one partial rather than here, because the admin layout includes the platform's
// widget directly and the two must not drift.
?>
<?= $view->partial('partials.live-chat') ?>
</body>
</html>
