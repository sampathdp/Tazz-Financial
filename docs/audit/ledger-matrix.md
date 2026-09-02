# Ledger Posting Matrix & Financial-Correctness Audit

**Scope:** every operation in the codebase that creates, changes, or reverses money, and the
general-ledger (GL) entry it produces. Read-only audit — no code was changed.

**Method:** discovered by scanning `backend/app` (controllers, `app/Support/*` services,
`app/Console/Commands`, `app/Listeners`, models, migrations). Every claim below carries a
`file:line` reference into `backend/`.

---

## 0. How posting works in this system

Two "single writer" services sit under everything:

| Writer | File | Owns | Opens its own DB transaction? |
|---|---|---|---|
| `LedgerPoster::post()` | `app/Support/LedgerPoster.php:27` | inserting `journal_entries` + `journal_lines` | **No** — must be called inside the caller's transaction (`LedgerPoster.php:18`) |
| `FinancePostingService::execute()` | `app/Support/FinancePostingService.php:66` | moving `cash_accounts` / `bank_accounts.current_balance`, writing `finance_transactions`, mirroring `cashbook_entries`, and calling `LedgerPoster` | **Yes** — `DB::transaction()` at `FinancePostingService.php:70` |

Structural guarantees that **are** enforced:

* `LedgerPoster::post()` rejects an unbalanced entry — `bccomp($totalDebit,$totalCredit,2)!==0` throws (`LedgerPoster.php:39`). It also requires ≥2 lines (`LedgerPoster.php:29`).
* DB CHECK constraint `chk_journal_lines_one_side` forces every line to be strictly one-sided and non-zero: `(debit=0 AND credit>0) OR (debit>0 AND credit=0)` (`database/migrations/2026_08_01_000007_create_journal_entries_and_journal_lines_tables.php:68`).
* `finance_transactions.idempotency_key` is `UNIQUE` (`database/migrations/2026_09_01_000004_create_finance_transactions_table.php:55`); `finance_transactions.amount > 0` CHECK (`:85`).
* All account balances in every report are derived from `journal_lines` — there is no cached GL balance column (`ChartOfAccount::balancesAsOf()`, `app/Models/ChartOfAccount.php:135`).
* Posted `journal_entries` are reversed, never edited/deleted, by `LedgerPoster::reverse()` (`LedgerPoster.php:77`) and `FinancePostingService::reverse()` (`FinancePostingService.php:203`).

Guarantees that are **NOT** enforced anywhere:

* **No period-close / lock-date mechanism exists.** No migration, model, or guard. Any `entry_date` / `paid_at` / `expense_date` the caller sends is accepted, including far past or future.
* **Cache vs GL agreement is not enforced at write time** — only re-asserted next morning by `finance:check-drift` (`app/Console/Commands/CheckLedgerDrift.php:33`, scheduled `Kernel.php:20`), and that check covers **only `cash_accounts` and `bank_accounts`** (`CheckLedgerDrift.php:39-44`) — not `petty_cash_funds`.
* **No HTTP-level idempotency** on payment-creation endpoints — see Risk #6.
* Several flows write to operational tables and post **nothing** to the GL — see Risk #1.

### Where accounts are configured in the database rather than hardcoded

| Config | Table / column | Used by |
|---|---|---|
| Per-till GL leaf | `cash_accounts.chart_of_account_id` → child of `1010` (created lazily) | `ChartOfAccount::forCashTill()` `app/Models/ChartOfAccount.php:57` |
| Per-bank GL leaf | `bank_accounts.chart_of_account_id` → child of `1020` | `ChartOfAccount::forBankAccount()` `ChartOfAccount.php:78` |
| Per-petty-cash-fund GL leaf | `petty_cash_funds.chart_of_account_id` → child of `1015` | `ChartOfAccount::forPettyCashFund()` `ChartOfAccount.php:99` |
| Expense category → expense account | `expense_categories.chart_of_account_id` | `ExpensePaymentExecutor.php:37`, `PettyCashVoucherController.php:56` |
| Loan/SD charge category → income account | `charge_categories.chart_of_account_id` (falls back to `4020`) | `LoanLedgerPoster::splitLines()` `app/Support/LoanLedgerPoster.php:449` |
| Manual-JE / bank-txn / cashbook offset | operator picks any `chart_of_accounts.id` at request time | `ManualJournalEntryController.php:42`, `BankTransactionController.php:63`, `CashbookEntryController.php:47` |
| Borrowing liability account | hardcoded by `borrowings.type` → `2200/2210/2130` | `BorrowingLedgerPoster::liabilityAccount()` `BorrowingLedgerPoster.php:34` |
| Reversal needs approval? | `finance_settings` key `reversal_requires_approval` | `FinanceTransactionController.php:77` |
| Approval routing / tiers | `approval_types`, `approval_tiers` | `ApprovalRouter.php`, `FinanceRequestService::submit()` `FinanceRequestService.php:97` |

### Control accounts (seeded chart of accounts)

`1010` Cash in Hand · `1015` Petty Cash Floats · `1020` Bank Accounts · `1030` Cheques/Undeposited Funds ·
`1100` Loan Principal Outstanding · `1110` Speed Draft Capital Outstanding · `1120` Pawning Capital Outstanding ·
`1150` Interest Receivable *(unused)* · `1190` Provision for Loan Losses (contra-asset, Credit-normal) ·
`2010` Customer FDs Payable · `2020` Interest Payable on FDs *(unused)* · `2100` Unapplied Loan Receipts (suspense) ·
`2110` Pawning Auction Surplus Payable · `2130` Re-Pledged Pawning Liability · `2200` Borrowings – Party ·
`2210` Bank Overdrafts · `3015` Opening Balance Equity · `3020` Retained Earnings ·
`4010` Interest Income – Loans · `4015` Interest Income – Speed Drafts · `4020` Loan Charges/Fees ·
`4025` Interest Income – Pawning · `4030` Penalty Income · `4050` Bad Debt Recovery Income ·
`4900` Other Income · `4905` Cash Overage · `5100` Interest Expense – FDs · `5110` Interest Expense – Borrowings ·
`5200` Staff Salaries & Commission · `5300` Provision for Loan Losses (expense) · `5310` Cash Shortage.
(Seed: `database/migrations/2026_08_01_000012_seed_chart_of_accounts.php` and follow-on `*_to_chart_of_accounts.php` migrations.)

---

## 1. Transaction inventory

**68 distinct money-movement operations were found**, across 12 modules. Operations that only
change workflow state (application, approval, valuation) are listed where relevant but marked
*no GL*.

### Loans (`app/Http/Controllers/Api/LoanController.php`, `LoanPaymentController.php`, `app/Support/LoanLedgerPoster.php`, `LoanPaymentAllocator.php`)

