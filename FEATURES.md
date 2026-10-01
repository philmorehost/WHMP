# WHMP — Feature & Function Reference

A self-hosted web-hosting & domain **billing and automation platform** (a WHMCS-class product): billing, provisioning, domains, support, marketing, security, and an AI copilot.

Generated from the live codebase.

| Metric | Count |
|---|---|
| Database tables | 72 |
| Module / route areas | 44 |
| Automated (cron) jobs | 15 |
| Payment gateways | 6 |
| Domain registrars | 5 |
| Server control panels | 5 |

**Legend:** ⏱ runs on a schedule (cron) · 🤖 uses the AI copilot · 🆕 added recently

---

## Billing & Payments

- **Invoicing** — auto-generated invoices with line items, PDF export, statuses (unpaid/paid/cancelled/refunded), per-invoice currency locking.
- **Pay several invoices at once** 🆕 — clients select unpaid invoices and get one consolidated invoice covering them. Paying it settles every invoice inside it, and those invoices are shown as *included in* it rather than offered for payment again, so nothing is billed twice.
- **Recurring billing** ⏱ — daily sweep generates renewal invoices ahead of the due date across every active service.
- **Automatic card charging** 🆕 ⏱ — saved payment methods are charged automatically on the due date so renewals don't lapse; failures fall through to dunning.
- **Dunning & reminders** ⏱ — overdue-invoice chasing and pre-renewal reminder emails.
- **Payment gateways** — Paystack, Flutterwave, PayPal, Payhub, Plisio (crypto), and manual; redirect and tokenized flows with verified webhooks.
- **Multi-currency** — per-client currencies, live conversion, client-facing currency switcher.
- **Tax & VAT** — per-country/state tax rules with EU VIES VAT-number validation.
- **Wallet / account credit** — client credit ledger applied to invoices or issued as refunds.
- **Credit notes** — formal credit-note documents with line items and PDF output.
- **Quotes / estimates** ⏱ — sales quotes with line items that expire automatically and convert to orders.
- **Billable items** ⏱ — ad-hoc one-off charges queued onto a client's next invoice.
- **Promotions & coupons** — percentage or fixed discounts with usage caps and validity windows.
- **Refunds & proration** — refunds to wallet or gateway; prorated charges on upgrades/date changes.
- **Transactions ledger** — every payment and refund recorded and reconciled against invoices.

## Clients & Accounts

- **Client portal** — self-service dashboard for services, domains, invoices, and tickets.
- **Registration & login** — account signup, authentication, and password reset.
- **Sub-contacts** — additional contacts per account with granular permissions.
- **Client groups** — segment clients for pricing, filtering, and bulk actions.
- **Custom fields** — admin-defined fields on clients and on individual services/products.
- **Account security** — security questions, login-attempt lockouts, per-account locks, **scan-to-setup 2FA QR codes** 🆕.
- **Saved payment methods** 🆕 — clients save and manage cards for automatic renewals.
- **My Emails** 🆕 — clients see every email we've sent them (invoices, renewals, tickets), with delivery status.
- **Add Funds** 🆕 — clients top up their wallet balance and pay the deposit invoice online.
- **Login as client** — admins impersonate a client for support without their password.

## Products, Cart & Orders

- **Product catalog** — products organized into groups, with per-billing-cycle pricing.
- **Configurable options** — add-ons and tiered choices with their own pricing per option.
- **Shopping cart & checkout** — guest-friendly session cart, promo codes, full checkout flow.
- **Abandoned-cart recovery** 🆕 — carts are snapshotted to the DB on every change and an hourly sweep emails a reminder with a direct checkout link (guest email opt-in on the cart page; one reminder per cart by default).
- **Client-side upgrade / downgrade** 🆕 — clients switch plans from their service page; pricing prorated (none / full credit / prorata) with charge/credit invoiced automatically.
- **Recurring add-ons** 🆕 — admin links any product as an add-on to a parent product (**Admin → Products → Product Add-ons**); clients add/remove them from the service page. An add-on is a child service billed on its own cycle (setup + first period invoiced immediately, renewals via the recurring engine), never touching the parent.
- **Order management** — orders with items, admin review, cancel, and status tracking.
- **Fraud triage** 🤖 — rule-based scoring plus optional AI risk analysis at order time.
- **Direct order links** — shareable per-product purchase URLs, WHMCS-style.

## Domains

