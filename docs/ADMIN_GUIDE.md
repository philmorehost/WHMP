# CodeVault Admin Guide

A day-to-day operations reference for whoever runs the admin panel (`/admin`). For setup/deployment, see the root [`README.md`](../README.md).

## Core billing

- **Orders / Invoices / Services** — orders land `pending`; accept them from the Orders queue. Invoices generate automatically ahead of a service's due date (recurring billing cron) or on-demand (billable items, upgrade/downgrade proration).
- **Products** — organized into Product Groups, each product has per-billing-cycle pricing (Products → a product → Pricing) and optional Configurable Options. A product flagged `is_upsell` shows as a cart add-on suggestion.
- **Product Add-ons** (Admin → Products → Product Add-ons) — link any product as a recurring add-on to a parent product (optionally pin it to one billing cycle, or leave it available on any). Clients then see an **Add-ons** button on that service and can add/remove the linked add-ons themselves. Each add-on is billed as its own child service: setup fee + first period are invoiced immediately, and the recurring billing engine handles every later period on the parent's cycle. Removing an add-on stops it renewing; the parent service is never touched.
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

**Abandoned-cart recovery** — every change to a visitor's cart writes a lightweight snapshot (products, promo, total, timestamp) to `abandoned_carts`. An hourly cron sweep (`AbandonedCartJob`) finds carts untouched for 2+ hours and emails a recovery reminder with a direct checkout link. Guests can opt in on the cart page by entering an email (shown only to non-logged-in visitors); logged-in clients are recovered via their account email. By default each cart is emailed once — set `cart.abandoned_repeat_hours` in the settings table to re-remind every N hours, or `cart.abandoned_idle_minutes` to change the idle threshold. The email body lives in `database/templates/abandoned_cart_reminder_body.php` (editable via **Admin → Email Templates**, key `abandoned_cart_reminder`).

## Backups

**Admin → Backups.** "Run Backup Now" triggers an immediate full DB dump (plain SQL, no `mysqldump` dependency) + a zip of the application files, saved to `storage/backups/`. Also runs automatically once a day via cron. The history table shows status/size/duration for every run, including failures with the error message.

## API credentials & the external API

**Admin → API Credentials** (requires `settings.manage`). Issue a scoped key/secret pair for the external REST API under `/api/*`:

- Each credential carries its own **scopes** (e.g. `clients.read`, `invoices.write`, `tickets.read`), so an integration only sees what you grant it. A credential with no matching scope gets a clear 401.
- The plaintext secret is shown **once**, at creation — only its Argon2id hash is stored, so it can't be recovered later. Copy it down immediately.
- Authenticate every request with `Authorization: Bearer <key>.<secret>`. Credentials are validated per request; deactivating one immediately cuts off every integration using it.

Available endpoints (all JSON, wrapped in `{status, data}`):

| Method | Path | Scope |
|---|---|---|
| GET | `/api/clients` / `/api/clients/{id}` | `clients.read` |
| GET | `/api/invoices` / `/api/invoices/{id}` | `invoices.read` |
| POST | `/api/invoices` (create from line items) | `invoices.write` |
| GET | `/api/services` | `services.read` |
| GET | `/api/domains` | `domains.read` |
| GET | `/api/tickets` | `tickets.read` |
| POST | `/api/tickets/{id}/reply` | `tickets.write` |
| GET | `/api/ping` | none (public liveness probe) |

**Uptime monitoring:** `GET /health` returns `{"status":"ok","database":"ok",...}` (200 when healthy, 503 when the DB is unreachable) and deliberately stays up during maintenance mode, so a monitor can tell "maintenance" apart from "down".

## Support

