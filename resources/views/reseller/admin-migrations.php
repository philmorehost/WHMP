<?php
/**
 * Client migrations: requests waiting for a decision, a form to move any client
 * directly, and the history of every move and refusal.
 *
 * Every action goes through the review page first, so nothing on THIS page changes
 * anything except "Decline", which only closes a request.
 *
 * @var array<int, array<string, mixed>> $pending
 * @var array<int, array<string, mixed>> $decided
 * @var array<int, array{value: string, label: string}> $destinations
 * @var bool $canDecide
 * @var string|null $notice
 * @var string|null $error
 */

$requestedBy = [
    'client' => 'The client',
    'reseller' => 'A reseller',
    'admin' => 'Staff',
];

$statusBadge = [
    'completed' => 'cv-badge--success',
    'rejected' => 'cv-badge--danger',
    'cancelled' => 'cv-badge--neutral',
    'pending' => 'cv-badge--king',
];

$muted = 'font-size:var(--cv-text-xs); color:var(--cv-text-secondary);';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Client migrations</h1>
    <p><a href="/admin/resellers">&larr; Back to resellers</a> &middot;
        <a href="/admin/resellers/escalations">Store escalations</a></p>
    <p style="color:var(--cv-text-secondary);">
        Move a client between providers: from one reseller store to another, from a store to the main site,
        or from the main site to a store. Their <strong>services, domains, invoices and ticket history</strong>
        move with them, and from then on they sign in, order and get support on the new provider's site only.
        Earnings the old store already made stay on its account.
    </p>
    <?php if (!$canDecide): ?>
        <div class="cv-alert cv-alert--warning">You can see requests, but only a <strong>super admin</strong> can move a client or decline a request.</div>
    <?php endif; ?>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Requests waiting for a decision (<?= count($pending) ?>)</h2>
    <?php if ($pending === []): ?>
        <p style="color:var(--cv-text-secondary);">No requests are waiting. Clients ask from their support page; resellers ask from their reseller area.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
            <tr>
                <th>#</th>
                <th>Client</th>
                <th>From</th>
                <th>To</th>
                <th>Asked by</th>
                <th>Ticket</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($pending as $row): ?>
                <?php $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')); ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?><br><span style="<?= $muted ?>"><?= e((string) $row['created_at']) ?></span></td>
                    <td>
                        <?php if (!empty($row['client_id'])): ?>
                            <a href="/admin/clients/<?= (int) $row['client_id'] ?>"><?= e($name !== '' ? $name : (string) $row['client_email']) ?></a>
                        <?php else: ?>
                            <span class="cv-badge cv-badge--neutral">No account</span>
                        <?php endif; ?>
                        <br><span style="<?= $muted ?>"><?= e((string) $row['client_email']) ?></span>
                    </td>
                    <td><?= e((string) ($row['from_label'] ?? '—')) ?></td>
                    <td>
                        <?= e((string) ($row['target_label'] ?? '')) ?>
                        <?php if (!empty($row['target_input'])): ?>
                            <br><span style="<?= $muted ?>">typed: <?= e((string) $row['target_input']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($requestedBy[(string) $row['requested_by']] ?? (string) $row['requested_by']) ?>
                        <?php if (trim((string) ($row['reason'] ?? '')) !== ''): ?>
                            <div style="<?= $muted ?> white-space:pre-wrap; max-width:22rem; margin-top:4px;"><?= e((string) $row['reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($row['ticket_id'])): ?>
                            <a href="/admin/tickets/<?= (int) $row['ticket_id'] ?>">#<?= (int) $row['ticket_id'] ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;">
                        <a class="cv-btn" href="/admin/resellers/migrations/review?request=<?= (int) $row['id'] ?>">Review</a>
                        <?php if ($canDecide): ?>
                            <details style="margin-top:var(--cv-space-2);">
                                <summary style="cursor:pointer; <?= $muted ?>">Decline…</summary>
                                <form method="post" action="/admin/resellers/migrations/<?= (int) $row['id'] ?>/reject" style="display:flex; flex-direction:column; gap:6px; margin-top:6px;">
                                    <?= csrf_field() ?>
                                    <textarea class="cv-input" name="note" rows="2" placeholder="Reason (sent to whoever asked)"></textarea>
                                    <button type="submit" class="cv-btn cv-btn--danger">Decline request</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Move a client</h2>
    <p style="color:var(--cv-text-secondary);">No request needed — for example when a client asked by phone or email. You will see exactly what moves before anything changes.</p>
    <form method="get" action="/admin/resellers/migrations/review" style="display:flex; flex-wrap:wrap; gap:var(--cv-space-3); align-items:flex-end;">
        <label style="display:flex;flex-direction:column;gap:4px; min-width:16rem;">
            Client ID or email address
            <input class="cv-input" type="text" name="client" required placeholder="e.g. 1042 or jane@example.com">
        </label>
        <label style="display:flex;flex-direction:column;gap:4px; min-width:16rem;">
            Move to
            <select class="cv-input" name="to">
                <?php foreach ($destinations as $option): ?>
                    <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="cv-btn">Review move</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">History</h2>
    <?php if ($decided === []): ?>
        <p style="color:var(--cv-text-secondary);">No client has been moved yet.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
            <tr>
                <th>#</th>
                <th>Client</th>
                <th>From → To</th>
                <th>Outcome</th>
                <th>What moved</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($decided as $row): ?>
                <?php
                $summary = json_decode((string) ($row['summary'] ?? ''), true);
                $summary = is_array($summary) ? $summary : [];
                $status = (string) $row['status'];
                ?>
                <tr>
                    <td>#<?= (int) $row['id'] ?><br><span style="<?= $muted ?>"><?= e((string) ($row['decided_at'] ?? $row['updated_at'])) ?></span></td>
                    <td>
                        <?php if (!empty($row['client_id'])): ?>
                            <a href="/admin/clients/<?= (int) $row['client_id'] ?>"><?= e((string) $row['client_email']) ?></a>
                        <?php else: ?>
                            <?= e((string) $row['client_email']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['from_label'] ?? '—')) ?> → <?= e((string) ($row['target_label'] ?? '')) ?></td>
                    <td>
                        <span class="cv-badge <?= $statusBadge[$status] ?? 'cv-badge--neutral' ?>"><?= e(ucfirst($status)) ?></span>
                        <br><span style="<?= $muted ?>"><?= e($requestedBy[(string) $row['requested_by']] ?? '') ?> asked</span>
                        <?php if (trim((string) ($row['decision_note'] ?? '')) !== ''): ?>
                            <div style="<?= $muted ?> white-space:pre-wrap; max-width:20rem;"><?= e((string) $row['decision_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="<?= $muted ?>">
                        <?php if ($status === 'completed' && $summary !== []): ?>
                            <?= (int) ($summary['services'] ?? 0) ?> services,
                            <?= (int) ($summary['domains'] ?? 0) ?> domains,
                            <?= (int) ($summary['invoices'] ?? 0) ?> invoices,
                            <?= (int) ($summary['tickets_moved'] ?? 0) ?> tickets
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
