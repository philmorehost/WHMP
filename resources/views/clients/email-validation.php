<?php
/** @var array<int, array<string, mixed>> $results */
/** @var array{total: int, invalid: int, lastScanAt: ?string} $summary */
/** @var string|null $scanned */
/** @var bool $dnsDown */
/** @var string|null $notice */
/** @var array<string, mixed>|null $blocker Invalid Email Blocker state (null = unavailable) */
$dnsDown ??= false;
$notice ??= null;
$blocker ??= null;
$blocking = is_array($blocker) && !empty($blocker['active']) && !empty($blocker['block']);
$allowList = is_array($blocker) ? (array) ($blocker['allow'] ?? []) : [];
$notices = [
    'blocking_on' => 'Blocking is ON — emails to addresses marked Invalid are no longer sent.',
    'blocking_off' => 'Blocking is OFF — every address receives email again.',
    'allowed' => 'Done — that address will always receive email.',
    'disallowed' => 'Done — that address is blocked again while it is marked Invalid.',
];
?>
<style>
    .ev-blocker { display: flex; flex-direction: column; gap: var(--cv-space-3); }
    .ev-blocker__head { display: flex; justify-content: space-between; align-items: flex-start; gap: var(--cv-space-4); flex-wrap: wrap; }
    .ev-blocker__head h2 { margin: 0 0 4px; font-size: var(--cv-text-md); }
    .ev-blocker__head p { margin: 0; color: var(--cv-text-secondary); font-size: var(--cv-text-sm); max-width: 62ch; }
    .ev-switch { display: inline-flex; align-items: center; gap: 10px; border: 0; background: none; cursor: pointer; padding: 0; font: inherit; color: var(--cv-text-primary); font-weight: 700; }
    .ev-switch__track { width: 46px; height: 26px; border-radius: 999px; background: rgba(148, 163, 184, .45); position: relative; transition: background .15s; flex: none; }
    .ev-switch__track::after { content: ""; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .15s; }
    .ev-switch.is-on .ev-switch__track { background: #22c55e; }
    .ev-switch.is-on .ev-switch__track::after { transform: translateX(20px); }
    .ev-switch:focus-visible .ev-switch__track { outline: 2px solid var(--cv-color-brand-500, #2563eb); outline-offset: 2px; }
    .ev-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(160px, 100%), 1fr)); gap: var(--cv-space-3); }
    .ev-stat { border: 1px solid var(--cv-border-default); border-radius: 10px; padding: 10px 14px; }
    .ev-stat strong { display: block; font-size: 1.4rem; line-height: 1.2; }
    .ev-stat span { color: var(--cv-text-secondary); font-size: var(--cv-text-xs); }
    .ev-meta { margin: 0; color: var(--cv-text-secondary); font-size: var(--cv-text-xs); }
    .ev-row-action { margin: 0; }
    .ev-row-action button { font-size: var(--cv-text-xs); padding: 3px 10px; }
    .ev-table-wrap { overflow-x: auto; }
</style>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Email Validation</h1>
    <p><a href="/admin/clients">&larr; Back to clients</a></p>
</div>

<?php if ($dnsDown): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-3);">
        The scan was stopped because this server could not look up any domains (DNS is not responding). Nothing was changed —
        no address was marked invalid. Please try again in a few minutes.
    </div>
<?php elseif ($scanned !== null): ?>
    <?php [$invalidCount, $totalCount] = array_map('intval', explode('-', $scanned) + [0, 0]); ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);">
        Scanned <?= $totalCount ?> client email(s) — found <?= $invalidCount ?> that look invalid.
        <?php if ($blocking && $invalidCount > 0): ?>They no longer receive email while blocking is ON.<?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($notice !== null && isset($notices[$notice])): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);"><?= e($notices[$notice]) ?></div>
<?php endif; ?>

