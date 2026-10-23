# CodeVault Admin Guide

A day-to-day operations reference for whoever runs the admin panel (`/admin`). For setup/deployment, see the root [`README.md`](../README.md).

## Core billing

- **Cancellations** — a client cancelling a service chooses the option themselves: **⚡ Cancel immediately** (the service ends right away, unused time is not refunded) or **📅 Cancel at the end of the billing period** (keeps what they paid for; it does not renew). Every end-of-period request lands in **Admin → Cancellations** for review, where the admin can switch the option either way: **⚡ Cancel Immediately Instead** is available both on pending requests (before approval) and on approved ones still waiting for their scheduled date (after approval) — it cancels the service right then, marks the request completed, and notifies the client. Cancelling a service cancels its unpaid invoices; cPanel/CyberPanel accounts are terminated on the server, and InterServer VPS/dedicated servers are cancelled through the provider's API — immediately on approval, or by the cron on the scheduled date. A failed API cancellation emails the admins for manual review (the request still completes, so billing stops). Nocix publishes no cancellation endpoint, so those servers are only marked cancelled here — cancel them in the Nocix portal.
- **Orders / Invoices / Services** — orders land `pending`; accept them from the Orders queue. Invoices generate automatically ahead of a service's due date (recurring billing cron) or on-demand (billable items, upgrade/downgrade proration). **Admin → Invoices** also offers one-click cleanups above the list: zero-value unpaid invoices can be settled in bulk, and a red callout appears whenever unpaid invoices are attached to **cancelled or terminated services** ("Cancel N Invoice(s)") — those debts can never be collected, so cancelling them stops reminders and overdue totals from chasing money that will never arrive. Only unpaid invoices linked to a dead service are touched; paid, refunded and unlinked invoices are never affected.
- **Products** — organized into Product Groups, each product has per-billing-cycle pricing (Products → a product → Pricing) and optional Configurable Options. A product flagged `is_upsell` shows as a cart add-on suggestion.
- **Product Add-ons** (Admin → Products → Product Add-ons) — link any product as a recurring add-on to a parent product (optionally pin it to one billing cycle, or leave it available on any). Clients then see an **Add-ons** button on that service and can add/remove the linked add-ons themselves. Each add-on is billed as its own child service: setup fee + first period are invoiced immediately, and the recurring billing engine handles every later period on the parent's cycle. Removing an add-on stops it renewing; the parent service is never touched.
- **Tax Rules** — country/state-specific rates; a client's tax is resolved by their billing address, `tax_exempt` clients are never taxed regardless of a matching rule.
- **Bulk Price Updates** (Admin → Bulk Price Updates, under Provisioning and Domains) — reprice many items in one pass: pick **products by their category** (with per-product narrowing) and choose which billing cycles to touch (setup fees optional), and/or pick **domain extensions by TLD** with the register/transfer/renew fields, then apply a percentage or flat increase/decrease (never below zero). Only existing price rows are adjusted — a cycle a product doesn't offer is never created here. After applying, every client who **subscribes to an affected product at one of the changed cycles or holds a domain under an affected extension** is emailed a `price_update_notice` price review listing only the lines that affect them (one email per client even when both product and domain prices moved); subscribers whose own cycle/extension didn't change get nothing. Delivery is queued like every outbound email. Both halves are permission-split (`products.manage` / `domains.manage`).
- **Payment Gateways** — the built-in Manual gateway (bank transfer, staff mark-paid) always works with zero configuration; add real gateway modules by implementing the `GatewayModule` SDK interface. Each gateway card also carries a **Resellers: On/Off** toggle: switching a gateway off for resellers hides it from clients of reseller storefronts only (their invoice and payment pages) and rejects their payment attempts, while the platform's own site keeps offering it. The flag is independent of the global enable toggle — a globally disabled gateway is hidden from everyone regardless.
- **Fraud Protection** — every order is scored on placement by all registered `FraudModule`s (max score wins, not average); a hold routes it to the fraud review queue instead of the normal pending queue.
- **Domain registration & approval flow** — a bought domain lands `pending` on its order (the order page lists it in its own table, and pending rows on the Orders list are tagged "🌐 N domain registration(s) — approving registers it"). **Accepting the order sends every pending domain on it to its registrar and activates it** (dates, nameservers, renewal billing) in the background job; you get a completion/failure email per domain. The paid invoice links straight to its order. For a domain stuck pending (order already accepted, or a previous attempt failed), **Admin → Domains → the domain → "Approve & Register"** submits it to the registrar immediately — same path, immediate activation. Registrars require an assigned, complete registrant: the page shows the registrant on file and blocks submission with the exact missing fields (name/email/address/city/ZIP/country/phone) instead of letting the API reject the order with an opaque error. ConnectReseller and ResellerClub orders carry a registrant contact resolved automatically — the client's existing contact at the registrar is reused (found by email), or one is created from the domain/client details and linked (`domains.registrar_contact_id`) so later actions never duplicate it. ResellerClub additionally needs a **Default Customer ID** in its registrar config before it can register anything. Upperlink (WHMCS Domains Reseller API) needs no contact record — but its register/transfer calls only recognise a registrant in the role-keyed `contacts[registrant][…]` structure, which the module now sends (it used to send a flat list the API read as "no registrant", failing every registration).
- **cPanel client tools & Imunify360 WebShield** — the client-area cPanel tool (email/FTP/database/DNS management and quick-login SSO) talks to WHM's JSON API from this billing server. If the hosting server runs Imunify360 with **cPanel account protection** (WebShield "Splash Screen", `WEBSHIELD.panel_protection`), WebShield answers panel-port requests with a JavaScript challenge: real browsers pass it once and get a 24h cookie, but server-to-server API automation cannot run JavaScript, so every tool call and SSO mint fails with an HTML challenge page instead of JSON. The tool detects this and reports it explicitly. Resolution on the WHM server, in order of preference:
  1. **Whitelist this billing server's IP** — `imunify360-agent whitelist ip add <IP>` (Imunify360 → Firewall → WhiteList). This is CloudLinux's documented answer for legitimate automation; the tool's error message includes the resolved outbound IP. Check `/var/log/imunify360-webshield/` if unsure.
  2. **Update WebShield** — builds before 1.31.4 have a bug where the Splash Screen loops forever ("Please wait while your request is being verified") even for real browsers, so clients cannot log in to cPanel at all. `bash imunify-force-update.sh` from the repo.
  3. **Turn off cPanel account protection** — `imunify360-agent config update '{"WEBSHIELD":{"panel_protection":false}}'` or untick it in Imunify360 → WebShield. This restores plain panel logins/SSO but removes the anti-bot challenge on ports 2082/2083 — prefer options 1–2.

  Clients whose IP is greylisted may see the challenge once; after passing it they are cookied for 24h and quick-login works normally.

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

