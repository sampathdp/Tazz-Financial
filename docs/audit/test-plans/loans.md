# Loans — test plan

Code under test: `LoanController`, `LoanPaymentController`, `LoanPostDatedChequeController`,
`app/Support/LoanAmortizer.php`, `LoanPaymentAllocator.php`, `LoanLedgerPoster.php`,
`LoanSummary.php`, `InterestAccrualSchedule.php`, `LoanDisbursementCompleter.php`,
`LoanProvisioning.php`.

Run [`ledger-invariants.md`](ledger-invariants.md) INV-01…INV-08 after **every** case below.

Fixtures: [`README.md`](README.md). `TILL` = `1010-1` leaf of TILL-HQ.

---

## Schedule generation

### LOAN-01 | Flat schedule, clean division (L-FLAT-1)
**Type:** unit · **Priority:** P0
**Setup:** L-FLAT-1 — financed `P = 100,000 − 0 = 100,000`; flat; monthly rate `r = 2.0%`; `term = 10`; monthly frequency → `periodsPerMonth = 1`, `n = 10`.
**Steps:** generate the schedule (assert against the literal table below, typed from the arithmetic — **not** from `LoanAmortizer::generate`).
**Hand computation** (`LoanAmortizer::flat`, `:103`):
- `totalInterest = 100,000 × (2.0/100) × 10 = 20,000.00`
- `principalPer = round(100,000 / 10, 2) = 10,000.00`
- `interestPer  = round(20,000 / 10, 2)  = 2,000.00`
- rows 1–9: principal `10,000.00`, interest `2,000.00`, total `12,000.00`
- row 10 (last): principal `= round(100,000 − 9×10,000, 2) = 10,000.00`; interest `= round(20,000 − 9×2,000, 2) = 2,000.00`; total `12,000.00`

**Expected ledger entries:** none (generation only).
**Expected balances after:**
- `Σ principal_due = 100,000.00` **exactly** = financed amount.
- `Σ interest_due  = 20,000.00` **exactly** = `100,000 × 2% × 10`.
- `Σ total_due = 120,000.00` = principal + total interest.
- `due_date`: `2026-04-01, 2026-05-01, … 2027-01-01` (`addMonthNoOverflow`).
**Risk it covers:** first/last-instalment rounding; schedule ≠ principal+interest.

---

### LOAN-02 | Flat schedule, awkward division — sum still exact (L-FLAT-ODD)
**Type:** unit · **Priority:** P0
**Setup:** L-FLAT-ODD — financed `100,000`; flat; `r = 1.5%`; `term = 7`; `n = 7`.
**Hand computation:**
- `totalInterest = 100,000 × 0.015 × 7 = 10,500.00`
- `principalPer = round(100,000 / 7, 2) = round(14,285.714285…, 2) = 14,285.71`
- `interestPer  = round(10,500 / 7, 2)  = 1,500.00`
- rows 1–6: principal `14,285.71`, interest `1,500.00`, total `15,785.71`
- row 7: principal `= round(100,000 − 6×14,285.71, 2) = round(100,000 − 85,714.26, 2) = 14,285.74`; interest `= round(10,500 − 9,000, 2) = 1,500.00`; total `15,785.74`
- check: `Σ principal = 85,714.26 + 14,285.74 = 100,000.00`; `Σ interest = 9,000.00 + 1,500.00 = 10,500.00`; `Σ total = 6×15,785.71 + 15,785.74 = 94,714.26 + 15,785.74 = 110,500.00 = 100,000 + 10,500`
**Expected balances after:** the three sums above, to the cent. The `0.03` rounding residue is absorbed entirely into row 7's principal (`14,285.74` vs `14,285.71`).
**Risk it covers:** rounding drift accumulating; last-row absorption (`LoanAmortizer.php:114`). **README top-25 #12.**

---

### LOAN-03 | Reducing-balance schedule (L-RB-1)
**Type:** unit · **Priority:** P0
**Setup:** L-RB-1 — financed `P = 12,000`; reducing_balance; monthly rate `1.0%`; `term = 3`; monthly → `periodsPerMonth = 1`, `n = 3`, `periodRate = (1.0/100)/1 = 0.01`.
**Hand computation** (`LoanAmortizer::reducingBalance`, `:125`):
- `(1.01)^3 = 1.030301`
- `installment = 12,000 × 0.01 × 1.030301 / (1.030301 − 1) = 123.63612 / 0.030301 = 4,080.2654…`
- row 1: `interest = round(12,000 × 0.01, 2) = 120.00`; `principal = round(4,080.2654 − 120.00, 2) = round(3,960.2654, 2) = 3,960.27`; `balance = round(12,000 − 3,960.27, 2) = 8,039.73`
- row 2: `interest = round(8,039.73 × 0.01, 2) = round(80.3973, 2) = 80.40`; `principal = round(4,080.2654 − 80.40, 2) = round(3,999.8654, 2) = 3,999.87`; `balance = round(8,039.73 − 3,999.87, 2) = 4,039.86`
- row 3 (last): `interest = round(4,039.86 × 0.01, 2) = round(40.3986, 2) = 40.40`; `principal = round(balance, 2) = 4,039.86`; `balance = 0.00`
- `total_due`: r1 `round(3,960.27+120.00,2)=4,080.27`; r2 `4,080.27`; r3 `round(4,039.86+40.40,2)=4,080.26`
**Expected balances after:**
- `Σ principal_due = 3,960.27 + 3,999.87 + 4,039.86 = 12,000.00` exactly.
- `Σ interest_due = 120.00 + 80.40 + 40.40 = 240.80`.
- `Σ total_due = 12,240.80 = 12,000 + 240.80`.
**Risk it covers:** annuity formula; declining-balance interest; last-row principal = remaining balance.

