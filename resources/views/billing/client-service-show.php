<?php
/** @var array<string, mixed> $service */
/** @var array<string, mixed>|null $usage */
/** @var string|null $error */
/** @var string|null $message */
/** @var bool $cpanelToolsAvailable */
/** @var bool $domainChangerAvailable */
/** @var array<string, mixed> $currency */
/** @var array<string, mixed>|null $pendingCancellation */
/** @var string $kind 'shared' | 'vps' | 'dedicated' */
/** @var array<string, mixed> $remote live values read from the server module */
/** @var array<string, string> $reverseDns ip => current PTR */
/** @var array<int, array<string, mixed>> $backups */
/** @var array<int, array<string, mixed>> $osTemplates */
/** @var int $addonCount */

$id = (int) $service['id'];
$cpanelToolsAvailable ??= false;
$domainChangerAvailable ??= false;

// Which surface this service gets is decided by the controller from the
// assigned server's module, not re-derived here from the product name. The
// name-matching this replaced treated any product containing "cloud" as a
// VPS, so a shared plan called "Cloud Hosting" rendered power controls.
$kind ??= 'shared';
$isDedicated = $kind === 'dedicated';
$isVps = $kind === 'vps';
$isShared = $kind === 'shared';

$remote ??= [];
$reverseDns ??= [];
$backups ??= [];
$osTemplates ??= [];

$primaryIp = trim((string) ($service['dedicated_ip'] ?? $service['ip_address'] ?? ''));
if ($primaryIp === '' && ($remote['ip'] ?? '') !== '') {
    $primaryIp = (string) $remote['ip'];
}
$assignedIpsRaw = trim((string) ($service['assigned_ips'] ?? ''));
$assignedIps = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", "", $assignedIpsRaw)))));

// Every IP we can offer a PTR form for: whatever the module reported, plus
// anything recorded locally. Keyed so duplicates collapse.
$ptrTargets = $reverseDns;
foreach (array_merge($primaryIp !== '' ? [$primaryIp] : [], $assignedIps) as $ip) {
    $ptrTargets[$ip] ??= '';
}

/** @var string $formattedAmount already converted+formatted by the controller (services carry no currency lock, so it must use the live rate) */
$formattedAmount = e($formattedAmount);

// Real power state from the module. There is no default: the badge used to
// read "Running" unconditionally, including for a VPS that was stopped or
// had been terminated upstream.
$remoteStatus = trim((string) ($remote['status'] ?? ''));
$remoteStatusIsUp = in_array(strtolower($remoteStatus), ['active', 'running', 'online'], true);

$cpanelTabs = [
    'email' => ['Email Accounts', '✉️'],
    'ftp' => ['FTP Accounts', '📁'],
    'databases' => ['MySQL Databases', '🗄️'],
    'dns' => ['DNS Zone Editor', '🌐'],
    'domains' => ['Domains &amp; Redirects', '🔗'],
    'cron' => ['Cron Jobs', '⏱️'],
    'ssh' => ['SSH Access', '🔑'],
    'ssl' => ['SSL Certificates', '🔒'],
    'usage' => ['Disk Usage', '📊'],
    'logins' => ['Quick Logins', '🚀'],
];
?>

<style>
/* Modern WHMP Client Service Details Design */
.svc-container {
    max-width: 1100px;
    margin: 0 auto;
    font-family: 'Hanken Grotesk', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--cv-text-primary, #f8fafc);
}

