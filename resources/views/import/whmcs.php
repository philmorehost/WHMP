<?php
/** @var array{success: bool, message: string, imported: array<string, int>, errors: array<int, array{row: int, reason: string}>}|null $result */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $runs */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">WHMCS Database Migrator</h1>
    <p><a href="/admin">&larr; Back to dashboard</a></p>
</div>

<div class="cv-dashboard-grid--2-3">
    <div>
        <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
            <h2 class="cv-card__title">Configure Connection & Run</h2>
            
            <?php if (!empty($error)): ?>
                <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($result !== null): ?>
                <div class="cv-badge cv-badge--success" style="padding:var(--cv-space-3);margin-bottom:var(--cv-space-3);display:block;font-size:var(--cv-text-sm);">
                    <?= e($result['message']) ?>
                </div>
                <h3>Migration Stats:</h3>
                <ul>
                    <li>Clients: <strong><?= (int) $result['imported']['clients'] ?></strong></li>
                    <li>Servers: <strong><?= (int) $result['imported']['servers'] ?></strong></li>
                    <li>Products: <strong><?= (int) $result['imported']['products'] ?></strong></li>
                    <li>Services: <strong><?= (int) $result['imported']['services'] ?></strong></li>
                    <li>Domains: <strong><?= (int) $result['imported']['domains'] ?></strong></li>
                    <li>Invoices: <strong><?= (int) $result['imported']['invoices'] ?></strong></li>
                    <li>Transactions: <strong><?= (int) $result['imported']['transactions'] ?></strong></li>
                    <li>Currencies: <strong><?= (int) $result['imported']['currencies'] ?></strong></li>
                    <li>Tax Rules: <strong><?= (int) $result['imported']['tax_rules'] ?></strong></li>
                    <li>Contacts: <strong><?= (int) $result['imported']['contacts'] ?></strong></li>
                    <li>Configurable Options: <strong><?= (int) $result['imported']['configurable_options'] ?></strong></li>
                    <li>Departments: <strong><?= (int) $result['imported']['departments'] ?></strong></li>
                    <li>Tickets: <strong><?= (int) $result['imported']['tickets'] ?></strong></li>
                    <li>Promotions: <strong><?= (int) $result['imported']['promotions'] ?></strong></li>
                </ul>

                <?php if ($result['errors'] !== []): ?>
                    <h3 style="color:var(--cv-color-danger);margin-top:var(--cv-space-3);">Errors / Warnings:</h3>
                    <ul style="color:var(--cv-color-danger);font-size:var(--cv-text-sm);">
                        <?php foreach ($result['errors'] as $err): ?>
                            <li><?= e($err['reason']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="/admin/import/whmcs" style="display:grid;grid-template-columns:1fr 1fr;gap:var(--cv-space-3);"><?= csrf_field() ?>
                <div class="cv-field">
                    <label class="cv-label">Database Host</label>
                    <input class="cv-input" name="host" value="127.0.0.1" required>
                </div>
                <div class="cv-field">
                    <label class="cv-label">Database Port</label>
                    <input class="cv-input" type="number" name="port" value="3306" required>
                </div>
                <div class="cv-field">
                    <label class="cv-label">Database Name</label>
                    <input class="cv-input" name="database" placeholder="whmcs_db" required>
                </div>
                <div class="cv-field">
                    <label class="cv-label">Database Username</label>
                    <input class="cv-input" name="username" placeholder="root" required>
                </div>
                <div class="cv-field">
                    <label class="cv-label">Database Password</label>
                    <input class="cv-input" type="password" name="password" placeholder="••••••••">
                </div>
                <div class="cv-field">
                    <label class="cv-label">Table Prefix (optional)</label>
                    <input class="cv-input" name="prefix" placeholder="tbl" value="tbl">
                </div>
                
                <p style="grid-column:span 2;color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">
                    Make sure the WHMP server can reach the specified WHMCS database host. All imports run inside a safe local transaction.
                </p>
                <button class="cv-btn" type="submit" style="grid-column:span 2;">Connect and Migrate</button>
            </form>
        </div>
    </div>

    <div>
        <div class="cv-card">
            <h2 class="cv-card__title">Recent WHMCS Imports</h2>
            <table class="cv-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Target</th>
                        <th>Imported</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runs as $run): ?>
                        <tr>
                            <td><?= e($run['created_at']) ?></td>
                            <td><?= e($run['filename']) ?></td>
                            <td><span class="cv-badge cv-badge--success"><?= (int) $run['imported_count'] ?> rows</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($runs === []): ?>
                        <tr>
                            <td colspan="3" style="color:var(--cv-text-secondary);text-align:center;">No migrations run yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