---

### LOAN-04 | Disbursement — Cash
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 Approved; document charge (n/a, `processing_fee_percent=0`); `TILL-HQ.current_balance = 1,000,000`; user with `Active Loans,Approve`.
**Steps:** `POST /loans/{id}/disburse {disbursement_method: Cash, first_installment_date: 2026-04-01, disbursed_at: 2026-03-01}`.
**Expected ledger entries** (one `journal_entries` row, `source_type='Loan'`, `source_id=loan.id`, `entry_date=2026-03-01`):
```
Dr 1100        100,000.00
   Cr 1010-1              100,000.00
```
Plus one `finance_transactions` row: `txn_type=DISBURSEMENT`, `amount=100,000.00`, `direction=Out`, `source_account_type=cash`, `idempotency_key='loan-disbursement-{id}'`, `journal_entry_id` = the entry above.
Plus one `cashbook_entries` mirror: `direction=Out`, `amount=100,000.00`, `balance_after=900,000.00`.
**Expected balances after:**
- `TILL-HQ.current_balance = 1,000,000 − 100,000 = 900,000.00`; `1010-1` leaf balance `= 900,000.00` (INV-04).
- `1100` balance `= 100,000.00`.
- `loan.status = Active`; `outstanding_balance_cache = 100,000.00`; 10 schedule rows exist.
**Risk it covers:** Risk 9 (cache=ledger), regression on `LoanLedgerPoster::postDisbursement`.

---

### LOAN-05 | Disbursement — Bank
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 Approved with `disbursement_method=Bank`, `disbursement_bank_account_id = BANK-1`; `BANK-1.current_balance = 1,000,000`, `overdraft_limit = 0`.
**Steps:** `POST /loans/{id}/disburse {disbursement_method: Bank, disbursement_bank_account_id: 1, first_installment_date: 2026-04-01}`.
**Expected ledger entries:**
```
Dr 1100        100,000.00
   Cr 1020-1              100,000.00
```
`finance_transactions.source_account_type=bank`.
**Expected balances after:** `BANK-1.current_balance = 900,000.00 = 1020-1` leaf; `1100 = 100,000.00`.
**Risk it covers:** correct bank leaf credited (not the till), `disbursement_bank_account_id` captured.

---

### LOAN-06 | Disbursement — request path: money and loan-completion are separate transactions
**Type:** integration · **Priority:** P1
**Setup:** L-FLAT-1 Approved; loan officer raises `POST /loans/{id}/request-disbursement`; Finance approves the resulting `finance_requests` row.
**Steps:**
1. Finance executes the request (`FinanceRequestService::execute`).
2. Inject a fault into `LoanDisbursementCompleter::complete` (e.g. `LoanAmortizer` throws) for this run.
**Expected ledger entries:** the money-movement entry **is** posted and committed (`Dr 1100 100,000 / Cr <source leaf> 100,000`), `finance_transactions` row `Completed`, `finance_requests.status='Paid'`.
**Expected balances after:**
- Source account moved by `100,000.00`; `1100 = 100,000.00`.
- `loan.status` still `Approved`; **no schedule rows**; `finance_requests.status = 'Paid'` (not `Completed`).
- An `errors`-channel log line "Disbursement completion failed after payment" (`CompleteDisbursementOnSettled.php:45`).
**Risk it covers:** the money moves and the GL is right, but the loan is left half-built and must be finished by hand — documents the boundary at `CompleteDisbursementOnSettled.php:59`.

---

## Payment allocation

### LOAN-07 | Bank-method instalment payment posts NO ledger entry
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (documents Risk 1)**
**Setup:** L-FLAT-1 Active; instalment 1 due `12,000` (P 10,000 + I 2,000); nothing paid.
**Steps:** `POST /loans/{id}/payments {amount: 12000, payment_method: Bank}`.
**Expected ledger entries (current behaviour):** **none.** `LoanLedgerPoster::postCashReceipt` returns immediately (`LoanLedgerPoster.php:299`).
**Expected balances after (current):**
- Instalment 1 `status=Paid`, `paid_amount=12,000.00`; `outstanding_balance_cache` reduced by `12,000`.
- `1100` **unchanged** (still `100,000`); `4010` **unchanged**; no bank receipt anywhere.
- INV-04 **fails**: `Σ loan principal outstanding (89,999.xx)` ≠ `1100 (100,000)`.
**Target behaviour the test asserts (xfail until fixed):** a GL entry
```
Dr <bank leaf or 1030 clearing>   12,000.00
   Cr 1100                                    10,000.00
   Cr 4010                                     2,000.00
```
plus a bank/clearing balance movement.
**Risk it covers:** Risk 1. **README top-25 #6.**