**Admin → Campaigns.** Compose a subject + HTML body and pick an audience target: every active client, accounts with no active product/domain (re-engagement), one client group, one individual client, **clients with specific products** (WHMCS-style — tick products in a searchable checkbox list with filter-as-you-type and "Select all" over the filtered rows), or external email addresses. Each recipient gets a unique open-tracking pixel; the campaign's detail page shows send/open status per recipient. Campaigns go through the same async email pipeline (queue + `email_log`) as every other outbound email — check **Admin → Email Log** (via a client's profile, or the raw `email_log` table) if a send looks stuck.

Old campaigns stay usable after sending: **Edit** rewrites a draft's or sent campaign's subject/body (and its audience, which takes effect the next time it sends — paused campaigns are content-only because their remaining recipients are already resolved); **Send Again** re-queues a sent campaign as a new *round* — the audience is resolved fresh at send time, earlier rounds' recipient/open history is kept, and the "only people who haven't received this campaign yet" checkbox limits the resend to new sign-ups (untick it to send everyone another copy); **Delete** removes a draft, paused or sent campaign together with its entire recipient history (a sending campaign must be paused first). The lists' Sent/Opened counts always describe the most recent round; older rounds stay visible on the campaign page.

Lifecycle emails run automatically via cron — currently just the service renewal reminder (7 days before `next_due_date`, once per billing cycle). Dunning (overdue-invoice reminders) is a separate, older engine — see the Billing section.

