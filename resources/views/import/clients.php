<?php
/** @var array{imported: int, skipped: int, errors: array<int, array{row: int, reason: string}>}|null $result */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $runs */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Import Clients</h1>
    <p><a href="/admin/clients">&larr; Back to clients</a></p>
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
    <p style="color:var(--cv-text-secondary);">The first row must be a header row. Recognized columns (any order, case-insensitive): <code>email</code>, <code>first_name</code>, <code>last_name</code>, <code>company_name</code>, <code>phone</code>, <code>address1</code>, <code>address2</code>, <code>city</code>, <code>state</code>, <code>postcode</code>, <code>country</code>. <code>email</code>, <code>first_name</code>, and <code>last_name</code> are required. Rows with an email that already exists are skipped, not overwritten. Imported clients have no password set — they'll use "Forgot password?" to set one.</p>
    <form method="post" action="/admin/import/clients" enctype="multipart/form-data"><?= csrf_field() ?>
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
