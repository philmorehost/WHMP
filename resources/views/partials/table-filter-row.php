<?php
/**
 * Reusable admin table column-filter ROW (partial).
 *
 * Emits the <tr class="table-filter-row"> that sits directly under the table
 * header. Each cell is bound to the GET form rendered by
 * partials/table-filter.php via the HTML5 `form` attribute, so pressing Enter
 * in any filter input re-submits the page with `filters[key]=value` and the
 * server re-filters + re-paginates. Active filters stay visible on reload;
 * empty cells stay hidden until their header is clicked or "Filters" is
 * toggled.
 *
 * Expected variables:
 *   @var string $formId
 *   @var string $action        page URL without query (for the clear links)
 *   @var array<int, array<string, mixed>> $columns
 *        header-order descriptors, one per header cell:
 *        ['filterable' => false]                         → spacer cell
 *        ['filterable' => true, 'key', 'label', 'type'   → filter cell
 *          ('text'|'number'|'select'), 'options'?, 'placeholder'?]
 *   @var array<string, string> $filters
 *   @var array<string, string> $preserve
 */
?>
<tr class="table-filter-row" id="filter-row-<?= e($formId) ?>">
    <?php foreach ($columns as $column): ?>
        <?php if (empty($column['filterable'])): ?>
            <th class="table-filter-cell is-hidden"></th>
        <?php else:
            $key = (string) $column['key'];
            $value = $filters[$key] ?? '';
            $visible = $value !== '' ? '' : ' is-hidden';
            $clear = \CodeVault\Table\TableFilters::query(
                array_diff_key($filters, [$key => true]),
                $preserve
            );
        ?>
            <th class="table-filter-cell<?= $visible ?>" data-filter-cell="<?= e($key) ?>">
                <?php if (($column['type'] ?? 'text') === 'select' && !empty($column['options'])): ?>
                    <select class="table-filter-select" name="filters[<?= e($key) ?>]"
                            form="<?= e($formId) ?>" data-filter-input="<?= e($key) ?>"
                            aria-label="Filter by <?= e($column['label']) ?>">
                        <option value="">— <?= e($column['label']) ?> —</option>
                        <?php foreach ((array) $column['options'] as $optValue => $optLabel): ?>
                            <option value="<?= e((string) $optValue) ?>" <?= $value === (string) $optValue ? 'selected' : '' ?>><?= e((string) $optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" class="table-filter-input"
                           name="filters[<?= e($key) ?>]" value="<?= e($value) ?>"
                           form="<?= e($formId) ?>" data-filter-input="<?= e($key) ?>"
                           placeholder="<?= e((string) ($column['placeholder'] ?? 'Filter ' . $column['label'])) ?>"
                           aria-label="Filter by <?= e($column['label']) ?>">
                <?php endif; ?>
                <?php if ($value !== ''): ?>
                    <a class="table-filter-clear" href="<?= e($action . $clear) ?>" title="Clear this filter">✕ Clear</a>
                <?php endif; ?>
            </th>
        <?php endif; ?>
    <?php endforeach; ?>
</tr>
