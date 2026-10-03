# WHMP — work status report

**Date:** 2026-10-03 · **Branch:** `BUYAFROBEATS2` · **HEAD:** `3a95998` · **Working tree:** clean, in sync with origin

---

## Scope and provenance

This report covers **the work done in this engagement**: the reseller / white-label workstream
(storefront, ticket desk, mail, portal) and the currency-and-invoice correctness work that grew out
of it.

It does **not** cover the earlier build history of the platform (the R0–R31 roadmap: installer,
billing engine, provisioning, domains, GDPR, 2FA, imports, and so on). Those are documented in
`CodeVault_WHMCS_Parity_Build_Blueprint.md`. Commit hashes below are the ones from this engagement's
history; where a behaviour is described without a hash it predates this engagement.

## Environment (how to run anything here)

- PHP is **not on PATH** — use `C:\xampp\php\php.exe`.
- MySQL/MariaDB: `C:\xampp\mysql\bin\mysql.exe` (root, no password, locally).
- Tests: `vendor\bin\phpunit --configuration phpunit.xml [--filter X]`.
- **Never pipe PHPUnit through `Select-Object`** — it buffers and the run looks hung. Use
  `Start-Process ... -Wait -PassThru -RedirectStandardOutput <file>`. Long runs get moved to the
  background by the terminal; that is expected, not a hang.
- The test database `codevault_test` is **shared**. Confirm `(Get-Process php).Count` is `0` before
  any DB-touching run, or two suites race and produce a distinctive mixed-failure signature.

---

# 1. Completed

## 1.1 White-label storefront — `8185f9a`

The `/store` page is the front page of every reseller's site, and it ignored their branding entirely.

- **`public/assets/css/store.css`** (new, ~505 lines): `.store-hero*`, `.store-group*`, `.store-grid`,
  `.store-card*`, `.store-empty*`, with breakpoints at 900/640/400/1400, `prefers-reduced-motion` and
  dark-scheme support. Tenant tints use `color-mix()` against `--cv-color-brand-500`.
- **`resources/views/cart/store.php`** rewritten — **zero colour literals**, `$theme ??= []` guard,
  empty product groups skipped, hero CTA now scrolls to `#plans` instead of pointing at a login wall.
- **Removed fabricated product claims.** Feature bullets and badges were chosen by substring-matching
  the product *name* (`google` → "30GB Secure Cloud Storage"), so any product a reseller later named
  `...google...` inherited invented claims. That is mis-selling; a card now shows only the product's
  own description and its real price.
- `tests/Unit/StorefrontLayoutTest.php` — 11 tests, **no database**. `docs/STOREFRONT_DESIGN.md`.

**Why it mattered:** `layouts/client.php` overrides only `--cv-color-brand-500`/`-600`; every other
brand step still describes the *platform* palette. There is now a test enforcing that the stylesheet
only uses steps a tenant can override.

## 1.2 Reseller ticket desk — `3c6ae48`

- `core/Reseller/ClientResellerTicketController.php` (new) — `index`, `show`, `reply`, `escalate`,
  `withdraw`.
- `core/Reseller/AdminResellerTicketController.php` (new) — the platform escalation queue. **No reply
  button by design**: the full `/admin/tickets/{id}` page exists, and only `authorType === 'admin'`
  clears an escalation, so a second thinner box would duplicate that rule.
- Views `resources/views/reseller/{client-tickets,client-ticket,admin-escalations}.php`; routes in
  `routes/reseller.php`; "Store Escalations" in the admin nav.
- The conversation's **author labels are the feature** — ours reads "From our support", theirs
  `You (<brand>)`, asserted to appear exactly once.
- No store id appears in any path: the store comes from the session guard and the ticket is scoped
  *inside* the query, so "not yours" and "does not exist" get identical answers.

## 1.3 White-label mail

### a) A store can send as itself — `6f9188c`

