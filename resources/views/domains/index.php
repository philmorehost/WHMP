<?php
/** @var array<int, array<string, mixed>> $domains */
/** @var string $statusFilter */
/** @var array<int, string> $defaultNameservers */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Domains</h1>
    <p><a href="/admin">&larr; Back to dashboard</a> &middot; <a href="/admin/domains/create">Add Domain</a> &middot; <a href="/admin/registrars">Registrars</a> &middot; <a href="/admin/domain-pricing">TLD Pricing</a></p>
    <div style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);flex-wrap:wrap;align-items:center;">
        <a class="cv-btn <?= $statusFilter === '' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains">
            All <span class="cv-badge cv-badge--neutral" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['all'] ?? 0) ?></span>
        </a>
        <a class="cv-btn <?= $statusFilter === 'active' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=active">
            Active <span class="cv-badge cv-badge--success" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['active'] ?? 0) ?></span>
        </a>
        <a class="cv-btn <?= $statusFilter === 'pending' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=pending">
            Pending <span class="cv-badge cv-badge--neutral" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['pending'] ?? 0) ?></span>
        </a>
        <a class="cv-btn <?= $statusFilter === 'expired' ? '' : 'cv-btn--secondary' ?>" href="/admin/domains?status=expired">
            Expired <span class="cv-badge cv-badge--danger" style="margin-left:var(--cv-space-1);"><?= (int) ($statusCounts['expired'] ?? 0) ?></span>
        </a>
    </div>

    <?php if (!empty($registrarCounts)): ?>
        <div style="margin-top:var(--cv-space-4);padding-top:var(--cv-space-3);border-top:1px solid var(--cv-border-color, #e0e0e0);">
            <h3 style="font-size:var(--cv-text-sm, 13px);color:var(--cv-text-secondary);text-transform:uppercase;letter-spacing:0.05em;margin:0 0 var(--cv-space-2) 0;">Active Domains by Registrar</h3>
            <div style="display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
                <?php foreach ($registrarCounts as $regSlug => $cnt): ?>
                    <span class="cv-badge cv-badge--neutral" style="padding:var(--cv-space-1) var(--cv-space-3);font-size:var(--cv-text-sm, 13px);">
                        <strong><?= e($regSlug) ?></strong>: <?= (int) $cnt ?> active
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($bulkMsg)): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;color:#10b981;margin-bottom:var(--cv-space-4);">
        ✔ <?= e($bulkMsg) ?>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Bulk Registrar Tools</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Execute automated Sync or Nameserver Refresh across all domains, or filter by a specific registrar module (ConnectReseller, Upperlink, ResellerClub, Namecheap).</p>
    <div style="display:flex;gap:var(--cv-space-4);flex-wrap:wrap;align-items:end;">
        <form method="post" action="/admin/domains/bulk-refresh-ns" style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;"><?= csrf_field() ?>
            <select class="cv-select" name="registrar_slug" style="width:auto;min-width:160px;">
                <option value="">All Registrars</option>
                <option value="connectreseller">ConnectReseller</option>
                <option value="upperlink">Upperlink</option>
                <option value="resellerclub">ResellerClub</option>
                <option value="namecheap">Namecheap</option>
            </select>
            <button class="cv-btn" type="submit">Refresh All Nameservers from Registrar</button>
        </form>

        <form method="post" action="/admin/domains/bulk-sync" style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;"><?= csrf_field() ?>
            <select class="cv-select" name="registrar_slug" style="width:auto;min-width:160px;">
                <option value="">All Registrars</option>
                <option value="connectreseller">ConnectReseller</option>
                <option value="upperlink">Upperlink</option>
                <option value="resellerclub">ResellerClub</option>
                <option value="namecheap">Namecheap</option>
            </select>
            <button class="cv-btn cv-btn--secondary" type="submit">Sync All Status &amp; Dates with Registrar</button>
        </form>
    </div>
</div>

