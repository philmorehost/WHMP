<?php
/** @var array<string, mixed> $domain */
$id = (int) $domain['id'];
$ns = json_decode((string) ($domain['nameservers'] ?? '[]'), true) ?: [];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($domain['domain_name']) ?></h1>
    <p><a href="/admin/domains">&larr; Back to domains</a></p>
    <p><strong>Registrar:</strong> <?= e($domain['registrar_slug']) ?> &middot; <strong>Status:</strong> <?= e($domain['status']) ?></p>
    <p><strong>Registered:</strong> <?= e((string) ($domain['registration_date'] ?? '-')) ?> &middot; <strong>Expires:</strong> <?= e((string) ($domain['expiry_date'] ?? '-')) ?></p>
    <p><strong>Lock:</strong> <?= $domain['registrar_lock_enabled'] ? 'Locked' : 'Unlocked' ?> &middot; <strong>ID Protection:</strong> <?= $domain['id_protection_enabled'] ? 'Enabled' : 'Disabled' ?></p>

    <?php if (!empty($domain['provisioning_error'])): ?>
        <div class="cv-field-error" style="margin:var(--cv-space-3) 0;">Error: <?= e($domain['provisioning_error']) ?></div>
    <?php endif; ?>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-3);flex-wrap:wrap;">
        <form method="post" action="/admin/domains/<?= $id ?>/renew"><?= csrf_field() ?><button class="cv-btn" type="submit">Renew</button></form>
        <form method="post" action="/admin/domains/<?= $id ?>/sync"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit">Sync with Registrar</button></form>
        <form method="post" action="/admin/domains/<?= $id ?>/lock"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit"><?= $domain['registrar_lock_enabled'] ? 'Unlock' : 'Lock' ?></button></form>
        <form method="post" action="/admin/domains/<?= $id ?>/id-protection"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit"><?= $domain['id_protection_enabled'] ? 'Disable ID Protection' : 'Enable ID Protection' ?></button></form>
        <a class="cv-btn cv-btn--secondary" href="/admin/domains/<?= $id ?>/contact">Manage Contact Info</a>
    </div>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Nameservers</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
        The values below are the last-known cache — they only update when saved here or refreshed from the registrar.
    </p>
    <form method="post" action="/admin/domains/<?= $id ?>/nameservers/refresh" style="margin-bottom:var(--cv-space-3);"><?= csrf_field() ?>
        <button class="cv-btn cv-btn--secondary" type="submit">Refresh from Registrar</button>
    </form>
    <form method="post" action="/admin/domains/<?= $id ?>/nameservers"><?= csrf_field() ?>
        <?php for ($i = 1; $i <= 5; $i++): ?>
            <div class="cv-field">
                <label class="cv-label">NS<?= $i ?></label>
                <input class="cv-input" name="ns<?= $i ?>" value="<?= e((string) ($ns[$i - 1] ?? '')) ?>">
            </div>
        <?php endfor; ?>
        <button class="cv-btn" type="submit">Save Nameservers</button>
    </form>
</div>