| # | Transaction | Business meaning | Trigger | GL written at | In DB txn? | Reversible? |
|---|---|---|---|---|---|---|
|1|Loan application|create pending facility|`POST /loans` `LoanController.php:249`|— *no GL*|yes|delete while Pending|
|2|Loan approval (maker-checker)|authorise disbursement|`LoanController.php:574`/`:614`|— *no GL*|yes|cancel request|
|3|Disbursement — direct|capital to customer|`disburse()` `LoanController.php:780`|`LoanLedgerPoster::postDisbursement` `:46` → `FinancePostingService::execute` `:61`|**yes** `LoanController.php:788`|contra via `reverseDisbursement()` `LoanLedgerPoster.php:265`|
|4|Disbursement — request path|as above, via Finance queue|`requestDisbursement()` `:803` → `FinanceRequestService::execute` `FinanceRequestService.php:236` → listener `CompleteDisbursementOnSettled.php:26`|`FinancePostingService::execute` inside `FinanceRequestService.php:290`|**yes** (`FinanceRequestService.php:287`) but loan-side completion is a **separate** txn (`CompleteDisbursementOnSettled.php:59`)|contra|
|5|Interest accrual|recognise earned interest|— none. Cash basis. `AccrualInterestCalculator` is **report-only** (`app/Support/AccrualInterestCalculator.php:13`)|n/a|n/a|n/a|
|6|Instalment payment — Cash|collect due amount|`LoanPaymentController::store` `:153`|`LoanLedgerPoster::postCashReceipt` `:297` → `postCollection` `:326` (COLLECTION)|**yes** `LoanPaymentController.php:153` (poster inside closure `:210`)|contra `reverseAllFor` `LoanLedgerPoster.php:407`|
|7|Instalment payment — Bank/Cheque|collect due amount|`LoanPaymentController::store` `:153`|**Cheque:** `1030` on realization only (`postChequeRealized` `LoanLedgerPoster.php:387`). **Bank: nothing** (`LoanLedgerPoster.php:299` early-returns)|yes|contra (Cheque) / n/a (Bank)|
|8|Cheque realization (manual)|cheque cleared at bank|`clearCheque()` `LoanPaymentController.php:312`|`postChequeRealized` `:336` — Dr `1030` / Cr split|**yes** `:333`|contra|
|9|Post-dated cheque realization|scheduled PDC deposit clears|`LoanPostDatedChequeController.php:208`|`postChequeRealized` `:236`|**yes** `:208`|contra|
|10|Penalty / late fee ("OD")|overdue interest charge|priced in `LoanSummary::penaltyDueForRow`, **collected inside a payment** → Cr `4030` (`LoanLedgerPoster::splitLines` `:441`)|with the payment|as payment|
|11|Partial payment|under-full instalment|`LoanPaymentAllocator::applyAllocation` `:286` waterfall|with the payment|as payment|
|12|Manual allocation payment|cashier chooses buckets|`LoanPaymentController::approveAllocationRequest` `:526` → `applyManualAllocation` `:402` → `postManualAllocationApplied` `LoanLedgerPoster.php:372`|**yes** `:526`|contra|
|13|Early settlement|pay off, future interest waived|`approveSettlement()` `LoanPaymentController.php:251`; amount **recomputed server-side** `settlementQuote()` `:126`,`:246`|`applySettlementWaiver` `:262` + `postSettlementApplied` `:264` (Dr `2100` suspense / Cr split)|**yes** `:251`|contra `reverseAllFor`|
|14|Pending settlement / manual cash receipt|cash held pending approval|`postCashReceipt` suspense branch `LoanLedgerPoster.php:310` — Cr `2100`|with `store()`|contra|
|15|Payment void / reject / bounce|undo a payment|`LoanPaymentController::destroy` `:287`, `rejectSettlement` `:276`, void-approval `:410`|`reverseAllFor` `LoanLedgerPoster.php:407` (FPS reverse + GL reverse)|**yes**|self (this *is* the reversal)|
|16|Restructure / reschedule|roll old loan into new -R loan|`reschedule()` `LoanController.php:1103`|`postRescheduleRollover` `LoanLedgerPoster.php:218` — Dr `1100` / Cr `4010`,`4030`,`4020` (earned amounts only)|**yes** `:1103`|no dedicated reversal (admin `purge` only)|
|17|Write-off|charge uncollectable loan to P&L|`approveWriteOffRequest()` `LoanController.php:900`; amount = `outstanding_balance_cache` `:938`|`postWriteOff` `LoanLedgerPoster.php:131` — Dr `1190` (up to provision) + Dr `5300` (remainder) / **Cr `1100` for the FULL amount** `:146`|**yes** `:927`|contra via `LedgerPoster::reverse` on the entry|
|18|Impairment provision build-up / release|CBSL NPL provisioning|`LoanProvisioning::post()` `app/Support/LoanProvisioning.php:75` (manual; daily `loans:recompute-provisioning` only refreshes the *cache*, `RecomputeLoanProvisioning.php:29`)|`postProvisionAdjustment` `LoanLedgerPoster.php:169` — build: Dr `5300`/Cr `1190`; release: Dr `1190`/Cr `4050`|**yes** `LoanProvisioning.php:77`|next adjustment nets it|
|19|Loan closure|mark fully paid|`LoanPaymentAllocator::finalizeApplied` `:520` — status only|— *no GL*|as payment|reopen only by voiding payments|
|20|Loan cancel (after disbursement)|unwind an activated loan|`approveCancelRequest()` `LoanController.php:691`|`reverseDisbursement` `:705` → `FinancePostingService::reverse` `LoanLedgerPoster.php:274`|**yes** `:691`|self|
|21|Loan waiver (Interest/Penalty/Charge/**Principal**)|forgive part of the debt|`LoanWaiverController::store` `:20`|— **no GL for any type** (incl. Principal). Effect only via `LoanPaymentAllocator::waivedByRow` `:105`|no txn, single insert|`destroy()` **hard-deletes** `LoanWaiverController.php:41`|
|22|Loan purge (admin, temporary)|hard-delete a mis-keyed loan|`purge()` `LoanController.php:387`|**deletes** `journal_entries`/`journal_lines` `:398-435`; walks balances back with `DB::raw('current_balance + '.$signed)` `:413`|**yes** `:403` (with `SET FOREIGN_KEY_CHECKS=0` `:404`)|irreversible|

### Speed Drafts (`SpeedDraftController.php`, `SpeedDraftPaymentController.php`, `app/Support/SpeedDraftLedgerPoster.php`) — parallels loans; `1110` / `4015` / `4030`

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|23|SD disbursement (Cash/Bank/Cheque)|`SpeedDraftController::disburse` `:312`|`postDisbursement` `SpeedDraftLedgerPoster.php:33` — Dr `1110` / Cr till or bank|**yes** `:312`|contra|
|24|SD payment — Cash|`SpeedDraftPaymentController::store` `:89`|`postCashReceipt` `:81` — Dr till / Cr `4015`,`4030`,`1110`|**yes** `:89`|`reverseAllFor` `:176`|
|25|SD payment — Bank/Cheque|`:89`|**nothing** (`SpeedDraftLedgerPoster.php:83` early-returns)|yes|n/a|
|26|SD renewal — capitalised interest/penalty|`SpeedDraftController::renew` `:421`|`postCapitalizedRenewalIncome` `SpeedDraftLedgerPoster.php:148` — Dr `1110` / Cr `4015`,`4030` (non-cash)|**yes** `:421`|no dedicated reversal|
|27|SD payment void|`SpeedDraftPaymentController::destroy` `:146`|`reverseAllFor` `:176`|**yes**|self|

### Pawning (`PawningController.php`, `PawningAuctionController.php`, `app/Support/PawningLedgerPoster.php`, `PawningInterestCalculator.php`)

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|28|Article intake + valuation|`PawningController::store` `:141-168`|— *no GL* (collateral + jewellery-detail rows only)|**yes** `:106`|—|
|29|Advance payout (disbursement)|`store()` `:106`|`postDisbursement` `PawningLedgerPoster.php:41` — Dr `1120` / Cr branch till (**Cash only, always**)|**yes** `:106` (poster inside `:183`)|contra `reverseAllFor` is payment-only; disbursement not individually reversible except `purge`|
|30|Interest accrual|— none. Flat per-cycle % computed on demand (`PawningInterestCalculator::quote` `:23`)|n/a|n/a|n/a|
|31|Renewal / rollover — Cash|`renew()` `PawningController.php:198`; interest = `PawningInterestCalculator::quoteFull` `:217`|`postCashReceipt` `PawningLedgerPoster.php:78` — Dr till / Cr `4025`,`1120`|**yes** `:223`|`reverseAllFor` `:239`|
|32|Partial redemption — Cash|`redeem()` type Partial `:300`; capital split by `max_loan_value` share (`quoteForArticles` `:57`)|`postCashReceipt` `:391`|**yes** `:341`|`reverseAllFor`|
|33|Full redemption — Cash|`redeem()` type Full `:300`|`postCashReceipt` `:391`|**yes** `:341`|`reverseAllFor`|
|34|Renewal / redemption — Bank/Cheque|`renew()`/`redeem()` accept `Bank`/`Cheque` (`PawningController.php:205`,`:309`)|**nothing** (`PawningLedgerPoster.php:80` early-returns) — ticket still closes, articles still released|yes|n/a|
|35|Auction batch listing|`PawningAuctionController::addItems` `:120` — snapshots `outstanding_capital`/`_interest`|— *no GL*|**yes** `:120`|—|
|36|Auction sale|`recordSale()` `PawningAuctionController.php:159`; `hammer_price` from client|`postAuctionSale` `PawningLedgerPoster.php:138` via **`LedgerPoster::post` directly** `:177` — Dr till-leaf + Dr `1190` (shortfall) / Cr `1120` + Cr `4025` + Cr `2110` (surplus). **Physical till balance NOT moved.**|**yes** `:170`|no reversal path ("auctions are final", `PawningLedgerPoster.php:26`)|
|37|Auction loss on shortfall|within #36 — Dr `1190` `PawningLedgerPoster.php:164`|with the sale|—|
|38|Surplus refund to customer|`settleSurplus()` `PawningAuctionController.php:217`|`postSurplusRefund` `PawningLedgerPoster.php:187` via `LedgerPoster::post` — Dr `2110` / Cr till-leaf. **Physical till balance NOT moved.**|**yes** `:226`|no|
|39|Surplus forfeiture (unclaimed)|`forfeitSurplus()` `:235`|`postSurplusForfeiture` `PawningLedgerPoster.php:215` — Dr `2110` / Cr `4900`|**yes** `:244`|no|
|40|Payment void|`voidPayment()` `PawningController.php:411`|`reverseAllFor` `:457`|**yes** `:426`|self|

### Expenses & payables (`ExpenseController.php`, `app/Support/ExpensePaymentExecutor.php`, `PaymentVoucherController.php`)

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|41|Expense entry|`ExpenseController::store` `:32`|— *no GL* (status Pending + approval request)|**yes** `:45`|reject|
|42|Expense approval (maker-checker)|`approve()` `:73`|— *no GL*|**yes** `:92`|—|
|43|Expense payment — direct|`pay()` `:162` → `ExpensePaymentExecutor::pay` `:31`|`FinancePostingService::execute` `:49` — Dr `expense_categories.chart_of_account_id` / Cr till or bank leaf. Idempotency `expense-payment-{id}` `:65`|**yes** `ExpensePaymentExecutor.php:46`|`PaymentVoucher` void → FPS reverse|
|44|Expense payment — request path|`requestPayment()` `:180` → `FinanceRequestService` → `CompleteExpensePaymentOnSettled` listener|`FinanceRequestService::execute` `:236` (offset = category account, from `meta` `ExpenseController.php:211`)|**yes** `FinanceRequestService.php:287`; voucher written in a separate listener txn|contra|
|45|Payment voucher void|`PaymentVoucherController::void` `:42`|`FinancePostingService::reverse` `:47` (or legacy `LedgerPoster::reverse` `:58`)|**yes** `:42`|self|
|46|Tax / withholding split|**DOES NOT EXIST** — expense payment posts the gross `expense.amount` with no WHT/VAT leg (`ExpensePaymentExecutor.php`). Confirmed by `EXP-07`; deferred as a feature request — see `failures.md` F-13.|n/a|n/a|n/a|
|47|Reimbursement|no dedicated flow — handled as an ordinary expense (#41–43)|—|—|—|

### Petty cash (`PettyCashVoucherController.php`, `PettyCashReplenishmentController.php`)

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|48|Petty cash spend|`PettyCashVoucherController::store` `:37`|`LedgerPoster::post` `:81` — Dr category account / Cr `1015-{fund}` leaf. **Fund balance moved by hand** (`bcsub` `:65`), **not** via FPS. **No idempotency key.**|**yes** `:60`|`destroy()` `:99` → `LedgerPoster::reverse` + manual `bcadd` `:108`|
|49|Petty cash replenishment / top-up|`PettyCashReplenishmentController::store` `:38`|`LedgerPoster::post` `:88` — Dr `1015-{fund}` / Cr till or bank leaf. **Source + fund balances moved by hand** `:68`,`:72`. **No idempotency key.**|**yes** `:57`|`destroy()` `:112`|
|50|Petty cash voucher void|`destroy()` `PettyCashVoucherController.php:99`|`LedgerPoster::reverse` `:111`|**yes**|self|
|51|Petty cash replenishment void|`destroy()` `PettyCashReplenishmentController.php:112`|`LedgerPoster::reverse` `:137`|**yes**|self|

### Cash floats, tills, sessions, transfers (`app/Support/CashFloatService.php`, `CashSessionService.php`, `FinanceRequestService.php`, `CashbookEntryController.php`)

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|52|Float issue / top-up|`CashFloatService::issue` `:29` → `FinanceRequestService::open` (`CashFloat`) `:72`; executed as a transfer|`executeTransfer` `FinanceRequestService.php:337` — Dr float leaf / Cr source till leaf|**yes** `:352`|`FinanceTransaction` reverse|
|53|Float settlement — return remainder|`CashFloatService::settle` `:120`|`FLOAT_RETURN` transfer `:183` — Dr home-till leaf / Cr float leaf|**yes** `:140`|FT reverse|
|54|Float shortage|`settle()` `:154`|`ADJUSTMENT` `:155` — Dr `5310` / Cr float leaf|**yes** `:140`|FT reverse|
|55|Float excess|`settle()` `:166`|`ADJUSTMENT` `:167` — Dr float leaf / Cr `4905`|**yes** `:140`|FT reverse|
|56|Cash session open (opening count)|`CashSessionService::open` `:22`|— *no GL*, no account lock|single insert|delete|
|57|Cash session close (closing count + denominations)|`close()` `:50`|— *no GL*|single update|reopen|
|58|Cash session shortage (on verify)|`verify()` `:80`|`ADJUSTMENT` `:96` — Dr `5310` / Cr till leaf; balance trued to count `:121`|**yes** `:89`|FT reverse|
|59|Cash session excess (on verify)|`verify()` `:80`|`ADJUSTMENT` `:109` — Dr till leaf / Cr `4905`|**yes** `:89`|FT reverse|
|60|Transfer (till↔till, till↔bank) — one step|`FinanceRequestService::executeTransfer` `:337`|Dr dest leaf / Cr source leaf|**yes** `:352`|FT reverse|
|61|Cross-branch transfer — release|`release()` `:394`|`TRANSFER` `:410` — Dr branch cash-in-transit leaf / Cr source leaf; status `InTransit`|**yes** `:409`|`recall()` `:498`|
|62|Cross-branch transfer — receive|`receive()` `:448`|`TRANSFER` `:463` — Dr dest leaf / Cr in-transit leaf|**yes** `:462`|FT reverse|
|63|Cross-branch transfer — recall|`recall()` `:498`|`FinancePostingService::reverse` `:510`|**yes** `:509`|self|
|64|Manual cashbook adjustment|`CashbookEntryController::store` `:38`|`FinancePostingService::execute` `ADJUSTMENT` `:66` — Dr/Cr between till leaf and **any operator-chosen `offset_account_id`** `:47`. No approval, no idempotency, `entry_date` free.|**yes** (FPS internal)|`destroy()` `:86` → FPS reverse|
|65|Manual cashbook void|`destroy()` `CashbookEntryController.php:86`|FPS reverse `:93` (or legacy manual walk-back `:101`)|**yes**|self|

### Bank (`BankAccountController.php`, `BankTransactionController.php`)

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|66|Bank account opening balance|`BankAccountController::store` `:26`|`OpeningBalanceLedgerPoster::postForBankAccount` `app/Support/OpeningBalanceLedgerPoster.php:34` — Dr bank leaf / Cr `3015` (no-op if zero)|**yes** `:26`|`LedgerPoster::reverse`|
|67|Bank deposit / withdrawal / transfer (manual)|`BankTransactionController::store` `:50`|`FinancePostingService::execute` `:88` — bank leaf vs contra (`Cash`/`Bank`/**`Ledger`=arbitrary account** `:63`). **No idempotency key.** `status:'Pending'` still moves the balance `:59`,`:105`.|**yes** `:87`|`destroy()` `:155` → FPS reverse (legacy manual `:169`)|
|68|Bank transaction delete|`BankTransactionController::destroy` `:155`|FPS reverse `:161`|**yes**|self|
| — |Bank / cash reconciliation|`BankReconciliationController`, `CashReconciliationController`|— *no GL* — compares `current_balance` to a statement, marks matched (`BankReconciliationController.php:37`)|—|—|

### Fixed Deposits (`FdDepositController.php`, `FdInterestPaymentController.php`, `FixedDepositController.php`, `app/Support/FdLedgerPoster.php`)

| # | Transaction | Trigger | GL (Cash method only) | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|69|FD deposit received|`FdDepositController::store` `:58`|`postDepositReceived` `FdLedgerPoster.php:41` — Dr till / Cr `2010`. Idempotency `fd-deposit-{id}` `:63`|**yes** `:58`|`destroy()` `:84` → `reverseDepositReceived` `:70`|
|70|FD interest instalment paid|`FdInterestPaymentController` `:96`|`postInterestPaid` `FdLedgerPoster.php:76` — Dr `5100` / Cr till|**yes** `:96`|`void()` `:124` → `reverseInterestPaid` `:148`|
|71|FD interest accrual|— none. `2020` Interest Payable on FDs is **seeded but never posted to**|n/a|n/a|n/a|
|72|FD maturity / early closure withdrawal|`FixedDepositController::withdraw` `:235`|`postWithdrawal` `FdLedgerPoster.php:106` — Dr `2010` (+ Dr `5100` for a maturity-method FD's accrued interest `:123`) / Cr till|**yes** `:235`|`voidWithdrawal()` `:286`|
|73|FD premature part-payment|`partPayment()` `FixedDepositController.php:338`; SL premature-rate maths server-side|`postPartPayment` `FdLedgerPoster.php:160` — Dr `2010` (+ Dr `5100` premature interest, − Cr `5100` clawback) / Cr till|**yes** `:338`|`voidPartPayment()` `:414`|
|74|FD renewal (principal rollover)|`renew()` `FixedDepositController.php:138`|— **not posted** (no cash moves; `FdLedgerPoster.php:33`). Only a capital-*enhancement* top-up posts, as an ordinary deposit `:186`|**yes** `:138`|—|
|75|FD Bank/Cheque legs (any of #69–73)|as above|**nothing** (every `FdLedgerPoster` method early-returns on non-Cash)|yes|n/a|

### Borrowings — company-side liabilities (`BorrowingController.php`, `app/Support/BorrowingLedgerPoster.php`)

| # | Transaction | Trigger | GL (Cash method only) | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|76|Borrowing drawdown (initial)|`BorrowingController::store` `:126`|`postDrawdown` `BorrowingLedgerPoster.php:53` — Dr till / Cr `2200`/`2210`/`2130`|**yes** `:126`|`reverseDrawdown` `:81`|
|77|Borrowing additional drawdown|`storeTransaction()` kind `Drawdown` `:257`|`postTransaction` `BorrowingLedgerPoster.php:100` — Dr till / Cr liability|**yes** `:257`|`reverseTransaction` `:128`|
|78|Borrowing interest payment|kind `InterestPayment` `:257`|Dr `5110` / Cr till `BorrowingLedgerPoster.php:105`|**yes** `:257`|`reverseTransaction`|
|79|Borrowing principal repayment / settlement|kind default `:257`|Dr liability / Cr till `BorrowingLedgerPoster.php:109`|**yes**|`reverseTransaction`|
|80|Borrowing transaction / drawdown void|`destroy()` `:300` / `:319`|`reverseTransaction` / `reverseDrawdown`|**yes**|self|
|81|Borrowing renewal|`renew()` `:209`|— *no GL* (roll dates only; interest to be recorded separately)|**yes**|—|
|82|Overdraft facility registration + Bank-method borrowing|`store()` isOverdraft `:126`|— **nothing** (`BorrowingLedgerPoster.php:55` requires `receipt_method==='Cash'`); OD limit set via raw `BankAccount::update` `:161`|yes|—|

### Sales-executive commission (`SalesExecutiveCommissionController.php`)

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|83|Commission accrual|created at disbursal (`LoanDisbursementCompleter::generateSalesExecutiveCommission`) / next cycle `:199`|— *no GL*|—|—|
|84|Commission payment|`pay()` `SalesExecutiveCommissionController.php:52`; **`payment_amount` taken from the client** `:64`,`:73`; not capped to `commissionAmount()`|`LedgerPoster::post` `:101` — Dr `5200` / Cr till or bank leaf. **Balance moved by hand** `:79`/`:83`, **not** via FPS; no cashbook mirror; no idempotency|**yes** `:72`|`void()` `:125`|
|85|Commission payment void|`void()` `:125`|manual `bcadd` restore `:136` + `LedgerPoster::reverse` `:147`|**yes**|self|

### Opening balances, manual journal, GL housekeeping

| # | Transaction | Trigger | GL | In DB txn? | Reversible? |
|---|---|---|---|---|---|
|86|Cash till opening balance|`CashAccountController::store` `:24`|`OpeningBalanceLedgerPoster::postForCashAccount` `:29` — Dr till leaf / Cr `3015`|**yes** `:24`|`LedgerPoster::reverse`|
|87|Subledger opening-balance true-up|`php artisan ledger:opening-balances --apply` `PostOpeningBalances.php:46`|`LedgerPoster::post` `:126` — Dr/Cr `1100`/`1110`/`1120` (+ optional every till/bank) to the **subledger cache figure**, offset `3015`|**yes** `:126`|reversible JE|
|88|Manual journal entry (adjusting/opening/correcting)|`ManualJournalEntryController::store` `:34` (balance validated `:65`) → `approve()` `:107`|`LedgerPoster::post` `:143`, `source_type='ManualAdjustment'`, `entry_date` = operator's date (**backdatable**)|**yes** `:126`|`LedgerPoster::reverse`|
|89|Journal entry reversal|`LedgerPoster::reverse` `:77` — many callers|mirrored entry, `status='Reversed'` on original|caller's txn|—|
|90|Finance transaction reversal|`FinanceTransactionController::reverseRequest` `:64` (direct or `finance_reversal` maker-checker `:100`)|`FinancePostingService::reverse` `:203`|**yes** `:212`|—|
|91|Period close|**DOES NOT EXIST**|n/a|n/a|n/a|
|92|Data-maintenance "clean"|`SystemMaintenanceController::cleanTransactions` `:116` (admin, prod-blocked, password + phrase)|**truncates** `journal_entries`/`journal_lines` + all transactional tables; resets `cash/bank.current_balance = opening_balance` `:149-150` **without re-posting opening entries**|**no** (TRUNCATE auto-commits, `:136`)|irreversible|

---

## 2. Posting matrix (Debit / Credit as the code actually writes them)

Amount-source legend: **server** = recomputed from schedule/config on the server; **client** = taken from the request body; **client-capped** = from the body but bounded by a server figure.

| # Transaction | Trigger | Debit | Credit | Amount source | Rounding | In DB txn? | Reversal | Code ref |
|---|---|---|---|---|---|---|---|---|
|3 Loan disbursement (Cash)|endpoint `disburse()`|`1100`|till leaf|server (`loan.disbursed_amount`)|bcmath 2dp (FPS); `disbursed_amount` set in `LoanDisbursementCompleter.php`|yes|contra `reverseDisbursement`|`LoanLedgerPoster.php:61`|
|3 Loan disbursement (Bank/Cheque)|`disburse()`|`1100`|selected bank leaf (`loan_facility_details.disbursement_bank_account_id`)|server|bcmath 2dp|yes|contra|`LoanLedgerPoster.php:104`|
|4 Loan disbursement (request path)|Finance executes request|`1100` (`meta.offset_account_id`)|source leaf|server (request `amount`)|bcmath 2dp|yes (money); loan completion separate txn|contra|`FinanceRequestService.php:275`, `LoanController.php:829`|
|6 Loan payment — Cash (regular)|`LoanPaymentController::store`|till leaf|`1100` principal, `4010` interest, `4030` penalty, charge→`charge_categories.chart_of_account_id` else `4020` — **from allocation rows, not `payment.amount`**|server (`splitLines` sums `facility_payment_allocations`)|`round(...,2)` PHP float in allocator; bcmath in FPS|yes|contra `reverseAllFor`|`LoanLedgerPoster.php:319`,`:428`|
|13 Loan settlement applied|`approveSettlement`|`2100` suspense|same split as #6|server (`settlementQuote`)|float `round` + bcmath|yes|contra|`LoanLedgerPoster.php:354`|
|8/9 Loan cheque realized|`clearCheque` / PDC|`1030`|same split as #6|server|float `round`|yes|contra|`LoanLedgerPoster.php:387`|
|17 Loan write-off|`approveWriteOffRequest`|`1190` (≤ `provision_posted_amount`) + `5300` (remainder)|**`1100` for the full `outstanding_balance_cache`**|server-cache (see Risk #2)|`round(...,2)`|yes|contra|`LoanLedgerPoster.php:131-155`, amount `LoanController.php:938`|
|18 Provision build-up|`LoanProvisioning::post`|`5300`|`1190`|server (CBSL slab calc)|`round(...,2)`|yes|next adjustment|`LoanLedgerPoster.php:177`|
|18 Provision release|`LoanProvisioning::post`|`1190`|`4050`|server|`round`|yes|—|`LoanLedgerPoster.php:180`|
|16 Loan reschedule rollover|`reschedule()`|`1100`|`4010` (capitalised earned interest), `4030` (retained OD), `4020` (rolled/added charges)|server (`capitalisedInterest` etc. computed in controller)|`round(max(0,...),2)`|yes|— (purge only)|`LoanLedgerPoster.php:235-244`|
|20 Loan cancel|`approveCancelRequest`|till/bank leaf (was Cr)|`1100` (was Dr)|server (original txn amount)|bcmath|yes|self|`FinancePostingService.php:203`|
|21 Loan waiver|`LoanWaiverController::store`|— **none**|— **none**|client (`amount`, uncapped)|—|no|hard-delete|`LoanWaiverController.php:20`|
|23 SD disbursement|`SpeedDraftController::disburse`|`1110`|till or bank leaf|server|bcmath|yes|contra|`SpeedDraftLedgerPoster.php:58`|
|24 SD payment — Cash|`SpeedDraftPaymentController::store`|till leaf|`4015` interest, `4030` penalty, `1110` capital (from allocations)|server|float `round` + bcmath|yes|`reverseAllFor`|`SpeedDraftLedgerPoster.php:90-108`|
|26 SD renewal capitalised income|`renew()`|`1110`|`4015`, `4030`|server|`round`|yes|—|`SpeedDraftLedgerPoster.php:163`|
|29 Pawning disbursement|`PawningController::store`|`1120`|branch till leaf|server (`principal_amount`)|bcmath|yes|contra (`reverseAllFor` payment-only)|`PawningLedgerPoster.php:56`|
|31 Pawning renewal — Cash|`renew()`|till leaf|`4025` interest, `1120` capital|server (`PawningInterestCalculator`)|`round($capital*$rate/100,2)` float|yes|`reverseAllFor`|`PawningLedgerPoster.php:91-95`|
|32/33 Pawning redemption — Cash|`redeem()`|till leaf|`4025`, `1120`|server|float `round`|yes|`reverseAllFor`|`PawningLedgerPoster.php:91-95`|
|36 Pawning auction sale|`recordSale()`|till **leaf** + `1190` (shortfall)|`1120` (full snapshot capital), `4025` (interest recovered), `2110` (surplus)|**client** `hammer_price` (legit — actual sale price)|`min()/max()/round(...,2)` float|yes — **but via `LedgerPoster` only; physical till balance NOT moved**|none|`PawningLedgerPoster.php:138-183`|
|38 Pawning surplus refund|`settleSurplus()`|`2110`|till **leaf**|server (`surplus_amount`)|float|yes — **physical till NOT moved**|none|`PawningLedgerPoster.php:187`|
|39 Pawning surplus forfeiture|`forfeitSurplus()`|`2110`|`4900`|server|float|yes|none|`PawningLedgerPoster.php:215`|
|43 Expense payment|`ExpensePaymentExecutor::pay`|`expense_categories.chart_of_account_id`|till or bank leaf|server (frozen `expense.amount`)|bcmath 2dp|yes|voucher void → FPS reverse|`ExpensePaymentExecutor.php:55`|
|48 Petty cash spend|`PettyCashVoucherController::store`|category account|`1015-{fund}` leaf|client (`amount`, checked ≤ `fund.current_balance`)|bcmath for fund, raw for GL|yes|`LedgerPoster::reverse`|`PettyCashVoucherController.php:81`|
|49 Petty cash top-up|`PettyCashReplenishmentController::store`|`1015-{fund}` leaf|source till/bank leaf|client (checked ≤ `float_limit`)|bcmath|yes|`LedgerPoster::reverse`|`PettyCashReplenishmentController.php:88`|
|52 Float issue|Finance executes|float leaf|source till leaf|client (`issue()` arg)|bcmath|yes|FT reverse|`FinanceRequestService.php:363`|
|53 Float return|`CashFloatService::settle`|home till leaf|float leaf|server (`float.current_balance`)|bcmath|yes|FT reverse|`CashFloatService.php:190`|
|54/58 Shortage|`settle()` / session `verify()`|`5310`|till/float leaf|server (counted − expected)|bcmath|yes|FT reverse|`CashFloatService.php:159`, `CashSessionService.php:101`|
|55/59 Excess|`settle()` / `verify()`|till/float leaf|`4905`|server|bcmath|yes|FT reverse|`CashFloatService.php:171`, `CashSessionService.php:113`|
|60 Transfer one-step|Finance executes|dest leaf|source leaf|server (request `amount`)|bcmath|yes|FT reverse|`FinanceRequestService.php:363`|
|61 Transfer release|`release()`|cash-in-transit leaf|source leaf|server|bcmath|yes|`recall()`|`FinanceRequestService.php:419`|
|62 Transfer receive|`receive()`|dest leaf|in-transit leaf|server|bcmath|yes|FT reverse|`FinanceRequestService.php:472`|
|64 Manual cashbook adj|`CashbookEntryController::store`|till leaf **or** `offset_account_id`|`offset_account_id` **or** till leaf|client (`amount`)|`number_format(...,2)`|yes|FPS reverse|`CashbookEntryController.php:56-64`|
|67 Bank txn (Deposit)|`BankTransactionController::store`|bank leaf|contra (`Cash`/`Bank` leaf, or any `Ledger` account)|client (`amount`)|`number_format(...,2)`|yes|FPS reverse|`BankTransactionController.php:78`|
|67 Bank txn (Withdrawal)|`store`|contra account|bank leaf|client|`number_format`|yes|FPS reverse|`BankTransactionController.php:78`|
|66/86 Opening balance (cash/bank)|account `store()`|leaf (positive) / `3015` (negative)|`3015` (positive) / leaf (negative)|server (`opening_balance`)|bcmath|yes|`LedgerPoster::reverse`|`OpeningBalanceLedgerPoster.php:48`|
|69 FD deposit — Cash|`FdDepositController::store`|till leaf|`2010`|server (`deposit.amount`)|bcmath|yes|`reverseDepositReceived`|`FdLedgerPoster.php:53`|
|70 FD interest paid — Cash|`FdInterestPaymentController`|`5100`|till leaf|server (`paid_amount`)|bcmath|yes|`reverseInterestPaid`|`FdLedgerPoster.php:89`|
|72 FD withdrawal — Cash|`FixedDepositController::withdraw`|`2010` (+ `5100` accrued)|till leaf (total)|server|bcmath|yes|`reverseWithdrawal`|`FdLedgerPoster.php:119-126`|
|73 FD part-payment — Cash|`partPayment()`|`2010` (+ `5100` premature int.)|`5100` (clawback) + till leaf (net)|server (SL premature maths)|`round`/bcmath|yes|`reversePartPayment`|`FdLedgerPoster.php:174-183`|
|76 Borrowing drawdown — Cash|`BorrowingController::store`|till leaf|`2200`/`2210`/`2130`|server (`principal_amount`)|bcmath|yes|`reverseDrawdown`|`BorrowingLedgerPoster.php:65`|
|78 Borrowing interest — Cash|txn kind `InterestPayment`|`5110`|till leaf|server (txn `amount`)|bcmath|yes|`reverseTransaction`|`BorrowingLedgerPoster.php:105`|
|79 Borrowing repayment — Cash|txn kind default|`2200`/`2210`/`2130`|till leaf|server|bcmath|yes|`reverseTransaction`|`BorrowingLedgerPoster.php:109`|
|84 Commission payment|`SalesExecutiveCommissionController::pay`|`5200`|till or bank leaf|**client** (`payment_amount`, **uncapped**)|`bcsub` for balance, raw for GL|yes — **via `LedgerPoster` only; physical balance moved by hand; no FT, no cashbook**|manual restore + `LedgerPoster::reverse`|`SalesExecutiveCommissionController.php:101`|
|87 Opening-balance true-up|`ledger:opening-balances`|`1100`/`1110`/`1120`/leaves (delta) / `3015`|`3015` / accounts (delta)|server-cache (`SUM(outstanding_balance_cache)` / `current_balance`)|bcmath|yes|reversible JE|`PostOpeningBalances.php:86-115`|
|88 Manual journal entry|`ManualJournalEntryController::approve`|operator lines|operator lines|client (validated to balance)|`number_format(...,2)`|yes|`LedgerPoster::reverse`|`ManualJournalEntryController.php:143`|

---

## 3. End-to-end flow traces

Notation: `Dr X / Cr Y  amount` per line, in the order the code emits them.

### 3.1 Loan lifecycle

| Stage | Entries produced | Source |
|---|---|---|
|**Application**|*(none)*|`LoanController.php:249`|
|**Approval**|*(none)* — approval_requests only|`LoanController.php:614`|
|**Disbursement (Cash, amount `D`)**|`Dr 1100 D / Cr <branch till leaf> D` — plus `finance_transactions` DISBURSEMENT row and `till.current_balance -= D`|`LoanLedgerPoster.php:61`|
|**Disbursement (Bank)**|`Dr 1100 D / Cr <selected bank leaf> D`; `bank.current_balance -= D`|`LoanLedgerPoster.php:104`|
|**Interest accrual**|*(none — cash basis)*|`AccrualInterestCalculator.php:13`|
|**Instalment payment (Cash, `P` split into principal `p`, interest `i`, penalty `n`, charges `c`)**|`Dr <till leaf> P` / `Cr 1100 p` / `Cr 4010 i` / `Cr 4030 n` / `Cr <charge account> c`; `till += P`|`LoanLedgerPoster.php:319`,`:428`; allocation `LoanPaymentAllocator.php:286`|
|**Instalment payment (Bank)**|*(none — schedule marked paid, GL untouched)*|`LoanLedgerPoster.php:299`|
|**Penalty / late fee**|no standalone entry — `Cr 4030 n` inside the payment above; priced by `LoanSummary::penaltyDueForRow` (grace days + `loan_types.default_charge_rate_percent`)|`LoanPaymentAllocator.php:22`|
|**Partial payment**|same shape as full, waterfall stops when `remaining` hits 0|`LoanPaymentAllocator.php:346`|
|**Early settlement (quote `Q`)**|at record time: `Dr <till leaf> Q / Cr 2100 Q`. At approval: `applySettlementWaiver` reduces `interest_due`/`total_due` on affected rows; then `Dr 2100 Q / Cr 1100 p / Cr 4010 i / Cr 4030 n / Cr <charge> c`|`LoanLedgerPoster.php:312`,`:354`; `LoanPaymentController.php:262`|
|**Restructure**|`Dr 1100 (i_earned + od_retained + charges_rolled) / Cr 4010 i_earned / Cr 4030 od_retained / Cr 4020 charges`. Old loan → `Restructured`, cache 0. Provision (if any) released: `Dr 1190 / Cr 4050`|`LoanLedgerPoster.php:218`; `LoanController.php:1213`,`:1221`|
|**Write-off (outstanding cache `W`, provision posted `V`)**|`Dr 1190 min(V,W)` + `Dr 5300 (W − min(V,W))` / **`Cr 1100 W`**  ⚠ see Risk #2|`LoanLedgerPoster.php:131`|
|**Closure**|status → `Closed`, `closed_at` set — *no GL*|`LoanPaymentAllocator.php:520`|

### 3.2 Pawning lifecycle

| Stage | Entries | Source |
|---|---|---|
|**Article intake / valuation**|*(none)* — `facility_collateral` + `facility_collateral_pawn_details` with `appraised_value`, `max_loan_value`|`PawningController.php:141`|
|**Advance payout (`A`)**|`Dr 1120 A / Cr <branch till leaf> A`; `till -= A`. Cash only, always.|`PawningLedgerPoster.php:56`|
|**Interest accrual**|*(none)* — flat `normal_rate_percent` (or `special_rate_percent` within `special_rate_days`) of outstanding capital, computed at settlement|`PawningInterestCalculator.php:23`|
|**Renewal (interest `i`, capital paid `k`, Cash)**|`Dr <till leaf> (i+k) / Cr 4025 i / Cr 1120 k`; `till += (i+k)`; `repay_due_date += cycle_days`|`PawningLedgerPoster.php:91`; `PawningController.php:283`|
|**Partial redemption (articles → capital share `k`, interest `i`)**|`Dr <till leaf> (k+i) / Cr 4025 i / Cr 1120 k`; released articles → `Released`|`PawningController.php:391`|
|**Full redemption**|as above for the whole balance; ticket → `Closed`|`PawningController.php:388`|
|**Renewal/redemption via Bank/Cheque**|*(none — ticket state changes, GL untouched)* ⚠ Risk #1|`PawningLedgerPoster.php:80`|
|**Auction listing**|*(none)* — item snapshots `outstanding_capital`, `outstanding_interest`|`PawningAuctionController.php:120`|
|**Auction sale (hammer `H`, snapshot capital `K`, interest `I`)**|`capitalRecovered = min(K,H)`, `shortfall = K − capitalRecovered`, `interestRecovered = min(I, H − capitalRecovered)`, `surplus = max(0, H − K − I)`.  `Dr <till leaf> H` / `Dr 1190 shortfall` / `Cr 1120 K` / `Cr 4025 interestRecovered` / `Cr 2110 surplus`. ⚠ **`till.current_balance` unchanged** — GL-only|`PawningLedgerPoster.php:138`|
|**Surplus refund to customer (`S`)**|`Dr 2110 S / Cr <till leaf> S`. ⚠ **`till.current_balance` unchanged**|`PawningLedgerPoster.php:187`|
|**Surplus forfeiture (unclaimed)**|`Dr 2110 S / Cr 4900 S`|`PawningLedgerPoster.php:215`|
|**Loss on auction**|the `Dr 1190 shortfall` leg of the sale entry|`PawningLedgerPoster.php:164`|

### 3.3 Expenses

| Stage | Entries | Source |
|---|---|---|
|**Expense entry**|*(none)* — `expenses` row status `Pending`, approval_request created|`ExpenseController.php:45`|
|**Approval**|*(none)*|`ExpenseController.php:92`|
|**Payment (direct, amount `E`, Cash)**|`Dr <category.chart_of_account_id> E / Cr <till leaf> E`; `till -= E`; `payment_vouchers` row; `expense.status='Paid'`. Idempotent on `expense-payment-{id}`|`ExpensePaymentExecutor.php:49`|
|**Payment (request path)**|money moves in `FinanceRequestService::execute` (`Dr category / Cr source`), voucher written by `CompleteExpensePaymentOnSettled` in a later transaction|`FinanceRequestService.php:290`|
|**Payable creation**|no distinct "payable" — an unpaid `Approved` expense *is* the payable; nothing posts to `2300` Accrued Expenses|— |
|**Payment of payable**|= Payment above|—|
|**Tax / withholding split**|**not implemented** — gross amount only|`ExpensePaymentExecutor.php:55`|
|**Reimbursement**|no dedicated flow|—|
|**Void**|`FinancePostingService::reverse` (restores balance + mirror + REVERSAL row) or legacy `LedgerPoster::reverse`; `voucher.status='Voided'`|`PaymentVoucherController.php:42`|

### 3.4 Cash floats & tills

| Stage | Entries | Source |
|---|---|---|
|**Float issue (`F`)**|a `CashFloat` finance request; on execute: `Dr <float leaf> F / Cr <source till leaf> F`; `float += F`, `till -= F`|`CashFloatService.php:29`; `FinanceRequestService.php:337`|
|**Top-up**|same as issue against the same open settlement|`CashFloatService.php:47`|
|**Return / handover (counted `C`, expected `X`)**|if `C<X`: `Dr 5310 (X−C) / Cr <float leaf> (X−C)`. if `C>X`: `Dr <float leaf> (C−X) / Cr 4905 (C−X)`. then `Dr <home till leaf> C / Cr <float leaf> C`; float → `Inactive`|`CashFloatService.php:154-197`|
|**Day-end cash count**|`CashSessionService::close` — records `counted_closing`, `variance`, denominations. *No GL.*|`CashSessionService.php:50`|
|**Shortage (verified)**|`Dr 5310 s / Cr <till leaf> s`; `till` trued to count; `last_reconciled_at` set|`CashSessionService.php:96`|
|**Excess (verified)**|`Dr <till leaf> e / Cr 4905 e`|`CashSessionService.php:109`|
|**Transfer between tills (`T`)**|`Dr <dest leaf> T / Cr <source leaf> T`; both balances move|`FinanceRequestService.php:354`|
|**Bank deposit (from till, `B`)**|`BankTransactionController` type `Deposit`, contra `Cash`: `Dr <bank leaf> B / Cr <till leaf> B`; `bank += B`, `till -= B`|`BankTransactionController.php:78`|
|**Bank withdrawal (to till)**|type `Withdrawal`, contra `Cash`: `Dr <till leaf> B / Cr <bank leaf> B`|`BankTransactionController.php:78`|

### 3.5 Opening balances / period close / journal adjustments / inter-account transfers

| Item | Entries | Source |
|---|---|---|
|**Opening balance — new till/bank**|`Dr <leaf> ob / Cr 3015 ob` (or reversed for a negative ob); no-op at zero|`OpeningBalanceLedgerPoster.php:39`|
|**Opening balance — subledger true-up**|one JE: for each of `1100/1110/1120` (+ optional every till/bank), `Dr/Cr` the delta between `SUM(outstanding_balance_cache)` / `current_balance` and the current GL balance; offset to `3015`|`PostOpeningBalances.php:79-115`|
|**Period close**|**no mechanism** — `3030` Current Year Profit/Loss and `3020` Retained Earnings are never posted to by any code path|— |
|**Journal adjustment**|`ManualJournalEntryController` — maker-checker; `LedgerPoster::post(lines, entry_date, source_type='ManualAdjustment')`. Balance enforced at `:65` and again in `LedgerPoster`|`ManualJournalEntryController.php:143`|
|**Inter-account transfer**|see 3.4; cross-branch is 2-step through a per-branch cash-in-transit leaf (`release` → `receive`), reversible via `recall`|`FinanceRequestService.php:394`,`:448`,`:498`|

---

## 4. Accuracy risks — ranked by how much money they can silently lose

### Risk 1 — Bank/Cheque-method collections and settlements post **nothing** to the GL

Every domain poster early-returns unless the method is `Cash`:
`LoanLedgerPoster.php:299`, `SpeedDraftLedgerPoster.php:83`, `PawningLedgerPoster.php:80`,
`BorrowingLedgerPoster.php:55`, and every `FdLedgerPoster` method (`:43`, `:78`, `:107`, `:162`).
Yet the operational side proceeds regardless — `LoanPaymentAllocator::applyAllocation` runs for a
Bank payment (`LoanPaymentController.php:189-191`), `PawningController::redeem` releases articles and
closes the ticket for any method (`:382`,`:388`), `PawningController::renew` rolls the due date.

**Failure scenario:** a customer redeems a LKR 800,000 pawning ticket by bank transfer.
`facility_collateral_pawn_details.item_status` → `Released`, `pawning.status` → `Closed`,
`outstanding_balance_cache` → 0. The GL still shows `Dr 1120 800,000` from the disbursement with no
offsetting credit — **`1120` overstated by 800k forever**, `4025` interest income never recognised,
no bank receipt recorded. Across a book of bank-settled facilities this is an unbounded, growing
overstatement of assets and understatement of income.

### Risk 2 — Loan write-off credits `1100` for principal **plus** never-booked interest/penalty/charges

`LoanLedgerPoster::postWriteOff` credits `1100` for the entire `$amount`
(`LoanLedgerPoster.php:146`), and `$amount = (float) $loan->outstanding_balance_cache`
(`LoanController.php:938`), which is principal **+ unpaid interest + penalty + other charges**
(`LoanPaymentAllocator::outstandingBalance` `:60-74`). Because interest/penalty/charges are
cash-basis and were never debited to a receivable, only the principal portion actually sits in `1100`.

**Failure scenario:** loan with 100,000 principal outstanding, 35,000 accrued interest + 8,000
penalty + 2,000 charges. Write-off posts `Cr 1100 145,000`, `Dr 5300 145,000` (assuming no
provision). `1100` for that loan goes to **−45,000** (abnormal credit, shows as negative on the
Trial Balance — the exact class of complaint in `trial_balance_negative_balances_diagnosis`), and
bad-debt expense is overstated by 45,000. `ledger:opening-balances` would then "fix" `1100` by
crediting `3015`, laundering the error into equity.

### Risk 3 — Pawning auction sale, surplus refund and forfeiture bypass `FinancePostingService`

`PawningLedgerPoster::postAuctionSale` (`:177`), `postSurplusRefund` (`:202`) and
`postSurplusForfeiture` (`:226`) call `LedgerPoster::post` directly. The sale/refund entries debit
or credit the **till leaf account** in the GL, but nothing moves `cash_accounts.current_balance`
and no `cashbook_entries` row is written.

**Failure scenario:** a LKR 2,000,000 auction. GL: `Dr <till leaf> 2,000,000`. Physical
`cash_accounts.current_balance`: unchanged. The teller's drawer, the Cashbook screen, and every
cash report now understate reality by 2M; `finance:check-drift` flags it the next morning
(`CheckLedgerDrift.php:46`) but nothing reconciles it and the cashier has no record of receiving
the money. Reverse sign for every surplus refund paid out.

### Risk 4 — Sales-executive commission payment amount is taken from the client and never capped

`SalesExecutiveCommissionController::pay` validates `payment_amount` as merely
`required|numeric|min:0` (`:64`) and uses it verbatim to move cash (`:79`/`:83`) and post
`Dr 5200 / Cr <source> payment_amount` (`:101`). It is never compared to `commissionAmount()`
(`:186`), which is the actual earned figure. The path also bypasses `FinancePostingService`: manual
`bcsub` with **no negative-balance guard**, no `finance_transactions` row, no `cashbook_entries`
mirror, no idempotency key.

**Failure scenario:** operator (or a fat-fingered form) submits `payment_amount = 5,000,000` for a
commission actually worth 45,000. 5M leaves the till (balance can go negative unnoticed), 5M is
booked to staff cost, and the Cashbook shows nothing. Reconciliation only catches the drift days
later via `finance:check-drift`.

### Risk 5 — Petty cash is a second money-writer, outside `FinancePostingService` and outside the drift check

`PettyCashVoucherController` and `PettyCashReplenishmentController` move
`cash_accounts` / `bank_accounts` / `petty_cash_funds.current_balance` by hand
(`PettyCashVoucherController.php:65`, `PettyCashReplenishmentController.php:68`,`:72`) and post the
GL separately via `LedgerPoster::post`. Neither endpoint sends an idempotency key. `finance:check-drift`
iterates only `CashAccount` and `BankAccount` (`CheckLedgerDrift.php:39-44`) — the
`petty_cash_funds.current_balance` vs `1015-{fund}` leaf comparison is **never run anywhere**.

**Failure scenario:** a replenishment POST is retried by the browser after a timeout. Two
`petty_cash_replenishments` rows, the source till debited twice, the fund credited twice, two
balanced GL entries. The fund's cache and its GL leaf still agree with each other (both doubled),
so even a manual check of that pair looks fine; only the source till is wrong, and only until
someone eyeballs it.

### Risk 6 — No request-level idempotency on payment/transaction creation

`finance_transactions.idempotency_key` is unique, but every key is derived from a row id that is
freshly created on each call: `loan-payment-{payment->id}` (`LoanLedgerPoster.php:348`),
`pawning-payment-{id}`, `fd-deposit-{id}`, etc. The creating endpoints
(`LoanPaymentController::store` `:153`, `PawningController::renew`/`redeem` `:223`/`:341`,
`SpeedDraftPaymentController::store` `:89`, `FdDepositController::store` `:58`,
`BankTransactionController::store` `:87`, `CashbookEntryController::store` `:66`,
`SalesExecutiveCommissionController::pay` `:72`) have no dedupe on the HTTP request itself.

**Partial mitigations that exist:** loan/pawning regular payments are re-checked against
`outstanding` (`LoanPaymentController.php:127`), so a duplicate usually 422s on the second try.
**Unprotected:** FD deposits (`FdDepositController.php` has no such check), bank transactions,
manual cashbook entries, commission payments, and any interest-only payment where `outstanding`
doesn't drop below the amount.

**Failure scenario:** mobile client double-submits an FD deposit of 500,000 → two `fd_deposits`,
`Dr till 500,000 / Cr 2010 500,000` twice, till overstated by 500k, and the customer's FD-payable
liability inflated.

### Risk 7 — Any account, any date, no approval, no period lock: manual JE / bank txn / manual cashbook

* `ManualJournalEntryController` accepts an arbitrary `entry_date` (`:37`, used at `:143`) — no lower bound.
* `BankTransactionController` `contra_kind='Ledger'` lets the operator post the other side to **any** `chart_of_accounts.id` (`:63`, `:143`) with **no maker-checker** and an arbitrary `txn_date` (`:55`).
* `CashbookEntryController` `offset_account_id` is any account (`:47`), **no approval**, arbitrary `entry_date`.
* There is **no closed-period / lock-date table anywhere** in the schema.

**Failure scenario:** a bank transaction with a `Ledger` contra of `3020` Retained Earnings, dated
into a prior reporting period, silently restates last year's equity and this year's bank balance
with no approval record. The only trace is the `journal_entries` row itself.

### Risk 8 — Loan principal waivers never reach the GL; waivers have no maker-checker and are hard-deletable

`LoanWaiverController::store` (`:20`) creates a `facility_waivers` row (`amount` is
`required|numeric|min:0.01`, otherwise **uncapped**), sets `approved_by = self` (`:33`), and posts
**nothing**. For `Interest`/`Penalty`/`Charge` that is defensible (cash-basis income never booked),
but a **`Principal`** waiver reduces `outstanding_balance_cache` via
`LoanPaymentAllocator::waivedByRow` (`:134`) and lets the loan close, while `Dr 1100` from
disbursement stays on the books with no bad-debt expense. `destroy()` (`:41`) hard-deletes the
waiver with no reversal.

**Failure scenario:** 250,000 of principal waived on a keying error. Loan shows `Closed`,
`outstanding_balance_cache = 0`, but `1100` is overstated by 250,000 and the P&L never records the
loss. Deleting the waiver afterward leaves the loan "closed" but under-collected with no audit row.

### Risk 9 — `outstanding_balance_cache` is a stored column that drives real money decisions

The write-off amount (Risk #2), settlement quotes' sanity check
(`LoanPaymentController.php:247`,`:330`), "loan auto-closes when cache ≤ 0"
(`LoanPaymentAllocator.php:520`), and `ledger:opening-balances` (`PostOpeningBalances.php:57`,
which trues `1100` **to** `SUM(outstanding_balance_cache)`) all trust this cache. It is rewritten in
~8 places (`LoanPaymentAllocator.php:515`, `LoanController` disburse/writeoff/reschedule,
`PawningController.php:279`,`:386`,`:388`, `PawningAuctionController.php:192`,`:194`) and is only as
correct as the last `refreshCache`/`outstandingBalance` call.

**Failure scenario:** a manual schedule correction (or a bug in one allocation path) leaves the
cache 12,000 below the true schedule balance. The loan auto-closes 12k short; then someone runs
`ledger:opening-balances --apply` and the 12k shortfall is posted as a `Cr 1100 / Dr 3015`
adjustment — the money is now permanently "lost" into opening equity with a clean-looking JE.

### Risk 10 — Pending/uncleared instruments move money immediately; `1030` is a roach motel

* `BankTransactionController` writes the movement through `FinancePostingService` even when
  `status='Pending'` (`:59`, balance moved at `:88`, `balance_after` snapshot `:105`). A pending
  (uncleared) deposit inflates the available bank balance right away.
* Loan cheque realizations land in `1030` Cheques/Undeposited Funds with **no specific bank
  account** (`LoanLedgerPoster.php:387`,`:393`) and there is **no flow that later clears `1030` into
  a real bank leaf** — `1030` only ever grows.

**Failure scenario:** a large customer cheque is recorded as a `Pending` bank Deposit; the bank
balance shows it as spendable; the cheque bounces a week later and the correction is a manual
`destroy()`. Meanwhile `1030` has accumulated every realized loan cheque ever and no report ties it
back to cash actually banked.

---

### Lower-severity findings

| Finding | Location | Note |
|---|---|---|
|`LoanController::purge()` deletes posted `journal_entries`/`journal_lines` and walks balances back with string-concatenated `DB::raw('current_balance + '.$signed)` and no `lockForUpdate`|`LoanController.php:398-435`, `:404` disables FK checks|explicitly "TEMPORARY, admin-only"; float→string concat can emit `E`-notation for large values|
|`SystemMaintenanceController::cleanTransactions` resets `cash/bank.current_balance = opening_balance` but truncates `journal_entries` without re-posting opening entries|`SystemMaintenanceController.php:149-150`; not in a transaction (`:136`)|after a clean, every account drifts by its opening balance until `ledger:opening-balances --include-cash` is re-run; prod-blocked|
|FD interest and borrowing interest are cash-basis; `2020` Interest Payable on FDs and `1150` Interest Receivable are seeded but never posted|`FdLedgerPoster.php` (no accrual method), COA seed|Accrual-basis P&L (`AccrualInterestCalculator`) is report-only and covers loan + speed-draft interest only|
|Mixed precision: domain calculations use PHP `float` + `round(...,2)` (`LoanPaymentAllocator` throughout, `PawningInterestCalculator.php:35`, `PawningLedgerPoster.php:155-158`), while `LedgerPoster`/`FinancePostingService` use `bcmath`|—|the `LedgerPoster` balance check (`:39`) and the DB CHECK are the backstop — an entry off by ≥ 0.01 throws rather than posting silently, so this manifests as failed requests, not silent imbalance|
|`journal_entries.source_type` is an ENUM extended by ~9 later migrations; a value not in the enum inserts as `''` under non-strict MySQL|`2026_08_01_000007...:31` + `add_*_to_journal_entries_source_type` migrations|low risk if MySQL `STRICT_TRANS_TABLES` is on|
|Cash session `open()`/`close()` take no `lockForUpdate` and write no GL; the opening/closing counts are advisory until a manager `verify()`s|`CashSessionService.php:22`,`:50`|two users could open the same account concurrently between the check (`:27`) and insert (`:34`)|
|`ManualJournalEntryController::approve` records the approval slot and posts the JE in one transaction, but the "not enough approvals yet" branch returns from the closure after already writing `first_approved_by` — fine, but there's no re-check that the accounts are still active at post time|`ManualJournalEntryController.php:126-153`|a deactivated account can still receive a posting if it was chosen before deactivation|
|Loan `disburse()` direct path and the request path use different transaction boundaries — request-path loan completion (`schedule generation, status→Active`) is a **separate** transaction from the money movement (`CompleteDisbursementOnSettled.php:59`), and a failure there only logs (`:41-50`)|`CompleteDisbursementOnSettled.php`|money + GL are correct; the loan can be left `Approved` with cash already disbursed and no schedule — must be finished by hand|
|`FinanceRequestService::execute` caps executed amount at the approved amount but allows it to be **less** (`:252`), with the GL/movement using the lower figure — a partial disbursement leaves the loan expecting the full `disbursed_amount` from `meta`|`FinanceRequestService.php:251`, `CompleteDisbursementOnSettled.php:62`|`LoanDisbursementCompleter` uses `meta.disbursed_amount`, not the executed amount|

---

## Summary

* **68 money-movement transaction types** across 12 modules (loans, speed drafts, pawning,
  pawning auctions, expenses, petty cash, cash floats/sessions, bank, fixed deposits, borrowings,
  sales commission, GL/opening balances).
* **Balanced double-entry is structurally guaranteed** for everything that goes through
  `LedgerPoster::post` (`LedgerPoster.php:39` + DB CHECK `chk_journal_lines_one_side`). No path was
  found that writes a one-sided or unbalanced `journal_lines` set.
* The real exposure is **entries that are balanced but wrong, or missing entirely**, and
  **caches that drift from the GL**. Top 10, ranked by silent-loss potential:

| # | Risk | Worst-case |
|---|---|---|
|1|Bank/Cheque collections & settlements post nothing to the GL (`LoanLedgerPoster.php:299` et al.)|Unbounded, growing overstatement of `1100`/`1110`/`1120` and understatement of interest income|
|2|Write-off credits `1100` for principal + never-booked interest/penalty/charges (`LoanLedgerPoster.php:146`)|`1100` driven abnormally negative; bad-debt expense overstated by the non-principal portion of every write-off|
|3|Pawning auction sale / surplus refund bypass `FinancePostingService` (`PawningLedgerPoster.php:177`,`:202`)|Till cache diverges from GL by the full hammer price of every auction and every surplus paid|
|4|Commission `payment_amount` from client, uncapped, non-FPS (`SalesExecutiveCommissionController.php:64`,`:101`)|Any amount can leave the till and hit `5200`; till can go negative undetected|
|5|Petty cash = second money-writer, no idempotency, outside the drift check (`PettyCashVoucherController.php:65`)|Duplicate submissions double-spend; fund vs GL-leaf drift is never checked|
|6|No HTTP-level idempotency on payment creation (`LoanPaymentController.php:153` et al.)|Duplicate FD deposits / bank txns / cashbook entries / commissions post twice|
|7|Manual JE / bank txn / manual cashbook: any account, any date, no period lock (`BankTransactionController.php:63`, `CashbookEntryController.php:47`)|Unapproved, backdated restatement of a closed period's equity or income|
|8|Loan principal waivers never hit the GL; no maker-checker; hard-deletable (`LoanWaiverController.php:20`,`:41`)|`1100` overstated by waived principal; loss never in P&L|
|9|`outstanding_balance_cache` drives write-off amount, auto-close and opening-balance true-up (`PostOpeningBalances.php:57`)|A wrong cache is laundered into `3015` as a clean-looking adjustment|
|10|Pending bank transactions move balances immediately; `1030` is never cleared to a real bank leaf (`BankTransactionController.php:59`, `LoanLedgerPoster.php:393`)|Uncleared funds shown as spendable; `1030` grows without bound|
