<?php
/** @var array<int, array<string, mixed>> $quotes */
$badgeClass = static fn (string $status): string => match ($status) {
    'accepted' => 'cv-badge--success',
    'declined', 'expired' => 'cv-badge--danger',
    'sent' => 'cv-badge--neutral',
    default => 'cv-badge--neutral',
};
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Quotes</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
    <div style="margin-top:var(--cv-space-2);">
        <a class="cv-btn" href="/admin/quotes/create">Create Quote</a>
    </div>
</div>

<div class="cv-card">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#quotes-table', 'placeholder' => 'Search quotes...']) ?>
    </div>
    <table class="cv-table" id="quotes-table">
        <thead><tr><th>#</th><th>Client</th><th>Subject</th><th>Total</th><th>Status</th><th>Valid Until</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($quotes as $quote): ?>
            <tr>
                <td><a href="/admin/quotes/<?= (int) $quote['id'] ?>">Q-<?= (int) $quote['id'] ?></a></td>
                <td><?= e($quote['first_name'] . ' ' . $quote['last_name']) ?> (<?= e($quote['client_email']) ?>)</td>
                <td><?= e($quote['subject']) ?></td>
                <td><?= e($quote['currency_symbol'] ?? '$') ?><?= number_format((float) $quote['total'], 2) ?></td>
                <td><span class="cv-badge <?= $badgeClass((string) $quote['status']) ?>"><?= e(ucfirst((string) $quote['status'])) ?></span></td>
                <td><?= $quote['valid_until'] !== null ? e((string) $quote['valid_until']) : '&mdash;' ?></td>
                <td><a class="cv-btn cv-btn--secondary" href="/admin/quotes/<?= (int) $quote['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($quotes === []): ?>
            <tr><td colspan="7" style="color:var(--cv-text-secondary);">No quotes yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
