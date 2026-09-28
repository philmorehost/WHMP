# Reseller White-Label Storefront — build plan

Status: **plan for approval.** Nothing in this document is implemented yet.
Scope agreed with the product owner: a reseller's own domain serves a **branded**
version of the storefront, the reseller sets their own retail prices, and their
customer orders there. We charge the reseller the **discounted** amount.

---

## 1. The money model this has to express

Today the platform has exactly one price for a product: the admin's catalogue
price. A reseller store needs **three** numbers per line:

| Number | What it is | Who sets it | Example (list $20, 20% reseller discount) |
|---|---|---|---|
| **list** | our catalogue price | admin (`product_pricing`) | $20.00 |
| **cost** | what the reseller owes us | admin (list − reseller discount) | $16.00 |
| **retail** | what the end customer pays | reseller | $24.99 |

- The **customer's invoice** is at *retail* — they are a normal client and are
  billed by us through the existing invoice/gateway path.
- The **reseller is invoiced for cost** (see §5). Their margin is retail − cost
  and never touches our revenue.
- All three must be snapshotted on the order at checkout: a later price change
  must not rewrite what an order was worth. `order_items` already does this for
  `unit_price`/`setup_fee`; the same treatment is required for cost and retail.

## 2. What exists today (verified)

- **Storefront routes** (`routes/cart.php`): `/store`, `/store/{id}`, `/cart`,
  `/cart/add`, `/cart/apply-promo`, `/cart/checkout` → `Cart\CheckoutController`.
  Public pages are in `routes/web.php` (`/`, `/deals`, `/terms`).
- **Pricing seam**: `Cart\CartService::priceItems()` is the *only* place a line
  price is computed. It reads `product_pricing.price` + configurable options +
  domain price, applies a promotion, and returns
  `['lines', 'subtotal', 'setupFees', 'domainTotal', 'discount', 'total']`.
- **Order writing**: `Cart\CheckoutService::buildOrder()` inserts
  `orders (client_id, status, total, discount_amount, promotion_code, currency_id, currency_rate, no_invoice, ...)`
  and `order_items (order_id, product_id, product_name, billing_cycle, quantity, unit_price, setup_fee, configurable_options)`.
  `orders.client_id` is **NOT NULL** with an FK to `clients`.
- **Branding is global and single-valued**: `Theme\ThemeSettings::get()` reads
  `theme.brand_name`, `theme.logo_url`, `theme.primary_color`,
  `theme.favicon_url`, `theme.terms_url`; `View` injects the result as `$theme`
  into every template. There is **no** per-client, per-group or per-domain
  branding path anywhere in the codebase.
- **Reseller today** = `api_credentials.client_id` + `reseller_domain` + a
  global discount pair (`ResellerSettings`). There is no reseller *entity*: no
  slug, no brand, no markup, no store state.
- **DNS**: nothing in the app does a DNS lookup. There is no domain-ownership
  verification helper to reuse.

## 3. Five things that will fight this feature

These are the real risks, in order. Each needs a decision, not just code.

### 3.1 The app deliberately does not trust the Host header

`Seo\SeoTags` builds canonical URLs from `APP_URL`, with this comment:

> Canonical is built from APP_URL, not the request's Host header — a spoofed
> Host header must never end up in a canonical tag or structured data a crawler
> trusts.

A storefront on `shop.reseller.com` **requires** reading Host. The only safe way
to do that is:

- look the host up in `resellers`, and
- only treat it as a tenant when the row is **verified** (`domain_verified_at`
  set, checked by us against DNS), and
- build canonical/absolute URLs from that **matched row's** host — never from
  the raw header. An unmatched host falls through to the platform site exactly
  as today, with no behaviour change.

So the Host is not trusted because it is a Host; it is trusted because it
already matches a row an admin can see. That distinction is the whole safety
argument and must be preserved in code comments and tests.

### 3.2 Branding is resolved from global settings in three separate places

Per-tenant branding has to override: `ThemeSettings::get()` (which `View`
injects), the `brand_name()` global helper (used by SEO and emails), and
`Mail\EmailDispatcher` (which builds links from `APP_URL`, so tenant emails
would carry platform links). Also note `brand_name()` memoises into a **static**:
harmless per-request, but a cron job rendering emails for two resellers in one
process would sign both with the first one's name. That cache has to be keyed by
tenant or dropped.

### 3.3 Absolute URLs leak the platform domain

Anything built from `APP_URL` — emails, gateway return URLs, payment callbacks,
canonicals — will point at the platform domain rather than the tenant. A
customer who pays on a reseller's store must come back to *that* store. This
needs a single "current site base URL" concept that resolves to the tenant when
one is active and to `APP_URL` otherwise.

### 3.4 The customer is a real client account

`orders.client_id` is NOT NULL. So a reseller's customer must exist as a client
in our install (which is the normal reseller arrangement — we host their
service, we bill them). Attribution therefore needs:

- `clients.reseller_id` — which reseller brought this account in,
- `orders.reseller_id` — which store the order was placed at,
- and a rule for what happens if an *existing* client buys from a store.

### 3.5 Custom domain needs DNS verification and TLS — only half of that is ours

The app can verify that a domain points at us (a TXT or CNAME check — new code).
The app **cannot** issue or renew a TLS certificate: that is web-server/edge
configuration. The admin UI must say so plainly rather than implying that
typing a domain into a form makes a store reachable, and the client UI must
distinguish "domain saved" from "domain verified" from "certificate live".

