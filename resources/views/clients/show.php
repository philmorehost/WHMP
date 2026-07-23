<?php
/** @var array<string, mixed> $client */
/** @var string $tab */
/** @var array<int, array<string, mixed>> $contacts */
/** @var array<int, array<string, mixed>> $activity */
/** @var array<int, array<string, mixed>> $services */
/** @var array<int, array<string, mixed>> $invoices */
/** @var float $creditBalance */
/** @var array<int, array<string, mixed>> $creditLedger */
$tabs = ['summary' => 'Summary', 'profile' => 'Profile', 'contacts' => 'Contacts', 'billing' => 'Billing', 'log' => 'Log'];
$id = (int) $client['id'];
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <div style="display:flex;justify-content:space-between;align-items:start;flex-wrap:wrap;gap:var(--cv-space-3);">
        <div>
            <h1 class="cv-card__title"><?= e($client['first_name'] . ' ' . $client['last_name']) ?></h1>
            <p style="color:var(--cv-text-secondary);"><?= e($client['email']) ?></p>
        </div>
        <div style="display:flex;gap:var(--cv-space-2);flex-wrap:wrap;">
            <form method="post" action="/admin/clients/<?= $id ?>/login-as"><?= csrf_field() ?>
                <button class="cv-btn" type="submit" style="background:var(--cv-color-brand-500);color:#ffffff;border:none;" title="Login as Client">🔑<span class="cv-hide-mobile"> Login as Client</span></button>
            </form>
            <a class="cv-btn cv-btn--secondary" href="/admin/clients/<?= $id ?>/edit" title="Edit">✏️<span class="cv-hide-mobile"> Edit</span></a>
            <?php if ($client['status'] !== 'closed'): ?>
            <form method="post" action="/admin/clients/<?= $id ?>/close"><?= csrf_field() ?>
                <button class="cv-btn cv-btn--danger" type="submit" title="Close Account">🛑<span class="cv-hide-mobile"> Close Account</span></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <p><a href="/admin/clients">&larr; Back to clients</a></p>
</div>

<div class="cv-card" style="margin-bottom:var(--cv-space-4); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:var(--cv-space-4);">
    <div>
        <span style="font-size:var(--cv-text-xs); color:var(--cv-text-secondary); display:block; text-transform:uppercase; font-weight:600;">Account Credit Balance</span>
        <span style="font-size:1.5rem; font-weight:800; color:#10b981;"><?= e($client['currency_symbol'] ?? '$') ?><?= number_format($creditBalance, 2) ?></span>
    </div>
    <form method="post" action="/admin/clients/<?= $id ?>/credit" style="display:flex; gap:var(--cv-space-2); align-items:flex-end; flex-wrap:wrap; margin:0; flex:1; max-width:600px; width:100%;">
        <?= csrf_field() ?>
        <div style="flex:1; min-width:80px;">
            <label class="cv-label" style="font-size:var(--cv-text-2xs); margin-bottom:4px; display:block;">Action</label>
            <select class="cv-input" name="action" style="width:100%; padding:6px 8px; font-size:var(--cv-text-sm); height:34px; box-sizing:border-box;">
                <option value="credit">Credit</option>
                <option value="debit">Debit</option>
            </select>
        </div>
        <div style="flex:1.5; min-width:110px;">
            <label class="cv-label" style="font-size:var(--cv-text-2xs); margin-bottom:4px; display:block;">Amount</label>
            <div style="position:relative; width:100%;">
                <span style="position:absolute; left:0.75rem; top:50%; transform:translateY(-50%); color:var(--cv-text-secondary); font-size:var(--cv-text-sm);"><?= e($client['currency_symbol'] ?? '$') ?></span>
                <input class="cv-input" type="number" step="0.01" name="amount" style="width:100%; padding-left:1.75rem; padding-top:6px; padding-bottom:6px; font-size:var(--cv-text-sm); height:34px; box-sizing:border-box;" required>
            </div>
        </div>
        <div style="flex:2.5; min-width:160px;">
            <label class="cv-label" style="font-size:var(--cv-text-2xs); margin-bottom:4px; display:block;">Reason</label>
            <input class="cv-input" name="reason" style="width:100%; padding:6px 8px; font-size:var(--cv-text-sm); height:34px; box-sizing:border-box;" required>
        </div>
        <div>
            <button class="cv-btn" type="submit" style="padding:8px 16px; font-size:var(--cv-text-xs); height:34px; box-sizing:border-box;">Submit</button>
        </div>
    </form>
