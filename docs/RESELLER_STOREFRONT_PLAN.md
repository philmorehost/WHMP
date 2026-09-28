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

### Phase 2 — Reseller retail pricing  *(additive — implemented)*
- `resellers.markup_percent` (store-wide default, clamped 0–1000) plus
  `reseller_prices` (per product+cycle) and `reseller_domain_prices` (per TLD,
  three prices, each optional) for hand-set overrides — migration 0186.
- `ResellerRetailPricing` composes the three figures: **list** (ours), **cost**
  (list less the admin discount) and **retail** (list + markup, or an override).
  `previewServices()`/`previewDomains()` build the whole catalogue at retail on
  top of `ResellerPricing`, so the two cannot disagree about a price.
- Reseller UI at `/client/reseller/prices`: markup, per-cycle and per-TLD
  overrides, with cost and margin shown per line. The admin sees the store's
  markup and override count on the admin store page.
- **Retail prices are NOT shown on the public storefront yet.** Until checkout
  charges retail (Phase 3), displaying them would advertise a price we do not
  honour, so the page says so explicitly and the storefront keeps list prices.
- A price below cost is refused on save (a half-saved price list is worse than
  a rejected one) but never silently rewritten afterwards; if a later discount
  change pushes a published price under cost, `belowCost()` reports it and the
  UI warns.

### Phase 2b — domain retail overrides
- `reseller_domain_prices` exists and is wired, but the UI sets all three
  prices per TLD from one row; a per-registrar or per-period schedule is not
  modelled.

### Phase 3 — Checkout and orders under a tenant  *(money path — highest risk)*
**Implemented 2026-09-29.** Migration `0187_add_reseller_attribution_to_orders`
adds `orders.reseller_id`, `orders.cost_total`, `order_items.cost_price` and
`clients.reseller_id`. Both foreign keys are `ON DELETE SET NULL`: deleting a
store must never delete an order or a client account.
- `CartService::priceItems()` takes an optional store context. Lines then carry
  both `unit_price` (retail) **and** `cost_price`; the totals carry `costTotal`
  and the priced cart carries `store_id`. On the platform path every `cost_*`
  value is `null`, so a platform order is byte-for-byte what it was.
- Setup fees, configurable options and domains go through the same
  `ResellerRetailPricing` call as the base price, so no line can be retail in
  one component and cost in another.
- `CheckoutService` converts the cost figures with the *same* rate as the
  retail figures (they are the same currency at that point), then writes
  `orders.reseller_id` + `orders.cost_total` and one `order_items.cost_price`
  per line as a snapshot, exactly like `unit_price`.
- **The customer's invoice is retail only.** `cost_total` is never totalled
  onto a document; it is the ledger figure Phase 4 bills the reseller for.
- A promotion reduces the customer's price only — `costTotal` is computed
  before the discount, so a reseller's campaign cannot spend our margin.
- `order_items.cost_price` is per line because a mix of products with different
  markups, and a later markup change, would make a single order-level figure
  un-auditable.
- Tests: 12 in `ResellerStoreCheckoutTest`, driving the real storefront path
  (session `Cart` → `priceItems()` → `placeOrder()`), 51 assertions. A negative
  control (forcing `priceItems()` to ignore the store) fails 7 of the 12 — the
  money tests bite, and the two platform-path tests correctly still pass.

### Phase 4 — Billing the reseller
**Implemented 2026-09-29.** Migration `0188_add_reseller_cost_invoicing` adds
`orders.reseller_cost_invoice_id` (FK to `invoices` `ON DELETE SET NULL`, indexed
with `reseller_id`) and three settings: `reseller.billing_auto`,
`reseller.billing_minimum`, `reseller.billing_due_days`. Daily job
`ResellerCostBillingJob`, registered in `bin/cron.php` as `reseller-cost-billing`.
- **Idempotency is a property of the data, not of the run.** An order's cost is
  billed when its stamp is set, and the guard is *inside* the UPDATE
  (`... AND reseller_cost_invoice_id IS NULL`). Running twice bills once; a
  missed month is billed by the next run; deleting an invoice un-bills its
  orders rather than losing them. There is no "last run" marker to keep in sync.
