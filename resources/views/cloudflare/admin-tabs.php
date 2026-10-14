<?php
/**
 * Shared header for /admin/cloudflare screens: styles, navigation and flash.
 *
 * @var string $active
 * @var string|null $notice
 * @var string|null $error
 */
$links = ['zones' => ['/admin/cloudflare', 'Zones'], 'import' => ['/admin/cloudflare/import', 'Import zones'], 'settings' => ['/admin/cloudflare/settings', 'Settings'], 'addon' => ['/admin/addons/cloudflare', 'Add-on']];
?>
<style>
    .cfa{display:grid;gap:18px;color:var(--cv-text-primary,#0f172a)}
    .cfa-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .cfa-head h1{margin:0 0 4px;display:flex;align-items:center;gap:10px;font-size:1.5rem}
    .cfa-head p{margin:0;color:var(--cv-text-secondary,#64748b)}
    .cfa-logo{display:inline-flex;width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#f6821f,#fbad41);color:#fff;align-items:center;justify-content:center;font-weight:700;font-size:.85rem}
    .cfa-nav{display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--cv-border-default,#e2e8f0)}
    .cfa-nav a{padding:9px 14px;border-radius:8px 8px 0 0;text-decoration:none;color:var(--cv-text-secondary,#64748b);font-weight:600;border-bottom:2px solid transparent}
    .cfa-nav a[aria-current="page"]{color:#f6821f;border-bottom-color:#f6821f}
    .cfa-card{background:var(--cv-bg-surface,#fff);color:var(--cv-text-primary,#0f172a);border:1px solid var(--cv-border-default,#e2e8f0);border-radius:14px;padding:20px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
    .cfa-card h2{margin:0 0 12px;font-size:1.1rem}
    .cfa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
    .cfa-stat{background:var(--cv-bg-surface,#fff);color:var(--cv-text-primary,#0f172a);border:1px solid var(--cv-border-default,#e2e8f0);border-radius:14px;padding:16px;border-top:3px solid var(--cfa-c,#94a3b8);text-decoration:none}
    .cfa-stat b{display:block;font-size:1.6rem;line-height:1.2}
    .cfa-stat span{color:var(--cv-text-secondary,#64748b);font-size:.85rem}
    .cfa-table{width:100%;border-collapse:collapse}
    .cfa-table th,.cfa-table td{padding:10px 8px;border-bottom:1px solid var(--cv-border-default,#e2e8f0);text-align:left;vertical-align:top}
    .cfa-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:var(--cv-text-secondary,#64748b)}
    .cfa-muted{color:var(--cv-text-secondary,#64748b);font-size:.85rem}
    .cfa-filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
    .cfa-filter .cv-input{width:auto}
    .cfa-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.78rem;font-weight:600;background:#f1f5f9;color:#475569}
    .cfa-pill--active{background:#dcfce7;color:#166534}
    .cfa-pill--pending{background:#fef3c7;color:#92400e}
    .cfa-pill--paused{background:#e0e7ff;color:#3730a3}
    .cfa-pill--deleting{background:#fee2e2;color:#991b1b}
    .cfa-pill--deleted{background:#f1f5f9;color:#64748b}
    .cfa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px}
    .cfa-dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;margin:0}
    .cfa-dl dt{color:var(--cv-text-secondary,#64748b)}
    .cfa-dl dd{margin:0;word-break:break-word}
    .cfa-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .cfa-actions form{margin:0;display:flex;gap:6px;align-items:center}
    .cfa-field{display:grid;gap:6px;margin-bottom:14px}
    .cfa-field label{font-weight:600}
    .cfa-check{display:flex;gap:8px;align-items:flex-start;margin-bottom:10px}
    .cfa-products{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:6px 16px;max-height:340px;overflow:auto;padding:4px}
    .cfa-steps{margin:0;padding-left:18px}
    .cfa-steps li{margin:4px 0}
    .cfa-mono{font-family:var(--cv-font-mono,monospace);font-size:.85rem}
</style>
<nav class="cfa-nav" aria-label="Cloudflare">
    <?php foreach ($links as $key => [$href, $label]): ?>
        <a href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>
<?php if (!empty($notice)): ?><div class="cv-alert cv-alert--success" role="status"><?= e((string) $notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="cv-alert cv-alert--error" role="alert"><?= e((string) $error) ?></div><?php endif; ?>
