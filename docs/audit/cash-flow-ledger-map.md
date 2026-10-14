# Cash In / Cash Out and Ledger Map: full audit

Scope: every place money enters or leaves a cash account, a bank account or the general ledger (GL).
Method: read the posting code (`backend/app/Support/*Poster.php`, `FinancePostingService`, `FinanceRequestService`, `CashSessionService`, the money controllers), then check suspicious paths with tests.
Probe tests: `backend/tests/Feature/CashLedgerAuditProbeTest.php`. Loan lifecycle tests: `LoanLifecycleScenariosTest.php`.
Date of audit: 2026-10-13. Chart of accounts (COA) shown is the one in the local `tazz` database.

---

> **Status update (2026-10-14):** findings 1 to 10 below have all been addressed; see `full-system-audit.md`
> section 2 (C1 purpose tills, C2 bank/cheque posting for FD and savings, C3 opening count note, C4 session gate in the
> money writer, C5 Document charge mapped to 4910, C6 void cannot overdraw, C7 deletes follow approval, C8 till choice
> on direct disbursement, F-10/F-11). Sections 2 and 4 describe the system as it was audited; where they say "lowest id
> till" the resolver now first looks for a till dedicated to the purpose (`cash_accounts.purpose`).

## 1. Summary

The money core is sound. Every cash or bank movement that goes through `FinancePostingService` is atomic (balance, GL entry, `finance_transactions` row and cashbook mirror in one transaction), idempotent, and refuses to overdraw.
The weak points are around the edges: which till is used, which modules bypass the core, and what the cash-session gate covers.

Ranked by impact:

| # | Finding | Status |
|---|---------|--------|
| 1 | Every module's cash goes to ONE till per branch (the lowest-id Active `BranchMain`/`HeadOfficeMain`). Tills named "FD", "Doc charge", "Collection" are never chosen by purpose. | Open, design decision (section 6) |
| 2 | FD, Savings, and Speed Draft/Pawning Bank or Cheque movements are not posted anywhere (no GL, no bank balance). Loan Bank/Cheque receipts only land in 1030 Undeposited Funds. | Open, design decision |
| 3 | Petty-cash top-up could overdraw a till or bank account (wrote `current_balance` directly, no funds check). | **Fixed** |
| 4 | "Open today's cash session" gate accepted a session left open from a previous day. | **Fixed** |
| 5 | Cash session can be opened with a count that differs from the book balance; the difference is never booked. | Open, policy |
| 6 | `require_cash_session` only gates loan collections. Cash disbursement, FD, savings, pawning, speed draft, manual cash entries ignore it. | Open, policy |
| 7 | All charge categories have no income account, so every charge (Document, Legal, Valuation, ...) posts to 4020. Chart has 4910 Documentation Income and 6062 Service Charge unused. | Open, config |
| 8 | Voids/reversals force-allow negative balances, so voiding a collection after the cash was spent leaves a negative till with no warning. | Open |
| 9 | Manual cashbook delete and bank-transaction delete reverse immediately, skipping the `reversal_requires_approval` setting that the Cash Transactions page honours. | Open |
| 10 | Direct loan disbursement cannot choose the till (always branch primary); the Finance-request path can. Same loan, two behaviours. | Open |

Findings 3 and 4 are fixed and covered by tests. Everything else needs a decision from you.

---

## 2. How an account gets chosen

`CashAccount::branchPrimary(branchId)`: first Active account at the branch whose type is `BranchMain`/`HeadOfficeMain` (`PRIMARY_TYPES`), ordered by id; otherwise the first Active account of any type.

Used by: loan payments (cash receipt, doc charge, cash-session gate), loan disbursement (direct), FD (deposit, interest, withdrawal, part payment, loan adjustment), Savings (deposit, withdrawal), Pawning (disbursement, receipt, auction), Speed Draft receipt, Borrowing default.

