<?php
/**
 * What the stores have handed up, oldest first.
 *
 * A work list, not a report: the ticket waiting longest is at the top, and every row
 * links to the full ticket page rather than offering a second, thinner reply box. See
 * AdminResellerTicketController for why there is no "answer from here" control.
 *
 * @var array<int, array<string, mixed>> $queue
 * @var array<int, int|null> $waiting hours waiting, keyed by ticket id (null = unparseable stamp)
 * @var string|null $oldest
 * @var string|null $notice
 * @var string|null $error
 */

$statusLabels = [
    'open' => 'Open',
    'customer-reply' => 'Customer replied',
    'answered' => 'Answered',
];

// A day is the point at which a reseller starts chasing, so that is where the badge
// changes colour rather than an arbitrary hour count.
$urgency = static function (?int $hours): string {
    if ($hours === null) {
        return 'cv-badge--neutral';
    }

    if ($hours >= 48) {
        return 'cv-badge--danger';
    }

    return $hours >= 24 ? 'cv-badge--king' : 'cv-badge--neutral';
};
?>
<?= $view->render('partials.reseller-admin-nav') ?>
<div class="cv-card rs-page-head" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Store escalations</h1>
    <p><a href="/admin/resellers">&larr; Back to resellers</a> &middot;
        <a href="/admin/resellers/domains">Store domains</a> &middot;
        <a href="/admin/resellers/accounts">Accounts</a></p>
    <p style="color:var(--cv-text-secondary);">
        Tickets a store could not resolve and has passed up to us. Answering one on its own
        ticket page releases it back to the store automatically — that is the signal they are
        waiting for, so nothing here needs a button of its own.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<?php if ($queue === []): ?>
    <div class="cv-alert cv-alert--success">
        Nothing is waiting on us. No store has an unanswered escalation.
    </div>
<?php else: ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div style="display:flex; flex-wrap:wrap; gap:var(--cv-space-5);">
            <div>
                <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; color:var(--cv-text-secondary);">Waiting on us</div>
                <div style="font-size:var(--cv-text-2xl); font-weight:800;"><?= count($queue) ?></div>
            </div>
            <div>
                <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; color:var(--cv-text-secondary);">Oldest since</div>
                <div style="font-size:var(--cv-text-lg); font-weight:700;"><?= e((string) $oldest) ?></div>
            </div>
        </div>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Queue</h2>
        <table class="cv-table">
            <thead>
            <tr>
                <th>Store</th>
                <th>Ticket</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Waiting</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($queue as $ticket): ?>
                <?php
                $id = (int) $ticket['id'];
                $hours = $waiting[$id] ?? null;
                $status = (string) ($ticket['status'] ?? '');
                $customerName = trim((string) ($ticket['client_first_name'] ?? '') . ' ' . (string) ($ticket['client_last_name'] ?? ''));
                $storeName = (string) ($ticket['brand_name'] ?? '');
                ?>
                <tr>
                    <td>
                        <strong><?= e($storeName !== '' ? $storeName : 'Store ' . (string) ($ticket['slug'] ?? $id)) ?></strong>
                        <?php if (!empty($ticket['slug'])): ?>
                            <br><span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);"><?= e((string) $ticket['slug']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="/admin/tickets/<?= $id ?>">
                            <strong>#<?= $id ?></strong>
                            <?= e((string) ($ticket['subject'] ?? '')) ?>
                        </a>
                        <?php if (trim((string) ($ticket['escalated_note'] ?? '')) !== ''): ?>
                            <div style="margin-top:var(--cv-space-2); font-size:var(--cv-text-xs); color:var(--cv-text-secondary); white-space:pre-wrap;"><?= e((string) $ticket['escalated_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($customerName !== '' ? $customerName : 'Unknown') ?>
                        <?php if (!empty($ticket['client_email'])): ?>
                            <br><span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);"><?= e((string) $ticket['client_email']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="cv-badge cv-badge--success"><?= e($statusLabels[$status] ?? ucfirst($status)) ?></span></td>
                    <td>
                        <span class="cv-badge <?= $urgency($hours) ?>">
                            <?= $hours === null ? 'Unknown' : (int) $hours . 'h' ?>
                        </span>
                        <br><span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);"><?= e((string) ($ticket['escalated_at'] ?? '')) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
