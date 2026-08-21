<?php
/**
 * Reusable admin table pagination (partial).
 *
 * Renders "Page X of Y (N total)" plus Previous/Next links that preserve the
 * active column filters AND any extra params (status tabs, search box), so a
 * filter stays applied when the admin pages through the result set.
 *
 * Expected variables:
 *   @var array{data: array<int, array<string, mixed>>, total: int, page: int, perPage: int} $results
 *   @var string $action   page URL without query (e.g. '/admin/orders')
 *   @var array<string, string> $filters
 *   @var array<string, string> $preserve
 *   @var array{column: string, dir: string}|null $sort
 *   @var string|null $label  optional label for the "N total" text (default 'items')
 */
?>
<?php
$totalPages = (int) ceil(($results['total'] ?? 0) / max(1, (int) ($results['perPage'] ?? 20)));
$currentPage = max(1, (int) ($results['page'] ?? 1));
$label = $label ?? 'items';
$sort = $sort ?? null;
?>
<style>
.table-pagination {
    display: flex; justify-content: space-between; align-items: center; gap: 12px;
    padding: 14px 20px; border-top: 1px solid var(--cv-border-default);
    flex-wrap: wrap;
}
.table-pagination__info { font-size: .85rem; color: var(--cv-text-secondary); }
.table-pagination__controls { display: flex; gap: 8px; }
.table-pagination__link {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .8rem; font-weight: 600; color: var(--cv-text-primary);
    background: var(--cv-bg-surface); border: 1px solid var(--cv-border-default);
    border-radius: 8px; padding: 7px 14px; text-decoration: none; transition: all .2s;
}
.table-pagination__link:hover { border-color: var(--cv-color-brand-500); color: var(--cv-color-brand-500); }
.table-pagination__link.is-disabled { opacity: .4; pointer-events: none; }
</style>
<div class="table-pagination">
    <div class="table-pagination__info">
        Page <strong><?= $currentPage ?></strong> of <strong><?= max(1, $totalPages) ?></strong>
        (<?= number_format((int) ($results['total'] ?? 0)) ?> total <?= e($label) ?>)
    </div>
    <div class="table-pagination__controls">
        <?php if ($currentPage > 1): ?>
            <a class="table-pagination__link" href="<?= e($action . \CodeVault\Table\TableFilters::query($filters, $preserve, $currentPage - 1, $sort)) ?>">← Previous</a>
        <?php else: ?>
            <span class="table-pagination__link is-disabled">← Previous</span>
        <?php endif; ?>
        <?php if ($currentPage < $totalPages): ?>
            <a class="table-pagination__link" href="<?= e($action . \CodeVault\Table\TableFilters::query($filters, $preserve, $currentPage + 1, $sort)) ?>">Next →</a>
        <?php else: ?>
            <span class="table-pagination__link is-disabled">Next →</span>
        <?php endif; ?>
    </div>
</div>
