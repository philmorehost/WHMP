<?php
/**
 * The last look before a client is moved: who, from where, to where, and exactly what
 * goes with them — and what deliberately stays behind.
 *
 * @var array<string, mixed> $preview ClientMigrationService::preview() (or an error-only array)
 * @var array<string, mixed>|null $client
 * @var string $clientInput what the admin typed, for the retry form
 * @var array<string, mixed>|null $requestRow
 * @var string $target
 * @var int|null $toStoreId
 * @var array<int, array{value: string, label: string}> $destinations
 * @var bool $canDecide
 */

$destinationValue = $target === 'store' && $toStoreId !== null ? (string) $toStoreId : 'platform';
$counts = $preview['counts'] ?? [];
$muted = 'color:var(--cv-text-secondary);';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Review client move</h1>
    <p><a href="/admin/resellers/migrations">&larr; All client migrations</a></p>

    <?php if ($requestRow !== null): ?>
        <p style="<?= $muted ?>">
            Request #<?= (int) $requestRow['id'] ?>, asked by
            <?= e(['client' => 'the client', 'reseller' => 'a reseller', 'admin' => 'staff'][(string) $requestRow['requested_by']] ?? 'someone') ?>
            on <?= e((string) $requestRow['created_at']) ?>
            <?php if (!empty($requestRow['ticket_id'])): ?>
                — <a href="/admin/tickets/<?= (int) $requestRow['ticket_id'] ?>">ticket #<?= (int) $requestRow['ticket_id'] ?></a>
            <?php endif; ?>.
            <?php if ((string) $requestRow['status'] !== 'pending'): ?>
                <strong>This request is already <?= e((string) $requestRow['status']) ?>.</strong>
            <?php endif; ?>
        </p>
        <?php if (trim((string) ($requestRow['reason'] ?? '')) !== ''): ?>
            <blockquote style="margin:0 0 var(--cv-space-3); padding:var(--cv-space-3); border-left:3px solid var(--cv-border-default); white-space:pre-wrap;"><?= e((string) $requestRow['reason']) ?></blockquote>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!($preview['ok'] ?? false)): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) ($preview['error'] ?? 'This move cannot be made.')) ?></div>

    <?php if ($requestRow === null): ?>
        <div class="cv-card">
            <form method="get" action="/admin/resellers/migrations/review" style="display:flex; flex-wrap:wrap; gap:var(--cv-space-3); align-items:flex-end;">
                <label style="display:flex;flex-direction:column;gap:4px; min-width:16rem;">
                    Client ID or email address
                    <input class="cv-input" type="text" name="client" required value="<?= e($client !== null ? (string) $client['id'] : (string) ($clientInput ?? '')) ?>">
                </label>
                <label style="display:flex;flex-direction:column;gap:4px; min-width:16rem;">
                    Move to
                    <select class="cv-input" name="to">
                        <?php foreach ($destinations as $option): ?>
                            <option value="<?= e($option['value']) ?>" <?= $option['value'] === $destinationValue ? 'selected' : '' ?>><?= e($option['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="cv-btn">Review again</button>
            </form>
        </div>
    <?php endif; ?>
<?php else: ?>
    <?php $c = $preview['client']; ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">
            <a href="/admin/clients/<?= (int) $c['id'] ?>"><?= e(trim((string) $c['first_name'] . ' ' . (string) $c['last_name'])) ?></a>
            <span style="font-weight:400; <?= $muted ?>">&lt;<?= e((string) $c['email']) ?>&gt;</span>
        </h2>
        <div style="display:flex; flex-wrap:wrap; gap:var(--cv-space-5); align-items:center; margin:var(--cv-space-3) 0;">
            <div>
                <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; <?= $muted ?>">From</div>
                <div style="font-size:var(--cv-text-lg); font-weight:700;"><?= e((string) $preview['fromLabel']) ?></div>
            </div>
            <div style="font-size:var(--cv-text-2xl);" aria-hidden="true">→</div>
            <div>
                <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; <?= $muted ?>">To</div>
                <div style="font-size:var(--cv-text-lg); font-weight:700;"><?= e((string) $preview['toLabel']) ?></div>
            </div>
        </div>

        <h3 style="margin-top:var(--cv-space-4);">Moves with the client</h3>
        <ul>
            <li><strong><?= (int) ($counts['services'] ?? 0) ?></strong> services</li>
            <li><strong><?= (int) ($counts['domains'] ?? 0) ?></strong> domains</li>
            <li><strong><?= (int) ($counts['invoices'] ?? 0) ?></strong> invoices (<?= (int) ($counts['unpaid_invoices'] ?? 0) ?> unpaid — the client pays them on the new provider's site)</li>
            <li><strong><?= (int) ($counts['tickets'] ?? 0) ?></strong> support tickets — the full history moves to the new provider's desk</li>
            <li>Sign-in: from now on the client can only sign in on the new provider's site, and every email they receive is written as the new provider.</li>
        </ul>

        <h3>Stays where it was earned</h3>
        <ul style="<?= $muted ?>">
            <li><strong><?= (int) ($counts['orders'] ?? 0) ?></strong> past orders stay credited to the provider that took them, together with that store's ledger, cost bills, payouts and statements. Rewriting them would move money one reseller already earned to another. New orders belong to the new provider.</li>
        </ul>
    </div>

    <?php if ($canDecide && ($requestRow === null || (string) $requestRow['status'] === 'pending')): ?>
        <div class="cv-card">
            <form method="post" action="/admin/resellers/migrations/execute" style="display:flex; flex-direction:column; gap:var(--cv-space-3); max-width:40rem;"
                  onsubmit="return confirm('Move this client and everything listed to the new provider?');">
                <?= csrf_field() ?>
                <input type="hidden" name="client_id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="to" value="<?= e($destinationValue) ?>">
                <?php if ($requestRow !== null): ?>
                    <input type="hidden" name="request_id" value="<?= (int) $requestRow['id'] ?>">
                <?php endif; ?>
                <label style="display:flex;flex-direction:column;gap:4px;">
                    Internal note (optional — kept on the migration record)
                    <textarea class="cv-input" name="note" rows="2"></textarea>
                </label>
                <div>
                    <button type="submit" class="cv-btn">Move client</button>
                </div>
                <p style="<?= $muted ?> margin:0;">
                    The client is told by email (or on their request ticket) where to sign in from now on.
                    The old and new store owners are told too.
                </p>
            </form>
        </div>
    <?php elseif (!$canDecide): ?>
        <div class="cv-alert cv-alert--warning">Only a super admin can move a client.</div>
    <?php endif; ?>
<?php endif; ?>
