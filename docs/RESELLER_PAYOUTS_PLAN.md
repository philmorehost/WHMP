# Reseller payouts — plan

Status: **plan for review.** Nothing here is built yet.

## 1. The gap this closes

The storefront plan decided (§6 item 1) that **we collect**. A customer buying at
a reseller's store pays **us** at retail through the existing gateways, and Phase 4
invoices the reseller for the order's cost.

Follow the money and the hole is obvious:

```
customer pays us retail          →  we hold 100% of it
we invoice the reseller for cost →  the reseller owes us part of it back
the reseller's margin            →  where is it?
```

As built, the reseller's **margin has no path back to them**. Phase 4 raises a
demand for money we are already holding, which is a strange thing to send a
reseller: an invoice for cash we never gave them. Phase 4 is correct as a
*record* of what we keep, but on its own it leaves the reseller's own money
stranded with us.

That is what this plan fixes. It is not an optional extra — without it, the model
we chose in §6 cannot actually pay a reseller.

## 2. The shape of the solution: one running account

The cleanest model is a single running account per store, which nets everything
automatically and reuses Phase 4 rather than replacing it.

**Every movement in and out is a ledger entry:**

| Direction | Kind | Amount | When |
|---|---|---|---|
| `+` | `store_receipt` | the customer's **retail** total, in the account's base unit | the customer's invoice is **paid** |
| `−` | `cost_invoice` | the **cost** of that order, per the monthly cost invoice | when Phase 4 raises the invoice |
| `−` | `payout` | what we actually sent them, at the rate we sent it at | when a payout is paid |
| `±` | `adjustment` | manual, admin-only, with a reason | as needed |

**The account is kept in ONE unit — the base currency — and shown in the
reseller's currency at the live rate.** That is the direct consequence of the FX
decision (§9 item 7): the reseller carries the movement, so the figure they see
is a conversion of a stable underlying amount rather than a number frozen on the
day of the sale. Two things follow, and both are intended:

- **The balance a reseller sees moves day to day**, with no new sales at all. The
  figure on the day of a sale is not a promise, and the UI must not present it as
  one.
- A payout **locks the rate at the moment it is paid** and records it, so the
  amount actually sent is auditable and the money leaving the account is exact.

**Balance = sum of the entries.** Then:

- Balance ≥ minimum → the reseller can withdraw it.
- Balance = 0 → nothing owed either way.
- Balance < 0 → **they owe us**: they priced below our cost, which §6/§7 already
  says is their problem. A payout can never take the balance below zero.

**Refunds and chargebacks append, they do not edit.** A refunded order reverses
*both* sides — the receipt and the cost — as two further entries. The original
entries are never edited or deleted: an append-only ledger has to keep the story,
and "why is this balance what it is" is the only question the account exists to
answer.

**Decided 2026-09-28:** this running-account model, crediting a receipt when the
customer's invoice is **paid**, settling cost invoices **from the balance**, and
reversing **both** sides on a refund. See §9.

This is worth being precise about, because it resolves the apparent redundancy in
Phase 4: the cost invoice is **not** a separate demand for cash. It is the debit
side of the same account, and it is settled out of the reseller's balance — the
retail we are already holding. The reseller sees one number ("we owe you $X")
instead of a bill and a payment crossing in the post.

### Why not "we keep cost and just pay margin" instead?

Both nets to the same figure, but the running account keeps Phase 4 (and its
audit trail) intact and makes a below-cost reseller fall out naturally as a
negative balance rather than as a special case. It also means one table answers
"what do we owe this store, and why" for any period.

## 3. What already exists, and what must NOT be reused

Reusable:

- **The client account and invoice path** — cost invoices and payouts both live on
  the reseller's ordinary client account, so they age, print, and appear in the
  client area like everything else.
- **`ResellerCostService::inResellerCurrency()`** — the one place that converts a
  stored order amount into the reseller's currency. Store receipts must use the
  *same* method as cost, or the two sides of the ledger can disagree by a cent and
  the balance becomes untrustworthy.
- **The guarded one-way update pattern** from Phase 4's cost-invoice claim
  (`... WHERE status = 'pending'`), for every payout state change.

**Not reusable — `client_credit_ledger`.** My first instinct was to accrue store
receipts as account credit and let the existing "apply credit" engine settle the
cost invoice, which would have needed no new money code. Having read
`0036_create_client_credit_ledger_table.php`, that is wrong for this purpose:

```sql
client_credit_ledger(id, client_id, amount, reason VARCHAR(255),
                     invoice_id, admin_id, created_at)
```