.svc-breadcrumb {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
    color: var(--cv-text-secondary, #94a3b8);
    margin-bottom: 16px;
}
.svc-breadcrumb a {
    color: var(--cv-color-brand-500, #3b82f6);
    text-decoration: none;
    font-weight: 500;
}
.svc-breadcrumb a:hover {
    text-decoration: underline;
}

.svc-alert {
    padding: 14px 20px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.svc-alert--danger {
    background: rgba(239, 68, 68, 0.12);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #f87171;
}
.svc-alert--success {
    background: rgba(16, 185, 129, 0.12);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #34d399;
}

/* Hero Section */
.svc-hero-card {
    background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98));
    border: 1px solid var(--cv-border-default, rgba(255, 255, 255, 0.1));
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.svc-hero-card::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 300px;
    height: 300px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
    pointer-events: none;
}

.svc-hero-grid {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 36px;
    align-items: center;
}
@media (max-width: 768px) {
    .svc-hero-grid {
        grid-template-columns: 1fr;
        gap: 24px;
    }
}

.svc-hero-badge-box {
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 28px 20px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.svc-hero-icon {
    width: 72px;
    height: 72px;
    background: linear-gradient(135deg, #f97316, #ea580c);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: white;
    margin-bottom: 16px;
    box-shadow: 0 6px 20px rgba(249, 115, 22, 0.35);
}
.svc-hero-icon--vps {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.35);
}
.svc-hero-icon--cpanel {
    background: linear-gradient(135deg, #10b981, #059669);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.35);
}

.svc-hero-title {
    font-size: 1.4rem;
    font-weight: 800;
    margin: 0 0 4px 0;
    color: #ffffff;
}
.svc-hero-category {
    font-size: 0.85rem;
    color: #f97316;
    font-weight: 600;
    margin-bottom: 16px;
}
.svc-hero-category--vps { color: #60a5fa; }
.svc-hero-category--cpanel { color: #34d399; }

.svc-status-pill {
    display: inline-block;
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.svc-status-pill--active {
    background: rgba(16, 185, 129, 0.2);
    border: 1px solid rgba(16, 185, 129, 0.5);
    color: #34d399;
}
.svc-status-pill--suspended {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.5);
    color: #f87171;
}
.svc-status-pill--neutral {
    background: rgba(148, 163, 184, 0.2);
    border: 1px solid rgba(148, 163, 184, 0.4);
    color: #cbd5e1;
}

.svc-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
}
.svc-meta-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.svc-meta-label {
    font-size: 0.75rem;
    color: var(--cv-text-secondary, #94a3b8);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.svc-meta-val {
    font-size: 1rem;
    font-weight: 700;
    color: #ffffff;
}

/* Service Detail Cards */
.svc-card {
    background: var(--cv-bg-surface, #0f172a);
    border: 1px solid var(--cv-border-default, rgba(255, 255, 255, 0.1));
    border-radius: 16px;
    margin-bottom: 24px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
}
.svc-card__header {
    padding: 18px 24px;
    background: rgba(15, 23, 42, 0.6);
    border-bottom: 1px solid var(--cv-border-default, rgba(255, 255, 255, 0.08));
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.svc-card__title {
    font-size: 1.1rem;
    font-weight: 800;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff;
}
.svc-card__body {
    padding: 24px;
}

/* Server Info Table Grid */
.svc-info-grid {
    display: grid;
    grid-template-columns: 160px 1fr;
    gap: 16px 24px;
    font-size: 0.95rem;
    align-items: start;
}
.svc-info-label {
    font-weight: 700;
    color: var(--cv-text-secondary, #94a3b8);
    text-align: right;
}
@media (max-width: 600px) {
    .svc-info-grid {
        grid-template-columns: 1fr;
        gap: 6px;
    }
    .svc-info-label {
        text-align: left;
    }
}
.svc-info-val {
    /* Follows the theme, because this sits inside .svc-card__body whose
       background is var(--cv-bg-surface) — white in light mode. Hardcoding
       #ffffff here made every value in this grid invisible on a light theme:
       the cPanel primary domain and username, and the VPS hostname, IPs and
       nameservers, were all white text on a white card. The fallback keeps the
       original colour for any theme that doesn't define the token. */
    color: var(--cv-text-primary, #ffffff);
    font-weight: 600;
}
.svc-ip-badge {
    display: inline-block;
    background: rgba(59, 130, 246, 0.12);
    border: 1px solid rgba(59, 130, 246, 0.3);
    color: #60a5fa;
    padding: 4px 10px;
    border-radius: 6px;
    font-family: monospace;
    font-size: 0.9rem;
    font-weight: 700;
    margin: 2px 4px 4px 0;
}

/* Management Action Grid */
.svc-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
}
.svc-action-card {
    background: rgba(30, 41, 59, 0.5);
    border: 1px solid var(--cv-border-default, rgba(255, 255, 255, 0.08));
    border-radius: 12px;
    padding: 16px;
    transition: all 0.2s ease;
}
.svc-action-card:hover {
    border-color: rgba(59, 130, 246, 0.4);
    background: rgba(30, 41, 59, 0.8);
}
.svc-action-card h4 {
    margin: 0 0 6px 0;
    font-size: 0.95rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
}
.svc-action-card p {
    margin: 0 0 12px 0;
    font-size: 0.8rem;
    color: var(--cv-text-secondary, #94a3b8);
    line-height: 1.4;
}

/* Buttons */
.svc-btn {
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
    font-size: 0.85rem;
    cursor: pointer;
    border: none;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all 0.2s;
}
.svc-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: #ffffff;
}
.svc-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
}
.svc-btn--success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff;
}
.svc-btn--danger {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.4);
    color: #f87171;
}
.svc-btn--danger:hover {
    background: rgba(239, 68, 68, 0.25);
    color: #ffffff;
}
.svc-btn--secondary {
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.15);
    color: #ffffff;
}
.svc-btn--secondary:hover {
    background: rgba(255, 255, 255, 0.15);
}

.svc-power-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
</style>

<div class="svc-container">

    <!-- Top Navigation / Breadcrumb -->
    <div class="svc-breadcrumb">
        <a href="/client">Portal Home</a> / 
        <a href="/client">Client Area</a> / 
        <a href="/client/services">My Products &amp; Services</a> / 
        <span>Product Details</span>
    </div>

    <!-- Alert Banners -->
    <?php if ($pendingCancellation !== null): ?>
        <div class="svc-alert svc-alert--danger">
            ⚠️ <strong>There is an outstanding cancellation request for this product/service.</strong>
            <span style="font-size:0.85rem;margin-left:auto;">Effective Date: <?= e($service['next_due_date']) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($message)): ?>
        <div class="svc-alert svc-alert--success">
            ✅ <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="svc-alert svc-alert--danger">
            ⚠️ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Main Hero Service Summary Card -->
    <div class="svc-hero-card">
        <div class="svc-hero-grid">
            
            <!-- Left Icon & Status Box -->
            <div class="svc-hero-badge-box">
                <?php if ($isDedicated): ?>
                    <div class="svc-hero-icon">🖥️</div>
                    <h3 class="svc-hero-title"><?= e($service['product_name']) ?></h3>
                    <div class="svc-hero-category">Dedicated Server</div>
                <?php elseif ($isVps): ?>
                    <div class="svc-hero-icon svc-hero-icon--vps">⚡</div>
                    <h3 class="svc-hero-title"><?= e($service['product_name']) ?></h3>
                    <div class="svc-hero-category svc-hero-category--vps">VPS Cloud Server</div>
                <?php else: ?>
                    <div class="svc-hero-icon svc-hero-icon--cpanel">🌐</div>
                    <h3 class="svc-hero-title"><?= e($service['product_name']) ?></h3>
                    <div class="svc-hero-category svc-hero-category--cpanel">Web Hosting</div>
                <?php endif; ?>

                <?php if ($service['status'] === 'active'): ?>
                    <span class="svc-status-pill svc-status-pill--active">Active</span>
                <?php elseif ($service['status'] === 'suspended'): ?>
                    <span class="svc-status-pill svc-status-pill--suspended">Suspended</span>
                <?php else: ?>
                    <span class="svc-status-pill svc-status-pill--neutral"><?= e(strtoupper($service['status'])) ?></span>
                <?php endif; ?>

                <?php if ($pendingCancellation !== null): ?>
                    <div style="margin-top:10px;font-size:0.75rem;color:#f87171;font-weight:700;">Cancellation Requested</div>
                <?php endif; ?>
            </div>

            <!-- Right Meta Info Grid -->
            <div>
                <div class="svc-meta-grid">
                    <div class="svc-meta-item">
                        <span class="svc-meta-label">Registration Date</span>
                        <span class="svc-meta-val"><?= e(date('F jS, Y', strtotime($service['created_at'] ?? 'now'))) ?></span>
                    </div>

                    <div class="svc-meta-item">
                        <span class="svc-meta-label">Recurring Amount</span>
                        <span class="svc-meta-val" style="color:#60a5fa;"><?= $formattedAmount ?></span>
                    </div>

                    <div class="svc-meta-item">
                        <span class="svc-meta-label">Billing Cycle</span>
                        <span class="svc-meta-val"><?= e(ucfirst($service['billing_cycle'])) ?></span>
                    </div>

                    <div class="svc-meta-item">
                        <span class="svc-meta-label">Next Due Date</span>
                        <span class="svc-meta-val"><?= e(date('F jS, Y', strtotime($service['next_due_date']))) ?></span>
                    </div>

                    <div class="svc-meta-item">
                        <span class="svc-meta-label">Payment Method</span>
                        <span class="svc-meta-val">Default Gateway</span>
                    </div>
                </div>

                <!-- Action Buttons Area -->
                <div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
                    <?php if ($isShared && $service['status'] === 'active'): ?>
                        <form method="post" action="/client/services/<?= $id ?>/sso" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="svc-btn svc-btn--primary" type="submit">🔑 Log In to Control Panel</button>
                        </form>
                        <?php if ($cpanelToolsAvailable): ?>
                            <a class="svc-btn svc-btn--secondary" href="/client/services/<?= $id ?>/cpanel-tools">cPanel Tools</a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($isVps && $service['status'] === 'active'): ?>
                        <form method="post" action="/client/services/<?= $id ?>/vnc" style="margin:0;">
                            <?= csrf_field() ?>
                            <button class="svc-btn svc-btn--primary" type="submit">🖥️ VNC Console</button>
                        </form>
                    <?php endif; ?>

                    <?php if (!in_array($service['status'], ['cancelled', 'terminated'], true) && $pendingCancellation === null): ?>
                        <a class="svc-btn svc-btn--secondary" href="/client/services/<?= $id ?>/upgrade" style="text-decoration:none;">⇅ Upgrade / Downgrade</a>
                    <?php endif; ?>

                    <?php if ($service['status'] === 'active'): ?>
                        <a class="svc-btn svc-btn--secondary" href="/client/services/<?= $id ?>/addons" style="text-decoration:none;">
                            ➕ Add-ons<?= (int) ($addonCount ?? 0) > 0 ? ' (' . (int) $addonCount . ')' : '' ?>
                        </a>
                    <?php endif; ?>

                    <?php if (!in_array($service['status'], ['cancelled', 'terminated'], true) && $pendingCancellation === null): ?>
                        <form method="post" action="/client/services/<?= $id ?>/cancel-request" style="margin:0;" data-confirm="Are you sure you want to request cancellation for this service?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="end_of_period">
                            <input type="hidden" name="reason" value="Requested from client portal">
                            <button class="svc-btn svc-btn--danger" type="submit">❌ Request Cancellation</button>
                        </form>
                    <?php endif; ?>

                    <a class="svc-btn svc-btn--secondary" href="/client/services">← Back to My Services</a>
                </div>
            </div>

        </div>
    </div>

    <?php if ($isShared): ?>
        <!--
            Shared hosting deliberately has no "Server Technical Information"
            card. Primary IP, assigned IPs and nameservers describe a machine
            the client does not control and cannot act on — on a shared plan
            they are shared infrastructure, not the customer's. cPanel Tools
            is the surface that matters here, so it takes that slot.
        -->
        <div class="svc-card">
            <div class="svc-card__header">
                <h2 class="svc-card__title">🛠️ cPanel Tools</h2>
                <?php if ($cpanelToolsAvailable && $service['status'] === 'active'): ?>
                    <a class="svc-btn svc-btn--secondary" style="padding:6px 12px;font-size:0.78rem;" href="/client/services/<?= $id ?>/cpanel-tools">Open all tools →</a>
                <?php endif; ?>
            </div>
            <div class="svc-card__body">
                <?php if (!$cpanelToolsAvailable): ?>
                    <p style="margin:0;color:var(--cv-text-secondary);font-size:0.9rem;">
                        Management tools become available once this account is provisioned on a cPanel server.
                    </p>
                <?php elseif ($service['status'] !== 'active'): ?>
                    <p style="margin:0;color:var(--cv-text-secondary);font-size:0.9rem;">
                        cPanel Tools are available while the service is active. This service is currently
                        <strong><?= e((string) $service['status']) ?></strong>.
                    </p>
                <?php else: ?>
                    <div class="svc-info-grid" style="grid-template-columns:160px 1fr;margin-bottom:20px;">
                        <div class="svc-info-label">Primary Domain:</div>
                        <div class="svc-info-val">
                            <?= e($service['domain'] ?: $service['hostname'] ?: 'Not configured') ?>
                            <?php if ($domainChangerAvailable): ?>
                                <button type="button" class="svc-btn svc-btn--secondary" style="padding:2px 10px;font-size:0.72rem;margin-left:8px;"
                                    data-toggle-target="#change-domain-form-<?= $id ?>">✏️ Change</button>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($service['username'])): ?>
                            <div class="svc-info-label">cPanel Username:</div>
                            <div class="svc-info-val"><?= e((string) $service['username']) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if ($domainChangerAvailable): ?>
                        <div id="change-domain-form-<?= $id ?>" style="display:none;margin-bottom:20px;padding:16px;border:1px dashed var(--cv-border-default);border-radius:8px;">
                            <form method="post" action="/client/services/<?= $id ?>/change-domain" style="margin:0;" data-confirm="Change the primary domain for this hosting account? Existing mail and site content stays on the account, but anything pointed at the old domain must be updated separately.">
                                <?= csrf_field() ?>
                                <label class="cv-label" for="new_domain-<?= $id ?>" style="font-size:0.8rem;">New primary domain</label>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                                    <input class="cv-input" id="new_domain-<?= $id ?>" name="new_domain" placeholder="newdomain.com" required style="flex:1;min-width:200px;">
                                    <button class="svc-btn svc-btn--primary" type="submit">Change Domain</button>
                                </div>
                                <p style="font-size:0.75rem;color:var(--cv-text-secondary);margin:8px 0 0;">
                                    Applied instantly via the cPanel/WHM API — no support ticket needed. Point the new domain's
                                    nameservers here first, or the account won't yet be reachable on it.
                                </p>
                            </form>
                        </div>
                    <?php endif; ?>

                    <div class="svc-actions-grid">
                        <?php foreach ($cpanelTabs as $tabKey => [$tabLabel, $tabIcon]): ?>
                            <a class="svc-action-card" style="text-decoration:none;color:inherit;display:block;"
                               href="/client/services/<?= $id ?>/cpanel-tools?tab=<?= e($tabKey) ?>">
                                <h4><?= $tabIcon ?> <?= $tabLabel ?></h4>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
    <!-- Server Technical Information Card -->
    <div class="svc-card">
        <div class="svc-card__header">
            <h2 class="svc-card__title">🌐 Server Technical Information</h2>
        </div>
        <div class="svc-card__body">
            <div class="svc-info-grid">
                <div class="svc-info-label">Hostname:</div>
                <div class="svc-info-val"><?= e($service['domain'] ?: $service['hostname'] ?: 'Not Configured') ?></div>

                <?php
                // Never invent network details. These three blocks used to fall
                // back to hardcoded sample values — 69.197.131.50 as the primary
                // IP, .51-.54 as "Assigned IPs", and two literal nameserver
                // hostnames — so a VPS with nothing recorded showed a client four
                // IP addresses that were not theirs and did not exist. A client
                // could reasonably have tried to configure DNS or firewall rules
                // against them. An empty field says "not assigned"; it must never
                // fill itself in with an example.
                ?>
                <div class="svc-info-label">Primary IP:</div>
                <div class="svc-info-val">
                    <?php if ($primaryIp !== ''): ?>
                        <span class="svc-ip-badge"><?= e($primaryIp) ?></span>
                    <?php else: ?>
                        <span style="color:var(--cv-text-secondary);">Not assigned yet</span>
                    <?php endif; ?>
                </div>

                <?php // Only render the row at all when there is something real to show. ?>
                <?php if ($assignedIps !== []): ?>
                    <div class="svc-info-label">Assigned IPs:</div>
                    <div class="svc-info-val">
                        <?php foreach ($assignedIps as $ip): ?>
                            <span class="svc-ip-badge"><?= e($ip) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Dedicated Server Management & Controls -->
    <?php if ($isDedicated): ?>
        <div class="svc-card">
            <div class="svc-card__header">
                <h2 class="svc-card__title">🖥️ Dedicated Server Management</h2>
            </div>
            <div class="svc-card__body">
                <?php
                // Nocix's published API covers disconnect/reconnect, bandwidth
                // and service listing — it documents no reverse-DNS endpoint,
                // so a PTR change is a request an operator fulfils. Submitting
                // this opens a real support ticket rather than calling an API
                // that does not exist and reporting success anyway.
                ?>
                <div style="background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;">
                    <h4 style="margin:0 0 8px 0;font-size:0.95rem;font-weight:700;">🌐 Reverse DNS (PTR) Request</h4>
                    <p style="font-size:0.85rem;color:var(--cv-text-secondary);margin-top:0;">
                        Submit the PTR hostname you want for one of your IPs. This opens a support ticket and our team applies it.
                    </p>
                    <form method="post" action="/client/services/<?= $id ?>/rdns">
                        <?= csrf_field() ?>
                        <div style="display:flex;gap:12px;max-width:640px;align-items:center;flex-wrap:wrap;">
                            <?php if ($ptrTargets !== []): ?>
                                <select class="cv-select" name="ip" required style="flex:0 0 200px;">
                                    <option value="">Select IP…</option>
                                    <?php foreach (array_keys($ptrTargets) as $ip): ?>
                                        <option value="<?= e((string) $ip) ?>"><?= e((string) $ip) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="cv-input" name="ip" placeholder="IP address" required style="flex:0 0 200px;">
                            <?php endif; ?>
                            <input class="cv-input" name="rdns" placeholder="ptr.yourdomain.com" required style="flex:1;min-width:200px;">
                            <button class="svc-btn svc-btn--primary" type="submit">Submit Request</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- VPS Server Management & Controls -->
    <?php if ($isVps): ?>
        <div class="svc-card">
            <div class="svc-card__header">
                <h2 class="svc-card__title">⚡ VPS Server Management &amp; Tools</h2>
                <?php
                // Live vps_status from the module. This badge used to be a
                // literal "Running" with a green dot, shown even for a VPS
                // that was stopped, suspended upstream, or unreachable.
                ?>
                <?php if ($remoteStatus !== ''): ?>
                    <span class="svc-status-pill <?= $remoteStatusIsUp ? 'svc-status-pill--active' : 'svc-status-pill--neutral' ?>" style="display:inline-flex;align-items:center;gap:6px;">
                        <span style="width:8px;height:8px;background:<?= $remoteStatusIsUp ? '#34d399' : '#cbd5e1' ?>;border-radius:50%;display:inline-block;"></span>
                        <?= e(ucfirst($remoteStatus)) ?>
                    </span>
                <?php else: ?>
                    <span class="svc-status-pill svc-status-pill--neutral">Status unavailable</span>
                <?php endif; ?>
            </div>
            <div class="svc-card__body">

                <?php
                // Real usage only. The defaults here used to be 245760 / 2097152
                // MB, so a server the module reported nothing for still showed
                // "240.00 GB / 2,048 GB" — an invented figure a client would
                // read as their actual traffic. The bar was a fixed width:12%
                // to match, regardless of the numbers beside it.
                $bwUsedMb = isset($usage['bandwidthUsedMb']) ? (float) $usage['bandwidthUsedMb'] : null;
                $bwLimitMb = isset($usage['bandwidthLimitMb']) ? (float) $usage['bandwidthLimitMb'] : null;
                $bwPercent = ($bwUsedMb !== null && $bwLimitMb !== null && $bwLimitMb > 0)
                    ? max(0, min(100, ($bwUsedMb / $bwLimitMb) * 100))
                    : null;
                ?>
                <!-- Bandwidth / Traffic Usage Meter -->
                <div style="background:rgba(15,23,42,0.6);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:20px;margin-bottom:24px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <span style="font-weight:700;font-size:0.9rem;">📊 Bandwidth / Traffic Usage</span>
                        <span style="font-size:0.85rem;color:#60a5fa;font-weight:700;">
                            <?php if ($bwUsedMb === null): ?>
                                <span style="color:var(--cv-text-secondary);font-weight:400;">Not reported by this server</span>
                            <?php else: ?>
                                <?= e(number_format($bwUsedMb / 1024, 2)) ?> GB<?php if ($bwLimitMb !== null && $bwLimitMb > 0): ?> / <?= e(number_format($bwLimitMb / 1024, 0)) ?> GB<?php endif; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($bwPercent !== null): ?>
                        <div style="width:100%;height:10px;background:rgba(255,255,255,0.1);border-radius:5px;overflow:hidden;">
                            <div style="width:<?= e(number_format($bwPercent, 2)) ?>%;height:100%;background:linear-gradient(90deg, #3b82f6, #60a5fa);border-radius:5px;"></div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- VPS Action Cards Grid -->
                <div class="svc-actions-grid">

                    <!-- Power Controls Card -->
                    <div class="svc-action-card">
                        <h4>⚡ Server Power Status</h4>
                        <p>Control virtual server power state (Start / Restart / Stop).</p>
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <form method="post" action="/client/services/<?= $id ?>/power" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="start">
                                <button class="svc-btn svc-btn--success" style="padding:6px 12px;font-size:0.75rem;" type="submit">▶️ Start</button>
                            </form>
                            <form method="post" action="/client/services/<?= $id ?>/power" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="restart">
                                <button class="svc-btn svc-btn--primary" style="padding:6px 12px;font-size:0.75rem;" type="submit">🔄 Restart</button>
                            </form>
                            <form method="post" action="/client/services/<?= $id ?>/power" style="margin:0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="stop">
                                <button class="svc-btn svc-btn--danger" style="padding:6px 12px;font-size:0.75rem;" type="submit">⏹️ Stop</button>
                            </form>
                        </div>
                    </div>

                    <!-- OS Reinstall Card -->
                    <div class="svc-action-card">
                        <h4>💿 Reinstall OS</h4>
                        <?php
                        // Templates come from the hypervisor, keyed by
                        // template_file. The hardcoded ubuntu24/debian12 list
                        // that used to sit here was not what the API accepts
                        // and did not reflect what this VPS can actually run.
                        ?>
                        <?php if ($osTemplates === []): ?>
                            <p>Available operating systems could not be read from the server right now.</p>
                        <?php else: ?>
                            <p>Requests a fresh OS install. This erases all data, so our team confirms with you first.</p>
                            <form method="post" action="/client/services/<?= $id ?>/reinstall" data-confirm="Request an OS reinstall? All current data will be erased.">
                                <?= csrf_field() ?>
                                <select class="cv-select" name="template" required style="width:100%;margin-bottom:8px;font-size:0.8rem;">
                                    <?php foreach ($osTemplates as $template): ?>
                                        <option value="<?= e((string) $template['file']) ?>"><?= e((string) ($template['name'] !== '' ? $template['name'] : $template['file'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="svc-btn svc-btn--primary" style="width:100%;">Request Reinstall</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <!-- Reverse DNS Card -->
                    <div class="svc-action-card">
                        <h4>🌐 Reverse DNS (rDNS)</h4>
                        <p>Set the PTR record for a specific IP on this VPS.</p>
                        <form method="post" action="/client/services/<?= $id ?>/rdns">
                            <?= csrf_field() ?>
                            <?php // A PTR belongs to one IP — the old form named none, so nothing was ever updated. ?>
                            <?php if ($ptrTargets !== []): ?>
                                <select class="cv-select" name="ip" required style="width:100%;margin-bottom:8px;font-size:0.8rem;">
                                    <?php foreach ($ptrTargets as $ip => $currentPtr): ?>
                                        <option value="<?= e((string) $ip) ?>">
                                            <?= e((string) $ip) ?><?= $currentPtr !== '' ? ' → ' . e((string) $currentPtr) : ' (no PTR set)' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="cv-input" name="ip" placeholder="IP address" required style="width:100%;margin-bottom:8px;font-size:0.8rem;">
                            <?php endif; ?>
                            <input class="cv-input" name="rdns" placeholder="ptr.example.com" required style="width:100%;margin-bottom:8px;font-size:0.8rem;">
                            <button type="submit" class="svc-btn svc-btn--primary" style="width:100%;">Update PTR</button>
                        </form>
                    </div>

                    <!-- VNC Console Card -->
                    <div class="svc-action-card">
                        <h4>🖥️ Setup VNC / Console</h4>
                        <p>Read out-of-band VNC console connection details for this VPS.</p>
                        <form method="post" action="/client/services/<?= $id ?>/vnc">
                            <?= csrf_field() ?>
                            <button type="submit" class="svc-btn svc-btn--primary" style="width:100%;">Get Console Details</button>
                        </form>
                    </div>

                    <!-- Backup VPS Card -->
                    <div class="svc-action-card">
                        <h4>💾 Backup VPS</h4>
                        <p>Queue an on-demand snapshot of your virtual server disk.</p>
                        <form method="post" action="/client/services/<?= $id ?>/backup">
                            <?= csrf_field() ?>
                            <button type="submit" class="svc-btn svc-btn--secondary" style="width:100%;">Create Snapshot</button>
                        </form>
                    </div>

                    <!-- Restore VPS Card -->
                    <div class="svc-action-card">
                        <h4>🔄 Restore VPS</h4>
                        <?php // Restore is keyed to a specific snapshot — there is no "restore latest". ?>
                        <?php if ($backups === []): ?>
                            <p>No snapshots available to restore from. Create one first.</p>
                        <?php else: ?>
                            <p>Requests a restore from one of your snapshots. This overwrites the disk, so our team confirms first.</p>
                            <form method="post" action="/client/services/<?= $id ?>/restore" data-confirm="Request a restore? This overwrites the current disk.">
                                <?= csrf_field() ?>
                                <select class="cv-select" name="backup" required style="width:100%;margin-bottom:8px;font-size:0.8rem;">
                                    <?php foreach ($backups as $backup): ?>
                                        <option value="<?= e((string) $backup['ref']) ?>">
                                            <?= e((string) $backup['name']) ?><?php if (($backup['sizeBytes'] ?? null) !== null): ?> (<?= e(number_format($backup['sizeBytes'] / 1048576, 1)) ?> MB)<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="svc-btn svc-btn--secondary" style="width:100%;">Request Restore</button>
                            </form>
                        <?php endif; ?>
                    </div>

                </div>

            </div>
        </div>
    <?php endif; ?>

</div>
