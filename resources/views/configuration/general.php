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

        <!-- Company Information Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Company Information</h2>

            <div class="cv-field">
                <label class="cv-label">Company Name</label>
                <input class="cv-input" type="text" name="company_name" value="<?= e($companyName ?? '') ?>" placeholder="Your Company Name">
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Displayed in invoices under "Pay To" section.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Billing Email</label>
                <input class="cv-input" type="email" name="company_email" value="<?= e($companyEmail ?? '') ?>" placeholder="billing@example.com">
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Displayed on invoices and sent payment receipts from this address.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Billing Department</label>
                <input class="cv-input" type="text" name="company_billing_dept" value="<?= e($companyDept ?? '') ?>" placeholder="Payments Dept.">
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Department name shown under company name on invoices.</span>
            </div>
        </div>

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

            <div class="cv-field" style="margin-top:var(--cv-space-3);">
                <label class="cv-label">Minimum Wallet Deposit ($)</label>
                <input class="cv-input" type="number" step="0.01" min="0" name="min_deposit" value="<?= e($minDeposit) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Smallest amount a client may add to their wallet.</span>
            </div>

            <div class="cv-field" style="margin-top:var(--cv-space-3);">
                <label class="cv-label">Maximum Wallet Deposit ($)</label>
                <input class="cv-input" type="number" step="0.01" min="0" name="max_deposit" value="<?= e($maxDeposit) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Largest amount a client may add in one deposit. Enter <strong>0</strong> for no upper limit. Like every stored amount these are in the base currency, and are converted to each client&rsquo;s own currency on the deposit form.</span>
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

        <!-- SMTP Mail Delivery Server Configuration Card -->
        <div class="cv-card" style="grid-column: 1 / -1;">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">📧 SMTP Mail Server Configuration (Inbox Delivery)</h2>
            <p style="font-size:0.85rem;color:var(--cv-text-secondary);margin-top:0;">Configure authenticating SMTP credentials to ensure system notifications, invoices, and campaign emails land in client and admin <strong>Inbox</strong> instead of Spam.</p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:var(--cv-space-3);">
                <div class="cv-field">
                    <label class="cv-label">SMTP Host Server</label>
                    <input class="cv-input" type="text" name="smtp_host" value="<?= e($smtpHost ?? '') ?>" placeholder="e.g. mail.philmorehost.com or smtp.gmail.com">
                    <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Leave blank or set to 'mail' to use local server sendmail daemon.</span>
                </div>

                <div class="cv-field">
                    <label class="cv-label">SMTP Port</label>
                    <input class="cv-input" type="number" name="smtp_port" value="<?= e($smtpPort ?? '587') ?>" placeholder="587 or 465">
                </div>

                <div class="cv-field">
                    <label class="cv-label">SMTP Username / Email</label>
                    <input class="cv-input" type="text" name="smtp_user" value="<?= e($smtpUser ?? '') ?>" placeholder="e.g. support@philmorehost.com">
                </div>

                <div class="cv-field">
                    <label class="cv-label">SMTP Password</label>
                    <input class="cv-input" type="password" name="smtp_pass" value="<?= e($smtpPass ?? '') ?>" placeholder="••••••••">
                </div>

                <div class="cv-field">
                    <label class="cv-label">SMTP Encryption</label>
                    <select class="cv-select" name="smtp_encryption">
                        <option value="tls" <?= ($smtpEncryption ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Port 587 / 25)</option>
                        <option value="ssl" <?= ($smtpEncryption ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                        <option value="none" <?= ($smtpEncryption ?? '') === 'none' ? 'selected' : '' ?>>None (Plaintext)</option>
                    </select>
                </div>

                <div class="cv-field">
                    <label class="cv-label">Sender From Email</label>
                    <input class="cv-input" type="email" name="smtp_from_email" value="<?= e($smtpFromEmail ?? '') ?>" placeholder="e.g. support@philmorehost.com">
                </div>

                <div class="cv-field">
                    <label class="cv-label">Sender From Name</label>
                    <input class="cv-input" type="text" name="smtp_from_name" value="<?= e($smtpFromName ?? '') ?>" placeholder="e.g. PhilmoreHost Support">
                </div>
            </div>
        </div>

    </div>

    <div style="margin-top:var(--cv-space-4);">
        <button class="cv-btn" type="submit">Save Settings</button>
    </div>

</form>