<div class="cv-card" id="whois-lookup-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">WHOIS Lookup</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Lookup live WHOIS records for any domain name directly from official registry WHOIS servers (same as WHMCS Admin WHOIS tool).</p>
    <form method="get" action="/admin/domains/whois-search#whois-lookup-card" style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;max-width:32rem;">
        <input class="cv-input" name="domain" placeholder="example.com or domain.com.ng" required value="<?= e($whoisDomain ?? '') ?>" style="flex:1;min-width:200px;">
        <button class="cv-btn" type="submit">Lookup WHOIS</button>
    </form>

    <?php if (isset($whoisSearchResult)): ?>
        <div style="margin-top:var(--cv-space-4);">
            <h3 style="font-size:var(--cv-text-sm, 14px);color:var(--cv-text-primary);margin-bottom:var(--cv-space-2);">WHOIS Output for <strong><?= e($whoisDomain ?? '') ?></strong> (Server: <?= e($whoisSearchResult['server'] ?? 'whois.iana.org') ?>)</h3>
            <?php if (!empty($whoisSearchResult['error'])): ?>
                <div class="cv-field-error" style="margin-bottom:var(--cv-space-2);"><?= e($whoisSearchResult['error']) ?></div>
            <?php endif; ?>
            <pre style="background:var(--cv-bg-dark, #0d1117);color:var(--cv-text-light, #c9d1d9);padding:1rem;border-radius:6px;font-family:Consolas, Monaco, monospace;font-size:0.85rem;line-height:1.4;max-height:350px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;user-select:all;border:1px solid var(--cv-border-color, #30363d);"><?= e($whoisSearchResult['whois'] ?? '') ?></pre>
        </div>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Default Nameservers</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Used to pre-fill new domain registrations when a client doesn't specify their own — same role as WHMCS's General Settings &rsaquo; Domains &rsaquo; Default Nameservers.</p>
    <form method="post" action="/admin/domains/nameservers" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
        <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Nameserver <?= $i + 1 ?><?= $i < 2 ? '' : ' (optional)' ?></label>
                <input class="cv-input" name="ns[]" value="<?= e($defaultNameservers[$i] ?? '') ?>" placeholder="ns<?= $i + 1 ?>.yourdomain.com">
            </div>
        <?php endfor; ?>
        <button class="cv-btn" type="submit" style="grid-column:1 / -1;margin-top:var(--cv-space-2);">Save Default Nameservers</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Expired Domain Deletion</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
        Real registrars delete an unredeemed domain outright once its grace and redemption periods both run out —
        at that point it becomes registrable by anyone again, including your own clients. This does the same for
        your local records once a domain has gone that far unrenewed. Grace and redemption periods are set per TLD
        on the <a href="/admin/domain-pricing">Domain Pricing</a> page; the days below are an <strong>extra</strong>
        wait on top of both, so a domain can never be deleted while it's still inside its own redemption window.
    </p>
    <form method="post" action="/admin/domains/deletion-policy"><?= csrf_field() ?>
        <div class="cv-field">
            <label style="display:flex;align-items:center;gap:.5rem;">
                <input type="checkbox" name="auto_delete_expired_enabled" value="1" <?= $autoDeleteExpiredEnabled ? 'checked' : '' ?>>
                <span><strong>Automatically delete expired, unredeemed domains</strong></span>
            </label>
            <span style="font-size:0.75rem;color:var(--cv-color-danger-600, #b42318);">
                Irreversible. Runs daily. Off until you switch it on.
            </span>
        </div>
        <div class="cv-field">
            <label class="cv-label">Extra Days After Grace + Redemption Before Deletion</label>
            <input class="cv-input" type="number" min="0" name="deletion_grace_days" value="<?= e((string) $deletionGraceDays) ?>" required>
            <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Default 30. With a typical 30-day grace and 30-day redemption period, this gives roughly 90 total days from expiry, matching most registrars.</span>
        </div>
        <button class="cv-btn" type="submit">Save Deletion Policy</button>
    </form>
</div>