<?php if (is_array($blocker)): ?>
    <div class="cv-card ev-blocker" style="margin-bottom:var(--cv-space-4);" id="blocker">
        <div class="ev-blocker__head">
            <div>
                <h2>🚫 Stop sending to invalid addresses</h2>
                <p>
                    When ON, every email type — invoices, reminders, tickets, service emails, campaigns — skips any address
                    marked <strong>Invalid</strong> below. Skipped emails are still recorded in the email log and still appear
                    in the client's in-app notifications.
                </p>
            </div>
            <?php if (!empty($blocker['can_toggle'])): ?>
                <form method="post" action="/admin/email-validation/blocking" class="ev-row-action"
                      data-confirm="<?= $blocking ? 'Turn blocking OFF? Invalid addresses will receive email again.' : 'Turn blocking ON? Addresses marked Invalid will stop receiving email.' ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="block" value="<?= $blocking ? '0' : '1' ?>">
                    <button type="submit" class="ev-switch<?= $blocking ? ' is-on' : '' ?>" role="switch" aria-checked="<?= $blocking ? 'true' : 'false' ?>" aria-label="Stop sending to invalid addresses">
                        <span class="ev-switch__track" aria-hidden="true"></span><?= $blocking ? 'ON' : 'OFF' ?>
                    </button>
                </form>
            <?php else: ?>
                <span class="cv-badge <?= $blocking ? 'cv-badge--success' : 'cv-badge--neutral' ?>"><?= $blocking ? 'ON' : 'OFF' ?></span>
            <?php endif; ?>
        </div>
        <div class="ev-stats">
            <div class="ev-stat"><strong><?= (int) ($blocker['stats']['blocked'] ?? 0) ?></strong><span><?= $blocking ? 'addresses blocked' : 'addresses would be blocked' ?></span></div>
            <div class="ev-stat"><strong><?= (int) ($blocker['stats']['skipped30'] ?? 0) ?></strong><span>emails skipped (30 days)</span></div>
            <div class="ev-stat"><strong><?= (int) ($blocker['stats']['allowed'] ?? 0) ?></strong><span>always-send exceptions</span></div>
        </div>
        <p class="ev-meta">
            Password resets and sign-up / PIN codes are <?= !empty($blocker['allow_security']) ? '<strong>still sent</strong>' : '<strong>also blocked</strong>' ?> to invalid addresses ·
            Automatic re-scan: <?= (int) $blocker['rescan_days'] > 0 ? 'every ' . (int) $blocker['rescan_days'] . ' day(s)' : 'off' ?>
            <?php if (!empty($blocker['can_toggle'])): ?> · <a href="/admin/addons/<?= e(\CodeVault\Mail\EmailSuppression::SLUG) ?>">More settings</a><?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:0;">
        Checks every active client's email for three things: whether it is a well-formed address, whether the domain has
        anywhere to receive mail at all (a DNS lookup only — nothing is emailed, so scanning never generates a bounce
        itself), and whether that exact address has actually bounced recently according to your own email history.
        Failures caused by your own mail server (connection or login problems) are not counted against a client.
    </p>
    <div style="display:flex;align-items:center;gap:var(--cv-space-4);flex-wrap:wrap;">
        <form method="post" action="/admin/email-validation/scan" data-confirm="Scan every active client's email now? This runs a DNS lookup per domain and may take a moment for a large client base.">
            <?= csrf_field() ?>
            <button class="cv-btn" type="submit">🔍 Scan All Client Emails</button>
        </form>
        <?php if ($summary['lastScanAt'] !== null): ?>
            <span style="font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                Last scan: <?= e((string) $summary['lastScanAt']) ?> &middot;
                <?= (int) $summary['invalid'] ?> of <?= (int) $summary['total'] ?> flagged
            </span>
        <?php endif; ?>
    </div>
</div>

<div class="cv-card ev-table-wrap">
    <table class="cv-table">
        <thead><tr><th>Client</th><th>Email</th><th>Status</th><th>Reason</th><th>Checked</th><?php if (is_array($blocker)): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($results as $row): ?>
            <?php
            $invalid = (int) $row['is_valid'] !== 1;
            $allowed = in_array(strtolower(trim((string) $row['email'])), $allowList, true);
            ?>
            <tr>
                <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                <td><?= e($row['email']) ?></td>
                <td style="white-space:nowrap;">
                    <?php if (!$invalid): ?>
                        <span class="cv-badge cv-badge--success">OK</span>
                    <?php elseif ($allowed): ?>
                        <span class="cv-badge cv-badge--warning" title="Marked invalid, but set to always receive email">Invalid · always sent</span>
                    <?php elseif ($blocking): ?>
                        <span class="cv-badge cv-badge--danger" title="No email is sent to this address">Invalid · blocked</span>
                    <?php else: ?>
                        <span class="cv-badge cv-badge--danger">Invalid</span>
                    <?php endif; ?>
                </td>
                <td><?= e((string) ($row['reason'] ?? '—')) ?></td>
                <td style="white-space:nowrap;"><?= e((string) $row['checked_at']) ?></td>
                <?php if (is_array($blocker)): ?>
                    <td style="white-space:nowrap;">
                        <?php if ($invalid || $allowed): ?>
                            <form method="post" action="/admin/email-validation/allow" class="ev-row-action">
                                <?= csrf_field() ?>
                                <input type="hidden" name="email" value="<?= e((string) $row['email']) ?>">
                                <input type="hidden" name="allow" value="<?= $allowed ? '0' : '1' ?>">
                                <button class="cv-btn cv-btn--secondary" type="submit"><?= $allowed ? 'Undo always send' : 'Always send' ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($results === []): ?>
            <tr><td colspan="<?= is_array($blocker) ? 6 : 5 ?>" style="color:var(--cv-text-secondary);">No scan has been run yet — click "Scan All Client Emails" above.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
