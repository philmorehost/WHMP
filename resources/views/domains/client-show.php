<?php
/** @var array<string, mixed> $domain */
/** @var string|null $eppCode */
/** @var string|null $eppError */
$id = (int) $domain['id'];
$ns = json_decode((string) ($domain['nameservers'] ?? '[]'), true) ?: [];
?>
<style>
/* ====== Domain Detail Page Styles ====== */
.domain-detail-hero {
    background: linear-gradient(135deg, #1a1a2e 0%, #16213e 45%, #0f3460 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.domain-detail-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(245,158,11,.12) 0%, transparent 70%);
    pointer-events: none;
}
.domain-detail-hero__content {
    position: relative;
    z-index: 1;
}
.domain-detail-hero__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #f59e0b;
    text-decoration: none;
    font-weight: 600;
    font-size: .95rem;
    margin-bottom: 16px;
    transition: all 0.2s;
}
.domain-detail-hero__back:hover {
    gap: 12px;
    color: #fbbf24;
}
.domain-detail-hero__domain {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2.5rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 12px 0;
    line-height: 1.2;
    word-break: break-all;
}
.domain-detail-hero__meta {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    font-size: .95rem;
    color: rgba(255,255,255,.75);
    align-items: center;
}
.domain-detail-hero__badge {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
}

/* Tabs */
.domain-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 32px;
    border-bottom: 2px solid var(--cv-border-default);
    overflow-x: auto;
    padding-bottom: 12px;
}
.domain-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    color: var(--cv-text-secondary);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-size: .95rem;
    white-space: nowrap;
}
.domain-tab:hover {
    color: var(--cv-text-primary);
}
.domain-tab.active {
    color: var(--cv-color-brand-500);
    border-bottom: 3px solid var(--cv-color-brand-500);
    margin-bottom: -12px;
    padding-bottom: 9px;
}
.domain-tab-content {
    display: none;
}
.domain-tab-content.active {
    display: block;
    animation: fadeIn 0.2s ease-in;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Tab Content Card */
.domain-tab-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 16px;
    padding: 32px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
}

/* Status Grid */
.status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 32px;
}
.status-item {
    padding: 16px;
    background: var(--cv-bg-surface-sunken);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
}
.status-item__label {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 8px;
    display: block;
}
.status-item__value {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
}

/* Action Buttons */
.action-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-top: 24px;
}
.action-btn {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 16px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
    text-align: center;
    display: inline-block;
}
.action-btn:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.action-btn--secondary {
    background: linear-gradient(135deg, #f59e0b, #d97706);
}
.action-btn--secondary:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    box-shadow: 0 8px 16px rgba(245,158,11,.3);
}

/* EPP Code Display */
.epp-code-display {
    background: var(--cv-bg-surface-sunken);
    border: 2px dashed var(--cv-border-default);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
    margin-top: 20px;
}
.epp-code-display__label {
    color: var(--cv-text-secondary);
    font-size: .85rem;
    font-weight: 600;
    text-transform: uppercase;
    margin-bottom: 12px;
    display: block;
}
.epp-code-display__code {
    font-family: 'Monaco', 'Courier New', monospace;
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--cv-color-brand-500);
    user-select: all;
    word-break: break-all;
    margin: 0;
}
.epp-code-display__copy {
    background: var(--cv-color-brand-500);
    color: white;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-weight: 700;
    font-size: .8rem;
    cursor: pointer;
    margin-top: 12px;
    transition: all 0.2s;
}
.epp-code-display__copy:hover {
    background: var(--cv-color-brand-600);
    transform: translateY(-1px);
}

/* Table Styles */
.records-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
    margin-bottom: 24px;
}
.records-table thead {
    background: var(--cv-bg-surface-sunken);
}
.records-table th {
    padding: 12px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    border-bottom: 2px solid var(--cv-border-default);
}
.records-table td {
    padding: 12px;
    border-bottom: 1px solid var(--cv-border-default);
    color: var(--cv-text-primary);
}
.records-table tbody tr:hover {
    background: var(--cv-bg-surface-sunken);
}
.record-type-badge {
    display: inline-block;
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
    padding: 4px 8px;
    border-radius: 6px;
    font-weight: 700;
    font-size: .75rem;
}
.record-delete-btn {
    background: #ef4444;
    color: white;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
}
.record-delete-btn:hover {
    background: #dc2626;
}

/* Form Sections */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

/* Alert Messages */
.alert-success {
    background: rgba(16,185,129,0.1);
    border: 1px solid rgba(16,185,129,0.3);
    color: #059669;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: .9rem;
}
.alert-error {
    background: rgba(239,68,68,0.1);
    border: 1px solid rgba(239,68,68,0.3);
    color: #dc2626;
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: .9rem;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .domain-detail-hero {
        padding: 32px 24px;
    }
    .domain-detail-hero__domain {
        font-size: 1.75rem;
    }
    .domain-tab-card {
        padding: 20px;
    }
    .status-grid {
        grid-template-columns: 1fr;
    }
    .action-buttons {
        grid-template-columns: 1fr;
    }
}
</style>

