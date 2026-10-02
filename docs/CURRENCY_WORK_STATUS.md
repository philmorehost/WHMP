# Multi-currency and billing work — status

**Branch:** `BUYAFROBEATS2` · **Shipped through:** `33f6976` (later commits on this branch are
unrelated reseller/statement work) · **Written:** 2026-10-01

This covers the workstream that started as a mailing-system security report and grew into the
admin billing lifecycle, invoice/order reactivation, and the multi-currency model.

---

## 1. Shipped

| Commit | What it delivers |
|---|---|
| `71d8b44` | Hardens the mailing system against unauthenticated bulk email (SMTP `RCPT TO:` CRLF injection reachable from public registration OTP) |
| `a2a7360` | Shows order domains on the admin pending-order page; surfaces a service's suspension reason at a glance and in the edit form |
| `6e8f671` | Ticket auto-assignment to an admin; billable-item lifecycle (create/edit/cancel/delete, client self-cancel); recurring invoices on the client Billing tab with next renewal date; "remind all unpaid" batch |
| `c1e3fc7` | Admin can reactivate a cancelled invoice |
| `1846111` | Fixes reactivation of invoices cancelled by the older code path (flag set, status left `unpaid`) |
| `c7f3377` | Admin can reactivate a cancelled order, reinstating its invoice |
| `fee03d4` | Clients can renew a service or domain before its due date |
| `d534068` | Cancelling an order cancels its invoice and services with it; reactivation restores them |
| `5a12335` | Payment status shown on admin orders placed through a gateway (status, gateway, paid date, invoice link) |
| `0ffc402` | Admin can switch a client's default currency, recalculating their billing |
| `23132a3` | That recalculation was scoped to live amounts only |
| `3b67225` | Adds the opt-in "convert settled records too" |
| `33f6976` | **Catalog prices are converted from the currency they were entered in** — the root-cause fix |

Migrations added: `0178` (registration OTP IP), `0179` (service suspension reason), `0180` (ticket
auto-assign setting), `0181` (billable-item status), `0182` (currencies.is_pricing).

---

## 2. Root causes fixed

### 2.1 The reported bug: ₦22,350 quoted, ordered and reported as $22,350

Every conversion in the app used `rateFor()` — "base currency → client currency" — for **catalog
prices** too. That is only correct when the catalog is priced in the base currency.

This install prices in naira while USD is the seeded default (`0037`). So the raw catalog figure was
multiplied by the USD rate (`1.0`) and the naira number was stored, quoted and reported wearing a
dollar sign, with no conversion applied anywhere. The mirror image of the same bug would have
charged an NGN client `22350 × 1490` = ₦33,301,500 for the same plan.

**Fix (`33f6976`)** — introduced the missing concept, *which currency the prices were typed in*,
independent of the default currency:

- `currencies.is_pricing` (migration `0182`) + `CurrencyRepository::pricing()` / `setPricing()`.
- `CurrencyService::catalogRate($currency)` = `rateFor($currency) ÷ rateFor(pricing currency)`.
- Applied to every fresh catalog read: `CheckoutService::convertPriced()` (the stored charge),
  `ProrationService`, `ServiceAddonService`, `ServiceController` package-price reset,
  `DomainController` register/renew, `DomainRenewalBillingService` redemption fee, and
  `CurrencyService::format()` for display.
- Admin control: `/admin/currencies` → "Prices in" column with a **💲 Prices** button.
- **Inert until marked.** With no flag set, `catalogRate()` is identical to `rateFor()` — the exact
  pre-existing behaviour, so no figure moves on deploy.

### 2.2 `currency_rate` was being written a conversion ratio

`ClientRepository::updateCurrency()` multiplied the stored amounts correctly, then wrote the
conversion **ratio** into `currency_rate`. Every read path treats that column as a display
multiplier (`shown = stored × rate`), so the already-converted amount was multiplied a second time:
an invoice that had correctly become $5.03 rendered as **$0.00**, and `PaymentCallbackController`
would have asked the gateway for the same figure.

**Fix (`0ffc402`)** — rows now land denominated in the target currency: `currency_rate = 1.0` and
`currency_id = NULL` for the base currency, matching `denominateColumns()`. Also fixed: `discount_amount`
was never converted (breaking `subtotal + tax − discount = total`), line items and transactions were
missed entirely, and the base currency's stored `exchange_rate` was read raw instead of through the
base-is-always-1.0 rule.

### 2.3 The storefront currency widget re-priced the whole account

`CurrencySwitchController` called `updateCurrency()`, so **any signed-in client clicking a currency
in the shop header re-denominated their entire account** — and back again on the next click, losing
value to rounding each round trip.

**Fix (`0ffc402`)** — it now calls `ClientRepository::setCurrencyPreference()`, which records the
preference and touches no amount. Only the deliberate admin change converts.

### 2.4 Promoting a base currency broke every other rate

