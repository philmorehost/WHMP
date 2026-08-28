<?php
/** @var array<string, mixed> $client */
/** @var string $tab */
/** @var array<int, array<string, mixed>> $contacts */
/** @var array<int, array<string, mixed>> $activity */
/** @var array<int, array<string, mixed>> $services */
/** @var array<int, array<string, mixed>> $domains */
/** @var array<int, array<string, mixed>> $invoices */
/** @var float $creditBalance */
/** @var array<int, array<string, mixed>> $creditLedger */
/** @var callable(float): string $serviceMoney */
/** @var callable(array<string, mixed>): string $invoiceMoney */
$tabs = ['summary' => 'Summary', 'profile' => 'Profile', 'contacts' => 'Contacts', 'billing' => 'Billing', 'log' => 'Log', 'message' => 'Message'];
$id = (int) $client['id'];
?>
<style>
/* Admin Client Detail Styles */
.admin-detail-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #0c0e1a 100%);
    padding: 48px 40px;
    margin-bottom: 32px;
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}
.admin-detail-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(37,99,235,.08) 0%, transparent 70%);
    pointer-events: none;
}
.admin-detail-hero__content {
    position: relative;
    z-index: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    flex-wrap: wrap;
}
.admin-detail-hero__info {
    flex: 1;
}
.admin-detail-hero__back {
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
.admin-detail-hero__back:hover {
    gap: 12px;
    color: #60a5fa;
}
.admin-detail-hero__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #fff;
    margin: 0 0 4px 0;
    line-height: 1.2;
}
.admin-detail-hero__subtitle {
    color: rgba(255,255,255,.7);
    font-size: .9rem;
    margin: 0;
}
.admin-detail-hero__actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-detail-btn {
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
.admin-detail-btn--primary {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}
.admin-detail-btn--primary:hover {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(37,99,235,.3);
}
.admin-detail-btn--secondary {
    background: rgba(255,255,255,.1);
    color: white;
    border: 1px solid rgba(255,255,255,.2);
}
.admin-detail-btn--secondary:hover {
    background: rgba(255,255,255,.15);
    border-color: rgba(255,255,255,.4);
}
.admin-detail-btn--danger {
    background: rgba(239,68,68,.2);
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-detail-btn--danger:hover {
    background: rgba(239,68,68,.3);
    border-color: rgba(239,68,68,.5);
}

/* Stat Cards for Credit Balance */
.admin-detail-stat-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 32px;
}
.admin-detail-stat-card__header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 24px;
    margin-bottom: 24px;
}
.admin-detail-stat-card__balance {
    text-align: left;
}
.admin-detail-stat-card__label {
    color: var(--cv-text-secondary);
    font-size: .8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 8px;
    display: block;
}
.admin-detail-stat-card__value {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 900;
    color: #10b981;
    margin: 0;
}
.admin-detail-credit-form {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    flex-wrap: wrap;
}
.admin-detail-credit-form > div {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.admin-detail-credit-form .cv-label {
    font-size: .8rem;
    font-weight: 600;
    color: var(--cv-text-secondary);
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-detail-credit-form input,
.admin-detail-credit-form select {
    padding: 8px 12px;
    border: 1px solid var(--cv-border-default);
    border-radius: 6px;
    background: var(--cv-bg-surface);
    color: var(--cv-text-primary);
    font-size: .9rem;
}
.admin-detail-credit-form input:focus,
.admin-detail-credit-form select:focus {
    outline: none;
    border-color: var(--cv-color-brand-500);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

/* Admin Tabs */
.admin-detail-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--cv-border-default);
    overflow-x: auto;
}
.admin-detail-tab {
    padding: 8px 16px;
    border: none;
    background: transparent;
    color: var(--cv-text-secondary);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    font-size: .9rem;
    white-space: nowrap;
    text-decoration: none;
}
.admin-detail-tab:hover {
    color: var(--cv-text-primary);
}
.admin-detail-tab.active {
    color: var(--cv-color-brand-500);
    border-bottom: 3px solid var(--cv-color-brand-500);
    margin-bottom: -12px;
    padding-bottom: 9px;
}

/* Card Container */
.admin-detail-card {
    background: var(--cv-bg-surface);
    border: 1px solid var(--cv-border-default);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    margin-bottom: 24px;
    overflow: hidden;
}
.admin-detail-card__title {
    font-family: 'Hanken Grotesk', sans-serif;
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--cv-text-primary);
    margin: 0;
    padding: 24px 24px 16px 24px;
    border-bottom: 1px solid var(--cv-border-default);
}
.admin-detail-card__body {
    padding: 24px;
}