- **There is no `currency_id`.** The table's own comment says amounts are added
  and spent against invoices; nothing records what currency an entry is in, and
  the codebase already mixes client-currency grants with base-currency credit-note
  totals in there. A ledger we are about to **pay money out of** cannot have an
  ambiguous unit.
- **`reason` is free text, with no `kind`.** The payout account needs typed
  entries (`store_receipt`, `cost_invoice`, `payout`, `adjustment`) so a balance
  can be explained and audited by kind, not by reading prose.
- Adding a currency column to it would force us to decide the currency of every
  existing row — unknowable, because the existing rows genuinely mix currencies.

**Recommendation: a dedicated `reseller_ledger` with explicit `currency_id` and
`kind`.** If a reseller's payout balance should also be spendable against their
own invoices later, that is a deliberate integration between two ledgers, not a
reason to conflate them.

## 4. The existing precedent — and why it is a warning

`AffiliateService` already implements almost exactly this flow for affiliates:

- commissions carry a status of `pending → requested → paid` (or back to pending
  on rejection);
- `requestPayout($affiliateId)` refuses when a request is already outstanding,
  refuses below `affiliates.min_payout`, flips pending → requested, and records a
  request for the pending total;
- `approvePayout()` marks the commissions paid; `rejectPayout()` reverts them.

**Reuse the shape**, with one deliberate difference: the affiliate model puts the
state *on the commission rows* (`pending|requested|paid`), which cannot express a
payout larger than one commission, nor a running balance netted against costs. The
reseller account needs signed ledger entries, so the *flow* is the same and the
*data model* is not.

**And it is a cautionary tale.** While running the full test suite I found that
`AffiliateService::requestPayout()` referenced an **undefined `$pending`**, so the
minimum-balance comparison was `null < $minPayout` — true for every positive
minimum. **Every affiliate payout request was refused** with "minimum balance
required", however much the affiliate had earned: the feature could never pay
anybody. It stayed invisible because the only test that asserted a refusal
(`zero balance fails`) passed for the wrong reason, and no test asserted that a
request with a healthy balance *succeeds*.

Fixing it exposed a second defect that the first had been hiding: the same method
reached into the global container for its settings, so it threw
`App container has not been set` as soon as it was unit-tested — a line that had
never been reached, because the bogus check returned first.

Two lessons that apply directly to the reseller payout work:

1. **A test that asserts a refusal can pass for entirely the wrong reason.** Every
   refusal needs a companion test proving the success path, or the refusal is
   untrustworthy.
2. **Read money rules from injected dependencies, not the global container.**
   Reaching for `App::container()` means the path can only be exercised through a
   full Kernel boot, which is precisely how a completely dead feature stayed
   green.

## 5. Request → approval → payment

Paying money out is a fraud target, so the flow is deliberately asymmetric: the
reseller can only *ask*.

1. **Reseller requests** a payout from their store page. Constraints:
   - amount ≥ configured minimum, and ≤ current balance;
   - a payout method on file (bank details, or a configured payout gateway);
   - only one open request at a time (a second request while one is pending is
     refused — otherwise the balance can be double-spent across two requests).
2. **Admin reviews** a queue of pending payouts: amount, store, balance, history.
3. **Admin approves** (records method + reference, marks paid) **or rejects** with
   a reason. A rejected payout is closed, and the funds stay in the balance.
4. **Marking paid writes the ledger entry.** The balance is always recomputed by
   summing entries — never a stored, mutable balance column that can drift out of
   step with the entries that justify it.

States: `pending → paid | rejected | cancelled`. Each transition is a one-way
guarded update (`WHERE status = 'pending'`), so a double-click cannot pay twice —
the same shape as the cost-invoice claim guard in Phase 4.

## 6. The hard parts

- **When is retail credited?** *Decided (§9): when the customer's invoice is
  PAID.* That is the moment we actually hold the money; crediting at order
  creation would create a withdrawable balance funded by money nobody has paid.
- **Refunds and chargebacks.** *Decided (§9): reverse both sides*, the receipt
  **and** the cost, as appended reversing entries. The alternative — keeping the
  cost owed on a sale the customer got refunded — would leave a reseller owing us
  for a sale they made in good faith.
- **Holding period.** *Decided (§9): 30 days.* A receipt becomes withdrawable 30
  days after the payment that funded it, which covers the card chargeback window.
  It gates **withdrawal only** — the balance and the entries behind it are visible
  from the moment they happen. Phase A therefore has to answer two different
  questions ("what is the balance?" and "how much of it is withdrawable?"), which
  is why `withdrawable_at` is a column on the entry rather than a rule applied at
  display time.
