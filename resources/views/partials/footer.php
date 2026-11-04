<?php
/** @var CodeVault\View $view */
/** @var CodeVault\Localization\Translation|null $t */
/** @var array{brandName: string}|null $theme */
$t ??= null;
$theme ??= ['brandName' => brand_name()];
?>
<footer style="padding:var(--cv-space-6);color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
    <style>
    .cv-footer-links { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--cv-space-2) var(--cv-space-5); margin-bottom: var(--cv-space-4); font-size: var(--cv-text-sm); font-weight: 600; }
    .cv-footer-links a { color: var(--cv-text-secondary); text-decoration: none; }
    .cv-footer-links a:hover { color: var(--cv-text-primary); text-decoration: underline; }
    </style>
    <nav class="cv-footer-links" aria-label="Footer">
        <a href="/store">Store</a>
        <a href="/domains/register">Register a Domain</a>
        <a href="/client/tickets">Support</a>
        <a href="/client/reseller/docs">API Docs</a>
    </nav>
    <p style="text-align:center;margin:0;">&copy; <?= date('Y') ?> <?= e($theme['brandName']) ?> &mdash; <?= e($t?->get('footer.rights') ?? 'All rights reserved.') ?></p>
</footer>
