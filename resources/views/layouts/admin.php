<?php
/** @var CodeVault\View $view */
/** @var string $title */
/** @var string $content */
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string}|null $theme */
$theme ??= ['brandName' => 'CodeVault', 'logoUrl' => null, 'primaryColor' => '#2f6fed', 'primaryColorDark' => '#26569c'];
?>
<!doctype html>
<html lang="en" data-skin="admin">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? "{$theme['brandName']} Admin") ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;800&family=Inter:wght@400;600&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
    <script src="/assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <style>:root { --cv-color-brand-500: <?= e($theme['primaryColor']) ?>; --cv-color-brand-600: <?= e($theme['primaryColorDark']) ?>; }</style>
    <script src="/assets/js/app.js" defer></script>
</head>
<body data-skin="admin">
<div class="cv-sidebar-backdrop" data-sidebar-close></div>
<div class="cv-shell">
    <aside class="cv-shell__sidebar">
        <?= $view->partial('partials.admin-nav', ['theme' => $theme]) ?>
    </aside>
    <div class="cv-shell__body">
        <div class="cv-shell__topbar">
            <button type="button" class="cv-sidebar-toggle" data-sidebar-toggle aria-label="Toggle navigation menu">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2 4h12M2 8h12M2 12h12"/></svg>
            </button>
            <button type="button" class="cv-theme-toggle" data-theme-toggle aria-label="Toggle dark mode">
                <svg class="cv-icon-moon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5 8.7A5.5 5.5 0 0 1 7.3 2.5a5.5 5.5 0 1 0 6.2 6.2z"/></svg>
                <svg class="cv-icon-sun" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8" r="3"/><path d="M8 1.5v1.5M8 13v1.5M2.5 8H1M15 8h-1.5M3.5 3.5l1 1M11.5 11.5l1 1M12.5 3.5l-1 1M4.5 11.5l-1 1"/></svg>
            </button>
        </div>
        <main class="cv-shell__main">
            <?= $content ?>
        </main>
    </div>
</div>
</body>
</html>