- **Public register & transfer** 🆕 — WHMCS-style search page with TLD category tabs and pricing, open to prospects without login.
- **TLD pricing** — per-TLD register/transfer/renew prices, categorized into tabs.
- **Registrar integrations** — ResellerClub, Namecheap, ConnectReseller, Upperlink, and a local/manual option.
- **Live availability + spinner** 🤖 — real-time availability checks and an AI-assisted name suggestion spinner.
- **Domain management** — nameservers (up to 6), EPP codes, registrar lock, ID protection.
- **Renewal & sync** ⏱ — automated renewal invoicing and registrar status synchronization.

## Provisioning & Servers

- **Server fleet** — servers and server groups with health/connection testing.
- **Control-panel modules** — cPanel/WHM, CyberPanel, InterServer VPS, Nocix dedicated, and local.
- **Auto-provisioning** — accounts created automatically on paid order.
- **cPanel Extended tools** — in-portal cPanel account actions (suspend, password, usage) via UAPI.

## Support

- **Ticket system** — departments, threaded replies, priorities, assignment, satisfaction ratings.
- **Attachments & preview** 🆕 — image and document uploads with inline image/PDF preview (no download needed).
- **AI reply drafting** 🤖 — one-click professional reply suggestions the agent can insert and edit.
- **Canned replies** — reusable saved responses for common questions.
- **Email piping** ⏱ — inbound email becomes tickets and replies via a mailbox poller.
- **Escalation & auto-close** ⏱ — SLA escalation and automatic closing of idle resolved tickets.
- **Merge & split** 🆕 — merge tickets into one conversation, or split one ticket in two at any reply (the reply and everything after move into a new ticket with its own subject/department; both sides carry a private note).
- **Knowledgebase** 🤖 — categorized help articles with AI-assisted search.
- **Network status** — public status page and network-issue announcements.
- **Announcements & downloads** — news posts and a categorized client download library, plus an **RSS feed** 🆕 (`/announcements.rss`) so subscribers can follow updates in their reader.

## AI Copilot (DeepSeek-powered)

- **AI management console** 🆕 — admin-managed API key, per-feature toggles, and token-usage monitoring.
- **Ticket replies** — drafts professional support responses from the conversation.
- **Domain suggestions** 🆕 — brandable name ideas checked live for availability.
- **Onboarding copilot** 🆕 — guides new clients through ordering, payment, and accessing hosting.
- **Fraud triage** — reasoned risk assessment of new orders.
- **KB & Ask-AI** — answers from your knowledgebase and a staff Q&A helper.
- **PII redaction** — personal data is stripped before anything is sent to the model.
- **SEO / AI visibility** — scores how discoverable pages are to AI answer engines.

## Marketing & Growth

- **Affiliate program** — referral tracking, commissions, and payout requests.
- **Reseller programme** 🆕 — client-owned API keys that stay inert until the reseller declares the domain
they sell from, plus white-label storefronts served at `{slug}.{platform host}` or a verified custom domain.
Resellers set their own retail prices over our cost, and we bill them that cost monthly (**Admin → Resellers**,
**Store Cost Billing**).
- **Reseller running account & payouts** 🆕 — every sale at a store credits the reseller's account the moment
their customer's invoice is **paid** (never at order time), and the monthly cost we bill them is debited against
the same balance. Balances are held in the base currency, because the reseller carries the exchange movement
between collection and payout. A receipt becomes **withdrawable 30 days after payment** (the card chargeback
window), so the pages always show two figures — what is owed and what can be taken today — and payouts are
measured against the second. A **refund un-winds the sale on both sides**: the receipt is reversed and any cost
already billed for that order is credited straight back, so a refunded order ends up neither earning nor costing
anything. A refund that lands before the month is invoiced never billed it in the first place. Resellers request
a payout themselves (**Reseller Area → Your account**) and admins
answer it from a queue (**Admin → Resellers → Reseller Payouts**). Payment is a **manual bank transfer** whose
reference the admin records: nothing in the system moves money, so a request emails every admin who can release
it — anyone holding the payout-queue permission, plus super admins — rather than sitting unnoticed in a queue.
Resellers who want an automated rail instead need a payout destination stored and verified first, which does not
exist yet. Each store's ledger can be downloaded as a
CSV from its account page (**Export CSV**) for accounting done outside the system — base amounts, one row per
entry, ids as plain values and no totals, because the ledger is the authority and a spreadsheet can sum a
column but cannot un-sum a wrong one. Each store also has a **period statement** (**Statement** on the account
page) showing the opening balance, the entries, the closing balance and the withdrawable figure as at the period
end. A statement can be **issued as a numbered, frozen document** (`STMT-2026-0001`, per store per year) that
never changes afterwards — the copy the reseller keeps and the copy we hold always agree, which is what makes it
usable as an accounting or tax document. The reseller reads their own issued statements from the Reseller Area;
issuing is an admin action. Our own tax identity (VAT number, registration number, registered address, contact
details) is set under **Configuration → General Settings → Company Information** and is stamped onto each
document as it stood when it was issued.- **Email campaigns** — bulk marketing sends with per-recipient tracking.
- **SEO tools** — canonical URLs, meta tags, and structured JSON-LD data.
- **Promotions** — public deals page driven by active coupon campaigns.