- **Currency and who carries FX.** *Decided (§9): the reseller carries it.* Entries
  are therefore kept in the account's **base unit** and converted to the reseller's
  currency only for display and at payout — not fixed at the moment of receipt.
  This is the one decision that changed the design instead of filling in a blank:
  fixing the rate at receipt would have been simpler and would have made the
  reseller's balance stable, which is precisely the risk they have now taken on.
  See §2 for the two things a reseller will notice.
- **Tax and reporting.** Paying a reseller is a payment to a third party, which
  may create reporting obligations (a statement or a self-billed invoice). Out of
  scope here, but it must be designed before any real money moves.
- **KYC.** Minimum, manual approval, one-open-request, and an admin-visible
  history are the first line of defence. Anything automated should wait until
  these are in place and have been used.

## 7. Data model sketch

```
reseller_ledger                     (new — typed, ONE unit per account)
  id, reseller_id, client_id,
  kind ENUM('store_receipt','cost_invoice','payout','adjustment'),
  amount DECIMAL(18,6)   -- BASE-currency, signed: + owed to them, - owed by them
  withdrawable_at TIMESTAMP NULL,   -- NULL on debits; receipt date + 30 days
  order_id, invoice_id, payout_id, description, admin_id, created_at
  INDEX (reseller_id, created_at)

reseller_payouts
  id, client_id, reseller_id,
  amount DECIMAL(18,6),             -- in the reseller's currency, as sent
  currency_id, currency_rate,       -- the rate LOCKED at payout, recorded
  amount_base DECIMAL(18,6),        -- what actually left the account
  status ENUM('pending','paid','rejected','cancelled'),
  method VARCHAR(32), reference VARCHAR(191), note TEXT,
  requested_at, decided_at, decided_by
```

**No new balance column anywhere**, on either table: a balance that is stored *and*
derived will eventually disagree with itself. The balance is
`SUM(reseller_ledger.amount)` for the reseller — **one unit, and no `currency_id`
on the ledger**, because an account that mixes units has no total. (That is
exactly why `client_credit_ledger` cannot be reused: it mixes client-currency
grants with base-currency credit-note totals, so no single factor ever fitted it.)

The withdrawable figure is the same sum restricted to `withdrawable_at <= NOW()`.
Two numbers, one table, no extra state to keep in step.

Money columns are `DECIMAL(18,6)` to match every other money column since
migration 0126.

**A consequence of storing the maturity date, which an operator has to know.**
Because `withdrawable_at` is written per entry when the entry is written,
changing `reseller.payout_holding_days` governs receipts collected from then on
and does **not** retroactively release money already in hand. Shortening the
period does not free everything that is waiting; lengthening it does not re-hold
anything that has matured. Both directions fail safe — an entry is only ever held
*longer* than a later policy would choose, never paid out earlier — which is the
right direction for a control that exists to cover a chargeback window. If
retroactive release is ever wanted, it has to be a deliberate migration that
rewrites `withdrawable_at`, not a side-effect of a settings form.

## 8. Phases

- **Phase A — the account (report only).** ✅ *Built.* Accrue a store receipt when
  a store order's invoice is paid; record the cost invoice as the debit; show the
  reseller their balance, how much of it is withdrawable (30 days — §9 item 5), and
  the entries behind both. No movement of money.
- **Phase B — request and approve.** ✅ *Built.* Reseller payout requests; admin
  queue; approve/reject/mark-paid with a reference. Payment is **manual bank
  transfer** — the simplest thing that can be honest.
- **Phase C — netting and statements.** ✅ *Built.* Cost invoices are settled from
  the balance automatically (§10.1, commit 591fe99); the ledger exports as a CSV for
  accounting (§10.3, commit 2696065); and the period **statement** exists both as a
  live view and as a NUMBERED, IMMUTABLE document (§10.2), with the reseller able to
  read and keep their own.
