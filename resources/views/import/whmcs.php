<?php
/** @var array{success: bool, message: string, imported: array<string, int>, errors: array<int, array{row: int, reason: string}>}|null $result */
/** @var array{success: bool, message: string, matched: int, not_found: int, empty_remote: int, errors: array<int, array{row: int, reason: string}>}|null $syncResult */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $runs */
$syncResult = $syncResult ?? null;
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">WHMCS Database Migrator</h1>
    <p><a href="/admin" style="color: var(--cv-color-brand-500); text-decoration: none; font-weight:600;">&larr; Back to dashboard</a></p>
</div>

<div class="cv-dashboard-grid--2-3">
    <div>
        <!-- Live Progress Card -->
        <div id="migration-progress-card" class="cv-card" style="display:none; margin-bottom:var(--cv-space-4); border-left: 4px solid var(--cv-color-brand-500);">
            <h2 class="cv-card__title">Migration in Progress</h2>
            <div style="background: var(--cv-border-default); border-radius: var(--cv-radius-full); height: 1.25rem; overflow: hidden; margin: var(--cv-space-3) 0; position: relative;">
                <div id="progress-bar" style="background: var(--cv-gradient-accent); width: 0%; height: 100%; transition: width 0.3s ease;"></div>
                <span id="progress-text" style="position: absolute; width: 100%; text-align: center; top: 0; left: 0; font-size: var(--cv-text-xs); font-weight: 700; color: #fff; line-height: 1.25rem;">0%</span>
            </div>
            <p id="progress-step" style="font-weight: 600; font-size: var(--cv-text-sm); margin-bottom: var(--cv-space-3);">Initializing...</p>
            
            <h3 style="font-family:'Hanken Grotesk',sans-serif; font-size: var(--cv-text-sm); margin-top: var(--cv-space-3);">Live Counters:</h3>
            <ul id="progress-stats" style="font-size: var(--cv-text-sm); display: grid; grid-template-columns: 1fr 1fr; gap: var(--cv-space-1); padding-left: var(--cv-space-4); margin-bottom: 0;">
                <li>Clients: <strong id="stat-clients">0</strong></li>
                <li>Servers: <strong id="stat-servers">0</strong></li>
                <li>Products: <strong id="stat-products">0</strong></li>
                <li>Services: <strong id="stat-services">0</strong></li>
                <li>Domains: <strong id="stat-domains">0</strong></li>
                <li>Invoices: <strong id="stat-invoices">0</strong></li>
                <li>Transactions: <strong id="stat-transactions">0</strong></li>
                <li>Currencies: <strong id="stat-currencies">0</strong></li>
                <li>Tickets: <strong id="stat-tickets">0</strong></li>
                <li>Promotions: <strong id="stat-promotions">0</strong></li>
            </ul>

            <div id="progress-errors-container" style="display:none; margin-top: var(--cv-space-3); border-top: 1px solid var(--cv-border-default); padding-top: var(--cv-space-2);">
                <h4 style="color:var(--cv-color-danger); margin: 0 0 var(--cv-space-1) 0;">Migration Errors / Warnings:</h4>
                <ul id="progress-errors" style="color:var(--cv-color-danger); font-size: var(--cv-text-xs); padding-left: var(--cv-space-4); max-height: 120px; overflow-y: auto;"></ul>
            </div>
        </div>

        <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
            <h2 class="cv-card__title">Configure Connection & Run</h2>
            
            <div id="static-error" class="cv-field-error" style="display:none; margin-bottom:var(--cv-space-3);"></div>
            <div id="static-success" class="cv-badge cv-badge--success" style="display:none; padding:var(--cv-space-3);margin-bottom:var(--cv-space-3);font-size:var(--cv-text-sm); width: 100%; box-sizing: border-box; text-align: center;"></div>

            <form id="migration-form" method="post" action="/admin/import/whmcs" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);"><?= csrf_field() ?>
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
                    <input class="cv-input" name="prefix" placeholder="e.g. whmcs_" value="">
                </div>
                
                <div class="cv-field" style="grid-column: 1 / -1; margin-bottom: var(--cv-space-1);">
                    <label style="display:flex; align-items:center; gap:var(--cv-space-2); font-weight:600; cursor:pointer;">
                        <input type="checkbox" name="overwrite" value="1">
                        Overwrite previous migration (wipes existing tables first)
                    </label>
                </div>
                
                <p style="grid-column: 1 / -1;color:var(--cv-text-secondary);font-size:var(--cv-text-sm); margin-top:0;">
                    Make sure the WHMP server can reach the specified WHMCS database host. All imports run inside a safe local transaction.
                </p>
                <button id="submit-btn" class="cv-btn" type="submit" style="grid-column: 1 / -1;">Connect and Migrate</button>
            </form>
        </div>

        <div class="cv-card" style="margin-bottom:var(--cv-space-4); border-top: 4px solid var(--cv-color-brand-500);">
            <h2 class="cv-card__title">Sync Client Passwords Only</h2>
            <p style="font-size:var(--cv-text-sm); color:var(--cv-text-secondary); margin-top:0;">
                For clients already migrated from WHMCS. Connects to the WHMCS database, reads <strong>only</strong>
                the client account table, and fills in the WHMCS password for any local account whose stored hash
                is empty/unusable. Services, invoices, domains and all other data are left untouched, and accounts
                that already have a working password are not overwritten.
            </p>

            <div id="sync-error" class="cv-field-error" style="display:none; margin-bottom:var(--cv-space-3);"></div>
            <div id="sync-success" class="cv-badge cv-badge--success" style="display:none; padding:var(--cv-space-3);margin-bottom:var(--cv-space-3);font-size:var(--cv-text-sm); width: 100%; box-sizing: border-box; text-align: center;"></div>

            <?php if ($syncResult !== null): ?>
                <?php if ($syncResult['success']): ?>
                    <div class="cv-badge cv-badge--success" style="display:block; padding:var(--cv-space-3);margin-bottom:var(--cv-space-3);font-size:var(--cv-text-sm); text-align:center;">
                        <?= e($syncResult['message']) ?> — <?= (int) $syncResult['matched'] ?> restored, <?= (int) $syncResult['not_found'] ?> local account(s) with no WHMCS match, <?= (int) $syncResult['empty_remote'] ?> with an empty WHMCS password (given an unusable fallback).
                    </div>
                <?php else: ?>
                    <div class="cv-field-error" style="margin-bottom:var(--cv-space-3);">
                        <?= e($syncResult['message']) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form id="password-sync-form" method="post" action="/admin/import/whmcs/passwords" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:var(--cv-space-3);"><?= csrf_field() ?>
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
                    <input class="cv-input" name="prefix" placeholder="e.g. whmcs_" value="">
                </div>

                <p style="grid-column: 1 / -1;color:var(--cv-text-secondary);font-size:var(--cv-text-sm); margin-top:0;">
                    Only local accounts with an empty password hash are updated. This does not run the full migration.
                </p>
                <button id="password-sync-btn" class="cv-btn" type="submit" style="grid-column: 1 / -1;">Sync Client Passwords</button>
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
                <tbody id="recent-runs-body">
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