/* Table Styles */
.admin-detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .9rem;
}
.admin-detail-table thead {
    background: linear-gradient(135deg, var(--cv-bg-surface-sunken), var(--cv-bg-surface));
    border-bottom: 2px solid var(--cv-border-default);
}
.admin-detail-table th {
    padding: 12px 16px;
    text-align: left;
    font-weight: 700;
    color: var(--cv-text-secondary);
    font-size: .8rem;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-detail-table tbody tr {
    border-bottom: 1px solid var(--cv-border-default);
    transition: all 0.2s;
}
.admin-detail-table tbody tr:hover {
    background: linear-gradient(135deg, rgba(37,99,235,0.02), transparent);
}
.admin-detail-table td {
    padding: 12px 16px;
    color: var(--cv-text-primary);
}
.admin-detail-table a {
    color: var(--cv-color-brand-500);
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s;
}
.admin-detail-table a:hover {
    color: var(--cv-color-brand-600);
    text-decoration: underline;
}

/* Badge */
.admin-detail-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.admin-detail-badge--active {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-detail-badge--closed {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}
.admin-detail-badge--inactive {
    background: linear-gradient(135deg, rgba(107,114,128,.2), rgba(75,85,99,.15));
    color: #6b7280;
    border: 1px solid rgba(107,114,128,.3);
}
.admin-detail-badge--paid {
    background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(5,150,105,.15));
    color: #10b981;
    border: 1px solid rgba(16,185,129,.3);
}
.admin-detail-badge--unpaid {
    background: linear-gradient(135deg, rgba(239,68,68,.2), rgba(220,38,38,.15));
    color: #ef4444;
    border: 1px solid rgba(239,68,68,.3);
}

@media (max-width: 768px) {
    .admin-detail-hero {
        padding: 32px 24px;
    }
    .admin-detail-hero__content {
        flex-direction: column;
    }
    .admin-detail-hero__title {
        font-size: 1.5rem;
    }
    .admin-detail-hero__actions {
        width: 100%;
        flex-direction: column;
    }
    .admin-detail-hero__actions form,
    .admin-detail-hero__actions a {
        width: 100%;
    }
    .admin-detail-stat-card__header {
        flex-direction: column;
    }
    .admin-detail-credit-form {
        flex-direction: column;
    }
    .admin-detail-credit-form > div {
        width: 100%;
    }
}
</style>

<!-- Hero Section -->
<div class="admin-detail-hero">
    <div class="admin-detail-hero__content">
        <div class="admin-detail-hero__info">
            <a href="/admin/clients" class="admin-detail-hero__back">
                <span>←</span>
                <span>Back to Clients</span>
            </a>
            <h1 class="admin-detail-hero__title"><?= e($client['first_name'] . ' ' . $client['last_name']) ?></h1>
            <p class="admin-detail-hero__subtitle"><?= e($client['email']) ?></p>
        </div>
        <div class="admin-detail-hero__actions">
            <form method="post" action="/admin/clients/<?= $id ?>/login-as"><?= csrf_field() ?>
                <button class="admin-detail-btn admin-detail-btn--primary" type="submit" title="Login as Client">🔑 Login as Client</button>
            </form>
            <a class="admin-detail-btn admin-detail-btn--secondary" href="/admin/clients/<?= $id ?>/edit" title="Edit">✏️ Edit</a>
            <?php if ($client['status'] !== 'closed'): ?>
            <form method="post" action="/admin/clients/<?= $id ?>/close"><?= csrf_field() ?>
                <button class="admin-detail-btn admin-detail-btn--danger" type="submit" title="Close Account">🛑 Close</button>
            </form>
            <?php endif; ?>
            <form method="post" action="/admin/clients/<?= $id ?>/delete" data-confirm="Are you sure you want to delete this client account permanently? All associated services, invoices, and data will be removed. This action cannot be undone."><?= csrf_field() ?>
                <button class="admin-detail-btn admin-detail-btn--danger" style="background:linear-gradient(135deg,rgba(239,68,68,.35),rgba(185,28,28,.3));border-color:rgba(239,68,68,.5);" type="submit" title="Delete Account">❌ Delete Account</button>
            </form>
        </div>
    </div>
</div>

<!-- Credit Balance & Management -->
<div class="admin-detail-stat-card">
    <div class="admin-detail-stat-card__header">
        <div class="admin-detail-stat-card__balance">
            <span class="admin-detail-stat-card__label">💰 Account Credit Balance</span>
            <p class="admin-detail-stat-card__value"><?= e($client['currency_symbol'] ?? '$') ?><?= number_format($creditBalance, 2) ?></p>
        </div>
        <form method="post" action="/admin/clients/<?= $id ?>/credit" class="admin-detail-credit-form"><?= csrf_field() ?>
            <div style="min-width:100px;">
                <span class="admin-detail-credit-form .cv-label">Action</span>
                <select name="action">
                    <option value="credit">Credit</option>
                    <option value="debit">Debit</option>
                </select>
            </div>
            <div style="min-width:110px;">
                <span class="admin-detail-credit-form .cv-label">Amount</span>
                <input type="number" step="0.01" name="amount" placeholder="0.00" required>
            </div>
            <div style="min-width:180px;">
                <span class="admin-detail-credit-form .cv-label">Reason</span>
                <input type="text" name="reason" placeholder="e.g., Refund, Adjustment" required>
            </div>
            <div>
                <button class="admin-detail-btn admin-detail-btn--primary" type="submit">Update</button>
            </div>
        </form>
    </div>
</div>

<!-- Tabs -->
<div class="admin-detail-tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="admin-detail-tab <?= $tab === $key ? 'active' : '' ?>" href="/admin/clients/<?= $id ?>?tab=<?= $key ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'summary'): ?>
    <div class="admin-detail-card">
        <h2 class="admin-detail-card__title">📋 Account Summary</h2>
        <div class="admin-detail-card__body">
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:24px;">
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Status</span>
                    <?php if ($client['status'] === 'active'): ?>
                        <span class="admin-detail-badge admin-detail-badge--active">Active</span>
                    <?php elseif ($client['status'] === 'closed'): ?>
                        <span class="admin-detail-badge admin-detail-badge--closed">Closed</span>
                    <?php else: ?>
                        <span class="admin-detail-badge admin-detail-badge--inactive">Inactive</span>
                    <?php endif; ?>
                </div>
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Group</span>
                    <span style="font-size:.95rem; color:var(--cv-text-primary);"><?= e((string) ($client['group_name'] ?? 'None')) ?></span>
                </div>
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Company</span>
                    <span style="font-size:.95rem; color:var(--cv-text-primary);"><?= e((string) ($client['company_name'] ?? '-')) ?></span>
                </div>
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Client Since</span>
                    <span style="font-size:.95rem; color:var(--cv-text-primary);"><?= e($client['created_at']) ?></span>
                </div>
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Credit Balance</span>
                    <span style="font-size:.95rem; font-weight:700; color:#10b981;"><?= e($client['currency_symbol'] ?? '$') ?><?= number_format($creditBalance, 2) ?></span>
                </div>
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Active Services</span>
                    <span style="font-size:1.25rem; font-weight:800; color:var(--cv-color-brand-500);"><?= count(array_filter($services, fn ($s) => $s['status'] === 'active')) ?></span>
                </div>
                <div>
                    <span style="display:block; font-size:.8rem; font-weight:700; color:var(--cv-text-secondary); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px;">Active Domains</span>
                    <span style="font-size:1.25rem; font-weight:800; color:var(--cv-color-brand-500);"><?= count(array_filter($domains ?? [], fn ($d) => $d['status'] === 'active')) ?></span>
                </div>
            </div>
        </div>
    </div>
