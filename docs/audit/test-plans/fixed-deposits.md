# Fixed Deposits — test plan

Code under test: `FdDepositController`, `FdInterestPaymentController`, `FixedDepositController`,
`app/Support/FdInterestCalculator.php`, `FdLedgerPoster.php`. Control accounts `2010` (payable),
`5100` (interest expense). Cash-method only — Bank/Cheque legs never post (Risk 1).

Run [`ledger-invariants.md`](ledger-invariants.md) after every case.

Fixtures: **FD-M** (principal `500,000`, annual `12%`, `term=12`, `interest_payment_method=monthly`,
start `2026-03-01`); **FD-T** (same but `maturity`); a funding product with
`premature_withdrawal_rate_percent`.

---

### FD-01 | Deposit received — Cash
**Type:** integration · **Priority:** P0
**Setup:** TILL-HQ `= 1,000,000`.
**Steps:** `POST /fd-deposits {amount: 500000, payment_method: Cash}` then allocate to FD-M.
**Expected ledger entries:**
```
Dr 1010-1   500,000.00
   Cr 2010            500,000.00
```
**Expected balances after:** `TILL-HQ = 1,500,000.00`; `2010 = 500,000.00`; `idempotency_key='fd-deposit-{id}'`.
**Risk it covers:** regression on `FdLedgerPoster::postDepositReceived`.

---

### FD-02 | Monthly-method interest: Σ payouts = principal × rate × term; maturity returns principal only
**Type:** unit + integration · **Priority:** P0
**Hand computation** (`FdInterestCalculator::calculate`):
- `monthlyRate = 12/12 = 1.0%`
- `monthlyInterest = round(500,000 × 1.0/100, 2) = 5,000.00`
- `totalInterestAmount = round(5,000 × 12, 2) = 60,000.00`
- `maturityAmount = principal = 500,000.00` (interest already paid out monthly)
**Steps:** generate the 12-row schedule, pay all 12 instalments Cash, then withdraw at maturity.
**Expected ledger entries:** 12 × `Dr 5100 5,000.00 / Cr 1010-1 5,000.00`; at maturity `Dr 2010 500,000.00 / Cr 1010-1 500,000.00` (`interest_amount=0`, no `5100` line — `FdLedgerPoster.php:122` only adds it `if interest_amount > 0`).
**Expected balances after:** `Σ 5100 credits over the term = 12 × 5,000 = 60,000.00 = 500,000 × 12% × (12/12)`; `2010` for this FD `= 0.00` after maturity; total cash paid to depositor across the term `= 60,000 (interest) + 500,000 (principal) = 560,000.00`, matching `principal + totalInterestAmount`.
**Risk it covers:** Σ payouts drifting from the contracted total; maturity accidentally re-paying interest already paid monthly. **README top-25 #24.**

---

### FD-03 | Maturity-method interest: silent accrual, one entry at close
**Type:** integration · **Priority:** P0
**Setup:** FD-T. `totalInterest = round(500,000 × 12/100 × 12/12, 2) = 60,000.00`; `maturityAmount = 560,000.00`.
**Steps:** hold to maturity, no interest payments during the term (none scheduled — `interest_payment_method=maturity` has no `fd_interest_payments` rows), then withdraw.
**Expected ledger entries during the term:** **none** — `2020 Interest Payable on FDs` is never posted to (confirmed: no `LedgerPoster::post` call anywhere references code `2020`).
**Expected ledger entries at withdrawal:**
```
Dr 2010   500,000.00
Dr 5100    60,000.00
   Cr 1010-1          560,000.00
```
check: `500,000+60,000 = 560,000` ✓
**Expected balances after:** `2010 = 0.00`; `5100 += 60,000.00` **in one lump on the closing day**, not spread across the 12 months it was actually earned — an accrual-basis P&L for any month before maturity shows `0` interest expense for this FD even though `AerCalculator`-style economics say `5,000/mo` was accruing.
**Risk it covers:** interest expense timing entirely absent from monthly P&L until maturity; if the FD is renewed instead of withdrawn, this `60,000` never posts at all (see FD-07).

