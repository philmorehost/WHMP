<?php
/** @var array<int, array<string, mixed>> $categories product_groups, may be empty if the catalog is unreachable */
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string}|null $theme */
/** @var string $requestedPath */

// This page is deliberately self-contained — its own <head>, its own critical
// CSS — rather than extending layouts.client. An error page has to render when
// something is already wrong, so it must not depend on the layout's data
// (translations, currencies, language lists) or fail if a partial does. The
// stylesheets below are a progressive enhancement: they bring the brand tokens
// when they load, and every rule here carries a literal fallback so the page
// still looks designed if they don't.
$theme ??= ['brandName' => 'CodeVault', 'logoUrl' => null, 'primaryColor' => '#2f6fed', 'primaryColorDark' => '#26569c'];
$brand = (string) ($theme['brandName'] ?? 'CodeVault');
$primary = (string) ($theme['primaryColor'] ?? '#2f6fed');
$primaryDark = (string) ($theme['primaryColorDark'] ?? '#26569c');
$categories = $categories ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title>Page not found — <?= e($brand) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@600;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/components.css">
<style>
:root {
    --e404-primary: <?= e($primary) ?>;
    --e404-primary-dark: <?= e($primaryDark) ?>;
    --e404-bg: var(--cv-bg-canvas, #f6f8fb);
    --e404-surface: var(--cv-bg-surface, #ffffff);
    --e404-sunken: var(--cv-bg-surface-sunken, #f1f4f9);
    --e404-text: var(--cv-text-primary, #16202f);
    --e404-muted: var(--cv-text-secondary, #5b6b82);
    --e404-border: var(--cv-border-default, #e2e8f0);
}
@media (prefers-color-scheme: dark) {
    :root {
        --e404-bg: var(--cv-bg-canvas, #0f1520);
        --e404-surface: var(--cv-bg-surface, #172130);
        --e404-sunken: var(--cv-bg-surface-sunken, #1f2b3d);
        --e404-text: var(--cv-text-primary, #eef3fa);
        --e404-muted: var(--cv-text-secondary, #9fb0c7);
        --e404-border: var(--cv-border-default, #2a3a52);
    }
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
    background: var(--e404-bg);
    color: var(--e404-text);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

/* Ambient background wash — pure decoration, sits behind everything. */
.e404-glow {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    background:
        radial-gradient(ellipse 70% 55% at 15% 0%, color-mix(in srgb, var(--e404-primary) 18%, transparent) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 85% 100%, color-mix(in srgb, var(--e404-primary) 12%, transparent) 0%, transparent 60%);
}
@supports not (background: color-mix(in srgb, red 10%, transparent)) {
    .e404-glow { background: none; }
}

.e404-shell {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 920px;
    margin: 0 auto;
    padding: 40px 24px 64px;
    flex: 1;
    display: flex;
    flex-direction: column;
}

/* Brand */
.e404-brand {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    color: var(--e404-text);
    font-family: 'Hanken Grotesk', sans-serif;
    font-weight: 800;
    font-size: 1.05rem;
    margin-bottom: 56px;
    align-self: flex-start;
}
.e404-brand img { height: 34px; width: auto; display: block; }
.e404-brand__mark {
    width: 34px; height: 34px;
    border-radius: 9px;
    background: linear-gradient(135deg, var(--e404-primary), var(--e404-primary-dark));
    color: #fff;
    display: grid;
    place-items: center;
    font-size: .95rem;
    font-weight: 900;
}

/* Headline block */
.e404-code {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: clamp(5.5rem, 22vw, 11rem);
    font-weight: 900;
    line-height: .85;
    letter-spacing: -.04em;
    margin: 0 0 8px;
    background: linear-gradient(135deg, var(--e404-primary), var(--e404-primary-dark));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    -webkit-text-fill-color: transparent;
}
@supports not ((-webkit-background-clip: text) or (background-clip: text)) {
    .e404-code { color: var(--e404-primary); -webkit-text-fill-color: currentColor; }
}
.e404-title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: clamp(1.5rem, 4vw, 2.1rem);
    font-weight: 800;
    margin: 0 0 12px;
    letter-spacing: -.02em;
}
.e404-lede {
    color: var(--e404-muted);
    font-size: 1.05rem;
    margin: 0 0 12px;
    max-width: 52ch;
}
.e404-path {
    display: inline-block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
    font-family: 'JetBrains Mono', ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .85rem;
    background: var(--e404-sunken);
    border: 1px solid var(--e404-border);
    border-radius: 6px;
    padding: 3px 8px;
    color: var(--e404-muted);
}

/* Actions */
.e404-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 32px 0 0;
}
.e404-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 22px;
    border-radius: 10px;
    font-weight: 700;
    font-size: .95rem;
    text-decoration: none;
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}
.e404-btn--primary {
    background: linear-gradient(135deg, var(--e404-primary), var(--e404-primary-dark));
    color: #fff;
    box-shadow: 0 8px 20px -6px color-mix(in srgb, var(--e404-primary) 55%, transparent);
}
.e404-btn--primary:hover { transform: translateY(-2px); }
.e404-btn--ghost {
    background: var(--e404-surface);
    color: var(--e404-text);
    border: 1px solid var(--e404-border);
}
.e404-btn--ghost:hover { background: var(--e404-sunken); }

/* Categories */
.e404-section {
    margin-top: 64px;
    border-top: 1px solid var(--e404-border);
    padding-top: 32px;
}
.e404-section__label {
    font-size: .75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--e404-muted);
    margin: 0 0 18px;
}
.e404-cats {
    display: grid;
    /* min(240px, 100%) so the track can still shrink below 240px on a narrow
       phone instead of forcing the page to scroll sideways. */
    grid-template-columns: repeat(auto-fill, minmax(min(240px, 100%), 1fr));
    gap: 12px;
}
.e404-cat {
    display: flex;
    align-items: center;
    gap: 12px;
    /* Grid items default to min-width:auto, so the nowrap category name
       inside would otherwise stretch the track past the viewport. */
    min-width: 0;
    padding: 16px 18px;
    background: var(--e404-surface);
    border: 1px solid var(--e404-border);
    border-radius: 12px;
    text-decoration: none;
    color: var(--e404-text);
    transition: transform .18s ease, border-color .18s ease, box-shadow .18s ease;
}
.e404-cat:hover {
    transform: translateY(-3px);
    border-color: var(--e404-primary);
    box-shadow: 0 12px 24px -12px rgba(0,0,0,.28);
}
.e404-cat__icon {
    flex: 0 0 auto;
    width: 38px; height: 38px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    font-size: 1.1rem;
    background: var(--e404-sunken);
}
.e404-cat__body { min-width: 0; flex: 1; }
.e404-cat__name {
    display: block;
    font-weight: 700;
    font-size: .95rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.e404-cat__desc {
    display: block;
    font-size: .8rem;
    color: var(--e404-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.e404-cat__arrow { color: var(--e404-muted); flex: 0 0 auto; transition: transform .18s ease; }
.e404-cat:hover .e404-cat__arrow { transform: translateX(3px); color: var(--e404-primary); }

.e404-foot {
    margin-top: auto;
    padding-top: 48px;
    color: var(--e404-muted);
    font-size: .85rem;
}
.e404-foot a { color: var(--e404-primary); text-decoration: none; }
.e404-foot a:hover { text-decoration: underline; }

@media (max-width: 560px) {
    .e404-shell { padding: 28px 18px 48px; }
    .e404-brand { margin-bottom: 36px; }
    .e404-actions .e404-btn { flex: 1 1 100%; justify-content: center; }
    .e404-cats { grid-template-columns: 1fr; }
}
@media (prefers-reduced-motion: reduce) {
    .e404-btn, .e404-cat, .e404-cat__arrow { transition: none; }
    .e404-btn--primary:hover, .e404-cat:hover { transform: none; }
}
</style>
</head>
<body>
<div class="e404-glow" aria-hidden="true"></div>

<div class="e404-shell">
    <a class="e404-brand" href="/">
        <?php if (!empty($theme['logoUrl'])): ?>
            <img src="<?= e((string) $theme['logoUrl']) ?>" alt="<?= e($brand) ?>">
        <?php else: ?>
            <span class="e404-brand__mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($brand, 0, 1))) ?></span>
        <?php endif; ?>
        <span><?= e($brand) ?></span>
    </a>

    <main>
        <p class="e404-code">404</p>
        <h1 class="e404-title">We couldn&rsquo;t find that page</h1>
        <p class="e404-lede">
            The page you were looking for may have been moved, renamed, or never existed.
            <?php if ($requestedPath !== ''): ?>
                <br><span class="e404-path"><?= e($requestedPath) ?></span>
            <?php endif; ?>
        </p>

        <div class="e404-actions">
            <a class="e404-btn e404-btn--primary" href="/store">Browse the store</a>
            <a class="e404-btn e404-btn--ghost" href="/">Go to homepage</a>
        </div>

        <?php if ($categories !== []): ?>
            <section class="e404-section">
                <p class="e404-section__label">Shop by category</p>
                <div class="e404-cats">
                    <?php foreach ($categories as $category): ?>
                        <?php
                            $catName = (string) ($category['name'] ?? '');
                            $lower = strtolower($catName);
                            // Same icon cues the store listing uses, so a
                            // category looks like itself wherever it appears.
                            if (str_contains($lower, 'domain')) {
                                $icon = '🌐';
                            } elseif (str_contains($lower, 'mail')) {
                                $icon = '✉️';
                            } elseif (str_contains($lower, 'hosting') || str_contains($lower, 'server')) {
                                $icon = '🖥️';
                            } elseif (str_contains($lower, 'ssl') || str_contains($lower, 'security')) {
                                $icon = '🔒';
                            } else {
                                $icon = '⭐';
                            }
                            $desc = trim((string) ($category['description'] ?? ''));
                        ?>
                        <a class="e404-cat" href="/store?group_id=<?= (int) $category['id'] ?>">
                            <span class="e404-cat__icon" aria-hidden="true"><?= $icon ?></span>
                            <span class="e404-cat__body">
                                <span class="e404-cat__name"><?= e($catName) ?></span>
                                <?php if ($desc !== ''): ?>
                                    <span class="e404-cat__desc"><?= e($desc) ?></span>
                                <?php endif; ?>
                            </span>
                            <span class="e404-cat__arrow" aria-hidden="true">&rarr;</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <p class="e404-foot">
        Still stuck? <a href="/client/tickets">Contact our support team</a> and we&rsquo;ll help you find it.
    </p>
</div>
</body>
</html>
