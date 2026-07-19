<?php
/** @var array<int, string> $errors */
/** @var array<string, string> $old */
?>

<?php if (!empty($errors)): ?>
    <div class="alert-error">
        <strong>Database Connection Failed:</strong>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="cv-card">
    <h1 class="cv-card__title">Database Setup</h1>
    <p style="color:var(--cv-text-secondary); margin-bottom: var(--cv-space-6);">Configure your site URL and database connection settings below. The installer will test the connection and migrate the database schema automatically.</p>

    <form method="post" action="/install/database"><?= csrf_field() ?>
        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label" for="app_url">Site URL</label>
            <input class="cv-input" id="app_url" name="app_url" value="<?= e($old['appUrl'] ?? 'http://localhost:8000') ?>" required>
        </div>
        
        <div style="display: grid; grid-template-columns: 3fr 1fr; gap: var(--cv-space-4); margin-bottom: var(--cv-space-4);">
            <div class="cv-field">
                <label class="cv-label" for="db_host">DB Host</label>
                <input class="cv-input" id="db_host" name="db_host" value="<?= e($old['host'] ?? '127.0.0.1') ?>" required>
            </div>
            <div class="cv-field">
                <label class="cv-label" for="db_port">DB Port</label>
                <input class="cv-input" id="db_port" name="db_port" value="<?= e($old['port'] ?? '3306') ?>" required>
            </div>
        </div>

        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label" for="db_database">Database Name</label>
            <input class="cv-input" id="db_database" name="db_database" value="<?= e($old['name'] ?? 'codevault') ?>" required>
        </div>

        <div class="cv-field" style="margin-bottom: var(--cv-space-4);">
            <label class="cv-label" for="db_username">Database Username</label>
            <input class="cv-input" id="db_username" name="db_username" value="<?= e($old['user'] ?? 'root') ?>" required>
        </div>

        <div class="cv-field" style="margin-bottom: var(--cv-space-6);">
            <label class="cv-label" for="db_password">Database Password</label>
            <input class="cv-input" id="db_password" type="password" name="db_password" value="">
        </div>

        <button class="cv-btn" type="submit">Test Connection &amp; Import Schema</button>
    </form>
</div>
