# System Audit Report — ABSL Financial

Scope: atomic CRUD / transaction integrity audit. Date: 2026-10-31. Branch `master`.
**Status: audit pass 1 — NOT complete.** See section 9 for exactly what was and was not inspected. No source code, migration or database was modified by this audit (the working tree already held uncommitted edits before it started; they were only read).

Evidence labels used throughout:
- **VERIFIED** – read in the code; the failing path is visible in the lines cited.
- **RISK** – plausible from the code but needs a test or data check to confirm.
- **CLARIFY** – a business rule is missing or ambiguous; needs your decision.

---

## 1. Architecture

| Layer | Facts (from code) |
|---|---|
| Backend | Laravel (PHP 8.5 locally), `backend/`. 261 write routes in `backend/routes/api.php` (768 lines). 94 controllers in `app/Http/Controllers/Api` (18,225 lines). Auth by Sanctum bearer token. |
| Money writers | `Support/LedgerPoster` (only writer of `journal_entries`/`journal_lines`), `Support/FinancePostingService` (only writer of cash/bank balance + `finance_transactions` + cashbook mirror), `Support/SavingsAccountService` (savings balance), `Services/NumberGenerator` (document numbers), plus one `*LedgerPoster` per module (Loan, Pawning, Fd, Savings, SpeedDraft, Borrowing, FixedAsset, OpeningBalance). |
| Transaction convention | Helpers do **not** open transactions; each controller wraps its own `DB::transaction()`. Documented in `LedgerPoster`, `NumberGenerator`, `SavingsAccountService` docblocks. |
| Duplicate protection | `Http/Middleware/Idempotency` (header `Idempotency-Key`, table `idempotency_keys`) applied to 41 of 261 write routes (see F-03). Ledger-level keys (`finance_transactions.idempotency_key`, unique) per source row. |
| Audit trail | `Http/Middleware/AuditWrites` writes one file-log line per successful write (no before/after, no DB row). `FinanceAuditEvent` table for finance module only. |
| Scheduler | `app/Console/Kernel.php`: 8 daily/monthly commands (provisioning, drift checks, FD auto-renew, FD-loan adjust, borrowing accrual/auto-renew, funding accrual). |
| Frontend | React + Vite + TypeScript, `frontend/src`. 61 screens (plus 3 sub-folders) in `screens/`, central axios client `api.ts` which attaches `Idempotency-Key` to every JSON write and refuses writes while offline. |
| DB | MySQL. 217 migrations. `database/schema/mysql-schema.dump` lists 79 tables, 85 FOREIGN KEYs, 19 CHECKs, 63 `ENGINE=InnoDB` and 16 `ENGINE=MyISAM` mentions. |

## 2. Module inventory (discovered from `app/Models`, routes, screens)

Loans (Loan, LoanType, charges, waivers, payments, schedules, reschedule, PDC, collateral, guarantors, provisions, write-off, cancel) · Speed Drafts (+types, renewals, payments) · Pawning (+types, gold rates, renewals, redemptions, auctions, surplus) · Fixed Deposits (+deposits, interest payments, renewals, withdrawals, part payments, joint holders, nominees, lien/FD-loans, commissions) · Savings (accounts, products, collection sheets, interest runs) · Company Borrowings · Branch Funding · Finance (requests, transactions, reversals, settings) · Cash (accounts, cashbook, transfers, floats, sessions, reconciliation) · Bank (accounts, transactions, reconciliation, undeposited funds) · Petty cash (funds, vouchers, replenishments) · Expenses / payment vouchers / vendors · Accounting (chart of accounts, manual journals, period lock, reports) · Fixed assets (+depreciation, disposal) · Commissions (sales executive, FD) · Customers (+bank, business, employment, documents, blacklist) · Staff, Users, Roles, Permissions, user overrides, branches · Approvals (types, tiers, requests) · Subscriptions · Company profile · System maintenance · Console commands (15) · Reports (≈9 report controllers).

## 3. What is already done well (VERIFIED)

