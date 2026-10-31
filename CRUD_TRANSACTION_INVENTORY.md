# CRUD & Transaction Inventory

Legend — **TX**: Y = body is wrapped in `DB::transaction` (per scan or read); N = no wrapper.
**LOCK**: row lock (`lockForUpdate`) taken in that method or the writer it calls. **Depth**: READ = method body read line by line; SCAN = write statements and transaction/lock presence found by static scan only (body not read; status of correctness unknown).
**IDEM** = `idempotency` middleware on the route. Finding IDs refer to `SYSTEM_AUDIT_REPORT.md`.
Controllers live in `backend/app/Http/Controllers/Api/`; "Poster" classes in `backend/app/Support/`.

## A. Routes with request-level idempotency (41, from `routes/api.php`)

loans/{loan}/disburse · loans/{loan}/request-disbursement · loans/{loan}/payments · loan-payments/{payment}/void-request · loan-payment-void-requests/{r}/approve · loan-payment-allocation-requests/{r}/approve · loan-payments/{payment}/approve-settlement · sales-executive-commissions/{c}/pay · loans/{loan}/waivers · speed-drafts/{sd}/disburse · …/request-disbursement · …/renew · …/payments · pawnings/{p}/renew · pawnings/{p}/redeem · bank-transactions · undeposited-funds/deposit · cashbook-entries · cash-float-settlements/{s}/settle · finance-requests/{r}/release · …/receive · fd-deposits · loans/{loan}/settle-from-fd · fd-commissions/{c}/pay · fixed-deposits/{fd}/premature-withdraw · …/part-payment · fd-interest-payments/{p}/pay · borrowings · borrowings/{b}/transactions · branch-funding/{id}/repay · savings-accounts · …/deposit · …/withdraw · savings-collection-sheets/{s}/post · savings-interest-runs/{r}/post · expenses · expenses/{e}/pay · …/request-payment · fixed-assets/depreciation/run · fixed-assets · fixed-assets/{a}/dispose.

## B. Module maps

### B1. Loans (`LoanController`, `LoanPaymentController`, `LoanChargeController`, `LoanWaiverController`, `LoanPostDatedChequeController`, `LoanDocument/Collateral/DiaryNote` controllers; `LoanLedgerPoster`, `LoanPaymentAllocator`, `LoanDisbursementCompleter`)
Frontend: `screens/LoanApplications.tsx`, `loan/*`, `ActiveLoans.tsx`, `LoanDisbursement.tsx`, `OverdueLoans.tsx`, `LoanAdjustments.tsx`, `LoanProvisions.tsx`, `PaymentReceipt.tsx`.

