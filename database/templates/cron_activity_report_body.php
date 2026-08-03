<?php

declare(strict_types=1);

// Single source of truth for the cron activity report email body.
// Shared by the migration that seeds it and the one that re-asserts it, so
// an environment that already applied an older version of the template
// converges on this one instead of keeping stale markup forever.
//
// Placeholders are flat {{key}} only -- EmailDispatcher::render() is a
// strtr() with no {{#if}}/{{#each}} support, so variable-length content
// (the job error list) arrives pre-rendered as {{job_errors_section}}.
// No <html> shell: wrapInModernLayout() supplies branding around this.

return <<<HTML
<h2 style="color:#2c3e50;margin:0 0 8px;font-size:20px;">Daily Automation Report</h2>
<p style="color:#667085;margin:0 0 24px;font-size:13px;"><strong>Date:</strong> {{report_date}} &nbsp;&bull;&nbsp; <strong>Generated:</strong> {{generated_at}}</p>

{{status_banner}}

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:8px;margin-bottom:8px;">
  <tr>
    <td width="33%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Invoices</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{invoices_generated}}</div>
      <div style="font-size:11px;color:#98a2b3;">Generated</div>
    </td>
    <td width="33%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Late Fees</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{late_fees_added}}</div>
      <div style="font-size:11px;color:#98a2b3;">Added</div>
    </td>
    <td width="33%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Card Charges</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{credit_card_charges}}</div>
      <div style="font-size:11px;color:#98a2b3;">Captured</div>
    </td>
  </tr>
  <tr>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Overdue Reminders</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{overdue_reminders}}</div>
      <div style="font-size:11px;color:#98a2b3;">Sent</div>
    </td>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Domain Renewals</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{domain_renewals}}</div>
      <div style="font-size:11px;color:#98a2b3;">Processed</div>
    </td>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Suspensions</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{overdue_suspensions}}</div>
      <div style="font-size:11px;color:#98a2b3;">Suspended</div>
    </td>
  </tr>
  <tr>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Renewal Reminders</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{renewal_reminders}}</div>
      <div style="font-size:11px;color:#98a2b3;">Sent</div>
    </td>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Tickets Escalated</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{tickets_escalated}}</div>
      <div style="font-size:11px;color:#98a2b3;">Escalated</div>
    </td>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Tickets Closed</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{tickets_auto_closed}}</div>
      <div style="font-size:11px;color:#98a2b3;">Auto-Closed</div>
    </td>
  </tr>
  <tr>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Cancellations</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{cancellations}}</div>
      <div style="font-size:11px;color:#98a2b3;">Processed</div>
    </td>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Emails</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{emails_sent}}</div>
      <div style="font-size:11px;color:#98a2b3;">Sent</div>
    </td>
    <td align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Jobs Run</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{jobs_executed}}</div>
      <div style="font-size:11px;color:#98a2b3;">Executed</div>
    </td>
  </tr>
</table>

<h3 style="color:#2c3e50;margin:20px 0 8px;font-size:14px;">Pruning &amp; Cleanup</h3>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:8px;margin-bottom:8px;">
  <tr>
    <td width="25%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Services</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{services_terminated}}</div>
      <div style="font-size:11px;color:#98a2b3;">Terminated</div>
    </td>
    <td width="25%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Services</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{services_pruned}}</div>
      <div style="font-size:11px;color:#98a2b3;">Pruned</div>
    </td>
    <td width="25%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Invoices</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{invoices_auto_cancelled}}</div>
      <div style="font-size:11px;color:#98a2b3;">Auto-Cancelled</div>
    </td>
    <td width="25%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Domains</div>
      <div style="font-size:28px;font-weight:800;color:#2c3e50;line-height:1.2;">{{domains_pruned}}</div>
      <div style="font-size:11px;color:#98a2b3;">Pruned</div>
    </td>
  </tr>
</table>

{{job_errors_section}}

<p style="text-align:center;margin:28px 0 8px;">
  <a href="{{admin_url}}" style="background:#3b6fd4;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:700;display:inline-block;font-size:14px;">View Full Report</a>
</p>
<p style="color:#98a2b3;font-size:11px;margin-top:24px;padding-top:16px;border-top:1px solid #e9ecef;text-align:center;">Automated report from {{company_name}}. Change the schedule or turn this off under Automation Settings in your admin area.</p>
HTML;
