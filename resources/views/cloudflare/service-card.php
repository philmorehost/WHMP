<?php
/**
 * Cloudflare card on the client's service page. Renders nothing while the add-on
 * is inactive, when the product is not offered Cloudflare, or on any error — it
 * must never break the service page.
 *
 * @var array<string, mixed> $service
 */
$cfState = null;

try {
    $cfContainer = \CodeVault\Support\App::container();

    if ($cfContainer->make(\CodeVault\Modules\AddonModuleRepository::class)->isActive(\CodeVault\Cloudflare\CloudflareCronJob::SLUG)) {
        $cfState = $cfContainer->make(\CodeVault\Cloudflare\CloudflareService::class)->stateFor($service);
    }
} catch (\Throwable) {
    $cfState = null;
}

if ($cfState === null || !$cfState['show']) {
    return;
}

$cfZone = $cfState['zone'];
$cfUrl = '/client/services/' . (int) $service['id'] . '/cloudflare';

if ($cfZone === null) {
    $cfBadge = '';
    $cfText = $cfState['canEnable']
        ? 'Speed up and protect your website with Cloudflare\'s global CDN, free SSL and DDoS protection — included free.'
        : $cfState['reason'];
} elseif ($cfZone['delete_after'] !== null) {
    $cfBadge = '<span class="cv-badge cv-badge--danger">Removal scheduled</span>';
    $cfText = 'Cloudflare will be removed from ' . $cfZone['name'] . ' on ' . date('j M Y', strtotime((string) $cfZone['delete_after']) ?: time()) . '.';
} elseif ((int) $cfZone['paused'] === 1) {
    $cfBadge = '<span class="cv-badge cv-badge--neutral">Paused</span>';
    $cfText = 'Cloudflare is paused for ' . $cfZone['name'] . '.';
} elseif ($cfZone['status'] === 'active') {
    $cfBadge = '<span class="cv-badge cv-badge--success">Active</span>';
    $cfText = $cfZone['name'] . ' is protected and accelerated by Cloudflare.';
} else {
    $cfBadge = '<span class="cv-badge cv-badge--warning">Action needed</span>';
    $cfText = 'Switch the nameservers for ' . $cfZone['name'] . ' to finish activating Cloudflare.';
}
?>
<div class="cv-card" style="margin-top:var(--cv-space-4,16px);display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap;border-left:4px solid #f6821f;">
    <div style="display:flex;gap:14px;align-items:center;min-width:0;flex:1 1 320px;">
        <span aria-hidden="true" style="flex:none;display:inline-flex;width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#f6821f,#fbad41);color:#fff;align-items:center;justify-content:center;font-weight:700;">CF</span>
        <div style="min-width:0;">
            <strong>Cloudflare CDN &amp; Security</strong> <span class="cv-badge cv-badge--neutral">Free</span> <?= $cfBadge ?>
            <div style="color:var(--cv-text-secondary,#64748b);font-size:.9rem;margin-top:2px;"><?= e($cfText) ?></div>
        </div>
    </div>
    <?php if ($cfZone !== null || $cfState['canEnable']): ?>
        <a class="cv-btn<?= $cfZone === null ? '' : ' cv-btn--secondary' ?>" href="<?= e($cfUrl) ?>"><?= $cfZone === null ? 'Set up free Cloudflare' : 'Manage Cloudflare' ?></a>
    <?php endif; ?>
</div>