---

### LOAN-08 | Cash instalment payment — allocation order & exact split
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 Active, disbursed `2026-03-01`. CLOCK = `2026-05-20`. Instalments 1 & 2 both overdue, nothing paid. LT-FLAT: `grace_period_days=5`, `default_charge_rate_percent=3.0`.
**Hand computation of what is due on `2026-05-20`:**
- Instalment 1: due `2026-04-01`. `overdueBalance = 10,000 + 2,000 − 0 = 12,000`. `chargeStart = 2026-04-01 + 5d = 2026-04-06`. Penalty loop (`LoanSummary::penaltyDueForRow`): `dailyBase = 12,000 × 3/100 = 360`.
  - cursor `2026-04-06` → segmentEnd `2026-05-01` (< today) → `days = 25`, `daysInMonth(April)=30` → `360/30 × 25 = 300.00`
  - cursor `2026-05-01` → segmentEnd `2026-06-01` > today → `= 2026-05-20` → `days = 19`, `daysInMonth(May)=31` → `360/31 × 19 = 220.645161…`
  - `penalty₁ = round(300.00 + 220.645161, 2) = 520.65`
- Instalment 2: due `2026-05-01`. `chargeStart = 2026-05-06`. today `2026-05-20 > 2026-05-06`. `dailyBase = 360`. cursor `2026-05-06` → segmentEnd `2026-06-01` > today → `2026-05-20`, `days = 14`, `daysInMonth(May)=31` → `penalty₂ = round(360/31 × 14, 2) = round(162.580645, 2) = 162.58`
- Total penalty due = `520.65 + 162.58 = 683.23`
**Steps:** `POST /loans/{id}/payments {amount: 20000, payment_method: Cash, paid_at: 2026-05-20}`.
**Waterfall** (`LoanPaymentAllocator::applyAllocation`, order = interest → penalty → charges → principal, oldest instalment first):
- Inst 1 interest `2,000.00` → remaining `18,000.00`
- Inst 1 penalty `520.65` → remaining `17,479.35`
- Inst 1 charges: none
- Inst 1 principal `10,000.00` → remaining `7,479.35`
- Inst 2 interest `2,000.00` → remaining `5,479.35`
- Inst 2 penalty `162.58` → remaining `5,316.77`
- Inst 2 principal `min(5,316.77, 10,000.00) = 5,316.77` → remaining `0.00`
- Applied totals: principal `= 15,316.77`, interest `= 4,000.00`, penalty `= 683.23`; sum `= 20,000.00` ✓
**Expected ledger entries** (one COLLECTION entry, `source_type='LoanPayment'`):
```
Dr 1010-1      20,000.00
   Cr 1100              15,316.77
   Cr 4010               4,000.00
   Cr 4030                 683.23
```
check: `15,316.77 + 4,000.00 + 683.23 = 20,000.00` ✓
**Expected balances after:**
- Inst 1 `status=Paid` (`paid_amount = 2,000 + 10,000 = 12,000` ≥ `total_due 12,000`).
- Inst 2 `status=Partial`, `paid_amount = 2,000 + 5,316.77 = 7,316.77`.
- `TILL-HQ` +20,000; `1010-1` leaf +20,000.
- `1100` reduced by `15,316.77`; `4010` credit `+4,000`; `4030` credit `+683.23`.
- `outstanding_balance_cache` recomputed (INV-04 must hold once we net the still-unpaid interest/penalty of instalments 2–10).
**Risk it covers:** allocation order; penalty priced identically for display and collection; per-bucket GL split from allocation rows not from `payment.amount` (`LoanLedgerPoster::splitLines`).

---

### LOAN-09 | Partial payment — waterfall stops mid-bucket
**Type:** integration · **Priority:** P0
**Setup:** as LOAN-08 but `amount = 2,500`, `paid_at = 2026-05-20`.
**Waterfall:** Inst 1 interest `2,000.00` → rem `500.00`; Inst 1 penalty `min(500.00, 520.65) = 500.00` → rem `0.00`. Nothing to principal.
**Expected ledger entries:**
```
Dr 1010-1       2,500.00
   Cr 4010               2,000.00
   Cr 4030                 500.00
```
**Expected balances after:** Inst 1 `status=Partial`, `paid_amount = 2,000.00` (penalty is **not** part of `paid_amount` — `LoanPaymentAllocator.php:503`). `1100` unchanged. Penalty allocation row `penalty_applied=500.00`. `4030` credit `+500.00`.
**Risk it covers:** penalty excluded from `paid_amount`/status; partial-bucket stop.

