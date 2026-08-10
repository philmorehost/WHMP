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

        <!-- Localisation Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Timezone</h2>

            <div class="cv-field">
                <label class="cv-label" for="timezone">Application Timezone</label>
                <select class="cv-select" name="timezone" id="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $tz === $timezone ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">
                    Currently <strong><?= e(date('Y-m-d H:i')) ?></strong> (<?= e(date('T P')) ?>).
                    This drives every date the system produces — invoice dates, the daily automation time,
                    ticket timestamps and email dates. Set it to the timezone you actually operate in;
                    otherwise the daily automation runs at the wrong local hour.
                </span>
            </div>
        </div>

        <!-- Backups Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Automated Backups</h2>
            <p style="font-size:0.8rem;color:var(--cv-text-secondary);margin-top:0;">
                Each backup writes a full database dump plus a zip of the install, so these are large.
                The schedule is enforced by the cron — your server cron should still run every minute.
            </p>

            <div class="cv-field">
                <label class="cv-label">Run a Backup Every (hours)</label>
                <input class="cv-input" type="number" min="1" name="backup_frequency_hours" value="<?= e($backupFrequencyHours) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">24 = once a day, 168 = weekly. Minimum 1 hour.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Backups to Keep</label>
                <input class="cv-input" type="number" min="1" name="backup_keep_count" value="<?= e($backupKeepCount) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">
                    Older backups are deleted after a successful new one — never after a failed run, so a
                    bad backup can't destroy your good copies. Minimum 1.
                </span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Backup Log History (days)</label>
                <input class="cv-input" type="number" min="1" name="backup_log_retention_days" value="<?= e($backupLogRetentionDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">
                    How long run history (status/timing, not the backup files themselves) is kept. Pruned on every run. Minimum 1.
                </span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Automation Log History (days)</label>
                <input class="cv-input" type="number" min="1" name="cron_log_retention_days" value="<?= e($cronLogRetentionDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">
                    How long cron job run history is kept for the "Last 24 Hours" panel and daily report. Pruned once a day. Minimum 1.
                </span>
            </div>
        </div>

        <!-- Service Lifecycle Card -->
        <div class="cv-card">
            <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Expired Service Lifecycle</h2>
            <p style="font-size:0.8rem;color:var(--cv-text-secondary);margin-top:0;">
                How long an unpaid service survives after its due date. A suspended service is locked until the
                renewal invoice is paid — paying it restores the service automatically. Both automations run hourly
                and are <strong>off</strong> until you switch them on below.
            </p>

            <div class="cv-field">
                <label style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="auto_suspend_enabled" value="1" <?= $autoSuspendEnabled ? 'checked' : '' ?>>
                    <span>Automatically suspend unpaid services</span>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Disables the account on the control panel. Reversed automatically when the client pays.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Suspend After (days past due)</label>
                <input class="cv-input" type="number" min="0" name="suspension_grace_days" value="<?= e($suspensionGraceDays) ?>" required>
            </div>

            <div class="cv-field" style="border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-3);">
                <label style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="auto_terminate_enabled" value="1" <?= $autoTerminateEnabled ? 'checked' : '' ?>>
                    <span><strong>Automatically terminate expired services</strong></span>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-color-danger-600, #b42318);">
                    Irreversible. Termination destroys the account and its data on the remote server. Services whose
                    invoice is paid before the window elapses are never terminated.
                </span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Terminate VPS &amp; Dedicated Servers After (days past due)</label>
                <input class="cv-input" type="number" min="0" name="termination_grace_days_server" value="<?= e($terminationGraceDaysServer) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Applies to products of type VPS or Dedicated, which hold reserved capacity. Default 1 day — reclaimed at the top of the hour once the day has elapsed.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Terminate Shared Hosting &amp; Other Services After (days past due)</label>
                <input class="cv-input" type="number" min="0" name="termination_grace_days" value="<?= e($terminationGraceDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Applies to shared, reseller, email and any other product type. Default 60 days.</span>
            </div>

            <div class="cv-field" style="border-top:1px solid var(--cv-border-default);padding-top:var(--cv-space-3);">
                <label style="display:flex;align-items:center;gap:.5rem;">
                    <input type="checkbox" name="prune_terminated_enabled" value="1" <?= $pruneTerminatedEnabled ? 'checked' : '' ?>>
                    <span><strong>Automatically delete terminated services</strong></span>
                </label>
                <span style="font-size:0.75rem;color:var(--cv-color-danger-600, #b42318);">
                    Irreversible. Removes the service record itself once it has sat terminated this long — its
                    invoices and payment history are untouched, they simply stop showing a linked service.
                </span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Delete Terminated Services After (days)</label>
                <input class="cv-input" type="number" min="0" name="prune_terminated_days" value="<?= e($pruneTerminatedDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Runs daily. Default 90 days.</span>
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
                <label class="cv-label">Late Fee Grace Period (days)</label>
                <input class="cv-input" type="number" min="0" name="late_fee_grace_days" value="<?= e($lateFeeGraceDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Days after the due date before the late fee is added. 0 adds it as soon as the invoice is overdue. Overdue reminder emails are unaffected — they still go out from the due date.</span>
            </div>

            <div class="cv-field">
                <label class="cv-label">Auto-Cancel Unpaid Invoices After (days past due)</label>
                <input class="cv-input" type="number" min="0" name="auto_cancel_unpaid_days" value="<?= e($autoCancelUnpaidDays) ?>" required>
                <span style="font-size:0.75rem;color:var(--cv-text-secondary);">
                    Cancels unpaid invoices left this long past their due date — useful for clearing months of
                    historical invoices that will never be paid. <strong>0 disables it.</strong>
                    Invoices belonging to a service that is still active or suspended are never cancelled, because
                    the suspension and termination rules rely on them to detect arrears.
                </span>
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

                <div class="cv-field" style="grid-column:1 / -1;display:flex;align-items:flex-end;gap:var(--cv-space-3);flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                        <label class="cv-label">Send Test Email To</label>
                        <input class="cv-input" type="email" id="smtp-test-to" placeholder="you@example.com">
                    </div>
                    <button class="cv-btn" type="button" id="smtp-test-send" data-token="<?= e(csrf_token() ?? '') ?>" style="min-width:180px;">📨 Send Test Email</button>
                    <span id="smtp-test-result" style="font-size:0.85rem;"></span>
                </div>
            </div>
        </div>

    </div>

    <div style="margin-top:var(--cv-space-4);">
        <button class="cv-btn" type="submit">Save Settings</button>
    </div>

</form>