<?php elseif ($tab === 'profile'): ?>
    <div class="admin-detail-card">
        <h2 class="admin-detail-card__title">👤 Profile Information</h2>
        <div class="admin-detail-card__body">
            <table class="admin-detail-table">
                <tbody>
                    <tr><td style="font-weight:700; width:160px;">Email</td><td><?= e($client['email']) ?></td></tr>
                    <tr><td style="font-weight:700;">Phone</td><td><?= e((string) ($client['phone'] ?? '-')) ?></td></tr>
                    <tr><td style="font-weight:700;">Address</td><td><?= e((string) ($client['address1'] ?? '')) ?> <?= e((string) ($client['address2'] ?? '')) ?></td></tr>
                    <tr><td style="font-weight:700;">City / State</td><td><?= e((string) ($client['city'] ?? '-')) ?> / <?= e((string) ($client['state'] ?? '-')) ?></td></tr>
                    <tr><td style="font-weight:700;">Postcode</td><td><?= e((string) ($client['postcode'] ?? '-')) ?></td></tr>
                    <tr><td style="font-weight:700;">Country</td><td><?= e((string) ($client['country'] ?? '-')) ?></td></tr>
                    <tr><td style="font-weight:700; vertical-align:top;">Notes</td><td><?= nl2br(e((string) ($client['notes'] ?? '-'))) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
