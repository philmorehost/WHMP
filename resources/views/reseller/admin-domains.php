<?php
/**
 * Custom-domain requests: the queue and the decision.
 *
 * @var array<int, array<string, mixed>> $pending
 * @var array<int, array<string, mixed>> $decided
 * @var array<int, array<string, mixed>> $outstanding
 * @var array<int, array{label: string, ok: bool|null, detail: string}> $panelCheck
 * @var array<int, array<string, mixed>> $servers
 * @var array{mode: string, server_id: string, account: string, docroot: string} $settings
 * @var string|null $notice
 * @var string|null $error
 */

$statusLabels = [
    'none' => 'Not requested',
    'pending' => 'Awaiting review',
    'approved' => 'Approved',
    'rejected' => 'Refused',
];

$statusBadge = static function (string $status): string {
    return match ($status) {
        'approved' => 'cv-badge--success',
        'rejected' => 'cv-badge--error',
        'pending' => 'cv-badge--warning',
        default => '',
    };
};
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Store domains</h1>
    <p style="color:var(--cv-text-secondary);">
        A reseller asks for a custom domain; you approve or refuse it here. Approving records your decision and
        — only if automatic provisioning is switched on below — asks the hosting panel to add the domain.
    </p>
    <p style="color:var(--cv-text-secondary);">
        <strong>Two separate gates.</strong> <em>Approval</em> is a decision about intent and is recorded on this page.
        <em>Control of the domain</em> is a fact, proved by DNS, and is what actually allows the store to be served
        at that address. A refused request has its DNS proof cleared as well, so refusing always stops the domain
        being served.
    </p>
    <p style="color:var(--cv-text-secondary);">
        The server is kept in step with the decision: approving a new name <strong>takes the old one off</strong>
        the hosting panel first, and refusing or releasing one removes it too. A hostname left behind answers for a
        domain no store claims, which shows <em>this</em> shop at <em>these</em> prices — so anything the automation
        could not finish is listed below with a button to retry it.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Awaiting your decision</h2>

    <?php if ($pending === []): ?>
        <p style="color:var(--cv-text-secondary);">
            Nothing is waiting. A request appears here as soon as a reseller submits a domain.
        </p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
            <tr><th>Store</th><th>Domain</th><th>Requested</th><th>Decide</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pending as $row): ?>
                <?php
                $clientId = (int) $row['client_id'];
                $owner = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
                ?>
                <tr>
                    <td>
                        <a href="/admin/resellers/<?= $clientId ?>/store"><?= e((string) $row['slug']) ?></a><br>
                        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e($owner !== '' ? $owner : (string) $row['email']) ?></span>
                    </td>
                    <td><strong><?= e((string) $row['custom_domain']) ?></strong></td>
                    <td><?= e((string) ($row['domain_requested_at'] ?? '—')) ?></td>
                    <td>
                        <form method="post" action="/admin/resellers/domains/<?= (int) $row['id'] ?>/approve"
                              style="display:flex;gap:var(--cv-space-2);align-items:flex-end;flex-wrap:wrap;">
                            <?= csrf_field() ?>
                            <label style="display:flex;flex-direction:column;gap:4px;">
                                <span style="font-size:var(--cv-text-sm);">Note <em>(optional)</em></span>
                                <input class="cv-input" name="note" maxlength="255" placeholder="Checked with the customer">
                            </label>
                            <button class="cv-btn" type="submit">Approve</button>
                        </form>

                        <form method="post" action="/admin/resellers/domains/<?= (int) $row['id'] ?>/reject"
                              style="display:flex;gap:var(--cv-space-2);align-items:flex-end;margin-top:var(--cv-space-2);flex-wrap:wrap;">
                            <?= csrf_field() ?>
                            <label style="display:flex;flex-direction:column;gap:4px;">
                                <span style="font-size:var(--cv-text-sm);">Reason <em>(required — the reseller sees it)</em></span>
                                <input class="cv-input" name="reason" required maxlength="255" placeholder="Domain does not belong to this customer">
                            </label>
                            <button class="cv-btn" type="submit">Refuse</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Automatic provisioning</h2>
    <p style="color:var(--cv-text-secondary);">
        Off by default. With it off, approving only records your decision and you add the domain on the server
        yourself (see <code>docs/RESELLER_DOMAIN_SETUP.md</code>). With it on, approving also calls the hosting
        panel to add the domain — using this platform's own WHM credentials, so check the three values below.
    </p>

    <form method="post" action="/admin/resellers/domains/settings">
        <?= csrf_field() ?>
        <table class="cv-table">
            <tbody>
            <tr>
                <th style="width:22%;">Mode</th>
                <td>
                    <select name="mode" class="cv-input">
                        <option value="off"<?= $settings['mode'] === 'off' ? ' selected' : '' ?>>Off — I add domains on the server</option>
                        <option value="cpanel"<?= $settings['mode'] === 'cpanel' ? ' selected' : '' ?>>On — add them via cPanel/WHM automatically</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th>WHM server</th>
                <td>
                    <select name="server_id" class="cv-input">
                        <option value="">— choose —</option>
                        <?php foreach ($servers as $server): ?>
                            <option value="<?= (int) $server['id'] ?>"<?= (string) $server['id'] === $settings['server_id'] ? ' selected' : '' ?>>
                                <?= e((string) $server['hostname']) ?><?= !empty($server['name']) ? ' — ' . e((string) $server['name']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                        The server row whose API token belongs to <strong>this platform's own</strong> account.
                    </span>
                </td>
            </tr>
            <tr>
                <th>cPanel account</th>
                <td>
                    <input class="cv-input" name="account" maxlength="64" value="<?= e($settings['account']) ?>" placeholder="clientmore">
                    <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                        The cPanel user the domain is added to — normally this platform's own.
                    </span>
                </td>
            </tr>
            <tr>
                <th>Document root</th>
                <td>
                    <input class="cv-input" name="docroot" maxlength="191" value="<?= e($settings['docroot']) ?>" placeholder="public_html/whm/public">
                    <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                        The folder that already serves this platform, relative to the account's home. The reseller's
                        domain is pointed at the <strong>same</strong> folder, so the store is served by this same
                        application rather than a copy of it. Wrong or empty = the domain is parked on an empty folder.
                    </span>
                </td>
            </tr>
            </tbody>
        </table>
        <p style="margin:var(--cv-space-3) 0 0;">
            <button class="cv-btn" type="submit">Save provisioning settings</button>
        </p>
    </form>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Is the hosting panel actually working?</h2>
    <p style="color:var(--cv-text-secondary);">
        This asks the panel what it can do and shows you its own words. Run it first whenever an approval
        reports a problem — the answer is usually the panel naming a module file it could not load, or refusing
        one of the API versions. It reports the raw reply rather than a summary, because the detail is the answer.
    </p>
    <form method="post" action="/admin/resellers/domains/diagnose"><?= csrf_field() ?>
        <button class="cv-btn" type="submit">Test the hosting panel</button>
    </form>

    <?php if ($panelCheck !== []): ?>
        <table class="cv-table" style="margin-top:var(--cv-space-3);">
            <thead>
            <tr><th>Check</th><th>Result</th><th>What the panel said</th></tr>
            </thead>
            <tbody>
            <?php foreach ($panelCheck as $row): ?>
                <tr>
                    <td><?= e((string) $row['label']) ?></td>
                    <td>
                        <?php if ($row['ok'] === true): ?>
                            <span class="cv-badge cv-badge--success">OK</span>
                        <?php elseif ($row['ok'] === false): ?>
                            <span class="cv-badge cv-badge--error">Failed</span>
                        <?php else: ?>
                            <span class="cv-badge">Unknown</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:var(--cv-text-sm);word-break:break-word;"><?= e((string) $row['detail']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);margin-top:var(--cv-space-2);">
            A failed <em>Addon domains readable</em> check is why an approval cannot create a domain. If it names a
            missing <code>Cpanel/API/AddonDomain.pm</code>, the panel does not have the modern AddonDomain module;
            the application already retries the operation over the older API 2, so if BOTH are refused the account
            has to be handled by hand.
        </p>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Still on the server, but no store should use it</h2>

    <?php if ($outstanding === []): ?>
        <p style="color:var(--cv-text-secondary);">
            Nothing is outstanding — every hostname on the hosting panel belongs to a store that is approved for it.
        </p>
    <?php else: ?>
        <p style="color:var(--cv-text-secondary);">
            These hostnames are on the hosting panel but no store is approved to use them. Until they come off they
            answer with this platform's own shop at this platform's own prices. Removal is attempted automatically;
            this list is what is left when the panel refused, could not be reached, or automatic provisioning was off.
        </p>
        <table class="cv-table">
            <thead>
            <tr><th>Store</th><th>On the server</th><th>Store uses</th><th>Why it is still there</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($outstanding as $row): ?>
                <tr>
                    <td><a href="/admin/resellers/<?= (int) $row['client_id'] ?>/store"><?= e((string) $row['slug']) ?></a></td>
                    <td><strong><?= e((string) $row['domain_provisioned_host']) ?></strong><br>
                        <span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">since <?= e((string) ($row['domain_provisioned_at'] ?? '—')) ?></span>
                    </td>
                    <td>
                        <?php $claim = trim((string) ($row['custom_domain'] ?? '')); ?>
                        <?php if ($claim === ''): ?>
                            <span style="color:var(--cv-text-secondary);">no domain claimed</span>
                        <?php else: ?>
                            <?= e($claim) ?>
                            <span class="cv-badge <?= $statusBadge((string) ($row['domain_status'] ?? 'none')) ?>"><?= e($statusLabels[(string) ($row['domain_status'] ?? 'none')] ?? '') ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) ($row['domain_provision_error'] ?? 'not recorded')) ?></td>
                    <td>
                        <?php if ((string) ($row['domain_status'] ?? 'none') === 'approved'): ?>
                            <form method="post" action="/admin/resellers/domains/<?= (int) $row['id'] ?>/provision" style="margin-bottom:var(--cv-space-1);"><?= csrf_field() ?>
                                <button class="cv-btn" type="submit">Create on server</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="/admin/resellers/domains/<?= (int) $row['id'] ?>/unprovision"><?= csrf_field() ?>
                            <button class="cv-btn" type="submit">Remove from server</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Recent decisions</h2>

    <?php if ($decided === []): ?>
        <p style="color:var(--cv-text-secondary);">No decisions yet.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
            <tr><th>Store</th><th>Domain</th><th>Decision</th><th>Reviewed</th><th>On the server</th></tr>
            </thead>
            <tbody>
            <?php foreach ($decided as $row): ?>
                <?php $status = (string) ($row['domain_status'] ?? 'none'); ?>
                <tr>
                    <td><a href="/admin/resellers/<?= (int) $row['client_id'] ?>/store"><?= e((string) $row['slug']) ?></a></td>
                    <td><?= e((string) $row['custom_domain']) ?></td>
                    <td>
                        <span class="cv-badge <?= $statusBadge($status) ?>"><?= e($statusLabels[$status] ?? $status) ?></span>
                        <?php if (!empty($row['domain_review_note'])): ?>
                            <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) $row['domain_review_note']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['domain_reviewed_at'] ?? '—')) ?></td>
                    <td>
                        <?php $onServer = trim((string) ($row['domain_provisioned_host'] ?? '')); ?>
                        <?php $onServerMatches = $onServer !== '' && $onServer === trim((string) $row['custom_domain']); ?>
                        <?php if ($onServer !== ''): ?>
                            <span class="cv-badge <?= $onServerMatches ? 'cv-badge--success' : 'cv-badge--warning' ?>">
                                <?= $onServerMatches ? 'Added' : 'Old name still there' ?>
                            </span>
                            <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e($onServer) ?> — <?= e((string) ($row['domain_provisioned_at'] ?? '')) ?></span>
                        <?php elseif (!empty($row['domain_provision_error'])): ?>
                            <span class="cv-badge cv-badge--error">Panel refused</span>
                            <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) $row['domain_provision_error']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--cv-text-secondary);">—</span>
                        <?php endif; ?>

                        <?php if ($status === 'approved' && !$onServerMatches): ?>
                            <form method="post" action="/admin/resellers/domains/<?= (int) $row['id'] ?>/provision" style="margin-top:var(--cv-space-1);"><?= csrf_field() ?>
                                <button class="cv-btn" type="submit">Create on server</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
