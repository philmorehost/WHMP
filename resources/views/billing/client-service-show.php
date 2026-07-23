<?php
/** @var array<string, mixed> $service */
/** @var array<string, mixed>|null $usage */
/** @var string|null $error */
/** @var bool $cpanelToolsAvailable */
/** @var array<string, mixed> $currency */
$id = (int) $service['id'];
$cpanelToolsAvailable ??= false;
?>
<div class="cv-card" style="max-width:32rem;margin:0 auto;">
    <h1 class="cv-card__title"><?= e($service['product_name']) ?></h1>
    <p><a href="/client/services">&larr; Back to my services</a></p>

    <?php if (!empty($error)): ?>
        <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div style="background:rgba(16, 185, 129, 0.1);border-left:4px solid #10b981;color:#059669;padding:var(--cv-space-3);border-radius:4px;margin-bottom:var(--cv-space-3);"><?= e($message) ?></div>
    <?php endif; ?>

    <p><strong>Status:</strong>
        <?php if ($service['status'] === 'active'): ?>
            <span class="cv-badge cv-badge--success">Active</span>
        <?php elseif ($service['status'] === 'suspended'): ?>
            <span class="cv-badge cv-badge--danger">Suspended</span>
        <?php else: ?>
            <span class="cv-badge cv-badge--neutral"><?= e($service['status']) ?></span>
        <?php endif; ?>
    </p>
    <p><strong>Billing Cycle:</strong> <?= e($service['billing_cycle']) ?> &middot; <strong>Amount:</strong> <?= e($currency['symbol'] ?? '$') ?><?= number_format(((float) $service['amount'] > 1000 && (float) ($currency['exchange_rate'] ?? 1) > 50 && ((float) $service['amount'] * (float) ($currency['exchange_rate'] ?? 1) > 5000000)) ? (float) $service['amount'] : ((float) $service['amount'] * (float) ($currency['exchange_rate'] ?? 1)), 2) ?></p>
    <?php if (!empty($service['domain']) || !empty($service['hostname'])): ?>
        <p><strong>Domain/Hostname:</strong> <?= e($service['domain'] ?: $service['hostname']) ?></p>
    <?php endif; ?>
    <p><strong>Next Due:</strong> <?= e($service['next_due_date']) ?></p>

    <?php if ($usage !== null && ($usage['success'] ?? false)): ?>
        <div class="cv-card" style="margin-top:var(--cv-space-3);">
            <strong>Usage</strong>
            <p>Disk: <?= number_format((float) $usage['diskUsedMb'], 0) ?> / <?= number_format((float) $usage['diskLimitMb'], 0) ?> MB</p>
            <p>Bandwidth: <?= number_format((float) $usage['bandwidthUsedMb'], 0) ?> / <?= number_format((float) $usage['bandwidthLimitMb'], 0) ?> MB</p>
        </div>
    <?php endif; ?>

    <?php if (($pendingCancellation ?? null) !== null): ?>
        <div style="background:rgba(239, 68, 68, 0.1);border-left:4px solid var(--cv-color-danger, #ef4444);padding:var(--cv-space-3);border-radius:4px;margin-top:var(--cv-space-3);margin-bottom:var(--cv-space-3);">
            <strong style="color:var(--cv-color-danger, #ef4444);">Scheduled for Cancellation</strong>
            <p style="font-size:var(--cv-text-sm);margin:0;line-height:1.4;margin-top:4px;">
                This service has a pending request to be cancelled at the end of the billing period (<?= e($service['next_due_date']) ?>).
            </p>
        </div>
    <?php endif; ?>

    <div style="display:flex;gap:var(--cv-space-2);margin-top:var(--cv-space-4);">
        <?php if ($service['status'] === 'active'): ?>
            <form method="post" action="/client/services/<?= $id ?>/sso"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Log In to Control Panel</button>
            </form>
            <?php if ($cpanelToolsAvailable): ?>
                <a class="cv-btn cv-btn--secondary" href="/client/services/<?= $id ?>/cpanel-tools">cPanel Tools</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!in_array($service['status'], ['cancelled', 'terminated'], true) && ($pendingCancellation ?? null) === null): ?>
            <a class="cv-btn cv-btn--danger" href="/client/services/<?= $id ?>/cancel">Cancel Service</a>
        <?php endif; ?>
    </div>

    <?php if ($service['status'] === 'active' && in_array($serverModule ?? '', ['interserver-vps', 'interserver-dedicated'], true)): ?>
        <hr style="border:0;border-top:1px solid var(--cv-border-color, #e5e7eb);margin:var(--cv-space-4) 0;">
        
        <div style="margin-top:var(--cv-space-3);">
            <h3 style="font-size:var(--cv-text-md);margin-bottom:var(--cv-space-2);">Server Management</h3>
            
            <!-- OS Reinstall Form -->
            <form method="post" action="/client/services/<?= $id ?>/reinstall" style="margin-bottom:var(--cv-space-3);" data-confirm="Are you sure you want to reinstall the OS? All current data on the server will be lost permanently!">
                <?= csrf_field() ?>
                <div class="cv-field" style="display:flex;gap:var(--cv-space-2);align-items:end;margin-bottom:0;">
                    <div style="flex:1;">
                        <label class="cv-label" style="font-size:var(--cv-text-xs);">Reinstall OS Template</label>
                        <select class="cv-select" name="os_version" required style="width:100%;">
                            <option value="ubuntu24">Ubuntu 24.04 LTS</option>
                            <option value="ubuntu22">Ubuntu 22.04 LTS</option>
                            <option value="debian12">Debian 12</option>
                            <option value="almalinux9">AlmaLinux 9</option>
                            <option value="centos9">CentOS Stream 9</option>
                        </select>
                    </div>
                    <button type="submit" class="cv-btn">Reinstall</button>
                </div>
            </form>

            <!-- rDNS Form -->
            <form method="post" action="/client/services/<?= $id ?>/rdns">
                <?= csrf_field() ?>
                <div class="cv-field" style="display:flex;gap:var(--cv-space-2);align-items:end;margin-bottom:0;">
                    <div style="flex:1;">
                        <label class="cv-label" style="font-size:var(--cv-text-xs);">Reverse DNS (PTR Record)</label>
                        <input class="cv-input" name="rdns" placeholder="ptr.example.com" required style="width:100%;">
                    </div>
                    <button type="submit" class="cv-btn">Update PTR</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
