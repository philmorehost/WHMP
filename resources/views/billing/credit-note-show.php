<?php
/** @var array<string, mixed> $creditNote */
/** @var array<int, array<string, mixed>> $items */
/** @var array<string, mixed>|null $client */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Credit Note CN-<?= (int) $creditNote['id'] ?></h1>
    <p><a href="/admin/credit-notes">&larr; Back to credit notes</a> &middot; <a href="/admin/credit-notes/<?= (int) $creditNote['id'] ?>/pdf" target="_blank">Download PDF</a></p>
    <p><strong>Client:</strong> <?= $client !== null ? e($client['first_name'] . ' ' . $client['last_name'] . ' (' . $client['email'] . ')') : 'Unknown' ?></p>
    <p><strong>Reason:</strong> <?= e($creditNote['reason']) ?></p>
    <?php if ($creditNote['invoice_id'] !== null): ?>
        <p><strong>Related Invoice:</strong> <a href="/admin/invoices/<?= (int) $creditNote['invoice_id'] ?>">INV-<?= (int) $creditNote['invoice_id'] ?></a></p>
    <?php endif; ?>
    <p><strong>Issued:</strong> <?= e($creditNote['created_at']) ?></p>
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
            <tr><td style="font-weight:700;">Total Credit</td><td style="font-weight:700;">$<?= number_format((float) $creditNote['total'], 2) ?></td></tr>
        </tfoot>
    </table>
</div>
