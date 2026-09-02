# Financial-correctness test plans

Companion to [`../ledger-matrix.md`](../ledger-matrix.md). **Plans only — no test code.**

One file per module:

| File | Covers |
|---|---|
| [`ledger-invariants.md`](ledger-invariants.md) | Cross-cutting invariants that must hold after **every** scenario in every other file |
| [`loans.md`](loans.md) | Loan lifecycle: schedule generation, disbursement, allocation, penalty, settlement, restructure, write-off, closure |
| [`speed-drafts.md`](speed-drafts.md) | Speed Draft disbursement, payment, renewal-capitalisation |
| [`pawning.md`](pawning.md) | Advance, interest, redemption, renewal, auction, surplus, loss |
| [`expenses.md`](expenses.md) | Direct expense, request path, petty cash, voids, WHT gap, closed-period gap |
| [`cash-floats.md`](cash-floats.md) | Float issue/return, cash sessions, shortage/excess, transfers, bank deposit/withdrawal |
| [`fixed-deposits.md`](fixed-deposits.md) | FD deposit, monthly/maturity interest, withdrawal, part-payment, renewal |
| [`borrowings.md`](borrowings.md) | Company borrowing drawdown, interest, repayment, overdraft |
| [`sales-commission.md`](sales-commission.md) | Commission accrual, payment (client-amount bug), void |
| [`bank-and-cashbook.md`](bank-and-cashbook.md) | Manual bank transactions, manual cashbook adjustments, `1030` accumulation |
| [`opening-balances-and-manual-journal.md`](opening-balances-and-manual-journal.md) | Account opening balances, subledger true-up, manual journal entries, reversals |
| [`reconciliation.md`](reconciliation.md) | Full-day simulation; report-vs-ledger; UI/export/PDF parity; date & branch scoping |

## Case format

```
ID | Title | Type (unit/integration/e2e) | Priority (P0/P1/P2)
Setup:   fixtures / DB state
Steps:   actions taken
Expected ledger entries: exact Dr/Cr lines with exact amounts — arithmetic shown inline
Expected balances after:  operational + GL balances
Risk it covers:  Risk # from ledger-matrix.md, or "regression"
```

Every asserted amount is hand-computed in the case body **from first principles**, never by
calling the function under test. A test that computes its expectation with
`LoanAmortizer::generate()` and then asserts the schedule equals `LoanAmortizer::generate()` is
worthless — those cases use a literal table typed from the arithmetic shown here.

## Shared fixtures (referenced by ID across files)

| Fixture | Definition |
|---|---|
| **BR-HQ** | Branch, code `HQ`. Primary till **TILL-HQ** = `cash_accounts` id 1, GL leaf `1010-1`, `allow_negative=false`, `opening_balance=0`. |
| **BANK-1** | `bank_accounts` id 1, GL leaf `1020-1`, `overdraft_limit=0`, `opening_balance=0`. |
| **COA** | Seeded chart of accounts (`2026_08_01_000012_seed_chart_of_accounts.php` + follow-ons). Key codes: `1010/1015/1020/1030/1100/1110/1120/1190/2010/2100/2110/2200/3015/4010/4015/4025/4030/4050/4900/4905/5010/5100/5110/5200/5300/5310`. |
| **LT-FLAT** | `loan_types`: `interest_method=flat`, `grace_period_days=5`, `default_charge_rate_percent=3.0`, `processing_fee_percent=0`, no commission. |
| **LT-RB** | as LT-FLAT but `interest_method=reducing_balance`. |
| **L-FLAT-1** | Loan on LT-FLAT: `principal_amount=100000`, `down_payment=0`, `interest_rate_percent=2.0`, `rate_period=monthly`, `term_months=10`, `installment_frequency=monthly`, `disbursement_method=Cash`, `disbursed_at=2026-03-01`, `first_installment_date=2026-04-01`. |
| **L-FLAT-ODD** | LT-FLAT: `principal=100000`, rate `1.5`, term `7`, monthly. |
| **L-RB-1** | LT-RB: `principal=12000`, `down_payment=0`, `interest_rate_percent=1.0`, `rate_period=monthly`, `term_months=3`, monthly. |
| **P-TYPE-1** | `pawning_types`: `normal_rate_percent=3.0`, `special_rate_percent=2.0`, `special_rate_days=10`, `cycle_days=30`, `ltv_percent=80`. |
| **P-1** | Pawning on P-TYPE-1, one article: `net_weight_grams=20.000`, `rate_per_gram=15000`, `principal_amount=200000`, `start_date=2026-03-01`, Cash. |
| **P-2ART** | Pawning on P-TYPE-1: Article A `net=20g @15000`; Article B `net=10g @15000`; `principal_amount=200000`, `start_date=2026-03-01`. |
| **EXP-CAT-1** | `expense_categories`: `chart_of_account_id` → `5010` Operating Expenses. |
| **PCF-1** | `petty_cash_funds`: `float_limit=50000`, `current_balance=50000`, GL leaf `1015-1`, `status=Active`. |
| **FD-M** | Fixed deposit: `principal=500000`, `annual_rate=12`, `term_months=12`, `interest_payment_method=monthly`, `start_date=2026-03-01`, Cash. |
| **FD-T** | as FD-M but `interest_payment_method=maturity`. |
| **BRW-1** | Borrowing: `type=Party` (→ `2200`), `principal_amount=1000000`, `receipt_method=Cash`, `borrowing_date=2026-03-01`. |
| **CLOCK** | All suites run with a frozen clock. Default "today" = `2026-03-01` unless a case says otherwise. |

