<?php
/** @var string $lateFeePercentage */
/** @var string $newOrderDueDays */
/** @var bool $allowCheckoutNotes */
/** @var bool $maintenanceMode */
/** @var string $minCreditBalance */
/** @var string $minAffiliatePayout */
/** @var bool $ticketRatingEnabled */
/** @var bool $randomCpanelUsernames */
/** @var bool $saved */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">General System Settings</h1>
    <p style="color:var(--cv-text-secondary);">Configure billing rules, WHMCS-style options, maintenance mode, and support settings.</p>
</div>

<?php if ($saved): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;color:#10b981;margin-bottom:var(--cv-space-4);">
        ✔ General settings updated successfully.
    </div>
<?php endif; ?>

<form method="post" action="/admin/settings/general"><?= csrf_field() ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:var(--cv-space-4);">

        <!-- Billing & Invoices Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Invoice &amp; Billing Rules</h2>
            
            <div class="cv-field">
                <label class="cv-label">Overdue Invoice Late Fee Percentage (%)</label>
                <input class="cv-input" type="number" step="0.01" min="0" name="late_fee_percentage" value="<?= e($lateFeePercentage) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Markup percentage automatically added to overdue invoices (e.g. 5 = 5%).</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">New Order Payment Due Days</label>
                <input class="cv-input" type="number" min="0" name="new_order_due_days" value="<?= e($newOrderDueDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Number of days allowed for payment of new orders before invoice becomes overdue.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Minimum Credit Balance / Deposit ($)</label>
                <input class="cv-input" type="number" step="0.01" min="0" name="min_credit_balance" value="<?= e($minCreditBalance) ?>">
            </div>
        </div>

        <!-- Checkout & System Mode Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Checkout &amp; System Toggles</h2>

            <div class="cv-field">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="checkbox" name="allow_checkout_notes" value="1" <?= $allowCheckoutNotes ? 'checked' : '' ?>>
                    <strong>Allow Notes on Checkout</strong>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);display:block;margin-left:1.5rem;">Displays an optional order instructions/notes text area on the checkout review page.</span>
            </div>

            <div class="cv-field" style="margin-top:var(--cv-space-3);">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= $maintenanceMode ? 'checked' : '' ?>>
                    <strong style="color:#ef4444;">Enable System Maintenance Mode</strong>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);display:block;margin-left:1.5rem;">Bounces all non-admin visitors to a 530 System Maintenance page. Staff &amp; admin login paths bypass this.</span>
            </div>
        </div>

        <!-- Affiliates & Support Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Affiliates &amp; Support Ticket Settings</h2>

            <div class="cv-field">
                <label class="cv-label">Minimum Affiliate Payout Amount ($)</label>
                <input class="cv-input" type="number" step="0.01" min="0" name="min_affiliate_payout" value="<?= e($minAffiliatePayout) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Minimum commission balance required before an affiliate can request a payout.</span>
            </div>

            <div class="cv-field" style="margin-top:var(--cv-space-3);">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="checkbox" name="ticket_rating_enabled" value="1" <?= $ticketRatingEnabled ? 'checked' : '' ?>>
                    <strong>Allow Staff Ticket Reply Ratings</strong>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);display:block;margin-left:1.5rem;">Allows clients to give 1 to 5 star ratings on individual staff responses in support tickets.</span>
            </div>
        </div>

        <!-- Provisioning & cPanel Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Provisioning &amp; cPanel Account Settings</h2>

            <div class="cv-field">
                <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                    <input type="checkbox" name="random_cpanel_usernames" value="1" <?= $randomCpanelUsernames ? 'checked' : '' ?>>
                    <strong>Use Random Usernames for cPanel Accounts</strong>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);display:block;margin-left:1.5rem;">When enabled, generates random 8-character cPanel usernames (WHMCS standard). When disabled, uses the first 6 letters of the domain name.</span>
            </div>
        </div>

    </div>

    <div style="margin-top:var(--cv-space-4);">
        <button class="cv-btn" type="submit">Save Settings</button>
    </div>

</form>