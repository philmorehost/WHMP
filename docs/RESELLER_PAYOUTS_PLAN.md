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
| `+` | `store_receipt` | the customer's **retail** total, in the reseller's currency | the customer's invoice is **paid** |
| `−` | `cost_invoice` | the **cost** of that order, per the monthly cost invoice | when Phase 4 raises the invoice |
| `−` | `payout` | what we actually sent them | when a payout is paid |
| `±` | `adjustment` | manual, admin-only, with a reason | as needed |

**Balance = sum of the entries.** Then:

- Balance ≥ minimum → the reseller can withdraw it.
- Balance = 0 → nothing owed either way.
- Balance < 0 → **they owe us**: they priced below our cost, which §6/§7 already
  says is their problem. A payout can never take the balance below zero.

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

## 6. The hard parts (each needs a decision, not a guess)

- **When is retail credited?** Recommendation: **when the customer's invoice is
  paid** — that is the moment we actually hold the money. Crediting at order
  creation would create a withdrawable balance funded by money nobody has paid.
- **Refunds and chargebacks.** If a store order is refunded, the retail we hold
  goes back, so the receipt must be reversed. Whether the *cost* is also reversed
  is a business decision, not a technical one: if we refund our cost too, the
  reseller's negative margin is ours to absorb.
- **Holding period.** A payout immediately after payment can be withdrawn and then
  charged back, leaving us exposed. A holding period (e.g. funds become
  withdrawable N days after payment) is the usual defence.
- **Currency.** Entries are in the reseller's currency, converted per order from
  its stored convention. **Who bears the FX movement** between the day we collect
  and the day we pay out is a decision; the ledger as designed fixes the rate at
  the moment of receipt, so the reseller's balance is stable and *we* carry FX.
- **Tax and reporting.** Paying a reseller is a payment to a third party, which
  may create reporting obligations (a statement or a self-billed invoice). Out of
  scope here, but it must be designed before any real money moves.
- **KYC.** Minimum, manual approval, one-open-request, and an admin-visible
  history are the first line of defence. Anything automated should wait until
  these are in place and have been used.

## 7. Data model sketch

```
reseller_ledger                     (new — currency-explicit, typed)
  id, reseller_id, client_id, currency_id,
  kind ENUM('store_receipt','cost_invoice','payout','adjustment'),
  amount DECIMAL(10,2)   -- signed: + is owed to the reseller, - is owed by them
  order_id, invoice_id, payout_id, description, admin_id, created_at
  INDEX (reseller_id, currency_id)

reseller_payouts
  id, client_id, reseller_id, amount DECIMAL(10,2), currency_id, currency_rate,
  status ENUM('pending','paid','rejected','cancelled'),
  method VARCHAR(32), reference VARCHAR(191), note TEXT,
  requested_at, decided_at, decided_by
```

**No new balance column anywhere**, on either table: a balance that is stored *and*
derived will eventually disagree with itself. The balance is
`SUM(reseller_ledger.amount)` for the reseller in that currency — which is the same
rule `client_credit_ledger` already follows, and worth copying deliberately.

## 8. Proposed phases

- **Phase A — the account (report only).** Accrue a store receipt when a store
  order's invoice is paid; record the cost invoice as the debit; show the
  reseller their balance and the entries behind it. No movement of money.
- **Phase B — request and approve.** Reseller payout requests; admin queue;
  approve/reject/mark-paid with a reference; the payout ledger entry. Payment is
  **manual bank transfer** — the simplest thing that can be honest.
- **Phase C — netting and statements.** Settle cost invoices from the balance
  automatically; monthly statement per reseller; exportable for accounting.
- **Phase D — methods and automation.** Gateway payouts, automatic payouts above a
  threshold, holding periods, refund/chargeback handling.

Phase A is safe to build immediately: it moves no money and its only output is a
number we can check by hand against the orders.

## 9. Decisions needed before Phase B

1. **Model**: the running account in §2 (recommended), or a gross
   invoice-and-payout pair with no netting?
2. **Accrual trigger**: credit on **payment** (recommended) or on order placement?
3. **Cost invoices**: settle them from the balance automatically (recommended), or
   keep demanding them in cash and pay retail out separately?
4. **Refunds**: reverse the receipt only, or the cost as well?
5. **Holding period**: none, or N days after payment before funds are withdrawable?
6. **Minimum payout** amount, and whether it differs per currency.
7. **FX risk**: we carry it (recommended, and simplest to explain) or the reseller?
8. **Payout method for Phase B**: manual bank transfer with an admin-recorded
   reference (recommended), or a gateway payout from the start?