- **One invoice per store per closed calendar month.** `duePeriods()` walks
  months in order instead of selecting "everything older than a cutoff", so three
  months of downtime produce three monthly invoices rather than one catch-up
  figure indistinguishable from a spike in sales. The current month is never
  billed.
- **A month below the minimum is carried forward** into the next invoice that
  clears it, and the invoice records every month it covers. Accrual that never
  clears a raised minimum stays unbilled and keeps showing as *unbilled* in the
  report — hard to bill must not become silently written off.
- **The reseller is billed in their own currency.** `orders.cost_total` is in the
  *customer's* order currency and a store's customers each choose their own, so
  the mix is normalised through the existing `CurrencyService::toBase()` — which
  reverses whichever storage convention wrote the row, denominated or locked —
  and converted once. Conversion lives in `ResellerCostService` and nowhere
  else, so the report and the invoice cannot disagree about a figure.
- **Arrears suspend nothing.** A storefront going offline takes the reseller's
  own customers with it, so suspension stays an explicit admin action on the
  store page; the report surfaces unpaid cost invoices instead.
- The invoice lands on the reseller's **client account** through the ordinary
  invoice path (no new money code), so it ages, duns and can be paid with the
  same gateways as any other invoice.
- Invoice lines name **our order number and date only, never the customer**. A
  store's customer may be one of our own clients, and a reseller must not be able
  to read our client list off an invoice.
- Admin screen `/admin/resellers/billing`: per-currency totals (never summed
  across currencies — an NGN total and a USD total have no common total), the
  per-store breakdown, arrears, the three tunables, and a "bill now" button that
  reports how many invoices it raised and is safe to press twice. The reseller
  sees their own figures and unpaid invoices on `/client/reseller/store`.
- Tests: `ResellerCostBillingTest` (18) covers once-ness, closed periods, the
  carry-forward, zero-cost months, all three currency shapes, terms, privacy and
  arrears. `AdminResellerBillingPageTest` (8) covers the page, the
  `resellers.manage` gate and a double press of "bill now". Negative controls:
  removing the unbilled filter is caught by the transactional claim guard
  (`claimed 0 of 1 orders`), and taking cost at face value instead of normalising
  it fails the denominated-order test with 14,900 against 10.

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
which gateway account the money lands in. **Decided: we collect** (see §6) —
Phase 3 is built on that, and the reseller is invoiced for cost in Phase 4.

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

**Decided 2026-09-29, while implementing Phase 3:**

4. **Existing clients** — *a store attributes only the accounts it created.*
   An existing client of ours who buys from a store keeps their current owner;
   only the order is attributed to the store. The alternative — reassigning them
   — would move a client's account, history and support relationship to a third
   party on the strength of one purchase, and would let a reseller acquire our
   clients by marketing at them. The claim is made by
   `ClientRepository::setResellerIfUnclaimed()` with the guard *inside* the
   `UPDATE` (`WHERE id = ? AND reseller_id IS NULL`), so it is atomic and the
   first store to create a customer keeps them. A platform order leaves
   `reseller_id` `NULL`, and a customer who belongs to nobody is attributed to
   whichever store first creates them.

**Still open:**

5. **Unverified domains** — a store with no verified domain: reachable on its
   platform subdomain only (implemented), or invisible entirely?

**Decided 2026-09-29, before implementing Phase 4:**

6. **Currency of the reseller invoice** — *the reseller's own account currency*,
   converting each order at the storage convention it was written with. Matches
   how every other invoice is denominated, and the converted figure is
   reproducible from the order instead of depending on today's rate.
7. **Cadence** — *monthly, one invoice per store per closed calendar month*, from
   the daily cron, plus a manual "bill now" that is safe to press twice.
8. **Minimum** — *none by default* (`reseller.billing_minimum` = `0.00`). Below a
   raised minimum a month is carried into the next invoice rather than invoiced
   on its own or dropped.
9. **Unpaid cost** — *never auto-suspend.* Taking a reseller's storefront offline
   also takes their customers offline, so it stays an explicit admin action;
   arrears are surfaced in the report instead.

