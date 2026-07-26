<?php
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string} $theme */
/** @var string|null $error */
/** @var bool $saved */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Theme</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($saved): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <p style="color:var(--cv-color-success-600, #1a7f37);">Theme saved.</p>
    </div>
<?php endif; ?>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card">
    <form method="post" action="/admin/theme" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">Brand Name</label>
            <input class="cv-input" name="brand_name" value="<?= e($theme['brandName']) ?>" required>
        </div>
        <div class="cv-field">
            <label class="cv-label">Logo URL (optional)</label>
            <input class="cv-input" type="url" name="logo_url" value="<?= e((string) ($theme['logoUrl'] ?? '')) ?>" placeholder="https://example.com/logo.png">
        </div>
        <div class="cv-field">
            <label class="cv-label">Favicon Upload / URL</label>
            <?php if (!empty($theme['faviconUrl'])): ?>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                    <img src="<?= e($theme['faviconUrl']) ?>" alt="Favicon preview" style="width:24px;height:24px;object-fit:contain;border:1px solid var(--cv-border-default);border-radius:4px;padding:2px;background:#fff;">
                    <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);">Current: <?= e($theme['faviconUrl']) ?></span>
                </div>
            <?php endif; ?>
            <input class="cv-input" type="file" name="favicon_file" accept=".ico,.png,.jpg,.jpeg,.gif,.svg,.webp" style="margin-bottom:8px;">
            <input class="cv-input" type="text" name="favicon_url" value="<?= e((string) ($theme['faviconUrl'] ?? '')) ?>" placeholder="Or enter direct URL (e.g. /uploads/favicon.png or https://example.com/favicon.ico)">
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);">
                Upload an icon file (.ico, .png, .jpg, .svg) or provide a direct image URL to customize the browser tab icon.
            </p>
        </div>
        <div class="cv-field">
            <label class="cv-label">Primary Color</label>
            <input class="cv-input" type="color" name="primary_color" value="<?= e($theme['primaryColor']) ?>" style="width:5rem;height:2.5rem;padding:0;">
        </div>
        <div class="cv-field">
            <label class="cv-label">Terms of Service URL (optional)</label>
            <input class="cv-input" type="url" name="terms_url" value="<?= e((string) ($theme['termsUrl'] ?? '')) ?>" placeholder="https://yourdomain.com/terms">
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);">
                Full URL of your Terms of Service page (usually on your primary website). Every &ldquo;Terms of Service&rdquo; link in the store and client area points here.
            </p>
        </div>
        <button class="cv-btn" type="submit">Save Theme</button>
    </form>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-3);">
        Applies to both the client area and this admin panel — buttons, links, and accents everywhere use the primary color.
    </p>
</div>