- `LoanPaymentController::store` (L103-L195) locks the loan row, re-checks outstanding inside the transaction, and posts allocation, approval request, receipt number and ledger in one transaction. Best-in-repo example.
- `FinancePostingService::applyMovement` locks the cash/bank row, enforces no-negative-balance, and is idempotent by key.
- `SavingsAccountService::record` locks the account row (`lockForUpdate`) and writes a running-balance snapshot.
- Void on loan/speed-draft payments keeps the row with `status='Voided'` and reverses the ledger (no delete).
- `LedgerPoster` enforces balanced, one-sided, non-negative lines, postable+active accounts and the period lock; failures throw, which rolls back the caller.
- `BorrowingController` locks the borrowing row before accruing and posting.

## 4. Findings

Priority: Critical / High / Medium / Low. "Tables" lists tables the operation writes.

### Critical

**F-01 Possible non-transactional (MyISAM) tables — RISK, environment-level**
Evidence: migrations `2026_08_01_000008`, `2026_08_27_000001` and the untracked `2026_10_31_000001_convert_myisam_tables_to_innodb.php` all exist to fix MyISAM tables; `config/database.php` had `'engine' => null` (committed) and is changed to `'InnoDB'` only in the uncommitted diff; `mysql-schema.dump` still contains 16 `ENGINE=MyISAM` mentions. `tests/Feature/AllTablesTransactionalTest.php` (untracked) asserts all tables are InnoDB.
I could **not** confirm the live engines: connecting to the local server failed (`Plugin 'mysql_native_password' is not loaded`).
Consequence: on a MyISAM table `DB::transaction` neither rolls back nor locks; every finding below about "rolls back" assumes InnoDB.
Fix: run the engine query on every environment; apply the migration; commit config change + test.

### High

**F-02 Check-then-act outside the transaction, no row lock (lost updates / double effects) — VERIFIED**
The status or balance guard is evaluated on a stale model before `DB::transaction`, and the transaction does not lock or re-check. Two requests with different idempotency keys (two employees, two browser tabs, or a route with no key support) both pass.

| ID | Operation | File / function | Tables | Concrete failure |
|---|---|---|---|---|
| F-02a | FD part payment | `FixedDepositController::partPayment` L728-L822 | fd_part_payments, fixed_deposits, fd_interest_payments, journal*, finance_transactions, cash/bank | `principal` read at L747 before the transaction. Two concurrent part payments each compute `remaining` from the same principal; both pay out cash; FD principal ends reduced by only one of them. Cash and ledger out by 2x, FD by 1x. |
| F-02b | FD renew | `renew` L214 / `performRenewal` L286 | fixed_deposits, fd_renewals, fd_deposits, journal* | No lock, no re-check of `status`/`renewed_to_id` inside the transaction. Two renewals create two new FDs from one old one (`fd_renewals.new_fixed_deposit_id` is unique, `old_fixed_deposit_id` is not). Same entry used by `fd:auto-renew` — an overlap with an operator renewal doubles principal. |
| F-02c | FD withdraw / premature withdraw | `withdraw` L432, `prematureWithdraw` L648 | fd_withdrawals … | `fd_withdrawals.fixed_deposit_id` is UNIQUE, so the second request fails — but as an unhandled 500 after consuming a voucher number inside the (rolled back) transaction. Safe, but not clean. A concurrent part-payment vs withdraw is not protected (F-02a). |
| F-02d | FD void part payment | `voidPartPayment` L830-L853 | fd_part_payments, fixed_deposits, journal* | "Latest payment only" check at L832 is outside the transaction and there is **no check that the FD is still Active**: voiding after the FD was closed/renewed restores principal on a closed FD and reverses cash. |
| F-02e | Pawning renew / redeem | `PawningController::renew` L226, `redeem` L328 | facility_payments, pawning_renewals/redemptions, credit_facilities, pawning details, journal*, finance_transactions | `outstanding_balance_cache`, `status` and `pledged` list read before the transaction; no lock. Two redemptions of the same ticket create two payments and two cash receipts. The ledger key `pawning-payment-{payment.id}` is per payment, so it does **not** collapse them. |
| F-02f | Pawning void | `voidPayment` L439 | same | "Most recent payment" check outside the transaction. |
| F-02g | Savings withdrawal | `SavingsAccountController::withdraw` L263-L314 + `SavingsAccountService::record` | savings_accounts, savings_transactions, journal*, finance_transactions | Minimum-balance check (L278-L284) uses the stale in-memory balance; `record()` locks the row but **never checks the new balance is >= min or >= 0**. Two simultaneous withdrawals can overdraw the account. |
| F-02h | Savings interest run post / collection sheet post | `SavingsInterestController::post` L102, `SavingsCollectionController::post` L108 | savings_*, journal* | Status `Draft`/`Open` checked at the top, not re-checked or locked inside the transaction. Two posts credit every account twice. |
| F-02i | Bank transaction clear | `BankTransactionController::clear` L133 | bank_transactions, bank_accounts, finance_transactions, journal* | Pending check outside the transaction, **no `idempotency_key`** in the `FinancePostingService::execute` spec, no lock on the bank_transactions row. Double clear moves the bank balance twice. |
| F-02j | Loan cancel vs payment | `LoanController::approveCancelRequest` L725 vs `LoanPaymentController::store` | credit_facilities, facility_schedules, facility_payments, journal* | Cancel checks `hasCollectionHistory()` outside the transaction and never locks the loan row (payment store does). A payment recorded in that window is posted to a loan whose schedule is then deleted and disbursement reversed. |
| F-02k | Approval-driven actions | `LoanPaymentController::approveSettlement/approveVoidRequest/approveAllocationRequest/reject*`, `LoanController::approve/reject/approveWriteOffRequest`, `PawningAuctionController::settleSurplus/forfeitSurplus/recordSale`, `SpeedDraftPaymentController::destroy` L161 | various | Status checked before the transaction. Void/settle can run twice concurrently. `SpeedDraftPaymentController::destroy` adds the reversed capital to `outstanding_balance_cache` once per concurrent run. Surplus: refund and forfeit can both succeed — different ledger keys. |
| F-02l | Finance reversal approval | `FinanceTransactionController::approveReverse` L100-L127 | approval_requests, finance_transactions, journal* | `recordApproval()` saves the approval slot **outside** any transaction, then `reverse()` (own transaction) runs, then request status is updated. If `reverse()` throws (e.g. cash account would go negative) the approval slot stays consumed and the request stays `PendingApproval`: stuck. `FinancePostingService::reverse` checks `status==='Reversed'` on the stale model before its transaction. |

