<?php
/** @var array<string, mixed> $service */
/** @var string $tab */
/** @var array<int, array<string, mixed>> $items */
/** @var string|null $listError */
/** @var array{success: bool, message: string}|null $notice */
/** @var array<int, string> $dnsRecordTypes */
/** @var array<int, array<string, mixed>> $forwarders */
/** @var array<int, array<string, mixed>> $autoresponders */
/** @var array<int, array<string, mixed>> $addonDomains */
/** @var array<int, array<string, mixed>> $subdomains */
/** @var array<int, array<string, mixed>> $redirects */
/** @var array{usedMb: float, limitMb: float}|null $usage */
$id = (int) $service['id'];

$tabs = [
    'email' => 'Email',
    'ftp' => 'FTP Accounts',
    'databases' => 'MySQL Databases',
    'dns' => 'DNS Zone Editor',
    'domains' => 'Domains & Redirects',
    'cron' => 'Cron Jobs',
    'ssh' => 'SSH Access',
    'ssl' => 'SSL Certificates',
    'usage' => 'Disk Usage',
    'logins' => 'Quick Logins',
];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">cPanel Tools &mdash; <?= e((string) $service['product_name']) ?></h1>
    <p><a href="/client/services/<?= $id ?>">&larr; Back to service</a></p>
</div>

<?php if ($notice !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);<?= $notice['success'] ? 'border-left:4px solid #22c55e;' : 'border-left:4px solid #ef4444;' ?>">
        <?= e($notice['message']) ?>
    </div>
<?php endif; ?>

