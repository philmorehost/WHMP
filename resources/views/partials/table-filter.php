<?php
/**
 * Reusable admin table column-filter FORM + UI chrome (partial).
 *
 * Renders the hidden GET form the filter-row inputs bind to (via the HTML5
 * `form` attribute), the "Filters" toggle button and the scoped CSS/JS:
 *   - clicking a filterable column header reveals + focuses that column's
 *     input in the filter row;
 *   - the "Filters" toggle shows/hides the whole filter row at once;
 *   - active filters stay visible on reload.
 *
 * Expected variables (set by the calling view):
 *   @var string $formId        unique id for the bound form (one per table)
 *   @var string $action        form action — page URL without query string
 *   @var array<string, string> $filters   current active filters (key => value)
 *   @var array<string, string> $preserve  extra params to carry (status, q, ...)
 *   @var int    $activeCount   number of active filters (shown on the toggle)
 *
 * The matching <tr> lives in partials/table-filter-row.php and must be
 * placed inside the table's <thead>, one cell per header column.
 */
?>
<style>
.table-filter-form { display: none; }
.table-filter-toggle {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .8rem; font-weight: 600; color: var(--cv-text-secondary);
    background: var(--cv-bg-surface); border: 1px solid var(--cv-border-default);
    border-radius: 8px; padding: 7px 12px; cursor: pointer; transition: all .2s;
    white-space: nowrap;
}
.table-filter-toggle:hover { color: var(--cv-color-brand-500); border-color: var(--cv-color-brand-500); }
.table-filter-toggle.is-active { color: var(--cv-color-brand-500); border-color: var(--cv-color-brand-500); background: rgba(47,111,237,.06); }
.table-filter-row th { padding: 6px 8px !important; vertical-align: middle; }
.table-filter-row .table-filter-cell { transition: background .15s; }
th[data-col-filter] { cursor: pointer; }
.table-filter-row .table-filter-cell.is-hidden { display: none; }
.table-filter-row .table-filter-input,
.table-filter-row .table-filter-select {
    width: 100%; min-width: 0; font-size: .78rem; padding: 5px 8px;
    border: 1px solid var(--cv-border-default); border-radius: 6px;
    background: var(--cv-bg-surface); color: var(--cv-text-primary); outline: none;
    transition: border-color .15s;
}
.table-filter-row .table-filter-input:focus,
.table-filter-row .table-filter-select:focus { border-color: var(--cv-color-brand-500); }
.table-filter-row .table-filter-clear {
    display: inline-flex; align-items: center; gap: 4px; font-size: .72rem;
    color: var(--cv-text-secondary); text-decoration: none; white-space: nowrap;
}
.table-filter-row .table-filter-clear:hover { color: #ef4444; }
</style>

<form method="get" action="<?= e($action) ?>" class="table-filter-form" id="<?= e($formId) ?>" data-filter-form>
    <input type="hidden" name="page" value="1">
    <?php // Only preserve the extra params (status tabs, search box) + the
          // active sort. The column filters themselves come from the visible
          // filter-row inputs (bound to this form via the form="" attribute),
          // so they must NOT be repeated as hidden inputs here or the URL
          // doubles up. ?>
    <?= \CodeVault\Table\TableFilters::hidden([], $preserve, $sort ?? null) ?>
</form>

<button type="button" class="table-filter-toggle<?= $activeCount > 0 ? ' is-active' : '' ?>" data-filter-toggle="<?= e($formId) ?>" title="Show / hide column filters">
    ⚙ Filters<?= $activeCount > 0 ? ' (' . $activeCount . ')' : '' ?>
</button>
