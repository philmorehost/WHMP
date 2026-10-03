<?php
/**
 * Account moves, from the reseller's side: ask us to bring a customer to this store, or
 * to move one of this store's customers to another provider.
 *
 * Shows the reseller only what THEY asked and what was decided — never whether an
 * address they asked about has an account anywhere, which would expose other stores'
 * customer lists.
 *
 * @var array<string, mixed> $store
 * @var array<int, array<string, mixed>> $requests
 * @var string|null $notice
 * @var string|null $error
 * @var array{direction?: string, client_email?: string, provider_website?: string, reason?: string} $old
 */

$statusLabels = [
    'pending' => ['Waiting for review', 'cv-badge--king'],
    'completed' => ['Moved', 'cv-badge--success'],
    'rejected' => ['Declined', 'cv-badge--danger'],
    'cancelled' => ['Cancelled', 'cv-badge--neutral'],
];
$direction = (string) ($old['direction'] ?? 'in');
$storeId = (int) $store['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <header class="rs-head">
        <h1 class="rs-head__title">Account moves</h1>
    </header>
    <?= $view->render('partials.reseller-nav') ?>
    <p style="color:var(--cv-text-secondary);">
        A customer who already has an account with another provider on <?= e(brand_name()) ?> can be moved to
        <strong><?= e((string) ($store['brand_name'] ?? 'your store')) ?></strong> — and one of your customers can be
        moved elsewhere if they ask. Their <strong>services, domains, invoices and ticket history</strong> move with
        the account. Every request is checked with the account holder by our team before anything moves, and we reply
        on a support ticket.
    </p>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <div class="cv-alert cv-alert--error" style="margin-bottom:var(--cv-space-4);"><?= e((string) $error) ?></div>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <div class="cv-alert cv-alert--success" style="margin-bottom:var(--cv-space-4);"><?= e((string) $notice) ?></div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title">Request a move</h2>
    <form method="post" action="/client/reseller/migrations" style="display:flex;flex-direction:column;gap:var(--cv-space-3);max-width:40rem;">
        <?= csrf_field() ?>
        <fieldset style="border:0;padding:0;margin:0;display:flex;flex-direction:column;gap:6px;">
            <legend class="cv-label" style="margin-bottom:6px;">What should happen?</legend>
            <label><input type="radio" name="direction" value="in" <?= $direction !== 'out' ? 'checked' : '' ?>>
                Move a customer <strong>to my store</strong> (they have asked to buy from you)</label>
            <label><input type="radio" name="direction" value="out" <?= $direction === 'out' ? 'checked' : '' ?>>
                Move one of <strong>my customers</strong> to another provider</label>
        </fieldset>
        <div class="cv-field">
            <label class="cv-label" for="client_email">The customer's account email address</label>
            <input class="cv-input" id="client_email" type="email" name="client_email" required value="<?= e((string) ($old['client_email'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label" for="provider_website">New provider's website <span style="color:var(--cv-text-secondary);font-weight:400;">(only when moving your customer away)</span></label>
            <input class="cv-input" id="provider_website" type="text" name="provider_website" placeholder="e.g. www.example.com" value="<?= e((string) ($old['provider_website'] ?? '')) ?>">
        </div>
        <div class="cv-field">
            <label class="cv-label" for="reason">Notes for our team <span style="color:var(--cv-text-secondary);font-weight:400;">(optional)</span></label>
            <textarea class="cv-input" id="reason" name="reason" rows="3"><?= e((string) ($old['reason'] ?? '')) ?></textarea>
        </div>
        <div><button class="cv-btn" type="submit">Send request</button></div>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Your requests</h2>
    <?php if ($requests === []): ?>
        <p style="color:var(--cv-text-secondary);">You have not asked for any moves yet.</p>
    <?php else: ?>
        <table class="cv-table">
            <thead><tr><th>Request</th><th>Customer</th><th>Direction</th><th>Status</th></tr></thead>
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
                    <td><?= e((string) $row['client_email']) ?></td>
                    <td>
                        <?php if ((int) ($row['to_reseller_id'] ?? 0) === $storeId): ?>
                            To your store
                        <?php else: ?>
                            Away to <?= e((string) ($row['target_input'] ?? 'another provider')) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="cv-badge <?= $badge ?>"><?= e($label) ?></span>
                        <?php if ((string) $row['status'] === 'rejected' && trim((string) ($row['decision_note'] ?? '')) !== ''): ?>
                            <div style="font-size:var(--cv-text-xs);color:var(--cv-text-secondary);white-space:pre-wrap;"><?= e((string) $row['decision_note']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