- **Phase D — methods and automation.** ◐ *Partly built.* Paying a payout out is
  still a manual bank transfer, but a request is no longer silent: requesting one
  fires `HookPoints::RESELLER_PAYOUT_REQUESTED`, and the listener emails **every
  admin who can release it** — derived from the permission that gates the payout
  queue (`resellers.manage`), plus every super admin, because `is_super_admin`
  bypasses the permission matrix. Deriving the list rather than hard-coding the
  super admin is the point: grant the queue to a finance role and they start being
  told, instead of being able to pay and never notified. The same hook is offered
  in the Slack/webhook endpoint picker, since it is wired in the Kernel.

  **Gateway payouts remain blocked, on the destination rather than the gateway.**
  `GatewayModule` has no payout concept (only `capture`/`refund`/`void`/`tokenize`/
  `chargeToken`/`handleCallback`), and `PayhubGateway` implements exactly that — so
  there is no `disburse()`/`transfer()` to call. But the harder gap is that **no
  payout destination is stored anywhere**: `resellers` holds branding and domain,
  `reseller_payouts` holds `method`/`reference`/`note`, and there is no account
  number, bank code or recipient token. There is nowhere to send the money. Manual
  transfer works precisely because the admin supplies the destination from outside
  the system, so any automatic rail needs a verified destination-recording step
  first — which is needed for *every* gateway, not just PayHub.

  **✅ The destination-recording step is BUILT (migration 0197).** One destination per
  store (`reseller_payout_destinations`, `UNIQUE (reseller_id)`): account name, account
  number, bank name/code, plus an optional human `verified_at`/`verified_by`
  attestation — nothing in the application can prove a bank account exists, so it is
  recorded as an attestation rather than dressed up as a check. The reseller manages it
  on `/client/reseller/account`, and a payout **cannot be requested until one is on
  file**: a request with nowhere to send the money is a note, not a request.
  `reseller_payouts.destination_snapshot` freezes the details as they read when the
  request was made, so a reseller who moves bank afterwards cannot restate where an
  earlier payout was sent; editing the stored destination also clears its verification,
  because a changed account number has not been checked by whoever checked the last one.
  The queue shows the frozen destination beside the "record as paid" form, so the admin
  pays the account the reseller named rather than one from memory.

  **Still NOT built, and now unblocked on the schema side only:** the
  `disburse()`/`transfer()` half itself. `GatewayModule` still has no payout concept, so
  an automatic rail remains a separate piece of work — but the missing *destination* it
  was blocked on is no longer missing.

  (The 30-day holding period is deliberately *not* listed here as deferred: it is a
  rule about what counts as withdrawable, so it belongs with the balance in Phase A.
  Since `withdrawable_at` is written when the receipt is written, adding it later
  would have meant back-filling every existing entry.)

### What Phase B settled (2026-09-28)

These were not in the original sketch and shape the built feature:

- **The account is debited when the request is MADE, not when it is paid.** A
  pending request that has not touched the balance is money the reseller can spend
  again, so double-spending would be possible exactly when an admin is slow.
  Debiting at request makes the second request fail on arithmetic instead of on a
  rule. Recording a payment therefore writes *nothing* to the ledger.
- **"One open request per reseller" is enforced by the storage engine.**
  `UNIQUE (reseller_id, status)` would have permitted exactly one *paid* payout per
  reseller ever, so `reseller_payouts` carries a generated column that is 1 only
  while pending, with `UNIQUE (reseller_id, open_flag)`. Repeated NULLs do not
  collide, so any number of decided rows coexist.
- **A payment reference is mandatory.** A manual transfer with no reference is
  unauditable; the reference is what turns "we paid them" from an assertion into
  a fact.
- **A rejection returns the funds as an appended entry, immediately withdrawable**
  and filed as an `adjustment` rather than a positive payout — money coming back is
  a different kind of event, and the "paid out" total must not count payouts that
  never happened.
- **The rate is locked at request** (`currency_rate` and `amount`, alongside
  `amount_base`), so a settled payout cannot be re-derived from a later rate and
  disagree with the transfer that settled it.
- **Requests are for the whole withdrawable balance, not a chosen amount.** A
  partial payout leaves dust and creates a second "spoken for" balance to keep in
  step, and the minimum already decides whether a transfer is worth making.

Phase A was safe to build first for the reason given above: it moves no money and
its only output is a number we can check by hand against the orders.


## 9. Decisions

**Decided 2026-09-28:**

1. **Model** — *one running account, netted* (§2). The cost invoice is the debit
   side of the same account, not a separate demand for cash we are already
   holding.
2. **Accrual trigger** — *when the customer's invoice is PAID.* That is the moment
   we actually hold the money; crediting on order placement would create a
   withdrawable balance funded by money nobody has paid yet.
3. **Cost invoices** — *settled from the balance automatically*, so the reseller
   sees one number instead of a bill they must pay out of a balance we owe them.
