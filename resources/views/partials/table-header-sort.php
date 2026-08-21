<?php
/**
 * Reusable admin table SORTABLE column header (partial).
 *
 * Renders a `<th>` whose label is a link that sorts the table by that column
 * — first click A-Z / 1-0, second click Z-A / 0-1 (WHMCS-style), preserving
 * the active filters, the status/search params and the current sort, and
 * resetting to page 1. The sorted column shows a ▲/▼ indicator.
 *
 * Expected variables:
 *   @var string $key       column key (must exist in the repo's sortable map)
 *   @var string $label     display text
 *   @var string $action    page URL without query
 *   @var array<string, string> $filters
 *   @var array<string, string> $preserve  (status, q, department_id, ...)
 *   @var array{column: string, dir: string}|null $sort  current sort
 *   @var string|null $align  optional 'right'/'center' for numeric columns
 */
?>
<?php
$isSorted = $sort !== null && $sort['column'] === $key;
$nextDir = $isSorted && $sort['dir'] === 'asc' ? 'desc' : 'asc';
$nextSort = ['column' => $key, 'dir' => $nextDir];
$href = $action . \CodeVault\Table\TableFilters::query($filters, $preserve, 1, $nextSort);
$indicator = $isSorted ? ($sort['dir'] === 'asc' ? '▲' : '▼') : '⇅';
$align = isset($align) && $align === 'right' ? 'right' : (isset($align) && $align === 'center' ? 'center' : 'left');
?>
<th class="table-sort-th" style="text-align:<?= $align ?>;">
    <a class="table-sort-link<?= $isSorted ? ' is-active' : '' ?>" href="<?= e($href) ?>"
       title="Sort by <?= e($label) ?> (click again to reverse)" data-sort-link>
        <span class="table-sort-label"><?= e($label) ?></span>
        <span class="table-sort-indicator<?= $isSorted ? ' is-active' : '' ?>" aria-hidden="true"><?= $indicator ?></span>
    </a>
</th>

<style>
.table-sort-th { white-space: nowrap; }
.table-sort-link {
    display: inline-flex; align-items: center; gap: 6px;
    color: inherit; text-decoration: none; font-weight: inherit;
}
.table-sort-link:hover { color: var(--cv-color-brand-500); }
.table-sort-link.is-active { color: var(--cv-color-brand-500); }
.table-sort-indicator {
    font-size: .72rem; opacity: .45; color: var(--cv-text-secondary);
}
.table-sort-indicator.is-active { opacity: 1; color: var(--cv-color-brand-500); }
</style>