---

### LOAN-10 | Exact / over / duplicate payment; no credit-balance mechanism
**Type:** integration · **Priority:** P1
**Setup:** L-FLAT-1 Active; `outstanding_balance_cache` and live outstanding = `120,000.00` exactly (no penalty yet — pay on `2026-04-01`).
**Steps & expected:**
1. Pay `120,000.00` Cash → succeeds; loan `status=Closed`, `closed_at` set; one COLLECTION entry `Dr 1010-1 120,000 / Cr 1100 100,000 / Cr 4010 20,000`.
2. On a fresh copy, pay `120,000.01` → HTTP **422** "Amount exceeds the outstanding balance (120000)" (`LoanPaymentController.php:127`). **No entry, no balance move.**
3. On a fresh copy, submit `120,000.00` twice → first closes the loan; second → **422** "This loan has no outstanding balance" (`:116`). One entry only.
**Expected balances after:** `1100` for the loan `= 0.00`; `4010` `= 20,000.00`; till `+120,000` once.
**Risk it covers:** overpayment silently posted / negative principal; **documents there is no refund or customer-credit-balance flow** (P1 gap).

---

### LOAN-11 | Duplicate interest-only payment slips past the outstanding guard
**Type:** integration · **Priority:** P1 · **partial FAIL (Risk 6)**
**Setup:** L-FLAT-1 Active on `2026-04-01`; submit an interest-only manual allocation `{allocation_mode: manual, allocations: {interest: 2000}, amount: 2000}` twice in quick succession (before approval re-check).
**Expected:** both create a `facility_payments` row `PendingApproval`; when both are approved, two `Dr 2100→…` reclass entries and two `Cr 4010 2,000` — interest income double-counted, because `outstanding` (`120,000`) never dropped below `2,000` between the two submissions and there is no request-level dedupe.
**Expected balances after:** `4010` over-credited by `2,000.00`; instalment 1 `interest` over-applied (bounded per-row by `interest_due` in the allocator, so the second lands on instalment 2).
**Risk it covers:** Risk 6 — idempotency gap on non-settlement payments.

---

## Penalty / late fee across boundaries

### LOAN-12 | Grace-period edge — penalty is 0 on the grace day, > 0 the day after
**Type:** unit · **Priority:** P0
**Setup:** one schedule row: `principal_due=10,000`, `interest_due=2,000`, `paid_amount=0`, `due_date=2026-02-28`. `graceDays=5`, `chargeRate=3.0`. `chargeStart = 2026-02-28 + 5d = 2026-03-05`.
**Hand computation** (`penaltyDueForRow`):
- `asOf = 2026-03-05`: `!$today->greaterThan($chargeStart)` → `2026-03-05 > 2026-03-05` is false → **return 0.00**
- `asOf = 2026-03-06`: cursor `2026-03-05` → segmentEnd `2026-04-01` > today → `2026-03-06`, `days = 1`, `daysInMonth(March)=31`, `dailyBase = 12,000 × 0.03 = 360` → `penalty = round(360/31 × 1, 2) = round(11.6129, 2) = 11.61`
- `asOf = 2026-03-04` (before grace ends) → `2026-03-04 > 2026-03-05` false, and `due_date < today` true, so first guard passes but grace guard returns **0.00**
**Expected balances after:** exactly `0.00`, `0.00`, `11.61` for the three dates.
**Risk it covers:** off-by-one at the grace boundary; `>` vs `>=`.

---

### LOAN-13 | Penalty accrual across month and year boundaries — per-month daily rate
**Type:** unit · **Priority:** P1
**Setup:** row `principal_due=20,000`, `interest_due=5,000`, `paid=0`. `overdueBalance = 25,000`. `chargeRate = 3.0` → `dailyBase = 25,000 × 0.03 = 750`. `graceDays = 5`.
**Sub-case A (Feb→Mar):** `due_date = 2026-01-25` → `chargeStart = 2026-01-30`. `asOf = 2026-03-10`.
- cursor `2026-01-30` → segEnd `2026-02-01` → `days = 2`, `daysInMonth(Jan)=31` → `750/31 × 2 = 48.387096…`
- cursor `2026-02-01` → segEnd `2026-03-01` → `days = 28`, `daysInMonth(Feb 2026)=28` → `750/28 × 28 = 750.00`
- cursor `2026-03-01` → segEnd `2026-04-01` > today → `2026-03-10`, `days = 9`, `daysInMonth(March)=31` → `750/31 × 9 = 217.741935…`
- `total = round(48.387096 + 750.00 + 217.741935, 2) = round(1,016.129031, 2) = 1,016.13`
**Sub-case B (Dec→Jan, year boundary):** `due_date = 2025-12-20` → `chargeStart = 2025-12-25`. `asOf = 2026-01-15`.
- cursor `2025-12-25` → segEnd `2026-01-01` → `days = 7`, `daysInMonth(Dec)=31` → `750/31 × 7 = 169.354838…`
- cursor `2026-01-01` → segEnd `2026-02-01` > today → `2026-01-15`, `days = 14`, `daysInMonth(Jan)=31` → `750/31 × 14 = 338.709677…`
- `total = round(169.354838 + 338.709677, 2) = round(508.064515, 2) = 508.06`
**Expected balances after:** `1,016.13` (A), `508.06` (B). 2028 (leap) variant: `daysInMonth(Feb)=29` changes the Feb term.
**Risk it covers:** flat-30-day assumption; year rollover in `addMonthNoOverflow`.

