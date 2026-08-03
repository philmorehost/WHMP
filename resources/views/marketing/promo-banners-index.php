<?php
/** @var array<int, array<string, mixed>> $banners */
/** @var array<int, array<string, mixed>> $promotions */
/** @var array<string, array{label: string, icon: string, panelGradient: string, ctaColor: string, ctaTextColor: string}> $templates */
/** @var array<string, string> $pages */
/** @var string|null $error */
/** @var bool $saved */

use CodeVault\Marketing\PromoBannerPages;
?>
<style>
.admin-promo-banner-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
}
.admin-promo-banner-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px;
}
.admin-promo-banner-hero__subtitle {
    color: rgba(255,255,255,.7);
    font-size: .95rem;
    max-width: 640px;
    margin: 0;
}
.admin-promo-banner-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-promo-banner-card__title {
    font-weight: 800;
    font-size: 1.15rem;
    color: var(--cv-text-primary);
    padding: 20px 24px;
    border-bottom: 1px solid var(--cv-border-default);
    margin: 0;
}
.admin-promo-banner-card__body { padding: 24px; }
.admin-promo-banner-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }
.admin-promo-banner-field { display: flex; flex-direction: column; gap: 6px; }
.admin-promo-banner-field--wide { grid-column: 1 / -1; }
.admin-promo-banner-field label { font-size: .8rem; font-weight: 700; color: var(--cv-text-secondary); text-transform: uppercase; letter-spacing: .04em; }
.admin-promo-banner-field input, .admin-promo-banner-field select, .admin-promo-banner-field textarea {
    padding: 8px 12px; border: 1px solid var(--cv-border-default); border-radius: 6px;
    background: var(--cv-bg-surface); color: var(--cv-text-primary); font-size: .9rem; font-family: inherit;
}
.admin-promo-banner-pages { display: flex; flex-wrap: wrap; gap: 12px; }
.admin-promo-banner-pages label { display: flex; align-items: center; gap: 6px; font-weight: 500; text-transform: none; font-size: .85rem; color: var(--cv-text-primary); }
.admin-promo-banner-copilot { background: var(--cv-bg-surface-sunken); border: 1px dashed var(--cv-border-default); border-radius: 8px; padding: 16px; margin: 20px 0; }
.admin-promo-banner-copilot__row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 10px; }
.admin-promo-banner-btn { background: linear-gradient(135deg, #3b82f6, #2563eb); color: #fff; border: none; border-radius: 8px; padding: 9px 18px; font-weight: 700; font-size: .85rem; cursor: pointer; }
.admin-promo-banner-btn--ghost { background: var(--cv-bg-surface); color: var(--cv-text-primary); border: 1px solid var(--cv-border-default); }
.admin-promo-banner-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.admin-promo-banner-table th { text-align: left; padding: 10px 14px; font-size: .75rem; text-transform: uppercase; color: var(--cv-text-secondary); border-bottom: 2px solid var(--cv-border-default); }
.admin-promo-banner-table td { padding: 10px 14px; border-bottom: 1px solid var(--cv-border-default); vertical-align: top; }
.admin-promo-banner-swatch { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 20px; font-size: .78rem; font-weight: 700; color: #fff; }
.admin-promo-banner-badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
.admin-promo-banner-badge--active { background: rgba(16,185,129,.18); color: #10b981; }
.admin-promo-banner-badge--paused { background: rgba(107,114,128,.18); color: #6b7280; }
.admin-promo-banner-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.admin-promo-banner-actions form { margin: 0; }
.admin-promo-banner-actions button { border-radius: 6px; padding: 5px 10px; font-size: .75rem; font-weight: 600; cursor: pointer; border: 1px solid var(--cv-border-default); background: var(--cv-bg-surface); color: var(--cv-text-primary); }
</style>

<div class="admin-promo-banner-hero">
    <h1 class="admin-promo-banner-hero__title">🎁 Promo Banners</h1>
    <p class="admin-promo-banner-hero__subtitle">
        Popup ads advertising a coupon code, shown on the public pages you choose. Clicking "Apply" hands the
        code straight to the visitor's cart — it stays applied through signup and for the rest of the day,
        so they never have to type it in.
    </p>
</div>

<?php if ($error !== null): ?>
    <div style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#b91c1c;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;">
        ⚠️ <?= e($error) ?>
    </div>
<?php endif; ?>

<?php if ($saved): ?>
    <div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.35);color:#047857;padding:14px 18px;border-radius:10px;margin-bottom:20px;font-weight:600;">
        ✅ Banner saved.
    </div>
<?php endif; ?>

<div class="admin-promo-banner-card">
    <h2 class="admin-promo-banner-card__title" id="promo-banner-form-title">➕ New Promo Banner</h2>
    <div class="admin-promo-banner-card__body">
        <form id="promo-banner-form" method="post" action="/admin/promo-banners">
            <?= csrf_field() ?>
            <div class="admin-promo-banner-form">
                <div class="admin-promo-banner-field">
                    <label>Internal Name</label>
                    <input name="name" placeholder="Spring sale popup" required>
                </div>
                <div class="admin-promo-banner-field">
                    <label>Design Template</label>
                    <select name="template">
                        <?php foreach ($templates as $key => $tpl): ?>
                            <option value="<?= e($key) ?>"><?= e($tpl['icon'] . ' ' . $tpl['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-promo-banner-field">
                    <label>Coupon Code</label>
                    <input name="coupon_code" list="promo-banner-codes" placeholder="SUMMER20" data-promo-copilot-coupon required>
                    <datalist id="promo-banner-codes">
                        <?php foreach ($promotions as $promotion): ?>
                            <option value="<?= e((string) $promotion['code']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="admin-promo-banner-field">
                    <label>Button Text</label>
                    <input name="cta_text" placeholder="Apply Now" data-promo-cta>
                </div>
                <div class="admin-promo-banner-field">
                    <label>Eyebrow Text (small, above headline)</label>
                    <input name="eyebrow_text" placeholder="40,000+ Customers" data-promo-eyebrow>
                </div>
                <div class="admin-promo-banner-field">
                    <label>Headline</label>
                    <input name="headline" placeholder="Enjoy your 20% promo code!" data-promo-headline required>
                </div>
                <div class="admin-promo-banner-field admin-promo-banner-field--wide">
                    <label>Subtext (optional)</label>
                    <input name="subtext" placeholder="Valid on all plans through the end of the month." data-promo-subtext>
                </div>
                <div class="admin-promo-banner-field admin-promo-banner-field--wide">
                    <label>Show On</label>
                    <div class="admin-promo-banner-pages">
                        <label><input type="checkbox" name="target_pages[]" value="<?= e(PromoBannerPages::ALL) ?>" checked> All pages</label>
                        <?php foreach ($pages as $key => $label): ?>
                            <label><input type="checkbox" name="target_pages[]" value="<?= e($key) ?>"> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="admin-promo-banner-field">
                    <label>Starts (optional)</label>
                    <input type="date" name="starts_at">
                </div>
                <div class="admin-promo-banner-field">
                    <label>Expires (optional)</label>
                    <input type="date" name="expires_at">
                </div>
            </div>

            <div class="admin-promo-banner-copilot" data-promo-copilot>
                <div class="admin-promo-banner-copilot__row">
                    <div class="admin-promo-banner-field">
                        <label>Discount, for the AI to describe (not saved)</label>
                        <input placeholder="20% off, first 3 months" data-promo-copilot-discount>
                    </div>
                    <div class="admin-promo-banner-field">
                        <label>Extra context (optional)</label>
                        <input placeholder="Targeting VPS shoppers" data-promo-copilot-brief>
                    </div>
                </div>
                <button type="button" class="admin-promo-banner-btn--ghost" style="border-radius:8px;padding:8px 16px;cursor:pointer;" data-promo-copilot-action>✨ Write copy with AI</button>
                <span data-promo-copilot-status style="font-size:.8rem;color:var(--cv-text-secondary);margin-left:10px;"></span>
            </div>

            <div style="display:flex;gap:12px;">
                <button class="admin-promo-banner-btn" type="submit" data-edit-submit>➕ Create Banner</button>
                <button class="admin-promo-banner-btn--ghost" type="button" style="display:none;border-radius:8px;padding:9px 18px;"
                    data-edit-cancel
                    data-edit-reset-action="/admin/promo-banners"
                    data-edit-reset-label="➕ Create Banner"
                    data-edit-reset-title="➕ New Promo Banner"
                    data-edit-title-target="#promo-banner-form-title">Cancel</button>
            </div>
            <p style="font-size:.8rem;color:var(--cv-text-secondary);margin-top:10px;">
                The coupon code must already exist under Billing → Promotions — a banner only advertises a code, it doesn't create one.
            </p>
        </form>
    </div>
</div>

<div class="admin-promo-banner-card">
    <h2 class="admin-promo-banner-card__title">📋 Banners</h2>
    <div style="overflow-x:auto;">
        <table class="admin-promo-banner-table">
            <thead>
                <tr><th>Name</th><th>Design</th><th>Coupon</th><th>Shown On</th><th>Status</th><th>Stats</th><th>Window</th><th style="width:220px;">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($banners as $banner): ?>
                <?php
                $tpl = $templates[$banner['template']] ?? reset($templates);
                $targetPages = json_decode((string) $banner['target_pages'], true) ?: [];
                $pageLabels = in_array(PromoBannerPages::ALL, $targetPages, true)
                    ? 'All pages'
                    : implode(', ', array_map(static fn (string $k) => $pages[$k] ?? $k, $targetPages));
                ?>
                <tr>
                    <td><strong><?= e((string) $banner['name']) ?></strong></td>
                    <td><span class="admin-promo-banner-swatch" style="background:<?= e($tpl['panelGradient']) ?>;"><?= e($tpl['icon']) ?> <?= e($tpl['label']) ?></span></td>
                    <td><code><?= e((string) $banner['coupon_code']) ?></code></td>
                    <td style="font-size:.82rem;"><?= e($pageLabels) ?></td>
                    <td>
                        <?php if ($banner['status'] === 'active'): ?>
                            <span class="admin-promo-banner-badge admin-promo-banner-badge--active">Active</span>
                        <?php else: ?>
                            <span class="admin-promo-banner-badge admin-promo-banner-badge--paused">Paused</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;white-space:nowrap;"><?= number_format((int) $banner['impressions']) ?> shown &middot; <?= number_format((int) $banner['clicks']) ?> clicked</td>
                    <td style="font-size:.82rem;white-space:nowrap;">
                        <?= $banner['starts_at'] !== null ? e((string) $banner['starts_at']) : 'any' ?> → <?= $banner['expires_at'] !== null ? e((string) $banner['expires_at']) : '∞' ?>
                    </td>
                    <td>
                        <div class="admin-promo-banner-actions">
                            <button type="button"
                                data-edit-trigger
                                data-edit-form="#promo-banner-form"
                                data-edit-fields="<?= e(json_encode([
                                    'name' => $banner['name'],
                                    'template' => $banner['template'],
                                    'coupon_code' => $banner['coupon_code'],
                                    'cta_text' => $banner['cta_text'],
                                    'eyebrow_text' => (string) ($banner['eyebrow_text'] ?? ''),
                                    'headline' => $banner['headline'],
                                    'subtext' => (string) ($banner['subtext'] ?? ''),
                                    'starts_at' => (string) ($banner['starts_at'] ?? ''),
                                    'expires_at' => (string) ($banner['expires_at'] ?? ''),
                                    'target_pages' => $targetPages,
                                ])) ?>"
                                data-edit-action="/admin/promo-banners/<?= (int) $banner['id'] ?>/update"
                                data-edit-submit-label="💾 Update Banner"
                                data-edit-title="✏️ Edit Promo Banner"
                                data-edit-title-target="#promo-banner-form-title">✏️ Edit</button>
                            <form method="post" action="/admin/promo-banners/<?= (int) $banner['id'] ?>/<?= $banner['status'] === 'active' ? 'pause' : 'resume' ?>">
                                <?= csrf_field() ?>
                                <button type="submit"><?= $banner['status'] === 'active' ? '⏸️ Pause' : '▶️ Resume' ?></button>
                            </form>
                            <form method="post" action="/admin/promo-banners/<?= (int) $banner['id'] ?>/delete" data-confirm="Delete this promo banner?">
                                <?= csrf_field() ?>
                                <button type="submit">🗑️ Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($banners === []): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--cv-text-secondary);padding:32px;">No promo banners yet. Create one above.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