**F-03 Idempotency middleware covers 41 of 261 write routes; important gaps remain — VERIFIED**
`routes/api.php` applies `idempotency` (41 routes, listed in `CRUD_TRANSACTION_INVENTORY.md` §A) to most "create money" actions: loan disburse/payments/settle, loan-payment void/approve, speed-draft disburse/renew/payments, pawning renew/redeem, bank-transactions, cashbook-entries, FD deposit/part-payment/premature-withdraw/interest-pay, borrowings, savings deposit/withdraw/open/post, expenses/pay, fixed assets, finance-request release/receive. The frontend (`api.ts` L50-L58) sends a key on every write, so a same-user double click or network retry is deduplicated on those routes.
**Not covered** (no request-level replay guard): FD `renew`, FD `withdraw`, FD `voidWithdrawal`/`voidPartPayment`, `BankTransactionController::clear`, savings `voidTransaction`/`voidRun`/`close`, `PawningController::voidPayment`, pawning auction sale/surplus, loan cancel/write-off request+approve, `approveSettlement` is covered but `rejectSettlement`/`clearCheque` are not, borrowing void, petty-cash, manual journal approve, cash transfers/reconciliation, FD commission store, user/staff create.
**Important limit:** a key only deduplicates the *same client's* retry. Two employees (different keys) or two tabs with different payloads still reach the handler together, so the F-02 races stay open even on covered routes (e.g. FD part payment, pawning redeem, savings withdraw are covered by the middleware but still race between two users).
Also RISK: the middleware claims the key outside any transaction and records the response afterwards; a process crash in between leaves the key "in-flight" (409) for 24 h. Not tested.

**F-04 Hard deletion of financial history on "void" — VERIFIED**
The ledger side is reversed correctly, but the business rows are physically deleted:
`FixedDepositController::voidWithdrawal` L571 (`FdWithdrawal`), `voidPartPayment` L851 (`FdPartPayment`), `PawningController::voidPayment` L464/L474 (`PawningRenewal`, `PawningRedemption`), `BorrowingController::voidTransaction` (`BorrowingTransaction`), `LoanWaiverController::destroy` (`FacilityWaiver`), `FdCommissionController::void` (payment fields nulled). Loan and speed-draft payments, by contrast, are kept as `Voided` rows — that is the pattern to follow.