## Admin, Staff & Configuration

- **Staff & roles** — admin accounts with granular role-based permissions.
- **Dashboard & reports** — revenue, client, and operational reporting for admins.
- **AI Insights widget** 🤖🆕 — a dashboard card that summarizes income, new clients, pending orders, overdue totals, open tickets, and renewals in plain language from the configured AI provider (cached hourly; falls back to live figures offline).
- **Activity log** — audit trail of admin and client actions.
- **Theme & branding** — brand name, logo, accent color, and Terms-of-Service URL.
- **Email templates** — editable transactional templates with a delivery log.
- **Notifications** — outbound alerts to endpoints like Discord.
- **Localization** — multiple languages with per-string translation overrides.
- **Addons & widgets** — pluggable addon modules, dashboard widgets, and report modules.

## Data, Security & Platform

- **Roles & permissions** — fine-grained access control across every admin capability.
- **CSRF & CSP hardening** — fail-closed CSRF tokens and a strict content-security policy.
- **Access rules** — IP allow/deny, country rules, and login rate-limiting.
- **WHMCS import** — full migration of clients, services, domains, invoices, and more from WHMCS.
- **Backups** ⏱ — scheduled database backups with run history.
- **GDPR tools** ⏱ — client data export, erasure requests, and automated data pruning.
- **WebP image pipeline** 🆕 — the `img()` helper and `/img` endpoint serve WebP derivatives (optionally downscaled) to capable browsers with far-future cache headers, falling back to the original bytes otherwise; `public/.htaccess` now sends immutable cache headers for versioned static assets.
- **REST API** 🆕 — real, scoped API: Bearer-authenticated (`key.secret`) with per-credential permissions. Read clients/invoices/services/domains/tickets, create invoices, reply to tickets. Managed at **Admin → API Credentials** (settings.manage).
- **Health endpoint** 🆕 — `GET /health` returns JSON liveness/DB status for uptime monitors, and stays up during maintenance mode.
- **Licensing & integrity** ⏱ — activation-key validation and scheduled integrity checks.
- **Framework** — custom PHP MVC: DI container, router, hooks, module manager, PDF engine, Redis/file sessions & queue.
- **Guided installer** — multi-step setup: requirements, database, admin account, and lock.

---

## Automated jobs (cron)

Point one system cron at `bin/cron.php`; it runs each job on its own schedule.

| Job | Purpose |
|---|---|
| `RecurringBillingJob` | Generate renewal invoices before the due date |
| `AutoChargeJob` | Charge saved cards for due invoices (before dunning) |
| `DunningJob` | Email reminders for overdue invoices |
| `RenewalReminderJob` | Pre-renewal reminder emails |
| `DomainRenewalBillingJob` | Invoice domain renewals |
| `DomainSyncJob` | Sync domain status from registrars |
| `BillableItemInvoicingJob` | Roll ad-hoc charges into invoices |
| `ResellerCostBillingJob` | Invoice each store for the cost of the orders it took (one per closed month) |
| `DomainVerificationJob` | Verify claimed store domains once DNS answers |
| `QuoteExpiryJob` | Expire stale quotes |
| `TicketEscalationJob` | Escalate SLA-breaching tickets |
| `TicketAutoCloseJob` | Close idle resolved tickets |
| `MailPipingJob` | Turn inbound email into tickets/replies |
| `BackupCronJob` | Scheduled database backups |
| `DataPruningJob` | GDPR-driven data retention pruning |
| `IntegrityCheckJob` | Scheduled integrity/licensing checks |
| `SendEmailJob` | Process the outbound email queue |

## Integrations

- **Payment gateways:** Paystack · Flutterwave · PayPal · Payhub · Plisio (crypto) · Manual
- **Domain registrars:** ResellerClub · Namecheap · ConnectReseller · Upperlink · Local
- **Server control panels:** cPanel/WHM · CyberPanel · InterServer VPS · Nocix (dedicated) · Local
- **Notifications:** Discord
- **AI provider:** DeepSeek (OpenAI-compatible)
