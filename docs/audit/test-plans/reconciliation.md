# Reconciliation & reporting — test plan

Code under test: `AccountingReportController` (Trial Balance, P&L, Balance Sheet, Cash Flow, Ledger
Drift), `CashBankReportController`, `LoanReportController`, `CollectionReportController`,
`ExpenseReportController`, `FinanceReportController`, `ChartOfAccount::balancesAsOf()`.

This file composes cases from every other plan file into one scripted business day, then checks
that every report agrees with the ledger and with itself across output formats.

---

### RECON-01 | Full-day mixed simulation — every report reconciles to the ledger
**Type:** e2e · **Priority:** P0
**Setup:** fresh DB. Seed BR-HQ + Branch-B, TILL-HQ (`opening 1,000,000` via OB-01), TILL-B (`opening 300,000`), BANK-1 (`opening 500,000`), PCF-1 (`50,000`), COA, LT-FLAT, LT-RB, P-TYPE-1, EXP-CAT-1. CLOCK `2026-03-01` through `2026-03-01` (single business day, all events dated the same day for a clean full-day check).
**Steps** (each drawn verbatim from its file's worked case — run in this order):
1. `LOAN-04` — disburse L-FLAT-1, Cash, `100,000`.
2. `LOAN-08`'s payment shape, simplified to same-day (no penalty): loan instalment 1 paid `12,000` Cash (`Cr 1100 10,000 / Cr 4010 2,000`).
3. `PWN-02` — disburse P-1, Cash, `200,000`.
4. `PWN-03`'s shape same-day (no interest, `daysElapsed=0` → still special rate `2.0%`... use the pinned `PWN-03` figures as given, dated `2026-03-01`, treating it as a same-day worked scenario): redeem for `202,000` total (`interest 2,000` at `daysElapsed=0`→ special rate applies, recompute: `interest = round(200,000×2/100,2) = 4,000.00`, total `204,000.00`) → `Dr 1010-1 204,000 / Cr 4025 4,000 / Cr 1120 200,000`.
5. `EXP-01` — pay expense `50,000` Cash.
6. `EXP-04` — petty cash spend `3,500`.
7. `EXP-13` — petty cash top-up `25,000` from TILL-HQ.
8. `CF-01` — issue float `100,000` from TILL-HQ.
9. `CF-10` — bank deposit `150,000` from TILL-HQ to BANK-1.
10. `FD-01` — FD deposit `500,000` Cash into TILL-HQ.
11. `BRW-01` — borrowing drawdown `1,000,000` Cash into TILL-HQ.
12. `COMM-01` — commission payment `5,000` Cash from TILL-HQ.

**Expected balances after** (hand-rolled running total for TILL-HQ, opening `1,000,000`):
```
+1,000,000  opening
−  100,000  (1) loan disbursement
+   12,000  (2) loan payment
−  200,000  (3) pawning disbursement
+  204,000  (4) pawning redemption
−   50,000  (5) expense
−    3,500 is NOT a TILL move — petty cash spend moves the FUND, not TILL-HQ
+   25,000  → actually (7) top-up MOVES TILL-HQ: −25,000 (cash leaves TILL to fund the float... wait (7) funds PCF from TILL) → TILL-HQ −25,000
−  100,000  (8) float issue
−  150,000  (9) bank deposit
+  500,000  (10) FD deposit
+1,000,000  (11) borrowing drawdown
−    5,000  (12) commission
= 1,000,000 −100,000 +12,000 −200,000 +204,000 −50,000 −25,000 −100,000 −150,000 +500,000 +1,000,000 −5,000
= 2,086,000.00
```
(Re-add carefully in the actual test as a running ledger — the point is the test computes this **independently of the app**, e.g. in a spreadsheet or a hand-written script, before asserting.)
**Expected ledger entries:** 12 balanced entries (11 via `FinancePostingService`, all with cashbook mirrors on every TILL/BANK leg); `Σ debit = Σ credit` system-wide (INV-01).
**Expected balances after (full set):**
- `TILL-HQ.current_balance` == the hand-rolled total above == its `1010-1` GL leaf (INV-03/INV-04).
- `PCF-1.current_balance = 50,000 − 3,500 + 25,000 = 71,500.00 = 1015-1` leaf.
- `BANK-1.current_balance = 500,000 + 150,000 = 650,000.00 = 1020-1` leaf.
- `1100` (loan) `= 90,000.00`; `1120` (pawning) `= 0.00`; `2010` (FD) `= 500,000.00`; `2200` (borrowing) `= 1,000,000.00`; `4010=2,000.00`; `4025=4,000.00`; `5010=50,000+3,500=53,500.00`; `5200=5,000.00`.
- Trial Balance as-of `2026-03-01` nets to `0.00` (INV-02).
**Risk it covers:** any single case passing in isolation while the composed day breaks (shared state, account-resolution collisions, float created only once per custodian, etc.). **README top-25 #23.**

---

### RECON-02 | Same figure across UI, export, and PDF for every report
**Type:** e2e · **Priority:** P0
**Setup:** RECON-01's DB.
**Steps:** for each of Trial Balance, P&L, Balance Sheet, Cash Flow, Loan Portfolio, Pawning Register, Expense Summary, Cashbook: fetch (a) the JSON the UI table binds to, (b) the CSV/Excel export, (c) the PDF export (parse its text layer).
**Expected balances after:** every total/subtotal figure identical to the cent across (a)/(b)/(c) — no rounding-for-display divergence (e.g. a PDF template re-rounding a percentage or a running balance independently).
**Risk it covers:** a display-layer rounding bug that never touches the ledger but makes two "official" copies of the same report disagree.

---

### RECON-03 | Trial Balance ties to `journal_lines` independent of the report's own SQL
**Type:** integration · **Priority:** P0
**Setup:** RECON-01's DB.
**Steps:** compute each account's Trial Balance figure with a **hand-written** raw query (`GROUP BY account_id` over `Posted` lines only) — not by calling `ChartOfAccount::balancesAsOf()` (that's INV-03's job) and not by calling the Trial Balance endpoint's own code path twice.
**Expected balances after:** the report's JSON matches the independently-computed figures to the cent, for every account, and the report's own displayed total nets to `0.00`.
**Risk it covers:** the report using a different filter (e.g. including `Reversed` entries, or excluding branch-null entries) than the rest of the system.

---

### RECON-04 | Cashbook report ties to till balances and `finance_transactions`
**Type:** integration · **Priority:** P0
**Setup:** RECON-01's DB.
**Steps:** for TILL-HQ, sum `cashbook_entries.amount` (signed by `direction`) for the day; separately sum `finance_transactions` legs touching TILL-HQ.
**Expected balances after:** both sums equal `TILL-HQ.current_balance − TILL-HQ.opening_balance` exactly; the Cashbook report's running `balance_after` column, read top-to-bottom, ends on `TILL-HQ.current_balance`.
**Risk it covers:** a cash leg posted without its cashbook mirror (would make the Cashbook report understate activity while the till balance is still correct — silent report drift with no balance-level symptom).

---

### RECON-05 | Loan portfolio report ties to `1100`, net of the cash-basis caveat
**Type:** integration · **Priority:** P0
**Setup:** RECON-01's DB plus `LOAN-08` (an overdue instalment with interest/penalty accrued but uncollected).
**Steps:** `Σ (Loan Portfolio report's "Principal Outstanding" column)` vs GL `1100` balance.
**Expected balances after:** equal, **because** the report's "Principal Outstanding" is defined as principal only (matching what `1100` actually holds) — assert the report does **not** use `outstanding_balance_cache` (which is all-in: principal+interest+penalty+charges, per `LoanPaymentAllocator.php:74`) for this column, or if it does, that the report is separately reconciled with the documented gap called out rather than silently mismatched.
**Risk it covers:** the single most likely place Risk 9's cache-vs-ledger distinction produces a report that looks right but is measuring the wrong thing.

---

### RECON-06 | Pawning register ties to `1120`
**Type:** integration · **Priority:** P1
**Setup:** RECON-01's DB with an additional open ticket (P-2ART, `outstanding 133,333.33` after PWN-05).
**Steps:** `Σ (Pawning Register "Outstanding Capital")` vs GL `1120`.
**Expected balances after:** equal exactly — pawning's cache **is** principal-only (unlike loans), so this should already hold; the test is the guard that it keeps holding.
**Risk it covers:** regression if pawning's cache definition ever changes to include interest (matching Risk 9's loan failure mode).

