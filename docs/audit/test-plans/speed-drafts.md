# Speed Drafts — test plan

Code under test: `SpeedDraftController`, `SpeedDraftPaymentController`, `SpeedDraftScheduler.php`,
`SpeedDraftPaymentAllocator.php`, `SpeedDraftLedgerPoster.php`. Interest-only schedule (capital is
never amortized — see `SpeedDraftScheduler.php:9`); control accounts `1110` / `4015` / `4030`.

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

Fixture **SD-1**: capital `50,000`, `rate_period=monthly`, `interest_rate_percent=5.0`, `term_months=3`, `start_date=2026-03-01`, Cash disbursement from TILL-HQ.

---

### SD-01 | Schedule generation — interest-only, capital on the last row
**Type:** unit · **Priority:** P0
**Hand computation** (`SpeedDraftScheduler::generate`, monthly rate applied flat regardless of days in month):
- Row 1 (due `2026-04-01`): `interest = round(50,000 × 5/100, 2) = 2,500.00`; `principal_due = 0.00`; `total = 2,500.00`
- Row 2 (due `2026-05-01`): `interest = 2,500.00`; `principal_due = 0.00`; `total = 2,500.00`
- Row 3 (due `2026-06-01`, last): `interest = 2,500.00`; `principal_due = 50,000.00`; `total = 52,500.00`
**Expected balances after:** `Σ interest_due = 7,500.00 = 50,000 × 5% × 3`; `Σ principal_due = 50,000.00` (once, on the last row only).
**Risk it covers:** capital duplicated across rows; wrong row carries the capital.

---

### SD-02 | Disbursement — Cash and Bank
**Type:** integration · **Priority:** P0
**Setup:** SD-1 Approved.
**Expected ledger entries (Cash):**
```
Dr 1110   50,000.00
   Cr 1010-1        50,000.00
```
**Expected ledger entries (Bank, `disbursement_bank_account_id=BANK-1`):**
```
Dr 1110   50,000.00
   Cr 1020-1        50,000.00
```
**Expected balances after:** source account moves by `50,000.00`; `1110 = 50,000.00`; `idempotency_key = 'speed-draft-disbursement-{id}'`.
**Risk it covers:** regression on `SpeedDraftLedgerPoster::postDisbursement` (shares `LoanLedgerPoster::disbursementTarget`).

---

### SD-03 | Payment order is penalty → interest → capital (NOT the loan order)
**Type:** integration · **Priority:** P0
**Setup:** SD-1 disbursed; row 1 (`2,500` interest) overdue with an accrued penalty of `150.00` (hand-computed the same way as `LOAN-12`/`LOAN-13`, using the Speed Draft type's `grace_period_days`/`default_charge_rate_percent` via `SpeedDraftSummary::penaltyDueForRow`). Pay `2,000` Cash.
**Steps:** `POST /speed-drafts/{id}/payments {amount: 2000, payment_method: Cash}`.
**Hand computation** (`SpeedDraftPaymentAllocator.php:74` — penalty first, then interest, then capital):
- penalty `min(2,000, 150.00) = 150.00` → remaining `1,850.00`
- interest `min(1,850.00, 2,500.00) = 1,850.00` → remaining `0.00`
- capital: nothing
**Expected ledger entries:**
```
Dr 1010-1   2,000.00
   Cr 4030            150.00
   Cr 4015          1,850.00
```
**Expected balances after:** row 1 `interest` partially applied `1,850.00`; penalty allocation `150.00`; `1110` unchanged (no capital paid).
**Risk it covers:** allocation-order regression — this order is the **opposite** of loans' interest-before-penalty (`LoanPaymentAllocator.php:352` vs `SpeedDraftPaymentAllocator.php:74`); a shared-code refactor could silently swap them.

---

### SD-04 | Bank-method payment posts NO ledger entry
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (Risk 1)**
**Setup:** SD-1 Active; row 1 due `2,500`.
**Steps:** pay `2,500` Bank.
**Expected (current):** row 1 `status=Paid`; **no GL entry** (`SpeedDraftLedgerPoster.php:83` early-return).
**Expected balances after (current):** `4015` unchanged; INV-04 fails for `1110`.
**Risk it covers:** Risk 1.

---

### SD-05 | Renewal — capitalised interest/penalty is real income, no cash
**Type:** integration · **Priority:** P0
**Setup:** SD-1 Active; at renewal, unpaid interest `2,500.00` and penalty `150.00` are capitalised into the new draft instead of collected in cash.
**Steps:** `POST /speed-drafts/{id}/renew {...}`.
**Expected ledger entries** (`postCapitalizedRenewalIncome`, non-cash):
```
Dr 1110   2,650.00
   Cr 4015          2,500.00
   Cr 4030            150.00
```
**Expected balances after:** `newDraft.principal_amount` includes the `2,650.00` on top of the rolled-over capital; `1110` for the pair increases by exactly `2,650.00`; **no `finance_transactions` row, no till movement** (this is correctly non-cash — assert no `movements` array was ever built, i.e. this must go through `LedgerPoster::post` directly, not `FinancePostingService`).
**Risk it covers:** double counting income at both renewal and eventual collection; renewal silently moving cash it shouldn't.

---

### SD-06 | Full close — penalty, interest, capital all clear together
**Type:** integration · **Priority:** P1
**Setup:** SD-1 Active, row 3 due (`interest 2,500` + `principal 50,000`), no penalty. Pay the full `outstandingBalance()`.
**Expected ledger entries:**
```
Dr 1010-1   52,500.00
   Cr 4015          2,500.00
   Cr 1110         50,000.00
```
**Expected balances after:** draft `status=Closed`; `1110` for draft `= 0.00`.
**Risk it covers:** final-row capital settlement; closure trigger.

---

### SD-07 | Payment void restores till and reverses the split
**Type:** integration · **Priority:** P1
**Setup:** SD-03's payment (`2,000` Cash, `Cr 4030 150 / Cr 4015 1,850`).
**Steps:** void the payment.
**Expected ledger entries:** `SpeedDraftLedgerPoster::reverseAllFor` → `FinancePostingService::reverse` on the COLLECTION txn: `Dr 4030 150.00 / Dr 4015 1,850.00 / Cr 1010-1 2,000.00`.
**Expected balances after:** `TILL-HQ` restored by `2,000`; row 1 allocations reversed on the audit trail but original allocation row **not deleted** (INV-07); row status recomputed.
**Risk it covers:** void leaving the till short/over.

---

### SD-08 | Edge — zero-rate Speed Draft
**Type:** unit · **Priority:** P2
**Setup:** SD-1 with `interest_rate_percent = 0`.
**Expected balances after:** every `interest_due = 0.00`; a payment posts `Dr 1010-1 X / Cr 1110 X` only — no `4015` line (zero-line CHECK).
**Risk it covers:** zero-amount line crash.
