<?php
/** @var array<string, mixed>|null $banner */
use CodeVault\Marketing\PromoBannerTemplates;

$banner ??= active_promo_banner();

if ($banner === null) {
    return;
}

$design = PromoBannerTemplates::get((string) $banner['template']);
?>
<div class="cv-promo-banner" id="cv-promo-banner-<?= (int) $banner['id'] ?>" data-promo-banner data-promo-banner-id="<?= (int) $banner['id'] ?>" hidden>
    <div class="cv-promo-banner__backdrop" data-promo-banner-dismiss></div>
    <div class="cv-promo-banner__modal" role="dialog" aria-modal="true" aria-label="Promotional offer">
        <button type="button" class="cv-promo-banner__close" data-promo-banner-dismiss aria-label="Close">&times;</button>
        <div class="cv-promo-banner__panel" style="background:<?= e($design['panelGradient']) ?>;">
            <div class="cv-promo-banner__icon"><?= e($design['icon']) ?></div>
            <?php if (!empty($banner['eyebrow_text'])): ?>
                <div class="cv-promo-banner__eyebrow"><?= e((string) $banner['eyebrow_text']) ?></div>
            <?php endif; ?>
        </div>
        <div class="cv-promo-banner__content">
            <h2 class="cv-promo-banner__headline"><?= e((string) $banner['headline']) ?></h2>
            <?php if (!empty($banner['subtext'])): ?>
                <p class="cv-promo-banner__subtext"><?= e((string) $banner['subtext']) ?></p>
            <?php endif; ?>
            <div class="cv-promo-banner__code"><?= e((string) $banner['coupon_code']) ?></div>
            <form method="post" action="/promo-banners/<?= (int) $banner['id'] ?>/apply">
                <?= csrf_field() ?>
                <button type="submit" class="cv-promo-banner__cta" style="background:<?= e($design['ctaColor']) ?>;color:<?= e($design['ctaTextColor']) ?>;">
                    <?= e((string) $banner['cta_text']) ?>
                </button>
            </form>
        </div>
    </div>
</div>
<style>
.cv-promo-banner { position: fixed; inset: 0; z-index: 9998; display: flex; align-items: center; justify-content: center; padding: var(--cv-space-4); }
.cv-promo-banner[hidden] { display: none; }
.cv-promo-banner__backdrop { position: absolute; inset: 0; background: rgba(15, 15, 20, 0.6); }
.cv-promo-banner__modal { position: relative; z-index: 1; width: 100%; max-width: 640px; max-height: 90vh; overflow-y: auto; background: var(--cv-bg-surface); border-radius: 16px; box-shadow: 0 24px 64px rgba(0,0,0,.35); display: flex; }
.cv-promo-banner__close { position: absolute; top: 10px; right: 14px; z-index: 2; background: none; border: none; font-size: 1.75rem; line-height: 1; color: #ffffff; cursor: pointer; text-shadow: 0 1px 3px rgba(0,0,0,.4); }
.cv-promo-banner__panel { flex: 0 0 42%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: var(--cv-space-6) var(--cv-space-4); color: #ffffff; }
.cv-promo-banner__icon { font-size: 3rem; margin-bottom: var(--cv-space-2); }
.cv-promo-banner__eyebrow { font-weight: 800; font-size: var(--cv-text-lg); line-height: 1.3; }
.cv-promo-banner__content { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: var(--cv-space-3); padding: var(--cv-space-6) var(--cv-space-5); }
.cv-promo-banner__headline { margin: 0; font-size: var(--cv-text-xl); font-weight: 800; color: var(--cv-text-primary); }
.cv-promo-banner__subtext { margin: 0; color: var(--cv-text-secondary); font-size: var(--cv-text-sm); }
.cv-promo-banner__code { border: 2px dashed var(--cv-border-default); border-radius: 8px; padding: var(--cv-space-3) var(--cv-space-5); font-weight: 800; font-size: var(--cv-text-lg); letter-spacing: .08em; color: var(--cv-text-primary); }
.cv-promo-banner__cta { border: none; border-radius: 8px; padding: var(--cv-space-3) var(--cv-space-6); font-weight: 700; font-size: var(--cv-text-base); cursor: pointer; }
.cv-promo-banner__cta:hover { filter: brightness(1.08); }
@media (max-width: 640px) {
    .cv-promo-banner__modal { flex-direction: column; max-width: 420px; }
    .cv-promo-banner__panel { flex: 0 0 auto; padding: var(--cv-space-5) var(--cv-space-4) var(--cv-space-4); }
    .cv-promo-banner__icon { font-size: 2.25rem; margin-bottom: var(--cv-space-1); }
}
</style>
