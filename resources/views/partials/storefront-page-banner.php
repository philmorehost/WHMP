<?php
/**
 * The title band at the top of a storefront's inner pages (a category, a
 * product, the cart, domain search, sign in) — the breadcrumb banner a hosting
 * template carries on every page that is not the home page.
 *
 * The heading is worked out from the path for pages whose controller passes a
 * generic title (the auth pages pass the product name, the cart passes
 * "Store"), and from the page title everywhere else.
 *
 * @var string|null $title
 * @var array<int, array{label: string, href?: string|null}>|null $breadcrumbs
 */
$title ??= null;
$breadcrumbs ??= null;

$path = rtrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/') ?: '/';

$known = [
    '/store' => 'Our services',
    '/cart' => 'Review & checkout',
    '/client/login' => 'Sign in',
    '/client/register' => 'Create your account',
    '/client/forgot-password' => 'Reset your password',
    '/client/reset-password' => 'Choose a new password',
    '/deals' => 'Deals & promotions',
];

$heading = trim((string) $title);

if (isset($known[$path]) && ($path !== '/store' || $heading === '' || $heading === 'Services' || $heading === 'Store')) {
    $heading = $known[$path];
}

if ($heading === '' || strcasecmp($heading, 'CodeVault') === 0) {
    $heading = brand_name();
}

if ($breadcrumbs === null) {
    $breadcrumbs = [['label' => 'Home', 'href' => '/']];

    if ((str_starts_with($path, '/store/')) || ($path === '/store' && isset($_GET['group_id']))) {
        $breadcrumbs[] = ['label' => 'Services', 'href' => '/store'];
    } elseif (str_starts_with($path, '/domains')) {
        $breadcrumbs[] = ['label' => 'Domains', 'href' => '/domains/register'];
    }

    $breadcrumbs[] = ['label' => $heading];
}
?>
<section class="sf-banner">
    <div class="sf-container sf-banner__inner">
        <h1 class="sf-banner__title"><?= e($heading) ?></h1>
        <nav class="sf-crumbs" aria-label="Breadcrumb">
            <?php foreach ($breadcrumbs as $i => $crumb): ?>
                <?php if ($i > 0): ?><span class="sf-crumbs__sep" aria-hidden="true">/</span><?php endif; ?>
                <?php if (!empty($crumb['href']) && $i < count($breadcrumbs) - 1): ?>
                    <a href="<?= e((string) $crumb['href']) ?>"><?= e((string) $crumb['label']) ?></a>
                <?php else: ?>
                    <span aria-current="page"><?= e((string) $crumb['label']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    </div>
</section>
