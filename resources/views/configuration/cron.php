<?php
/** @var bool $reportEnabled */
/** @var string $reportTime */
/** @var string $reportEmail */
/** @var bool $saved */
?>
<div class="cv-card" style="margin-bottom:var(--cv-space-4);">
    <h1 class="cv-card__title">Cron & Automation Settings</h1>
    <p style="color:var(--cv-text-secondary);">Configure automated task reporting and cron job notifications sent to administrators.</p>
</div>

<?php if ($saved): ?>
    <div class="cv-card" style="background:rgba(16,185,129,0.1);border-color:#10b981;color:#10b981;margin-bottom:var(--cv-space-4);">
        ✔ Cron automation settings updated successfully.
    </div>
<?php endif; ?>

<form method="post" action="/admin/settings/cron"><?= csrf_field() ?>

    <div class="cv-card">
        <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">Daily Activity Report</h2>
        <p style="color:var(--cv-text-secondary);margin-bottom:var(--cv-space-3);">Automated cron jobs run billing, domain renewals, ticket escalations, and other maintenance tasks. A daily activity report summarizes everything that happened in the past 24 hours and emails it to the configured recipient.</p>

        <div class="cv-field" style="margin-bottom:var(--cv-space-3);">
            <label style="display:flex;align-items:center;gap:var(--cv-space-2);cursor:pointer;">
                <input type="checkbox" name="activity_report_enabled" value="1" <?= $reportEnabled ? 'checked' : '' ?>>
                <strong>Enable Daily Activity Report</strong>
            </label>
            <span style="font-size:0.75rem;color:var(--cv-text-secondary);display:block;margin-left:1.5rem;">When enabled, a summary of all cron job activity will be emailed daily to the configured recipient.</span>
        </div>

        <div class="cv-field">
            <label class="cv-label">Report Send Time</label>
            <input class="cv-input" type="time" name="activity_report_time" value="<?= e($reportTime) ?>" style="max-width:150px;">
            <span style="font-size:0.75rem;color:var(--cv-text-secondary);">What time of day (24-hour format) to send the daily report. The cron job runs every minute, so the report will be sent at the closest minute to this time.</span>
        </div>

        <div class="cv-field">
            <label class="cv-label">Report Recipient Email</label>
            <input class="cv-input" type="email" name="activity_report_email" value="<?= e($reportEmail) ?>" placeholder="admin@example.com">
            <span style="font-size:0.75rem;color:var(--cv-text-secondary);">Email address where the daily activity report should be sent. Leave blank to skip sending (report generation will be skipped entirely).</span>
        </div>
    </div>

    <div class="cv-card">
        <h2 class="cv-card__title" style="margin-bottom:var(--cv-space-3);">What's Included in the Report</h2>
        <p style="color:var(--cv-text-secondary);margin-bottom:var(--cv-space-2);">The daily activity report shows statistics from the past 24 hours for:</p>
        <ul style="color:var(--cv-text-secondary);margin-left:1.5rem;line-height:1.7;">
            <li><strong>Billing:</strong> Invoices generated, late fees added, successful auto-charges, credit card charges</li>
            <li><strong>Services &amp; Domains:</strong> Domain renewals processed, renewal notices and reminders sent, service cancellations</li>
            <li><strong>Support:</strong> Overdue reminders sent, tickets escalated and auto-closed, email marketing campaigns queued</li>
            <li><strong>System Maintenance:</strong> Backups created, integrity checks passed, old data pruned, expired quotes</li>
        </ul>
    </div>

    <button type="submit" class="cv-btn" style="margin-top:var(--cv-space-4);">Save Cron Settings</button>
</form>