</div>

<div class="cv-tabs" style="margin-bottom:var(--cv-space-4);" role="tablist">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="cv-tab" href="/admin/clients/<?= $id ?>?tab=<?= $key ?>" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'summary'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Summary</h2>
        <p><strong>Status:</strong>
            <?php if ($client['status'] === 'active'): ?>
                <span class="cv-badge cv-badge--success">Active</span>
            <?php elseif ($client['status'] === 'closed'): ?>
                <span class="cv-badge cv-badge--danger">Closed</span>
            <?php else: ?>
                <span class="cv-badge cv-badge--neutral">Inactive</span>
            <?php endif; ?>
        </p>
        <p><strong>Group:</strong> <?= e((string) ($client['group_name'] ?? 'None')) ?></p>
        <p><strong>Company:</strong> <?= e((string) ($client['company_name'] ?? '-')) ?></p>
        <p><strong>Client since:</strong> <?= e($client['created_at']) ?></p>
        <p><strong>Credit balance:</strong> <?= e($client['currency_symbol'] ?? '$') ?><?= number_format($creditBalance, 2) ?></p>
        <p><strong>Active services:</strong> <?= count(array_filter($services, fn ($s) => $s['status'] === 'active')) ?></p>
        <p style="color:var(--cv-text-secondary);font-size:var(--cv-text-sm);">Domains and tickets will appear here once those engines land (R7-R8).</p>
    </div>