Not used (caller picks the account): Finance-request execute, transfers, cash floats, petty-cash top-up, manual cashbook entry, bank transactions, Borrowing when a `cash_account_id` is supplied.

Consequence on your Horana branch (HB), which has two `BranchMain` tills:

| id | Name | Used by the system? |
|----|------|---------------------|
| 16 | FD | **Yes, for everything at HB** (lowest id) |
| 17 | Doc charge | **Never** (unless chosen by hand in a transfer, request or manual entry) |

That is why the document-charge payment asked for "today's cash session for FD". Head Office has the same pattern: `Collection` (15) wins over `Cash Float` (18).

---

## 3. Accounts used (COA codes)

| Code | Name | Role |
|------|------|------|
| 1010-x | Cash in Hand: one leaf per till | Physical cash |
| 1020-x | Bank: one leaf per bank account | Bank balance |
| 1015-x | Petty Cash Floats: one leaf per fund | Petty cash |
| 1030 | Cheques / Undeposited Funds | Bank-method and cheque loan, speed-draft and pawning receipts, until deposited |
| 1100 | Loan Principal Outstanding | Loan capital |
| 1110 | Speed Draft Capital Outstanding | Speed draft capital |
| 1120 | Pawning Capital Outstanding | Pawning capital |
| 1190 | Provision for Loan Losses (contra) | Impairment |
| 2010 | Customer Fixed Deposits Payable | FD principal owed |
| 2050 | Customer Savings Deposits Payable | Savings owed |
| 2100 | Unapplied Loan Receipts | Pending settlement / manual allocation |
| 2110 | Pawning Auction Surplus Payable | Auction surplus owed to customer |
| 2130 | Re-Pledged Pawning Liability | Re-pledge borrowing |
| 2200 | Borrowings: Party / Institutional | Company borrowings |
| 2210 | Bank Overdrafts | Overdraft borrowing |
| 2300 | Accrued Expenses | Expense recorded, not yet paid |
| 3015 | Opening Balance Equity | Opening balances |
| 4010 | Interest Income, Loans | Loan interest |
| 4015 | Interest Income, Speed Drafts | |
| 4020 | Loan Charges / Fees | All loan charges (category not mapped) |
| 4025 | Interest Income, Pawning | |
| 4030 | Penalty Income | Loan and speed draft penalty |
| 4045 | Savings Account Fee Income | |
| 4050 | Bad Debt Recovery Income | Provision release |
| 4900 | Other Income | Forfeited auction surplus |
| 4905 | Cash Overage | Session or float excess |
| 5100 | Interest Expense, FDs | |
| 5110 | Interest Expense, Borrowings | |
| 5120 | Interest Expense, Savings | |
| 5300 | Provision for Loan Losses (expense) | Provision, write-off, auction shortfall |
| 5310 | Cash Shortage | Session or float shortage |

---

## 4. Flow by flow

"Till" = the branch primary till (section 2) unless stated. All cash rows run through `FinancePostingService`: balance moves, finance transaction and cashbook row are written, funds check applies, replays are idempotent.

### 4.1 Loans

| Event | Method | Debit | Credit | Physical balance | Reversal |
|-------|--------|-------|--------|------------------|----------|
| Disburse (direct) | Cash | 1100 | Till leaf | Till, minus | `reverseDisbursement` on cancel |
| Disburse (direct) | Bank/Cheque | 1100 | Chosen bank leaf | Bank, minus | same |
| Disburse (Finance request) | Cash/Bank | 1100 (offset) | Till or bank the executor picks | That account, minus | via request reversal |
| Payment | Cash | Till leaf | 1100 (principal), 4010 (interest), 4030 (penalty), charge-category account or 4020 (charges) | Till, plus | `reverseAllFor` on void |
| Payment | Bank | **1030** | same split | **None** (no bank account is captured) | void reverses the GL entry |
| Payment | Cheque | nothing until realized, then 1030 | same split | None | on bounce |
| Settlement / manual allocation (pending) | Cash/Bank | Till leaf or 1030 | 2100 | Till, plus (cash) | reject reverses |
| Settlement approved | | 2100 | split | none | |
| Document charge receipt | any | same as Payment | usually 4020 | same as Payment | |
| Write-off | none | 1190 and/or 5300 | 1100 | none | |
| Provision build / release | none | 5300 / 1190 | 1190 / 4050 | none | |
| Reschedule rollover | none | 1100 | 4010 / 4030 / 4020 | none | |
| FD adjusted against loan | | 2010 (+5100) | allocated split (+ till for any excess) | Till for the excess only | |

