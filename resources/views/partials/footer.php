<?php
/** @var CodeVault\View $view */
/** @var CodeVault\Localization\Translation|null $t */
/** @var array{brandName: string}|null $theme */
$t ??= null;
$theme ??= ['brandName' => brand_name()];
?>
<footer style="padding:var(--cv-space-6);color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
    &copy; <?= date('Y') ?> <?= e($theme['brandName']) ?> &mdash; <?= e($t?->get('footer.rights') ?? 'All rights reserved.') ?>
</footer>