<div style="max-width: 1200px; margin: 0 auto;">
    <!-- Hero Section -->
    <div class="domain-detail-hero">
        <div class="domain-detail-hero__content">
            <a href="/client/domains" class="domain-detail-hero__back">
                <span>←</span>
                <span>Back to Domains</span>
            </a>
            <h1 class="domain-detail-hero__domain"><?= e($domain['domain_name']) ?></h1>
            <div class="domain-detail-hero__meta">
                <span class="domain-detail-hero__badge"><?= e($domain['status']) ?></span>
                <span>Expires <?= e((string) ($domain['expiry_date'] ?? 'N/A')) ?></span>
            </div>
        </div>
    </div>

    <!-- Tabs Navigation -->
    <?php // Delegated tab handler lives in app.js — inline onclick is blocked by this app's CSP. ?>
    <div class="domain-tabs" data-tabs data-tab-panels=".domain-tab-content">
        <button type="button" class="domain-tab active" data-tab-target="overview">📋 Overview</button>
        <button type="button" class="domain-tab" data-tab-target="nameservers">🔗 Nameservers</button>
        <button type="button" class="domain-tab" data-tab-target="dns">🔍 DNS Records</button>
        <button type="button" class="domain-tab" data-tab-target="advanced">⚙️ Advanced</button>
    </div>

    <!-- TAB 1: OVERVIEW -->
    <div id="overview" class="domain-tab-content active">
        <div class="domain-tab-card">
            <h2 style="font-family: 'Hanken Grotesk', sans-serif; font-size: 1.5rem; font-weight: 800; margin: 0 0 24px 0;">Domain Overview</h2>

            <!-- Status Grid -->
            <div class="status-grid">
                <div class="status-item">
                    <span class="status-item__label">Status</span>
                    <span class="status-item__value"><?= e($domain['status']) ?></span>
                </div>
                <div class="status-item">
                    <span class="status-item__label">Expiry Date</span>
                    <span class="status-item__value"><?= e((string) ($domain['expiry_date'] ?? '-')) ?></span>
                </div>
                <div class="status-item">
                    <span class="status-item__label">Registrar Lock</span>
                    <span class="status-item__value"><?= $domain['registrar_lock_enabled'] ? '🔒 Locked' : '🔓 Unlocked' ?></span>
                </div>
                <div class="status-item">
                    <span class="status-item__label">ID Protection</span>
                    <span class="status-item__value"><?= $domain['id_protection_enabled'] ? '✓ Enabled' : '✗ Disabled' ?></span>
                </div>
            </div>

            <!-- Alerts -->
            <?php if (!empty($msg)): ?>
                <div class="alert-success"><?= e($msg) ?></div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <form method="post" action="/client/domains/<?= $id ?>/lock" style="margin: 0;">
                    <?= csrf_field() ?>
                    <button class="action-btn" type="submit"><?= $domain['registrar_lock_enabled'] ? '🔓 Unlock Domain' : '🔒 Lock Domain' ?></button>
                </form>
                <form method="post" action="/client/domains/<?= $id ?>/id-protection" style="margin: 0;">
                    <?= csrf_field() ?>
                    <button class="action-btn action-btn--secondary" type="submit"><?= $domain['id_protection_enabled'] ? '🛡️ Disable ID Protection' : '🛡️ Enable ID Protection' ?></button>
                </form>
                <form method="post" action="/client/domains/<?= $id ?>/epp-code" style="margin: 0;">
                    <?= csrf_field() ?>
                    <button class="action-btn" type="submit">🔐 Get EPP Code</button>
                </form>
            </div>

            <!-- EPP Code Display -->
            <?php if ($eppCode !== null): ?>
                <div class="epp-code-display">
                    <span class="epp-code-display__label">Your EPP / Auth Code</span>
                    <p class="epp-code-display__code" id="epp-code"><?= e($eppCode) ?></p>
                    <button type="button" class="epp-code-display__copy" data-copy-from="epp-code">📋 Copy Code</button>
                </div>
            <?php elseif (!empty($eppError)): ?>
                <div class="alert-error"><?= e($eppError) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- TAB 2: NAMESERVERS -->
    <div id="nameservers" class="domain-tab-content">
        <div class="domain-tab-card">
            <h2 style="font-family: 'Hanken Grotesk', sans-serif; font-size: 1.5rem; font-weight: 800; margin: 0 0 24px 0;">Manage Nameservers</h2>
            <p style="color: var(--cv-text-secondary); margin-bottom: 24px;">Update your domain's nameservers to point to your hosting provider or DNS service.</p>

            <form method="post" action="/client/domains/<?= $id ?>/nameservers">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <div class="cv-field">
                            <label class="cv-label">Nameserver <?= $i ?></label>
                            <input class="cv-input" name="ns<?= $i ?>" value="<?= e((string) ($ns[$i - 1] ?? '')) ?>" placeholder="ns<?= $i ?>.example.com">
                        </div>
                    <?php endfor; ?>
                </div>
                <button class="action-btn" type="submit" style="margin-top: 20px;">💾 Save Nameservers</button>
            </form>
        </div>
    </div>

    <!-- TAB 3: DNS RECORDS -->
    <div id="dns" class="domain-tab-content">
        <div class="domain-tab-card">
            <h2 style="font-family: 'Hanken Grotesk', sans-serif; font-size: 1.5rem; font-weight: 800; margin: 0 0 24px 0;">DNS Records</h2>
            <p style="color: var(--cv-text-secondary); margin-bottom: 24px;">Manage your DNS host records (A, AAAA, CNAME, MX, TXT).</p>

            <!-- Existing Records Table -->
            <?php if (!empty($dnsRecords)): ?>
                <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0 0 16px 0;">Current Records</h3>
                <div style="overflow-x: auto; margin-bottom: 32px;">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Host / Name</th>
                                <th>Value / Target</th>
                                <th style="width: 80px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dnsRecords as $rec): ?>
                                <tr>
                                    <td><span class="record-type-badge"><?= e($rec['type']) ?></span></td>
                                    <td><strong><?= e($rec['name']) ?></strong></td>
                                    <td style="word-break: break-all;"><?= e($rec['content']) ?></td>
                                    <td style="text-align: center;">
                                        <form method="post" action="/client/domains/<?= $id ?>/dns/<?= (int)$rec['id'] ?>/delete" style="display: inline;" data-confirm="Delete this DNS record?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="record-delete-btn">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Add New DNS Record Form -->
            <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0 0 16px 0;">Add New Record</h3>
            <form method="post" action="/client/domains/<?= $id ?>/dns">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="cv-field">
                        <label class="cv-label">Record Type</label>
                        <select class="cv-select" name="type" required>
                            <option value="">Select type...</option>
                            <option value="A">A Record (IPv4 Address)</option>
                            <option value="AAAA">AAAA Record (IPv6 Address)</option>
                            <option value="CNAME">CNAME Record (Alias)</option>
                            <option value="MX">MX Record (Mail Exchange)</option>
                            <option value="TXT">TXT Record (Text)</option>
                        </select>
                    </div>
                    <div class="cv-field">
                        <label class="cv-label">Host / Subdomain</label>
                        <input class="cv-input" name="name" value="@" placeholder="@ or subdomain" required>
                    </div>
                    <div class="cv-field">
                        <label class="cv-label">Value / Target</label>
                        <input class="cv-input" name="content" placeholder="Destination IP or hostname" required>
                    </div>
                    <div class="cv-field">
                        <label class="cv-label">Priority (MX only)</label>
                        <input class="cv-input" type="number" name="priority" value="10" min="0" max="65535">
                    </div>
                </div>
                <button class="action-btn" type="submit" style="margin-top: 20px;">✚ Add DNS Record</button>
            </form>
        </div>
    </div>

    <!-- TAB 4: ADVANCED -->
    <div id="advanced" class="domain-tab-content">
        <div class="domain-tab-card">
            <h2 style="font-family: 'Hanken Grotesk', sans-serif; font-size: 1.5rem; font-weight: 800; margin: 0 0 24px 0;">Advanced Settings</h2>
            <p style="color: var(--cv-text-secondary); margin-bottom: 24px;">Create custom nameservers using your domain (e.g., ns1.<?= e($domain['domain_name']) ?> → 192.168.1.1).</p>

            <!-- Existing Private Nameservers -->
            <?php if (!empty($childNameservers)): ?>
                <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0 0 16px 0;">Your Private Nameservers</h3>
                <div style="overflow-x: auto; margin-bottom: 32px;">
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>IP Address</th>
                                <th style="width: 80px; text-align: center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($childNameservers as $cns): ?>
                                <tr>
                                    <td><strong><?= e($cns['hostname']) ?></strong></td>
                                    <td><?= e($cns['ip_address']) ?></td>
                                    <td style="text-align: center;">
                                        <form method="post" action="/client/domains/<?= $id ?>/child-ns/<?= (int)$cns['id'] ?>/delete" style="display: inline;" data-confirm="Delete this private nameserver?">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="record-delete-btn">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Add Private Nameserver Form -->
            <h3 style="font-size: 1.1rem; font-weight: 700; margin: 0 0 16px 0;">Add Private Nameserver</h3>
            <form method="post" action="/client/domains/<?= $id ?>/child-ns">
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="cv-field">
                        <label class="cv-label">Nameserver Hostname</label>
                        <input class="cv-input" name="hostname" placeholder="ns1.<?= e($domain['domain_name']) ?>" required>
                    </div>
                    <div class="cv-field">
                        <label class="cv-label">IP Address</label>
                        <input class="cv-input" name="ip_address" placeholder="192.168.1.1" required>
                    </div>
                </div>
                <button class="action-btn" type="submit" style="margin-top: 20px;">✚ Add Private Nameserver</button>
            </form>
        </div>
    </div>
</div>

<?php
// The tab switcher and the EPP copy-to-clipboard used to be defined here and
// called from onclick="..." attributes. Both now live in app.js behind
// delegated [data-tab-target] / [data-copy-from] listeners, because CSP blocks
// inline event handlers — which is why Nameservers, DNS Records and Advanced
// appeared unimplemented when the code for them was here all along.
?>