## Priority definitions

* **P0** — a failure means money is silently mis-stated (wrong account, wrong amount, missing entry, cache≠ledger, lost update). Ship-blocking.
* **P1** — a failure means a wrong figure a human would likely catch, or a workflow/authorisation hole.
* **P2** — edge case, cosmetic, or defence-in-depth.

---

## P0 case counts per file

| File | P0 | P1 | P2 | Total |
|---|---|---|---|---|
| ledger-invariants.md | 12 | 2 | 1 | 15 |
| loans.md | 15 | 9 | 2 | 26 |
| speed-drafts.md | 5 | 2 | 1 | 8 |
| pawning.md | 9 | 7 | 4 | 20 |
| expenses.md | 7 | 7 | 1 | 15 |
| cash-floats.md | 9 | 6 | 2 | 17 |
| fixed-deposits.md | 6 | 3 | 2 | 11 |
| borrowings.md | 5 | 2 | 1 | 8 |
| sales-commission.md | 4 | 2 | 1 | 7 |
| bank-and-cashbook.md | 3 | 3 | 2 | 8 |
| opening-balances-and-manual-journal.md | 5 | 3 | 2 | 10 |
| reconciliation.md | 5 | 4 | 1 | 10 |
| **Total** | **85** | **50** | **20** | **155** |

---

## The 25 to automate first (most money protected)

Ranked. Each maps to a Risk in `ledger-matrix.md`.

| # | Case ID | Why it protects the most money |
|---|---|---|
| 1 | INV-01 | System-wide Σdebit = Σcredit after every scenario — the master guard; one assertion catches most posting bugs |
| 2 | INV-04 | Every operational balance = its ledger counterpart (loan/pawning/till/payable) — catches Risks 1, 3, 8, 9 in one sweep |
| 3 | INV-03 | Every account balance = Σ its journal lines — catches cache-divergence and orphan lines |
| 4 | LOAN-18 | Write-off credits `1100` only for principal actually on the books (Risk 2) — currently fails; guards a systemic overstatement |
| 5 | PWN-11 | Auction sale moves the physical till, not just the GL leaf (Risk 3) — currently fails |
| 6 | LOAN-07 | Bank/Cheque-method loan payment must post a GL entry (Risk 1) — currently fails |
| 7 | PWN-08 | Bank/Cheque redemption must post a GL entry + not release gold with `1120` unrelieved (Risk 1) — currently fails |
| 8 | COMM-02 | Commission payment amount capped to earned amount + routed through FinancePostingService (Risk 4) — currently fails |
| 9 | INV-10 | Concurrency: two simultaneous loan payments — no double posting, no lost `paid_amount` update |
| 10 | CF-02 | Loan/pawning cash receipt lands in the **branch till**, not a generic cash account |
| 11 | INV-08 | 1,000-transaction rounding-drift harness nets to exactly 0.00 |
| 12 | LOAN-02 | Flat schedule: Σ principal = financed amount exactly, Σ interest = rate×term exactly |
| 13 | LOAN-22 | Payment on a closed / zero-balance loan is rejected, never posted |
| 14 | EXP-04 | Petty cash spend: fund cache = `1015-x` leaf, an equality checked nowhere else in production (Risk 5) |
| 15 | INV-11 | Idempotency: replaying the same payment HTTP request produces one set of entries (Risk 6) — currently fails for FD/bank/cashbook |
| 16 | CF-09 | Negative till balance is impossible (`InsufficientFundsException`) |
| 17 | INV-07 | Reversal writes a contra entry; the original entry/lines are byte-for-byte unchanged |
| 18 | LOAN-15 | Early settlement: accrued-to-date interest only; future interest waived, not collected or written to income |
| 19 | PWN-03 | Full redemption clears principal + interest, releases gold, `1120` nets to 0 for the ticket |
| 20 | BANK-01 | Manual bank txn with `Ledger` contra cannot post to equity/retained-earnings without approval (Risk 7) — currently fails |
| 21 | LOAN-27 | Loan principal waiver posts a bad-debt entry; `1100` relieved (Risk 8) — currently fails |
| 22 | CF-06 | Cash session shortage/excess posts to `5310`/`4905` and trues the till to the count |
| 23 | RECON-01 | Full mixed-day simulation: every report reconciles to the ledger |
| 24 | FD-02 | FD monthly-interest schedule: Σ payouts = principal × monthly-rate × term; maturity returns principal only |
| 25 | OB-02 | `ledger:opening-balances` posts the delta to `3015` and never double-counts on re-run |
