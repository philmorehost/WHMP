<?php
/** @var array<string, mixed>|null $quote */
/** @var array<int, array<string, mixed>> $items */
/** @var string|null $error */
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto var(--cv-space-4);">
    <h1 class="cv-card__title">Quote Q-<?= (int) $quote['id'] ?></h1>
    <p><a href="/client/quotes">&larr; Back to my quotes</a> &middot; <a href="/client/quotes/<?= (int) $quote['id'] ?>/pdf" target="_blank">Download PDF</a></p>
    <p><strong>Subject:</strong> <?= e($quote['subject']) ?></p>
    <p><strong>Status:</strong> <?= e(ucfirst((string) $quote['status'])) ?></p>
    <?php if (!empty($quote['valid_until'])): ?>
        <p><strong>Valid Until:</strong> <?= e((string) $quote['valid_until']) ?></p>
    <?php endif; ?>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="max-width:40rem;margin:0 auto var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <table class="cv-table">
        <thead><tr><th>Description</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr><td><?= e($item['description']) ?></td><td>$<?= number_format((float) $item['amount'], 2) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr><td style="font-weight:700;">Total</td><td style="font-weight:700;">$<?= number_format((float) $quote['total'], 2) ?></td></tr>
        </tfoot>
    </table>

    <?php if ($quote['status'] === 'sent'): ?>
        <div style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);">
            <form method="post" action="/client/quotes/<?= (int) $quote['id'] ?>/accept"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Accept Quote</button>
            </form>
            <form method="post" action="/client/quotes/<?= (int) $quote['id'] ?>/decline" data-confirm="Decline this quote?"><?= csrf_field() ?>
                <button class="cv-btn cv-btn--secondary" type="submit">Decline</button>
            </form>
        </div>
    <?php endif; ?>
</div>
