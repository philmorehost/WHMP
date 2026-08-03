<?php

declare(strict_types=1);

// Single source of truth for the "admin created an order for you" email
// body — sent by AdminOrderController::store() after a successful order,
// since no other path in the app currently emails a client when an
// order/invoice is created (HookPoints::INVOICE_CREATED has no listeners).
//
// Placeholders are flat {{key}} only -- EmailDispatcher::render() is a
// strtr() with no {{#if}}/{{#each}} support.
// No <html> shell: wrapInModernLayout() supplies branding around this.

return <<<HTML
<h2 style="color:#2c3e50;margin:0 0 8px;font-size:20px;">A new invoice is ready</h2>
<p style="color:#667085;margin:0 0 24px;font-size:14px;">Hi {{first_name}}, we've placed order #{{order_id}} for you and generated invoice #{{invoice_id}}.</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:8px;margin-bottom:8px;">
  <tr>
    <td width="50%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Total Due</div>
      <div style="font-size:24px;font-weight:800;color:#2c3e50;line-height:1.2;">{{invoice_total}}</div>
    </td>
    <td width="50%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Due Date</div>
      <div style="font-size:24px;font-weight:800;color:#2c3e50;line-height:1.2;">{{due_date}}</div>
    </td>
  </tr>
</table>

<p style="text-align:center;margin:28px 0 8px;">
  <a href="{{invoice_url}}" style="background:#3b6fd4;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:700;display:inline-block;font-size:14px;">View &amp; Pay Invoice</a>
</p>
<p style="color:#98a2b3;font-size:11px;margin-top:24px;padding-top:16px;border-top:1px solid #e9ecef;text-align:center;">Sent by {{company_name}}.</p>
HTML;