---

### FD-04 | Interest instalment payment — Cash
**Type:** integration · **Priority:** P0
**Setup:** FD-M, instalment 1 due `2026-04-01`, amount `5,000.00`.
**Expected ledger entries:**
```
Dr 5100   5,000.00
   Cr 1010-1        5,000.00
```
**Expected balances after:** `TILL-HQ -= 5,000`; instalment `status=Paid`; `idempotency_key='fd-interest-{id}'`.
**Risk it covers:** wrong account debited for interest paid out (must be `5100` expense, not `2010`).

---

### FD-05 | Premature part-payment — maturity method (worked example)
**Type:** integration · **Priority:** P0
**Setup:** FD-T, principal `500,000`, start `2026-03-01`; funding product `premature_withdrawal_rate_percent=8.0`. Part payment `withdrawAmount=150,000` on `2026-09-01`.
**Hand computation** (`FixedDepositController::partPayment`):
- `heldDays = diffInDays(2026-03-01, 2026-09-01) = 31+30+31+30+31+31 = 184`
- `entitlement = round(150,000 × 8/100 × 184/365, 2)`. `150,000×0.08 = 12,000`. `12,000 × 184/365 = 12,000 × 0.5041095890 = 6,049.315068…` → `round = 6,049.32`
- `interest_payment_method = maturity` → `interestPaid = entitlement = 6,049.32`; `clawback = 0.00`
- `netAmount = round(150,000 + 6,049.32 − 0, 2) = 156,049.32`
**Expected ledger entries:**
```
Dr 2010     150,000.00
Dr 5100       6,049.32
   Cr 1010-1              156,049.32
```
check: `150,000 + 6,049.32 = 156,049.32` ✓
**Expected balances after:** `TILL-HQ -= 156,049.32`; FD's `principal_amount` recalculated to `350,000.00`; schedule/`maturity_amount` recomputed forward from today at the product's normal rate (past interest untouched).
**Risk it covers:** premature-rate math on the withdrawn portion only; regression on `FdLedgerPoster::postPartPayment`.

---

