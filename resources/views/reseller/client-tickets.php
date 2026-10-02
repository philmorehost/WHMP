<?php
/**
 * The store's support queue.
 *
 * Ordered open → customer-reply → answered → closed, which is the platform's own order
 * (TicketRepository::forReseller) rather than a second convention: a reseller and an
 * admin looking at the same ticket must not disagree about which one needs attention.
 *
 * @var array<string, mixed> $store
 * @var array<int, array<string, mixed>> $tickets
 * @var array{total: int, needsReply: int, escalated: int, closed: int} $counts
 * @var string|null $notice
 * @var string|null $error
 */

$statusLabels = [
    'open' => 'Open',
    'customer-reply' => 'Customer replied',
    'answered' => 'Answered',
    'closed' => 'Closed',
];

// Which statuses need the STORE to act. "customer-reply" means the customer has
// written back since the last answer, so it is waiting on the store exactly as "open"
// is — counting only "open" would hide the newer of the two.
$needsReply = static fn (string $status): bool => in_array($status, ['open', 'customer-reply'], true);
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Support for your customers</h1>
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary);">
        These are tickets raised by <strong>your</strong> customers at
        <?= e((string) ($store['brand_name'] ?? 'your store')) ?>.
        Answer them yourself, and if you cannot resolve one, pass it to
        <?= e(brand_name()) ?> support with a note saying what you already tried.
        When they answer, the ticket comes back to you — the customer only ever deals with
        you.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <div style="display:flex; flex-wrap:wrap; gap:var(--cv-space-5);">
        <div>
            <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; color:var(--cv-text-secondary);">Total</div>
            <div style="font-size:var(--cv-text-2xl); font-weight:800;"><?= (int) $counts['total'] ?></div>
        </div>
        <div>
            <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; color:var(--cv-text-secondary);">Waiting on you</div>
            <div style="font-size:var(--cv-text-2xl); font-weight:800;"><?= (int) $counts['needsReply'] ?></div>
        </div>
        <div>
            <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; color:var(--cv-text-secondary);">With our support</div>
            <div style="font-size:var(--cv-text-2xl); font-weight:800;"><?= (int) $counts['escalated'] ?></div>
        </div>
        <div>
            <div style="font-size:var(--cv-text-xs); text-transform:uppercase; font-weight:700; color:var(--cv-text-secondary);">Closed</div>
            <div style="font-size:var(--cv-text-2xl); font-weight:800;"><?= (int) $counts['closed'] ?></div>
        </div>
    </div>
</div>

<?php if ($tickets === []): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Nothing here yet</h2>
        <p style="color:var(--cv-text-secondary);">
            When one of your customers opens a ticket on your storefront, it appears here.
            Nothing has been raised so far.
        </p>
    </div>
<?php else: ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Tickets</h2>
        <table class="cv-table">
            <thead>
            <tr>
                <th>Ticket</th>
                <th>Customer</th>
                <th>Department</th>
                <th>Status</th>
                <th>Last activity</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $ticket): ?>
                <?php
                $status = (string) ($ticket['status'] ?? '');
                $escalated = ($ticket['escalated_at'] ?? null) !== null && $status !== 'closed';
                $customerName = trim((string) ($ticket['client_first_name'] ?? '') . ' ' . (string) ($ticket['client_last_name'] ?? ''));
                ?>
                <tr>
                    <td>
                        <a href="/client/reseller/tickets/<?= (int) $ticket['id'] ?>">
                            <strong>#<?= (int) $ticket['id'] ?></strong>
                            <?= e((string) ($ticket['subject'] ?? '')) ?>
                        </a>
                        <?php if ($escalated): ?>
                            <br><span class="cv-badge cv-badge--neutral">With our support</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= e($customerName !== '' ? $customerName : 'Unknown') ?>
                        <?php if (!empty($ticket['client_email'])): ?>
                            <br><span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);"><?= e((string) $ticket['client_email']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($ticket['department_name'] ?? '')) ?></td>
                    <td>
                        <span class="cv-badge <?= $needsReply($status) ? 'cv-badge--danger' : ($status === 'closed' ? 'cv-badge--neutral' : 'cv-badge--success') ?>">
                            <?= e($statusLabels[$status] ?? ucfirst($status)) ?>
                        </span>
                    </td>
                    <td style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);">
                        <?= e((string) ($ticket['last_reply_at'] ?? $ticket['updated_at'] ?? '')) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
