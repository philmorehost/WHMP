<?php
/**
 * A store's own promo codes and promo popup.
 *
 * Everything listed here belongs to THIS store only: the codes work only at this
 * store's checkout, and the popup appears only on this store's website. The
 * platform's own offers never appear on the storefront, and nothing here appears
 * anywhere else.
 *
 * @var CodeVault\View $view
 * @var array<string, mixed> $store
 * @var array<int, array<string, mixed>> $promotions
 * @var array<int, array<string, mixed>> $banners
 * @var array<string, array{label: string, icon: string, panelGradient: string, ctaColor: string, ctaTextColor: string}> $templates
 * @var array<string, string> $pages
 * @var string|null $formError
 * @var string|null $notice
 * @var string|null $error
 */

use CodeVault\Marketing\PromoBannerPages;

$brand = trim((string) ($store['brand_name'] ?? ''));
$brand = $brand !== '' ? $brand : 'your store';
$today = (new DateTimeImmutable())->format('Y-m-d');
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Promotions</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary);">
        Run your own discounts on <strong><?= e($brand) ?></strong>. Create a promo code, then (optionally) a popup
        banner that advertises it — visitors click <em>Apply</em> and the code goes straight into their cart.
    </p>
    <p style="color:var(--cv-text-secondary);margin-bottom:0;">
        Your codes and banners are <strong>yours alone</strong>: they only work and only appear on your storefront.
        Our own platform offers never show on your website, and other resellers can't see or use yours. A discount
        comes out of your retail price — what you owe us for the order doesn't change.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($formError !== null && $formError !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $formError) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<style>