---

### RECON-07 | Expense summary ties to `5010` and every other category account, for the period
**Type:** integration · **Priority:** P1
**Setup:** RECON-01's DB plus a second expense in a different category (→ `5100`, say, misused for a non-FD expense to test cross-category summation — or a dedicated second category account).
**Steps:** `Σ (Expense Summary report, by category)` vs each category's GL account balance for the period.
**Expected balances after:** equal per category, and the report's grand total equals `Σ` across categories, equals the sum of all `EXPENSE_PAYMENT`-type `finance_transactions` for the period.
**Risk it covers:** a category with `chart_of_account_id=null` silently dropped from the report while its expense still posted somewhere (or vice versa — blocked at payment time per `ExpensePaymentExecutor.php:38`, so this should be unreachable; assert it actually is).

---

### RECON-08 | Date filters — no leakage across the boundary
**Type:** integration · **Priority:** P1
**Setup:** RECON-01's DB (all dated `2026-03-01`) plus one entry each dated `2026-02-28` and `2026-03-02`.
**Steps:** run every report above with `from=2026-03-01, to=2026-03-01`.
**Expected balances after:** totals identical to RECON-01/03/04/05 (the boundary entries excluded); re-run with `from=2026-02-28, to=2026-03-02` and confirm the totals now **do** include all three days, with the difference exactly equal to the two boundary entries' amounts.
**Risk it covers:** an off-by-one on `whereDate('entry_date', '>=' / '<=')` silently including or excluding a boundary day.