---

## Early settlement / restructure / write-off

### LOAN-15 | Early settlement — accrued-to-date interest only (full worked example)
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 disbursed `2026-03-01`, first instalment `2026-04-01`, 10 × (P 10,000 / I 2,000). Nothing paid. Cashier requests full settlement on `2026-04-15`; a second approver approves.
**Hand computation:**
*Interest accrued by 2026-04-15* (`InterestAccrualSchedule::accruedThrough`, period start = prior due date, first period start = `disbursed_at`):
- Inst 1: period `2026-03-01 → 2026-04-01`, `asOf ≥ end` → accrued `= 2,000.00`; waive `= 2,000 − 2,000 = 0.00`
- Inst 2: period `2026-04-01 → 2026-05-01`, `asOf = 2026-04-15` between → accrued `= round(2,000 × 14/30, 2) = round(933.333…, 2) = 933.33`; waive `= 2,000 − 933.33 = 1,066.67`
- Inst 3–10: period starts after `asOf` → accrued `= 0.00`; waive `= 2,000.00` each (8 × 2,000 = 16,000)
- Persisted `waived_interest`: Inst 2 `1,066.67`, Inst 3–10 `2,000.00` each. Total waived `= 17,066.67`
*Penalty on 2026-04-15:* only Inst 1 is past due. `chargeStart = 2026-04-06`, `days = 9`, `daysInMonth(April)=30`, `dailyBase = (10,000+2,000)×0.03 = 360` → `penalty₁ = round(360/30 × 9, 2) = 108.00`
*Settlement outstanding:*
- schedule balance = `12,000 (I1) + (12,000 − 1,066.67) (I2) + 8×(12,000 − 2,000) = 12,000 + 10,933.33 + 80,000 = 102,933.33`
- penalty balance = `108.00`
- charges = `0`
- **outstanding `Q = round(102,933.33 + 108.00, 2) = 103,041.33`**
*Allocation of `Q` at approval:*
- I1: interest `2,000.00`, penalty `108.00`, principal `10,000.00` → cumulative `12,108.00`
- I2: interest `933.33` (interest_due now reduced to 933.33), principal `10,000.00`
- I3–10: interest `0.00`, principal `10,000.00` each
- totals: principal `100,000.00`, interest `2,000.00 + 933.33 = 2,933.33`, penalty `108.00`; sum `= 103,041.33` ✓
**Steps:**
1. `POST /loans/{id}/payments {is_settlement: true, payment_method: Cash, paid_at: 2026-04-15}` (amount is recomputed server-side to `103,041.33`).
2. Second approver `POST …/approve-settlement`.
**Expected ledger entries:**
- At record time (Cash held in suspense):
```
Dr 1010-1     103,041.33
   Cr 2100             103,041.33
```
- At approval (reclass out of suspense):
```
Dr 2100       103,041.33
   Cr 1100             100,000.00
   Cr 4010               2,933.33
   Cr 4030                 108.00
```
check: `100,000.00 + 2,933.33 + 108.00 = 103,041.33` ✓
**Expected balances after:**
- `loan.status = Closed`; `outstanding_balance_cache = 0.00`.
- `2100` nets to `0.00` for this loan.
- `1100` for this loan `= 0.00`; `4010` credited `2,933.33` (the ~17,066.67 of future interest is **waived, never touched income**); `4030` credited `108.00`.
- `TILL-HQ` +`103,041.33`; `1010-1` leaf +`103,041.33`.
- schedule rows 2–10 have `waived_interest` set and reduced `interest_due`/`total_due`.
**Risk it covers:** future interest recognised as income by mistake; suspense account not clearing; penalty double-charge. **README top-25 #18.**

---

### LOAN-16 | Settlement on the same day as disbursement
**Type:** integration · **Priority:** P1
**Setup:** L-FLAT-1 disbursed `2026-03-01`; settle `2026-03-01`.
**Hand computation:** every period's `accruedThrough` has `asOf` not `> start` → accrued `= 0.00` for all 10; entire `20,000` interest waived. No instalment past due → penalty `0`. `Q = Σ principal_due = 100,000.00`.
**Expected ledger entries:**
```
Dr 1010-1     100,000.00        (record)
   Cr 2100             100,000.00

Dr 2100       100,000.00        (approval)
   Cr 1100             100,000.00
```
No `4010` line at all (interest = 0, and a zero line is forbidden by `chk_journal_lines_one_side`).
**Expected balances after:** `1100` for loan `= 0`; `4010` unchanged; loan Closed.
**Risk it covers:** zero-interest settlement posting a zero line / crashing; disbursement-day divide-by-zero in accrual (`InterestAccrualSchedule::normalize`).

