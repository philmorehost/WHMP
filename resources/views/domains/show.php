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
        <a class="cv-btn cv-btn--secondary" href="/admin/domains/<?= $id ?>/whois">View WHOIS Record</a>
        <form method="post" action="/admin/domains/<?= $id ?>/lock"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit"><?= $domain['registrar_lock_enabled'] ? 'Unlock' : 'Lock' ?></button></form>
        <form method="post" action="/admin/domains/<?= $id ?>/id-protection"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit"><?= $domain['id_protection_enabled'] ? 'Disable ID Protection' : 'Enable ID Protection' ?></button></form>
        <a class="cv-btn cv-btn--secondary" href="/admin/domains/<?= $id ?>/contact">Manage Contact Info</a>
    </div>
</div>

<?php if (isset($whoisResult)): ?>
    <div class="cv-card" id="whois-record" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">WHOIS Record: <?= e($domain['domain_name']) ?></h2>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
            Querying WHOIS Server: <strong><?= e($whoisResult['server'] ?? 'whois.iana.org') ?></strong>
        </p>

        <?php if (!empty($whoisResult['error'])): ?>
            <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($whoisResult['error']) ?></div>
        <?php endif; ?>

        <pre style="background:var(--cv-bg-dark, #0d1117);color:var(--cv-text-light, #c9d1d9);padding:1rem;border-radius:6px;font-family:Consolas, Monaco, monospace;font-size:0.85rem;line-height:1.4;max-height:400px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;user-select:all;border:1px solid var(--cv-border-color, #30363d);"><?= e($whoisResult['whois'] ?? '') ?></pre>
    </div>
<?php endif; ?>

