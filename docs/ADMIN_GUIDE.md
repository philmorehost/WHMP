# CodeVault Admin Guide

A day-to-day operations reference for whoever runs the admin panel (`/admin`). For setup/deployment, see the root [`README.md`](../README.md).

## Core billing

- **Orders / Invoices / Services** — orders land `pending`; accept them from the Orders queue. Invoices generate automatically ahead of a service's due date (recurring billing cron) or on-demand (billable items, upgrade/downgrade proration).
- **Products** — organized into Product Groups, each product has per-billing-cycle pricing (Products → a product → Pricing) and optional Configurable Options. A product flagged `is_upsell` shows as a cart add-on suggestion.
- **Tax Rules** — country/state-specific rates; a client's tax is resolved by their billing address, `tax_exempt` clients are never taxed regardless of a matching rule.
- **Payment Gateways** — the built-in Manual gateway (bank transfer, staff mark-paid) always works with zero configuration; add real gateway modules by implementing the `GatewayModule` SDK interface.
- **Fraud Protection** — every order is scored on placement by all registered `FraudModule`s (max score wins, not average); a hold routes it to the fraud review queue instead of the normal pending queue.

## Multi-currency

**Admin → Currencies.** Add a currency with a code/symbol/exchange rate (rates are set manually here — there's no live FX feed wired up). The client-facing currency switcher (top of every storefront page, once 2+ currencies exist) lets a client browse in any active currency; their choice is locked onto the order/invoice at checkout time so historical documents never re-price themselves when you later update a rate.

## Localization

**Admin → Languages.** Ships with English (default), Spanish, and Arabic (RTL) pre-seeded. Only *active* languages appear in the switcher; the default language can't be deactivated. **Admin → Languages → Edit Strings** on a language lets you override individual translated strings without touching the file catalogs in `resources/lang/`.

Scope note: the translation catalogs cover the public storefront, cart/checkout, and shared header/footer chrome — not the entire admin panel or every client-area page. Extending coverage means adding keys to `resources/lang/en.php` (and the other catalogs) and calling `$t->get('your.key')` in the relevant view.

## Theme

**Admin → Theme.** Set a brand name, an optional logo URL, and a primary color (hex) — applies to both the client area and the admin panel itself (buttons, links, active-nav highlighting).

## Notifications

**Admin → Notifications.** Add a Slack incoming-webhook or a generic outbound webhook, subscribe it to one or more events (new order, invoice paid, new ticket, order held for fraud). Generic webhooks are HMAC-SHA256 signed (`X-CodeVault-Signature` header) when you set a secret, so the receiving endpoint can verify authenticity.

## Marketing campaigns

**Admin → Campaigns.** Compose a subject + HTML body, target either every active client or one client group, and send. Each recipient gets a unique open-tracking pixel; the campaign's detail page shows send/open status per recipient. Campaigns go through the same async email pipeline (queue + `email_log`) as every other outbound email — check **Admin → Email Log** (via a client's profile, or the raw `email_log` table) if a send looks stuck.

Lifecycle emails run automatically via cron — currently just the service renewal reminder (7 days before `next_due_date`, once per billing cycle). Dunning (overdue-invoice reminders) is a separate, older engine — see the Billing section.

## Backups

**Admin → Backups.** "Run Backup Now" triggers an immediate full DB dump (plain SQL, no `mysqldump` dependency) + a zip of the application files, saved to `storage/backups/`. Also runs automatically once a day via cron. The history table shows status/size/duration for every run, including failures with the error message.

## Support

- **Tickets** — auto-escalate and auto-close on configurable timers (cron-driven); a resolved ticket can convert to a billable item.
- **Mail Piping** — inbound email-to-ticket via IMAP (spec-correct; live delivery unverified in environments without `ext-imap`).
- **Knowledgebase** — public search includes an AI-synthesized answer (DeepSeek) drawn only from the matching articles' content, when a `DEEPSEEK_API_KEY` is configured.

## Staff / Security

- **Staff / Roles** — granular permission matrix; every admin controller checks a specific permission constant (see `core/Staff/PermissionRegistry.php`) before allowing an action.
- **BruteGuard** (Admin → BruteGuard) — IP/account lockout on repeated failed logins, with a GeoIP-aware allow/deny list.
- Session cookies are `HttpOnly` + `SameSite=Lax`; every response carries a locked-down CSP (`script-src 'self'`, no inline scripts anywhere in the app) plus the standard `X-Frame-Options`/`X-Content-Type-Options`/`Referrer-Policy` headers.

## Reports & Affiliates

- **Reports** — revenue/order/ticket dashboards.
- **Affiliates** — referral tracking + commission accrual on paid invoices; payout requests go through an admin approval step.