<?php elseif ($tab === 'profile'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Profile</h2>
        <table class="cv-table">
            <tr><th>Email</th><td><?= e($client['email']) ?></td></tr>
            <tr><th>Phone</th><td><?= e((string) ($client['phone'] ?? '-')) ?></td></tr>
            <tr><th>Address</th><td><?= e((string) ($client['address1'] ?? '')) ?> <?= e((string) ($client['address2'] ?? '')) ?></td></tr>
            <tr><th>City / State</th><td><?= e((string) ($client['city'] ?? '-')) ?> / <?= e((string) ($client['state'] ?? '-')) ?></td></tr>
            <tr><th>Postcode</th><td><?= e((string) ($client['postcode'] ?? '-')) ?></td></tr>
            <tr><th>Country</th><td><?= e((string) ($client['country'] ?? '-')) ?></td></tr>
            <tr><th>Notes</th><td><?= nl2br(e((string) ($client['notes'] ?? '-'))) ?></td></tr>
        </table>
    </div>
<?php elseif ($tab === 'contacts'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Contacts / Sub-accounts</h2>
        <table class="cv-table">
            <thead><tr><th>Name</th><th>Email</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($contacts as $contact): ?>
                <tr>
                    <td><?= e($contact['name']) ?></td>
                    <td><?= e($contact['email']) ?></td>
                    <td>
                        <form method="post" action="/admin/clients/<?= $id ?>/contacts/<?= (int) $contact['id'] ?>/delete"><?= csrf_field() ?>
                            <button class="cv-btn cv-btn--danger" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($contacts === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No sub-accounts yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <form method="post" action="/admin/clients/<?= $id ?>/contacts" style="margin-top:var(--cv-space-4);display:flex;gap:var(--cv-space-2);align-items:end;"><?= csrf_field() ?>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Name</label>
                <input class="cv-input" name="name">
            </div>
            <div class="cv-field" style="margin-bottom:0;">
                <label class="cv-label">Email</label>
                <input class="cv-input" type="email" name="email">
            </div>
            <button class="cv-btn" type="submit">Add Contact</button>
        </form>
    </div>
<?php elseif ($tab === 'billing'): ?>
    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Services</h2>
        <table class="cv-table">
            <thead><tr><th>Product</th><th>Cycle</th><th>Amount</th><th>Next Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($services as $service): ?>
                <tr>
                    <td><?= e($service['product_name']) ?></td>
                    <td><?= e($service['billing_cycle']) ?></td>
                    <td><?= e($currency['symbol'] ?? '$') ?><?= number_format((float) $service['amount'], 2) ?></td>
                    <td><?= e($service['next_due_date']) ?></td>
                    <td><span class="cv-badge cv-badge--neutral"><?= e($service['status']) ?></span></td>
                    <td><a class="cv-btn cv-btn--secondary" href="/admin/services/<?= (int) $service['id'] ?>">Manage</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($services === []): ?>
                <tr><td colspan="6" style="color:var(--cv-text-secondary);">No services yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="cv-card" style="margin-bottom:var(--cv-space-4);">
        <h2 class="cv-card__title">Invoices</h2>
        <table class="cv-table">
            <thead><tr><th>#</th><th>Total</th><th>Due</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td><a href="/admin/invoices/<?= (int) $invoice['id'] ?>">INV-<?= (int) $invoice['id'] ?></a></td>
                    <td><?= e($currency['symbol'] ?? '$') ?><?= number_format((float) $invoice['total'], 2) ?></td>
                    <td><?= e($invoice['due_date']) ?></td>
                    <td><span class="cv-badge <?= $invoice['status'] === 'paid' ? 'cv-badge--success' : 'cv-badge--danger' ?>"><?= e($invoice['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invoices === []): ?>
                <tr><td colspan="4" style="color:var(--cv-text-secondary);">No invoices yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if (isset($billingPagination) && $billingPagination['total'] > 10): ?>
            <?php
            $totalPages = (int) ceil($billingPagination['total'] / 10);
            $currentPage = $billingPagination['page'];
            ?>
            <div style="display:flex; justify-content:center; gap:var(--cv-space-2); margin-top:var(--cv-space-4);">
                <?php if ($currentPage > 1): ?>
                    <a class="cv-btn cv-btn--secondary" href="/admin/clients/<?= $id ?>?tab=billing&billing_page=<?= $currentPage - 1 ?>" style="padding:4px 8px; font-size:var(--cv-text-xs); text-decoration:none;">&laquo; Prev</a>
                <?php endif; ?>
                <span style="font-size:var(--cv-text-xs); align-self:center; color:var(--cv-text-secondary);">Page <?= $currentPage ?> of <?= $totalPages ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a class="cv-btn cv-btn--secondary" href="/admin/clients/<?= $id ?>?tab=billing&billing_page=<?= $currentPage + 1 ?>" style="padding:4px 8px; font-size:var(--cv-text-xs); text-decoration:none;">Next &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title">Credit History / Ledger</h2>
        <table class="cv-table">
            <thead><tr><th>Date</th><th>Amount</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($creditLedger as $entry): ?>
                <tr>
                    <td><?= e($entry['created_at']) ?></td>
                    <td><?= (float) $entry['amount'] >= 0 ? '+' : '' ?><?= e($client['currency_symbol'] ?? '$') ?><?= number_format((float) $entry['amount'], 2) ?></td>
                    <td><?= e($entry['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($creditLedger === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No credit activity yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php elseif ($tab === 'log'): ?>
    <div class="cv-card">
        <h2 class="cv-card__title">Activity Log</h2>
        <table class="cv-table">
            <thead><tr><th>Time</th><th>Action</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($activity as $entry): ?>
                <tr>
                    <td><?= e($entry['created_at']) ?></td>
                    <td><?= e($entry['action']) ?></td>
                    <td><?= e($entry['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($activity === []): ?>
                <tr><td colspan="3" style="color:var(--cv-text-secondary);">No activity recorded yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