---

### LOAN-17 | Backdated payment reduces penalty income, no guard
**Type:** integration · **Priority:** P1
**Setup:** two identical loans L-A, L-B (both L-FLAT-1 Active, disbursed `2026-03-01`). CLOCK = `2026-06-01`. Instalment 1 (due `2026-04-01`) unpaid on both.
**Steps:**
- L-A: pay `12,000` Cash `paid_at = 2026-06-01` (today).
- L-B: pay `12,000` Cash `paid_at = 2026-04-20` (backdated 42 days).
**Hand computation of penalty applied:**
- L-A `asOf = 2026-06-01`: `chargeStart = 2026-04-06`. Terms: Apr `360/30×25 = 300.00`; May `360/31×31 = 360.00`; Jun `360/30×0`… cursor stops at today → actually `2026-04-06→2026-05-01` (25d, /30 → 300.00), `2026-05-01→2026-06-01` (31d, /31 → 360.00). `penalty_A = 660.00`. Allocation: interest 2,000 + penalty 660.00 + principal 9,340.00.
- L-B `asOf = 2026-04-20`: `chargeStart = 2026-04-06`. One term `2026-04-06→2026-04-20` = 14d, /30 → `360/30×14 = 168.00`. `penalty_B = 168.00`. Allocation: interest 2,000 + penalty 168.00 + principal 9,832.00.
**Expected ledger entries:**
- L-A: `Dr 1010-1 12,000 / Cr 1100 9,340.00 / Cr 4010 2,000.00 / Cr 4030 660.00`
- L-B: `Dr 1010-1 12,000 / Cr 1100 9,832.00 / Cr 4010 2,000.00 / Cr 4030 168.00`
**Expected balances after:** `4030` gets `660.00` from L-A but only `168.00` from L-B for the identical economic event — a user choosing an earlier `paid_at` **reduces recognised penalty income by `492.00`** with nothing stopping it. `entry_date` = the backdated date.
**Risk it covers:** Risk 7 (backdating), penalty logic driven by client-chosen date.

---