.rs-promo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--cv-space-3); }
.rs-promo-grid .rs-promo-wide { grid-column: 1 / -1; }
.rs-promo-field { display: flex; flex-direction: column; gap: 4px; }
.rs-promo-field label { font-size: var(--cv-text-sm); font-weight: 600; color: var(--cv-text-secondary); }
.rs-promo-pages { display: flex; flex-wrap: wrap; gap: var(--cv-space-3); }
.rs-promo-pages label { display: inline-flex; align-items: center; gap: 6px; font-weight: 500; color: var(--cv-text-primary); }
.rs-promo-swatch { display: inline-flex; align-items: center; gap: 6px; padding: 2px 10px; border-radius: 999px; font-size: var(--cv-text-sm); font-weight: 700; color: #fff; white-space: nowrap; }
.rs-promo-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.rs-promo-actions form { margin: 0; }
.rs-promo-table-wrap { overflow-x: auto; }
.rs-btn-sm { padding: 4px 10px; font-size: var(--cv-text-sm); }
</style>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" id="rs-code-form-title">Your promo codes</h2>
    <form method="post" action="/client/reseller/promotions/codes" id="rs-code-form">
        <?= csrf_field() ?>
        <div class="rs-promo-grid">
            <div class="rs-promo-field">
                <label for="rs-code">Code</label>
                <input class="cv-input" id="rs-code" name="code" maxlength="50" required placeholder="SAVE10"
                       pattern="[A-Za-z0-9_\-]{3,50}" style="text-transform:uppercase;">
            </div>
            <div class="rs-promo-field">
                <label for="rs-type">Discount type</label>
                <select class="cv-input" id="rs-type" name="type">
                    <option value="percentage">Percentage off</option>
                    <option value="fixed">Fixed amount off</option>
                </select>
            </div>
            <div class="rs-promo-field">
                <label for="rs-value">Discount</label>
                <input class="cv-input" id="rs-value" name="value" type="number" min="0.01" step="0.01" required placeholder="10">
            </div>
            <div class="rs-promo-field">
                <label for="rs-max">Usage limit (optional)</label>
                <input class="cv-input" id="rs-max" name="max_redemptions" type="number" min="1" step="1" placeholder="Unlimited">
            </div>
            <div class="rs-promo-field">
                <label for="rs-min">Minimum order (optional)</label>
                <input class="cv-input" id="rs-min" name="min_order_amount" type="number" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="rs-promo-field">
                <label for="rs-status">Status</label>
                <select class="cv-input" id="rs-status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Switched off</option>
                </select>
            </div>
            <div class="rs-promo-field">
                <label for="rs-starts">Starts (optional)</label>
                <input class="cv-input" id="rs-starts" name="starts_at" type="date">
            </div>
            <div class="rs-promo-field">
                <label for="rs-expires">Expires (optional)</label>
                <input class="cv-input" id="rs-expires" name="expires_at" type="date">
            </div>
        </div>
        <p style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);flex-wrap:wrap;">
            <button class="cv-btn" type="submit" data-edit-submit>Save code</button>
            <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
                    data-edit-cancel
                    data-edit-reset-action="/client/reseller/promotions/codes"
                    data-edit-reset-label="Save code"
                    data-edit-reset-title="Your promo codes"
                    data-edit-title-target="#rs-code-form-title">Cancel</button>
        </p>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
            Saving a code that already exists in your store updates it. A fixed amount is taken off in the same
            currency your prices are listed in.
        </p>
    </form>

    <div class="rs-promo-table-wrap">
        <table class="cv-table">
            <thead>
            <tr><th>Code</th><th>Discount</th><th>Used</th><th>Minimum order</th><th>Window</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($promotions as $promotion): ?>
                <?php
                $isActive = ($promotion['status'] ?? 'active') === 'active';
                $expired = !empty($promotion['expires_at']) && (string) $promotion['expires_at'] < $today;
                $discount = ($promotion['type'] ?? '') === 'percentage'
                    ? rtrim(rtrim(number_format((float) $promotion['value'], 2, '.', ''), '0'), '.') . '%'
                    : number_format((float) $promotion['value'], 2) . ' off';
                ?>
                <tr>
                    <td><code><?= e((string) $promotion['code']) ?></code></td>
                    <td><?= e($discount) ?></td>
                    <td><?= (int) $promotion['redemption_count'] ?><?= $promotion['max_redemptions'] !== null ? ' / ' . (int) $promotion['max_redemptions'] : '' ?></td>
                    <td><?= (float) $promotion['min_order_amount'] > 0 ? e(number_format((float) $promotion['min_order_amount'], 2)) : '—' ?></td>
                    <td style="white-space:nowrap;"><?= e((string) ($promotion['starts_at'] ?? 'any')) ?> → <?= e((string) ($promotion['expires_at'] ?? '∞')) ?></td>
                    <td>
                        <?php if ($expired): ?>
                            <span class="cv-badge">Expired</span>
                        <?php elseif ($isActive): ?>
                            <span class="cv-badge cv-badge--success">Active</span>
                        <?php else: ?>
                            <span class="cv-badge">Off</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="rs-promo-actions">
                            <button type="button" class="cv-btn cv-btn--secondary rs-btn-sm"
                                    data-edit-trigger
                                    data-edit-form="#rs-code-form"
                                    data-edit-fields="<?= e((string) json_encode([
                                        'code' => (string) $promotion['code'],
                                        'type' => (string) $promotion['type'],
                                        'value' => number_format((float) $promotion['value'], 2, '.', ''),
                                        'max_redemptions' => $promotion['max_redemptions'] !== null ? (string) (int) $promotion['max_redemptions'] : '',
                                        'min_order_amount' => (float) $promotion['min_order_amount'] > 0 ? number_format((float) $promotion['min_order_amount'], 2, '.', '') : '',
                                        'status' => (string) $promotion['status'],
                                        'starts_at' => (string) ($promotion['starts_at'] ?? ''),
                                        'expires_at' => (string) ($promotion['expires_at'] ?? ''),
                                    ])) ?>"
                                    data-edit-action="/client/reseller/promotions/codes"
                                    data-edit-submit-label="Update code"
                                    data-edit-title="Edit promo code"
                                    data-edit-title-target="#rs-code-form-title">Edit</button>
                            <form method="post" action="/client/reseller/promotions/codes/<?= (int) $promotion['id'] ?>/toggle">
                                <?= csrf_field() ?>
                                <button type="submit" class="cv-btn cv-btn--secondary rs-btn-sm"><?= $isActive ? 'Switch off' : 'Switch on' ?></button>
                            </form>
                            <form method="post" action="/client/reseller/promotions/codes/<?= (int) $promotion['id'] ?>/delete"
                                  data-confirm="Delete <?= e((string) $promotion['code']) ?>? Banners advertising it will stop working.">
                                <?= csrf_field() ?>
                                <button type="submit" class="cv-btn cv-btn--secondary rs-btn-sm">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($promotions === []): ?>
                <tr><td colspan="7" style="color:var(--cv-text-secondary);text-align:center;padding:var(--cv-space-5);">
                    No promo codes yet. Create one above — for example <code>SAVE10</code> for 10% off.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" id="rs-banner-form-title">New discount banner</h2>
    <?php if ($promotions === []): ?>
        <p style="color:var(--cv-text-secondary);">Create a promo code first — a banner advertises one of your codes.</p>
    <?php else: ?>
        <form method="post" action="/client/reseller/promotions/banners" id="rs-banner-form">
            <?= csrf_field() ?>
            <div class="rs-promo-grid">
                <div class="rs-promo-field">
                    <label for="rs-b-name">Internal name</label>
                    <input class="cv-input" id="rs-b-name" name="name" maxlength="100" required placeholder="Spring sale popup">
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-template">Design</label>
                    <select class="cv-input" id="rs-b-template" name="template">
                        <?php foreach ($templates as $key => $tpl): ?>
                            <option value="<?= e($key) ?>"><?= e($tpl['icon'] . ' ' . $tpl['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-code">Promo code</label>
                    <select class="cv-input" id="rs-b-code" name="coupon_code" required>
                        <?php foreach ($promotions as $promotion): ?>
                            <option value="<?= e((string) $promotion['code']) ?>"><?= e((string) $promotion['code']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-cta">Button text</label>
                    <input class="cv-input" id="rs-b-cta" name="cta_text" maxlength="40" placeholder="Apply Now">
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-eyebrow">Small text above the headline (optional)</label>
                    <input class="cv-input" id="rs-b-eyebrow" name="eyebrow_text" maxlength="120" placeholder="Limited time">
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-headline">Headline</label>
                    <input class="cv-input" id="rs-b-headline" name="headline" maxlength="150" required placeholder="Get 10% off your first order!">
                </div>
                <div class="rs-promo-field rs-promo-wide">
                    <label for="rs-b-subtext">Subtext (optional)</label>
                    <input class="cv-input" id="rs-b-subtext" name="subtext" maxlength="300" placeholder="Valid on all hosting plans this month.">
                </div>
                <div class="rs-promo-field rs-promo-wide">
                    <label>Show on</label>
                    <div class="rs-promo-pages">
                        <label><input type="checkbox" name="target_pages[]" value="<?= e(PromoBannerPages::ALL) ?>" checked> All pages</label>
                        <?php foreach ($pages as $key => $label): ?>
                            <label><input type="checkbox" name="target_pages[]" value="<?= e($key) ?>"> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-starts">Starts (optional)</label>
                    <input class="cv-input" id="rs-b-starts" type="date" name="starts_at">
                </div>
                <div class="rs-promo-field">
                    <label for="rs-b-expires">Expires (optional)</label>
                    <input class="cv-input" id="rs-b-expires" type="date" name="expires_at">
                </div>
            </div>
            <p style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);flex-wrap:wrap;">
                <button class="cv-btn" type="submit" data-edit-submit>Create banner</button>
                <button class="cv-btn cv-btn--secondary" type="button" style="display:none;"
                        data-edit-cancel
                        data-edit-reset-action="/client/reseller/promotions/banners"
                        data-edit-reset-label="Create banner"
                        data-edit-reset-title="New discount banner"
                        data-edit-title-target="#rs-banner-form-title">Cancel</button>
            </p>
            <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                Only one banner shows at a time (the newest active one for the page), and each visitor sees it at most
                once a day.
            </p>
        </form>
    <?php endif; ?>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Your banners</h2>
    <div class="rs-promo-table-wrap">
        <table class="cv-table">
            <thead>
            <tr><th>Name</th><th>Design</th><th>Code</th><th>Shown on</th><th>Status</th><th>Stats</th><th>Window</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($banners as $banner): ?>
                <?php
                $tpl = $templates[$banner['template']] ?? reset($templates);
                $targetPages = json_decode((string) $banner['target_pages'], true) ?: [];
                $pageLabels = in_array(PromoBannerPages::ALL, $targetPages, true)
                    ? 'All pages'
                    : implode(', ', array_map(static fn ($k) => $pages[(string) $k] ?? (string) $k, $targetPages));
                $bannerActive = ($banner['status'] ?? '') === 'active';
                ?>
                <tr>
                    <td><strong><?= e((string) $banner['name']) ?></strong><br>
                        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) $banner['headline']) ?></span></td>
                    <td><span class="rs-promo-swatch" style="background:<?= e($tpl['panelGradient']) ?>;"><?= e($tpl['icon'] . ' ' . $tpl['label']) ?></span></td>
                    <td><code><?= e((string) $banner['coupon_code']) ?></code></td>
                    <td style="font-size:var(--cv-text-sm);"><?= e($pageLabels) ?></td>
                    <td>
                        <?php if ($bannerActive): ?>
                            <span class="cv-badge cv-badge--success">Live</span>
                        <?php else: ?>
                            <span class="cv-badge">Paused</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--cv-text-sm);white-space:nowrap;"><?= number_format((int) $banner['impressions']) ?> shown · <?= number_format((int) $banner['clicks']) ?> clicked</td>
                    <td style="font-size:var(--cv-text-sm);white-space:nowrap;"><?= e((string) ($banner['starts_at'] ?? 'any')) ?> → <?= e((string) ($banner['expires_at'] ?? '∞')) ?></td>
                    <td>
                        <div class="rs-promo-actions">
                            <?php if ($promotions !== []): ?>
                                <button type="button" class="cv-btn cv-btn--secondary rs-btn-sm"
                                        data-edit-trigger
                                        data-edit-form="#rs-banner-form"
                                        data-edit-fields="<?= e((string) json_encode([
                                            'name' => (string) $banner['name'],
                                            'template' => (string) $banner['template'],
                                            'coupon_code' => (string) $banner['coupon_code'],
                                            'cta_text' => (string) $banner['cta_text'],
                                            'eyebrow_text' => (string) ($banner['eyebrow_text'] ?? ''),
                                            'headline' => (string) $banner['headline'],
                                            'subtext' => (string) ($banner['subtext'] ?? ''),
                                            'starts_at' => (string) ($banner['starts_at'] ?? ''),
                                            'expires_at' => (string) ($banner['expires_at'] ?? ''),
                                            'target_pages' => array_values(array_map('strval', $targetPages)),
                                        ])) ?>"
                                        data-edit-action="/client/reseller/promotions/banners/<?= (int) $banner['id'] ?>/update"
                                        data-edit-submit-label="Update banner"
                                        data-edit-title="Edit discount banner"
                                        data-edit-title-target="#rs-banner-form-title">Edit</button>
                            <?php endif; ?>
                            <form method="post" action="/client/reseller/promotions/banners/<?= (int) $banner['id'] ?>/<?= $bannerActive ? 'pause' : 'resume' ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="cv-btn cv-btn--secondary rs-btn-sm"><?= $bannerActive ? 'Pause' : 'Resume' ?></button>
                            </form>
                            <form method="post" action="/client/reseller/promotions/banners/<?= (int) $banner['id'] ?>/delete" data-confirm="Delete this banner?">
                                <?= csrf_field() ?>
                                <button type="submit" class="cv-btn cv-btn--secondary rs-btn-sm">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($banners === []): ?>
                <tr><td colspan="8" style="color:var(--cv-text-secondary);text-align:center;padding:var(--cv-space-5);">
                    No banners yet — your storefront shows no promo popup until you create one.
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
