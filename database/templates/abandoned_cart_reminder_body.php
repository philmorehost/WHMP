<?php

declare(strict_types=1);

// Single source of truth for the "you left items in your cart" recovery
// email body — sent by AbandonedCartJob when a cart has sat untouched for
// the configured idle threshold.
//
// Placeholders are flat {{key}} only -- EmailDispatcher::render() is a
// strtr() with no {{#if}}/{{#each}} support.
// No <html> shell: wrapInModernLayout() supplies branding around this.

return <<<HTML
<h2 style="color:#2c3e50;margin:0 0 8px;font-size:20px;">Your cart is still waiting</h2>
<p style="color:#667085;margin:0 0 24px;font-size:14px;">Hi {{first_name}}, you have {{item_count}} item(s) totalling {{total}} waiting in your cart — we saved it so you don't lose it.</p>

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:separate;border-spacing:8px;margin-bottom:8px;">
  <tr>
    <td width="50%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Items In Cart</div>
      <div style="font-size:24px;font-weight:800;color:#2c3e50;line-height:1.2;">{{item_count}}</div>
    </td>
    <td width="50%" align="center" style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:16px 8px;">
      <div style="font-size:12px;color:#667085;font-weight:600;">Order Total</div>
      <div style="font-size:24px;font-weight:800;color:#2c3e50;line-height:1.2;">{{total}}</div>
    </td>
  </tr>
</table>

<p style="text-align:center;margin:28px 0 8px;">
  <a href="{{checkout_url}}" style="background:#3b6fd4;color:#ffffff;text-decoration:none;padding:12px 26px;border-radius:6px;font-weight:700;display:inline-block;font-size:14px;">Return To Checkout</a>
</p>
<p style="color:#98a2b3;font-size:11px;margin-top:24px;padding-top:16px;border-top:1px solid #e9ecef;text-align:center;">Sent by {{company_name}}.</p>
HTML;
