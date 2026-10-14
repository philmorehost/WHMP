<?php
/**
 * @var array<string, string> $values
 * @var array<int, array<string, mixed>> $products
 * @var array<int, array<string, mixed>> $productPolicies keyed by product id
 * @var array<int, array<string, mixed>> $clientPolicies
 * @var array<int, array<string, mixed>> $servers
 * @var string $catalogCode
 * @var int $pending
 */
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$on = static fn (string $k): string => ($values[$k] ?? '') === '1' ? ' checked' : '';
$inList = static fn (string $k, string $item): string => in_array($item, explode(',', (string) ($values[$k] ?? '')), true) ? ' checked' : '';
$sel = static fn (string $k, string $opt): string => ($values[$k] ?? '') === $opt ? ' selected' : '';
$tri = static function (?array $row, string $field): string {
    $cur = $row[$field] ?? null;
    $out = '<option value=""' . ($cur === null ? ' selected' : '') . '>Default</option>';
    $out .= '<option value="1"' . ($cur !== null && (int) $cur === 1 ? ' selected' : '') . '>Yes</option>';
    $out .= '<option value="0"' . ($cur !== null && (int) $cur === 0 ? ' selected' : '') . '>No</option>';

    return $out;
};
$code = $catalogCode !== '' ? $catalogCode : 'catalog currency';
?>
<div class="uca">
    <div class="uca-head">
        <div>
            <h1>Username Changer settings</h1>
            <p>Global rules for every customer — platform and reseller stores. Product and client overrides sit below.</p>
        </div>
    </div>
    <?= $view->render('username-changer.admin-tabs', ['pending' => $pending, 'notice' => $notice ?? null, 'error' => $error ?? null]) ?>

    <form method="post" action="/admin/username-changer/settings">
        <?= csrf_field() ?>

        <div class="uca-card" id="fee">
            <h2>💳 Payment</h2>
            <label class="uca-switch"><input type="checkbox" name="fee_enabled" value="1"<?= $on('fee_enabled') ?> data-uca-toggle="#uca-fee-body"> <strong>Charge a fee for each username change</strong></label>
            <div id="uca-fee-body" class="uca-grid2" style="margin-top:14px">
                <div class="uca-field">
                    <label for="uca-fee">Fee (<?= e($code) ?>)</label>
                    <input class="cv-input" id="uca-fee" name="fee" type="number" min="0" step="0.01" value="<?= e($v('fee')) ?>">
                    <small>Platform customers pay this. Shown in each customer's own currency. A product override can set a different fee.</small>
                </div>
                <div class="uca-field">
                    <label class="uca-switch"><input type="checkbox" name="store_pricing" value="1"<?= $on('store_pricing') ?>> <strong>Resellers can resell the change</strong></label>
                    <small>Each reseller sets its own price, never below its cost (this fee, or the parent reseller's price for a sub-reseller). The store's customer pays the store's price, and the margin goes to the reseller's balance once the invoice is paid. If the invoice is refunded, the credit is reversed.</small>
                </div>
            </div>
            <p style="margin:12px 0 0;color:var(--cv-text-secondary);font-size:13px">When payment is off, every change is free. Requests are invoiced only after confirmation and any approval, and the rename runs once the invoice is paid. Admin renames and client overrides set to <em>waive</em> are never charged.</p>
        </div>

        <div class="uca-card">
            <h2>Eligibility &amp; limits</h2>
            <div class="uca-grid2">
                <div class="uca-field"><label>Changes allowed per service</label><input class="cv-input" name="max_changes" type="number" min="0" value="<?= e($v('max_changes')) ?>"><small>0 = unlimited</small></div>
                <div class="uca-field"><label>Cooldown between changes (days)</label><input class="cv-input" name="cooldown_days" type="number" min="0" value="<?= e($v('cooldown_days')) ?>"></div>
                <div class="uca-field"><label>Service statuses allowed</label>
                    <div class="uca-checks"><?php foreach (['active', 'suspended'] as $st): ?><label><input type="checkbox" name="statuses[]" value="<?= $st ?>"<?= $inList('statuses', $st) ?>> <?= ucfirst($st) ?></label><?php endforeach; ?></div></div>
                <div class="uca-field"><label>Product types</label>
                    <div class="uca-checks"><?php foreach (['shared', 'reseller', 'vps', 'dedicated', 'other'] as $t): ?><label><input type="checkbox" name="product_types[]" value="<?= $t ?>"<?= $inList('product_types', $t) ?>> <?= ucfirst($t) ?></label><?php endforeach; ?></div>
                    <small>Only services on cPanel/WHM servers are ever eligible.</small></div>
            </div>
        </div>

        <div class="uca-card">
            <h2>Username rules</h2>
            <div class="uca-grid2">
                <div class="uca-field"><label>Minimum length</label><input class="cv-input" name="min_length" type="number" min="1" max="16" value="<?= e($v('min_length')) ?>"></div>
                <div class="uca-field"><label>Maximum length</label><input class="cv-input" name="max_length" type="number" min="1" max="16" value="<?= e($v('max_length')) ?>"><small>cPanel allows 16 at most.</small></div>
                <div class="uca-field"><label>Name must be unique across</label>
                    <select class="cv-input" name="unique_scope"><option value="platform"<?= $sel('unique_scope', 'platform') ?>>All services on the platform</option><option value="server"<?= $sel('unique_scope', 'server') ?>>The service's server only</option></select></div>
                <div class="uca-field"><label>First-8-characters rule (MySQL)</label>
                    <select class="cv-input" name="first8_rule"><option value="auto"<?= $sel('first8_rule', 'auto') ?>>Automatic per server</option><option value="on"<?= $sel('first8_rule', 'on') ?>>Always enforce</option><option value="off"<?= $sel('first8_rule', 'off') ?>>Never enforce</option></select></div>
                <div class="uca-field" style="grid-column:1/-1"><label>Extra reserved names</label><textarea class="cv-input" name="reserved_extra" rows="2" placeholder="one per line or comma-separated"><?= e($v('reserved_extra')) ?></textarea><small>Added to the built-in list (root, admin, cpanel, mysql, www and more).</small></div>
            </div>
        </div>

        <div class="uca-card">
            <h2>Confirmation &amp; approval</h2>
            <div class="uca-grid2">
                <div class="uca-field"><label>Ways to confirm</label>
                    <div class="uca-checks"><label><input type="checkbox" name="confirm_methods[]" value="email"<?= $inList('confirm_methods', 'email') ?>> Email link</label><label><input type="checkbox" name="confirm_methods[]" value="pin"<?= $inList('confirm_methods', 'pin') ?>> Account security PIN</label></div>
                    <small>The PIN is offered only to clients who have set one.</small></div>
                <div class="uca-field"><label>Email link expires after (hours)</label><input class="cv-input" name="confirm_ttl_hours" type="number" min="1" max="720" value="<?= e($v('confirm_ttl_hours')) ?>"></div>
                <div class="uca-field"><label>Approval</label>
                    <select class="cv-input" name="approval"><option value="none"<?= $sel('approval', 'none') ?>>No approval — run once confirmed</option><option value="admin"<?= $sel('approval', 'admin') ?>>Super admin approves every request</option></select></div>
                <div class="uca-field"><label class="uca-switch"><input type="checkbox" name="require_reason" value="1"<?= $on('require_reason') ?>> <strong>Ask clients for a reason</strong></label></div>
            </div>
        </div>

        <div class="uca-card">
            <h2>Execution</h2>
            <div class="uca-grid2">
                <div class="uca-field"><label>Run the rename</label>
                    <select class="cv-input" name="execution"><option value="immediate"<?= $sel('execution', 'immediate') ?>>Immediately</option><option value="queued"<?= $sel('execution', 'queued') ?>>On the next cron run (every 5 min)</option></select></div>
                <div class="uca-field"><label>Attempts before giving up</label><input class="cv-input" name="max_attempts" type="number" min="1" max="10" value="<?= e($v('max_attempts')) ?>"></div>
                <div class="uca-field"><label class="uca-switch"><input type="checkbox" name="allow_db_rename" value="1"<?= $on('allow_db_rename') ?>> <strong>Let clients rename databases to the new prefix</strong></label><small>Sites must then update their database settings.</small></div>
                <div class="uca-field"><label>Keep audit events (days)</label><input class="cv-input" name="retention_days" type="number" min="7" value="<?= e($v('retention_days')) ?>"></div>
                <div class="uca-field"><label>Staff alert email</label><input class="cv-input" name="staff_alert_email" type="email" value="<?= e($v('staff_alert_email')) ?>" placeholder="Defaults to admins with add-on access"></div>
            </div>
        </div>

        <div class="uca-card">
            <h2>Reseller stores</h2>
            <div class="uca-grid2">
                <div class="uca-field"><label class="uca-switch"><input type="checkbox" name="stores_allowed" value="1"<?= $on('stores_allowed') ?>> <strong>Reseller customers can change usernames</strong></label><small>Emails and pages carry only the store's brand.</small></div>
                <div class="uca-field"><label class="uca-switch"><input type="checkbox" name="store_approval_allowed" value="1"<?= $on('store_approval_allowed') ?>> <strong>Resellers may require their own approval</strong></label><small>Used only when super-admin approval is off.</small></div>
            </div>
        </div>

        <div class="uca-card">
            <h2>Branding</h2>
            <div class="uca-grid2">
                <div class="uca-field"><label>Heading</label><input class="cv-input" name="heading" maxlength="80" value="<?= e($v('heading')) ?>"></div>
                <div class="uca-field"><label>Accent colour</label><input class="cv-input" name="accent" placeholder="#2563eb — blank uses the site theme" pattern="#[0-9a-fA-F]{6}" value="<?= e($v('accent')) ?>"></div>
            </div>
        </div>

        <p><button class="cv-btn" type="submit">Save settings</button></p>
    </form>

    <div class="uca-card" id="products">
        <h2>Product overrides</h2>
        <p style="color:var(--cv-text-secondary);font-size:13px;margin-top:0">Leave a field blank to use the global setting.</p>
        <?php if ($products === []): ?>
            <p>No hosting products yet.</p>
        <?php else: ?>
            <form method="post" action="/admin/username-changer/policies/products">
                <?= csrf_field() ?>
                <div style="overflow-x:auto">
                <table class="uca-table">
                    <thead><tr><th>Product</th><th>Enabled</th><th>Changes</th><th>Cooldown</th><th>Approval</th><th>DB rename</th><?php if ($v('fee_enabled') === '1'): ?><th>Fee (<?= e($code) ?>)</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($products as $p): $pid = (int) $p['id']; $row = $productPolicies[$pid] ?? null; ?>
                        <tr>
                            <td><?= e((string) $p['name']) ?> <small>(<?= e((string) $p['type']) ?>)</small></td>
                            <td><select class="cv-input" name="p[<?= $pid ?>][enabled]"><?= $tri($row, 'enabled') ?></select></td>
                            <td><input class="cv-input" style="width:80px" type="number" min="0" name="p[<?= $pid ?>][max_changes]" value="<?= e((string) ($row['max_changes'] ?? '')) ?>"></td>
                            <td><input class="cv-input" style="width:80px" type="number" min="0" name="p[<?= $pid ?>][cooldown_days]" value="<?= e((string) ($row['cooldown_days'] ?? '')) ?>"></td>
                            <td><select class="cv-input" name="p[<?= $pid ?>][approval]"><?php $a = $row['approval'] ?? ''; ?><option value=""<?= $a === '' || $a === null ? ' selected' : '' ?>>Default</option><option value="none"<?= $a === 'none' ? ' selected' : '' ?>>None</option><option value="admin"<?= $a === 'admin' ? ' selected' : '' ?>>Admin</option></select></td>
                            <td><select class="cv-input" name="p[<?= $pid ?>][allow_db_rename]"><?= $tri($row, 'allow_db_rename') ?></select></td>
                            <?php if ($v('fee_enabled') === '1'): ?><td><input class="cv-input" style="width:100px" type="number" min="0" step="0.01" name="p[<?= $pid ?>][fee]" value="<?= e((string) ($row['fee'] ?? '')) ?>" placeholder="<?= e($v('fee')) ?>"></td><?php else: ?><input type="hidden" name="p[<?= $pid ?>][fee]" value="<?= e((string) ($row['fee'] ?? '')) ?>"><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p><button class="cv-btn" type="submit">Save product overrides</button></p>
            </form>
        <?php endif; ?>
    </div>

    <div class="uca-card" id="clients">
        <h2>Client overrides</h2>
        <form class="uca-filter" method="post" action="/admin/username-changer/policies/client">
            <?= csrf_field() ?>
            <input class="cv-input" name="client_id" type="number" min="1" placeholder="Client ID" required style="width:120px">
            <select class="cv-input" name="client_mode"><option value="">Normal rules</option><option value="waive">Waive limits &amp; fee</option><option value="block">Block changes</option></select>
            <input class="cv-input" name="extra_changes" type="number" min="0" placeholder="Extra changes" style="width:140px">
            <input class="cv-input" name="note" placeholder="Note (staff only)" maxlength="255">
            <button class="cv-btn" type="submit">Save override</button>
        </form>
        <?php if ($clientPolicies !== []): ?>
            <table class="uca-table">
                <thead><tr><th>Client</th><th>Mode</th><th>Extra changes</th><th>Note</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($clientPolicies as $c): ?>
                    <tr>
                        <td>#<?= (int) $c['scope_id'] ?> <?= e(trim($c['first_name'] . ' ' . $c['last_name'])) ?> <small><?= e((string) $c['email']) ?></small></td>
                        <td><?= e((string) ($c['client_mode'] ?? 'normal')) ?></td>
                        <td><?= e((string) ($c['extra_changes'] ?? '—')) ?></td>
                        <td><?= e((string) ($c['note'] ?? '')) ?></td>
                        <td><form method="post" action="/admin/username-changer/policies/client"><?= csrf_field() ?><input type="hidden" name="client_id" value="<?= (int) $c['scope_id'] ?>"><input type="hidden" name="remove" value="1"><button class="cv-btn cv-btn--secondary uca-btn-sm" type="submit">Remove</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="uca-card" id="servers">
        <h2>cPanel servers</h2>
        <p style="color:var(--cv-text-secondary);font-size:13px;margin-top:0">The instant check uses a cached copy of each server's account list (refreshed by cron). The live WHM check always runs again right before a rename.</p>
        <?php if ($servers === []): ?>
            <p>No cPanel servers configured.</p>
        <?php else: ?>
            <table class="uca-table">
                <thead><tr><th>Server</th><th>Accounts cached</th><th>Database engine</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($servers as $s): $sid = (int) $s['id']; ?>
                    <tr>
                        <td><?= e((string) $s['name']) ?> <small><?= e((string) $s['hostname']) ?></small><?= (int) $s['active'] === 1 ? '' : ' <span class="uca-pill">inactive</span>' ?>
                            <?php if (!empty($s['last_error'])): ?><br><small style="color:#b91c1c"><?= e((string) $s['last_error']) ?></small><?php endif; ?></td>
                        <td><?= $s['account_count'] === null ? '—' : (int) $s['account_count'] ?> <small><?= e((string) ($s['accounts_synced_at'] ?? 'never')) ?></small></td>
                        <td>
                            <form class="uca-inline" method="post" action="/admin/username-changer/servers/<?= $sid ?>/engine"><?= csrf_field() ?>
                                <?php $ov = (string) ($s['db_engine_override'] ?? ''); ?>
                                <select class="cv-input uca-btn-sm" name="engine"><option value="">Detected: <?= e((string) ($s['db_engine'] ?? 'unknown')) ?></option><option value="mysql"<?= $ov === 'mysql' ? ' selected' : '' ?>>MySQL</option><option value="mariadb"<?= $ov === 'mariadb' ? ' selected' : '' ?>>MariaDB</option></select>
                                <button class="cv-btn cv-btn--secondary uca-btn-sm" type="submit">Set</button>
                            </form>
                        </td>
                        <td><form method="post" action="/admin/username-changer/servers/<?= $sid ?>/refresh"><?= csrf_field() ?><button class="cv-btn uca-btn-sm" type="submit">Refresh now</button></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/js/username-changer-admin.js" defer></script>
