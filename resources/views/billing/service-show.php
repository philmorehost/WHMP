<?php
/** @var array<string, mixed> $service */
/** @var array<int, array<string, mixed>> $products */
/** @var array<string, string> $cycles */
/** @var array<string, string> $modes */
/** @var string|null $error */
/** @var bool $showDomainField false for VPS/dedicated, which are addressed by hostname */
/** @var bool $isCpanelSharedHosting only cPanel shared hosting gets the Create Account button */
$id = (int) $service['id'];
// Default to showing it: a caller that hasn't decided should get the full form
// rather than silently drop a field the admin needs.
$showDomainField ??= true;
$isCpanelSharedHosting ??= false;
?>
<style>
/* Admin Service Detail Styles */
.admin-service-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-service-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-service-hero__content {
    position: relative;
    z-index: 1;
}
.admin-service-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #3b82f6;
    text-decoration: none;
    font-weight: 600;
    font-size: .9rem;
    margin-bottom: 12px;
    transition: all 0.2s;
}
.admin-service-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-service-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 8px 0;
    line-height: 1.2;
}
.admin-service-meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 24px;
    margin-top: 24px;
}
.admin-service-meta__item {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-service-meta__label {
    font-size: .8rem;
    color: rgba(255,255,255,.6);
    text-transform: uppercase;
    letter-spacing: .05em;
    font-weight: 700;
}
.admin-service-meta__value {
    font-size: .95rem;
    color: white;
    font-weight: 600;
}
.admin-service-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 24px;
}
.admin-service-btn {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: .85rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}
.admin-service-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.admin-service-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-service-btn--secondary {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
}
.admin-service-btn--secondary:hover {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}
.admin-service-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-service-btn--danger:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}

/* Service Detail Card */
.admin-service-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-service-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-service-card__body {
    padding: 24px;
}

/* Form Styles */
.admin-service-field {
    margin-bottom: 20px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.admin-service-field label {
    font-size: .85rem;
    font-weight: 700;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-service-field input,
.admin-service-field select {
    padding: 10px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 8px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
    font-family: inherit;
}
.admin-service-field input:focus,
.admin-service-field select:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}
.admin-service-field small {
    font-size: .8rem;
    color: var(--cv-text-secondary);
    margin-top: 4px;
}

/* Error */
.admin-service-error {
    background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(220,38,38,.1));
    border: 1px solid rgba(239,68,68,.3);
    color: #ef4444;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 24px;
    font-size: .9rem;
}