<form method="post" action="/admin/domains/bulk-status" id="bulkSelectedDomainsForm">
    <?= csrf_field() ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);background:var(--cv-bg-surface);border:1px solid var(--cv-border-color);">
        <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-2);">Bulk Operations for Selected Domains</h2>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-bottom:var(--cv-space-3);">Select multiple domain names using the table checkboxes below to update their status (e.g. set Pending to Active) or permanently delete expired domains in bulk.</p>
        <div style="display:flex;gap:var(--cv-space-3);align-items:center;flex-wrap:wrap;justify-content:space-between;">
            <div style="display:flex;gap:var(--cv-space-2);align-items:center;flex-wrap:wrap;">
                <select class="cv-select" name="status" id="bulkStatusSelect" style="width:auto;min-width:160px;">
                    <option value="">-- Change Status --</option>
                    <option value="active">Set Active</option>
                    <option value="pending">Set Pending</option>
                    <option value="expired">Set Expired</option>
                    <option value="cancelled">Set Cancelled</option>
                    <option value="transferred">Set Transferred</option>
                    <option value="fraud">Set Fraud</option>
                </select>
                <button type="submit" formaction="/admin/domains/bulk-status" class="cv-btn"
                        data-require-checked=".domain-select-checkbox"
                        data-require-checked-message="Please select at least one domain using the checkboxes."
                        data-require-value="#bulkStatusSelect"
                        data-require-value-message="Please select a status from the dropdown to apply to the selected domains.">Update Selected Status</button>
            </div>
            <div>
                <button type="submit" formaction="/admin/domains/bulk-delete" class="cv-btn cv-btn--danger" style="background:#ef4444;color:#fff;border:none;"
                        data-require-checked=".domain-select-checkbox"
                        data-require-checked-message="Please select at least one domain using the checkboxes."
                        data-confirm-count="Are you sure you want to permanently delete {count} selected domain(s)? This action cannot be undone.">Delete Selected Domains</button>
            </div>
        </div>
    </div>

    <div class="cv-card">
        <div class="cv-datatable__toolbar">
            <?= $view->partial('partials.table-search', ['target' => '#domains-table', 'placeholder' => 'Search domains...']) ?>
        </div>
        <table class="cv-table" id="domains-table">
            <thead>
                <tr>
                    <th style="width:38px;text-align:center;"><input type="checkbox" id="selectAllCheckbox" data-select-all=".domain-select-checkbox" title="Select all domains"></th>
                    <th>Domain</th>
                    <th>Client</th>
                    <th>Registrar</th>
                    <th>Expiry</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($domains as $domain): ?>
                <tr>
                    <td style="text-align:center;"><input type="checkbox" name="domain_ids[]" value="<?= (int) $domain['id'] ?>" class="domain-select-checkbox"></td>
                    <td><strong><?= e($domain['domain_name']) ?></strong></td>
                    <td><?= e($domain['first_name'] . ' ' . $domain['last_name']) ?> (<?= e($domain['client_email']) ?>)</td>
                    <td><?= e($domain['registrar_slug']) ?></td>
                    <td><?= e((string) ($domain['expiry_date'] ?? '-')) ?></td>
                    <td>
                        <?php if ($domain['status'] === 'active'): ?>
                            <span class="cv-badge cv-badge--success">Active</span>
                        <?php elseif ($domain['status'] === 'expired'): ?>
                            <span class="cv-badge cv-badge--danger">Expired</span>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--neutral"><?= e($domain['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><a class="cv-btn cv-btn--secondary" href="/admin/domains/<?= (int) $domain['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($domains === []): ?>
                <tr><td colspan="7" style="color:var(--cv-text-secondary);">No domains yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</form>

<?php
// The select-all and bulk-action guards live in app.js behind delegated
// [data-select-all] / [data-require-checked] listeners. They used to be
// functions here called from onclick="…" attributes, which CSP blocked —
// script-src carries a nonce but no 'unsafe-inline', and a nonce does not
// apply to inline event-handler attributes.
?>
