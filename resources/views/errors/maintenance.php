<?php
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string}|null $theme */

// Self-contained like errors/404.php — an error page has to render when
// something is already wrong, so it must not depend on the layout's data
// (translations, currencies, language lists) or fail if a partial does.
// The stylesheets are a progressive enhancement: brand tokens load when
// they can, and every rule here carries a literal fallback so the page
// still looks designed if they don't.
$theme ??= ['brandName' => 'CodeVault', 'logoUrl' => null, 'primaryColor' => '#2f6fed', 'primaryColorDark' => '#2558c4'];
$brand = (string) ($theme['brandName'] ?? 'CodeVault');
$logo = ($theme['logoUrl'] ?? '') !== '' ? (string) $theme['logoUrl'] : null;
$primary = (string) ($theme['primaryColor'] ?? '#2f6fed');
$primaryDark = (string) ($theme['primaryColorDark'] ?? '#2558c4');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= e($primary) ?>">
<title><?= e($brand) ?> — Under maintenance</title>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/components.css">
<style>
:root {
    --maint-primary: <?= e($primary) ?>;
    --maint-primary-dark: <?= e($primaryDark) ?>;
    --maint-bg: var(--cv-bg-canvas, #f6f8fb);
    --maint-surface: var(--cv-bg-surface, #ffffff);
    --maint-text: var(--cv-text-primary, #16202f);
    --maint-muted: var(--cv-text-secondary, #5b6b82);
    --maint-border: var(--cv-border-default, #e2e8f0);
}
@media (prefers-color-scheme: dark) {
    :root {
        --maint-bg: var(--cv-bg-canvas, #0f1520);
        --maint-surface: var(--cv-bg-surface, #172130);
        --maint-text: var(--cv-text-primary, #eef3fa);
        --maint-muted: var(--cv-text-secondary, #9fb0c7);
        --maint-border: var(--cv-border-default, #2a3a52);
    }
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    background: var(--maint-bg);
    color: var(--maint-text);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.card {
    background: var(--maint-surface);
    border: 1px solid var(--maint-border);
    border-radius: 16px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .10);
    max-width: 440px;
    width: 100%;
    padding: 40px 32px;
    text-align: center;
}
.logo {
    font-family: 'Hanken Grotesk', -apple-system, sans-serif;
    font-weight: 800;
    font-size: 22px;
    letter-spacing: -.01em;
    color: var(--maint-text);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}
.logo img { height: 28px; width: auto; display: block; }
.icon {
    width: 56px; height: 56px;
    border-radius: 14px;
    background: color-mix(in srgb, <?= e($primary) ?> 12%, transparent);
    color: var(--maint-primary);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 20px auto 16px;
}
h1 {
    font-family: 'Hanken Grotesk', -apple-system, sans-serif;
    font-size: 22px; font-weight: 800; margin: 0 0 8px; letter-spacing: -.01em;
}
p { margin: 0 0 20px; color: var(--maint-muted); font-size: 15px; }
.status-dot {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 13px; font-weight: 600; color: var(--maint-muted);
    background: var(--maint-bg);
    border: 1px solid var(--maint-border);
    border-radius: 999px; padding: 6px 14px;
}
.status-dot::before {
    content: ''; width: 8px; height: 8px; border-radius: 50%;
    background: <?= e($primary) ?>;
    animation: pulse 1.6s ease-in-out infinite;
}
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .35; } }
</style>
</head>
<body>
<div class="card">
    <a class="logo" href="/">
        <?php if ($logo !== null): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><?= e($brand) ?><?php endif; ?>
    </a>
    <div class="icon" aria-hidden="true">🛠️</div>
    <h1>System Maintenance</h1>
    <p>We're currently performing scheduled maintenance. Please check back shortly — we'll be right back.</p>
    <span class="status-dot">In progress</span>
</div>
</body>
</html>