**F-05 Hard purge and wipe endpoints — VERIFIED (classification: restricted deletion)**
`LoanController::purge` L430-L583 (`DELETE loans/{loan}/purge`, admin) deletes payments, journal lines/entries, cashbook rows with `FOREIGN_KEY_CHECKS=0`; labeled TEMPORARY in its own docblock; blocked in production unless `LOAN_PURGE_ENABLED`. `SystemMaintenanceController::cleanTransactions` truncates all transactional tables; documented as non-atomic (TRUNCATE implicit commit); needs password + phrase; blocked in production unless `DB_CLEAN_ENABLED`. Both contradict "historical transactions cannot be removed" and should be removed or kept off in production by config assertion.

**F-06 Loan waiver ledger/record mismatch — VERIFIED**
`LoanWaiverController::store` posts `min(amount, relievable)` to the ledger but stores the full `amount` on `facility_waivers`. `destroy` reverses "the latest Posted 'Principal waived%' entry for this loan" by description match, not the entry created for this waiver; deleting an older waiver reverses the wrong entry. The waiver row is then deleted.

### Medium

**F-07 Loan charges editable without ledger — VERIFIED.** `LoanChargeController::update` allows editing `paid_amount` by hand when no allocation exists; no payment row, no ledger entry. `store/update/destroy` have no loan lock, no loan-status guard (charges can be added to Closed/Cancelled loans). CLARIFY: how charges are recognised in the ledger.
**F-08 User + staff link not atomic — VERIFIED.** `UserController::store` L47-L72 creates the user, then links or creates staff, no transaction. A staff failure leaves a login with no staff record. `StaffController::createLogin/destroy`, `update` not line-audited.
**F-09 Account update + chart-of-accounts update not atomic — VERIFIED.** `CashAccountController::update` L41-L65, `BankAccountController::update` L36-L51.
**F-10 Undeposited-funds deposit race — VERIFIED.** `UndepositedFundsController::deposit` computes the 1030 balance, then dispatches a sub-request; no lock, so two deposits can both pass the cap.
**F-11 Loan disbursement completer has no state guard — VERIFIED (mitigated).** `LoanDisbursementCompleter::complete` inserts the schedule unconditionally; second concurrent disburse trips `UNIQUE(credit_facility_id, installment_no)` and fails as a 500. Ledger key `loan-disbursement-{id}` prevents double cash. Safe by constraint; poor error.
**F-12 Document-number sequence rows are held for the whole transaction — VERIFIED.** `NumberGenerator::next` takes `lockForUpdate` on `number_sequences`; the `journal` sequence is one company-wide row, so every ledger posting in the company serialises on it until commit, and lock order differs between flows (loan row → payment seq → journal seq → cash row vs others). No transaction passes a retry-attempts argument and nothing in `app/` handles MySQL deadlock 1213, so a deadlock surfaces as a 500 with the work rolled back. RISK (throughput and deadlock), not tested.
**F-13 Scheduled jobs: no overlap guard — RISK.** `Console/Kernel.php` has no `withoutOverlapping()` / `onOneServer()`. `fd:auto-renew` calls `FixedDepositController::performRenewal` (F-02b). `AdjustMaturedFdLoans`, `AutoRenewMaturedBorrowings` bodies not line-audited.
**F-14 Audit trail is a log file — VERIFIED.** `AuditWrites` logs method/path/user only, only on 2xx; no payload, no old/new values, rolled-back or failed attempts are not recorded.
**F-15 Float arithmetic in money paths — VERIFIED.** Ledger layer uses `bcmath`; domain layers use `(float)` + `round()` (FD part payment L747-L795, savings withdraw L276-L280 with `1e-6` tolerance, pawning renew/redeem, loan allocator comparisons). Rounding drift risk. Column types for balances not enumerated in this pass.
**F-16 `LoanController::close` has no lock or transaction — VERIFIED.** Balance check then status update; a charge or payment landing between the two is not seen. Low consequence.
**F-17 Commission pay duplicates — VERIFIED (mitigated).** `FdCommissionController::pay`, `SalesExecutiveCommissionController::pay`: `paid_at` check outside the transaction; idempotency key `…-{count+1}` is computed from the same count in two concurrent requests, so the ledger collapses them to one — but both then rewrite the commission row.

