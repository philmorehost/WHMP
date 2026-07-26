<?php

declare(strict_types=1);

// Cron automation daily activity report — sent to admin summarizing all cron
// job activity from the past 24 hours (invoices, renewals, late fees, etc.)

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'cron_activity_report',
            'Cron Automation Activity Report',
            'Daily Automation Report for {{report_date}}',
            '<h2 style="color:#2c3e50;margin-bottom:20px;">Daily Automation Report</h2>
<p style="color:#555;margin-bottom:30px;"><strong>Date:</strong> {{report_date}}<br><strong>Report Generated:</strong> {{generated_at}}</p>

<div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:30px;">
  <h3 style="color:#2c3e50;margin-top:0;border-bottom:2px solid #e0e0e0;padding-bottom:10px;">Billing & Invoicing</h3>
  <table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Invoices Generated:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{invoices_generated}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Late Fees Added:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{late_fees_added}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Credit Card Charges Captured:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{credit_card_charges}}</td></tr>
    <tr><td style="padding:8px;"><strong>Auto-Charge Successes:</strong></td><td style="padding:8px;text-align:right;">{{auto_charge_success}}</td></tr>
  </table>
</div>

<div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:30px;">
  <h3 style="color:#2c3e50;margin-top:0;border-bottom:2px solid #e0e0e0;padding-bottom:10px;">Services & Domains</h3>
  <table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Domain Renewals Processed:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{domain_renewals}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Domain Renewal Notices Sent:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{renewal_notices}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Cancellation Requests Processed:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{cancellations}}</td></tr>
    <tr><td style="padding:8px;"><strong>Service Renewal Reminders Sent:</strong></td><td style="padding:8px;text-align:right;">{{renewal_reminders}}</td></tr>
  </table>
</div>

<div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:30px;">
  <h3 style="color:#2c3e50;margin-top:0;border-bottom:2px solid #e0e0e0;padding-bottom:10px;">Support & Communications</h3>
  <table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Overdue Reminders Sent:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{overdue_reminders}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Tickets Escalated:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{tickets_escalated}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Tickets Auto-Closed:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{tickets_auto_closed}}</td></tr>
    <tr><td style="padding:8px;"><strong>Email Campaigns Queued:</strong></td><td style="padding:8px;text-align:right;">{{email_campaigns}}</td></tr>
  </table>
</div>

<div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:30px;">
  <h3 style="color:#2c3e50;margin-top:0;border-bottom:2px solid #e0e0e0;padding-bottom:10px;">System Maintenance</h3>
  <table style="width:100%;border-collapse:collapse;">
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Backups Created:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{backups_created}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Integrity Checks Passed:</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{integrity_checks_passed}}</td></tr>
    <tr><td style="padding:8px;border-bottom:1px solid #e0e0e0;"><strong>Old Data Pruned (records):</strong></td><td style="padding:8px;border-bottom:1px solid #e0e0e0;text-align:right;">{{data_pruned}}</td></tr>
    <tr><td style="padding:8px;"><strong>Quotes Expired:</strong></td><td style="padding:8px;text-align:right;">{{quotes_expired}}</td></tr>
  </table>
</div>

{{#if job_errors}}
<div style="background:#fff3cd;padding:20px;border-radius:8px;margin-bottom:30px;border-left:4px solid #ffc107;">
  <h3 style="color:#856404;margin-top:0;">⚠️ Job Errors Detected</h3>
  <p style="color:#856404;margin-bottom:15px;">The following cron jobs encountered errors during this report period:</p>
  <ul style="color:#856404;">
    {{#each job_errors}}
    <li><strong>{{this.job_name}}:</strong> {{this.error_message}}</li>
    {{/each}}
  </ul>
</div>
{{/if}}

<p style="color:#888;font-size:12px;margin-top:30px;padding-top:20px;border-top:1px solid #e0e0e0;">This is an automated report from {{company_name}}. To adjust automation settings or disable this report, visit the admin panel at {{admin_url}}</p>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