---

### RECON-09 | Branch scoping — sums to the same whole
**Type:** integration · **Priority:** P1
**Setup:** RECON-01's DB (HQ + Branch-B events — extend the script with at least one Branch-B loan payment, per `CF-02`).
**Steps:** run the Trial Balance / Cashbook / Loan Portfolio filtered by `branch_id=HQ`, then by `branch_id=Branch-B`, then unfiltered.
**Expected balances after:** `report(HQ) + report(Branch-B) + report(branch_id IS NULL, e.g. company-wide journal entries) == report(unfiltered)` for every account that has branch-scoped activity; entries with `branch_id=null` (e.g. a manual JE never tied to a branch) appear only in the unfiltered total, not double-counted into either branch.
**Risk it covers:** a branch filter using `WHERE branch_id = X` (dropping company-wide entries from every branch view **and** from their sum) vs `WHERE branch_id = X OR branch_id IS NULL` (double-counting them into every branch) — either bug is invisible unless the three totals are cross-checked.

---

### RECON-10 | Report totals are stable across repeated calls (cache correctness)
**Type:** unit · **Priority:** P2
**Setup:** RECON-01's DB.
**Steps:** call `ChartOfAccount::balancesAsOf($asOf, $fromDate)` four times in one request lifecycle with the same arguments (mirroring the P&L's real call pattern — `AccountingReportController.php:723` docblock notes it calls this 4× per request), then once with different arguments.
**Expected balances after:** all four same-argument calls return identical results (memoized, per `ChartOfAccount.php:143`); the differently-argued call recomputes rather than returning the cached figure for the wrong range.
**Risk it covers:** the per-request static cache keyed incorrectly (e.g. keying only on `$asOfDate`, silently ignoring `$fromDate`) — would make a P&L's income and waterfall figures agree with each other for the wrong reason.
