<?php
/** @var array{imported: int, skipped: int, errors: array<int, array{row: int, reason: string}>}|null $result */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $runs */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Import Services</h1>
    <p><a href="/admin/services">&larr; Back to services</a></p>
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
    <p style="color:var(--cv-text-secondary);">The first row must be a header row. Recognized columns (any order, case-insensitive): <code>client_email</code>, <code>product_name</code>, <code>billing_cycle</code>, <code>amount</code>, <code>status</code>, <code>next_due_date</code>. <code>client_email</code>, <code>product_name</code>, <code>billing_cycle</code>, <code>amount</code>, and <code>next_due_date</code> are required. The client and the product must already exist (matched by email and by exact product name); <code>status</code> defaults to <code>pending</code> when left blank.</p>
    <form method="post" action="/admin/import/services" enctype="multipart/form-data"><?= csrf_field() ?>
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