| Operation | Endpoint → method | Tables written | TX | LOCK | IDEM | Depth / notes |
|---|---|---|---|---|---|---|
| Create application | POST loans → `store` | credit_facilities, loan_facility_details, guarantors, co-applicants, collateral(+details), insurance, approval_requests, document_number_log, number_sequences | Y | seq | – | READ (header); body not fully read |
| Update application | PUT loans/{loan} → `update` | same, deletes+recreates guarantors/co-applicants/collateral | Y | N | – | READ; Pending-only |
| Withdraw (reject) | DELETE loans/{loan} → `destroy` | credit_facilities.status, approval_requests | Y | N | – | READ. Status flip, not delete (good). Status check outside TX |
| Approve / reject | POST …/approve, …/reject → `approve`,`reject` | credit_facilities, approval_requests | Y | N | – | READ. F-02k |
| Disburse | POST loans/{loan}/disburse → `disburse` | credit_facilities, facility_schedules, details, sales_executive_commissions, journal*, finance_transactions, cash_accounts/bank | Y | seq, cash row | Y | READ. F-11 (schedule unique saves it) |
| Request disbursement | → `requestDisbursement` | finance_requests | scan N | – | Y | SCAN (writes=1, no TX) |
| Record payment | POST loans/{loan}/payments → `LoanPaymentController::store` | facility_payments, allocations, charges(paid_amount), schedules, approval_requests, journal*, finance_transactions, cashbook | Y | loan row | Y | READ — reference implementation |
| Settlement approve/reject | `approveSettlement` / `rejectSettlement` | payment, approval_requests, schedules, journal* | Y | N | Y / – | READ. F-02k |
| Manual allocation approve/reject | `approveAllocationRequest` / `rejectAllocationRequest` | same | Y | N | Y / – | READ. F-02k |
| Void payment (request→approve) | `voidRequest`, `approveVoidRequest`, `rejectVoidRequest` | approval_requests; payment.status=Voided; allocations reversed; journal reversed | approve: Y; request/reject: N | N | Y | READ. Soft void (good). `voidRequest` check-then-create race → duplicate pending requests |
| Cheque realize / bounce | `clearCheque` | payment, schedules, journal* | Y | bounce: loan row; realize: N | – | READ. Realize checks `clearance_status` outside TX → double allocation possible (F-02k) |
| Cancel request / approve | `cancelRequest`, `approveCancelRequest` | approval_requests; schedules deleted; commissions deleted; journal reversed; loan status | request N; approve Y | N | – | READ. F-02j; schedule rows hard-deleted on cancel |
| Write-off request/approve | `writeOffRequest`, `approveWriteOffRequest`, `rejectWriteOffRequest` | approval_requests, journal*, provisions | approve Y; others N | N | – | partial (SCAN) |
| Reschedule | POST loans/{loan}/reschedule → `reschedule` | new credit_facilities, schedules, reschedule audit, journal* | Y | N | – | SCAN |
| Close | POST …/close → `close` | credit_facilities | **N** | N | – | READ. F-16 |
| Skip installment(s) | `skipInstallment`, `bulkSkipInstallments` | facility_schedules | Y | N | – | SCAN |
| Status flags / officers | `updateStatusFlags` (Y), `updateOfficers` (N) | status events, details | mixed | N | – | READ/SCAN |
| Charges | `LoanChargeController::store/update/destroy` | facility_charges | N (single-row) | N | – | READ. F-07 |
| Waivers | `LoanWaiverController::store` (Y, IDEM) / `destroy` (Y) | facility_waivers, journal* | Y | N | store only | READ. F-06 |
| Post-dated cheques | `LoanPostDatedChequeController` store/destroy/others | facility_post_dated_cheques, payments | store N; others Y | cheque row | – | SCAN |
| Provisions | `LoanProvisionController`, `LoanProvisioning::refreshCache` | loan_provisions, journal* | refresh Y | – | – | SCAN |
| Purge (hard delete) | DELETE loans/{loan}/purge → `purge` | ~20 tables incl. journal*, cashbook | Y | N | – | READ. F-05 |
| Documents / collateral / diary | Loan*Document/Collateral/DiaryNote controllers | facility_documents etc., storage files | N | N | – | SCAN; file+row not atomic (row first/last unknown) |

### B2. Speed Drafts (`SpeedDraftController`, `SpeedDraftPaymentController`, `SpeedDraftCollateralController`, types)
store Y · update Y · approve Y · reject Y · disburse Y (IDEM) · requestDisbursement N · renew Y (IDEM) · payment store Y (IDEM) · **payment destroy (void) Y, no lock — F-02k (READ)** · destroy (soft delete of Pending) N (SCAN) · collateral store/update Y, destroy N (SCAN).

### B3. Pawning (`PawningController`, `PawningAuctionController`, type/article/gold-rate controllers; `PawningLedgerPoster`)
| Operation | Method | TX | LOCK | IDEM | Notes |
|---|---|---|---|---|---|
| Create ticket + disbursement | `store` | Y | seq | – | READ (head) |
| Admin edit | `adminUpdate` | Y | N | – | READ (guards on no payments) |
| Renew | `renew` | Y | **N** | Y | READ. F-02e |
| Redeem (full/partial) | `redeem` | Y | **N** | Y | READ. F-02e |
| Void payment | `voidPayment` | Y | **N** | – | READ. F-02f, F-04 (deletes renewal/redemption rows) |
| Auction create / add items | `store`, `addItems` | Y | N | – | SCAN |
| Record sale | `recordSale` | Y | N | – | READ (head). Ledger key per item |
| Surplus refund / forfeit | `settleSurplus`, `forfeitSurplus` | Y | N | – | READ. Different ledger keys → both can win (F-02k) |
| Mark unsold / complete | `markUnsold`, `complete` | **N** | N | – | READ. Single-row status writes |
| Gold rates, types | store/update/destroy | N | N | – | SCAN. `PawningGoldRate` destroy physical delete |