## 8. Status

- **Phase 1 — implemented** (2026-09-28). Migration `0185_create_resellers_table`;
  `ResellerStoreRepository`, `ResellerStoreLocator`, `CurrentReseller`,
  `DomainVerifier`, `ResellerStoreService`; tenant resolution in
  `Kernel::handle()`; per-tenant branding through `ThemeSettings::forCurrentSite()`,
  `brand_name()` and `SeoTags`; admin and client store screens.
- **Phase 2 — implemented** (2026-09-28). Migration
  `0186_add_reseller_retail_prices` (`resellers.markup_percent`,
  `reseller_prices`, `reseller_domain_prices`); `ResellerRetailPricing` + its
  repository; reseller price list at `/client/reseller/prices`; admin sees the
  markup and override count on the admin store page.
- **Phase 3 — implemented** (2026-09-29). Migration
  `0187_add_reseller_attribution_to_orders`; store-aware `CartService` and
  `CheckoutService`; order and account attribution; per-tenant catalogue cache
  key.
- **Phase 4 — implemented** (2026-09-29). Migration
  `0188_add_reseller_cost_invoicing`; `ResellerCostRepository`,
  `ResellerCostService`, `ResellerCostBillingJob`; admin screen
  `/admin/resellers/billing`; the reseller's own figures on
  `/client/reseller/store`.
- Phase 5 not started.

### Test-harness note (found 2026-09-29)

A killed test run leaves `codevault_test` unusable, and the symptom imitates a
migration-order bug. `DatabaseTestCase::setUp()` drops every table and *then*
migrates, so a process killed between the two leaves a populated `migrations`
table with no schema behind it. The next run fails somewhere mid-chain — I saw
`Table 'codevault_test.mail_campaign_recipients' doesn't exist` raised from
migration `0145`, and separately
`Duplicate entry '0133_add_client_id_to_mail_campaigns.php' for key 'migration'`.
Both disappear after:

```sql
DROP DATABASE IF EXISTS codevault_test; CREATE DATABASE codevault_test;
```

(`AbandonedCartTest` then passes 7/7.) Before believing any mid-chain failure,
reset the database — and never kill a run mid-migration.

`0145` is also genuinely fragile in isolation: its guard asks whether the
*column* exists, so when the *table* is missing it proceeds to `ALTER` a table
that is not there. It should check the table exists first (and skip), the way
`0134` guards each of its steps.

### Harness coupling fixed while landing Phase 3 (2026-09-29)

`CartCheckoutTest::setUp()` boots the real `Kernel` and then re-pinned only
`Database` to the test connection. Every other singleton the Kernel built —
`SettingsRepository` in particular — stayed bound to the application's
configured database (`clientmore_whmp`), so on any machine where that database
is unreachable 9 of its 16 tests died with
`Access denied for user 'clientmore_whmp'@'localhost'`, thrown from
`CheckoutService` when it resolves `SettingsRepository` lazily to stamp the new
invoice's due date. Nothing to do with the storefront — but it is the suite that
guards the *platform* checkout path, i.e. the one thing Phase 3 must be shown not
to have changed, so it was worth repairing rather than excusing.

Fix: re-pin the DB-backed singletons, not just `Database` (the same repair
`OrderCancellationTest` / `AcceptOrderJobTest` already carry):

```php
$container = \CodeVault\Support\App::container();
$container->instance(\CodeVault\Database::class, $this->db);
$container->instance(SettingsRepository::class, new SettingsRepository($this->db));
```

Result: `CartCheckoutTest` 16/16, 65 assertions. The general lesson is worth
keeping: **a container built at boot keeps the connections it was built with**,
and re-pinning one of them looks like it worked until something resolves another
one lazily.

## 7. Explicitly out of scope

- Issuing/renewing TLS certificates (server/edge configuration).
- Reseller-authored page content, templates or themes beyond brand name, logo,
  favicon and accent colour.
- Reseller-branded email sending domains (DKIM/SPF per tenant) — a reseller's
  customers will receive mail from our domain until that is tackled.
- Self-service reseller signup and KYC.
