<?php
/**
 * A client asks to move their account to another provider on this platform.
 *
 * Worded for whichever site it is shown on: it never names the platform or any store.
 * The destination is the website address the client types — they know where they want
 * to go — and only platform staff ever see it.
 *
 * @var array<int, array<string, mixed>> $requests this client's own requests, newest first
 * @var string|null $notice
 * @var string|null $error
 * @var array{provider_website?: string, reason?: string} $old
 */

$statusLabels = [
    'pending' => ['Waiting for review', 'cv-badge--king'],
    'completed' => ['Moved', 'cv-badge--success'],
    'rejected' => ['Declined', 'cv-badge--danger'],
    'cancelled' => ['Cancelled', 'cv-badge--neutral'],
];
$hasPending = false;

foreach ($requests as $row) {
    if ((string) $row['status'] === 'pending') {
        $hasPending = true;
    }
}
?>
<div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
    <h1 class="cv-card__title">Move your account to another provider</h1>
    <p><a href="/client/tickets">&larr; Back to support</a></p>
    <p style="color:var(--cv-text-secondary);">
        If you would like your account looked after by another provider on this platform, tell us their website
        address below. Your <strong>services, domains, invoices and support history</strong> all move with your account,
        and nothing needs to be set up again. A support ticket is opened for your request and we will reply on it
        once it has been reviewed.
    </p>

    <?php if ($error !== null && $error !== ''): ?>
        <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-3);"><?= e((string) $error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-3);"><?= e((string) $notice) ?></div>
    <?php endif; ?>

    <?php if ($hasPending): ?>
        <div class="cv-alert cv-alert--neutral">You already have a request waiting for review. We will update its ticket as soon as it has been decided.</div>
    <?php else: ?>
        <form method="post" action="/client/account-move" style="display:flex;flex-direction:column;gap:var(--cv-space-3);">
            <?= csrf_field() ?>
            <div class="cv-field">
                <label class="cv-label" for="provider_website">The new provider's website address</label>
                <input class="cv-input" id="provider_website" type="text" name="provider_website" required
                       placeholder="e.g. www.example.com" value="<?= e((string) ($old['provider_website'] ?? '')) ?>">
            </div>
            <div class="cv-field">
                <label class="cv-label" for="reason">Anything we should know? <span style="color:var(--cv-text-secondary);font-weight:400;">(optional)</span></label>
                <textarea class="cv-input" id="reason" name="reason" rows="4"><?= e((string) ($old['reason'] ?? '')) ?></textarea>
            </div>
            <div>
                <button class="cv-btn" type="submit">Send request</button>
            </div>
            <p style="margin:0;font-size:var(--cv-text-sm);color:var(--cv-text-secondary);">
                After the move you will sign in on the new provider's website with your usual email address and password.
            </p>
        </form>
    <?php endif; ?>
</div>

<?php if ($requests !== []): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto;">
        <h2 class="cv-card__title">Your requests</h2>
        <table class="cv-table">
            <thead><tr><th>Request</th><th>Provider</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $row): ?>
                <?php [$label, $badge] = $statusLabels[(string) $row['status']] ?? [ucfirst((string) $row['status']), 'cv-badge--neutral']; ?>
                <tr>
                    <td>
                        #<?= (int) $row['id'] ?><br>
                        <span style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);"><?= e((string) $row['created_at']) ?></span>
                        <?php if (!empty($row['ticket_id'])): ?>
                            <br><a href="/client/tickets/<?= (int) $row['ticket_id'] ?>">View ticket</a>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) ($row['target_input'] ?? '')) ?></td>
                    <td><span class="cv-badge <?= $badge ?>"><?= e($label) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
