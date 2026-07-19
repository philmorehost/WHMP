<?php
/** @var array{imported: int, skipped: int, errors: array<int, array{row: int, reason: string}>}|null $result */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $runs */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Import Domain TLD Pricing</h1>
    <p><a href="/admin/domain-pricing">&larr; Back to domain pricing</a></p>
</div>

<?php if ($error !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <div class="cv-field-error"><?= e($error) ?></div>
    </div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Import Complete</h2>
        <p>
            <span class="cv-badge cv-badge--success"><?= (int) $result['imported'] ?> imported</span>
            <?php if ($result['skipped'] > 0): ?>
                <span class="cv-badge cv-badge--danger"><?= (int) $result['skipped'] ?> skipped</span>
            <?php endif; ?>
        </p>
        <?php if ($result['errors'] !== []): ?>
            <table class="cv-table">
                <thead><tr><th>Row</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($result['errors'] as $err): ?>
                    <tr><td><?= (int) $err['row'] ?></td><td><?= e($err['reason']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Upload a CSV</h2>
    <p style="color:var(--cv-text-secondary);">
        The first row must be a header row. Recognized columns (any order, case-insensitive):
        <code>tld</code>, <code>registrar_slug</code>, <code>register_price</code>, <code>transfer_price</code>, <code>renew_price</code> — all required.
        <code>tld</code> may be given with or without a leading dot (e.g. <code>com</code> or <code>.com</code>).
        A row whose TLD already exists updates that row's pricing in place, so this is safe to re-run with an updated price list.
    </p>
    <form method="post" action="/admin/import/tlds" enctype="multipart/form-data"><?= csrf_field() ?>
        <div class="cv-field">
            <label class="cv-label">CSV file</label>
            <input class="cv-input" type="file" name="csv" accept=".csv,text/csv" required>
        </div>
        <button class="cv-btn" type="submit">Upload &amp; Import</button>
    </form>
</div>

<div class="cv-card">
    <h2 class="cv-card__title" style="font-size:var(--cv-text-md);">Recent Imports</h2>
    <table class="cv-table">
        <thead><tr><th>File</th><th>Total Rows</th><th>Imported</th><th>Skipped</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($runs as $run): ?>
            <tr>
                <td><?= e((string) $run['filename']) ?></td>
                <td><?= (int) $run['total_rows'] ?></td>
                <td><?= (int) $run['imported_count'] ?></td>
                <td><?= (int) $run['skipped_count'] ?></td>
                <td><?= e((string) $run['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($runs === []): ?>
            <tr><td colspan="5" style="color:var(--cv-text-secondary);">No imports yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
