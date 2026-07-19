<?php
/** @var array<int, array<string, mixed>> $quotes */
$badgeClass = static fn (string $status): string => match ($status) {
    'accepted' => 'cv-badge--success',
    'declined', 'expired' => 'cv-badge--danger',
    default => 'cv-badge--neutral',
};
// A draft hasn't been sent yet — nothing for the client to see until it is.
$visibleQuotes = array_values(array_filter($quotes, static fn (array $q) => $q['status'] !== 'draft'));
?>
<div class="cv-card" style="max-width:40rem;margin:var(--cv-space-6) auto;">
    <h1 class="cv-card__title">My Quotes</h1>
    <p><a href="/client/dashboard">&larr; Back to dashboard</a></p>
</div>

<div class="cv-card" style="max-width:40rem;margin:0 auto;">
    <div class="cv-datatable__toolbar">
        <?= $view->partial('partials.table-search', ['target' => '#my-quotes-table', 'placeholder' => 'Search quotes...']) ?>
    </div>
    <table class="cv-table" id="my-quotes-table">
        <thead><tr><th>#</th><th>Subject</th><th>Total</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($visibleQuotes as $quote): ?>
            <tr>
                <td>Q-<?= (int) $quote['id'] ?></td>
                <td><?= e($quote['subject']) ?></td>
                <td>$<?= number_format((float) $quote['total'], 2) ?></td>
                <td><span class="cv-badge <?= $badgeClass((string) $quote['status']) ?>"><?= e(ucfirst((string) $quote['status'])) ?></span></td>
                <td><a class="cv-btn cv-btn--secondary" href="/client/quotes/<?= (int) $quote['id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($visibleQuotes === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No quotes yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
