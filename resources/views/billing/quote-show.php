<?php
/** @var array<string, mixed> $quote */
/** @var array<int, array<string, mixed>> $items */
/** @var array<string, mixed>|null $client */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Quote Q-<?= (int) $quote['id'] ?></h1>
    <p><a href="/admin/quotes">&larr; Back to quotes</a> &middot; <a href="/admin/quotes/<?= (int) $quote['id'] ?>/pdf" target="_blank">Download PDF</a></p>
    <p><strong>Client:</strong> <?= $client !== null ? e($client['first_name'] . ' ' . $client['last_name'] . ' (' . $client['email'] . ')') : 'Unknown' ?></p>
    <p><strong>Subject:</strong> <?= e($quote['subject']) ?></p>
    <p><strong>Status:</strong> <?= e(ucfirst((string) $quote['status'])) ?></p>
    <?php if (!empty($quote['valid_until'])): ?>
        <p><strong>Valid Until:</strong> <?= e((string) $quote['valid_until']) ?></p>
    <?php endif; ?>
    <?php if ($quote['invoice_id'] !== null): ?>
        <p><strong>Converted Invoice:</strong> <a href="/admin/invoices/<?= (int) $quote['invoice_id'] ?>">INV-<?= (int) $quote['invoice_id'] ?></a></p>
    <?php endif; ?>
    <p><strong>Created:</strong> <?= e($quote['created_at']) ?></p>

    <div style="margin-top:var(--cv-space-3);display:flex;gap:var(--cv-space-2);">
        <?php if ($quote['status'] === 'draft'): ?>
            <form method="post" action="/admin/quotes/<?= (int) $quote['id'] ?>/send"><?= csrf_field() ?>
                <button class="cv-btn" type="submit">Send to Client</button>
            </form>
            <form method="post" action="/admin/quotes/<?= (int) $quote['id'] ?>/delete" data-confirm="Delete this draft quote? This cannot be undone."><?= csrf_field() ?>
                <button class="cv-btn cv-btn--danger" type="submit">Delete Draft</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="cv-card">
    <h2 class="cv-card__title">Items</h2>
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
</div>