<?php elseif ($tab === 'contacts'): ?>
    <div class="admin-detail-card">
        <h2 class="admin-detail-card__title">👥 Contacts / Sub-accounts</h2>
        <div class="admin-detail-card__body">
            <table class="admin-detail-table">
                <thead><tr><th>Name</th><th>Email</th><th style="width:100px;">Action</th></tr></thead>
                <tbody>
                <?php foreach ($contacts as $contact): ?>
                    <tr>
                        <td><?= e($contact['name']) ?></td>
                        <td><?= e($contact['email']) ?></td>
                        <td>
                            <form method="post" action="/admin/clients/<?= $id ?>/contacts/<?= (int) $contact['id'] ?>/delete" style="margin:0;"><?= csrf_field() ?>
                                <button class="admin-detail-btn admin-detail-btn--danger" type="submit" style="padding:6px 12px; font-size:.75rem;">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($contacts === []): ?>
                    <tr><td colspan="3" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No sub-accounts yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-detail-card" style="margin-top:24px;">
        <h3 class="admin-detail-card__title">➕ Add New Contact</h3>
        <div class="admin-detail-card__body">
            <form method="post" action="/admin/clients/<?= $id ?>/contacts" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;"><?= csrf_field() ?>
                <div style="flex:1; min-width:200px;">
                    <label class="cv-label" style="display:block; margin-bottom:6px; font-size:.85rem; font-weight:600;">Name</label>
                    <input class="cv-input" name="name" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary);">
                </div>
                <div style="flex:1; min-width:200px;">
                    <label class="cv-label" style="display:block; margin-bottom:6px; font-size:.85rem; font-weight:600;">Email</label>
                    <input class="cv-input" type="email" name="email" style="width:100%; padding:8px 12px; border:1px solid var(--cv-border-default); border-radius:6px; background:var(--cv-bg-surface); color:var(--cv-text-primary);">
                </div>
                <button class="admin-detail-btn admin-detail-btn--primary" type="submit">Add Contact</button>
            </form>
        </div>
    </div>