- **Tickets** — auto-escalate and auto-close on configurable timers (cron-driven); a resolved ticket can convert to a billable item. **Merge** folds one ticket's replies/attachments into another; **Split** (✂️ Split Ticket on a ticket page) moves a chosen reply and everything after it into a brand-new ticket with its own subject and department — useful when one ticket has quietly become two conversations. Both sides carry a private note marking where the split happened.
- **Mail Piping** — inbound email-to-ticket via IMAP (spec-correct; live delivery unverified in environments without `ext-imap`).
- **Knowledgebase** — public search includes an AI-synthesized answer (DeepSeek) drawn only from the matching articles' content, when a `DEEPSEEK_API_KEY` is configured.

## Staff / Security

- **Staff / Roles** — granular permission matrix; every admin controller checks a specific permission constant (see `core/Staff/PermissionRegistry.php`) before allowing an action.
- **BruteGuard** (Admin → BruteGuard) — IP/account lockout on repeated failed logins, with a GeoIP-aware allow/deny list.
- Session cookies are `HttpOnly` + `SameSite=Lax`; every response carries a locked-down CSP (`script-src 'self'`, no inline scripts anywhere in the app) plus the standard `X-Frame-Options`/`X-Content-Type-Options`/`Referrer-Policy` headers.

## Reports & Affiliates

- **Reports** — revenue/order/ticket dashboards.
- **AI Insights widget** — the dashboard now includes an **AI Insights** card that summarizes income, new clients, pending orders, overdue totals, open tickets, and renewals in plain language, generated by the configured AI provider. It runs on the shared AI key (**Admin → AI Settings**); when no key is configured (or the provider is unreachable) it falls back to showing the raw figures. The summary is cached hourly, so refreshes stay fast and cheap.
- **Client upgrades** — clients can switch to another plan in the same product group from their service page (**⇅ Upgrade / Downgrade**). Proration mode is chosen at submit (switch now / full credit / prorata); the difference is charged or credited automatically. The same proration engine backs admin-led upgrades.
- **Affiliates** — referral tracking + commission accrual on paid invoices; payout requests go through an admin approval step.

## Reseller programme

Three screens, all under **Admin → Resellers** (Marketing group):

- **Resellers** (`/admin/resellers`) — the discount resellers get off list price (services and domains,
  clamped to 0–100), and the API keys issued to them. A reseller key is created **disabled** and is only
  switched on once the reseller submits the domain they sell from, so a key that never completes that
  step cannot authenticate at all.
- **Store Cost Billing** (`/admin/resellers/billing`) — what the stores owe us, and how it is billed.
  A customer buying at a store pays **us** at retail; the order records what it cost us (catalogue price
  less the reseller discount) and that is what we invoice the reseller for, as an ordinary invoice on
  their own client account. One invoice per store per closed calendar month. Totals are shown **per
  currency** and never added across currencies. "Bill now" is safe to press twice — each order carries
  the id of the invoice that billed it, so a second press raises nothing.
- **Per-store page** (`/admin/resellers/{clientId}/store`) — branding, the custom domain and the
  go-live checklist, the store's markup and price overrides, and the store's status. Suspending a store
  makes its domain return **503** rather than showing our shop at our prices.

### Making a store live on its own domain

1. The reseller claims the domain and proves control with a **TXT** record at
   `_codevault-verify.<domain>` containing `codevault-store-verify=<token>`, or by pointing the domain
   at the platform host. The nightly `reseller-domain-verification` job picks this up on its own;
   **Verify domain now** does it on demand.
2. **On the server** — this application cannot do either step: point the domain's A/CNAME at this host,
   then issue a TLS certificate for it. Until both are done the store is reachable only at
   `{slug}.{platform host}`.
3. A custom domain is only ever served once it is verified — that test is in the SQL, so no code path
   can serve an unverified domain. The check is **never** re-run against a verified store, so a resolver
   outage cannot black out a live storefront.
4. If a provider cannot serve the TXT record, an admin can force the verification from the store page.
   It is recorded as **manual** and shown as such, never as a DNS proof; a later real proof replaces it.
   Removing a verification keeps the domain claimed, so it stays reserved to that store.