4. **Refunds** — *reverse both sides*, the receipt **and** the cost, as appended
   reversing entries. Consistent with the storefront plan's "below-cost pricing is
   the reseller's problem" (a refunded sale simply un-winds) and it keeps the two
   sides of the ledger coherent.

   **✅ Receipt side (commit 13e63c9).** The **receipt** side: a listener on
   `HookPoints::INVOICE_REFUNDED` (which `RefundService` already fired) posts a
   `receipt_reversal`, and — the part that carries the risk — the reversal is
   **withdrawable immediately**. The holding period covers the chargeback window on
   money we are HOLDING; a refund is money we no longer hold, so holding it again
   would leave the receipt withdrawable and let a reseller be paid for a sale the
   customer had been reimbursed for. Migration 0193 was required: the `kind` ENUM had
   no reversal member, and `UNIQUE (kind, invoice_id)` capped reversals at one, so it
   is replaced by `(kind, invoice_id, forward_flag)` — a generated discriminator that
   keeps the forward "one posting per invoice" guarantee verbatim while leaving
   reversals uncapped for partial refunds.

   **✅ Cost side: built — BOTH halves.** A refunded sale now un-winds completely, by
   two different mechanisms because the two timings are genuinely different problems:

   *A refund BEFORE the month is invoiced* needs an **exclusion**, not a reversal — the
   order is simply never billed. The definition of "billable" lives in ONE place
   (`ResellerCostRepository::BILLABLE_ORDER`) and is used by both the billing query and
   the report, because a report that counts an order the billing run refuses to bill is
   a report that makes someone ask why a figure never becomes an invoice.

   *A refund AFTER the month was invoiced* needs the reversing `cost_reversal` credit,
   and this was unbuildable until the schema caught up. Reversing one order's share of
   an invoice needs the per-order figure the invoice was built from, and
   `ResellerCostBillingJob::raise()` wrote each line as a **description string** into
   `invoice_items` with no order id — so there was nothing to reverse against. Migration
   **0194** adds `invoice_items.order_id` (FK to `orders`, `ON DELETE SET NULL`) and the
   job now records it. Recomputation was explicitly rejected: the figure round-trips
   through the reseller's currency (order cost → converted for the invoice → back to base
   for the debit) and `recordCostInvoice()`'s own docblock warns that round-trip is not
   exactly invertible, so recomputing the share would leave a residual on every reversal.
   The **billed line is therefore the authority** for the amount credited back, and it
   is also the figure actually billed.

   Two defects were found and fixed while verifying this, neither visible in a happy path:

   1. **The ceiling was structurally wrong.** The cost debit is posted **per invoice**,
      covering every order that invoice billed, so it carries no `order_id` and summing
      by order finds nothing. The symptom was exact: the receipt reversed, the cost
      silently didn't, and the balance sat at −80. The ceiling is now the billed line
      *less what has already been credited back* (that half IS per order).
   2. **A double-credit hole.** The listener fires once per refund **event**, so a second
      partial refund reaches the reversal again; without the subtraction it re-credits
      the whole line. The ledger's duplicate guard would not object — it is keyed on the
      *invoice*, and both reversals are against the same invoice.

   Each side is measured **per kind, not per sign**, so neither reversal's ceiling depends
   on whether the other has already run. That is not a detail: a ceiling taken from the
   order's whole net made a receipt-first refund succeed and a cost-first refund fail,
   with the same input. A test pins that contrast, and a negative control confirmed it by
   failing on exactly the cost-first case while receipt-first still passed.

**Decided 2026-09-28 (Phase B questions):**

5. **Holding period** — *30 days.* A receipt becomes withdrawable 30 days after the
   payment that funded it, covering the card chargeback window. It gates
   withdrawal only; the balance and its entries are visible immediately.
6. **Minimum payout** — *50.00, a single value for all currencies.* Deliberately
   the same figure the affiliate programme already seeds as `affiliates.min_payout`
   (migration 0115): two payout features carrying two different minimums is an
   inconsistency that generates support tickets. A per-currency minimum is the
   more correct answer for NGN against USD, and is not being built until there are
   multi-currency resellers to justify it.
   **Superseded 2026-10-03 (migration 0205):** the minimum is now **$10**, set once
   in an anchor currency (USD by default) on `/admin/resellers/accounts`. Every
   other currency's minimum is that amount × a conversion rate — the live exchange
   rate by default, or a fixed per-currency rate the admin enters on the same page
   (`reseller.payout_minimum_amount`, `reseller.payout_minimum_currency`,
   `reseller.payout_minimum_rates`). The old `reseller.payout_minimum` /
   `reseller.payout_minimums` keys are retired.
7. **FX risk** — *the reseller carries it.* The account is therefore kept in the
   base unit and converted at payout, rather than fixed at receipt. This is the
   one decision that changed the design: §2 for what a reseller will notice, §6
   for why it is the opposite of the simpler option.
