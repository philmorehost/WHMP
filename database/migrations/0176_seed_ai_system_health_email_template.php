<?php

declare(strict_types=1);

// Seeds the weekly AI system-health report template, emailed to the admin by
// the AiSystemHealthJob. It carries:
//   {{status_banner}}      — a clear "issues detected" / "all clear" strip.
//   {{errors_section}}     — the raw error log (cron_job_runs failures from the
//                            last 7 days + the PHP error-log tail), so the admin
//                            is always notified even if the AI call fails.
//   {{ai_analysis}}        — the AI's analysis of those errors (root cause,
//                            severity, recommended fixes).
//   {{implementation_plan}}— a concrete implementation plan the AI proposes when
//                            it sees a feature/enhancement that would prevent or
//                            mitigate the issues (else "No plan needed.").
//   {{ai_error}}           — set only when the AI was unavailable, so the email
//                            explains why the analysis is missing.
//
// {{key}} placeholders are substituted by EmailDispatcher::sendTemplate().
// Upsert so the automatic migrator that heals the schema on boot can re-apply.

return [
    'up' => [
        <<<'SQL'
        INSERT INTO email_templates (`key`, name, subject, body_html, created_at, updated_at) VALUES (
            'ai_system_report',
            'Weekly AI System Health Report',
            'Weekly AI System Health Report — {{report_date}}',
            '<p>Hello Admin,</p><p>{{status_banner}}</p>{{errors_section}}<h3 style="margin-top:24px;">🤖 AI Analysis &amp; Recommendations</h3>{{ai_analysis_block}}<h3 style="margin-top:24px;">🔧 Proposed Implementation Plan</h3><div style="background:#f7f8fa;border:1px solid #eef0f3;border-radius:8px;padding:16px;">{{implementation_plan}}</div><p style="margin-top:24px;">Review everything in the admin panel: <a href="{{admin_url}}">Open admin</a></p><p>This weekly scan is generated automatically by the system cron.</p><p>Thanks,<br>{{company_name}} System</p>',
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE body_html = VALUES(body_html), subject = VALUES(subject), updated_at = NOW()
        SQL,
    ],
];