**A real bug, worse than the branding it was meant to deliver:** the `TICKET_REPLY` listener in
`core/Kernel.php` branched on `admin` and `client` and had **no `reseller` case**. A store answering
its own customer emailed **nobody** — the reply was stored, the ticket moved to `answered`, and the
customer was never told.

- `Mailer::send()` gained `?array $from = null`. Threaded through `SendEmailJob` (**appended with a
  default**, so a job already serialized in the queue still unserializes), `EmailDispatcher`,
  `SmtpMailer` (override applied *before* `assertSafeAddress`) and `LogMailer`.
- **11 anonymous `implements Mailer` test doubles** had to change in the same edit — a parameter on
  an interface cannot be optional-and-ignored.
- `core/Reseller/ResellerMailIdentity.php` — the *name* is always the store's; the *address* only when
  set and valid, because `SmtpMailer` validates the sender and a typo would throw and lose the message.
- `core/Reseller/MailDomainAlignment.php` — SPF/DKIM check. Recognises `include:`, bare `a`/`mx`; an
  empty `p=` is a **revoked** DKIM key, not an aligned one; `UNAVAILABLE` is not `MISALIGNED`.
- Migration `0202`; `tests/Unit/MailSenderOverrideTest.php`, `ResellerMailIdentityTest.php`,
  `MailDomainAlignmentTest.php`.

### b) A store gets a mailbox on its own domain — `053fcd0`, `253421d`

- `core/Reseller/ResellerMailboxProvisioner.php` — creates the mailbox; `ResellerMailboxJob` runs it
  as a **daily sweep** (registered in `bin/cron.php`, not the Kernel), because a store can become
  eligible in ways domain verification does not cover.
- Page `/client/reseller/mail`; migration `0203` (`mailbox_host`, `mailbox_provisioned_at`,
  `mailbox_provision_error`).