### FD-06 | Premature part-payment — monthly method with clawback (worked example)
**Type:** integration · **Priority:** P0
**Setup:** FD-M, principal `500,000`; `premature_withdrawal_rate_percent=6.0`. By `2026-09-01`, 6 monthly instalments of `5,000` have been **Paid** (`paidSoFar=30,000`). Part payment `withdrawAmount=100,000` on `2026-09-01` (`heldDays=184`).
**Hand computation:**
- `attributed = round(30,000 × (100,000/500,000), 2) = round(30,000 × 0.2, 2) = 6,000.00` (the withdrawn fifth's share of interest already paid)
- `entitlement = round(100,000 × 6/100 × 184/365, 2)`. `100,000×0.06=6,000`. `6,000 × 0.5041095890 = 3,024.657534` → `round = 3,024.66`
- `clawback = max(0, round(6,000 − 3,024.66, 2)) = 2,975.34`
- `interest_payment_method = monthly` → `interestPaid = 0.00` (only the clawback matters here)
- `netAmount = round(100,000 + 0 − 2,975.34, 2) = 97,024.66`
**Expected ledger entries:**
```
Dr 2010   100,000.00
   Cr 5100              2,975.34
   Cr 1010-1           97,024.66
```
check: `100,000 = 2,975.34 + 97,024.66` ✓ — **no debit-side `5100` line** (`interestPaid=0`, so that leg is omitted at `FdLedgerPoster.php:177`); the clawback appears as a **credit** to `5100`, partially reversing prior interest expense.
**Expected balances after:** `TILL-HQ -= 97,024.66`; `5100`'s running balance for this FD is reduced by `2,975.34` (interest already expensed monthly turns out to have been `2,975.34` too generous for the withdrawn portion).
**Risk it covers:** the clawback sign/side — crediting `5100` instead of debiting it would double the expense instead of correcting it; a wrong `attributed` pro-ration would misprice every subsequent part payment.

---

### FD-07 | Renewal — principal rollover posts nothing; only a capital-enhancement top-up posts
**Type:** integration · **Priority:** P1
**Setup:** FD-M reaches maturity with `principal=500,000`; renewed with a `50,000` cash top-up (`enhancementDeposit`).
**Steps:** `POST /fixed-deposits/{id}/renew {enhancement_amount: 50000, payment_method: Cash}`.
**Expected ledger entries:** **no entry for the rolled-over `500,000`** (`FdLedgerPoster.php:33` — "no cash moves"); the enhancement posts as an ordinary deposit:
```
Dr 1010-1   50,000.00
   Cr 2010            50,000.00
```
**Expected balances after:** new FD `principal_amount = 550,000.00`; `2010` for the pair increases by exactly `50,000.00` (the `500,000` was already there under the old FD and simply moves to the new one — no double count, no gap).
**Risk it covers:** a renewal accidentally re-posting the whole `550,000` (double-counting `2010`) or posting nothing at all for the enhancement (losing `50,000` of real cash received).

---

### FD-08 | Voids restore the till and reverse the exact entry
**Type:** integration · **Priority:** P1
**Setup:** FD-05's part payment (`net 156,049.32`), FD-04's interest payment (`5,000.00`), FD-01's deposit (`500,000.00` — still `Available`, unallocated).
**Steps:** void each independently.
**Expected ledger entries:** three separate `FinancePostingService::reverse` mirrors:
- part payment: `Dr 1010-1 156,049.32 / Cr 2010 150,000.00 / Cr 5100 6,049.32`
- interest payment: `Dr 1010-1 5,000.00 / Cr 5100 5,000.00`
- deposit: `Dr 2010 500,000.00 / Cr 1010-1 500,000.00` (only allowed while `Available`/unallocated)
**Expected balances after:** `TILL-HQ` restored by the sum of all three; each original entry `Reversed`, not deleted (INV-07).
**Risk it covers:** void of a still-open-but-allocated deposit silently corrupting the FD it's attached to; wrong-sign reversal on the clawback leg.

---

### FD-09 | Bank/Cheque FD legs post nothing
**Type:** integration · **Priority:** P1 · **partial FAIL (Risk 1)**
**Setup:** an FD deposit received Bank; an FD withdrawal paid Bank.
**Expected (current):** both operations complete (FD created/closed) with **zero** GL entries — every `FdLedgerPoster` method early-returns on non-Cash (`:43`,`:78`,`:107`,`:162`).
**Expected balances after (current):** `2010` never reflects a Bank-received deposit; INV-04 fails for `2010`.
**Risk it covers:** Risk 1, FD instance.

---

### FD-10 | Duplicate FD deposit via retried request
**Type:** integration · **Priority:** P2 · **Expected result: FAIL (Risk 6)**
**Setup:** `TILL-HQ` large.
**Steps:** submit the identical `POST /fd-deposits {...}` twice (client retry after a timeout, no idempotency key on the endpoint).
**Expected (current):** two `fd_deposits` rows, two `Dr 1010-1 500,000 / Cr 2010 500,000` entries — `TILL-HQ` and `2010` both overstated by `500,000`.
**Risk it covers:** Risk 6, FD instance — no `outstanding`-style guard exists here to catch it (unlike loan payments).

---

### FD-11 | Zero-rate FD — no interest lines ever posted
**Type:** unit · **Priority:** P2
**Setup:** an FD product with `annual_rate=0`.
**Expected:** `monthlyInterestAmount=0.00` / `totalInterestAmount=0.00`; interest-payment schedule rows all `amount=0.00` — but a `0`-amount `fd_interest_payments` row, if ever "paid", would try to post a zero GL line and must be rejected before reaching `LedgerPoster` (the CHECK constraint would throw). Assert the controller guards this (e.g. skips posting or rejects the pay action) rather than relying on the DB to blow up mid-request.
**Risk it covers:** unhandled DB exception surfacing as a 500 instead of a clean validation error.