`CurrencyRepository::setDefault()` pinned the promoted currency to `1.0000` and left every other
row's `exchange_rate` untouched. A rate reads "units per 1 base unit", so it is meaningless once the
base changes: promoting NGN kept USD at `1.0`, and the two converted **1:1**.

**Fix (`33f6976`)** — the outgoing base's rate is now divided out of every other row. `exchange_rate`
is `DECIMAL(18,8)` (widened by `0126` for exactly this kind of inverse rate), so `1/1490 = 0.00067114`
is representable.

---

## 3. Remaining

### 3.1 Set the pricing currency *(blocking — do this first)*

`is_pricing` exists in the schema (migration `0182`) and is read by the code, but **nothing sets it**:
there is no seeding migration and no admin action recorded. Unless it was set by hand on the live
install, it is `0` on every row — and until it is marked, the pricing fix is inert and the original
mis-quoting still happens on new orders.

**Action:** `/admin/currencies` → click **💲 Prices** on NGN.

### 3.2 Correct the already-placed order *(needs a decision)*

The existing order's rows hold the naira figure stamped as USD. No currency switch can repair it —
switching that client to NGN would multiply it to ₦33M, because the stored amount is mislabelled
rather than merely in the wrong currency.

Two options, both defensible:

- **Re-denominate** that client's rows at `÷1490` → `$15.00` (the client stays on USD).
- **Relabel as naira** — set the client to NGN and set those rows' `currency_id` to NGN with
  `currency_rate = 1.0`, leaving the amounts alone → `₦22,350`.

**Needed:** the client id / order id, and which of the two you want. A targeted correction is right
here; a blanket migration would also hit genuinely-correct USD rows and cannot tell them apart.

### 3.3 Client-side currency switch during the order *(built)*

Requested behaviour: if a client changes currency while ordering, the change should apply to their
**account** (not just the session), the amounts should recalculate into the new currency, and the
client must be **warned that all their amounts will change**.

**Built.** The cart/checkout page now carries a *Billing currency* panel that spells out the
consequences and requires a confirmation before it does anything. On confirmation it calls
`ClientRepository::updateCurrency()` (live amounts only — settled history keeps the currency it was
billed in) and answers with a warning, because the re-denomination re-rounds each amount.

`CurrencySwitchController` now has two distinct entry points, deliberately kept apart:

- `select()` (`POST /currency`) is the header/storefront picker. It records the choice **in the
  session only** — it no longer writes `clients.currency_id`.
- `applyToAccount()` (`POST /currency/account`) is the deliberate switch. It requires `confirm=1`,
  converts live amounts, and refuses (with a reason) otherwise.

**Why the picker stopped writing the preference column.** `clients.currency_id` is not a free display
preference — it is the *account* currency, the same column `updateCurrency()` reads to decide whether
there is anything to convert, and the column an unlocked (NULL-currency) document falls back to for
display. Writing it from a browse toggle left the account half-changed: the column said naira while
the amounts it labelled were still the base figures. Worse, the next deliberate conversion then saw
"already naira" and converted nothing. So the two operations are now genuinely separate, which is also
what §2.3 was protecting. `ClientRepository::setCurrencyPreference()` is therefore currently unused;
its docblock says so and warns against calling it from a currency switcher.

Tests: `tests/Unit/CheckoutCurrencySwitchTest.php` (5). The success path is paired with refusal and
"nothing changed" tests, and one test pins the browse picker as account-neutral — the `0ffc402`
regression. Verified red by disabling the confirmation check and by removing the conversion.

### 3.4 `client_credit_ledger` cannot follow a currency change

Left untouched by `updateCurrency()` in **both** modes, deliberately. Its rows mix client-currency
manual grants with base-currency credit-note totals (`CreditNoteService::issue()` adds the
base-denominated total to the ledger), so no single factor is correct for all of them.

Consequence: a client's credit **balance** still reads in the old units under the new symbol after a
switch. Fixing it means settling the ledger's convention first, then converting it — a separate change.

### 3.5 Historical documents are re-labelled with the client's *current* currency *(verified consistent; pinned)*

Sixteen query sites resolve a document's display currency as
`COALESCE(document.currency_id, client.currency_id, <default>)` — for example
`OrderRepository.php:34/111/130/156`, `InvoiceRepository.php:172/698/709`,
`AdminInvoiceController.php:70`, `ReportRepository.php:84/90/112/118/133/138/273/280`.

**Investigation result: the rule is already implemented consistently, and the one place it could
drift has been removed.** The fallback chain is intentional and is the rule the codebase committed
to — an unlocked document (`currency_id IS NULL`) shows in the CLIENT's currency, which is what the
client sees on their own invoice list, and the default is only the final fallback for a client with no
currency at all. `tests/Unit/OrderRepositoryCurrencyTest.php` already pins it, and the admin and client
invoice pages use the same rule in PHP (`currency_id !== null ? resolveLocked(id) : resolveForClient(client)`).
It is explicitly **not** "NULL means base currency" for display: that reading labels a naira client's
imported invoice `$7,501.50`, which is the symptom the fallback exists to prevent.

