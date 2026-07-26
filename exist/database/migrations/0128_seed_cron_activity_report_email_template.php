<?php

declare(strict_types=1);

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'cron_activity_report',
            'Daily Automation Activity Report',
            '[{{company_name}}] Daily Automation Report - {{date}}',
            '<div style="font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; color: #333; line-height: 1.6;">

<p>Hi Admin,</p>

<p>Here is your daily automation activity report for {{company_name}} ({{period_start}} - {{period_end}}):</p>

<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
  <tr style="background-color: #f5f5f5; border-bottom: 2px solid #ddd;">
    <td style="padding: 12px; text-align: left; font-weight: bold; border: 1px solid #ddd;">Metric</td>
    <td style="padding: 12px; text-align: center; font-weight: bold; border: 1px solid #ddd;">Count</td>
  </tr>
  <tr>
    <td style="padding: 12px; border: 1px solid #ddd;">Invoices Generated</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{invoices_generated}}</td>
  </tr>
  <tr style="background-color: #f9f9f9;">
    <td style="padding: 12px; border: 1px solid #ddd;">Late Fees Added</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{late_fees_added}}</td>
  </tr>
  <tr>
    <td style="padding: 12px; border: 1px solid #ddd;">Domain Renewals</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{domain_renewals}}</td>
  </tr>
  <tr style="background-color: #f9f9f9;">
    <td style="padding: 12px; border: 1px solid #ddd;">Tickets Created</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{tickets_created}}</td>
  </tr>
  <tr>
    <td style="padding: 12px; border: 1px solid #ddd;">Tickets Resolved</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{tickets_resolved}}</td>
  </tr>
  <tr style="background-color: #f9f9f9;">
    <td style="padding: 12px; border: 1px solid #ddd;">Services Created</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{services_created}}</td>
  </tr>
  <tr>
    <td style="padding: 12px; border: 1px solid #ddd;">Emails Sent</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{email_sent_count}}</td>
  </tr>
  <tr style="background-color: #f9f9f9;">
    <td style="padding: 12px; border: 1px solid #ddd;">Payments Captured</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{payment_captured_count}}</td>
  </tr>
  <tr>
    <td style="padding: 12px; border: 1px solid #ddd;">Backups Completed</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{backups_completed}}</td>
  </tr>
  <tr style="background-color: #f9f9f9;">
    <td style="padding: 12px; border: 1px solid #ddd;">Cancellations Processed</td>
    <td style="padding: 12px; text-align: center; border: 1px solid #ddd; font-weight: bold;">{{cancellations_processed}}</td>
  </tr>
</table>

<p><a href="{{admin_url}}/system/cron" style="color: #007bff; text-decoration: none; font-weight: bold;">View Full Cron Status</a></p>

<p>Best regards,<br>{{company_name}} Automation System</p>

</div>',
            NOW(),
            NOW()
        )
        SQL,
    ],
];