@media (max-width: 768px) {
    .admin-service-hero {
        padding: 32px 24px;
    }
    .admin-service-hero__title {
        font-size: 1.5rem;
    }
    .admin-service-meta {
        grid-template-columns: 1fr;
    }
    .admin-service-actions {
        width: 100%;
        flex-direction: column;
    }
    .admin-service-actions form,
    .admin-service-actions button {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-service-hero">
    <div class="admin-service-hero__content">
        <a href="/admin/services" class="admin-service-hero__back">
            <span>←</span>
            <span>Back to Services</span>
        </a>
        <h1 class="admin-service-hero__title"><?= e($service['product_name']) ?></h1>

        <div class="admin-service-meta">
            <div class="admin-service-meta__item">
                <span class="admin-service-meta__label">👤 Client</span>
                <span class="admin-service-meta__value"><?= e(($service['first_name'] ?? '') . ' ' . ($service['last_name'] ?? '')) ?></span>
                <span style="font-size:.8rem; color:rgba(255,255,255,.6);"><?= e($service['client_email'] ?? '') ?></span>
            </div>
            <div class="admin-service-meta__item">
                <span class="admin-service-meta__label">💳 Billing Cycle & Amount</span>
                <span class="admin-service-meta__value"><?= e($cycles[$service['billing_cycle']] ?? $service['billing_cycle']) ?> — <?= e($service['currency_symbol'] ?? '$') ?><?= number_format((float) $service['amount'], 2) ?></span>
                <span style="font-size:.8rem; color:rgba(255,255,255,.6);">Currency: <?= e($service['currency_code'] ?? 'USD') ?></span>
            </div>
            <div class="admin-service-meta__item">
                <span class="admin-service-meta__label">📅 Next Due</span>
                <span class="admin-service-meta__value"><?= e($service['next_due_date']) ?></span>
            </div>
            <div class="admin-service-meta__item">
                <span class="admin-service-meta__label">🔧 Status</span>
                <span class="admin-service-meta__value"><?= e($service['status']) ?></span>
            </div>
            <div class="admin-service-meta__item">
                <span class="admin-service-meta__label">👤 Username</span>
                <span class="admin-service-meta__value"><?= e((string) ($service['username'] ?? '-')) ?>
                    <?php
                    // Shortcut into the cPanel Username Changer while the add-on is active.
                    $ucnShortcut = false;
                    if (!empty($isCpanelSharedHosting) && !empty($service['username'])) {
                        try {
                            $ucnShortcut = \CodeVault\Support\App::container()->make(\CodeVault\Modules\AddonModuleRepository::class)
                                ->isActive(\CodeVault\UsernameChanger\UsernameChangeCronJob::SLUG);
                        } catch (\Throwable) {
                            $ucnShortcut = false;
                        }
                    }
                    ?>
                    <?php if ($ucnShortcut): ?><a href="/admin/username-changer/manual?service_id=<?= (int) $service['id'] ?>" style="font-size:.8em;margin-left:6px;" title="Rename this cPanel account">✏️ Change</a><?php endif; ?>
                </span>
            </div>
        </div>

        <?php if (!empty($service['provisioning_error'])): ?>
            <div class="admin-service-error" style="margin-top:24px;">
                ⚠️ Provisioning error: <?= e($service['provisioning_error']) ?>
            </div>
        <?php endif; ?>

        <?php $suspensionReason = trim((string) ($service['suspension_reason'] ?? '')); ?>
        <?php if ($service['status'] === 'suspended'): ?>
            <div class="admin-service-error" style="margin-top:24px;">
                🛑 <strong>Suspended</strong><?= $suspensionReason !== '' ? ': ' . e($suspensionReason) : ' — no reason recorded.' ?>
                <div style="margin-top:6px;font-size:.8rem;opacity:.85;">Unsuspending the service (or paying the overdue invoice) clears this reason automatically.</div>
            </div>
        <?php endif; ?>

        <div class="admin-service-actions">
            <?php if ($service['status'] === 'pending'): ?>
                <form method="post" action="/admin/services/<?= $id ?>/retry-provisioning"><?= csrf_field() ?>
                    <button class="admin-service-btn admin-service-btn--primary" type="submit">⚙️ Provision Now</button>
                </form>
            <?php endif; ?>
            <?php if ($service['status'] === 'active'): ?>
                <form method="post" action="/admin/services/<?= $id ?>/suspend"><?= csrf_field() ?>
                    <button class="admin-service-btn admin-service-btn--danger" type="submit">🛑 Suspend</button>
                </form>
            <?php elseif ($service['status'] === 'suspended'): ?>
                <form method="post" action="/admin/services/<?= $id ?>/unsuspend"><?= csrf_field() ?>
                    <button class="admin-service-btn admin-service-btn--primary" type="submit">✅ Unsuspend</button>
                </form>
            <?php endif; ?>
            <?php
            // Create Account: only for cPanel shared-hosting packages. Pending
            // services already get "⚙️ Provision Now" (same provision() call),
            // so this is the management-button equivalent for an active or
            // suspended service whose account was never actually built on WHM.
            ?>
            <?php if ($isCpanelSharedHosting && !in_array($service['status'], ['pending', 'terminated'], true)): ?>
                <form method="post" action="/admin/services/<?= $id ?>/create-account" data-confirm="Create the cPanel account on the server for this service now? This runs createacct against the assigned cPanel/WHM server and can take a few minutes — please wait for it to finish."><?= csrf_field() ?>
                    <button class="admin-service-btn admin-service-btn--primary" type="submit">🖥️ Create Account</button>
                </form>
            <?php endif; ?>
            <?php if ($service['status'] !== 'terminated'): ?>
                <form method="post" action="/admin/services/<?= $id ?>/terminate"><?= csrf_field() ?>
                    <button class="admin-service-btn admin-service-btn--danger" type="submit">🗑️ Terminate</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin/services/<?= $id ?>/delete" data-confirm="Are you sure you want to delete this service permanently? This action cannot be undone."><?= csrf_field() ?>
                <button class="admin-service-btn admin-service-btn--danger" style="background:linear-gradient(135deg,rgba(239,68,68,.3),rgba(185,28,28,.25));border-color:rgba(239,68,68,.5);" type="submit">❌ Delete Service</button>
            </form>
        </div>

        <?php
        // Manual status, for products provisioned by hand. Every button above
        // routes through a provisioning module, which is no use for a dedicated
        // server built in the provider's own portal — Nocix has no ordering API
        // at all. This sets the status locally and touches nothing remote.
        $statusOptions = [
            'pending' => 'Pending — awaiting setup',
            'active' => 'Active — client can use it',
            'suspended' => 'Suspended',
            'cancelled' => 'Cancelled',
            'terminated' => 'Terminated',
        ];
        ?>
        <div style="margin-top:var(--cv-space-4);padding-top:var(--cv-space-4);border-top:1px solid var(--cv-border-default);">
            <form method="post" action="/admin/services/<?= $id ?>/status" style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;"><?= csrf_field() ?>
                <label style="font-weight:600;font-size:.9rem;">Set status manually:</label>
                <select name="status" class="cv-select" style="width:auto;min-width:220px;">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $service['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="suspension_reason" class="cv-input" style="width:auto;min-width:260px;"
                       placeholder="Reason (used when setting Suspended)"
                       value="<?= e($suspensionReason) ?>">
                <button class="admin-service-btn admin-service-btn--primary" type="submit"
                        data-confirm="Change this service's status? This updates WHMP only — it does not start, stop or terminate anything on the server.">💾 Apply Status</button>
            </form>
            <small style="color:var(--cv-text-secondary);display:block;margin-top:var(--cv-space-2);">
                For servers you set up by hand. Marking a service <strong>Active</strong> makes it appear in the client's
                service list. This changes WHMP's records only — no provider API is called, so nothing is created,
                suspended or destroyed on the actual server.
            </small>

            <?php
            // Send/resend sits right here, next to the status control, because
            // this is where the admin is looking the moment a manually-built
            // service goes live. It was previously only at the foot of the Edit
            // Service Details card, far enough away to be missed.
            //
            // Called "Service Details" throughout, not "Server Details" — a
            // license or a domain-bound product has login details worth
            // sending too, and neither is a server.
            $detailsSentAt = trim((string) ($service['details_sent_at'] ?? ''));
            $hasBeenSent = $detailsSentAt !== '';
            ?>
            <div style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
                <form method="post" action="/admin/services/<?= $id ?>/send-details"
                      data-confirm="<?= $hasBeenSent ? 'Re-send' : 'Send' ?> the service details — including the password — to the client?"><?= csrf_field() ?>
                    <button class="admin-service-btn admin-service-btn--secondary" type="submit">
                        📧 <?= $hasBeenSent ? 'Resend Service Details' : 'Email Service Details to Client' ?>
                    </button>
                </form>
                <small style="color:var(--cv-text-secondary);">
                    <?php if ($hasBeenSent): ?>
                        Last sent <strong><?= e($detailsSentAt) ?></strong> to <?= e((string) ($service['client_email'] ?? 'the client')) ?>.
                    <?php else: ?>
                        Not sent yet. Sending happens automatically the first time you set this service Active.
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="admin-service-error"><?= e($error) ?></div>
<?php endif; ?>

<?php // Outcome of the "Email Service Details" action — including the useful refusal when nothing has been filled in yet. ?>
<?php if (($_GET['status_set'] ?? '') !== ''): ?>
    <div class="admin-service-error" style="background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.35);color:#10b981;">
        ✅ Status set to <strong><?= e((string) $_GET['status_set']) ?></strong>. No provider API was called.
        <?php // Say plainly whether the client was emailed, so the admin never has to guess. ?>
        <?= match ($_GET['details'] ?? '') {
            'sent' => ' Service details were emailed to the client.',
            'skipped' => ' No service details emailed — fill in a username, domain, hostname or IP below, then use “Email Service Details to Client”.',
            'failed' => ' The service-details email could not be sent — see the activity log.',
            default => '',
        } ?>
    </div>
<?php endif; ?>
<?php if (($_GET['status_error'] ?? '') !== ''): ?>
    <div class="admin-service-error">⚠️ <?= e((string) $_GET['status_error']) ?></div>
<?php endif; ?>
<?php if (($_GET['details_sent'] ?? '') !== ''): ?>
    <div class="admin-service-error" style="background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.35);color:#10b981;">✅ Service details emailed to the client.</div>
<?php endif; ?>
<?php if (($_GET['details_error'] ?? '') !== ''): ?>
    <div class="admin-service-error">⚠️ <?= e((string) $_GET['details_error']) ?></div>
<?php endif; ?>
<?php if (($_GET['price_error'] ?? '') !== ''): ?>
    <div class="admin-service-error">⚠️ <?= e((string) $_GET['price_error']) ?></div>
<?php endif; ?>
<?php if (($_GET['create_queued'] ?? '') !== ''): ?>
    <div class="admin-service-error" style="background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.35);color:#60a5fa;">
        🔄 Account creation is running in the background — you'll receive an email with the outcome when it finishes. This page won't block or time out.
    </div>
<?php endif; ?>
<?php if (($_GET['upgrade_queued'] ?? '') !== ''): ?>
    <div class="admin-service-error" style="background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.35);color:#60a5fa;">
        🔄 Package upgrade is running in the background — you'll receive an email with the outcome when it finishes. This page won't block or time out.
    </div>
<?php endif; ?>
<?php if (($_GET['create_error'] ?? '') !== ''): ?>
    <div class="admin-service-error">⚠️ <?= e((string) $_GET['create_error']) ?></div>
<?php endif; ?>

<?php
$remoteLinkable = $remoteLinkable ?? false;
$remoteLink = $remoteLink ?? null;
$remoteServices = $remoteServices ?? null;
$remoteNotice = $remoteNotice ?? null;
$isVpsProduct = $isVpsProduct ?? false;
$currentRef = is_array($remoteLink) ? ($remoteLink['ref'] ?? null) : null;
$remoteLabel = null;
foreach ((array) ($remoteServices['services'] ?? []) as $remoteRow) {
    if ($currentRef !== null && (string) $remoteRow['ref'] === (string) $currentRef) {
        $remoteLabel = (string) $remoteRow['label'];
    }
}
$viaText = ['hostname' => 'its hostname', 'ip' => 'its IP address', 'username' => 'its username'];
$isNocix = ($remoteProvider ?? null) === 'nocix';
$providerName = $isNocix ? 'Nocix' : 'InterServer';
$machineWord = $isNocix ? 'dedicated server' : 'VPS';
$clientActions = $isNocix ? 'restart and OS reload buttons act' : "power, console, reverse DNS, snapshot, reinstall and restore buttons act";
?>
<?php if ($remoteLinkable): ?>
<div class="admin-service-card" id="remote-link">
    <h2 class="admin-service-card__title">🔗 <?= e($providerName) ?> <?= $isNocix ? 'server' : 'VPS' ?> link (client self-service)</h2>
    <div class="admin-service-card__body">
        <?php if ($remoteNotice !== null && $remoteNotice !== ''): ?>
            <div class="admin-service-error" style="background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.35);color:#60a5fa;margin-bottom:var(--cv-space-3);"><?= e($remoteNotice) ?></div>
        <?php endif; ?>

        <?php if (is_array($remoteLink) && ($remoteLink['linked'] ?? null) !== null): ?>
            <p>✅ <strong>Linked</strong> to <?= $remoteLabel !== null ? e($remoteLabel) : '<code>' . e((string) $remoteLink['linked']) . '</code>' ?>.
                The client's <?= e($clientActions) ?> on this <?= e($machineWord) ?>.</p>
        <?php elseif ($currentRef !== null): ?>
            <p>🟡 <strong>Matched automatically</strong> by <?= e($viaText[$remoteLink['via']] ?? 'its details') ?>
                to <?= $remoteLabel !== null ? e($remoteLabel) : '<code>' . e((string) $currentRef) . '</code>' ?>.
                It works, but changing the <?= $isNocix ? 'IP or username' : 'hostname or IP' ?> above would break it. Link it to make it permanent.</p>
        <?php else: ?>
            <p>⚠️ <strong>Not linked.</strong> No <?= e($machineWord) ?> on the <?= e($providerName) ?> account matches this service's <?= $isNocix ? 'IP addresses' : 'hostname or IP' ?>,
                so the client's server buttons open support tickets. Choose the <?= e($machineWord) ?> below.</p>
        <?php endif; ?>

        <?php if (is_array($remoteServices) && !$remoteServices['success']): ?>
            <div class="admin-service-error">⚠️ Could not read the <?= e($providerName) ?> account: <?= e((string) $remoteServices['message']) ?></div>
        <?php elseif (is_array($remoteServices)): ?>
            <form method="post" action="/admin/services/<?= $id ?>/remote-link" style="display:flex;gap:var(--cv-space-2);flex-wrap:wrap;align-items:flex-end;">
                <?= csrf_field() ?>
                <div class="admin-service-field" style="flex:1 1 22rem;margin:0;">
                    <label><?= e(ucfirst($machineWord)) ?> on the <?= e($providerName) ?> account</label>
                    <select name="remote_id" required>
                        <option value="">Choose a <?= e($machineWord) ?>…</option>
                        <?php foreach ($remoteServices['services'] as $remoteRow): ?>
                            <option value="<?= e((string) $remoteRow['ref']) ?>" <?= $currentRef !== null && (string) $remoteRow['ref'] === (string) $currentRef ? 'selected' : '' ?>><?= e((string) $remoteRow['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="admin-service-btn admin-service-btn--primary" type="submit">🔗 Link this <?= $isNocix ? 'server' : 'VPS' ?></button>
            </form>
            <?php if (is_array($remoteLink) && ($remoteLink['linked'] ?? null) !== null): ?>
                <form method="post" action="/admin/services/<?= $id ?>/remote-link" style="margin-top:var(--cv-space-2);" data-confirm="Unlink this service from its <?= e($providerName) ?> <?= e($machineWord) ?>?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="remote_id" value="">
                    <button class="admin-service-btn admin-service-btn--secondary" type="submit">Unlink</button>
                </form>
            <?php endif; ?>
            <?php if ($remoteServices['services'] === []): ?>
                <small style="color:var(--cv-text-secondary);">The <?= e($providerName) ?> account behind this server lists none. Check that the API credentials belong to the account the <?= e($machineWord) ?> was bought on.</small>
            <?php endif; ?>
        <?php endif; ?>

        <div style="margin-top:var(--cv-space-3);padding-top:var(--cv-space-3);border-top:1px dashed var(--cv-border-default);">
            <div style="display:flex;align-items:center;gap:var(--cv-space-2);flex-wrap:wrap;">
                <button type="button" class="admin-service-btn admin-service-btn--secondary" data-remote-check="<?= $id ?>" data-token="<?= e(csrf_token()) ?>">🩺 Run self-service check</button>
                <small style="color:var(--cv-text-secondary);">Shows exactly why a client button would open a ticket. Read-only: it does not reboot, snapshot or change the <?= e($machineWord) ?>.</small>
            </div>
            <div data-remote-check-result="<?= $id ?>" style="margin-top:var(--cv-space-3);"></div>
        </div>

        <small style="color:var(--cv-text-secondary);display:block;margin-top:var(--cv-space-3);">
            <?php if ($isNocix): ?>
                ℹ️ Through the Nocix API the client can restart the server and reload its OS. After a reload they can reveal
                the new login Nocix stores in its portal. Suspending this service disconnects the server from the network and
                unsuspending reconnects it. Terminating does not cancel it at Nocix (the API cannot); cancel it in the Nocix portal.
            <?php elseif (!empty($remoteHasAccountPassword)): ?>
                ✅ The InterServer account password is saved on the server, so clients can also reinstall the OS and restore backups themselves.
            <?php else: ?>
                ℹ️ OS reinstall and backup restore also need the InterServer account password. Until it is saved on the
                <a href="/admin/servers/<?= (int) ($remoteServerId ?? 0) ?>/edit" style="color:var(--cv-color-brand-500);text-decoration:underline;">server record</a>,
                those two requests come to you as support tickets. Everything else works now.
            <?php endif; ?>
            <?php if (!$isNocix): ?>
                Note: suspending this service in WHMP stops the VPS at InterServer, unsuspending starts it, and terminating it cancels the VPS on your InterServer account.
            <?php endif; ?>
        </small>
    </div>
</div>
<?php elseif ($isVpsProduct): ?>
<div class="admin-service-card" id="remote-link">
    <h2 class="admin-service-card__title">🔗 Client self-service is off</h2>
    <div class="admin-service-card__body">
        <p>This server is not assigned to <?= !empty($isDedicatedProduct) ? 'a Nocix' : 'an InterServer VPS' ?> server record, so the client's server buttons open support tickets.
            To turn on self-service: set <strong>Assigned Server</strong> below to your <?= !empty($isDedicatedProduct) ? 'Nocix Dedicated' : 'InterServer VPS' ?> server
            (<a href="/admin/servers" style="color:var(--cv-color-brand-500);text-decoration:underline;">add one</a> with your API credentials if there is none),
            save, then link the <?= !empty($isDedicatedProduct) ? 'server' : 'VPS' ?> in the card that appears here.</p>
    </div>
</div>
<?php endif; ?>

<div class="admin-service-card">
    <h2 class="admin-service-card__title">✏️ Edit Service Details</h2>
    <div class="admin-service-card__body">
        <form method="post" action="/admin/services/<?= $id ?>/edit"><?= csrf_field() ?>
            <div class="admin-service-field">
                <label>Username (login the client uses)</label>
                <input name="username" value="<?= e((string) ($service['username'] ?? '')) ?>" placeholder="e.g. cv123, root, or a remote service ID like 5001">
            </div>
            <div class="admin-service-field">
                <label>Password (login the client uses)</label>
                <input type="password" name="password" autocomplete="new-password" placeholder="<?= !empty($service['password']) ? '••••••••  (leave blank to keep the current one)' : 'e.g. the root or cPanel password' ?>">
                <small style="color:var(--cv-text-secondary);">Stored so it can be shown to the client and included in the service-details email. Leave blank to keep the existing password unchanged.</small>
            </div>
            <?php // A VPS or dedicated server is identified by its hostname — a domain adds nothing there, so the field is only rendered for products that actually use one (shared/reseller). ?>
            <?php if ($showDomainField): ?>
                <div class="admin-service-field">
                    <label>Domain</label>
                    <input name="domain" value="<?= e((string) ($service['domain'] ?? '')) ?>" placeholder="example.com">
                </div>
            <?php endif; ?>
            <div class="admin-service-field">
                <label>Hostname</label>
                <input name="hostname" value="<?= e((string) ($service['hostname'] ?? '')) ?>" placeholder="vps.example.com">
            </div>
            <div class="admin-service-field">
                <label>Primary IP (Main Server IP)</label>
                <input name="dedicated_ip" value="<?= e((string) ($service['dedicated_ip'] ?? '')) ?>" placeholder="e.g. 69.197.131.50">
            </div>
            <div class="admin-service-field">
                <label>Assigned Sub IPs (Additional IPs — 1 per line)</label>
                <textarea name="assigned_ips" rows="4" class="cv-input" style="width:100%;font-family:monospace;font-size:0.85rem;" placeholder="69.197.131.51&#10;69.197.131.52&#10;69.197.131.53"><?= e((string) ($service['assigned_ips'] ?? '')) ?></textarea>
                <small style="color:var(--cv-text-secondary);">Clients will see these additional IPs in their server details panel (1 per line).</small>
            </div>
            <div class="admin-service-field">
                <label>Assigned Server</label>
                <select name="server_id">
                    <option value="">None — No Server Assigned</option>
                    <?php foreach ($servers as $srv): ?>
                        <option value="<?= (int) $srv['id'] ?>" <?= ((int) ($service['server_id'] ?? 0) === (int) $srv['id']) ? 'selected' : '' ?>>
                            <?= e($srv['name']) ?> (<?= e($srv['module_slug']) ?><?= !empty($srv['group_name']) ? ' &bull; ' . e($srv['group_name']) : '' ?><?= !$srv['active'] ? ' &bull; Disabled' : '' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Includes all VPS, Dedicated, and Shared Hosting servers configured in WHMP (<a href="/admin/servers" target="_blank" style="color:var(--cv-color-brand-500);text-decoration:underline;">Manage Servers & Server Groups</a>).</small>
            </div>
            <div class="admin-service-field">
                <label>📅 Next Renewal Date</label>
                <input type="date" name="next_due_date" value="<?= e((string) ($service['next_due_date'] ?? '')) ?>">
                <small>When the next renewal invoice is generated. Leave unchanged unless the billing date moved — changing it here only affects future renewals and does not create or backdate an invoice.</small>
            </div>
            <div class="admin-service-field">
                <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;">
                    <input type="checkbox" name="set_package_price" value="1" style="width:auto;">
                    💲 Set recurring price to package price
                </label>
                <small>Rewrites this service's recurring amount (currently <strong><?= e($service['currency_symbol'] ?? '$') ?><?= number_format((float) $service['amount'], 2) ?></strong>) to the product's current catalog price for the <?= e($cycles[$service['billing_cycle']] ?? $service['billing_cycle']) ?> cycle. No order or proration is generated.</small>
            </div>
            <button class="admin-service-btn admin-service-btn--primary" type="submit">💾 Save Details</button>
        </form>

        <div style="margin-top:var(--cv-space-4);padding-top:var(--cv-space-4);border-top:1px solid var(--cv-border-default);">
            <?php // Second entry point to the same action, for when the admin has just edited the details above and wants to push them out without scrolling back up. ?>
            <form method="post" action="/admin/services/<?= $id ?>/send-details"
                  data-confirm="<?= !empty($service['details_sent_at']) ? 'Re-send' : 'Send' ?> the service details — including the password — to the client?"><?= csrf_field() ?>
                <button class="admin-service-btn admin-service-btn--secondary" type="submit">
                    📧 <?= !empty($service['details_sent_at']) ? 'Resend These Details to Client' : 'Email These Details to Client' ?>
                </button>
            </form>
            <small style="color:var(--cv-text-secondary);display:block;margin-top:var(--cv-space-2);">
                Sends the hostname, IPs, username and password to
                <strong><?= e((string) ($service['client_email'] ?? 'the client')) ?></strong>.
                Save your changes first — this sends what is currently stored.
            </small>
        </div>
    </div>
</div>

<div class="admin-service-card">
    <h2 class="admin-service-card__title">⬆️ Upgrade / Downgrade</h2>
    <div class="admin-service-card__body">
        <form method="post" action="/admin/services/<?= $id ?>/upgrade"><?= csrf_field() ?>
            <div class="admin-service-field">
                <label>New Product</label>
                <select name="product_id" required>
                    <option value="">Select a product</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= e($product['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="admin-service-field">
                <label>Proration Mode</label>
                <select name="proration_mode">
                    <?php foreach ($modes as $modeKey => $label): ?>
                        <option value="<?= e($modeKey) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p style="color:var(--cv-text-secondary);font-size:.85rem;margin:16px 0 0 0;">The new product must have pricing set for this service's current billing cycle (<?= e($cycles[$service['billing_cycle']] ?? $service['billing_cycle']) ?>).</p>
            <button class="admin-service-btn admin-service-btn--primary" type="submit" style="margin-top:16px;">⬆️ Upgrade Service</button>
        </form>
    </div>
</div>