### LOAN-18 | Write-off over-credits `1100` with never-booked interest & penalty
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (documents Risk 2)**
**Setup:** L-FLAT-1 Active; instalments 1–3 paid in full (principal `30,000`, interest `6,000` collected — so this loan's contribution to `1100` is `100,000 − 30,000 = 70,000`). Loan is NPL at the 100% slab. `provision_required_amount = provision_posted_amount = 45,000` (posted earlier: `Dr 5300 45,000 / Cr 1190 45,000`). On write-off day `outstanding_balance_cache = 92,000` (unpaid principal `70,000` + unpaid interest `14,000` + accrued penalty `8,000` + charges `0`).
**Steps:** raise + approve a `loan_write_off` request. `amount = outstanding_balance_cache = 92,000.00` (`LoanController.php:938`).
**Hand computation** (`LoanLedgerPoster::postWriteOff`):
- `fromProvision = min(45,000, 92,000) = 45,000.00`
- `direct = round(92,000 − 45,000, 2) = 47,000.00`
**Expected ledger entries (current behaviour):**
```
Dr 1190   45,000.00
Dr 5300   47,000.00
   Cr 1100          92,000.00
```
**Expected balances after (current — the bug):**
- This loan's contribution to `1100` = `70,000 − 92,000 = −22,000.00` (abnormal credit → negative on Trial Balance).
- `5300` total charged for this loan = `45,000 + 47,000 = 92,000.00`, but the real economic loss is `70,000.00` of principal — **over-expensed by `22,000`** (the interest+penalty that were never income).
- INV-02 still nets (the entry is balanced), INV-04 **fails** for `1100`.
**Target entry the test asserts (xfail until fixed):**
```
Dr 1190   45,000.00
Dr 5300   25,000.00        (70,000 principal − 45,000 already provided)
   Cr 1100          70,000.00
```
**Risk it covers:** Risk 2. **README top-25 #4.**

---

### LOAN-19 | Write-off relieves the posted provision before hitting expense
**Type:** integration · **Priority:** P1
**Setup:** L-FLAT-1 Active, NPL, fully provisioned: `provision_posted_amount = 60,000`. Remaining principal on the books `= 50,000`. `outstanding_balance_cache = 50,000` (interest/penalty all waived first for this case so cache == principal).
**Hand computation:** `fromProvision = min(60,000, 50,000) = 50,000`; `direct = round(50,000 − 50,000, 2) = 0.00`.
**Expected ledger entries:**
```
Dr 1190   50,000.00
   Cr 1100          50,000.00
```
No `5300` line (direct = 0 → line omitted, `LoanLedgerPoster.php:143`).
**Expected balances after:** `1100` for loan `= 0.00`; `1190` reduced by `50,000` (the `10,000` over-provision stays in `1190`/`5300` — a separate release entry is expected via the provisioning recompute); `5300` unchanged by the write-off itself.
**Risk it covers:** double-counting the loss (expensing again what `5300` already absorbed).

---

### LOAN-20 | Recovery after write-off — no built-in path
**Type:** integration · **Priority:** P1
**Setup:** a `WrittenOff` loan (LOAN-18 fixture). Customer pays `20,000` cash months later.
**Steps:** `POST /loans/{id}/payments {amount: 20000, payment_method: Cash}`.
**Expected:** HTTP **422** "Payments can only be recorded against an active or approved loan" (`LoanPaymentController.php:61`). **No entry.**
**Expected balances after:** unchanged. The only way to book the recovery is a manual journal entry:
```
Dr 1010-1   20,000.00
   Cr 4050            20,000.00      (Bad Debt Recovery Income)
```
which a human must author (ManualJournalEntry, maker-checker).
**Risk it covers:** documents that post-write-off recovery has **no dedicated flow** and no automatic `4050` credit (P1 gap); the recovery could be mis-posted to `4900` or netted against `5300`.

---

### LOAN-21 | Restructure rollover — no cash, no disbursement entry, income catch-up only
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 Active, disbursed `2026-03-01`. Instalments 1–3 paid (principal `30,000` collected → `1100` holds `70,000` for this loan). CLOCK `2026-09-01`; instalments 4–7 overdue. Admin reschedules: term-extend, capitalise earned interest + retained OD, roll unpaid charges (none here), no manual added charges. Controller computes (record these as the controller's outputs, then assert the invariant, not the exact split):
- `capitalisedInterest` = earned portion of unpaid interest on instalments 4–10 as of `2026-09-01` — call it `Ci`
- `retainedOd` = accrued penalty kept = `Od`
- `rolledAndAddedCharges = 0`
- `total = Ci + Od`
**Steps:** `POST /loans/{id}/reschedule {...}`.
**Expected ledger entries** (`source_type='LoanReschedule'`, `source_id = newLoan.id`, `entry_date` = reschedule date):
```
Dr 1100        (Ci + Od)
   Cr 4010              Ci
   Cr 4030              Od
```
**No** COLLECTION / DISBURSEMENT entry (no cash moves — `LoanLedgerPoster.php:198`).
If the old loan carried a posted provision `V`: also
```
Dr 1190        V
   Cr 4050            V
```
**Expected balances after:**
- `Dr 1100` total `== Cr 4010 + Cr 4030 + Cr 4020` (structural — assert exactly).
- `newLoan.principal_amount == oldLoan remaining principal (70,000) + Ci + Od + rolled charges` (assert to the cent).
- Net change to `1100` across both loans `= +(Ci + Od)` (the new receivable recognised; the `70,000` carried over unchanged).
- old loan `status=Restructured`, `outstanding_balance_cache = 0`, `npl_flag=false`, provision fields zeroed.
- new loan has a fresh schedule; `Σ new principal_due == newLoan.principal_amount`.
- INV-04: `Σ(1100 across L-FLAT-1 old + new) == GL 1100`.
**Risk it covers:** phantom disbursement entry; income recognised that was never earned; provision left stranded; principal double-counted across the two linked loans.

---

## Rejection guards & edges

### LOAN-22 | Payment on a fully-paid / zero-outstanding loan is rejected
**Type:** integration · **Priority:** P0
**Setup:** L-FLAT-1 Closed (LOAN-10 step 1). Also a variant: Active loan with every instalment `Paid` but `status` not yet flipped.
**Steps:** `POST /loans/{id}/payments {amount: 100, payment_method: Cash}`.
**Expected:** HTTP **422** — "This loan has no outstanding balance" (`LoanPaymentController.php:116`) or "Payments can only be recorded against an active or approved loan" (`:61`).
**Expected ledger entries:** **none.**
**Expected balances after:** unchanged; no `facility_payments` row created.
**Risk it covers:** a stray payment posted against a closed loan (would credit `1100` negative). **README top-25 #13.**

---

### LOAN-23 | Payment on WrittenOff / Restructured / Cancelled loan is rejected
**Type:** integration · **Priority:** P0
**Setup:** three loans in states `WrittenOff`, `Restructured`, `Cancelled`.
**Steps:** attempt a Cash payment on each.
**Expected:** HTTP **422** on all three (`LoanPaymentController.php:61`). No entries.
**Risk it covers:** posting collections against a loan whose receivable is already gone.

---

### LOAN-24 | Loan cancel after disbursement — contra entry restores the till
**Type:** integration · **Priority:** P1
**Setup:** L-FLAT-1 disbursed Cash (LOAN-04). No payments. `TILL-HQ = 900,000`. Admin approves a cancel request.
**Steps:** `POST …/approve-cancel-request`.
**Expected ledger entries:** `LoanLedgerPoster::reverseDisbursement` → `FinancePostingService::reverse` on the DISBURSEMENT txn:
```
Dr 1010-1     100,000.00
   Cr 1100             100,000.00
```
Original disbursement entry `status → Reversed`; a `REVERSAL` `finance_transactions` row (`reversal_of_id` set); a cashbook mirror `direction=In 100,000`.
**Expected balances after:** `TILL-HQ = 1,000,000.00` (restored); `1100` for loan `= 0.00`; `loan.status = Cancelled`, `disbursed_amount = 0`, `outstanding_balance_cache = 0`.
**Risk it covers:** cancel that zeroes operational fields but leaves the GL/till holding the disbursement.

---

### LOAN-25 | Edge loans — zero interest, single instalment, extreme amounts
**Type:** unit · **Priority:** P2
**Setup & sub-cases:**
- **Zero interest:** L-FLAT-1 with `interest_rate_percent = 0`. `totalInterest = 0`. Schedule rows: principal `10,000` / interest `0` / total `10,000`. Payment posts `Dr 1010-1 10,000 / Cr 1100 10,000` — **no `4010` line** (guarded at `LoanLedgerPoster.php:438`).
- **Single instalment:** `term_months = 1`, monthly → `n = 1`. Row: principal `= round(100,000 − 0, 2) = 100,000.00`, interest `= round(totalInterest − 0, 2)`.
- **Reducing balance, rate 0:** `installment = principal / n` branch (`LoanAmortizer.php:129`); rows all `principal = round(P/n)` except last = remainder, interest `0`.
- **Smallest amount:** `principal = 0.01` financed → schedule must still balance to `0.01`; a `0.00` principal row is impossible (CHECK), so `n` must be 1.
- **Largest amount:** `principal = 999,999,999.99` — assert no overflow of `decimal(15,2)` in `total_due` / journal lines.
- **Max tenure:** `term_months = 120`, daily frequency 30/mo → `n = 3,600` rows; assert `Σ principal_due == financed` exactly and generation completes.
**Expected balances after:** every sub-case: `Σ principal_due == financed`, `Σ interest_due == hand-computed total`, no zero-amount journal line ever emitted.
**Risk it covers:** boundary crashes; zero-line CHECK violation; decimal overflow.

---

### LOAN-27 | Principal waiver never touches the GL — `1100` overstated, no bad-debt expense
**Type:** integration · **Priority:** P0 · **Expected result: FAIL (documents Risk 8)**
**Setup:** L-FLAT-1 Active; instalments 1–3 paid (principal `30,000` collected — `1100` for this loan `= 70,000.00`). Remaining principal due across instalments 4–10 `= 70,000.00`.
**Steps:** `POST /loans/{id}/waivers {waiver_type: Principal, amount: 70000, reason: "goodwill"}` — created directly by a single user (`approved_by = creator`, `LoanWaiverController.php:33`), no maker-checker, no second approver.
**Expected (current):** the waiver is applied via `LoanPaymentAllocator::waivedByRow` the next time anything reads `outstandingBalance()` — each remaining schedule row's `principal_due` is netted down to `0` in that computation, and the loan can now be closed with a `0`-amount "payment" (or closes automatically once nothing is left owing). **No `journal_entries` row is ever created for this waiver** (`LoanWaiverController.php:20` — no `LedgerPoster` call anywhere in the controller).
**Expected balances after (current):** `1100` for this loan **stays at `70,000.00`** even though the loan shows `outstanding_balance_cache = 0` and can be closed; `5300`/`4050` untouched — the `70,000` loss never reaches the P&L. INV-04 fails (`Σ loan principal outstanding` no longer matches `1100`, in the other direction from Risk 2 — here the operational side says less is owed than the GL shows).
**`destroy()` compounds it:** deleting the waiver (`:41`) hard-removes the row with **no reversal, no audit trail** — a loan already reported as settled/closed on this waiver can silently revert to owing `70,000` again with nothing in the history explaining why.
**Target behaviour the test asserts (xfail):** a `Principal` waiver posts
```
Dr 5300   70,000.00
   Cr 1100           70,000.00
```
(or a dedicated "waiver expense" account) at the moment it's created, and requires the same maker-checker gate as `loan_write_off`.
**Risk it covers:** Risk 8.

---

### LOAN-26 | Post-dated cheque realization
**Type:** integration · **Priority:** P2
**Setup:** L-FLAT-1 Active; a `facility_post_dated_cheques` row recorded, `cheque_date = 2026-05-10`, amount `12,000`.
**Steps:** on `2026-05-10` realize the PDC (`LoanPostDatedChequeController`, `asOf = 2026-05-10`).
**Expected ledger entries:** `LoanLedgerPoster::postChequeRealized` → `Dr 1030 12,000 / Cr 1100 10,000 / Cr 4010 2,000` (`entry_date = 2026-05-10`). Allocation applied as-of `2026-05-10`.
**Expected balances after:** `1030` +`12,000` (and stays — no flow clears `1030` to a bank leaf, Risk 10); instalment 1 `Paid`.
**Risk it covers:** Risk 10 — `1030` accumulation; realization date drives allocation not the click date.
