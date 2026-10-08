<?php
/** @var array<string, mixed> $service */
/** @var array<string, mixed>|null $zone */
/** @var array{show: bool, canEnable: bool, zone: mixed, reason: string} $state */
/** @var string $tab */
/** @var string|null $notice */
/** @var string|null $error */
/** @var bool $registered */
/** @var bool $manageable */
/** @var array<int, array<string, mixed>> $records */
/** @var array<string, mixed> $settings */
/** @var array<int, array<string, mixed>> $rules */
/** @var array<int, array<string, mixed>> $activity */
/** @var string|null $loadError */
/** @var string $editId */

use CodeVault\Cloudflare\CloudflareService;

$id = (int) $service['id'];
$base = '/client/services/' . $id . '/cloudflare';
$tabs = ['overview' => 'Overview', 'dns' => 'DNS', 'ssl' => 'SSL/TLS', 'caching' => 'Caching', 'security' => 'Security', 'activity' => 'Activity'];
$domainLabel = (string) ($zone['name'] ?? CloudflareService::zoneName((string) ($service['domain'] ?? '')) ?? ($service['domain'] ?? ''));

$statusBadge = static function (?array $zone): string {
    if ($zone === null) {
        return '';
    }

    if ($zone['delete_after'] !== null) {
        return '<span class="cv-badge cv-badge--danger">Scheduled for removal</span>';
    }

    if ((int) $zone['paused'] === 1) {
        return '<span class="cv-badge cv-badge--neutral">Paused</span>';
    }

    return $zone['status'] === 'active'
        ? '<span class="cv-badge cv-badge--success">Active</span>'
        : '<span class="cv-badge cv-badge--warning">Waiting for nameservers</span>';
};

$val = static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default;

/** A compact "choose a value + Save" form for one zone setting. */
$settingForm = static function (string $setting, array $options, mixed $current, bool $enabled) use ($base): string {
    $html = '<form method="post" action="' . e($base . '/setting') . '" class="cf-inline">' . csrf_field()
        . '<input type="hidden" name="setting" value="' . e($setting) . '">'
        . '<select class="cv-input" name="value"' . ($enabled ? '' : ' disabled') . ' aria-label="' . e(CloudflareService::settingLabel($setting)) . '">';

    foreach ($options as $value => $label) {
        $html .= '<option value="' . e((string) $value) . '"' . ((string) $value === (string) $current ? ' selected' : '') . '>' . e($label) . '</option>';
    }

    return $html . '</select><button class="cv-btn cv-btn--secondary" type="submit"' . ($enabled ? '' : ' disabled') . '>Save</button></form>';
};

/** An on/off switch rendered as a single button. */
$toggle = static function (string $setting, mixed $current, bool $enabled) use ($base): string {
    $on = $current === 'on';

    return '<form method="post" action="' . e($base . '/setting') . '" class="cf-inline">' . csrf_field()
        . '<input type="hidden" name="setting" value="' . e($setting) . '">'
        . '<input type="hidden" name="value" value="' . ($on ? 'off' : 'on') . '">'
        . '<span class="cv-badge ' . ($on ? 'cv-badge--success' : 'cv-badge--neutral') . '">' . ($on ? 'On' : 'Off') . '</span>'
        . '<button class="cv-btn cv-btn--secondary" type="submit"' . ($enabled ? '' : ' disabled') . '>' . ($on ? 'Turn off' : 'Turn on') . '</button></form>';
};