8. **Payout method** — *manual bank transfer,* with the admin recording a
   reference. Phase B records and settles payouts; automation is Phase D. Keeping
   it manual keeps Phase B small enough to be correct, and leaves a real ledger to
   check against before any money moves automatically.

**Still open, but not blocking:** the cost-side debit needs its base-currency
equivalent pinned when Phase 4 raises the invoice — and a denominated invoice
stores `currency_rate = 1.0`, so the rate in force on the invoice date is **not
recoverable from the invoice afterwards**. `ResellerCostBillingJob` must capture
it at the moment it bills. This is a Phase A implementation detail rather than a
business decision, and it is written down so it does not become a surprise.

*(Phase A note, 2026-09-29: partially settled. `recordCostInvoice()` converts via
`CurrencyService::toBase()`, which for a denominated row divides by the currency's
**live** rate rather than one recorded on the invoice. That is correct on the
normal path because the job raises the invoice and posts the debit in the same
request, so "live" and "at billing" are the same instant. It is NOT correct for a
backfill: settling a historical invoice later would use today's rate. Any future
backfill must capture the rate itself rather than trusting `toBase()`.)*

## 10. Phase C — design notes (not yet built)

### 10.1 ✅ FIXED (commit 591fe99) — cost invoices were netted but never settled

**Cost invoices are debited from the account but never marked paid.** Found
2026-09-29 while designing this phase, and it is live in the shipped code.

`ResellerCostBillingJob::raise()` inserts the invoice `unpaid` with
`due_date = now + reseller.billing_due_days` (default 7) and `service_id` NULL.
`ResellerLedgerService::recordCostInvoice()` then debits the ledger by the
invoice's **total** — possibly driving the balance negative, which is the intended
`in_arrears` state. Nothing ever marks the invoice settled, so:

- `InvoiceRepository::overdue()` (`WHERE status = 'unpaid' AND due_date < today`)
  has no notion of reseller cost invoices, so `DunningJob` emails the reseller
  `invoice_overdue` reminders for a bill that has already been netted off their
  balance.
- `DunningJob` also adds a late fee (`billing.late_fee_percentage`, default 5%) to
  `invoices.subtotal` / `total`. **That fee is never debited from the ledger** — the
  debit happened at creation and is guarded by `hasCostEntryForInvoice()` — so the
  invoice and the account then disagree by exactly the fee. (It is applied once per
  invoice, guarded by an `invoice_items LIKE '%Late Fee%'` check, so it does not
  compound.)
- The admin cost report's "unpaid cost invoices" section lists them forever, reading
  as arrears that are in fact already settled.
- **No suspension, and nothing acts on `INVOICE_OVERDUE`.** `ServiceRepository::
  overdueForSuspension()` requires `i.service_id = s.id` and a cost invoice has none,
  so the blast radius is wrong emails and a wrong fee rather than cut-off service.

The fix is decision 3 ("settled from the balance automatically") applied to the
invoice row: mark it settled in the same transaction that posts the debit.

Two implementation cautions, both of which are cheap to get wrong:

1. **Set the status through the repository, not the payment path.** Routing this
   through the normal payment flow would fire `INVOICE_PAID` and run the
   provisioning and notification listeners for a document that is not a customer
   sale. (The ledger's own accrual would be safe either way —
   `accrueStoreReceipt()` looks for an order via `storeOrderForInvoice()` and a cost
   invoice has no `order_id` — but the other listeners are not.)
2. **Decide what "settled" means when the balance does not cover it.** The debit is
   for the full total regardless, so the account goes negative and the debt lives
   there. Marking the invoice paid as well is the reading consistent with "one
   running account, netted" (decision 1). The alternative — leave the shortfall on
   the invoice — reintroduces two places that both claim to know what is owed.

Consequence accepted: the cost report's arrears section is normally empty, because
with netting there are no unpaid cost invoices. The table was KEPT rather than deleted,
because it still surfaces documents raised before the fix plus any an admin re-opens by
hand, and its copy now says so.

**A second-order effect the fix caused, and its mitigation.** Marking the invoice paid
made the dashboard count one store sale twice: `AdminDashboardController` shows
`InvoiceRepository::totalPaidThisMonth()` as *income this month*, and a store sale
produces TWO invoices — the customer's retail one and the reseller's cost one. So
`totalPaidThisMonth()` and `paidThisMonthByCurrency()` now exclude invoices that an
order names as its `reseller_cost_invoice_id`. No migration was needed: InnoDB had
already created an index for that foreign key. The overdue metrics needed nothing and
actually improved, since cost invoices no longer inflate them.