### B4. Fixed Deposits (`FixedDepositController`, `FdDepositController`, `FdInterestPaymentController`, `FdLoanController`, `FdCommissionController`; `FdLedgerPoster`, `FdLienService`, `FdInterestCalculator`)
| Operation | Method | TX | LOCK | IDEM | Notes |
|---|---|---|---|---|---|
| Deposit (receive money) | `FdDepositController::store` / `destroy` | Y | seq | store Y | SCAN |
| Open FD | `FixedDepositController::store` → `createFixedDeposit` | Y | seq | – | READ (head) |
| Admin edit | `adminUpdate` | Y | N | – | READ; deletes+regenerates interest schedule |
| Renew | `renew`/`performRenewal` | Y | **N** | – | READ. F-02b |
| Auto-renew command | `fd:auto-renew` → `performRenewal` | per FD | N | – | F-02b, F-13 |
| Withdraw (maturity) | `withdraw` | Y | N | – | READ. unique(fd_id) catches duplicate (F-02c) |
| Premature withdraw | `prematureWithdraw` | Y | N | Y | READ (head) |
| Void withdrawal | `voidWithdrawal` | Y | N | – | READ. F-04 |
| Part payment | `partPayment` | Y | **N** | Y | READ. F-02a |
| Void part payment | `voidPartPayment` | Y | **N** | – | READ. F-02d, F-04 |
| Interest installment pay / void | `FdInterestPaymentController::pay` / `void` | Y | N | pay Y | READ (pay). F-19 |
| Auto-renew flag, payout account, extras | `setAutoRenew`, `setPayoutAccount`, `saveExtras` | N | N | – | SCAN (single-row; `saveExtras` multi-row inside caller's TX) |
| Lien / loan against FD | `FdLienService::pledge/release`, `FdLoanController::store` | Y | FD row (Y) | store Y | SCAN — only module with proper row locks |
| Commission | `FdCommissionController` store/update/destroy N; pay/void Y | | | pay Y | READ. F-17, F-04 |

### B5. Savings (`SavingsAccountController`, `SavingsCollectionController`, `SavingsInterestController`, product controller; `SavingsAccountService`, `SavingsLedgerPoster`)
open Y · deposit Y · **withdraw Y, stale balance guard — F-02g (READ)** · close Y (SCAN) · voidTransaction Y (SCAN) · setStatus N (SCAN) · adminUpdate N (SCAN) · collection sheet store/update Y, **post Y — F-02h**, cancel N (READ) · interest run store Y, destroy(Draft) N, **post Y — F-02h**, voidRun Y (READ) · `SavingsAccountService::record` locks the account row (READ).

### B6. Borrowings & Branch Funding (`BorrowingController`, `BranchFundingController`, `BranchFundingService`, `BorrowingLedgerPoster`, `BorrowingInterestAccrual`)
Borrowing `store`, `addTransaction`, `renew` Y with `lockForUpdate` on the borrowing (READ — good) · `voidTransaction` Y no lock, deletes txn row (F-04) · `destroy` Y · `adminUpdate` N (SCAN) · accrual commands Y with lock. Branch funding: store/approve/repay Y with locks (4 `lockForUpdate`), `reject` N (SCAN).

### B7. Finance, cash, bank, petty cash, expenses, journals
| Area | Operations | TX | LOCK | Depth |
|---|---|---|---|---|
| `FinancePostingService::execute/reverse` | all cash/bank balance changes | Y (own) | cash/bank row | READ |
| Bank transactions | `store` Y (IDEM), **`clear` Y, no key/lock — F-02i**, void/others SCAN | | | READ (clear) |
| Cashbook entries | `store` Y (IDEM) | Y | row | SCAN |
| Undeposited funds | `deposit` → sub-request | (inner Y) | N | READ — F-10 |
| Cash/Bank accounts | store Y; **update N — F-09**; destroy N / Y | | | READ |
| Cash reconciliation | store (SCAN), `verify` N, `destroy` N | | | READ — verify has 2 writes without TX |
| Bank reconciliation | store/update/destroy N | | | SCAN |
| Petty cash | fund store/update/destroy N; voucher Y+lock; replenishment Y+lock | | | SCAN (locks present) |
| Expenses | store/approve/reject Y; `postAccrual` runs inside approve TX; pay via `ExpensePaymentExecutor` (IDEM) | | | SCAN |
| Payment vouchers | Y + lock | | | SCAN |
| Manual journals | store/approve/reject Y | | | SCAN |
| Finance requests | `FinanceRequestService` 10 TX blocks, ledger keys per request | Y | | SCAN |
| Finance reversal | `reverseRequest` N, **`approveReverse` N — F-02l**, `rejectReverse` N | | | READ |
| Chart of accounts | store N, update N (2 writes) | | | SCAN |
| Cash floats/settlements | `CashFloatService` Y + lock | | | SCAN |

### B8. Fixed assets, commissions
Fixed assets store/dispose/destroy Y, update N (single row) (SCAN) · depreciation run Y via `FixedAssetDepreciator` (IDEM) · Sales-executive commission pay Y + lock (IDEM), `maybeStartNextCycle` N (SCAN) — F-17.

### B9. Customers, staff, users, roles, branches, admin
Customer store/update Y; `destroy` soft-delete with FK-existence guards, N (READ); `syncDetail` N. Documents/photos/bank details: row + file, N (SCAN). Staff update/destroy/createLogin N (SCAN). **User store N (F-08)**, update N (token revoke after save), destroy N (READ). Roles store/update/destroy N; `Permissions::saveForRole/saveForUser` N (SCAN). Branch store Y, update N, destroy Y. Approval types/tiers N (SCAN). Company profile update/uploadLogo N (SCAN). Subscriptions N (SCAN). SystemMaintenance wipe — deliberately non-transactional (F-05).

### B10. Reports and dashboards (read-only)
Accounting, Loan, Collection, Customer, Expense, Finance, Funding, Investor, Savings, CashBank reports; Dashboard/FinanceDashboard/FundingDashboard. Not inspected. `DashboardController::index` L28 and `AccountingReportController::plWaterfall` L321 matched the write-scan pattern (likely cache writes); not examined.

## C. Cross-module dependency map (who writes what)

Money in to a till/bank: `FinancePostingService::execute` ← LoanLedgerPoster (COLLECTION), PawningLedgerPoster, SpeedDraftLedgerPoster, FdLedgerPoster, SavingsLedgerPoster, BorrowingLedgerPoster, ExpensePaymentExecutor, FinanceRequestService, Fd/SalesExec commission pay, Bank/Cashbook controllers. Each call writes in one DB transaction: `finance_transactions`, `journal_entries`+`journal_lines` (via `LedgerPoster`, which may add an inter-branch companion entry), `cashbook_entries` mirror, `cash_accounts.current_balance` / `bank_accounts.current_balance`.
Derived caches that must stay in step: `credit_facilities.outstanding_balance_cache` (loan, speed draft, pawning), `fixed_deposits.principal_amount/lien_amount`, `savings_accounts.current_balance`, `cash_accounts.current_balance`, `borrowings.outstanding_amount/accrued_interest_unpaid`. Reconciliation commands exist for cash/bank-vs-GL (`finance:check-drift`) and borrowings (`borrowings:check-ledger`); **none exist for loan/pawning/speed-draft/FD/savings caches vs their payment rows.**
Counters: `number_sequences` row lock held to commit (F-12) for every numbered document.