## 4. Phases

Each phase is independently useful and independently verifiable.

### Phase 1 — Tenant entity, host resolution, per-tenant branding  *(no money path)*
- Migration: `resellers` table — `client_id` (FK clients), `slug`, `custom_domain`,
  `domain_verified_at`, `domain_verification_token`, `brand_name`, `logo_url`,
  `favicon_url`, `primary_color`, `status`, timestamps. Unique on `slug` and on
  `custom_domain`.
- `Reseller\ResellerStoreRepository` — lookup by verified host, by slug, CRUD.
- `Reseller\CurrentReseller` — resolved once per request, exposed via the
  container; `null` on the platform site.
- Kernel: resolve the tenant from the Host before routing; a host that matches
  no verified row changes nothing.
- `ThemeSettings` and `brand_name()` consult the resolved tenant first.
- Admin: store settings on `/admin/resellers` (slug, domain, brand, logo,
  colour) + "verify DNS" action showing the record to create.
- Client: `/client/reseller/store` — store name/slug, logo, colour, custom
  domain, and the verification state.
- Tests: host→tenant resolution; **unknown host falls through unchanged**;
  branding from a tenant does not leak to the platform site or to another
  tenant; a saved-but-unverified domain resolves nothing.

### Phase 2 — Reseller retail pricing  *(additive)*
- `reseller_product_prices` table (per product + cycle retail override) plus a
  store-wide markup percentage as the default.
- `ResellerPricing` gains `retailFor($listPrice, $productId, $cycle)`.
- Tenant storefront shows retail prices.
- No checkout change yet.

### Phase 3 — Checkout and orders under a tenant  *(money path — highest risk)*
- `CartService::priceItems()` takes an optional store context: lines carry
  `unit_price` (retail) **and** `cost_price`; the returned totals carry
  `costTotal`.
- `CheckoutService::buildOrder()` writes `orders.reseller_id`, `orders.cost_total`,
  and tags the customer `clients.reseller_id`.
- `order_items` gains `cost_price` (snapshot, like `unit_price`).
- Tests: retail vs cost on the same order; the customer is invoiced retail;
  cost is unaffected by promotions (a coupon is *our* concession, it must not
  reduce what the reseller owes... **decision needed**, see §6).

### Phase 4 — Billing the reseller
- A report of cost accrued per reseller per period, and a job that raises the
  reseller's invoice via the existing invoice path (no new money code).
- Needs rules: invoice cadence, minimum, whether unpaid cost suspends the store.

### Phase 5 — Custom domain go-live
- DNS verification job + admin override, certificate instructions, and a
  "store is live" checklist. Documents the server-side half that this
  application cannot do.

## 5. What "we charge the reseller the discounted amount" means concretely

Recommended model, and the one the phases above assume:

1. The customer checks out at the reseller's store and pays **us** at retail,
   through the existing gateways. Nothing new on the payment path.
2. The order records `cost_total` (discounted). The customer's invoice is
   unaffected by it.
3. The reseller is invoiced for the sum of `cost_total` for the period, as an
   ordinary invoice to their client account — so it appears in their client
   area, ages, and can be paid with the same gateways.

The alternative (the customer pays the reseller directly, off-platform, and we
invoice the reseller for cost) removes step 1 entirely and makes the storefront
order-only. Both are defensible; they differ in who carries the payment risk and
which gateway account the money lands in. **This needs a decision before
Phase 3.**

## 6. Decisions needed

**Decided 2026-09-28:**

1. **Who collects the customer's money** — *we do.* The customer pays us at
   retail through the existing gateways, and we invoice the reseller for the
   order's cost. No new payment code.
2. **Promotions vs cost** — *cost stays fixed.* A coupon is the reseller's own
   concession; our cost remains the discounted catalogue price, so our margin
   cannot be spent by a reseller's campaign.
3. **Platform subdomains** — *yes.* Every store is reachable at
   `{slug}.{platform host}` immediately, and `{slug}` is validated against a
   reserved list (`www`, `api`, `admin`, `mail`, `checkout`, …) so a store can
   never sit on infrastructure-looking names.

Still open (needed before Phase 3):

4. **Existing clients** — may an existing client of ours order from a store and
   become attributed to that reseller, or is a store's customer always a new or
   already-attributed account?
5. **Unverified domains** — a store with no verified domain: reachable on its
   platform subdomain only (implemented), or invisible entirely?

## 8. Status

- **Phase 1 — implemented** (2026-09-28). Migration `0185_create_resellers_table`;
  `ResellerStoreRepository`, `ResellerStoreLocator`, `CurrentReseller`,
  `DomainVerifier`, `ResellerStoreService`; tenant resolution in
  `Kernel::handle()`; per-tenant branding through `ThemeSettings::forCurrentSite()`,
  `brand_name()` and `SeoTags`; admin and client store screens.
- Phases 2–5 not started.

## 7. Explicitly out of scope

- Issuing/renewing TLS certificates (server/edge configuration).
- Reseller-authored page content, templates or themes beyond brand name, logo,
  favicon and accent colour.
- Reseller-branded email sending domains (DKIM/SPF per tenant) — a reseller's
  customers will receive mail from our domain until that is tackled.
- Self-service reseller signup and KYC.