**Still overstated, pre-existing, and NOT fixed here.** For a store sale the customer's
retail invoice counts in full, but our revenue is the COST — the retail is collected on
the reseller's behalf and the margin is theirs. So *income this month* was already
overstated by (retail − cost) before any of this. What income should mean once resellers
exist is a product decision, so the change above only stops the same sale being counted
twice; it does not attempt to answer that question.

`topClientsByRevenue()` was deliberately left alone. It answers "how much has this client
paid us, ever", and for a reseller a netted cost invoice is a genuine charge to them, so
excluding it would be a different claim than the one the widget makes.

### 10.2 ✅ BUILT (commits 376eb57, 09eada5, 29d3e22, edbda16) — Statements

A statement is a period, a reseller, and the entries in it — with an **opening
balance, the entries, and a closing balance**, plus the withdrawable figure as at
the period end. All of it derived from `reseller_ledger`; there is no balance
column to drift, so a statement is a query and not a snapshot that has to be kept
in step.

**DECISIONS (2026-09-30), which were the open questions below.** A statement must
eventually work as a **tax document**, and is being built as **a view first, a
numbered document later**. That ordering is deliberate: a view is recomputed on
every read and can be corrected, while a tax document must be numbered and
IMMUTABLE so two copies of "statement 12" can never disagree. Building the
numbered document before its format is settled would mean reissuing documents,
which is the one thing numbering exists to prevent.

**Built** at `GET /admin/resellers/{clientId}/statement`, gated on
`resellers.manage`, defaulting to the current calendar month. It carries the
content a tax document needs — period, both parties, opening and closing balances
— and says on the page that it is not yet the document of record.

Two things the plan did not spell out, both of which would fail SILENTLY:

- **The opening balance is taken strictly BEFORE the period.** On-or-before counts
  the period's own first entry twice — once as opening and again among the entries
  — making the closing balance wrong by that amount, and invisible in any month
  whose first day saw no activity.
- **The period end is normalised to the LAST DAY at 23:59:59, not to midnight.**
  Midnight on the last day silently omits everything posted during it, which is
  exactly the day a monthly billing run writes its entries.

Both are pinned by tests with negative controls: normalising the end to midnight
fails the boundary test, and taking the withdrawable figure at *now* rather than
at the period end fails the maturity test (100 instead of 60, because an August
receipt had not matured by 31 August).

**Currency, as decided:** the statement's figures are the base ones, with the
reseller-currency equivalents shown for the **closing balance only** — showing a
converted figure per line would imply each line was settled at that rate, which is
exactly what "the reseller carries the FX movement" denies. The withdrawable
figure is likewise taken at the period END, so a receipt maturing later is not
presented as having been available during the period.

**What was built for the tax document.**

A numbered, immutable document in `reseller_statements`, rendered from its own
stored snapshot and never from the live ledger. What is frozen: the five money
columns, the itemised lines (`line_items` JSON), the rate used, and **our tax
identity as it stood on the day** — a company that later changes its VAT number
must not retroactively restate documents it has already issued, or "issued by"
describes whoever we are today rather than making a statement about the past.

**Numbering: per store, per year (`STMT-2026-0001`).** Chosen because a statement
is the reseller's own document and their bookkeeper expects to file their own
sequence; a shared platform number would make their references non-contiguous.
Per-store also leaks nothing — a global sequence would let every reseller read the
platform's growth off their own statement number. The sequence is deliberately
**not gapless**: gapless means reusing a number when a transaction rolls back, and
a number that can be handed out twice is what makes a numbered document useless in
an audit. A gap means something real happened.

`(reseller_id, period_year, seq)` is the allocator, `(reseller_id, number)` is a
backstop against a formatting bug, and `(reseller_id, period_from, period_to)` is
**idempotency**: a repeat issue returns the existing document rather than minting a
second number, because a duplicate number is indistinguishable from a lost one.

**Viewing and issuing are different routes.** The live view is recomputed on every
read and can be corrected; a document is frozen and can only be superseded. So
issuing is an explicit POST — a document that numbers itself when you open it is a
document nobody decided to send. The reseller has read-only access to their own;
they cannot issue to themselves, because issuing stamps our identity and mints an
irreversible number.

**Our identity fields** live on General Settings → Company Information: tax/VAT
number, registration number, registered address, contact phone. `company.name` and
`company.email` are reused rather than duplicated. They are saved exactly as typed
and never defaulted, and the statement page lists which are still blank BEFORE
anything is issued — a tax document that prints an empty VAT number has already
gone out by the time it is noticed, and a placeholder is worse than a blank.