- **Rule adopted:** a store with no address is *already* white-labelled (its brand name goes on the
  message, the platform's authenticated address carries it). Adopting the store's own address is not
  an upgrade — it replaces authenticated mail with mail that gets filtered. So the mailbox is created
  either way, but the address is adopted **only when alignment says aligned**.

### c) Piped mail lands on the right desk — `e033af7`

- `MailPipingJob` read the recipient from the wrong field and never looked the address up, so **every**
  piped message was filed against the platform. It now resolves it via
  `ResellerStoreRepository::forSupportEmail()` and passes the owning store id down.
- `TicketService::open()` gained a trailing `?int $resellerId = null`, leaving all seven existing call
  sites untouched.
- `ResellerMailboxProvisioner` also creates the cPanel **forwarder** that feeds the store address into
  the mailbox the job sweeps — on both success paths, treating the panel's "already exist" as success
  so re-runs stay idempotent.
- The lookup is **case-insensitive on purpose**: a case-sensitive compare would not error, it would
  silently file the ticket in the wrong queue.

## 1.4 Portal navigation and layout — `21f85d0`, `c4a37ff`

- The same link list had been hand-written in **eight views**, each with its own subset — which is how
  the support desk and the support address each shipped with only a partial set of links. Replaced by
  `resources/views/partials/reseller-nav.php` (8 destinations) + `public/assets/css/reseller.css`
  (`.rs-shell`, `.rs-nav`, `.rs-head`, `.rs-stats`, `.rs-stat`, `.rs-actions`).
- The partial emits its own stylesheet link, so a new page cannot forget it.
- **The active item is derived from `$_SERVER['REQUEST_URI']`**, not passed in: the hub matches
  exactly only (it is a prefix of everything), sections match themselves or a child, longest match
  wins, and the value is guarded with `?? ''` because a CLI render has none.
- Page pass: `cv-card__title` headings → `.rs-head`, inline-styled count blocks → `.rs-stats` grids,
  the account page's balance moved off a 3-column table that needed a horizontal scrollbar on a phone.

## 1.5 Currency correctness

- **`9e2c286`** — the pricing-currency flag (`currencies.is_pricing`) had **no test coverage at all**.
  Every `setPricing(` in the suite belongs to `ProductPricingRepository`, a different method on a
  different class that merely shares the name. Added `tests/Unit/CurrencyPricingFlagTest.php` (6 tests)
  pinning the unmarked fallback, the one-row invariant, the `$15.00` / ₦22,350 conversion, the pair
  that shows the flag is what makes a naira client's price correct, and that a visitor's own currency
  still wins over a naira catalogue.
- **`fc9808e`** — corrected `docs/CURRENCY_WORK_STATUS.md`, which instructed the operator to mark NGN
  as the pricing currency. On this install that would divide every price by 1490.

## 1.6 Invoice integrity

### a) The double-converted invoice — production fix

Invoice **3617** (client 237, order 37) held four rows at `15 × 1490²` = **33,301,500** while its
`total`, the order total and the service amount all correctly said **22,350**:

| Row | Before | After |
|---|---|---|
| `invoices.subtotal` (3617) | 33,301,500 | 22,350 |
| `invoice_items.amount` (5582) | 33,301,500 | 22,350 |
| `order_items.unit_price` (29) | 33,301,500 | 22,350 |
| `transactions.amount` (2591) | 33,301,500 | 22,350 |

The last is the serious one: a **`completed`, `manual` payment recorded at 1490× the invoice total**,
with a `NULL` gateway reference, created in the same second as `paid_at`.

### b) A discounted invoice did not add up — `8b54387`

Every discounted invoice in the book (7 of them) printed
`Sub Total 461.90 / Promo Discount −59.60 / Total Due 461.90`.

`subtotal` is **GROSS** by convention: `total = subtotal + tax − discount`. `CheckoutService` was the
one writer that did not — it stored the cart's *net* total — and it also wrote the discount a second
time as a negative line item. Both fixed; **`total` was not touched**, so no amount charged moved.

### c) The 7 existing invoices back-filled

Subtotals grossed, promo line items removed, `total` unchanged, `ROW_COUNT()` 7 and 7. Whole-book
count of invoices failing the identity fell **304 → 297**.

### d) Every invoice writer audited — `3a95998`

All 12 sites that create or recompute an invoice were checked. **`CheckoutService` was the only
violator.** `WhmcsImportService` is *not applicable by design* — it preserves the source system's
figures rather than recomputing them, which is where the remaining 297 came from.

Guards added where the arithmetic actually carries weight:
- `massPay` sums each source invoice's four columns, so a consolidation is correct only *because* its
  sources were. Its test helper only ever made discount-free invoices (trivially true, unfailable).
  Now takes a discount and asserts the sources first so the test cannot be vacuous.
- Domain renewal, where a redemption fee is folded into `subtotal`.

---

# 2. Production data changes

All applied on 2026-10-03 to `clientmore_whmp` with value-guarded statements (`ROW_COUNT()` verified),
each with a reversal script:

| Change | Scope | Reversal |
|---|---|---|
| Double-converted rows → 22,350 | 4 rows on invoice 3617 | `%TEMP%\whmp_remote_restore.sql` |
| Subtotals grossed, promo rows removed | 7 invoices (3438, 3542, 3557, 3562, 3567, 3590, 3632) | `%TEMP%\whmp_backfill_reverse.sql` |

Before-states: `%TEMP%\whmp_remote_verify_out.txt`, `%TEMP%\whmp_before_out.txt`.

**Run the double-conversion reversal only if the ₦33,301,500 turns out to have been a real bank
transfer.** The evidence says it was computed, but you know better than I do.

---

# 3. Verification record

Nothing was accepted on "it ran". Every central property was **deliberately broken once** to confirm
the test catches it; a gate nobody has seen fail is a gate nobody should trust.

| Run | Result |
|---|---|
| `CurrencyPricingFlagTest` | 6 / 13 — control: 4 red, 2 green (the 2 that don't depend on it) |
| `CurrencyPricingFlagTest\|CurrencyServiceTest\|CartCheckoutTest` | 48 / 116 |
| `CartCheckoutTest` | 17 / 70 |
| Control — net subtotal restored | `12.99 vs 7.99`; identity `11.99 vs 10.99` |
| Control — negative promo item restored | `size 1 matches expected size 0` |
| `CartCheckoutTest\|AdminOrderControllerTest\|ExistingOrderTest` | 27 / 117 |
| `ResellerStoreCheckoutTest\|AutoSetupProvisioningTest\|OrderAcceptanceIntegrationTest` | 15 / 71 |
| `ClientMassPaymentTest\|DomainRenewalBillingTest` | 22 / 92 |
| Control — massPay net subtotal | `220.0 is identical to 250.0` |
| `ResellerMailboxProvisionerTest\|MailPipingJobTest` | 32 / 249 |
| Ticket suites (`TicketRelatedItem\|Split\|Merge\|ClientTicketController`) | 22 / 91 |
| `AdminClientMessageTabTest\|ClientTicketControllerTest` | 14 / 50 |
| Earlier in this engagement: storefront, nav, portal-page batches | 49 / 212 · 95 / 408 · 100 / 447 |

---

# 4. Remaining — needs your decision

| Item | Recommendation |
|---|---|
| **The 297 historical mismatches** | **Leave them.** Import debris: `discount_amount = 0` with `total < subtotal`, created 2016-03-01 → 2026-06-22, all at `00:00:00`, no credit note behind any, 149 zeroed write-offs, none unpaid |
| **Invoice 3419** — 5% late fee applied twice | Cosmetic, on a cancelled invoice; the only duplicate among 536. Clean or leave |
| **`whmp_prod_snapshot`** | Your production data in a local database. Drop, or keep and point the app at it to develop against real data |
| **Statement issuing permission** | Gated on `resellers.manage` — the same permission that merely *reads* the account, though issuing is write-and-irreversible. May deserve a narrower gate |
| **Statement legal layout** | Field names are conventional; no jurisdiction-specific tax-document format. Figures and the freeze do not change — only the rendering |

# 5. Remaining — needs you, cannot be automated

1. **Rotate the production MySQL password.** Every credentials file has been deleted on both sides,
   but the password is in the chat transcript.
2. **Live-verify the cPanel mailbox calls** (`Email::add_pop`, `Email::add_forwarder`). The UAPI proxy
   has only ever been confirmed for WHM API 1 `version`, and `AddonDomain` turned out not to exist on
   your panel. This is the last gap in the white-label mail feature.
3. **Paystack / Flutterwave** have never run against the real APIs. The pipeline around them is tested
   (signed webhook, idempotency, no false payment); a sandbox key closes it.

# 6. Remaining — unbuilt features

- **Automatic payout rails** (`disburse()` / `transfer()`). *Verified still missing* — the only
  `transfer()` methods in the codebase are registrar **domain** transfers. `GatewayModule` has no
  payout concept. Destinations already exist, so only the rail is absent.
- **Weekly cost billing.** *Verified*: `duePeriods()` groups by `substr(created_at, 0, 7)`, so calendar
  months only, and the settings deliberately do not offer weekly.
- **Per-currency payout minimum** — one `50.00` figure for all currencies, deliberately deferred.
- **`client_credit_ledger` following a currency change** — its rows mix client-currency grants with
  base-currency credit-note totals, so no single factor fits. Needs the convention settled first.
- **CSF / nginx firewall sync** — BruteGuard's app-level gate is live; the real firewall sync needs a
  CSF-equipped server to be meaningful.

# 7. Deliberately out of scope

Written down so they are not rediscovered as bugs: issuing/renewing **TLS certificates** for custom
domains · **reseller-authored page content** beyond brand name, logo, favicon and accent colour ·
**self-service reseller signup and KYC** · **collapsing** `api_credentials.reseller_domain` with
`resellers.custom_domain` (different jobs: a key-activation gate vs a served hostname) · **domain
re-verification is never repeated** (a domain that stops resolving keeps its verified mark until an
admin removes it) · **new clients default to the currency flagged `is_default`** (USD today).

---

# 8. Rules this work established

These are the invariants the code now encodes, and the ones most easily re-broken.

1. **An invoice's `subtotal` is GROSS** — `total = subtotal + tax_amount − discount_amount` must hold.
   The detail page, the PDF and the admin edit path all render or recompute from it, and both
   `DunningJob`'s 5% late fee and `massPay()`'s consolidation read `subtotal`. A discount belongs in
   `discount_amount` **once** — never also as a negative line item, never pre-subtracted.
2. **`currencies.is_pricing` unset is not an error** — it means "the catalogue is in the base
   currency". Do not mark it unless the catalogue was actually typed in that currency. If you ever
   want a naira catalogue: re-price first, mark second.
3. **Which currency a customer is charged in** = session choice → the client's own `currency_id` →
   the default. `is_default` is the separate lever for what *new* clients start in.
4. **Gateways settle in their own `gateway_currency`**, bridged by `crossConvert()` — a no-op when the
   codes match. `GatewayFeeCalculator` only applies fees when that currency is NGN.
5. **Relabelling a document is not converting it.** Set `currency_id` and `currency_rate = 1.0`;
   never use `ClientRepository::updateCurrency()` for a relabel — it *converts*.
6. **A store's reply is `author_type = 'reseller'`**, and `TicketService::isSupportAuthor()` is the one
   definition of "who is support" — used by the status transition, both AI transcript labellers and
   the staff/customer split.
7. **`tickets.reseller_id` is derived, never passed** — from `clients.reseller_id` inside
   `TicketRepository::create()`, which is what makes the portal form, admin-created tickets and mail
   piping all stamp it with no caller changes.
8. **`resellers.domain_provisioned_host` records what is on the hosting panel and must not be cleared
   by `setCustomDomain()`** — clearing it strands a hostname that would keep answering as our shop.
9. **A hook point that is declared and never fired is dead code**, and logic hung off it fails
   silently. Check for the `fire(` call before attaching behaviour to an event.
10. **A test that agrees with the bug is worse than no test.** Several were found and fixed in this
    work: a fake that used `?? []` (which cannot return null) agreed with the very collapse under
    test; a vacuous idempotency test passed with its guard deleted; `CartCheckoutTest` asserted the
    duplicate promo line *existed*.

# 9. Artifacts on disk

**In the repo:** `docs/STOREFRONT_DESIGN.md`, `docs/CURRENCY_WORK_STATUS.md` (corrected),
`docs/RESELLER_DOMAIN_SETUP.md`, `docs/RESELLER_PAYOUTS_PLAN.md`, `docs/RESELLER_STOREFRONT_PLAN.md`,
`docs/RESELLER_STORE_CHAT.md`.

**Local, not committed** (`%TEMP%`): the reversal scripts, the before-state captures, and the SQL used
for each diagnostic. **Local database:** `whmp_prod_snapshot` — a copy of the production dump, useful
for developing against real data.

**No credentials remain on disk** — the MySQL options file used for the production work was deleted
after each use.

# 10. Accepted residues (known, not defects)

- **Invoice 3438** — `subtotal` `1.000130` against an item sum of `1.000000`. A sub-cent rounding that
  predates the changes, well inside the 0.01 tolerance used throughout.
- **The 297** — see §4. Historical, and deliberately untouched.
- **`massPay`'s item lines sum to `total`, not to `subtotal`** — the lines carry each source invoice's
  net. The identity `subtotal + tax − discount = total` still holds, so the arithmetic reads correctly;
  the lines simply do not add up to the gross subtotal the way a checkout invoice's do.