What was genuinely missing was a **guard**: the rule is written out in ~20 places, so one surface can
drift from another and the same stored figure then renders under two different symbols. That is now
pinned by `tests/Unit/DocumentCurrencyConsistencyTest.php`, which asserts the surfaces *against each
other* — the invoice list, the order list/detail, the invoice-detail formatter and the income report
must all resolve the same document to the same currency, with a companion test that a locked currency
still beats the client's current one. Verified red by pointing a single surface at the base currency
(`expected NGN, got USD`).

**One real interaction was found and fixed while building §3.3:** the header picker used to write
`clients.currency_id`, so a single browse click silently re-labelled every unlocked document on the
account (see §3.3). It now leaves that column alone, so this class of drift cannot be triggered by a
visitor any more.

**What is deliberately NOT done.** Rewriting pre-locking/imported rows so the fallback is never
needed. A backfill would have to guess whether a NULL row means "base currency" (a client who was on
base, then switched) or "the old writer stamped nothing" (a genuinely naira amount) — and those two
cases are indistinguishable in the data, so any blanket migration would mislabel one of them. The
reader rule above is correct for both *as stored*, and a targeted correction is only appropriate once
the specific rows are known.

### 3.6 Smaller open items

- New clients default to the default currency (USD). With `is_pricing` = NGN they will now correctly
  see `$15.00` for a naira-priced plan — decide whether new clients should default to NGN instead.
- `AdminOrderControllerTest` / `CartCheckoutTest` assert on order/invoice currency locks; they were
  not re-run after `33f6976` (see §4).

---

## 4. Verification status

**Local MariaDB could not be started for any of this work.** `mysqld` aborts with
`Could not open mysql.plugin table` → `Failed to initialize plugins` → `Aborting`: the XAMPP `mysql`
system schema is unreadable (same Aria corruption family noted in repo memory). InnoDB itself starts
fine. Consequently every `DatabaseTestCase`-derived test **skips** rather than runs.

What was verified instead:

- **`php -l` on every changed file** — clean (`C:\xampp\php\php.exe`; PHP is not on PATH).
- **SQL/binding harnesses.** `CodeVault\Database` is not `final`, and neither are its query helpers,
  so a subclass records every `[sql, bindings]` pair with no server. Used to assert placeholder count
  == binding count (a mismatch means PDO throws, the transaction rolls back, and the change silently
  does nothing), correct conversion ratios, and that the settled-record filters appear only in the
  intended mode.
- **Pricing-currency arithmetic**, driven through the real `CurrencyRepository`/`CurrencyService` on a
  stand-in database, reproducing the reported case:

  ```
  pricing currency = NGN (rate 1490), base/default = USD
  catalogRate(NGN) is 1                     OK  1
  catalogRate(USD) is 1/1490                OK  0.00067114
  naira client sees the price as typed      OK  ₦22,350.00
  dollar client sees the equivalent         OK  $15.00
  pricing currency unset (falls back to USD)
  dollar figure unchanged                   OK  $22,350.00   ← no behaviour change
  setDefault() with a corrupt base 1490     OK  rebases by 1490
  ```

**Not verified:** anything requiring a real database — migrations `0178`–`0182` against MySQL,
`updateCurrency()` end-to-end, the `/admin/currencies` UI, and the PHPUnit suite. All throwaway
harnesses were deleted after use. Re-running the suite once MySQL is healthy is the first thing to do
before anyone depends on this in production.

---

## 5. Money model reference

The rules below are what the fixes encode. They are easy to re-break — `§2.1` and `§2.2` were both
caused by violating one of them.

1. **Every stored amount is in the client's own currency.** Nothing converts on the read path.
2. **Two currency roles, and they are not the same thing.**
   - `currencies.is_default` — the unit every rate is quoted against; its own rate is pinned `1.0`.
   - `currencies.is_pricing` — the currency catalog prices were *typed* in.
3. **Use `CurrencyService::catalogRate()` for catalog prices** (products, add-ons, domain pricing,
   configurable options). `rateFor()` answers "base → currency" and is only correct for catalog
   prices when the pricing currency *is* the base currency.
4. **`currency_rate` is a display multiplier**: `shown = stored × rate`. Never store a conversion
   ratio in it.
5. **The base currency is always stored as `currency_id = NULL`**, never the default's own id.
6. **The base currency's `exchange_rate` is 1.0 by definition**, whatever the column happens to say.
   Read it through `CurrencyService::rateFor()` / `formatLocked()`, never the raw column. A stale
   `1490` there put ₦11,177,235 on a ₦7,501.50 invoice.
7. **A cancelled invoice/order has two representations** — `status` and the `is_cancelled` flag.
   Always handle both.
8. **`updateCurrency()` is the deliberate admin action; `setCurrencyPreference()` is the passive one.**
   `updateCurrency($id, $currencyId, $includeSettled = false)`: default converts only live amounts;
   `true` drops every status filter so the whole account reads in the new currency.
   `client_credit_ledger` is untouched in both modes (see §3.4).