<?php if (!empty($updated)): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;color:#10b981;margin-bottom:var(--cv-space-4);">
        ✔ Domain status and details updated successfully.
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Domain Status &amp; Management</h2>
    <form method="post" action="/admin/domains/<?= $id ?>/status"><?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:var(--cv-space-3);">
            <div class="cv-field">
                <label class="cv-label">Domain Status</label>
                <select class="cv-select" name="status">
                    <option value="active" <?= ($domain['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= ($domain['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Pending Registration / Transfer</option>
                    <option value="expired" <?= ($domain['status'] ?? '') === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="cancelled" <?= ($domain['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="transferred_away" <?= ($domain['status'] ?? '') === 'transferred_away' ? 'selected' : '' ?>>Transferred Away</option>
                    <option value="fraud" <?= ($domain['status'] ?? '') === 'fraud' ? 'selected' : '' ?>>Fraud</option>
                </select>
            </div>

            <div class="cv-field">
                <label class="cv-label">Registration Date</label>
                <input class="cv-input" type="date" name="registration_date" value="<?= e((string) ($domain['registration_date'] ?? '')) ?>">
            </div>

            <div class="cv-field">
                <label class="cv-label">Expiry Date</label>
                <input class="cv-input" type="date" name="expiry_date" value="<?= e((string) ($domain['expiry_date'] ?? '')) ?>">
            </div>

            <div class="cv-field">
                <label class="cv-label">Next Due Date</label>
                <input class="cv-input" type="date" name="next_due_date" value="<?= e((string) ($domain['next_due_date'] ?? '')) ?>">
            </div>

            <div class="cv-field">
                <label class="cv-label">Renewal Amount ($)</label>
                <input class="cv-input" type="number" step="0.01" min="0" name="amount" value="<?= e((string) ($domain['amount'] ?? '0.00')) ?>">
            </div>

            <div class="cv-field" style="display:flex;align-items:center;margin-top:1.8rem;">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="checkbox" name="auto_renew" value="1" <?= !empty($domain['auto_renew']) ? 'checked' : '' ?>>
                    <strong>Auto Renew Enabled</strong>
                </label>
            </div>
        </div>

        <div style="margin-top:var(--cv-space-3);">
            <button class="cv-btn" type="submit">Update Domain Status &amp; Details</button>
        </div>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Nameservers</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
        The values below are cached from the registrar — click Refresh from Registrar to sync live records.
    </p>
    <form method="post" action="/admin/domains/<?= $id ?>/nameservers/refresh" style="margin-bottom:var(--cv-space-3);"><?= csrf_field() ?>
        <button class="cv-btn cv-btn--secondary" type="submit">Refresh from Registrar</button>
    </form>
    <form method="post" action="/admin/domains/<?= $id ?>/nameservers"><?= csrf_field() ?>
        <?php for ($i = 1; $i <= 6; $i++): ?>
            <div class="cv-field">
                <label class="cv-label">NS<?= $i ?></label>
                <input class="cv-input" name="ns<?= $i ?>" value="<?= e((string) ($ns[$i - 1] ?? '')) ?>">
            </div>
        <?php endfor; ?>
        <button class="cv-btn" type="submit">Save Nameservers</button>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Private Nameservers (Child Nameservers / Glue Records)</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
        Register custom nameservers for <?= e($domain['domain_name']) ?> pointing to custom IP addresses (e.g., ns1.<?= e($domain['domain_name']) ?> &rarr; 192.168.1.1).
    </p>

    <?php if (!empty($childNameservers)): ?>
        <table class="cv-table" style="margin-bottom:var(--cv-space-4);">
            <thead><tr><th>Hostname</th><th>IP Address</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($childNameservers as $cns): ?>
                <tr>
                    <td><strong><?= e($cns['hostname']) ?></strong></td>
                    <td><?= e($cns['ip_address']) ?></td>
                    <td>
                        <form method="post" action="/admin/domains/<?= $id ?>/child-ns/<?= (int)$cns['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--sm cv-btn--danger" type="submit" onclick="return confirm('Delete this child nameserver?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form method="post" action="/admin/domains/<?= $id ?>/child-ns" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">Nameserver Prefix / Hostname</label>
            <input class="cv-input" name="hostname" placeholder="e.g. ns1.<?= e($domain['domain_name']) ?>" required>
        </div>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">IP Address</label>
            <input class="cv-input" name="ip_address" placeholder="e.g. 192.168.1.1" required>
        </div>
        <button class="cv-btn" type="submit">Add Private Nameserver</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">DNS Management</h2>
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-xs);">
        Manage DNS Host Records (A, AAAA, CNAME, MX, TXT) for <?= e($domain['domain_name']) ?>.
    </p>

    <?php if (!empty($dnsRecords)): ?>
        <table class="cv-table" style="margin-bottom:var(--cv-space-4);">
            <thead><tr><th>Type</th><th>Host / Name</th><th>Address / Content</th><th>Priority</th><th>TTL</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($dnsRecords as $rec): ?>
                <tr>
                    <td><span class="cv-badge cv-badge--neutral"><?= e($rec['type']) ?></span></td>
                    <td><strong><?= e($rec['name']) ?></strong></td>
                    <td style="word-break:break-all;"><?= e($rec['content']) ?></td>
                    <td><?= (int) ($rec['priority'] ?? 10) ?></td>
                    <td><?= (int) ($rec['ttl'] ?? 3600) ?></td>
                    <td>
                        <form method="post" action="/admin/domains/<?= $id ?>/dns/<?= (int)$rec['id'] ?>/delete" style="display:inline;"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--sm cv-btn--danger" type="submit" onclick="return confirm('Delete this DNS record?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <form method="post" action="/admin/domains/<?= $id ?>/dns" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">Record Type</label>
            <select class="cv-select" name="type">
                <option value="A">A (IPv4)</option>
                <option value="AAAA">AAAA (IPv6)</option>
                <option value="CNAME">CNAME (Alias)</option>
                <option value="MX">MX (Mail Server)</option>
                <option value="TXT">TXT (Text)</option>
            </select>
        </div>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">Host Name</label>
            <input class="cv-input" name="name" value="@" placeholder="@ or www" required>
        </div>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">Target Content / IP</label>
            <input class="cv-input" name="content" placeholder="Destination IP or record" required>
        </div>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">Priority (MX)</label>
            <input class="cv-input" type="number" name="priority" value="10">
        </div>
        <div class="cv-field" style="margin:0;">
            <label class="cv-label">TTL (seconds)</label>
            <input class="cv-input" type="number" name="ttl" value="3600">
        </div>
        <button class="cv-btn" type="submit" style="grid-column: 1 / -1;margin-top:var(--cv-space-2);">Add DNS Record</button>
    </form>
</div>
