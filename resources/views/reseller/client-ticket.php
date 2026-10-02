<?php
/**
 * One conversation, as the store sees it.
 *
 * THE AUTHOR LABELS ARE THE POINT OF THIS PAGE.
 *
 * A reply is authored as 'reseller' when the store writes it, 'admin' when WE do, and
 * 'client' when the customer does. TicketService::isSupportAuthor() treats the first
 * two as support for the status transition, so the platform's own view shows them the
 * same way — but here they must be told APART, because "has our support answered?" is
 * the whole question the reseller is asking. Rendering both as "Support" would hide the
 * one reply they are waiting for.
 *
 * @var array<string, mixed> $store
 * @var array<string, mixed> $ticket
 * @var array<int, array<string, mixed>> $replies
 * @var string $authorName
 * @var string|null $notice
 * @var string|null $error
 */

$status = (string) ($ticket['status'] ?? '');
$isClosed = $status === 'closed';
$escalatedAt = $ticket['escalated_at'] ?? null;
$isEscalated = $escalatedAt !== null && !$isClosed;

$statusLabels = [
    'open' => 'Open',
    'customer-reply' => 'Customer replied',
    'answered' => 'Answered',
    'closed' => 'Closed',
];

$customerName = trim((string) ($ticket['client_first_name'] ?? '') . ' ' . (string) ($ticket['client_last_name'] ?? ''));
$customerName = $customerName !== '' ? $customerName : 'your customer';
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">#<?= (int) $ticket['id'] ?> — <?= e((string) ($ticket['subject'] ?? '')) ?></h1>
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary); font-size:var(--cv-text-sm);">
        From <strong><?= e($customerName) ?></strong>
        <?php if (!empty($ticket['client_email'])): ?>
            (<?= e((string) $ticket['client_email']) ?>)
        <?php endif; ?>
        &middot; <?= e((string) ($ticket['department_name'] ?? '')) ?>
        &middot; Opened <?= e((string) ($ticket['created_at'] ?? '')) ?>
        &middot; <span class="cv-badge <?= $status === 'closed' ? 'cv-badge--neutral' : 'cv-badge--success' ?>"><?= e($statusLabels[$status] ?? ucfirst($status)) ?></span>
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<?php if ($isEscalated): ?>
    <div class="cv-alert cv-alert--warning" style="margin-bottom:var(--cv-space-4);">
        <strong>Waiting on <?= e(brand_name()) ?> support</strong> since <?= e((string) $escalatedAt) ?>.
        <div style="margin-top:var(--cv-space-2); white-space:pre-wrap;"><?= e((string) ($ticket['escalated_note'] ?? '')) ?></div>
        <div style="margin-top:var(--cv-space-3);">
            <form method="post" action="/client/reseller/tickets/<?= (int) $ticket['id'] ?>/withdraw">
                <?= csrf_field() ?>
                <button type="submit" class="cv-btn cv-btn--secondary">I resolved this myself — take it back</button>
            </form>
        </div>
        <p style="margin:var(--cv-space-2) 0 0; font-size:var(--cv-text-xs);">
            You can still add a reply below; it is recorded on the same ticket.
        </p>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Conversation</h2>
    <?php if ($replies === []): ?>
        <p style="color:var(--cv-text-secondary);">No messages on this ticket yet.</p>
    <?php else: ?>
        <?php foreach ($replies as $reply): ?>
            <?php
            $authorType = (string) ($reply['author_type'] ?? '');
            $isOurs = $authorType === 'admin';
            $isMine = $authorType === 'reseller';

            if ($isOurs) {
                $who = brand_name() . ' support';
            } elseif ($isMine) {
                $who = 'You (' . (string) ($store['brand_name'] ?? 'your store') . ')';
            } else {
                $who = (string) ($reply['author_name'] ?? $customerName);
            }
            ?>
            <div style="border:1px solid var(--cv-border-default); border-radius:var(--cv-radius-md); padding:var(--cv-space-4); margin-bottom:var(--cv-space-3);">
                <div style="display:flex; justify-content:space-between; gap:var(--cv-space-3); flex-wrap:wrap; margin-bottom:var(--cv-space-2);">
                    <strong>
                        <?= e($who) ?>
                        <?php if ($isOurs): ?>
                            <span class="cv-badge cv-badge--neutral">From our support</span>
                        <?php endif; ?>
                    </strong>
                    <span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);"><?= e((string) ($reply['created_at'] ?? '')) ?></span>
                </div>
                <div style="white-space:pre-wrap;"><?= e((string) ($reply['message'] ?? '')) ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if (!$isClosed): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Reply to your customer</h2>
        <form method="post" action="/client/reseller/tickets/<?= (int) $ticket['id'] ?>/reply">
            <?= csrf_field() ?>
            <div class="cv-form-group">
                <label for="message">Message</label>
                <textarea id="message" name="message" class="cv-textarea" rows="6" required></textarea>
                <p style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary);">
                    Sent as <strong><?= e($authorName) ?></strong> — your customer sees your store's name,
                    never ours.
                </p>
            </div>
            <button type="submit" class="cv-btn">Send reply</button>
        </form>
    </div>

    <?php if (!$isEscalated): ?>
        <div class="cv-card">
            <h2 class="cv-card__title">Cannot resolve it? Pass it up</h2>
            <p style="color:var(--cv-text-secondary);">
                <?= e(brand_name()) ?> support will pick it up from here. Say what you have already
                tried — that is what saves the round trip, and the customer keeps dealing only with you.
            </p>
            <form method="post" action="/client/reseller/tickets/<?= (int) $ticket['id'] ?>/escalate">
                <?= csrf_field() ?>
                <div class="cv-form-group">
                    <label for="note">What have you already tried?</label>
                    <textarea id="note" name="note" class="cv-textarea" rows="4" required></textarea>
                </div>
                <button type="submit" class="cv-btn cv-btn--secondary">Pass to <?= e(brand_name()) ?> support</button>
            </form>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="cv-alert cv-alert--neutral">
        This ticket is closed. A closed ticket cannot be replied to — ask your customer to open a new one
        if the problem has come back.
    </div>
<?php endif; ?>
