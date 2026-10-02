<?php
/**
 * Custom-domain requests: the queue and the decision.
 *
 * @var array<int, array<string, mixed>> $pending
 * @var array<int, array<string, mixed>> $decided
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
                        <?php if (!empty($row['domain_provisioned_at'])): ?>
                            <span class="cv-badge cv-badge--success">Added</span>
                            <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) $row['domain_provisioned_at']) ?></span>
                        <?php elseif (!empty($row['domain_provision_error'])): ?>
                            <span class="cv-badge cv-badge--error">Panel refused</span>
                            <br><span style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);"><?= e((string) $row['domain_provision_error']) ?></span>
                        <?php else: ?>
                            <span style="color:var(--cv-text-secondary);">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