<?php elseif ($tab === 'billing'): ?>
    <div class="admin-detail-card" style="margin-bottom:24px;">
        <h2 class="admin-detail-card__title">🖥️ Services</h2>
        <div class="admin-detail-card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="admin-detail-table">
                    <thead><tr><th>Product</th><th>Domain / Hostname</th><th>Cycle</th><th>Amount</th><th>Next Due</th><th>Status</th><th style="width:100px;">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($services as $service): ?>
                        <?php
                        // A shared/reseller product is identified by its domain;
                        // a VPS/dedicated one by its hostname. Fall back to the
                        // other field when the primary is empty.
                        $isHostnameService = in_array((string) ($service['product_type'] ?? ''), ['vps', 'dedicated'], true);
                        $serviceLabel = trim((string) ($isHostnameService ? ($service['hostname'] ?? '') : ($service['domain'] ?? '')));
                        if ($serviceLabel === '') {
                            $serviceLabel = trim((string) ($isHostnameService ? ($service['domain'] ?? '') : ($service['hostname'] ?? '')));
                        }
                        ?>
                        <tr>
                            <td><?= e($service['product_name']) ?></td>
                            <td><?= $serviceLabel !== '' ? e($serviceLabel) : '<span style="color:var(--cv-text-secondary);">—</span>' ?></td>
                            <td><?= e($service['billing_cycle']) ?></td>
                            <td style="font-family:'Monaco', 'Courier New', monospace; font-weight:700;"><?= e($serviceMoney((float) $service['amount'])) ?></td>
                            <td><?= e($service['next_due_date']) ?></td>
                            <td><span class="admin-detail-badge admin-detail-badge--active"><?= e($service['status']) ?></span></td>
                            <td><a class="admin-detail-btn admin-detail-btn--secondary" href="/admin/services/<?= (int) $service['id'] ?>" style="padding:6px 12px; font-size:.75rem;">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($services === []): ?>
                        <tr><td colspan="7" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No services yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="admin-detail-card" style="margin-bottom:24px;">
        <h2 class="admin-detail-card__title">🌐 Domains</h2>
        <div class="admin-detail-card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="admin-detail-table">
                    <thead><tr><th>Domain</th><th>Registrar</th><th>Registered</th><th>Expiry / Renewal</th><th>Next Due</th><th>Amount</th><th>Auto-Renew</th><th>Status</th><th style="width:100px;">Action</th></tr></thead>
                    <tbody>
                    <?php foreach (($domains ?? []) as $domain): ?>
                        <?php
                        $expiry = (string) ($domain['expiry_date'] ?? '');
                        $daysToExpiry = $expiry !== '' ? (int) ((strtotime($expiry) - time()) / 86400) : null;
                        // Highlight an at-risk domain: expired, or expiring soon.
                        $expiryAtRisk = $daysToExpiry !== null && $daysToExpiry <= 30;
                        $expiryColor = $daysToExpiry !== null && $daysToExpiry < 0
                            ? '#dc2626'
                            : ($expiryAtRisk ? '#d97706' : 'var(--cv-text-primary)');
                        $statusClass = match ($domain['status'] ?? '') {
                            'active' => 'admin-detail-badge--active',
                            'expired' => 'admin-detail-badge--unpaid',
                            default => '',
                        };
                        ?>
                        <tr>
                            <td><strong><?= e((string) ($domain['domain_name'] ?? '')) ?></strong></td>
                            <td><?= e((string) ($domain['registrar_slug'] ?? '—')) ?></td>
                            <td><?= e((string) ($domain['registration_date'] ?? '—')) ?></td>
                            <td style="color:<?= $expiryColor ?>; font-weight:<?= $expiryAtRisk ? '700' : '400' ?>;">
                                <?= e($expiry !== '' ? $expiry : '—') ?>
                                <?php if ($expiryAtRisk): ?>
                                    <span style="display:block; font-size:.72rem; color:<?= $expiryColor ?>;">
                                        <?= $daysToExpiry < 0 ? 'Expired ' . abs($daysToExpiry) . 'd ago' : 'Expires in ' . $daysToExpiry . 'd' ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($domain['next_due_date'] ?? '—')) ?></td>
                            <td style="font-family:'Monaco', 'Courier New', monospace; font-weight:700;"><?= e($serviceMoney((float) ($domain['amount'] ?? 0))) ?></td>
                            <td><?= !empty($domain['auto_renew']) ? '✔' : '—' ?></td>
                            <td><span class="admin-detail-badge <?= $statusClass ?>"><?= e((string) ($domain['status'] ?? '')) ?></span></td>
                            <td><a class="admin-detail-btn admin-detail-btn--secondary" href="/admin/domains/<?= (int) $domain['id'] ?>" style="padding:6px 12px; font-size:.75rem;">Manage</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (($domains ?? []) === []): ?>
                        <tr><td colspan="9" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No domains yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="admin-detail-card" style="margin-bottom:24px;">
        <h2 class="admin-detail-card__title">📄 Invoices</h2>
        <div class="admin-detail-card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="admin-detail-table">
                    <thead><tr><th>#</th><th>Total</th><th>Due</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><a href="/admin/invoices/<?= (int) $invoice['id'] ?>">INV-<?= (int) $invoice['id'] ?></a></td>
                            <td style="font-family:'Monaco', 'Courier New', monospace; font-weight:700;"><?= e($invoiceMoney($invoice)) ?></td>
                            <td><?= e($invoice['due_date']) ?></td>
                            <td><span class="admin-detail-badge <?= $invoice['status'] === 'paid' ? 'admin-detail-badge--paid' : 'admin-detail-badge--unpaid' ?>"><?= e($invoice['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($invoices === []): ?>
                        <tr><td colspan="4" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No invoices yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (isset($billingPagination) && $billingPagination['total'] > 10): ?>
                <?php
                $totalPages = (int) ceil($billingPagination['total'] / 10);
                $currentPage = $billingPagination['page'];
                ?>
                <div style="display:flex; justify-content:center; gap:8px; padding:16px; border-top:1px solid var(--cv-border-default);">
                    <?php if ($currentPage > 1): ?>
                        <a class="admin-detail-btn admin-detail-btn--secondary" href="/admin/clients/<?= $id ?>?tab=billing&billing_page=<?= $currentPage - 1 ?>" style="padding:6px 12px; font-size:.75rem;">← Previous</a>
                    <?php endif; ?>
                    <span style="font-size:.85rem; align-self:center; color:var(--cv-text-secondary);">Page <strong><?= $currentPage ?></strong> of <strong><?= $totalPages ?></strong></span>
                    <?php if ($currentPage < $totalPages): ?>
                        <a class="admin-detail-btn admin-detail-btn--secondary" href="/admin/clients/<?= $id ?>?tab=billing&billing_page=<?= $currentPage + 1 ?>" style="padding:6px 12px; font-size:.75rem;">Next →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="admin-detail-card">
        <h2 class="admin-detail-card__title">📊 Credit History / Ledger</h2>
        <div class="admin-detail-card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="admin-detail-table">
                    <thead><tr><th>Date</th><th>Amount</th><th>Reason</th></tr></thead>
                    <tbody>
                    <?php foreach ($creditLedger as $entry): ?>
                        <tr>
                            <td><?= e($entry['created_at']) ?></td>
                            <td style="font-weight:700; color:<?= (float) $entry['amount'] >= 0 ? '#10b981' : '#ef4444' ?>;"><?= (float) $entry['amount'] >= 0 ? '+' : '' ?><?= e($client['currency_symbol'] ?? '$') ?><?= number_format((float) $entry['amount'], 2) ?></td>
                            <td><?= e($entry['reason']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($creditLedger === []): ?>
                        <tr><td colspan="3" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No credit activity yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($tab === 'log'): ?>
    <div class="admin-detail-card">
        <h2 class="admin-detail-card__title">📝 Activity Log</h2>
        <div class="admin-detail-card__body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table class="admin-detail-table">
                    <thead><tr><th>Time</th><th>Action</th><th>Description</th></tr></thead>
                    <tbody>
                    <?php foreach ($activity as $entry): ?>
                        <tr>
                            <td style="font-size:.85rem; color:var(--cv-text-secondary);"><?= e($entry['created_at']) ?></td>
                            <td style="font-weight:600; color:var(--cv-color-brand-500);"><?= e($entry['action']) ?></td>
                            <td><?= e($entry['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($activity === []): ?>
                        <tr><td colspan="3" style="color:var(--cv-text-secondary); text-align:center; padding:32px;">No activity recorded yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php elseif ($tab === 'message'): ?>
    <?php if (!empty($msg)): ?>
        <div style="background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.3);color:#10b981;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;">
            ✅ <?= e($msg) ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div style="background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);color:#ef4444;padding:12px 16px;border-radius:8px;margin-bottom:20px;font-weight:600;">
            ⚠️ <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:24px;">
        <!-- Direct Email Message Card -->
        <div class="admin-detail-card">
            <h2 class="admin-detail-card__title">✉️ Send Direct Email to Client</h2>
            <div class="admin-detail-card__body">
                <p style="font-size:.85rem;color:var(--cv-text-secondary);margin-top:0;">Send an email message directly to <strong><?= e($client['email']) ?></strong>.</p>
                <form method="post" action="/admin/clients/<?= $id ?>/send-message">
                    <?= csrf_field() ?>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:.85rem;font-weight:700;margin-bottom:6px;">Email Subject</label>
                        <input type="text" name="subject" class="cv-input" style="width:100%;" placeholder="e.g. Important Update Regarding Your Account" required>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:.85rem;font-weight:700;margin-bottom:6px;">Message Content</label>
                        <textarea name="message" class="cv-input" rows="6" style="width:100%;" placeholder="Type your message here..." required></textarea>
                    </div>
                    <button type="submit" class="admin-detail-btn admin-detail-btn--primary">✉️ Send Email Message</button>
                </form>
            </div>
        </div>

        <!-- Create Support Ticket Card -->
        <div class="admin-detail-card">
            <h2 class="admin-detail-card__title">🎫 Open Support Ticket for Client</h2>
            <div class="admin-detail-card__body">
                <p style="font-size:.85rem;color:var(--cv-text-secondary);margin-top:0;">Create a support ticket on behalf of this client (e.g. for phone call or chat support).</p>
                <form method="post" action="/admin/clients/<?= $id ?>/create-ticket">
                    <?= csrf_field() ?>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:.85rem;font-weight:700;margin-bottom:6px;">Department</label>
                        <select name="department_id" class="cv-select" style="width:100%;" required>
                            <?php if (isset($departments)): ?>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?= (int) $dept['id'] ?>"><?= e($dept['name']) ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:.85rem;font-weight:700;margin-bottom:6px;">Priority</label>
                        <select name="priority" class="cv-select" style="width:100%;">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                        </select>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:.85rem;font-weight:700;margin-bottom:6px;">Ticket Subject</label>
                        <input type="text" name="subject" class="cv-input" style="width:100%;" placeholder="e.g. Phone Support Request: VPS Setup" required>
                    </div>
                    <div style="margin-bottom:16px;">
                        <label style="display:block;font-size:.85rem;font-weight:700;margin-bottom:6px;">Initial Ticket Message</label>
                        <textarea name="message" class="cv-input" rows="5" style="width:100%;" placeholder="Details of the support request..." required></textarea>
                    </div>
                    <button type="submit" class="admin-detail-btn admin-detail-btn--primary">🎫 Open Support Ticket</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