$ttlLabel = static function (int $ttl): string {
    if ($ttl === 1) {
        return 'Auto';
    }

    return $ttl % 3600 === 0 ? ($ttl / 3600) . ' hr' : ($ttl % 60 === 0 ? ($ttl / 60) . ' min' : $ttl . ' s');
};
$ttlOptions = [1 => 'Auto', 300 => '5 min', 1800 => '30 min', 3600 => '1 hr', 14400 => '4 hr', 86400 => '1 day'];
?>
<style>
    .cf-head{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap}
    .cf-head h1{margin:0 0 .25rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
    .cf-logo{display:inline-flex;width:2.25rem;height:2.25rem;border-radius:.6rem;background:linear-gradient(135deg,#f6821f,#fbad41);color:#fff;align-items:center;justify-content:center;font-weight:700}
    .cf-muted{color:var(--cv-color-text-muted,#64748b);font-size:.9rem}
    .cf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--cv-space-4,1rem);margin-bottom:var(--cv-space-4,1rem)}
    .cf-card{margin-bottom:var(--cv-space-4,1rem)}
    .cf-ns{display:grid;gap:.5rem;margin:.75rem 0}
    .cf-ns code{display:block;padding:.6rem .8rem;border-radius:.5rem;background:var(--cv-color-surface-2,#f1f5f9);font-size:1rem;font-weight:600;word-break:break-all}
    .cf-steps{margin:.5rem 0 0;padding-left:1.25rem}
    .cf-steps li{margin:.35rem 0}
    .cf-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:.75rem}
    .cf-inline{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin:0}
    .cf-inline .cv-input{width:auto;min-width:10rem}
    .cf-row{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.9rem 0;border-top:1px solid var(--cv-color-border,#e2e8f0);flex-wrap:wrap}
    .cf-row:first-of-type{border-top:0}
    .cf-row h3{margin:0 0 .2rem;font-size:1rem}
    .cf-row p{margin:0}
    .cf-benefits{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;margin:1rem 0;padding:0;list-style:none}
    .cf-benefits li{padding:.75rem .9rem;border-radius:.6rem;background:var(--cv-color-surface-2,#f8fafc);border:1px solid var(--cv-color-border,#e2e8f0)}
    .cf-benefits strong{display:block;margin-bottom:.15rem}
    .cf-cloud{display:inline-flex;align-items:center;gap:.3rem;font-size:.85rem;font-weight:600}
    .cf-cloud--on{color:#f6821f}
    .cf-cloud--off{color:var(--cv-color-text-muted,#64748b)}
    .cf-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;align-items:end}
    .cf-table-wrap{overflow-x:auto}
    .cf-table-wrap td{vertical-align:middle}
    .cf-content{max-width:22rem;word-break:break-all;font-family:var(--cv-font-mono,monospace);font-size:.85rem}
    .cf-danger{border:1px solid #fecaca}
    .cf-flash{margin-bottom:var(--cv-space-4,1rem)}
    .cf-card [hidden]{display:none!important}
    .cf-card .cv-table{display:table;width:100%}
    .cf-card .cf-table-wrap .cv-table{white-space:nowrap}
    .cf-check{display:flex;align-items:center;gap:.45rem;white-space:nowrap;min-height:2.5rem;cursor:pointer}
    .cf-head .cv-btn,.cf-card a.cv-btn{text-decoration:none}
</style>

<div class="cv-card cf-card">
    <div class="cf-head">
        <div>
            <h1 class="cv-card__title"><span class="cf-logo" aria-hidden="true">CF</span> Cloudflare &mdash; <?= e($domainLabel) ?> <?= $statusBadge($zone) ?></h1>
            <p class="cf-muted">Free CDN, SSL and DDoS protection for your website.</p>
        </div>
        <a class="cv-btn cv-btn--secondary" href="/client/services/<?= $id ?>">&larr; Back to service</a>
    </div>
</div>

<?php if ($notice): ?><div class="cv-alert cv-alert--success cf-flash" role="status"><?= e((string) $notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="cv-alert cv-alert--error cf-flash" role="alert"><?= e((string) $error) ?></div><?php endif; ?>

<?php if ($zone === null): ?>
    <div class="cv-card cf-card">
        <h2 class="cv-card__title">Turn on free Cloudflare for <?= e($domainLabel) ?></h2>
        <ul class="cf-benefits">
            <li><strong>Faster everywhere</strong><span class="cf-muted">Your site is cached on a global network close to your visitors.</span></li>
            <li><strong>Free SSL</strong><span class="cf-muted">HTTPS for your domain, renewed automatically.</span></li>
            <li><strong>DDoS &amp; bot protection</strong><span class="cf-muted">Attacks are filtered before they reach your hosting.</span></li>
            <li><strong>You stay in control</strong><span class="cf-muted">Manage DNS, caching and security right here.</span></li>
        </ul>
        <?php if ($state['canEnable']): ?>
            <p class="cf-muted">We copy your current DNS records, so nothing changes until you switch your nameservers. It's free, and you can turn it off at any time.</p>
            <form method="post" action="<?= e($base . '/enable') ?>">
                <?= csrf_field() ?>
                <button class="cv-btn" type="submit">Enable free Cloudflare</button>
            </form>
        <?php else: ?>
            <div class="cv-alert cv-alert--neutral"><?= e($state['reason']) ?></div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php if ($zone['delete_after'] !== null): ?>
        <div class="cv-alert cv-alert--error cf-flash">
            <strong>Cloudflare will be removed from <?= e((string) $zone['name']) ?> on <?= e(date('j M Y, H:i', strtotime((string) $zone['delete_after']) ?: time())) ?>.</strong>
            <?php if ((string) $service['status'] === 'active'): ?>
                <form method="post" action="<?= e($base . '/keep') ?>" class="cf-actions">
                    <?= csrf_field() ?>
                    <button class="cv-btn" type="submit">Keep Cloudflare</button>
                </form>
            <?php endif; ?>
        </div>
    <?php elseif ((int) $zone['paused'] === 1): ?>
        <div class="cv-alert cv-alert--neutral cf-flash">Cloudflare is paused<?= (int) $zone['paused_by_us'] === 1 ? ' while this service is suspended' : '' ?>. Your DNS still works, but traffic is not protected or cached.</div>
    <?php endif; ?>

    <div class="cv-tabs" style="margin-bottom:var(--cv-space-4);" role="tablist">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="cv-tab" href="<?= e($base . ($key === 'overview' ? '' : '?tab=' . $key)) ?>" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($loadError !== null): ?>
        <div class="cv-alert cv-alert--error cf-flash"><?= e($loadError) ?></div>
    <?php endif; ?>

    <?php if ($tab === 'overview'): ?>
        <?php $ns = (array) $zone['name_servers']; ?>
        <?php if ($zone['status'] !== 'active' && $zone['delete_after'] === null): ?>
            <div class="cv-card cf-card">
                <h2 class="cv-card__title">One step left: switch your nameservers</h2>
                <p>Cloudflare is ready for <strong><?= e((string) $zone['name']) ?></strong>. To activate it, the domain's nameservers must point to:</p>
                <div class="cf-ns">
                    <?php foreach ($ns as $server): ?><code><?= e((string) $server) ?></code><?php endforeach; ?>
                </div>
                <?php if ($registered): ?>
                    <p>Your domain is registered with us, so we can make this change for you.</p>
                    <form method="post" action="<?= e($base . '/nameservers') ?>" class="cf-actions">
                        <?= csrf_field() ?>
                        <button class="cv-btn" type="submit">Switch nameservers for me</button>
                        <span class="cf-muted">Your current nameservers are saved so they can be put back if you turn Cloudflare off.</span>
                    </form>
                <?php else: ?>
                    <ol class="cf-steps">
                        <li>Sign in where you registered <?= e((string) $zone['name']) ?> (your registrar).</li>
                        <li>Find the nameserver (DNS) settings and replace the current nameservers with the two above.</li>
                        <li>Save. It usually takes effect within a few hours (up to 24).</li>
                    </ol>
                    <?php if (!empty($zone['original_name_servers'])): ?>
                        <p class="cf-muted">Current nameservers: <?= e(implode(', ', (array) $zone['original_name_servers'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
                <form method="post" action="<?= e($base . '/check') ?>" class="cf-actions">
                    <?= csrf_field() ?>
                    <button class="cv-btn cv-btn--secondary" type="submit">Check now</button>
                    <span class="cf-muted">We also check automatically and email you once it's active.</span>
                </form>
            </div>
        <?php endif; ?>

        <div class="cf-grid">
            <div class="cv-card">
                <h2 class="cv-card__title">Status</h2>
                <p><?= $statusBadge($zone) ?></p>
                <p class="cf-muted">
                    Plan: Free<br>
                    <?php if (!empty($zone['activated_at'])): ?>Active since <?= e(date('j M Y', strtotime((string) $zone['activated_at']) ?: time())) ?><br><?php endif; ?>
                    Set up <?= e(date('j M Y', strtotime((string) $zone['created_at']) ?: time())) ?>
                </p>
                <?php if ($zone['status'] === 'active'): ?>
                    <form method="post" action="<?= e($base . '/check') ?>" class="cf-actions">
                        <?= csrf_field() ?>
                        <button class="cv-btn cv-btn--secondary" type="submit">Refresh status</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="cv-card">
                <h2 class="cv-card__title">Cloudflare nameservers</h2>
                <div class="cf-ns"><?php foreach ($ns as $server): ?><code><?= e((string) $server) ?></code><?php endforeach; ?></div>
            </div>
            <div class="cv-card">
                <h2 class="cv-card__title">Quick links</h2>
                <p><a href="<?= e($base . '?tab=dns') ?>">Manage DNS records</a><br>
                <a href="<?= e($base . '?tab=caching') ?>">Purge cache</a><br>
                <a href="<?= e($base . '?tab=security') ?>">Security &amp; "I'm under attack" mode</a></p>
            </div>
        </div>

        <?php if ($activity !== []): ?>
            <div class="cv-card cf-card">
                <h2 class="cv-card__title">Recent activity</h2>
                <table class="cv-table"><tbody>
                <?php foreach ($activity as $row): ?>
                    <tr><td class="cf-muted" style="white-space:nowrap"><?= e(date('j M, H:i', strtotime((string) $row['created_at']) ?: time())) ?></td><td><?= e((string) $row['summary']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <p><a href="<?= e($base . '?tab=activity') ?>">See all activity</a></p>
            </div>
        <?php endif; ?>

        <?php if ($zone['delete_after'] === null): ?>
            <div class="cv-card cf-card cf-danger">
                <h2 class="cv-card__title">Turn off Cloudflare</h2>
                <p class="cf-muted">
                    <?= (int) $zone['ns_switched_by_us'] === 1
                        ? 'We will put your previous nameservers back straight away.'
                        : 'If you changed your nameservers to Cloudflare, change them back at your registrar first so your site keeps working.' ?>
                    Cloudflare is removed after a grace period, and you can change your mind until then.
                </p>
                <form method="post" action="<?= e($base . '/disable') ?>" class="cf-actions">
                    <?= csrf_field() ?>
                    <label class="cf-inline"><input type="checkbox" name="confirm" value="1" required> I understand</label>
                    <button class="cv-btn cv-btn--secondary" type="submit">Turn off Cloudflare</button>
                </form>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'dns'): ?>
        <?php if ($manageable): ?>
            <div class="cv-card cf-card">
                <h2 class="cv-card__title">Add a DNS record</h2>
                <form method="post" action="<?= e($base . '/dns') ?>" class="cf-form-grid" data-cf-dns-form>
                    <?= csrf_field() ?>
                    <div class="cv-field"><label class="cv-label" for="cf-type">Type</label>
                        <select class="cv-input" id="cf-type" name="type" data-cf-type>
                            <?php foreach (CloudflareService::DNS_TYPES as $type): ?><option><?= e($type) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="cv-field"><label class="cv-label" for="cf-name">Name</label>
                        <input class="cv-input" id="cf-name" name="name" placeholder="@ or www" required></div>
                    <div class="cv-field" style="grid-column:span 2"><label class="cv-label" for="cf-content">Content</label>
                        <input class="cv-input" id="cf-content" name="content" placeholder="IP address, hostname or text" required></div>
                    <div class="cv-field" data-cf-priority hidden><label class="cv-label" for="cf-priority">Priority</label>
                        <input class="cv-input" id="cf-priority" name="priority" type="number" min="0" max="65535" value="10"></div>
                    <div class="cv-field"><label class="cv-label" for="cf-ttl">TTL</label>
                        <select class="cv-input" id="cf-ttl" name="ttl"><?php foreach ($ttlOptions as $value => $label): ?><option value="<?= $value ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="cv-field" data-cf-proxy><label class="cf-check"><input type="checkbox" name="proxied" value="1" checked> Proxied</label></div>
                    <div class="cv-field"><button class="cv-btn" type="submit">Add record</button></div>
                </form>
                <p class="cf-muted">Mail, FTP and control-panel names (mail, ftp, cpanel, webmail…) should stay <strong>DNS only</strong> — Cloudflare's proxy only carries web traffic.</p>
            </div>
        <?php endif; ?>

        <div class="cv-card cf-card">
            <h2 class="cv-card__title">DNS records <span class="cf-muted">(<?= count($records) ?>)</span></h2>
            <div class="cf-table-wrap">
            <table class="cv-table">
                <thead><tr><th>Type</th><th>Name</th><th>Content</th><th>Proxy</th><th>TTL</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($records as $record): ?>
                    <?php
                    $rid = (string) ($record['id'] ?? '');
                    $rtype = (string) ($record['type'] ?? '');
                    $editable = in_array($rtype, CloudflareService::DNS_TYPES, true);
                    $short = (string) ($record['name'] ?? '');
                    $short = $short === $zone['name'] ? '@' : preg_replace('/\.' . preg_quote((string) $zone['name'], '/') . '$/', '', $short);
                    ?>
                    <?php if ($editId === $rid && $editable && $manageable): ?>
                        <tr><td colspan="6">
                            <form method="post" action="<?= e($base . '/dns/' . $rid) ?>" class="cf-form-grid" data-cf-dns-form>
                                <?= csrf_field() ?>
                                <input type="hidden" name="type" value="<?= e($rtype) ?>" data-cf-type>
                                <div class="cv-field"><span class="cv-label">Type</span><strong><?= e($rtype) ?></strong></div>
                                <div class="cv-field"><label class="cv-label">Name</label><input class="cv-input" name="name" value="<?= e((string) $short) ?>" required></div>
                                <div class="cv-field" style="grid-column:span 2"><label class="cv-label">Content</label><input class="cv-input" name="content" value="<?= e((string) ($record['content'] ?? '')) ?>" required></div>
                                <?php if ($rtype === 'MX'): ?>
                                    <div class="cv-field"><label class="cv-label">Priority</label><input class="cv-input" name="priority" type="number" min="0" max="65535" value="<?= (int) ($record['priority'] ?? 10) ?>"></div>
                                <?php endif; ?>
                                <div class="cv-field"><label class="cv-label">TTL</label><select class="cv-input" name="ttl"><?php foreach ($ttlOptions + [(int) ($record['ttl'] ?? 1) => $ttlLabel((int) ($record['ttl'] ?? 1))] as $value => $label): ?><option value="<?= $value ?>"<?= (int) ($record['ttl'] ?? 1) === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                                <?php if (in_array($rtype, CloudflareService::PROXIABLE, true)): ?>
                                    <div class="cv-field"><label class="cf-check"><input type="checkbox" name="proxied" value="1"<?= !empty($record['proxied']) ? ' checked' : '' ?>> Proxied</label></div>
                                <?php endif; ?>
                                <div class="cv-field cf-inline"><button class="cv-btn" type="submit">Save</button><a class="cv-btn cv-btn--secondary" href="<?= e($base . '?tab=dns') ?>">Cancel</a></div>
                            </form>
                        </td></tr>
                    <?php else: ?>
                        <tr>
                            <td><span class="cv-badge cv-badge--neutral"><?= e($rtype) ?></span></td>
                            <td><?= e((string) $short) ?></td>
                            <td class="cf-content"><?= $rtype === 'MX' ? '<span class="cf-muted">' . (int) ($record['priority'] ?? 0) . '</span> ' : '' ?><?= e(mb_strimwidth((string) ($record['content'] ?? ''), 0, 120, '…')) ?></td>
                            <td><?php if (!empty($record['proxiable'])): ?>
                                <span class="cf-cloud <?= !empty($record['proxied']) ? 'cf-cloud--on' : 'cf-cloud--off' ?>"><?= !empty($record['proxied']) ? '&#9729; Proxied' : '&#9729; DNS only' ?></span>
                            <?php else: ?><span class="cf-muted">—</span><?php endif; ?></td>
                            <td><?= e($ttlLabel((int) ($record['ttl'] ?? 1))) ?></td>
                            <td style="white-space:nowrap">
                                <?php if ($manageable && $rid !== ''): ?>
                                    <?php if ($editable): ?><a class="cv-btn cv-btn--secondary" href="<?= e($base . '?tab=dns&edit=' . rawurlencode($rid)) ?>">Edit</a><?php endif; ?>
                                    <form method="post" action="<?= e($base . '/dns/' . $rid . '/delete') ?>" style="display:inline" data-cf-confirm="Delete this <?= e($rtype) ?> record?">
                                        <?= csrf_field() ?>
                                        <button class="cv-btn cv-btn--secondary" type="submit">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($records === [] && $loadError === null): ?>
                    <tr><td colspan="6" class="cf-muted">No DNS records yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

    <?php elseif ($tab === 'ssl'): ?>
        <div class="cv-card cf-card">
            <h2 class="cv-card__title">SSL/TLS</h2>
            <div class="cf-row">
                <div><h3>Encryption mode</h3><p class="cf-muted"><strong>Full</strong> is right for most sites hosted with us. Use <strong>Full (strict)</strong> when your hosting has a valid SSL certificate (AutoSSL / Let's Encrypt).</p></div>
                <?= $settingForm('ssl', ['off' => 'Off (not secure)', 'flexible' => 'Flexible', 'full' => 'Full', 'strict' => 'Full (strict)'], $val('ssl'), $manageable) ?>
            </div>
            <div class="cf-row">
                <div><h3>Always use HTTPS</h3><p class="cf-muted">Redirect every http:// request to https://.</p></div>
                <?= $toggle('always_use_https', $val('always_use_https'), $manageable) ?>
            </div>
            <div class="cf-row">
                <div><h3>Automatic HTTPS rewrites</h3><p class="cf-muted">Fix "mixed content" by rewriting http:// links that can be served securely.</p></div>
                <?= $toggle('automatic_https_rewrites', $val('automatic_https_rewrites'), $manageable) ?>
            </div>
            <div class="cf-row">
                <div><h3>Minimum TLS version</h3><p class="cf-muted">TLS 1.2 is recommended.</p></div>
                <?= $settingForm('min_tls_version', ['1.0' => 'TLS 1.0', '1.1' => 'TLS 1.1', '1.2' => 'TLS 1.2', '1.3' => 'TLS 1.3'], $val('min_tls_version'), $manageable) ?>
            </div>
        </div>

    <?php elseif ($tab === 'caching'): ?>
        <div class="cf-grid">
            <div class="cv-card">
                <h2 class="cv-card__title">Purge everything</h2>
                <p class="cf-muted">Removes every cached file. Your site may be slightly slower for a few minutes while the cache refills.</p>
                <form method="post" action="<?= e($base . '/purge') ?>" data-cf-confirm="Purge the entire cache?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="everything" value="1">
                    <button class="cv-btn" type="submit"<?= $manageable ? '' : ' disabled' ?>>Purge everything</button>
                </form>
            </div>
            <div class="cv-card">
                <h2 class="cv-card__title">Purge specific URLs</h2>
                <form method="post" action="<?= e($base . '/purge') ?>">
                    <?= csrf_field() ?>
                    <textarea class="cv-input" name="urls" rows="3" placeholder="https://<?= e((string) $zone['name']) ?>/style.css" aria-label="URLs to purge, one per line"></textarea>
                    <p class="cf-muted">One URL per line, up to 30.</p>
                    <button class="cv-btn cv-btn--secondary" type="submit"<?= $manageable ? '' : ' disabled' ?>>Purge URLs</button>
                </form>
            </div>
        </div>
        <div class="cv-card cf-card">
            <h2 class="cv-card__title">Cache settings</h2>
            <div class="cf-row">
                <div><h3>Development mode</h3><p class="cf-muted">Temporarily bypass the cache while you edit your site. Turns itself off after 3 hours.</p></div>
                <?= $toggle('development_mode', $val('development_mode'), $manageable) ?>
            </div>
            <div class="cf-row">
                <div><h3>Caching level</h3><p class="cf-muted">How query strings affect caching. "Standard" suits most sites.</p></div>
                <?= $settingForm('cache_level', ['basic' => 'No query string', 'simplified' => 'Ignore query string', 'aggressive' => 'Standard'], $val('cache_level'), $manageable) ?>
            </div>
            <div class="cf-row">
                <div><h3>Browser cache TTL</h3><p class="cf-muted">How long visitors' browsers keep your files.</p></div>
                <?= $settingForm('browser_cache_ttl', [0 => 'Respect existing headers', 1800 => '30 minutes', 3600 => '1 hour', 7200 => '2 hours', 14400 => '4 hours', 28800 => '8 hours', 57600 => '16 hours', 86400 => '1 day', 172800 => '2 days', 604800 => '1 week', 2592000 => '1 month', 31536000 => '1 year'], $val('browser_cache_ttl'), $manageable) ?>
            </div>
        </div>

    <?php elseif ($tab === 'security'): ?>
        <div class="cv-card cf-card">
            <h2 class="cv-card__title">Security</h2>
            <div class="cf-row">
                <div><h3>Security level</h3><p class="cf-muted">How suspicious visitors are challenged. Choose <strong>I'm under attack</strong> only during an attack — every visitor sees a short check first.</p></div>
                <?= $settingForm('security_level', ['essentially_off' => 'Essentially off', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'under_attack' => "I'm under attack!"], $val('security_level'), $manageable) ?>
            </div>
            <div class="cf-row">
                <div><h3>Browser integrity check</h3><p class="cf-muted">Block requests from browsers with headers commonly abused by spammers and bots.</p></div>
                <?= $toggle('browser_check', $val('browser_check'), $manageable) ?>
            </div>
        </div>
        <div class="cv-card cf-card">
            <h2 class="cv-card__title">IP access rules</h2>
            <?php if ($manageable): ?>
                <form method="post" action="<?= e($base . '/rules') ?>" class="cf-form-grid">
                    <?= csrf_field() ?>
                    <div class="cv-field"><label class="cv-label" for="cf-mode">Action</label>
                        <select class="cv-input" id="cf-mode" name="mode"><option value="block">Block</option><option value="managed_challenge">Managed challenge</option><option value="js_challenge">JavaScript challenge</option><option value="challenge">Interactive challenge</option><option value="whitelist">Allow</option></select></div>
                    <div class="cv-field"><label class="cv-label" for="cf-target">Applies to</label>
                        <select class="cv-input" id="cf-target" name="target"><option value="ip">IP address</option><option value="ip_range">IP range</option><option value="country">Country</option><option value="asn">ASN</option></select></div>
                    <div class="cv-field"><label class="cv-label" for="cf-value">Value</label><input class="cv-input" id="cf-value" name="value" placeholder="203.0.113.7, NG, AS13335…" required></div>
                    <div class="cv-field"><label class="cv-label" for="cf-notes">Note</label><input class="cv-input" id="cf-notes" name="notes" maxlength="100" placeholder="Optional"></div>
                    <div class="cv-field"><button class="cv-btn" type="submit">Add rule</button></div>
                </form>
            <?php endif; ?>
            <div class="cf-table-wrap">
            <table class="cv-table" style="margin-top:1rem">
                <thead><tr><th>Action</th><th>Target</th><th>Value</th><th>Note</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rules as $rule): ?>
                    <tr>
                        <td><?= e(ucwords(str_replace(['_', 'whitelist'], [' ', 'allow'], (string) ($rule['mode'] ?? '')))) ?></td>
                        <td><?= e(str_replace('_', ' ', (string) ($rule['configuration']['target'] ?? ''))) ?></td>
                        <td><code><?= e((string) ($rule['configuration']['value'] ?? '')) ?></code></td>
                        <td class="cf-muted"><?= e((string) ($rule['notes'] ?? '')) ?></td>
                        <td><?php if ($manageable && !empty($rule['id'])): ?>
                            <form method="post" action="<?= e($base . '/rules/' . (string) $rule['id'] . '/delete') ?>" data-cf-confirm="Remove this rule?">
                                <?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit">Remove</button>
                            </form>
                        <?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rules === [] && $loadError === null): ?>
                    <tr><td colspan="5" class="cf-muted">No rules yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

    <?php elseif ($tab === 'activity'): ?>
        <div class="cv-card cf-card">
            <h2 class="cv-card__title">Activity</h2>
            <table class="cv-table">
                <thead><tr><th>When</th><th>By</th><th>What</th></tr></thead>
                <tbody>
                <?php foreach ($activity as $row): ?>
                    <tr>
                        <td class="cf-muted" style="white-space:nowrap"><?= e(date('j M Y, H:i', strtotime((string) $row['created_at']) ?: time())) ?></td>
                        <td><?= e(match ((string) $row['actor_type']) { 'client' => 'You', 'admin' => 'Support', default => 'Automatic' }) ?></td>
                        <td><?= e((string) $row['summary']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($activity === []): ?><tr><td colspan="3" class="cf-muted">Nothing yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!$manageable && $tab !== 'overview' && $tab !== 'activity'): ?>
        <p class="cf-muted">Changes are disabled while <?= $zone['delete_after'] !== null ? 'Cloudflare is scheduled for removal' : 'the service is not active' ?>.</p>
    <?php endif; ?>
<?php endif; ?>
<script src="/assets/js/cloudflare.js" defer></script>