**Abandoned-cart recovery** — every change to a visitor's cart writes a lightweight snapshot (products, promo, total, timestamp) to `abandoned_carts`. An hourly cron sweep (`AbandonedCartJob`) finds carts untouched for 2+ hours and emails a recovery reminder with a direct checkout link. Guests can opt in on the cart page by entering an email (shown only to non-logged-in visitors); logged-in clients are recovered via their account email. By default each cart is emailed once — set `cart.abandoned_repeat_hours` in the settings table to re-remind every N hours, or `cart.abandoned_idle_minutes` to change the idle threshold. The email body lives in `database/templates/abandoned_cart_reminder_body.php` (editable via **Admin → Email Templates**, key `abandoned_cart_reminder`).

## Backups

**Admin → Backups.** "Run Backup Now" triggers an immediate full DB dump (plain SQL, no `mysqldump` dependency) + a zip of the application files, saved to `storage/backups/`. The automated schedule runs **at most once per day** (General Settings — the frequency field is floored at 24 hours; higher values such as 168 = weekly work too) at the configured daily automation time. **General Settings → "Enable automated backups"** switches the scheduled backup system off entirely while leaving the manual button working. Only one backup can ever run at a time — a run started while another is still going is skipped, not queued on top. **Terminate running backups** (the red button on the Backups page) cleans up after trouble: it closes out every "Running" history entry and deletes its half-written files — the leftovers of a crashed or previously piled-up run. If a backup is genuinely executing at that moment, its entry is removed and its partial files deleted, but the process itself cannot be force-killed from the browser (the page tells you); it simply finishes and the lock frees itself. Completed backups are never touched. The history table shows status/size/duration for every run, including failures with the error message.

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
  step cannot authenticate at all. The API documentation (`/client/reseller/docs`) is **public** — a
  prospective reseller can read the whole surface before signing up — but the key itself is printed
  there only for a signed-in reseller whose account has been approved; everyone else (guests, and
  signed-in resellers still awaiting their domain approval) sees the docs without it. The base URL and
  every curl example on that page use the portal's own full domain, so examples can be copy-pasted
  straight into a terminal. The page is linked from the main menu ("API") and the site footer
  ("API Docs") for quick access.
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
2. **On the server** — this application cannot do any of it, and **DNS alone is not enough**:
   1. **Add the domain to the web server** so the server answers for that hostname. cPanel →
      *Domains → Create A New Domain*, with the **same document root** as the platform's own
      domain. Skip this and a request for the domain never reaches the application at all —
      the server serves its default site instead, which reads to everyone as "the domain does
      not work".
   2. **Point the domain's DNS here** — an `A` record to the server IP, or a `CNAME`.
   3. **Issue a TLS certificate** — cPanel → *SSL/TLS Status → Run AutoSSL*. If the domain is
      behind Cloudflare, set the record to **DNS only (grey cloud)** while AutoSSL runs, or the
      proxy answers the challenge and issuance fails.

   Until all three are done the store is reachable only at `{slug}.{platform host}`.
   Full walkthrough, with a diagnostic for each failure and a reseller-facing version:
   [`RESELLER_DOMAIN_SETUP.md`](RESELLER_DOMAIN_SETUP.md).
3. A custom domain is only ever served once it is verified — that test is in the SQL, so no code path
   can serve an unverified domain. The check is **never** re-run against a verified store, so a resolver
   outage cannot black out a live storefront.
4. If a provider cannot serve the TXT record, an admin can force the verification from the store page.
   It is recorded as **manual** and shown as such, never as a DNS proof; a later real proof replaces it.
   Removing a verification keeps the domain claimed, so it stays reserved to that store.
