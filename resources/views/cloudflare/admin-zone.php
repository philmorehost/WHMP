<?php
/**
 * @var array<string, mixed> $zone
 * @var array<int, array<string, mixed>> $activity
 * @var int $graceDays
 */
$id = (int) $zone['id'];
$isStore = $zone['reseller_id'] !== null;
$deleted = $zone['status'] === 'deleted';
$action = static fn (string $name, string $label, bool $primary = false): string => '<form method="post" action="/admin/cloudflare/zones/' . $id . '/' . $name . '">' . csrf_field()
    . '<button class="cv-btn' . ($primary ? '' : ' cv-btn--secondary') . '" type="submit">' . e($label) . '</button></form>';
?>
<div class="cfa">
    <div class="cfa-head">
        <div>
            <h1><span class="cfa-logo" aria-hidden="true">CF</span> <?= e((string) $zone['name']) ?></h1>
            <p>Zone <span class="cfa-mono"><?= e((string) $zone['cf_zone_id']) ?></span> · Free plan</p>
        </div>
        <a class="cv-btn cv-btn--secondary" href="/admin/cloudflare">&larr; All zones</a>
    </div>
    <?= $view->render('cloudflare.admin-tabs', ['active' => 'zones', 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <div class="cfa-grid">
        <div class="cfa-card">
            <h2>Details</h2>
            <dl class="cfa-dl">
                <dt>Status</dt><dd><strong><?= e(ucfirst((string) $zone['status'])) ?></strong><?= (int) $zone['paused'] === 1 ? ' · paused' . ((int) $zone['paused_by_us'] === 1 ? ' (service suspended)' : '') : '' ?></dd>
                <?php if ($zone['delete_after'] !== null): ?>
                    <dt>Removal</dt><dd style="color:#b91c1c"><?= e((string) $zone['delete_after']) ?> (<?= e((string) $zone['delete_reason']) ?>)</dd>
                <?php endif; ?>
                <dt>Customer</dt><dd>
                    <?= e(trim((string) $zone['first_name'] . ' ' . (string) $zone['last_name'])) ?> <span class="cfa-muted"><?= e((string) $zone['client_email']) ?></span>
                    <?php if ($isStore): ?>
                        <br><span class="cfa-pill">Store customer: <?= e((string) ($zone['store_name'] ?: $zone['store_slug'] ?: '#' . $zone['reseller_id'])) ?></span>
                        <?php if (!empty($zone['store_owner_client_id'])): ?>
                            <a href="/admin/resellers/<?= (int) $zone['store_owner_client_id'] ?>/customers/<?= (int) $zone['client_id'] ?>">Open in reseller management</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <br><a href="/admin/clients/<?= (int) $zone['client_id'] ?>">Open client</a>
                    <?php endif; ?>
                </dd>
                <dt>Service</dt><dd><?php if ($zone['service_id'] !== null): ?>
                    <?= $isStore ? '#' . (int) $zone['service_id'] : '<a href="/admin/services/' . (int) $zone['service_id'] . '">#' . (int) $zone['service_id'] . '</a>' ?>
                    <?= e((string) ($zone['product_name'] ?? '')) ?> <span class="cfa-muted"><?= e((string) ($zone['service_status'] ?? '')) ?></span>
                <?php else: ?>—<?php endif; ?></dd>
                <dt>Nameservers</dt><dd class="cfa-mono"><?= e(implode(', ', (array) $zone['name_servers'])) ?: '—' ?></dd>
                <dt>Previous NS</dt><dd class="cfa-mono"><?= e(implode(', ', (array) $zone['original_name_servers'])) ?: '—' ?></dd>
                <dt>NS switched by us</dt><dd><?= (int) $zone['ns_switched_by_us'] === 1 ? 'Yes (restored automatically on removal)' : 'No' ?></dd>
                <?php if (!empty($zone['ns_restore_after'])): ?><dt>NS restore</dt><dd><?= e((string)$zone['ns_restore_after']) ?> (waiting for DNSSEC DS caches)</dd><?php endif; ?>
                <dt>DNSSEC</dt><dd><?= e(ucfirst((string)($zone['dnssec_status']??'')) ?: 'Not checked') ?><?php if (is_array($zone['dnssec_ds']??null)): ?> · key tag <?= e((string)($zone['dnssec_ds']['key_tag']??'')) ?><?php endif; ?><?php if ((int)($zone['dnssec_ds_by_us']??0)===1): ?> · DS added by WHMP<?php endif; ?><?php if (!empty($zone['dnssec_disable_after'])): ?> · signing off after <?= e((string)$zone['dnssec_disable_after']) ?><?php endif; ?></dd>
                <dt>Origin CA</dt><dd><?= !empty($zone['origin_cert_id']) ? 'Installed'.(!empty($zone['origin_cert_expires'])?', expires '.e((string)$zone['origin_cert_expires']):'').' (revoked on deletion)' : '—' ?></dd>
                <dt>Created</dt><dd><?= e((string) $zone['created_at']) ?></dd>
                <dt>Activated</dt><dd><?= e((string) ($zone['activated_at'] ?? '—')) ?></dd>
                <dt>Last synced</dt><dd><?= e((string) ($zone['last_synced_at'] ?? '—')) ?></dd>
                <?php if (!empty($zone['last_error'])): ?><dt>Last warning</dt><dd style="color:#b45309"><?= e((string) $zone['last_error']) ?></dd><?php endif; ?>
                <?php if ($deleted): ?><dt>Deleted</dt><dd><?= e((string) ($zone['deleted_at'] ?? '')) ?></dd><?php endif; ?>
            </dl>
        </div>

        <div class="cfa-card">
            <h2>Actions</h2>
            <?php if ($deleted): ?>
                <p class="cfa-muted">This zone has been deleted from Cloudflare.</p>
                <?php if (!empty($zone['backup_bind'])): ?>
                    <a class="cv-btn cv-btn--secondary" href="/admin/cloudflare/zones/<?= $id ?>/backup">Download DNS backup (BIND)</a>
                <?php endif; ?>
            <?php else: ?>
                <div class="cfa-actions">
                    <?= $action('sync', 'Sync now', true) ?>
                    <?php if ($zone['status'] !== 'active'): ?><?= $action('check', 'Check activation') ?><?php endif; ?>
                    <?= (int) $zone['paused'] === 1 ? $action('resume', 'Resume') : $action('pause', 'Pause') ?>
                    <?= $zone['delete_after'] !== null ? $action('cancel', 'Cancel removal') : $action('schedule', 'Schedule removal (' . $graceDays . ' days)') ?>
                </div>
                <hr style="margin:16px 0;border:0;border-top:1px solid var(--cv-border,#e2e8f0)">
                <form method="post" action="/admin/cloudflare/zones/<?= $id ?>/delete" class="cfa-actions">
                    <?= csrf_field() ?>
                    <input class="cv-input" name="confirm" placeholder="Type DELETE" style="width:140px" aria-label="Type DELETE to confirm" required>
                    <button class="cv-btn cv-btn--secondary" type="submit" style="color:#b91c1c">Delete now</button>
                </form>
                <p class="cfa-muted">Deletes the zone immediately (a DNS backup is kept). A DS record WHMP added is removed; nameservers WHMP switched are restored only after DNSSEC caches clear; Origin CA is revoked.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="cfa-card">
        <h2>Activity</h2>
        <div style="overflow-x:auto">
        <table class="cfa-table">
            <thead><tr><th>When</th><th>By</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($activity as $row): ?>
                <tr>
                    <td><small><?= e((string) $row['created_at']) ?></small></td>
                    <td><?= e(ucfirst((string) $row['actor_type'])) ?><?= $row['actor_id'] !== null ? ' #' . (int) $row['actor_id'] : '' ?></td>
                    <td class="cfa-mono"><?= e((string) $row['action']) ?></td>
                    <td><?= e((string) $row['summary']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($activity === []): ?><tr><td colspan="4" class="cfa-muted">No activity.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