Cash-session gate: loan **cash** payments only, against the branch primary till.

### 4.2 Fixed deposits (FD) and savings

| Event | Method | Debit | Credit | Physical |
|-------|--------|-------|--------|----------|
| FD deposit received | Cash | Till | 2010 | Till plus |
| FD interest paid | Cash | 5100 | Till | Till minus |
| FD withdrawal | Cash | 2010 (+5100 for maturity interest) | Till | Till minus |
| FD part payment | Cash | 2010 + 5100 | Till | Till minus |
| Savings deposit | Cash | Till | 2050 | Till plus |
| Savings withdrawal | Cash | 2050 | Till | Till minus |
| Savings fee | none | 2050 | 4045 | none |
| Savings interest run | none | 5120 | 2050 | none |
| Any of the above | **Bank/Cheque** | **not posted** | **not posted** | **none** |

### 4.3 Pawning and speed drafts

| Event | Debit | Credit | Physical |
|-------|-------|--------|----------|
| Pawning disbursement (cash only) | 1120 | Till | Till minus |
| Pawning receipt, cash | Till | 4025 (interest), 1120 (capital) | Till plus |
| Pawning receipt, other | 1030 | same | none |
| Auction sale | Till | 1120, 4025, 2110 (surplus); 5300 for any shortfall | Till plus |
| Surplus refund | 2110 | Till | Till minus |
| Surplus forfeited | 2110 | 4900 | none |
| Speed draft disburse | 1110 | Till or bank leaf | that account minus |
| Speed draft receipt, cash | Till | 4015, 4030, 1110 | Till plus |
| Speed draft receipt, other | 1030 | same | none |

### 4.4 Company side: expenses, petty cash, borrowings, transfers

| Event | Debit | Credit | Physical |
|-------|-------|--------|----------|
| Expense recorded | Category expense account | 2300 | none |
| Expense paid (Finance request) | 2300 | Till or bank | That account minus |
| Payment voucher | per request | Till or bank | minus |
| Petty-cash top-up | Fund leaf 1015-x | Till or bank leaf | Source minus (**now funds-checked**) |
| Petty-cash spend | Category expense account | Fund leaf | Fund balance only |
| Borrowing drawdown | Till | 2200 / 2210 / 2130 | Till plus |
| Borrowing interest | 5110 | Till | Till minus |
| Borrowing repayment | 2200 / 2210 / 2130 | Till | Till minus |
| Transfer (one step) | Destination leaf | Source leaf | both move |
| Transfer (cross branch) | In-transit leaf, then destination | Source, then in-transit | in-transit holds the cash |
| Float issue / return | Float till | Home till | both move |
| Float / session settlement | 5310 shortage or till | till or 4905 overage | till trued to counted |
| Manual cashbook entry | Till (In) or offset account (Out) | offset account (In) or till (Out) | Till |
| Bank transaction | Bank leaf or contra | contra or bank leaf | Bank (and contra till or bank) |
| Opening balance | Till or bank leaf | 3015 | none (opening balance already on the account) |

Funding a till: use Cash Transactions, then a manual Cash In with an offset account (for example 3015 or 3010 for owner capital, or a bank leaf). Or a Transfer from a bank account or another till.

