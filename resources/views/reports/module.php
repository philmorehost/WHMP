<?php
/**
 * @var string $slug
 * @var \CodeVault\Modules\ReportModule|null $module
 * @var array<string, array{type: string, label: string}> $filters
 * @var array<string, mixed> $values
 * @var array{success: bool, message: string, columns?: array<int, string>, rows?: array<int, array<int, mixed>>} $result
 */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title"><?= e($module?->metadata()['name'] ?? $slug) ?></h1>
    <p style="color:var(--cv-text-secondary);"><?= e($module?->metadata()['description'] ?? '') ?></p>
    <p><a href="/admin/reports">&larr; Back to reports</a></p>
</div>

<?php if ($filters !== []): ?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <form method="get" action="/admin/reports/modules/<?= e($slug) ?>">
        <?php foreach ($filters as $key => $filter): ?>
            <label class="cv-label" for="filter-<?= e($key) ?>"><?= e($filter['label']) ?></label>
            <input class="cv-input" type="<?= e($filter['type']) ?>" id="filter-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e((string) ($values[$key] ?? '')) ?>">
        <?php endforeach; ?>
        <button class="cv-btn" type="submit" style="margin-top:var(--cv-space-3);">Run report</button>
    </form>
</div>
<?php endif; ?>

<div class="cv-card">
    <?php if (!$result['success']): ?>
        <p style="color:var(--cv-danger, #c0392b);"><?= e($result['message']) ?></p>
    <?php else: ?>
        <table class="cv-table">
            <thead>
                <tr><?php foreach ($result['columns'] as $column): ?><th><?= e($column) ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php foreach ($result['rows'] as $row): ?>
                <tr><?php foreach ($row as $cell): ?><td><?= e((string) $cell) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if ($result['rows'] === []): ?>
                <tr><td colspan="<?= count($result['columns']) ?>" style="color:var(--cv-text-secondary);">No data for this range.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
