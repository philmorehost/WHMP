<?php
/** @var array{brandName: string, logoUrl: ?string, primaryColor: string, primaryColorDark: string} $theme */
/** @var string|null $error */
/** @var bool $saved */
/** @var array{design: string, headline: string, tagline: string, trust: string}|null $website */
/** @var bool|null $websiteSaved */
use CodeVault\Theme\PlatformSite;

$website ??= ['design' => PlatformSite::DESIGN_PREMIUM, 'headline' => '', 'tagline' => '', 'trust' => ''];
$websiteSaved ??= false;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Theme</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<?php if ($saved): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <p style="color:var(--cv-color-success-600, #1a7f37);"><?= $websiteSaved ? 'Website design saved.' : 'Theme saved.' ?></p>
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

<div class="cv-card" id="website-design" style="margin-top:var(--cv-space-4);">
    <h2 class="cv-card__title">Website design</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:0;">
        The look of your main website — home page, store, domains, cart, deals, sign-in and the client area.
        Reseller stores are not affected: they always use their own storefront design and their own words.
    </p>
    <form method="post" action="/admin/theme/website"><?= csrf_field() ?>
        <div class="cv-field">
            <span class="cv-label">Design</span>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:var(--cv-space-3);">
                <?php foreach ([
                    PlatformSite::DESIGN_PREMIUM => ['Premium (recommended)', 'Modern hosting design: hero, domain search, services grid, pricing tabs, guarantees, FAQ and a four-column footer.'],
                    PlatformSite::DESIGN_CLASSIC => ['Classic', 'The previous layout with the plain top bar and the product list on the home page.'],
                ] as $value => [$label, $hint]): ?>
                    <label style="display:flex;gap:var(--cv-space-3);align-items:flex-start;padding:var(--cv-space-4);border:1px solid var(--cv-border-default);border-radius:var(--cv-radius-md);cursor:pointer;<?= $website['design'] === $value ? 'border-color:var(--cv-color-brand-500);box-shadow:0 0 0 3px color-mix(in srgb, var(--cv-color-brand-500) 18%, transparent);' : '' ?>">
                        <input type="radio" name="site_design" value="<?= e($value) ?>" <?= $website['design'] === $value ? 'checked' : '' ?> style="margin-top:3px;">
                        <span><strong><?= e($label) ?></strong><br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);"><?= e($hint) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="cv-field">
            <label class="cv-label" for="home_headline">Home page headline</label>
            <input class="cv-input" id="home_headline" name="home_headline" maxlength="120" value="<?= e($website['headline']) ?>" placeholder="<?= e(PlatformSite::DEFAULT_HEADLINE) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label" for="home_tagline">Home page tagline</label>
            <textarea class="cv-input" id="home_tagline" name="home_tagline" rows="3" maxlength="320" placeholder="<?= e(PlatformSite::DEFAULT_TAGLINE) ?>"><?= e($website['tagline']) ?></textarea>
        </div>
        <div class="cv-field">
            <label class="cv-label" for="trust_line">Line under &ldquo;Why customers choose us&rdquo;</label>
            <input class="cv-input" id="trust_line" name="trust_line" maxlength="160" value="<?= e($website['trust']) ?>" placeholder="<?= e(PlatformSite::DEFAULT_TRUST) ?>">
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);margin-top:var(--cv-space-1);">
                Leave any field empty to use the default shown. Contact details in the header and footer come from
                Settings &rarr; Company (email, phone, WhatsApp, address).
            </p>
        </div>
        <button class="cv-btn" type="submit">Save website design</button>
        <a href="/" target="_blank" rel="noopener" class="cv-btn cv-btn--secondary" style="margin-left:var(--cv-space-2);">View website</a>
    </form>
</div>