### 4.5 Sessions and reconciliation

Open (count taken), Close (count and denominations, variance = counted minus `current_balance`), Verify by another user (posts 5310 or 4905 and trues the balance). Sessions never move money themselves.
Cash and bank reconciliation screens, and the Ledger Drift check (`finance:check-drift`), compare `current_balance` against the GL leaf.

---

## 5. Findings in detail

### F1 One till per branch
Every module uses `branchPrimary()`. A branch with several tills (FD, Doc charge, Collection, ...) puts all cash in the lowest-id one, so the "Doc charge" and "Cash Float" tills stay empty, the cash-session gate asks for the wrong till, and a disbursement can fail because the "wrong" till is short. Confirmed by test AUD-01.

### F2 Bank and cheque movements
FD, Savings, Pawning (disburse) post nothing for Bank/Cheque. Loan, Speed Draft and Pawning receipts post Dr 1030 with no bank-account balance change, so 1030 only clears if someone later does a Bank Deposit with contra "Ledger 1030". There is no bank-account link on a plain receipt. Confirmed by AUD-06.

### F3 Petty-cash top-up overdraw (fixed)
`PettyCashReplenishmentController::store` subtracted the till or bank balance directly with no funds check and no `finance_transactions` row. A 20,000 top-up from a 1,000 till succeeded and left the till at -19,000. Now rejects with the same insufficient-funds error as other flows. Test AUD-05. Still missing there: finance-transaction row and cashbook mirror (the top-up is invisible in Cash Transactions).

### F4 Stale session (fixed)
`CashSessionService::openFor` returns any Open session. A session left open yesterday satisfied the gate. The gate now requires `business_date` = today. Test AUD-03.

### F5 Opening count not reconciled
`CashSessionService::open` records `opening_amount` (counted) and `opening_expected` (book) but never compares them. Your FD session opened with 20,000 while the till book balance was 0.00, so the ledger holds no such cash and the later disbursement fails. Test AUD-04 (marked incomplete: policy).

### F6 Gate scope
`require_cash_session` is read in one place, `LoanPaymentController::store`. Test AUD-07 (incomplete: policy).

### F7 Charge income accounts
`charge_categories.chart_of_account_id` is null for all 8 categories. Test AUD-02 confirms Document charge posts to 4020.

### F8, F9, F10
See table. F8: `FinancePostingService::reverse` applies inverse movements with `allowNegativeOverride`. F9: `CashbookEntryController::destroy` and `BankTransactionController::destroy` call `reverse()` directly. F10: `LoanLedgerPoster::disbursementTarget` vs `FinanceRequestService::execute`.

---

## 6. Decisions needed (recommendations first)

1. **Purpose tills (F1).** Add a `purpose` to cash accounts (Collection, Doc charge, FD, Savings, Pawning, Disbursement, General) and make `branchPrimary($branch, $purpose)` pick the matching till, falling back to today's behaviour. Doc-charge receipts would then use the "Doc charge" till, FD deposits the "FD" till, and so on, and the session gate would check the same till.
2. **Charge income accounts (F7).** Map Document to 4910 Documentation Income and Service Charges to 6062 (note 6062 is an Income account under an Expense-looking code) in Masters > Charge Categories. No code change needed.
3. **Session opening (F5).** Refuse, or auto-post the difference as Cash In / overage.
4. **Gate scope (F6).** Extend to cash disbursement, FD, savings and pawning cash movements, or leave collections only.
5. **Bank/Cheque for FD, Savings, Pawning (F2).** Capture a bank account on those transactions and post them like loan disbursement does.
6. **Reversal approval (F9)** and **negative on void (F8).** Route manual cashbook and bank deletes through the same approval as Cash Transactions; warn when a void would take a till below zero.

Not audited in depth: payroll and commission payout, FD renewal rollover, cheque realization UI, the reconciliation screens, report formulas.
