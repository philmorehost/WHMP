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
use CodeVault\Cloudflare\CloudflareRules;

$dnssec ??= null;
$origin ??= null;
$rulesets ??= [];
$analytics ??= null;
$emailRouting ??= null;
$emailManageable ??= false;
$range ??= 7;

$id = (int) $service['id'];
$base = '/client/services/' . $id . '/cloudflare';
$tabs = ['overview' => 'Overview', 'dns' => 'DNS', 'ssl' => 'SSL/TLS', 'speed' => 'Speed', 'caching' => 'Caching', 'rules' => 'Rules', 'security' => 'Security', 'email' => 'Email Routing', 'analytics' => 'Analytics', 'activity' => 'Activity'];
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
    .cv-tabs{overflow-x:auto;flex-wrap:nowrap;scrollbar-width:thin}.cv-tabs .cv-tab{white-space:nowrap}
    .cf-ds{display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem;margin:.75rem 0}.cf-ds dt{font-weight:600}.cf-ds dd{margin:0;font-family:var(--cv-font-mono,monospace);font-size:.85rem;word-break:break-all}
    .cf-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.75rem;margin-bottom:1rem}.cf-stat{padding:.85rem 1rem;border-radius:.75rem;background:#fff;border:1px solid var(--cv-color-border,#e2e8f0)}.cf-stat span{display:block;font-size:.8rem;color:var(--cv-color-text-muted,#64748b)}.cf-stat strong{display:block;font-size:1.25rem;margin-top:.15rem}
    .cf-bars{list-style:none;margin:0;padding:0;display:grid;gap:.55rem}.cf-bars li{display:grid;grid-template-columns:minmax(5rem,9rem) 1fr auto;gap:.7rem;align-items:center;font-size:.9rem}.cf-bar{height:.6rem;border-radius:1rem;background:#f1f5f9;overflow:hidden}.cf-bar b{display:block;height:100%;background:#f6821f;border-radius:1rem}
    .cf-presets{display:flex;flex-wrap:wrap;gap:.5rem}.cf-presets form{margin:0}.cf-details{margin-top:1rem;border-top:1px solid var(--cv-color-border,#e2e8f0);padding-top:.75rem}.cf-details summary{cursor:pointer;font-weight:600;margin-bottom:.75rem}.cf-expr{font-family:var(--cv-font-mono,monospace);font-size:.8rem;word-break:break-word;max-width:24rem}
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
            <?php if (!empty($zone['ns_restore_after'])): ?>
                <p class="cf-muted">We removed the registrar DS record. Your previous nameservers will be restored automatically after the DNSSEC cache wait, on <?= e(date('j M Y, H:i', strtotime((string)$zone['ns_restore_after']) ?: time())) ?>.</p>
            <?php elseif (in_array((string)($zone['dnssec_status']??''),['active','pending','pending-disabled','unknown'],true) && (int)($zone['dnssec_ds_removed']??0)!==1): ?>
                <p class="cf-muted">DNSSEC may still have an external DS record or could not be verified. Do not change nameservers away from Cloudflare yet; zone removal will wait until it is safe.</p>
            <?php endif; ?>
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

        <?php if ($dnssec !== null): ?>
            <?php
            $dsStatus = (string) ($dnssec['status'] ?? 'disabled');
            $ds = $dnssec['ds'] ?? null;
            $dsBadge = match ($dsStatus) {
                'active' => '<span class="cv-badge cv-badge--success">On</span>',
                'pending' => '<span class="cv-badge cv-badge--warning">Waiting for DS</span>',
                'pending-disabled' => '<span class="cv-badge cv-badge--warning">Turning off</span>',
                'error' => '<span class="cv-badge cv-badge--danger">Error</span>',
                default => '<span class="cv-badge cv-badge--neutral">Off</span>',
            };
            ?>
            <div class="cv-card cf-card">
                <h2 class="cv-card__title">DNSSEC <?= !empty($dnssec['ok']) ? $dsBadge : '' ?></h2>
                <p class="cf-muted">DNSSEC signs your DNS records to help prevent forged DNS answers.</p>
                <?php if (empty($dnssec['ok'])): ?>
                    <div class="cv-alert cv-alert--neutral"><?= e((string) ($dnssec['message'] ?? 'DNSSEC could not be read.')) ?></div>
                <?php elseif (in_array($dsStatus, ['disabled', ''], true)): ?>
                    <?php if ((string) $zone['status'] !== 'active'): ?>
                        <p class="cf-muted">Activate Cloudflare for this domain first.</p>
                    <?php elseif ($manageable): ?>
                        <p class="cf-muted"><?= !empty($dnssec['canPublish']) ? 'Your domain is registered with us; we can add the DS record at the registrar for you.' : 'After switching it on, add the displayed DS record at your registrar.' ?></p>
                        <form method="post" action="<?= e($base . '/dnssec/enable') ?>"><?= csrf_field() ?><button class="cv-btn" type="submit">Turn on DNSSEC</button></form>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if (is_array($ds)): ?>
                        <dl class="cf-ds"><dt>Key tag</dt><dd><?= e((string) $ds['key_tag']) ?></dd><dt>Algorithm</dt><dd><?= e((string) $ds['algorithm']) ?></dd><dt>Digest type</dt><dd><?= e((string) $ds['digest_type']) ?></dd><dt>Digest</dt><dd><?= e((string) $ds['digest']) ?></dd></dl>
                    <?php endif; ?>
                    <?php if ($dsStatus === 'pending-disabled' && (int)($zone['dnssec_ds_removed']??0)!==1 && !empty($dnssec['publishedByUs'])): ?>
                        <p class="cf-muted"><strong>Cloudflare reports signing is off, but our registrar DS record may still be published.</strong> Turn DNSSEC off below; we remove the DS record and keep the zone serving through the <?= CloudflareService::DS_SETTLE_HOURS ?>-hour cache wait.</p>
                    <?php elseif ($dsStatus === 'pending-disabled' && (int)($zone['dnssec_ds_removed']??0)!==1): ?>
                        <p class="cf-muted"><strong>Cloudflare reports signing is off, but an unmanaged registrar DS record may still be published.</strong> Remove it at your registrar and confirm below; we keep the zone serving through a <?= CloudflareService::DS_SETTLE_HOURS ?>-hour DNS cache wait before nameserver changes or deletion.</p>
                    <?php elseif ($dsStatus === 'pending-disabled'): ?>
                        <p class="cf-muted">The DS record has been removed. Cloudflare signing stops<?= !empty($zone['dnssec_disable_after']) ? ' on ' . e(date('j M Y H:i', strtotime((string) $zone['dnssec_disable_after']) ?: time())) : ' after the DNS cache wait period' ?>.</p>
                    <?php elseif (!empty($dnssec['publishedByUs'])): ?>
                        <p class="cf-muted">We published this DS record at the registrar.<?= $dsStatus === 'pending' ? ' Cloudflare will confirm it shortly.' : '' ?></p>
                    <?php elseif (!empty($dnssec['canPublish']) && $manageable && is_array($ds)): ?>
                        <form method="post" action="<?= e($base . '/dnssec/enable') ?>" class="cf-actions"><?= csrf_field() ?><button class="cv-btn" type="submit">Add DS record at registrar</button></form>
                    <?php elseif (is_array($ds)): ?>
                        <p class="cf-muted">Add the DS values above at your registrar. Cloudflare detects the record automatically.</p>
                    <?php endif; ?>
                    <?php if ((string) $service['status'] === 'active' && ($dsStatus !== 'pending-disabled' || (int)($zone['dnssec_ds_removed']??0)!==1)): ?>
                        <details class="cf-details"><summary>Turn off DNSSEC</summary>
                            <form method="post" action="<?= e($base . '/dnssec/disable') ?>" data-cf-confirm="Turn off DNSSEC?">
                                <?= csrf_field() ?>
                                <?php if (!empty($dnssec['publishedByUs'])): ?>
                                    <p class="cf-muted">We remove our registrar DS record first. Cloudflare stops signing after <?= CloudflareService::DS_SETTLE_HOURS ?> hours, once DNS caches clear.</p>
                                <?php elseif (in_array($dsStatus, ['active', 'pending', 'pending-disabled'], true) && is_array($ds)): ?>
                                    <p class="cf-muted"><strong>Remove this DS record at your registrar, then confirm to start a <?= CloudflareService::DS_SETTLE_HOURS ?>-hour DNS cache wait.</strong> Disabling DNSSEC before the wait ends can make the domain stop resolving.</p>
                                    <label class="cf-check"><input type="checkbox" name="confirm" value="1" required> I removed the DS record; keep the zone serving through the <?= CloudflareService::DS_SETTLE_HOURS ?>-hour DNS cache wait</label>
                                <?php elseif (in_array($dsStatus, ['active', 'pending', 'pending-disabled'], true) && (int)($zone['dnssec_ds_removed']??0)!==1): ?>
                                    <p class="cf-muted">Cloudflare has not returned the DS details yet. To protect your domain, DNSSEC cannot be turned off until those values are available.</p>
                                <?php endif; ?>
                                <?php $dsMissing=in_array($dsStatus,['active','pending','pending-disabled'],true) && !is_array($ds) && (int)($zone['dnssec_ds_removed']??0)!==1; ?>
                                <button class="cv-btn cv-btn--secondary" type="submit"<?= $dsMissing ? ' disabled' : '' ?>>Turn off DNSSEC</button>
                            </form>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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

        <?php if ($origin !== null && !empty($origin['supported'])): ?>
            <div class="cv-card cf-card">
                <h2 class="cv-card__title">Origin certificate <?= !empty($origin['certId']) ? '<span class="cv-badge cv-badge--success">Installed</span>' : '' ?></h2>
                <p class="cf-muted">A free, 15-year Cloudflare certificate installed on your cPanel hosting. It is trusted by Cloudflare only, so keep your website records proxied through Cloudflare.</p>
                <?php if (!empty($origin['expires'])): ?><p class="cf-muted">Valid until <?= e(date('j M Y', strtotime((string) $origin['expires']) ?: time())) ?>.</p><?php endif; ?>
                <?php if ($manageable): ?>
                    <form method="post" action="<?= e($base . '/origin-certificate') ?>" class="cf-actions" data-cf-confirm="Install a Cloudflare Origin CA certificate on your hosting?">
                        <?= csrf_field() ?><label class="cf-check"><input type="checkbox" name="strict" value="1" checked> Switch to Full (strict)</label>
                        <button class="cv-btn" type="submit"><?= !empty($origin['certId']) ? 'Reinstall certificate' : 'Install origin certificate' ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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

    <?php elseif ($tab === 'speed'): ?>
        <?php $speedOptions = [
            'early_hints'=>['Early Hints','Hints browsers to start loading key files early.'],
            'http3'=>['HTTP/3 (QUIC)','Modern transport, especially helpful on mobile networks.'],
            '0rtt'=>['0-RTT resumption','Faster reconnects for returning visitors.'],
            'rocket_loader'=>['Rocket Loader','Loads JavaScript later; test your scripts after enabling.'],
            'always_online'=>['Always Online','Serves cached pages when your origin is unavailable.'],
            'ipv6'=>['IPv6 compatibility','Allows visitors on IPv6-only networks to reach your site.'],
            'websockets'=>['WebSockets','Allows realtime apps and chat to connect through Cloudflare.'],
            'opportunistic_encryption'=>['Opportunistic encryption','Enables encryption for compatible browsers.'],
            'tls_1_3'=>['TLS 1.3','Fast, modern transport security.'],
            'email_obfuscation'=>['Email address obfuscation','Hides page email addresses from basic spam bots.'],
            'hotlink_protection'=>['Hotlink protection','Stops other sites embedding your images.'],
        ]; ?>
        <div class="cv-card cf-card"><h2 class="cv-card__title">Speed &amp; network</h2>
            <?php foreach ($speedOptions as $key => [$label,$help]): if (!array_key_exists($key,$settings)) { continue; } ?>
                <div class="cf-row"><div><h3><?= e($label) ?></h3><p class="cf-muted"><?= e($help) ?></p></div><?= $toggle($key,$val($key),$manageable) ?></div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($tab === 'rules'): ?>
        <?php
        $presets=CloudflareRules::presets((string)$zone['name']);
        $kindLabels=['redirect'=>'Redirect rules','cache'=>'Cache rules','firewall'=>'Firewall rules'];
        ?>
        <?php if ($manageable): ?><div class="cv-card cf-card"><h2 class="cv-card__title">Quick rules</h2><p class="cf-muted">Add a common rule in one click. Rules can be removed below.</p><div class="cf-presets">
            <?php foreach ($presets as $key=>$preset): ?><form method="post" action="<?= e($base.'/presets/'.$key) ?>"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit"><?= e($preset['label']) ?></button></form><?php endforeach; ?>
        </div></div><?php endif; ?>
        <?php foreach (CloudflareRules::KINDS as $kind=>$meta): $set=$rulesets[$kind]??['rules'=>[],'limit'=>$meta['limit'],'error'=>null]; ?>
            <div class="cv-card cf-card"><h2 class="cv-card__title"><?= e($kindLabels[$kind]) ?> <span class="cv-badge cv-badge--neutral"><?= count($set['rules']) ?> / <?= (int)$set['limit'] ?></span></h2>
                <?php if ($set['error']): ?><div class="cv-alert cv-alert--neutral"><?= e($set['error']) ?></div><?php else: ?>
                    <div class="cf-table-wrap"><table class="cv-table"><thead><tr><th>Rule</th><th>When</th><th>Then</th><th></th></tr></thead><tbody>
                    <?php foreach ($set['rules'] as $rule): ?><tr><td><?= e((string)($rule['description']??'')) ?></td><td><code class="cf-expr"><?= e((string)($rule['expression']??'')) ?></code></td><td><?= e(CloudflareRules::actionSummary($kind,$rule)) ?></td><td>
                        <?php if ($manageable && !empty($rule['id'])): ?><form method="post" action="<?= e($base.'/rulesets/'.$kind.'/'.(string)$rule['id'].'/delete') ?>" data-cf-confirm="Remove this rule?"><?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit">Remove</button></form><?php endif; ?>
                    </td></tr><?php endforeach; ?>
                    <?php if (!$set['rules']): ?><tr><td colspan="4" class="cf-muted">No rules yet.</td></tr><?php endif; ?>
                    </tbody></table></div>
                    <?php if ($manageable && count($set['rules'])<(int)$set['limit']): ?>
                        <details class="cf-details"><summary>Add a <?= e($kind) ?> rule</summary>
                            <form method="post" action="<?= e($base.'/rulesets/'.$kind) ?>" class="cf-form-grid">
                                <?= csrf_field() ?>
                                <div class="cv-field"><label class="cv-label">Name</label><input class="cv-input" name="description" maxlength="100" placeholder="Optional"></div>
                                <div class="cv-field"><label class="cv-label">When</label><select class="cv-input" name="match"><?php foreach(CloudflareRules::KIND_MATCHES[$kind] as $m): ?><option value="<?= e($m) ?>"><?= e(CloudflareRules::MATCHES[$m]) ?></option><?php endforeach; ?></select></div>
                                <div class="cv-field"><label class="cv-label">Value</label><input class="cv-input" name="value" placeholder="<?= $kind==='firewall'?'NG, 203.0.113.5, /wp-login.php':'/old-page, jpg, png' ?>"></div>
                                <?php if($kind==='redirect'): ?>
                                    <div class="cv-field"><label class="cv-label">Redirect to</label><input class="cv-input" name="target_url" type="url" required placeholder="https://example.com/new-page"></div>
                                    <div class="cv-field"><label class="cv-label">Status code</label><select class="cv-input" name="status_code"><option value="301">301 Permanent</option><option value="302">302 Temporary</option><option value="307">307 Temporary</option><option value="308">308 Permanent</option></select></div>
                                    <label class="cf-check"><input type="checkbox" name="keep_path" value="1"> Keep visitor path</label><label class="cf-check"><input type="checkbox" name="preserve_query" value="1" checked> Keep query string</label>
                                <?php elseif($kind==='cache'): ?>
                                    <div class="cv-field"><label class="cv-label">Then</label><select class="cv-input" name="cache_mode"><option value="cache">Cache it</option><option value="bypass">Never cache it</option></select></div>
                                    <div class="cv-field"><label class="cv-label">Edge TTL</label><select class="cv-input" name="edge_ttl"><?php foreach(CloudflareRules::EDGE_TTLS as $ttl=>$label): ?><option value="<?= (int)$ttl ?>"<?= $ttl===86400?' selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                                <?php else: ?>
                                    <div class="cv-field"><label class="cv-label">Then</label><select class="cv-input" name="action"><?php foreach(CloudflareRules::FIREWALL_ACTIONS as $a=>$label): ?><option value="<?= e($a) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                                <?php endif; ?>
                                <div class="cv-field"><button class="cv-btn" type="submit">Add rule</button></div>
                            </form><p class="cf-muted">Rule values are validated; quotes and backslashes aren't accepted.</p>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

    <?php elseif ($tab === 'email'): ?>
        <?php
        $emailData = is_array($emailRouting) ? $emailRouting : ['ok' => false, 'message' => 'Email Routing could not be checked safely.'];
        $verifiedDestinations = array_values(array_filter((array) ($emailData['destinations'] ?? []), static fn (array $destination): bool => ($destination['status'] ?? '') === 'verified'));
        $emailCanManage = $emailManageable && !empty($emailData['can_manage']);
        $catchAll = (array) ($emailData['catch_all'] ?? []);
        ?>
        <div class="cv-card cf-card">
            <h2 class="cv-card__title">Email Routing</h2>
            <p class="cf-muted">Free forwarding for inbound email on this domain. It forwards to an existing, verified destination; it does not create mailboxes or send outgoing mail. This setup never changes nameservers.</p>
            <p><span class="cv-badge cv-badge--neutral">Cloudflare Free</span>
                <?php if (!empty($emailData['enabled']) && !empty($emailData['ready'])): ?> <span class="cv-badge cv-badge--success">Ready</span>
                <?php elseif (!empty($emailData['enabled'])): ?> <span class="cv-badge cv-badge--warning">DNS needs attention</span>
                <?php else: ?> <span class="cv-badge cv-badge--neutral">Not enabled</span><?php endif; ?>
            </p>
        </div>

        <?php if (empty($emailData['ok'])): ?>
            <div class="cv-alert cv-alert--error cf-flash">Email Routing could not be checked safely. No Email Routing or DNS changes were made. If this continues, ask support to verify the Cloudflare API-token permissions.</div>
        <?php else: ?>
            <?php if (empty($emailData['enabled'])): ?>
                <div class="cv-card cf-card">
                    <h2 class="cv-card__title">Review email DNS before enabling</h2>
                    <p>Cloudflare Email Routing takes over inbound mail for <strong><?= e((string) $zone['name']) ?></strong>. If you use another mail host or have existing mailboxes, do not enable it until you have migrated them and intentionally changed your mail DNS. WHMP fails closed around existing root MX, SPF and DKIM records; it does not overwrite them.</p>
                    <?php if (!empty($emailData['mx_records'])): ?>
                        <h3>Current root-domain MX records</h3>
                        <ul><?php foreach ((array) $emailData['mx_records'] as $record): ?><li><code><?= e((string) ($record['content'] ?? '')) ?></code> (priority <?= (int) ($record['priority'] ?? 0) ?>)</li><?php endforeach; ?></ul>
                    <?php else: ?>
                        <p class="cf-muted">No root-domain MX records were found.</p>
                    <?php endif; ?>
                    <?php if (!empty($emailData['spf_records'])): ?>
                        <h3>Current root-domain SPF records</h3>
                        <ul><?php foreach ((array) $emailData['spf_records'] as $record): ?><li><code><?= e((string) ($record['content'] ?? '')) ?></code></li><?php endforeach; ?></ul>
                        <div class="cv-alert cv-alert--error">An SPF record already exists. WHMP will not replace, merge, or duplicate it. Keep a single SPF record that preserves all existing senders and includes Cloudflare's requirement before enabling Email Routing.</div>
                    <?php else: ?>
                        <p class="cf-muted">No root-domain SPF record was found. Review your outbound-mail provider's SPF requirements before proceeding.</p>
                    <?php endif; ?>
                    <?php if (!empty($emailData['dkim_records'])): ?>
                        <h3>Existing DKIM records for this domain</h3>
                        <ul><?php foreach ((array) $emailData['dkim_records'] as $record): ?><li><code><?= e((string) ($record['name'] ?? '')) ?></code> (<?= e((string) ($record['type'] ?? '')) ?>): <code><?= e((string) ($record['content'] ?? '')) ?></code></li><?php endforeach; ?></ul>
                        <div class="cv-alert cv-alert--error">Existing DKIM records may be used by your outgoing-mail provider. WHMP will not alter them or proceed automatically; review the mail DNS migration with your provider first.</div>
                    <?php endif; ?>
                    <?php if (!empty($emailData['mx_conflicts'])): ?>
                        <div class="cv-alert cv-alert--error">Existing MX records point outside Cloudflare Email Routing. WHMP left them untouched. Migrate any mailboxes and change those records yourself before trying again.</div>
                    <?php elseif (!empty($emailData['mx_records'])): ?>
                        <div class="cv-alert cv-alert--error">Root-domain MX records are already present. Even if they appear to match Cloudflare, WHMP will not replace or duplicate them; review Email Routing's DNS state in Cloudflare first.</div>
                    <?php endif; ?>
                    <h3>DNS records Cloudflare says it requires</h3>
                    <?php if (!empty($emailData['required_records'])): ?>
                        <div class="cf-table-wrap"><table class="cv-table"><thead><tr><th>Type</th><th>Name</th><th>Content / target</th><th>Priority</th></tr></thead><tbody>
                        <?php foreach ((array) $emailData['required_records'] as $record): ?>
                            <tr><td><?= e((string) ($record['type'] ?? '')) ?></td><td><code><?= e((string) ($record['name'] ?? $zone['name'])) ?></code></td><td><code><?= e((string) ($record['content'] ?? '')) ?></code></td><td><?= isset($record['priority']) ? (int) $record['priority'] : '&mdash;' ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php else: ?>
                        <p class="cf-muted">Cloudflare did not return a DNS checklist, so setup is unavailable.</p>
                    <?php endif; ?>

                    <?php if (!empty($emailData['can_enable']) && $emailManageable): ?>
                        <form method="post" action="<?= e($base . '/email-routing/enable') ?>" class="cf-actions" data-cf-confirm="Email Routing takes over inbound mail. Proceed only if you have reviewed the MX, SPF and DKIM records above.">
                            <?= csrf_field() ?>
                            <label class="cf-check"><input type="checkbox" name="confirm" value="1" required> I confirm this domain has no existing mail provider or mailbox that must keep receiving mail, and I have reviewed the DNS checklist.</label>
                            <button class="cv-btn" type="submit">Enable free Email Routing</button>
                        </form>
                    <?php elseif (!empty($emailData['mx_conflicts'])): ?>
                        <p class="cf-muted">Setup is blocked until the existing MX records are intentionally migrated or removed. No records have been changed.</p>
                    <?php elseif (!empty($emailData['mx_records'])): ?>
                        <p class="cf-muted">Setup is blocked because root-domain MX records are already present. Review the current DNS configuration in Cloudflare first.</p>
                    <?php elseif (!empty($emailData['spf_records'])): ?>
                        <p class="cf-muted">Setup is blocked while an existing SPF record needs a safe manual merge. No records have been changed.</p>
                    <?php elseif (!empty($emailData['dkim_records'])): ?>
                        <p class="cf-muted">Setup is blocked while existing DKIM records require a safe manual mail-DNS review. No records have been changed.</p>
                    <?php elseif (empty($emailData['required_dns_complete'])): ?>
                        <p class="cf-muted">Cloudflare did not return a complete MX, SPF and DKIM checklist. Setup is unavailable until Cloudflare returns all required records.</p>
                    <?php elseif (!$emailManageable): ?>
                        <p class="cf-muted">Email Routing can be enabled only when this Cloudflare zone and hosting service are active and not paused or scheduled for removal.</p>
                    <?php else: ?>
                        <p class="cf-muted">Cloudflare's DNS state is not a clean, unconfigured state. WHMP will not overwrite it; check Email Routing in Cloudflare first.</p>
                    <?php endif; ?>
                </div>
            <?php elseif (empty($emailData['ready'])): ?>
                <div class="cv-alert cv-alert--error cf-flash">Cloudflare reports Email Routing is enabled but its DNS is not ready. WHMP has disabled forwarding controls to avoid changing a misconfigured setup. Review the DNS status in Cloudflare.</div>
            <?php else: ?>
                <?php if ((int) ($emailData['unmanaged_rule_count'] ?? 0) > 0): ?>
                    <div class="cv-alert cv-alert--neutral">There are <?= (int) $emailData['unmanaged_rule_count'] ?> routing rule(s) that were not created by this service. WHMP leaves them untouched and does not show their destinations.</div>
                <?php endif; ?>

                <div class="cv-card cf-card">
                    <h2 class="cv-card__title">Verified destination addresses</h2>
                    <p class="cf-muted">Cloudflare emails each new destination a verification link. Only destinations added by this exact client/reseller account are shown here.</p>
                    <?php if ($emailData['destinations'] === []): ?><p class="cf-muted">No destinations yet.</p><?php else: ?>
                        <div class="cf-table-wrap"><table class="cv-table"><thead><tr><th>Destination</th><th>Status</th></tr></thead><tbody>
                        <?php foreach ((array) $emailData['destinations'] as $destination): ?>
                            <tr><td><?= e((string) $destination['email']) ?></td><td><?= e(ucfirst((string) $destination['status'])) ?><?= !empty($destination['verified_at']) ? ' · ' . e((string) $destination['verified_at']) : '' ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                    <?php if ($emailCanManage): ?>
                        <form method="post" action="<?= e($base . '/email-routing/destinations') ?>" class="cf-inline" style="margin-top:1rem">
                            <?= csrf_field() ?>
                            <label class="cv-field"><span class="cv-label">Add a destination</span><input class="cv-input" type="email" name="email" maxlength="90" autocomplete="email" required placeholder="you@example.net"></label>
                            <button class="cv-btn" type="submit">Send verification email</button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="cv-card cf-card">
                    <h2 class="cv-card__title">Forwarding addresses</h2>
                    <p class="cf-muted">Each alias forwards to exactly one verified destination. Cloudflare Free allows up to 200 destination addresses and 200 rules per domain.</p>
                    <?php if ($emailData['routes'] === []): ?><p class="cf-muted">No forwarding rules yet.</p><?php else: ?>
                        <div class="cf-table-wrap"><table class="cv-table"><thead><tr><th>Address</th><th>Forwards to</th><th>Status</th><th></th></tr></thead><tbody>
                        <?php foreach ((array) $emailData['routes'] as $route): ?>
                            <tr><td><code><?= e((string) $route['address']) ?></code></td><td><?= e((string) $route['destination_email']) ?></td><td>
                                <?php if (!empty($route['missing'])): ?>Missing in Cloudflare
                                <?php elseif (!empty($route['changed'])): ?>Changed outside WHMP
                                <?php else: ?><?= !empty($route['enabled']) ? 'Active' : 'Paused' ?><?php endif; ?>
                            </td><td><div class="cf-actions">
                                <?php if ($emailCanManage && !empty($route['manageable'])): ?>
                                    <form method="post" action="<?= e($base . '/email-routing/routes/' . (int) $route['id'] . '/toggle') ?>">
                                        <?= csrf_field() ?><input type="hidden" name="enabled" value="<?= !empty($route['enabled']) ? '0' : '1' ?>">
                                        <button class="cv-btn cv-btn--secondary" type="submit"><?= !empty($route['enabled']) ? 'Pause' : 'Enable' ?></button>
                                    </form>
                                    <form method="post" action="<?= e($base . '/email-routing/routes/' . (int) $route['id'] . '/delete') ?>" data-cf-confirm="Remove this forwarding rule?">
                                        <?= csrf_field() ?><button class="cv-btn cv-btn--secondary" type="submit">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </div></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                    <?php if ($emailCanManage): ?>
                        <form method="post" action="<?= e($base . '/email-routing/routes') ?>" class="cf-form-grid" style="margin-top:1rem">
                            <?= csrf_field() ?>
                            <div class="cv-field"><label class="cv-label" for="cf-email-local-part">Alias</label><div class="cf-inline"><input class="cv-input" id="cf-email-local-part" name="local_part" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._+-]*[A-Za-z0-9]|[A-Za-z0-9]" required placeholder="sales"><span>@<?= e((string) $zone['name']) ?></span></div></div>
                            <div class="cv-field"><label class="cv-label" for="cf-email-destination">Verified destination</label><select class="cv-input" id="cf-email-destination" name="destination_id" required <?= $verifiedDestinations === [] ? 'disabled' : '' ?>><option value="">Choose a destination</option><?php foreach ($verifiedDestinations as $destination): ?><option value="<?= (int) $destination['id'] ?>"><?= e((string) $destination['email']) ?></option><?php endforeach; ?></select></div>
                            <div class="cv-field"><button class="cv-btn" type="submit" <?= $verifiedDestinations === [] ? 'disabled' : '' ?>>Add forwarding address</button></div>
                        </form>
                        <?php if ($verifiedDestinations === []): ?><p class="cf-muted">Add and verify a destination before creating forwarding addresses.</p><?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="cv-card cf-card">
                    <h2 class="cv-card__title">Catch-all forwarding</h2>
                    <p class="cf-muted">Optional and off by default. When enabled, every otherwise-unmatched address at <?= e((string) $zone['name']) ?> is forwarded to the selected destination.</p>
                    <?php if (!empty($catchAll['external'])): ?>
                        <div class="cv-alert cv-alert--neutral">A catch-all rule is configured outside this service. WHMP leaves it untouched and does not reveal its destination.</div>
                    <?php elseif (!empty($catchAll['changed'])): ?>
                        <div class="cv-alert cv-alert--error">The catch-all rule changed outside WHMP. It is read-only here until reviewed in Cloudflare.</div>
                    <?php else: ?>
                        <p>Status: <strong><?= !empty($catchAll['enabled']) ? 'On' : 'Off' ?></strong><?= !empty($catchAll['managed']) && !empty($catchAll['destination_email']) ? ' · forwards to ' . e((string) $catchAll['destination_email']) : '' ?></p>
                        <?php if ($emailCanManage && !empty($catchAll['manageable'])): ?>
                            <form method="post" action="<?= e($base . '/email-routing/catch-all') ?>" class="cf-form-grid">
                                <?= csrf_field() ?><input type="hidden" name="enabled" value="1">
                                <div class="cv-field"><label class="cv-label" for="cf-catch-destination">Verified destination</label><select class="cv-input" id="cf-catch-destination" name="destination_id" required <?= $verifiedDestinations === [] ? 'disabled' : '' ?>><option value="">Choose a destination</option><?php foreach ($verifiedDestinations as $destination): ?><option value="<?= (int) $destination['id'] ?>"<?= (int) ($catchAll['destination_id'] ?? 0) === (int) $destination['id'] ? ' selected' : '' ?>><?= e((string) $destination['email']) ?></option><?php endforeach; ?></select></div>
                                <div class="cv-field"><button class="cv-btn" type="submit" <?= $verifiedDestinations === [] ? 'disabled' : '' ?>><?= !empty($catchAll['enabled']) ? 'Save catch-all' : 'Enable catch-all' ?></button></div>
                            </form>
                            <?php if (!empty($catchAll['enabled']) && !empty($catchAll['managed'])): ?>
                                <form method="post" action="<?= e($base . '/email-routing/catch-all') ?>" class="cf-actions" data-cf-confirm="Disabling catch-all stops forwarding for every address without a specific rule.">
                                    <?= csrf_field() ?><input type="hidden" name="enabled" value="0">
                                    <label class="cf-check"><input type="checkbox" name="confirm" value="1" required> I understand unmatched addresses will no longer be forwarded.</label>
                                    <button class="cv-btn cv-btn--secondary" type="submit">Turn off catch-all</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($emailCanManage && !empty($emailData['can_disable'])): ?>
                    <div class="cv-card cf-card">
                        <h2 class="cv-card__title">Turn off Email Routing</h2>
                        <p>Disabling stops forwarding and asks Cloudflare to remove the DNS records it manages for Email Routing. Preserve any SPF requirements for your outgoing mail and configure a replacement mail provider first. Cloudflare nameservers will not be changed.</p>
                        <form method="post" action="<?= e($base . '/email-routing/disable') ?>" class="cf-actions" data-cf-confirm="Disabling Email Routing can stop inbound delivery. Confirm that replacement mail DNS and outbound SPF are ready.">
                            <?= csrf_field() ?>
                            <label class="cf-check"><input type="checkbox" name="confirm" value="1" required> I understand this turns off forwarding and removes Cloudflare-managed Email Routing DNS records.</label>
                            <button class="cv-btn cv-btn--secondary" type="submit">Disable Email Routing</button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

    <?php elseif ($tab === 'analytics'): ?>
        <div class="cf-actions" style="margin:0 0 1rem"><a class="cv-btn<?= $range===7?'':' cv-btn--secondary' ?>" href="<?= e($base.'?tab=analytics&range=7') ?>">7 days</a><a class="cv-btn<?= $range===30?'':' cv-btn--secondary' ?>" href="<?= e($base.'?tab=analytics&range=30') ?>">30 days</a></div>
        <?php if ($analytics!==null): $t=$analytics['totals']; $pct=$t['requests']?round($t['cached']/$t['requests']*100):0; $saved=$t['bytes']?round($t['cachedBytes']/$t['bytes']*100):0; ?>
            <div class="cf-stats">
                <div class="cf-stat"><span>Requests</span><strong><?= number_format((int)$t['requests']) ?></strong></div>
                <div class="cf-stat"><span>Served from cache</span><strong><?= (int)$pct ?>%</strong></div>
                <div class="cf-stat"><span>Bandwidth</span><strong><?= number_format((int)round($t['bytes']/1048576),1) ?> MB</strong></div>
                <div class="cf-stat"><span>Bandwidth saved</span><strong><?= (int)$saved ?>%</strong></div>
                <div class="cf-stat"><span>Threats stopped</span><strong><?= number_format((int)$t['threats']) ?></strong></div>
                <div class="cf-stat"><span>Page views</span><strong><?= number_format((int)$t['pageViews']) ?></strong></div>
            </div>
            <div class="cv-card cf-card"><h2 class="cv-card__title">Top countries</h2>
                <?php if (!$analytics['countries']): ?><p class="cf-muted">Cloudflare has no country data yet.</p><?php else: $top=max(1,...array_values($analytics['countries'])); ?>
                    <ul class="cf-bars"><?php foreach($analytics['countries'] as $country=>$requests): ?><li><span><?= e((string)$country) ?></span><span class="cf-bar"><b style="width:<?= max(2,round($requests/$top*100)) ?>%"></b></span><span class="cf-muted"><?= number_format((int)$requests) ?></span></li><?php endforeach; ?></ul>
                <?php endif; ?>
                <p class="cf-muted">Daily analytics can take several hours to update.</p>
            </div>
        <?php endif; ?>

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