<div class="cv-tabs" style="margin-bottom:var(--cv-space-4);" role="tablist">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="cv-tab" href="/client/services/<?= $id ?>/cpanel-tools?tab=<?= $key ?>" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($listError !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($listError) ?></div>
    </div>
<?php endif; ?>

<?php if ($tab === 'email'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Email Accounts</h2>
        <table class="cv-table">
            <thead><tr><th>Address</th><th>Quota (MB)</th><th>Used (MB)</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $account): ?>
                <tr>
                    <td><?= e((string) $account['email']) ?></td>
                    <td><?= e((string) $account['quota']) ?></td>
                    <td><?= e((string) $account['used']) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/email/delete" data-confirm="Delete this email account?"><?= csrf_field() ?>
                            <input type="hidden" name="local_part" value="<?= e((string) $account['login']) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="4" style="color:var(--cv-text-secondary);">No email accounts yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cv-card">
        <h3>Create Email Account</h3>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/email" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Username (before the @)</label>
                <input class="cv-input" name="local_part" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Password</label>
                <input class="cv-input" type="password" name="password" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Quota (MB, 0 = unlimited)</label>
                <input class="cv-input" type="number" name="quota_mb" value="250" min="0">
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Create Email Account</button>
            </div>
        </form>
    </div>

    <div class="cv-card" style="margin-top:var(--cv-space-4);margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Email Forwarders</h2>
        <table class="cv-table">
            <thead><tr><th>From</th><th>Forwards To</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($forwarders as $fwd): ?>
                <tr>
                    <td><?= e((string) $fwd['source']) ?></td>
                    <td><?= e((string) $fwd['destination']) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/email/forwarder/delete" data-confirm="Delete this forwarder?"><?= csrf_field() ?>
                            <input type="hidden" name="address" value="<?= e((string) $fwd['source']) ?>">
                            <input type="hidden" name="forwarder" value="<?= e((string) $fwd['destination']) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($forwarders === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No forwarders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/email/forwarder" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;margin-top:var(--cv-space-3);"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Forward From (before the @)</label>
                <input class="cv-input" name="local_part" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Forward To (full address)</label>
                <input class="cv-input" type="email" name="destination" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Add Forwarder</button>
            </div>
        </form>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Autoresponders</h2>
        <table class="cv-table">
            <thead><tr><th>Address</th><th>Subject</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($autoresponders as $ar): ?>
                <?php $arEmail = (string) ($ar['email'] ?? ''); ?>
                <tr>
                    <td><?= e($arEmail) ?></td>
                    <td><?= e((string) ($ar['subject'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/email/autoresponder/delete" data-confirm="Delete this autoresponder?"><?= csrf_field() ?>
                            <input type="hidden" name="local_part" value="<?= e(str_contains($arEmail, '@') ? strstr($arEmail, '@', true) : $arEmail) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($autoresponders === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No autoresponders yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/email/autoresponder" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;margin-top:var(--cv-space-3);"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Address (before the @)</label>
                <input class="cv-input" name="local_part" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Subject</label>
                <input class="cv-input" name="subject" required>
            </div>
            <div class="cv-field" style="grid-column: 1 / -1;margin-bottom:0;">
                <label class="cv-label">Message</label>
                <textarea class="cv-input" name="body" rows="3" required></textarea>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Add Autoresponder</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'ftp'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">FTP Accounts</h2>
        <table class="cv-table">
            <thead><tr><th>Username</th><th>Home Directory</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $account): ?>
                <tr>
                    <td><?= e((string) ($account['user'] ?? '')) ?></td>
                    <td><?= e((string) ($account['homedir'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/ftp/delete" data-confirm="Delete this FTP account?"><?= csrf_field() ?>
                            <input type="hidden" name="username" value="<?= e((string) ($account['user'] ?? '')) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No FTP accounts yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cv-card">
        <h3>Create FTP Account</h3>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/ftp" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Username</label>
                <input class="cv-input" name="username" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Password</label>
                <input class="cv-input" type="password" name="password" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Home Directory</label>
                <input class="cv-input" name="homedir" value="public_html">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Quota (MB, 0 = unlimited)</label>
                <input class="cv-input" type="number" name="quota_mb" value="0" min="0">
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Create FTP Account</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'databases'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">MySQL Databases</h2>
        <table class="cv-table">
            <thead><tr><th>Database</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $database): ?>
                <tr>
                    <td><?= e((string) ($database['database'] ?? $database['name'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/database/delete" data-confirm="Delete this database? This cannot be undone."><?= csrf_field() ?>
                            <input type="hidden" name="name" value="<?= e((string) ($database['database'] ?? $database['name'] ?? '')) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="2" style="color:var(--cv-text-secondary);">No databases yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cv-card">
        <h3>Create Database</h3>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/database" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Database Name</label>
                <input class="cv-input" name="name" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Create Database</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'dns'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">DNS Zone Editor</h2>
        <table class="cv-table">
            <thead><tr><th>Name</th><th>Type</th><th>TTL</th><th>Value</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $record): ?>
                <tr>
                    <td><?= e((string) $record['name']) ?></td>
                    <td><?= e((string) $record['type']) ?></td>
                    <td><?= (int) $record['ttl'] ?></td>
                    <td><?= e((string) $record['value']) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/dns/delete" data-confirm="Delete this DNS record?"><?= csrf_field() ?>
                            <input type="hidden" name="line" value="<?= (int) $record['line'] ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="5" style="color:var(--cv-text-secondary);">No DNS records found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cv-card">
        <h3>Add DNS Record</h3>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Supports A, AAAA, CNAME, and TXT records.</p>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/dns" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Name</label>
                <input class="cv-input" name="name" placeholder="www" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Type</label>
                <select class="cv-select" name="type">
                    <?php foreach ($dnsRecordTypes as $type): ?>
                        <option value="<?= e($type) ?>"><?= e($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Value</label>
                <input class="cv-input" name="value" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">TTL</label>
                <input class="cv-input" type="number" name="ttl" value="14400" min="60">
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Add Record</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'domains'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Addon Domains</h2>
        <table class="cv-table">
            <thead><tr><th>Domain</th><th>Document Root</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($addonDomains as $ad): ?>
                <tr>
                    <td><?= e((string) ($ad['domain'] ?? '')) ?></td>
                    <td><?= e((string) ($ad['dir'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/domains/addon/delete" data-confirm="Delete this addon domain?"><?= csrf_field() ?>
                            <input type="hidden" name="domain" value="<?= e((string) ($ad['domain'] ?? '')) ?>">
                            <input type="hidden" name="subdomain" value="<?= e((string) ($ad['subdomain'] ?? '')) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($addonDomains === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No addon domains yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/domains/addon" style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:var(--cv-space-3);align-items:end;margin-top:var(--cv-space-3);"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">New Domain</label>
                <input class="cv-input" name="new_domain" placeholder="example.com" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Subdomain Part</label>
                <input class="cv-input" name="subdomain" placeholder="example" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Document Root</label>
                <input class="cv-input" name="dir" placeholder="public_html/example.com">
            </div>
            <button class="cv-btn" type="submit">Add Addon Domain</button>
        </form>
    </div>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Subdomains</h2>
        <table class="cv-table">
            <thead><tr><th>Subdomain</th><th>Document Root</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($subdomains as $sd): ?>
                <tr>
                    <td><?= e((string) ($sd['domain'] ?? '')) ?></td>
                    <td><?= e((string) ($sd['dir'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/domains/subdomain/delete" data-confirm="Delete this subdomain?"><?= csrf_field() ?>
                            <input type="hidden" name="domain" value="<?= e((string) ($sd['domain'] ?? '')) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($subdomains === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No subdomains yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/domains/subdomain" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);align-items:end;margin-top:var(--cv-space-3);"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Subdomain Name</label>
                <input class="cv-input" name="subdomain" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Document Root</label>
                <input class="cv-input" name="dir" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Add Subdomain</button>
            </div>
        </form>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Domain Redirects</h2>
        <table class="cv-table">
            <thead><tr><th>Source</th><th>Destination</th><th>Type</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($redirects as $rd): ?>
                <?php $rdSource = (string) ($rd['domain'] ?? $rd['source'] ?? ''); ?>
                <tr>
                    <td><?= e($rdSource) ?></td>
                    <td><?= e((string) ($rd['dest'] ?? '')) ?></td>
                    <td><?= e((string) ($rd['type'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/domains/redirect/delete" data-confirm="Delete this redirect?"><?= csrf_field() ?>
                            <input type="hidden" name="source_domain" value="<?= e($rdSource) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($redirects === []): ?>
                <tr><td colspan="4" style="color:var(--cv-text-secondary);">No redirects yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/domains/redirect" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:var(--cv-space-3);align-items:end;margin-top:var(--cv-space-3);"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Type</label>
                <select class="cv-select" name="type">
                    <option value="permanent">301 (Permanent)</option>
                    <option value="temp">302 (Temporary)</option>
                </select>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Redirect From Path</label>
                <input class="cv-input" name="source" placeholder="/">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Redirect To</label>
                <input class="cv-input" type="url" name="target" placeholder="https://example.com" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Add Redirect</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'cron'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Cron Jobs</h2>
        <table class="cv-table">
            <thead><tr><th>Minute</th><th>Hour</th><th>Day</th><th>Month</th><th>Weekday</th><th>Command</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $job): ?>
                <tr>
                    <td><?= e((string) ($job['minute'] ?? '')) ?></td>
                    <td><?= e((string) ($job['hour'] ?? '')) ?></td>
                    <td><?= e((string) ($job['day'] ?? '')) ?></td>
                    <td><?= e((string) ($job['month'] ?? '')) ?></td>
                    <td><?= e((string) ($job['weekday'] ?? '')) ?></td>
                    <td><?= e((string) ($job['command'] ?? '')) ?></td>
                    <td>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/cron/delete" data-confirm="Delete this cron job?"><?= csrf_field() ?>
                            <input type="hidden" name="line" value="<?= (int) ($job['line'] ?? 0) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="7" style="color:var(--cv-text-secondary);">No cron jobs yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cv-card">
        <h3>Add Cron Job</h3>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/cron" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(100px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Minute</label>
                <input class="cv-input" name="minute" value="*" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Hour</label>
                <input class="cv-input" name="hour" value="*" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Day</label>
                <input class="cv-input" name="day" value="*" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Month</label>
                <input class="cv-input" name="month" value="*" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Weekday</label>
                <input class="cv-input" name="weekday" value="*" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Command</label>
                <input class="cv-input" name="command" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Add Cron Job</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'ssh'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">SSH Keys</h2>
        <table class="cv-table">
            <thead><tr><th>Name</th><th>Bits</th><th>Authorized</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $key): ?>
                <tr>
                    <td><?= e((string) ($key['name'] ?? '')) ?></td>
                    <td><?= e((string) ($key['bits'] ?? '')) ?></td>
                    <td><?= !empty($key['authorized']) ? 'Yes' : 'No' ?></td>
                    <td style="display:flex;gap:var(--cv-space-2);">
                        <?php if (empty($key['authorized'])): ?>
                            <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/ssh/authorize"><?= csrf_field() ?>
                                <input type="hidden" name="name" value="<?= e((string) ($key['name'] ?? '')) ?>">
                                <button class="cv-btn cv-btn--secondary" type="submit">Authorize</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/ssh/delete" data-confirm="Delete this SSH key?"><?= csrf_field() ?>
                            <input type="hidden" name="name" value="<?= e((string) ($key['name'] ?? '')) ?>">
                            <button class="cv-btn cv-btn--danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="4" style="color:var(--cv-text-secondary);">No SSH keys yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="cv-card">
        <h3>Generate SSH Key</h3>
        <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/ssh" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(150px, 1fr));gap:var(--cv-space-3);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Key Name</label>
                <input class="cv-input" name="name" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Passphrase</label>
                <input class="cv-input" type="password" name="password" required>
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Key Size</label>
                <select class="cv-select" name="key_size">
                    <option value="2048">2048</option>
                    <option value="4096">4096</option>
                </select>
            </div>
            <div style="grid-column: 1 / -1;">
                <button class="cv-btn" type="submit">Generate Key</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if ($tab === 'ssl'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Installed SSL Certificates</h2>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Read-only — certificate installation is managed by your host.</p>
        <table class="cv-table">
            <thead><tr><th>Domain</th><th>Issuer</th><th>Expires</th></tr></thead>
            <tbody>
            <?php foreach ($items as $cert): ?>
                <tr>
                    <td><?= e((string) ($cert['domain'] ?? '')) ?></td>
                    <td><?= e((string) ($cert['issuer'] ?? '')) ?></td>
                    <td><?= e((string) ($cert['expires'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($items === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No SSL certificates found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'usage'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Disk Usage</h2>
        <?php if ($usage === null): ?>
            <p style="color:var(--cv-text-secondary);">Usage data is unavailable.</p>
        <?php else: ?>
            <?php
                $used = $usage['usedMb'];
                $limit = $usage['limitMb'];
                $pct = $limit > 0 ? min(100, round(($used / $limit) * 100)) : 0;
            ?>
            <p><?= number_format($used, 1) ?> MB used of <?= $limit > 0 ? number_format($limit, 1) . ' MB' : 'unlimited' ?></p>
            <?php if ($limit > 0): ?>
                <div style="background:var(--cv-border-default);border-radius:4px;height:10px;overflow:hidden;">
                    <div style="background:var(--cv-color-primary, #4f46e5);width:<?= $pct ?>%;height:100%;"></div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($tab === 'logins'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Quick Logins</h2>
        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap:var(--cv-space-4);">
            <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/sso/cpanel" target="_blank" style="display:contents;"><?= csrf_field() ?>
                <button class="cv-card cv-card--interactive" type="submit" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:var(--cv-space-3); border:1px solid var(--cv-border-default); padding:var(--cv-space-4); text-align:center; width:100%; height:100%; background:var(--cv-bg-surface); cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#ff6c2c;"><path d="M4 22h14a2 2 0 0 0 2-2V7l-5-5H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M3 15h6"/><path d="M3 18h6"/><path d="M14 15h.01"/><path d="M14 18h.01"/></svg>
                    <span style="font-weight:600; font-size:var(--cv-text-sm); color:var(--cv-text-primary);">cPanel</span>
                </button>
            </form>
            <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/sso/webmail" target="_blank" style="display:contents;"><?= csrf_field() ?>
                <button class="cv-card cv-card--interactive" type="submit" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:var(--cv-space-3); border:1px solid var(--cv-border-default); padding:var(--cv-space-4); text-align:center; width:100%; height:100%; background:var(--cv-bg-surface); cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#2563eb;"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    <span style="font-weight:600; font-size:var(--cv-text-sm); color:var(--cv-text-primary);">Webmail</span>
                </button>
            </form>
            <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/sso/phpmyadmin" target="_blank" style="display:contents;"><?= csrf_field() ?>
                <button class="cv-card cv-card--interactive" type="submit" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:var(--cv-space-3); border:1px solid var(--cv-border-default); padding:var(--cv-space-4); text-align:center; width:100%; height:100%; background:var(--cv-bg-surface); cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#f59e0b;"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg>
                    <span style="font-weight:600; font-size:var(--cv-text-sm); color:var(--cv-text-primary);">phpMyAdmin</span>
                </button>
            </form>
            <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/sso/filemanager" target="_blank" style="display:contents;"><?= csrf_field() ?>
                <button class="cv-card cv-card--interactive" type="submit" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:var(--cv-space-3); border:1px solid var(--cv-border-default); padding:var(--cv-space-4); text-align:center; width:100%; height:100%; background:var(--cv-bg-surface); cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#10b981;"><path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></svg>
                    <span style="font-weight:600; font-size:var(--cv-text-sm); color:var(--cv-text-primary);">File Manager</span>
                </button>
            </form>
            <form method="post" action="/client/services/<?= $id ?>/cpanel-tools/sso/softaculous" target="_blank" style="display:contents;"><?= csrf_field() ?>
                <button class="cv-card cv-card--interactive" type="submit" style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:var(--cv-space-3); border:1px solid var(--cv-border-default); padding:var(--cv-space-4); text-align:center; width:100%; height:100%; background:var(--cv-bg-surface); cursor:pointer;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:#8b5cf6;"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    <span style="font-weight:600; font-size:var(--cv-text-sm); color:var(--cv-text-primary);">Softaculous</span>
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>