### Low

F-18 Time zone fixed to `UTC` in `config/app.php`; business dates (`now()->format('Y-m-d')`, `Carbon::today()`) therefore roll over at 05:30 Sri Lanka time. CLARIFY with finance which day a late-night receipt belongs to.
F-19 `FdInterestPaymentController::pay` L80: paid-status check outside the transaction; ledger key `fd-interest-{id}` stops double cash, but a second voucher number is consumed/overwritten.
F-20 Several master-data CRUDs (types, categories, vendors, banks, roles) are single-row writes — atomic per statement; delete guards depend on FK `RESTRICT` (not line-checked per table).

## 5. Business-rule questions (CLARIFY)

1. Bank-method loan receipts record no company bank account and park in 1030 until a manual Bank Deposit (`LoanLedgerPoster::postCashReceipt`). Is that intended permanently?
2. Which day does a late-evening receipt belong to (UTC vs Sri Lanka)?
3. May a charge be added to a closed/cancelled loan? How is a charge recognised as income?
4. Should a void of an FD part payment be allowed after the FD is closed/renewed? (current code allows it)
5. May an operator renew an FD more than once from the same maturity (no — assumed), and what happens when auto-renew and operator collide?
6. Are refund and forfeit of the same auction surplus mutually exclusive? (assumed yes)

## 6. Security and permission notes (from routes and middleware read)

Route groups use `auth:sanctum` + throttle; sensitive actions use `perm:Module,Action` or `admin`. `UserController` guards last-admin and admin-role assignment and revokes tokens on credential/role change (good). Approval flows block self-approval. Not audited: `CheckPermission` internals, per-user overrides, branch scope trait `BelongsToBranch` for write paths (previous audit in `audit/` covered branch isolation for reads/writes it tested).

## 7. Prior work reviewed

`audit/` and `docs/audit/` (earlier accounting/branch-isolation audits). They did not cover transaction atomicity, concurrency, idempotency coverage or hard deletes, so the findings above are new; `docs/audit/failures.md` was not re-verified.

## 8. Summary counts

Critical 1 (environmental, unverified) · High 6 groups (F-02, F-03, F-04, F-05, F-06 + F-02 sub-items a-l) · Medium 11 · Low 3. Verified 21, Risk 4, Clarify 6 questions.

## 9. Coverage — what this pass did NOT inspect

Read line-by-line: `LoanPaymentController`, key methods of `LoanController` (store/update/destroy/purge/approve/reject/cancel/disburse/close/updateStatusFlags), `FixedDepositController` (all money methods), `PawningController`, lower half of `PawningAuctionController`, `BorrowingController` (renew/transaction/void/destroy), savings (withdraw, interest post/void, collection post, service), `LedgerPoster`, `FinancePostingService` (execute head, reverse, applyMovement), `NumberGenerator` (head), `Idempotency`, `AuditWrites`, `FdCommission`, `FdInterestPayment::pay`, `BankTransaction::clear`, `UserController`, cash/bank account controllers, `LoanCharge`, `LoanWaiver`, `SpeedDraftPaymentController::destroy`, `UndepositedFunds`, `FinanceTransactionController`, `SystemMaintenanceController`, frontend `api.ts`.
Classified by static scan only (write method found, transaction/lock presence known, body not read): all remaining controllers in `CRUD_TRANSACTION_INVENTORY.md` marked "scan".
**Not inspected:** the 63 frontend screens (form → endpoint tracing done only at the API-client level), report controllers (read-only), model events/observers/`Concerns`, `FinanceRequestService`/`ExpensePaymentExecutor`/`CashFloatService` bodies, `LoanPaymentAllocator`/`LoanAmortizer`/interest calculators (calculation correctness), `Permissions`/`CheckPermission`, console command bodies, migrations' column types/constraints beyond those cited, live database contents and engines, test suite execution (not run).
This audit therefore does not certify any module outside the "read" list. Recommended: authorise a second pass over the "scan" and "not inspected" items before implementation of Phases 6-8.