**Remaining, if you want it:** the field names are conventional (`company.tax_number`,
etc.) but nothing prints a jurisdiction-specific legal layout — tax documents in
some countries require a specific ordering, wording and registration references.
The figures and the freeze do not need to change for that; only the rendering would.

**Open permission question:** issuing is gated on `resellers.manage`, the same
permission that merely READS the account. Issuing is write-and-irreversible, so it
may deserve a narrower gate. Not changed unilaterally.

### 10.3 ✅ BUILT (commit 2696065) — Export

A CSV of ledger entries: `created_at, kind, amount_base, withdrawable_at, order_id,
invoice_id, payout_id, description`. Base amounts only, one row per entry, no
totals — a spreadsheet can sum, and a total in an export invites someone to
reconcile against it when the ledger is the authority.

The one thing an export must include that the screens do not: **`payout_id` and
`invoice_id` as plain ids rather than links**, because the point of the export is to
be joined against other records outside the system.

**What was built.** One store's entries, at
`GET /admin/resellers/{clientId}/account/export`, addressed by client id exactly like
the account page it exports, with a link on that page. Gated on the same
`resellers.manage` permission as the page — an export is a read with a different
content type, and a separate permission would only stop a staff member who may read
the account from copying it.

Three things the plan did not spell out, decided here and worth knowing:

- **The export does NOT reuse `entries()`.** That method caps at 200 because it
  feeds a page; an export that inherited the cap would silently DROP ROWS from a
  file someone reconciles money against. `entriesForExport()` has no limit and
  orders **oldest first** — the order the money moved, the opposite of the page.
  Pinned by a test that writes 205 entries and expects 206 rows, with a negative
  control (re-adding `LIMIT 200` fails it, 201 vs 206).
- **No per-row conversion**, so no currency column. There is no per-row rate at
  which each line was settled; showing today's would contradict "the reseller
  carries the FX movement".
- **No totals, deliberately.** A total in the export would be a second source of
  truth for the same number, and the one people reconcile against, because it is
  the one that arrived in a file.

### 10.4 ✅ FIXED — the revenue chart counted a netted cost invoice as income

Found 2026-10-01 while auditing the dashboard's income figures, and it is the same
class of mistake as §10.1: cost invoices exist, and one reporting query did not know
it.

The dashboard computed "income" in four places. Two of them excluded a reseller's
store-cost invoice and two did not:

| Figure | Backed by | Excluded it? |
| --- | --- | --- |
| Income this month (tile) | `InvoiceRepository::totalPaidThisMonth()` | yes |
| Income by currency | `InvoiceRepository::paidThisMonthByCurrency()` | yes |
| **Six-month revenue chart** | `ReportRepository::incomeByMonth()` | **no** |
| **Reports page, by month** | same `incomeByMonth()` | **no** |

A store-cost invoice is `status = 'paid'` with `paid_at` stamped — `ResellerCostBillingJob`
settles it through a plain guarded `markPaid()` for exactly that reason — so it passes
`incomeByMonth()`'s filter and inflates the chart. **The tile and the chart are on the
SAME SCREEN**, so for any month with cost billing the bar ran high by the cost of every
store sale in it, and only one of the two figures was right. A wrong number is bad; a
wrong number next to a right one for the same month is worse, because there is no way to
tell which to trust.

**The fix is a shared predicate, not a third copy.** The exclusion had already been
written out twice inside `InvoiceRepository` and was missing from the third place that
needed it — which is precisely the drift that produced it. It now lives once, as
`InvoiceRepository::EXCLUDE_RESELLER_COST_INVOICE`, and all three figures use it.

**The test asserts the tile and the chart AGAINST EACH OTHER**, then asserts the shared
answer (the cash invoice alone). Asserting either figure on its own is how they diverged:
each was individually defensible. A negative control (removing the clause from the chart
query) failed with `chart 500.0 vs tile 100.0` — the disagreement itself.

**`taxLiabilityByMonth()` deliberately NOT changed.** It shares the same query shape but
sums `i.tax_amount`, and a cost invoice is raised with zero tax, so it is numerically
unaffected. Changing it would be scope creep with no observable difference; noted here so
the next person does not "fix" it speculatively either.

**Still open, and genuinely a product decision rather than a bug:** a store sale's
CUSTOMER invoice is counted in full, but our revenue on that sale is the COST, because
the retail is collected on the reseller's behalf. What "income" should mean once
resellers exist is not a question code can answer.

