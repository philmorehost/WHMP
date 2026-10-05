<?php
/**
 * Super admin: the Free Reseller programme — is it open, where is it advertised,
 * what the landing page says, and which demo stores prospects can open.
 *
 * @var bool $enabled
 * @var bool $clientAdvert
 * @var bool $publicBanner
 * @var string $headline
 * @var string $tagline
 * @var string $bannerText
 * @var array<int, array{label: string, url: string}> $demoLinks
 * @var array{stores: int, last30: int, pendingDomains: int} $stats
 * @var array<string, mixed> $rules
 * @var string|null $notice
 * @var string|null $error
 */
use CodeVault\Reseller\FreeResellerProgramme;

$icon = static fn (string $paths): string => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
$rows = $demoLinks;
while (count($rows) < FreeResellerProgramme::MAX_DEMO_LINKS) {
    $rows[] = ['label' => '', 'url' => ''];
}
$pct = static fn (float $v): string => rtrim(rtrim(number_format($v, 2), '0'), '.') . '%';
?>
<link rel="stylesheet" href="/assets/css/reseller.css">
<style>
.frp-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: var(--cv-space-4); align-items: start; }
.frp-switches { display: grid; gap: 12px; }
.frp-switch { display: flex; gap: 14px; align-items: flex-start; padding: 14px 16px; border: 1px solid var(--cv-border-default); border-radius: 12px; background: var(--cv-bg-surface); cursor: pointer; }
.frp-switch:has(input:checked) { border-color: var(--cv-color-brand-500); background: color-mix(in srgb, var(--cv-color-brand-500) 6%, var(--cv-bg-surface)); }
.frp-switch input { width: 18px; height: 18px; margin-top: 3px; flex-shrink: 0; accent-color: var(--cv-color-brand-500); }
.frp-switch strong { display: block; }
.frp-switch span { color: var(--cv-text-secondary); font-size: var(--cv-text-sm); }
.frp-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.frp-field label { font-weight: 700; font-size: var(--cv-text-sm); }
.frp-field small { color: var(--cv-text-secondary); font-size: var(--cv-text-xs); }
.frp-field input, .frp-field textarea { width: 100%; box-sizing: border-box; }
.frp-demo { display: grid; grid-template-columns: 34px minmax(0, 1fr) minmax(0, 1.6fr); gap: 10px; align-items: center; margin-bottom: 10px; }
.frp-demo__n { width: 30px; height: 30px; border-radius: 8px; background: var(--cv-bg-surface-sunken); display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: var(--cv-text-sm); color: var(--cv-text-secondary); }
.frp-rules { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; font-size: var(--cv-text-sm); }
.frp-rules li { display: flex; justify-content: space-between; gap: 10px; border-bottom: 1px dashed var(--cv-border-default); padding-bottom: 8px; }
.frp-rules li span { color: var(--cv-text-secondary); }
.frp-rules a { font-size: var(--cv-text-xs); }
.frp-status { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; font-weight: 800; font-size: var(--cv-text-xs); letter-spacing: .04em; }
.frp-status--on { background: rgba(34,197,94,.14); color: #15803d; }
.frp-status--off { background: rgba(239,68,68,.12); color: #b91c1c; }
.frp-status i { width: 8px; height: 8px; border-radius: 50%; background: currentColor; display: block; }
@media (max-width: 1000px) { .frp-grid { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .frp-demo { grid-template-columns: 1fr; } .frp-demo__n { display: none; } }
</style>

<section class="rs-welcome rs-welcome--admin" aria-labelledby="frp-title">
    <div class="rs-welcome__body">
        <p class="rs-eyebrow">Reseller programme</p>
        <header class="rs-head">
            <h1 class="rs-head__title" id="frp-title">Free Reseller programme</h1>
            <p class="rs-head__lede">Advertise free reseller websites to your clients and visitors. The public page explains how
                resellers earn, get paid and go live, using your live programme rules. Adverts only ever appear on
                <strong>your</strong> website, never on a reseller's store.</p>
        </header>
        <div class="rs-welcome__actions">
            <a class="cv-btn" href="/free-reseller" target="_blank" rel="noopener"><?= $icon('<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/>') ?> View public page</a>
            <a class="cv-btn cv-btn--secondary" href="/free-reseller/apply" target="_blank" rel="noopener">View application form</a>
            <span class="frp-status <?= $enabled ? 'frp-status--on' : 'frp-status--off' ?>"><i></i><?= $enabled ? 'OPEN TO APPLICATIONS' : 'CLOSED' ?></span>
        </div>
    </div>
    <div class="rs-welcome__art" aria-hidden="true">
        <span class="rs-welcome__orb rs-welcome__orb--a"></span>
        <span class="rs-welcome__orb rs-welcome__orb--b"></span>
        <span class="rs-welcome__glyph"><?= $icon('<path d="M20 12v10H4V12"/><path d="M2 7h20v5H2z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>') ?></span>
    </div>
</section>

<?= $view->render('partials.reseller-admin-nav') ?>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="rs-stats rs-stats--kpi" style="margin-bottom:var(--cv-space-4);">
    <a class="rs-stat rs-stat--link" href="/admin/resellers#rs-stores">
        <span class="rs-stat__icon"><?= $icon('<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/>') ?></span>
        <div class="rs-stat__label">Reseller stores</div>
        <div class="rs-stat__value"><?= (int) $stats['stores'] ?></div>
        <div class="rs-stat__note">in total</div>
    </a>
    <div class="rs-stat rs-stat--teal">
        <span class="rs-stat__icon"><?= $icon('<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>') ?></span>
        <div class="rs-stat__label">New in 30 days</div>
        <div class="rs-stat__value"><?= (int) $stats['last30'] ?></div>
        <div class="rs-stat__note">stores opened</div>
    </div>
    <a class="rs-stat rs-stat--violet rs-stat--link" href="/admin/resellers/domains">
        <span class="rs-stat__icon"><?= $icon('<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>') ?></span>
        <div class="rs-stat__label">Domains awaiting approval</div>
        <div class="rs-stat__value"><?= (int) $stats['pendingDomains'] ?></div>
        <div class="rs-stat__note">review in Domains</div>
    </a>
</div>

<form method="post" action="/admin/resellers/free-programme">
    <?= csrf_field() ?>
    <div class="frp-grid">
        <div>
            <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
                <h2 class="cv-card__title">Availability &amp; adverts</h2>
                <div class="frp-switches">
                    <label class="frp-switch">
                        <input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <div><strong>Programme open</strong><span>Visitors can read the page at /free-reseller and apply. When closed, the page says applications are not open (you still see a preview while signed in as admin) and every advert is hidden.</span></div>
                    </label>
                    <label class="frp-switch">
                        <input type="checkbox" name="public_banner" value="1" <?= $publicBanner ? 'checked' : '' ?>>
                        <div><strong>Public banner on the website</strong><span>A "Get Free Reseller" strip at the top of your public pages, plus a section on the home page. Visitors can hide the strip for a week.</span></div>
                    </label>
                    <label class="frp-switch">
                        <input type="checkbox" name="client_advert" value="1" <?= $clientAdvert ? 'checked' : '' ?>>
                        <div><strong>Advert on client dashboards</strong><span>A card on the dashboard of your direct clients who do not already resell. Never shown to a reseller's customers.</span></div>
                    </label>
                </div>
                <p style="margin:12px 0 0;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">The "Free Reseller" link in the website menu follows "Programme open".</p>
            </div>

            <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
                <h2 class="cv-card__title">Landing page &amp; banner text</h2>
                <div class="frp-field">
                    <label for="frp-headline">Headline</label>
                    <input class="cv-input" id="frp-headline" name="headline" maxlength="<?= FreeResellerProgramme::HEADLINE_MAX ?>" value="<?= e($headline) ?>" placeholder="<?= e(FreeResellerProgramme::DEFAULT_HEADLINE) ?>">
                    <small>The big title at the top of the page and on the home page section. The word "free" is highlighted automatically.</small>
                </div>
                <div class="frp-field">
                    <label for="frp-tagline">Introduction</label>
                    <textarea class="cv-input" id="frp-tagline" name="tagline" rows="3" maxlength="<?= FreeResellerProgramme::TAGLINE_MAX ?>" placeholder="<?= e(FreeResellerProgramme::DEFAULT_TAGLINE) ?>"><?= e($tagline) ?></textarea>
                </div>
                <div class="frp-field" style="margin-bottom:0;">
                    <label for="frp-banner">Banner strip text</label>
                    <input class="cv-input" id="frp-banner" name="banner_text" maxlength="<?= FreeResellerProgramme::BANNER_TEXT_MAX ?>" value="<?= e($bannerText) ?>" placeholder="<?= e(FreeResellerProgramme::DEFAULT_BANNER_TEXT) ?>">
                    <small>Shown in the strip across your public pages, next to a "Get Free Reseller" link. Leave any field empty to use the default.</small>
                </div>
            </div>

            <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
                <h2 class="cv-card__title">Demo websites</h2>
                <p style="margin:0 0 14px;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                    Links to example reseller stores, so prospects can see how their website will look. Shown as cards in a
                    "Demo reseller websites" section, opening in a new tab. Up to <?= FreeResellerProgramme::MAX_DEMO_LINKS ?> — leave a row empty to remove it.
                </p>
                <?php foreach ($rows as $i => $row): ?>
                    <div class="frp-demo">
                        <span class="frp-demo__n"><?= $i + 1 ?></span>
                        <input class="cv-input" name="demo_label[]" maxlength="60" value="<?= e($row['label']) ?>" placeholder="Label, e.g. Hosting store demo" aria-label="Demo <?= $i + 1 ?> label">
                        <input class="cv-input" name="demo_url[]" maxlength="300" value="<?= e($row['url']) ?>" placeholder="https://demo.example.com" inputmode="url" aria-label="Demo <?= $i + 1 ?> web address">
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="cv-btn">Save programme settings</button>
        </div>

        <aside>
            <div class="cv-card">
                <h2 class="cv-card__title">Rules shown on the page</h2>
                <p style="margin:0 0 12px;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Read live from their own settings — change them there and the public page follows.</p>
                <ul class="frp-rules">
                    <li><span>Service discount</span><b><?= e($pct((float) $rules['serviceDiscount'])) ?></b></li>
                    <li><span>Domain discount</span><b><?= e($pct((float) $rules['domainDiscount'])) ?></b></li>
                    <li style="border:0;padding:0;justify-content:flex-end;"><a href="/admin/resellers#rs-discounts">Edit discounts &rarr;</a></li>
                    <li><span>Payout holding period</span><b><?= (int) $rules['holdingDays'] ?> days</b></li>
                    <li><span>Minimum payout</span><b><?= e((string) $rules['payoutMinimum']) ?></b></li>
                    <li style="border:0;padding:0;justify-content:flex-end;"><a href="/admin/resellers/accounts">Edit payout rules &rarr;</a></li>
                    <li><span>Cost billing</span><b><a href="/admin/resellers/billing">Billing settings</a></b></li>
                    <li><span>Free store address</span><b><?= $rules['storeDomain'] !== null ? '*.' . e((string) $rules['storeDomain']) : 'not set' ?></b></li>
                    <li style="border:0;padding:0;justify-content:flex-end;"><a href="/admin/resellers#rs-platform-address">Edit platform address &rarr;</a></li>
                </ul>
            </div>
            <div class="cv-card" style="margin-top:var(--cv-space-4);">
                <h2 class="cv-card__title">How applications work</h2>
                <ol style="margin:0;padding-left:18px;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);display:flex;flex-direction:column;gap:6px;">
                    <li>The applicant chooses a business name, store ID and domain (new or existing).</li>
                    <li>Guests register (email code + PIN) or sign in, then return to finish.</li>
                    <li>Their store opens instantly; a new domain goes into their cart to pay.</li>
                    <li>The domain appears in <a href="/admin/resellers/domains">Domains</a> for your approval, as usual. For a domain registered here on your nameservers you can use "override verification".</li>
                </ol>
            </div>
        </aside>
    </div>
</form>
