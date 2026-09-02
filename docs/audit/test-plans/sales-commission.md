# Sales-executive commission — test plan

Code under test: `SalesExecutiveCommissionController`, `LoanDisbursementCompleter::generateSalesExecutiveCommission`,
`LoanSummary::collectedInterest`. Control account `5200` (Staff Salaries & Commission). This is the
only money-movement path in the codebase that both bypasses `FinancePostingService` **and** takes
its amount from the client with no server cap — see `ledger-matrix.md` Risk 4.

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

---

### COMM-01 | Fixed-type commission, paid at the correct amount
**Type:** integration · **Priority:** P0
**Setup:** a loan's commission cycle: `commission_type=fixed`, `commission_value=5,000.00`, eligible (paid installments ≥ `required_installments`). TILL-HQ `= 200,000`.
**Steps:** `POST /sales-executive-commissions/{id}/pay {payment_amount: 5000, payment_method: Cash, cash_account_id: 1, payment_date: 2026-05-01}`.
**Expected ledger entries:**
```
Dr 5200   5,000.00
   Cr 1010-1        5,000.00
```
**Expected balances after:** `TILL-HQ = 195,000.00 = 1010-1` leaf (moved by hand — assert equality anyway, INV-04); `commission.paid_at` set, `commission_amount` frozen at `5,000.00`.
**Risk it covers:** baseline correctness of the posting shape.

---

### COMM-02 | `payment_amount` from the client is never capped to what's earned
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (documents Risk 4)**
**Setup:** as COMM-01 — the loan actually earned `commissionAmount() = 5,000.00`. TILL-HQ `= 200,000`.
**Steps:** `POST /sales-executive-commissions/{id}/pay {payment_amount: 500000, payment_method: Cash, cash_account_id: 1}` (two orders of magnitude too high — a typo or a malicious actor).
**Expected (current):** validation only requires `numeric|min:0` (`SalesExecutiveCommissionController.php:64`) — **accepted**. Posts:
```
Dr 5200   500,000.00
   Cr 1010-1          500,000.00
```
**Expected balances after (current):** `TILL-HQ = −300,000.00` — driven negative because this path's manual `bcsub` (`:79`) has **no** `InsufficientFundsException` guard, unlike every `FinancePostingService`-routed payment. `commission.payment_amount = 500,000.00` stored as fact.
**Target behaviour the test asserts (xfail):** HTTP 422 "Payment amount exceeds the commission earned (5,000.00)" — no entry, no balance move.
**Risk it covers:** Risk 4. **README top-25 #8.**

---

### COMM-03 | No `finance_transactions` row, no cashbook mirror, no idempotency
**Type:** integration · **Priority:** P0
**Setup:** COMM-01's payment.
**Steps:** inspect `finance_transactions` and `cashbook_entries` for any row referencing this payment; submit the identical `pay()` request a second time before the first response returns (simulated race).
**Expected (current):**
- No `finance_transactions` row exists for this movement at all — the Cashbook report and the Finance Transactions ledger **cannot show this payment ever happened**, even though `journal_entries` has it.
- Two concurrent `pay()` calls: nothing prevents both from reading `commission.paid_at IS NULL` and both proceeding — **two** `Dr 5200 5,000 / Cr 1010-1 5,000` entries, `TILL-HQ` debited `10,000` for one `5,000` commission (no idempotency key, no row lock on `commission` before the check at `:54`).
**Expected balances after (current):** `TILL-HQ` short by `5,000` extra; `commission.paid_at` set once (last write wins) but two GL entries and two till movements exist.
**Risk it covers:** Risk 4 + Risk 6 compounded — this is the single worst-guarded money-out path in the system.

---

### COMM-04 | Percentage-type commission — `commissionAmount()` frozen at payment, not recomputed later
**Type:** integration · **Priority:** P0
**Setup:** `commission_type=percentage`, `commission_value=10.0`, `from_installment=0`. `LoanSummary::collectedInterest(loan, 0) = 45,000.00` (active, non-voided interest collected across the loan's schedule) at payment time.
**Hand computation:** `commissionAmount = round(45,000 × 10/100, 2) = 4,500.00`.
**Steps:** `pay({payment_amount: 4500, ...})`; afterward the customer pays more interest, pushing `collectedInterest` to `70,000`.
**Expected ledger entries:**
```
Dr 5200   4,500.00
   Cr 1010-1        4,500.00
```
**Expected balances after:** `commission.commission_amount = 4,500.00` **frozen** at payment (`SalesExecutiveCommissionController.php:87`); re-querying the paid commission afterward still shows `4,500.00`, not a recomputed `7,000.00` from the later interest.
**Risk it covers:** retroactive commission inflation/deflation after payment; confirms `commissionAmount()`'s `$c->paid_at` short-circuit (`:188`).

---

### COMM-05 | Void restores the balance and reverses the entry
**Type:** integration · **Priority:** P1
**Setup:** COMM-01's payment.
**Steps:** `POST /sales-executive-commissions/{id}/void`.
**Expected ledger entries:** `LedgerPoster::reverse` on the original entry: `Dr 1010-1 5,000.00 / Cr 5200 5,000.00`. Manual `bcadd` restores `TILL-HQ` (`:136`).
**Expected balances after:** `TILL-HQ = 200,000.00` restored; `commission.paid_at=null`, `payment_amount=null`; original entry `Reversed`.
**Risk it covers:** void that restores the GL but not the manually-moved till (or vice versa) — since this path is hand-rolled on both sides, they can drift independently, unlike an `FinancePostingService::reverse` pair.

---

### COMM-06 | Percentage cycle auto-starts the next cycle without double-counting interest
**Type:** integration · **Priority:** P1
**Setup:** percentage commission cycle 1, `from_installment=0`, paid at `paidInstallments=6` out of a `10`-installment loan.
**Steps:** pay cycle 1.
**Expected ledger entries:** COMM-04's shape for cycle 1 only.
**Expected balances after:** a new `sales_executive_commissions` row created, `cycle_no=2`, `from_installment=6` (`maybeStartNextCycle`, `:199`); `LoanSummary::collectedInterest(loan, sinceInstallment=6)` for cycle 2 counts **only** interest from installment 7 onward — assert cycle 2's live `commissionAmount()` excludes the interest already priced into cycle 1.
**Risk it covers:** the same interest paid twice across two commission cycles.

---

### COMM-07 | Payment on a not-yet-eligible / already-paid commission is rejected
**Type:** unit · **Priority:** P2
**Setup:** a commission cycle `required_installments=10`, only `6` paid; and a separate already-paid commission.
**Steps:** attempt `pay()` on each.
**Expected:** HTTP 422 "This commission is not yet eligible for payment" (`:59`) and "This commission has already been paid" (`:54`) respectively. No entries either time.
**Risk it covers:** commission paid before it's earned, or paid twice outright (distinct from the concurrency race in COMM-03).
