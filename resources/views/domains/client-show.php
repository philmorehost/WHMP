<?php
/** @var array<string, mixed> $domain */
/** @var string|null $eppCode */
/** @var string|null $eppError */
$id = (int) $domain['id'];
$ns = json_decode((string) ($domain['nameservers'] ?? '[]'), true) ?: [];
?>
<div class="cv-card" style="max-width:32rem;margin:0 auto;">
    <h1 class="cv-card__title"><?= e($domain['domain_name']) ?></h1>
    <p><a href="/client/domains">&larr; Back to my domains</a></p>
    <p><strong>Status:</strong> <?= e($domain['status']) ?> &middot; <strong>Expires:</strong> <?= e((string) ($domain['expiry_date'] ?? '-')) ?></p>
    <p><strong>Lock:</strong> <?= $domain['registrar_lock_enabled'] ? 'Locked' : 'Unlocked' ?> &middot; <strong>ID Protection:</strong> <?= $domain['id_protection_enabled'] ? 'Enabled' : 'Disabled' ?></p>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);flex-wrap:wrap;">
        <form method="post" action="/client/domains/<?= $id ?>/lock"><?= csrf_field() ?>
            <button class="cv-btn cv-btn--secondary" type="submit"><?= $domain['registrar_lock_enabled'] ? 'Unlock' : 'Lock' ?></button>
        </form>
        <form method="post" action="/client/domains/<?= $id ?>/id-protection"><?= csrf_field() ?>
            <button class="cv-btn cv-btn--secondary" type="submit"><?= $domain['id_protection_enabled'] ? 'Disable ID Protection' : 'Enable ID Protection' ?></button>
        </form>
        <form method="post" action="/client/domains/<?= $id ?>/epp-code"><?= csrf_field() ?>
            <button class="cv-btn cv-btn--secondary" type="submit">Get EPP/Auth Code</button>
        </form>
    </div>

    <?php if ($eppCode !== null): ?>
        <div class="cv-card" style="margin-top:var(--cv-space-3);">EPP Code: <strong><?= e($eppCode) ?></strong></div>
    <?php elseif (!empty($eppError)): ?>
        <div class="cv-field-error" style="margin-top:var(--cv-space-3);"><?= e($eppError) ?></div>
    <?php endif; ?>
</div>

<div class="cv-card" style="max-width:32rem;margin:var(--cv-space-4) auto 0;">
    <h2 class="cv-card__title">Nameservers</h2>
    <form method="post" action="/client/domains/<?= $id ?>/nameservers"><?= csrf_field() ?>
        <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="cv-field">
                <label class="cv-label">NS<?= $i ?></label>
                <input class="cv-input" name="ns<?= $i ?>" value="<?= e((string) ($ns[$i - 1] ?? '')) ?>">
            </div>
        <?php endfor; ?>
        <button class="cv-btn" type="submit">Save Nameservers</button>
    </form>
</div>
