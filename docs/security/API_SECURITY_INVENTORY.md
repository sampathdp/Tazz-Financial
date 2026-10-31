# API Security Inventory

Generated 2026-11-01 from `php artisan route:list --json` (423 `api/*` routes; 261 are writes; 71 writes carry the `idempotency` middleware). "Permission" is the `CheckPermission:Module,Action` route middleware. All authenticated routes also pass `throttle:300,1`.

## Summary

- **Unauthenticated (4):** `POST /api/login` (throttle 30/min + per email+IP lockout), `GET /api/ping`, `GET /api/company-profile/branding`, `GET /api/company-profile/logo` (intentional: login screen branding).
- **Authenticated, no route-level permission or admin (35):** listed below with their verified in-handler control.
- **Everything else (384):** route-level `perm:` and/or `admin`.
- Route-level checks only prove the role has *some* grant on the module. Record-level (branch/ownership) checks are **not verified** for most handlers; see F-09 in `SECURITY_AUDIT_REPORT.md`.

## The 35 authenticated routes without route-level permission

| Route group | In-handler control | Status |
|---|---|---|
| `GET /me`, `POST /logout` | Caller's own token | OK by design |
| `/profile/photo`, `/profile/staff`, `/profile/achievements*` (8 routes) | Scoped to `$request->user()` (per comment in `routes/api.php`); achievement update/delete ownership check **not read** | Needs verification (IDOR on `{achievement}`) |
| `GET /users/active`, `/users/marketing-executives`, `/staff/options` | Return picklists to any signed-in user | Needs verification of fields returned (should be id/name only) |
| `approve`/`reject` on loans, loan cancel / write-off / void / allocation / settlement, speed drafts, expenses, manual journals, finance-transaction reversals | `ApprovalType::isAuthorized`-style check inside handler (pattern matched in 18 of 19 handlers) | Partially verified |
| `POST /finance-requests/{id}/approve|reject` | Controller delegates to `FinanceRequestService::approve` (`app/Support/FinanceRequestService.php:144`) which checks status, `ApprovalType::isAuthorized`, blocks self-approval, then locks inside a DB transaction | **Verified** |

## Full route table

| Method | URI | Handler | Auth | Permission | Admin | Idempotency |
|---|---|---|---|---|---|---|
| GET | /api/accounting-reports/{type} | AccountingReportController@show | yes | Accounting Reports,View |  |  |
| GET | /api/accounting-reports/{type}/pdf | AccountingReportController@exportPdf | yes | Accounting Reports,View |  |  |
| GET | /api/accounting-reports/profit-loss/monthly | AccountingReportController@profitAndLossMonthly | yes | Accounting Reports,View |  |  |
| GET | /api/approval-types | ApprovalTypeController@index | yes | — | yes |  |
| POST | /api/approval-types | ApprovalTypeController@store | yes | — | yes |  |
| PUT | /api/approval-types/{approvalType} | ApprovalTypeController@update | yes | — | yes |  |
| DELETE | /api/approval-types/{approvalType} | ApprovalTypeController@destroy | yes | — | yes |  |
| PUT | /api/approval-types/{approvalType}/approvers | ApprovalTypeController@updateApprovers | yes | — | yes |  |
| GET | /api/approvals | ApprovalController@index | yes | — | yes |  |
| GET | /api/asset-categories | AssetCategoryController@index | yes | Fixed Assets,View |  |  |
| POST | /api/asset-categories | AssetCategoryController@store | yes | Fixed Assets,Create |  |  |
| PUT | /api/asset-categories/{assetCategory} | AssetCategoryController@update | yes | Fixed Assets,Edit |  |  |
| GET | /api/bank-accounts | BankAccountController@index | yes | Bank Accounts,View |  |  |
| POST | /api/bank-accounts | BankAccountController@store | yes | Bank Accounts,Create |  |  |
| PUT | /api/bank-accounts/{bankAccount} | BankAccountController@update | yes | Bank Accounts,Edit |  |  |
| DELETE | /api/bank-accounts/{bankAccount} | BankAccountController@destroy | yes | Bank Accounts,Delete |  |  |
| GET | /api/bank-reconciliations | BankReconciliationController@index | yes | Bank Reconciliation,View |  |  |
| POST | /api/bank-reconciliations | BankReconciliationController@store | yes | Bank Reconciliation,Create |  |  |
| PUT | /api/bank-reconciliations/{bankReconciliation} | BankReconciliationController@update | yes | Bank Reconciliation,Edit |  |  |
| DELETE | /api/bank-reconciliations/{bankReconciliation} | BankReconciliationController@destroy | yes | Bank Reconciliation,Delete |  |  |
| GET | /api/bank-transactions | BankTransactionController@index | yes | Bank Transactions,View |  |  |
| POST | /api/bank-transactions | BankTransactionController@store | yes | Bank Transactions,Create |  | yes |
| DELETE | /api/bank-transactions/{bankTransaction} | BankTransactionController@destroy | yes | Bank Transactions,Delete |  |  |
| POST | /api/bank-transactions/{bankTransaction}/clear | BankTransactionController@clear | yes | Bank Transactions,Edit |  | yes |
| GET | /api/banks | BankController@index | yes | Masters,View |  |  |
| POST | /api/banks | BankController@store | yes | Masters,Create |  |  |
| PUT | /api/banks/{bank} | BankController@update | yes | Masters,Edit |  |  |
| DELETE | /api/banks/{bank} | BankController@destroy | yes | Masters,Delete |  |  |
| POST | /api/borrowing-transactions/{borrowingTransaction}/void | BorrowingController@voidTransaction | yes | Borrowings,Delete |  | yes |
| GET | /api/borrowings | BorrowingController@index | yes | Borrowings,View |  |  |
| POST | /api/borrowings | BorrowingController@store | yes | Borrowings,Create |  | yes |
| GET | /api/borrowings/{borrowing} | BorrowingController@show | yes | Borrowings,View |  |  |
| PUT | /api/borrowings/{borrowing} | BorrowingController@adminUpdate | yes | — | yes |  |
| DELETE | /api/borrowings/{borrowing} | BorrowingController@destroy | yes | Borrowings,Delete |  |  |
| POST | /api/borrowings/{borrowing}/renew | BorrowingController@renew | yes | Borrowings,Edit |  |  |
| POST | /api/borrowings/{borrowing}/transactions | BorrowingController@addTransaction | yes | Borrowings,Edit |  | yes |
| GET | /api/borrowings/lookups | BorrowingController@lookups | yes | Borrowings,View |  |  |
| GET | /api/borrowings/next-number | BorrowingController@nextNumber | yes | Borrowings,Create |  |  |
| GET | /api/branch-funding | BranchFundingController@index | yes | Branch Funding,View |  |  |
| POST | /api/branch-funding | BranchFundingController@store | yes | Branch Funding,Create |  |  |
| GET | /api/branch-funding/{id} | BranchFundingController@show | yes | Branch Funding,View |  |  |
| PUT | /api/branch-funding/{id} | BranchFundingController@adminUpdate | yes | — | yes |  |
| POST | /api/branch-funding/{id}/accrue | BranchFundingController@accrue | yes | Branch Funding,Edit |  |  |
| POST | /api/branch-funding/{id}/approve | BranchFundingController@approve | yes | Branch Funding,Approve |  |  |
| POST | /api/branch-funding/{id}/reject | BranchFundingController@reject | yes | Branch Funding,Approve |  |  |
| POST | /api/branch-funding/{id}/repay | BranchFundingController@repay | yes | Branch Funding,Edit |  | yes |
| GET | /api/branch-funding/lookups | BranchFundingController@lookups | yes | Branch Funding,View |  |  |
| GET | /api/branches | BranchController@index | yes | Branch Management,View |  |  |
| POST | /api/branches | BranchController@store | yes | Branch Management,Create |  |  |
| PUT | /api/branches/{branch} | BranchController@update | yes | Branch Management,Edit |  |  |
| DELETE | /api/branches/{branch} | BranchController@destroy | yes | Branch Management,Delete |  |  |
| GET | /api/cash-accounts | CashAccountController@index | yes | Cashbook,View |  |  |
| POST | /api/cash-accounts | CashAccountController@store | yes | Cashbook,Create |  |  |
| PUT | /api/cash-accounts/{cashAccount} | CashAccountController@update | yes | Cashbook,Edit |  |  |
| DELETE | /api/cash-accounts/{cashAccount} | CashAccountController@destroy | yes | Cashbook,Delete |  |  |
| GET | /api/cash-bank-reports/{type} | CashBankReportController@show | yes | Cash & Bank Reports,View |  |  |
| GET | /api/cash-bank-reports/{type}/pdf | CashBankReportController@exportPdf | yes | Cash & Bank Reports,View |  |  |
| GET | /api/cash-float-settlements | CashFloatController@settlements | yes | Cash Floats,View |  |  |
| POST | /api/cash-float-settlements/{cashFloatSettlement}/settle | CashFloatController@settle | yes | Cash Floats,Edit |  | yes |
| GET | /api/cash-floats | CashFloatController@index | yes | Cash Floats,View |  |  |
| POST | /api/cash-floats/{cashAccount}/request-settlement | CashFloatController@requestSettlement | yes | Cash Floats,Edit |  |  |
| POST | /api/cash-floats/issue | CashFloatController@issue | yes | Cash Floats,Create |  |  |
| GET | /api/cash-reconciliations | CashReconciliationController@index | yes | Cash Reconciliation,View |  |  |
| POST | /api/cash-reconciliations | CashReconciliationController@store | yes | Cash Reconciliation,Create |  |  |
| DELETE | /api/cash-reconciliations/{cashReconciliation} | CashReconciliationController@destroy | yes | Cash Reconciliation,Delete |  |  |
| POST | /api/cash-reconciliations/{cashReconciliation}/verify | CashReconciliationController@verify | yes | Cash Reconciliation,Edit |  |  |
| GET | /api/cash-reconciliations/summary | CashReconciliationController@summary | yes | Cash Reconciliation,View |  |  |
| GET | /api/cashbook-entries | CashbookEntryController@index | yes | Cashbook,View |  |  |
| POST | /api/cashbook-entries | CashbookEntryController@store | yes | Cashbook,Create |  | yes |
| DELETE | /api/cashbook-entries/{cashbookEntry} | CashbookEntryController@destroy | yes | Cashbook,Delete |  |  |
| GET | /api/charge-categories | ChargeCategoryController@index | yes | Masters,View |  |  |
| POST | /api/charge-categories | ChargeCategoryController@store | yes | Masters,Create |  |  |
| PUT | /api/charge-categories/{chargeCategory} | ChargeCategoryController@update | yes | Masters,Edit |  |  |
| DELETE | /api/charge-categories/{chargeCategory} | ChargeCategoryController@destroy | yes | Masters,Delete |  |  |
| GET | /api/chart-of-accounts | ChartOfAccountController@index | yes | Chart of Accounts,View |  |  |
| POST | /api/chart-of-accounts | ChartOfAccountController@store | yes | Chart of Accounts,Create |  |  |
| PUT | /api/chart-of-accounts/{chartOfAccount} | ChartOfAccountController@update | yes | Chart of Accounts,Edit |  |  |
| GET | /api/collection-reports/{type} | CollectionReportController@show | yes | Collection Reports,View |  |  |
| GET | /api/collection-reports/{type}/pdf | CollectionReportController@exportPdf | yes | Collection Reports,View |  |  |
| GET | /api/company-profile | CompanyProfileController@show | yes | Company Profile,View |  |  |
| PUT | /api/company-profile | CompanyProfileController@update | yes | Company Profile,Edit |  |  |
| GET | /api/company-profile/branding | CompanyProfileController@branding | **NO** | **none** |  |  |
| GET | /api/company-profile/logo | CompanyProfileController@logo | **NO** | **none** |  |  |
| POST | /api/company-profile/logo | CompanyProfileController@uploadLogo | yes | Company Profile,Edit |  |  |
| GET | /api/customer-reports/{type} | CustomerReportController@show | yes | Customer Reports,View |  |  |
| GET | /api/customer-reports/{type}/pdf | CustomerReportController@exportPdf | yes | Customer Reports,View |  |  |
| GET | /api/customers | CustomerController@index | yes | Customer Management,View |  |  |
| POST | /api/customers | CustomerController@store | yes | Customer Management,Create |  |  |
| GET | /api/customers/{customer} | CustomerController@show | yes | Customer Management,View |  |  |
| PUT | /api/customers/{customer} | CustomerController@update | yes | Customer Management,Edit |  |  |
| DELETE | /api/customers/{customer} | CustomerController@destroy | yes | Customer Management,Delete |  |  |
| GET | /api/customers/{customer}/bank-details | CustomerBankDetailController@index | yes | Customer Management,View |  |  |
| POST | /api/customers/{customer}/bank-details | CustomerBankDetailController@store | yes | Customer Management,Create |  |  |
| DELETE | /api/customers/{customer}/bank-details/{bankDetail} | CustomerBankDetailController@destroy | yes | Customer Management,Delete |  |  |
| POST | /api/customers/{customer}/blacklist | BlacklistController@store | yes | — | yes |  |
| DELETE | /api/customers/{customer}/blacklist | BlacklistController@destroy | yes | — | yes |  |
| GET | /api/customers/{customer}/crib-report | CustomerCribReportController@show | yes | Customer Management,View |  |  |
| GET | /api/customers/{customer}/crib-report/pdf | CustomerCribReportController@exportPdf | yes | Customer Management,View |  |  |
| POST | /api/customers/{customer}/documents | CustomerDocumentController@store | yes | Customer Management,Create |  |  |
| DELETE | /api/customers/{customer}/documents/{document} | CustomerDocumentController@destroy | yes | Customer Management,Delete |  |  |
| GET | /api/customers/{customer}/documents/{document}/file | CustomerDocumentController@show | yes | Customer Management,View |  |  |
| GET | /api/customers/{customer}/funding | CustomerController@funding | yes | Customer Management,View |  |  |
| GET | /api/customers/{customer}/loans | CustomerController@loans | yes | Customer Management,View |  |  |
| GET | /api/customers/{customer}/photo | CustomerPhotoController@show | yes | Customer Management,View |  |  |
| POST | /api/customers/{customer}/photo | CustomerPhotoController@store | yes | Customer Management,Edit |  |  |
| DELETE | /api/customers/{customer}/photo | CustomerPhotoController@destroy | yes | Customer Management,Edit |  |  |
| GET | /api/dashboard | DashboardController@index | yes | Dashboard,View |  |  |
| GET | /api/document-categories | DocumentCategoryController@index | yes | Masters,View |  |  |
| POST | /api/document-categories | DocumentCategoryController@store | yes | Masters,Create |  |  |
| PUT | /api/document-categories/{documentCategory} | DocumentCategoryController@update | yes | Masters,Edit |  |  |
| DELETE | /api/document-categories/{documentCategory} | DocumentCategoryController@destroy | yes | Masters,Delete |  |  |
| GET | /api/expense-categories | ExpenseCategoryController@index | yes | Masters,View |  |  |
| POST | /api/expense-categories | ExpenseCategoryController@store | yes | Masters,Create |  |  |
| PUT | /api/expense-categories/{expenseCategory} | ExpenseCategoryController@update | yes | Masters,Edit |  |  |
| DELETE | /api/expense-categories/{expenseCategory} | ExpenseCategoryController@destroy | yes | Masters,Delete |  |  |
| GET | /api/expense-reports/{type} | ExpenseReportController@show | yes | Expense Reports,View |  |  |
| GET | /api/expense-reports/{type}/pdf | ExpenseReportController@exportPdf | yes | Expense Reports,View |  |  |
| GET | /api/expenses | ExpenseController@index | yes | Expenses,View |  |  |
| POST | /api/expenses | ExpenseController@store | yes | Expenses,Create |  | yes |
| PUT | /api/expenses/{expense}/approve | ExpenseController@approve | yes | **none** |  |  |
| POST | /api/expenses/{expense}/pay | ExpenseController@pay | yes | Expenses,Edit |  | yes |
| PUT | /api/expenses/{expense}/reject | ExpenseController@reject | yes | **none** |  |  |
| POST | /api/expenses/{expense}/request-payment | ExpenseController@requestPayment | yes | Expenses,Create |  | yes |
| GET | /api/fd-commissions | FdCommissionController@index | yes | Fixed Deposits,View |  |  |
| PUT | /api/fd-commissions/{commission} | FdCommissionController@update | yes | Fixed Deposits,Edit |  |  |
| DELETE | /api/fd-commissions/{commission} | FdCommissionController@destroy | yes | Fixed Deposits,Edit |  |  |
| POST | /api/fd-commissions/{commission}/pay | FdCommissionController@pay | yes | Fixed Deposits,Edit |  | yes |
| POST | /api/fd-commissions/{commission}/void | FdCommissionController@void | yes | — | yes |  |
| GET | /api/fd-deposits | FdDepositController@index | yes | Deposits,View |  |  |
| POST | /api/fd-deposits | FdDepositController@store | yes | Deposits,Create |  | yes |
| GET | /api/fd-deposits/{fdDeposit} | FdDepositController@show | yes | Deposits,View |  |  |
| DELETE | /api/fd-deposits/{fdDeposit} | FdDepositController@destroy | yes | Deposits,Delete |  |  |
| GET | /api/fd-deposits/next-number | FdDepositController@nextDepositNo | yes | Deposits,Create |  |  |
| POST | /api/fd-interest-payments/{fdInterestPayment}/pay | FdInterestPaymentController@pay | yes | Fixed Deposits,Edit |  | yes |
| POST | /api/fd-interest-payments/{fdInterestPayment}/void | FdInterestPaymentController@void | yes | Fixed Deposits,Delete |  |  |
| GET | /api/fd-interest-payments/{fdInterestPayment}/voucher | FdInterestPaymentController@voucher | yes | Fixed Deposits,View |  |  |
| GET | /api/fd-interest-payments/due | FdInterestPaymentController@due | yes | Fixed Deposits,View |  |  |
| GET | /api/fd-loan-policies | FdLoanController@policies | yes | Funding Products,View |  |  |
| PUT | /api/fd-loan-policies/{fundingProduct} | FdLoanController@savePolicy | yes | Funding Products,Edit |  |  |
| POST | /api/fd-loans | FdLoanController@store | yes | Loan Applications,Create |  |  |
| GET | /api/fd-loans/eligible-fds | FdLoanController@eligibleFds | yes | Loan Applications,Create |  |  |
| GET | /api/fd-loans/quote/{fixedDeposit} | FdLoanController@quote | yes | Loan Applications,Create |  |  |
| POST | /api/fd-part-payments/{fdPartPayment}/void | FixedDepositController@voidPartPayment | yes | Fixed Deposits,Delete |  | yes |
| GET | /api/finance-audit | FinanceAuditController@index | yes | Finance Audit,View |  |  |
| POST | /api/finance-calculator/calculate | FinanceCalculatorController@calculate | yes | Loan Calculator,View |  |  |
| GET | /api/finance-dashboard | FinanceDashboardController@index | yes | Finance Dashboard,View |  |  |
| GET | /api/finance-reports/{type} | FinanceReportController@show | yes | Finance Reports,View |  |  |
| GET | /api/finance-reports/{type}/pdf | FinanceReportController@exportPdf | yes | Finance Reports,View |  |  |
| GET | /api/finance-requests | FinanceRequestController@index | yes | Finance Requests,View |  |  |
| POST | /api/finance-requests | FinanceRequestController@store | yes | Finance Requests,Create |  |  |
| GET | /api/finance-requests/{financeRequest} | FinanceRequestController@show | yes | Finance Requests,View |  |  |
| POST | /api/finance-requests/{financeRequest}/approve | FinanceRequestController@approve | yes | **none** |  |  |
| POST | /api/finance-requests/{financeRequest}/cancel | FinanceRequestController@cancel | yes | Finance Requests,Create |  |  |
| POST | /api/finance-requests/{financeRequest}/execute | FinanceRequestController@execute | yes | Finance Requests,Edit |  |  |
| POST | /api/finance-requests/{financeRequest}/recall | FinanceRequestController@recall | yes | Finance Transfers,Edit |  |  |
| POST | /api/finance-requests/{financeRequest}/receive | FinanceRequestController@receive | yes | Finance Transfers,Edit |  | yes |
| POST | /api/finance-requests/{financeRequest}/reject | FinanceRequestController@reject | yes | **none** |  |  |
| POST | /api/finance-requests/{financeRequest}/release | FinanceRequestController@release | yes | Finance Transfers,Edit |  | yes |
| POST | /api/finance-requests/{financeRequest}/submit | FinanceRequestController@submit | yes | Finance Requests,Create |  |  |
| GET | /api/finance-settings | FinanceSettingController@index | yes | Finance Settings,View |  |  |
| PUT | /api/finance-settings | FinanceSettingController@update | yes | Finance Settings,Edit |  |  |
| POST | /api/finance-transaction-reverse-requests/{approvalRequest}/approve | FinanceTransactionController@approveReverse | yes | **none** |  |  |
| POST | /api/finance-transaction-reverse-requests/{approvalRequest}/reject | FinanceTransactionController@rejectReverse | yes | **none** |  |  |
| GET | /api/finance-transactions | FinanceTransactionController@index | yes | Cash Transactions,View |  |  |
| GET | /api/finance-transactions/{financeTransaction} | FinanceTransactionController@show | yes | Cash Transactions,View |  |  |
| POST | /api/finance-transactions/{financeTransaction}/reverse-request | FinanceTransactionController@reverseRequest | yes | Cash Transactions,Delete |  | yes |
| GET | /api/fixed-assets | FixedAssetController@index | yes | Fixed Assets,View |  |  |
| POST | /api/fixed-assets | FixedAssetController@store | yes | Fixed Assets,Create |  | yes |
| GET | /api/fixed-assets/{fixedAsset} | FixedAssetController@show | yes | Fixed Assets,View |  |  |
| PUT | /api/fixed-assets/{fixedAsset} | FixedAssetController@update | yes | Fixed Assets,Edit |  |  |
| DELETE | /api/fixed-assets/{fixedAsset} | FixedAssetController@destroy | yes | — | yes |  |
| POST | /api/fixed-assets/{fixedAsset}/dispose | FixedAssetController@dispose | yes | Fixed Assets,Edit |  | yes |
| POST | /api/fixed-assets/depreciation/preview | FixedAssetController@depreciationPreview | yes | Fixed Assets,View |  |  |
| POST | /api/fixed-assets/depreciation/run | FixedAssetController@depreciationRun | yes | Fixed Assets,Edit |  | yes |
| GET | /api/fixed-assets/depreciations | FixedAssetController@depreciations | yes | Fixed Assets,View |  |  |
| GET | /api/fixed-deposits | FixedDepositController@index | yes | Fixed Deposits,View |  |  |
| POST | /api/fixed-deposits | FixedDepositController@store | yes | Fixed Deposits,Create |  |  |
| GET | /api/fixed-deposits/{fixedDeposit} | FixedDepositController@show | yes | Fixed Deposits,View |  |  |
| PUT | /api/fixed-deposits/{fixedDeposit} | FixedDepositController@adminUpdate | yes | — | yes |  |
| PUT | /api/fixed-deposits/{fixedDeposit}/auto-renew | FixedDepositController@setAutoRenew | yes | Fixed Deposits,Edit |  |  |
| POST | /api/fixed-deposits/{fixedDeposit}/commissions | FdCommissionController@store | yes | Fixed Deposits,Edit |  |  |
| GET | /api/fixed-deposits/{fixedDeposit}/interest-payments | FdInterestPaymentController@index | yes | Fixed Deposits,View |  |  |
| POST | /api/fixed-deposits/{fixedDeposit}/part-payment | FixedDepositController@partPayment | yes | Fixed Deposits,Edit |  | yes |
| PUT | /api/fixed-deposits/{fixedDeposit}/payout-account | FixedDepositController@setPayoutAccount | yes | Fixed Deposits,Edit |  |  |
| GET | /api/fixed-deposits/{fixedDeposit}/premature-preview | FixedDepositController@prematurePreview | yes | Fixed Deposits,View |  |  |
| POST | /api/fixed-deposits/{fixedDeposit}/premature-withdraw | FixedDepositController@prematureWithdraw | yes | Fixed Deposits,Edit |  | yes |
| POST | /api/fixed-deposits/{fixedDeposit}/renew | FixedDepositController@renew | yes | Fixed Deposits,Edit |  | yes |
| POST | /api/fixed-deposits/{fixedDeposit}/void-withdrawal | FixedDepositController@voidWithdrawal | yes | Fixed Deposits,Delete |  | yes |
| POST | /api/fixed-deposits/{fixedDeposit}/withdraw | FixedDepositController@withdraw | yes | Fixed Deposits,Edit |  | yes |
| GET | /api/fixed-deposits/{fixedDeposit}/withdrawal-preview | FixedDepositController@withdrawalPreview | yes | Fixed Deposits,View |  |  |
| GET | /api/funding-dashboard | FundingDashboardController@index | yes | Funding Reports,View |  |  |
| GET | /api/funding-products | FundingProductController@index | yes | Funding Products,View |  |  |
| POST | /api/funding-products | FundingProductController@store | yes | Funding Products,Create |  |  |
| PUT | /api/funding-products/{fundingProduct} | FundingProductController@update | yes | Funding Products,Edit |  |  |
| DELETE | /api/funding-products/{fundingProduct} | FundingProductController@destroy | yes | Funding Products,Delete |  |  |
| GET | /api/funding-reports/{type} | FundingReportController@show | yes | Funding Reports,View |  |  |
| GET | /api/funding-reports/{type}/pdf | FundingReportController@exportPdf | yes | Funding Reports,View |  |  |
| GET | /api/generated-numbers | GeneratedNumberController@index | yes | Document Tracking,View |  |  |
| GET | /api/generated-numbers-years | GeneratedNumberController@years | yes | Document Tracking,View |  |  |
| GET | /api/investor-reports/{type} | InvestorReportController@show | yes | Investor Reports,View |  |  |
| GET | /api/investor-reports/{type}/pdf | InvestorReportController@exportPdf | yes | Investor Reports,View |  |  |
| GET | /api/journal-entries | JournalEntryController@index | yes | Accounting Reports,View |  |  |
| POST | /api/loan-cancel-requests/{cancelRequest}/approve | LoanController@approveCancelRequest | yes | **none** |  | yes |
| POST | /api/loan-cancel-requests/{cancelRequest}/reject | LoanController@rejectCancelRequest | yes | **none** |  | yes |
| PUT | /api/loan-charges/{charge} | LoanChargeController@update | yes | Active Loans,Edit |  |  |
| DELETE | /api/loan-charges/{charge} | LoanChargeController@destroy | yes | Active Loans,Delete |  |  |
| DELETE | /api/loan-diary-notes/{note} | LoanDiaryNoteController@destroy | yes | Active Loans,Delete |  |  |
| POST | /api/loan-payment-allocation-requests/{allocationRequest}/approve | LoanPaymentController@approveAllocationRequest | yes | **none** |  | yes |
| POST | /api/loan-payment-allocation-requests/{allocationRequest}/reject | LoanPaymentController@rejectAllocationRequest | yes | **none** |  |  |
| POST | /api/loan-payment-void-requests/{voidRequest}/approve | LoanPaymentController@approveVoidRequest | yes | **none** |  | yes |
| POST | /api/loan-payment-void-requests/{voidRequest}/reject | LoanPaymentController@rejectVoidRequest | yes | **none** |  |  |
| POST | /api/loan-payments/{payment}/approve-settlement | LoanPaymentController@approveSettlement | yes | **none** |  | yes |
| PATCH | /api/loan-payments/{payment}/clear-cheque | LoanPaymentController@clearCheque | yes | — | yes |  |
| POST | /api/loan-payments/{payment}/reject-settlement | LoanPaymentController@rejectSettlement | yes | **none** |  | yes |
| POST | /api/loan-payments/{payment}/void-request | LoanPaymentController@voidRequest | yes | Loan Payments,Delete |  | yes |
| PATCH | /api/loan-post-dated-cheques/{cheque} | LoanPostDatedChequeController@updateStatus | yes | — | yes |  |
| DELETE | /api/loan-post-dated-cheques/{cheque} | LoanPostDatedChequeController@destroy | yes | Loan Payments,Delete |  |  |
| GET | /api/loan-reports/{type} | LoanReportController@show | yes | Loan Reports,View |  |  |
| GET | /api/loan-reports/{type}/pdf | LoanReportController@exportPdf | yes | Loan Reports,View |  |  |
| GET | /api/loan-types | LoanTypeController@index | yes | Loan Types,View |  |  |
| POST | /api/loan-types | LoanTypeController@store | yes | Loan Types,Create |  |  |
| PUT | /api/loan-types/{loanType} | LoanTypeController@update | yes | Loan Types,Edit |  |  |
| DELETE | /api/loan-types/{loanType} | LoanTypeController@destroy | yes | Loan Types,Delete |  |  |
| POST | /api/loan-types/{loanType}/schedules/bulk-skip | LoanController@bulkSkipInstallments | yes | — | yes |  |
| GET | /api/loan-types/{loanType}/skip-dates | LoanController@skipDates | yes | — | yes |  |
| DELETE | /api/loan-waivers/{waiver} | LoanWaiverController@destroy | yes | — | yes |  |
| POST | /api/loan-write-off-requests/{writeOffRequest}/approve | LoanController@approveWriteOffRequest | yes | **none** |  | yes |
| POST | /api/loan-write-off-requests/{writeOffRequest}/reject | LoanController@rejectWriteOffRequest | yes | **none** |  | yes |
| GET | /api/loans | LoanController@index | yes | Loan Applications,View |  |  |
| POST | /api/loans | LoanController@store | yes | Loan Applications,Create |  |  |
| GET | /api/loans-disbursement | LoanController@awaitingDisbursement | yes | Loan Disbursement,View |  |  |
| GET | /api/loans-disbursement/{loan} | LoanController@show | yes | Loan Disbursement,View |  |  |
| GET | /api/loans-overdue | LoanController@overdue | yes | Overdue Loans,View |  |  |
| GET | /api/loans-provisions | LoanProvisionController@index | yes | Loan Provisioning,View |  |  |
| GET | /api/loans-status-counts | LoanController@statusCounts | yes | Active Loans,View |  |  |
| GET | /api/loans/{loan} | LoanController@show | yes | Loan Applications,View |  |  |
| PUT | /api/loans/{loan} | LoanController@update | yes | — | yes |  |
| DELETE | /api/loans/{loan} | LoanController@destroy | yes | Loan Applications,Delete |  |  |
| POST | /api/loans/{loan}/approve | LoanController@approve | yes | **none** |  |  |
| POST | /api/loans/{loan}/cancel-request | LoanController@cancelRequest | yes | Loan Applications,Delete |  | yes |
| GET | /api/loans/{loan}/charges | LoanChargeController@index | yes | Active Loans,View |  |  |
| POST | /api/loans/{loan}/charges | LoanChargeController@store | yes | Active Loans,Create |  |  |
| POST | /api/loans/{loan}/close | LoanController@close | yes | Active Loans,Edit |  |  |
| POST | /api/loans/{loan}/collateral/{collateral}/documents | LoanCollateralDocumentController@store | yes | Loan Applications,Create |  |  |
| DELETE | /api/loans/{loan}/collateral/{collateral}/documents/{document} | LoanCollateralDocumentController@destroy | yes | Loan Applications,Delete |  |  |
| GET | /api/loans/{loan}/collateral/{collateral}/documents/{document}/file | LoanCollateralDocumentController@show | yes | Loan Applications,View |  |  |
| GET | /api/loans/{loan}/diary-notes | LoanDiaryNoteController@index | yes | Active Loans,View |  |  |
| POST | /api/loans/{loan}/diary-notes | LoanDiaryNoteController@store | yes | Active Loans,Create |  |  |
| POST | /api/loans/{loan}/disburse | LoanController@disburse | yes | Loan Disbursement,Approve |  | yes |
| POST | /api/loans/{loan}/documents | LoanDocumentController@store | yes | Active Loans,Create |  |  |
| DELETE | /api/loans/{loan}/documents/{document} | LoanDocumentController@destroy | yes | Active Loans,Delete |  |  |
| GET | /api/loans/{loan}/documents/{document}/file | LoanDocumentController@show | yes | Active Loans,View |  |  |
| GET | /api/loans/{loan}/next-receipt-no | LoanPaymentController@nextReceiptNo | yes | Loan Payments,Create |  |  |
| PATCH | /api/loans/{loan}/officers | LoanController@updateOfficers | yes | Active Loans,Edit |  |  |
| GET | /api/loans/{loan}/outstanding-by-bucket | LoanPaymentController@outstandingByBucket | yes | Loan Payments,Create |  |  |
| GET | /api/loans/{loan}/payments | LoanPaymentController@index | yes | Loan Payments,View |  |  |
| POST | /api/loans/{loan}/payments | LoanPaymentController@store | yes | Loan Payments,Create |  | yes |
| GET | /api/loans/{loan}/post-dated-cheques | LoanPostDatedChequeController@index | yes | Active Loans,View |  |  |
| POST | /api/loans/{loan}/post-dated-cheques | LoanPostDatedChequeController@store | yes | Loan Payments,Create |  |  |
| POST | /api/loans/{loan}/post-provision | LoanProvisionController@post | yes | Loan Provisioning,Edit |  |  |
| GET | /api/loans/{loan}/provisions | LoanProvisionController@history | yes | Loan Provisioning,View |  |  |
| DELETE | /api/loans/{loan}/purge | LoanController@purge | yes | — | yes |  |
| POST | /api/loans/{loan}/reject | LoanController@reject | yes | **none** |  |  |
| POST | /api/loans/{loan}/request-disbursement | LoanController@requestDisbursement | yes | Loan Disbursement,Create |  | yes |
| POST | /api/loans/{loan}/reschedule | LoanController@reschedule | yes | — | yes |  |
| POST | /api/loans/{loan}/schedules/{schedule}/skip | LoanController@skipInstallment | yes | — | yes |  |
| POST | /api/loans/{loan}/settle-from-fd | FdLoanController@settle | yes | Active Loans,Approve |  | yes |
| PATCH | /api/loans/{loan}/status-flags | LoanController@updateStatusFlags | yes | Active Loans,Edit |  |  |
| GET | /api/loans/{loan}/waivers | LoanWaiverController@index | yes | Active Loans,View |  |  |
| POST | /api/loans/{loan}/waivers | LoanWaiverController@store | yes | — | yes | yes |
| POST | /api/loans/{loan}/write-off-request | LoanController@writeOffRequest | yes | Active Loans,Delete |  | yes |
| POST | /api/login | AuthController@login | **NO** | **none** |  |  |
| POST | /api/logout | AuthController@logout | yes | **none** |  |  |
| GET | /api/manual-journal-entries | ManualJournalEntryController@index | yes | Manual Journal Entries,View |  |  |
| POST | /api/manual-journal-entries | ManualJournalEntryController@store | yes | Manual Journal Entries,Create |  | yes |
| PUT | /api/manual-journal-entries/{manualJournalEntry}/approve | ManualJournalEntryController@approve | yes | **none** |  | yes |
| PUT | /api/manual-journal-entries/{manualJournalEntry}/reject | ManualJournalEntryController@reject | yes | **none** |  | yes |
| GET | /api/manual-journal-entries/account-options | ManualJournalEntryController@accountOptions | yes | Manual Journal Entries,View |  |  |
| GET | /api/me | AuthController@me | yes | **none** |  |  |
| GET | /api/pawning-article-types | PawningArticleTypeController@index | yes | Pawning Types,View |  |  |
| POST | /api/pawning-article-types | PawningArticleTypeController@store | yes | Pawning Types,Create |  |  |
| PUT | /api/pawning-article-types/{pawningArticleType} | PawningArticleTypeController@update | yes | Pawning Types,Edit |  |  |
| DELETE | /api/pawning-article-types/{pawningArticleType} | PawningArticleTypeController@destroy | yes | Pawning Types,Delete |  |  |
| POST | /api/pawning-auction-items/{pawningAuctionItem}/forfeit-surplus | PawningAuctionController@forfeitSurplus | yes | Pawning Auctions,Edit |  | yes |
| POST | /api/pawning-auction-items/{pawningAuctionItem}/sale | PawningAuctionController@recordSale | yes | Pawning Auctions,Edit |  | yes |
| POST | /api/pawning-auction-items/{pawningAuctionItem}/settle-surplus | PawningAuctionController@settleSurplus | yes | Pawning Auctions,Edit |  | yes |
| POST | /api/pawning-auction-items/{pawningAuctionItem}/unsold | PawningAuctionController@markUnsold | yes | Pawning Auctions,Edit |  | yes |
| GET | /api/pawning-auctions | PawningAuctionController@index | yes | Pawning Auctions,View |  |  |
| POST | /api/pawning-auctions | PawningAuctionController@store | yes | Pawning Auctions,Create |  |  |
| GET | /api/pawning-auctions-eligible | PawningAuctionController@eligible | yes | Pawning Auctions,View |  |  |
| GET | /api/pawning-auctions/{pawningAuction} | PawningAuctionController@show | yes | Pawning Auctions,View |  |  |
| POST | /api/pawning-auctions/{pawningAuction}/complete | PawningAuctionController@complete | yes | Pawning Auctions,Edit |  |  |
| POST | /api/pawning-auctions/{pawningAuction}/items | PawningAuctionController@addItems | yes | Pawning Auctions,Edit |  |  |
| GET | /api/pawning-gold-rates | PawningGoldRateController@index | yes | Pawning Types,View |  |  |
| POST | /api/pawning-gold-rates | PawningGoldRateController@store | yes | Pawning Types,Create |  |  |
| DELETE | /api/pawning-gold-rates/{pawningGoldRate} | PawningGoldRateController@destroy | yes | Pawning Types,Delete |  |  |
| GET | /api/pawning-gold-rates/latest | PawningGoldRateController@latest | yes | Pawnings,Create |  |  |
| POST | /api/pawning-payments/{payment}/void | PawningController@voidPayment | yes | Pawning Payments,Delete |  | yes |
| GET | /api/pawning-types | PawningTypeController@index | yes | Pawning Types,View |  |  |
| POST | /api/pawning-types | PawningTypeController@store | yes | Pawning Types,Create |  |  |
| PUT | /api/pawning-types/{pawningType} | PawningTypeController@update | yes | Pawning Types,Edit |  |  |
| DELETE | /api/pawning-types/{pawningType} | PawningTypeController@destroy | yes | Pawning Types,Delete |  |  |
| GET | /api/pawnings | PawningController@index | yes | Pawnings,View |  |  |
| POST | /api/pawnings | PawningController@store | yes | Pawnings,Create |  |  |
| GET | /api/pawnings-overdue | PawningController@overdue | yes | Pawnings,View |  |  |
| GET | /api/pawnings/{pawning} | PawningController@show | yes | Pawnings,View |  |  |
| PUT | /api/pawnings/{pawning} | PawningController@adminUpdate | yes | — | yes |  |
| POST | /api/pawnings/{pawning}/redeem | PawningController@redeem | yes | Pawning Payments,Create |  | yes |
| POST | /api/pawnings/{pawning}/renew | PawningController@renew | yes | Pawning Payments,Create |  | yes |
| GET | /api/payment-vouchers | PaymentVoucherController@index | yes | Payment Vouchers,View |  |  |
| DELETE | /api/payment-vouchers/{paymentVoucher} | PaymentVoucherController@destroy | yes | Payment Vouchers,Delete |  |  |
| GET | /api/permissions | PermissionController@index | yes | User Permission,View |  |  |
| PUT | /api/permissions/{user} | PermissionController@updateUser | yes | User Permission,Edit |  |  |
| GET | /api/petty-cash-funds | PettyCashFundController@index | yes | Petty Cash,View |  |  |
| POST | /api/petty-cash-funds | PettyCashFundController@store | yes | Petty Cash,Create |  |  |
| PUT | /api/petty-cash-funds/{pettyCashFund} | PettyCashFundController@update | yes | Petty Cash,Edit |  |  |
| DELETE | /api/petty-cash-funds/{pettyCashFund} | PettyCashFundController@destroy | yes | Petty Cash,Delete |  |  |
| GET | /api/petty-cash-replenishments | PettyCashReplenishmentController@index | yes | Petty Cash,View |  |  |
| POST | /api/petty-cash-replenishments | PettyCashReplenishmentController@store | yes | Petty Cash,Create |  | yes |
| DELETE | /api/petty-cash-replenishments/{pettyCashReplenishment} | PettyCashReplenishmentController@destroy | yes | Petty Cash,Delete |  | yes |
| GET | /api/petty-cash-vouchers | PettyCashVoucherController@index | yes | Petty Cash,View |  |  |
| POST | /api/petty-cash-vouchers | PettyCashVoucherController@store | yes | Petty Cash,Create |  | yes |
| DELETE | /api/petty-cash-vouchers/{pettyCashVoucher} | PettyCashVoucherController@destroy | yes | Petty Cash,Delete |  | yes |
| GET | /api/ping | Closure | **NO** | **none** |  |  |
| GET | /api/post-dated-cheques | LoanPostDatedChequeController@all | yes | — | yes |  |
| GET | /api/profile/achievements | UserAchievementController@index | yes | **none** |  |  |
| POST | /api/profile/achievements | UserAchievementController@store | yes | **none** |  |  |
| PUT | /api/profile/achievements/{achievement} | UserAchievementController@update | yes | **none** |  |  |
| DELETE | /api/profile/achievements/{achievement} | UserAchievementController@destroy | yes | **none** |  |  |
| GET | /api/profile/photo | ProfileController@photo | yes | **none** |  |  |
| POST | /api/profile/photo | ProfileController@storePhoto | yes | **none** |  |  |
| DELETE | /api/profile/photo | ProfileController@destroyPhoto | yes | **none** |  |  |
| GET | /api/profile/staff | ProfileController@staff | yes | **none** |  |  |
| GET | /api/roles | RoleController@index | yes | — | yes |  |
| POST | /api/roles | RoleController@store | yes | — | yes |  |
| PUT | /api/roles/{role} | RoleController@update | yes | — | yes |  |
| DELETE | /api/roles/{role} | RoleController@destroy | yes | — | yes |  |
| GET | /api/roles/{role}/permissions | PermissionController@role | yes | Role Management,View |  |  |
| PUT | /api/roles/{role}/permissions | PermissionController@updateRole | yes | Role Management,Edit |  |  |
| GET | /api/sales-executive-commissions | SalesExecutiveCommissionController@index | yes | Sales Executive Commissions,View |  |  |
| POST | /api/sales-executive-commissions/{commission}/pay | SalesExecutiveCommissionController@pay | yes | — | yes | yes |
| POST | /api/sales-executive-commissions/{commission}/void | SalesExecutiveCommissionController@void | yes | — | yes |  |
| GET | /api/savings-accounts | SavingsAccountController@index | yes | Savings Accounts,View |  |  |
| POST | /api/savings-accounts | SavingsAccountController@store | yes | Savings Accounts,Create |  | yes |
| GET | /api/savings-accounts/{savingsAccount} | SavingsAccountController@show | yes | Savings Accounts,View |  |  |
| PUT | /api/savings-accounts/{savingsAccount} | SavingsAccountController@adminUpdate | yes | — | yes |  |
| POST | /api/savings-accounts/{savingsAccount}/close | SavingsAccountController@close | yes | Savings Accounts,Edit |  | yes |
| POST | /api/savings-accounts/{savingsAccount}/deposit | SavingsAccountController@deposit | yes | Savings Accounts,Edit |  | yes |
| GET | /api/savings-accounts/{savingsAccount}/statement | SavingsAccountController@statement | yes | Savings Accounts,View |  |  |
| PATCH | /api/savings-accounts/{savingsAccount}/status | SavingsAccountController@setStatus | yes | Savings Accounts,Edit |  |  |
| POST | /api/savings-accounts/{savingsAccount}/withdraw | SavingsAccountController@withdraw | yes | Savings Accounts,Edit |  | yes |
| GET | /api/savings-accounts/next-number | SavingsAccountController@nextNumber | yes | Savings Accounts,Create |  |  |
| GET | /api/savings-collection-sheets | SavingsCollectionController@index | yes | Savings Collection,View |  |  |
| POST | /api/savings-collection-sheets | SavingsCollectionController@store | yes | Savings Collection,Create |  |  |
| GET | /api/savings-collection-sheets/{savingsCollectionSheet} | SavingsCollectionController@show | yes | Savings Collection,View |  |  |
| PUT | /api/savings-collection-sheets/{savingsCollectionSheet} | SavingsCollectionController@update | yes | Savings Collection,Edit |  |  |
| POST | /api/savings-collection-sheets/{savingsCollectionSheet}/cancel | SavingsCollectionController@cancel | yes | Savings Collection,Delete |  |  |
| POST | /api/savings-collection-sheets/{savingsCollectionSheet}/post | SavingsCollectionController@post | yes | Savings Collection,Edit |  | yes |
| GET | /api/savings-interest-preview | SavingsInterestController@preview | yes | Savings Interest,View |  |  |
| GET | /api/savings-interest-runs | SavingsInterestController@index | yes | Savings Interest,View |  |  |
| POST | /api/savings-interest-runs | SavingsInterestController@store | yes | Savings Interest,Create |  |  |
| GET | /api/savings-interest-runs/{savingsInterestRun} | SavingsInterestController@show | yes | Savings Interest,View |  |  |
| DELETE | /api/savings-interest-runs/{savingsInterestRun} | SavingsInterestController@destroy | yes | Savings Interest,Create |  |  |
| POST | /api/savings-interest-runs/{savingsInterestRun}/post | SavingsInterestController@post | yes | Savings Interest,Edit |  | yes |
| POST | /api/savings-interest-runs/{savingsInterestRun}/void | SavingsInterestController@voidRun | yes | Savings Interest,Delete |  | yes |
| GET | /api/savings-products | SavingsProductController@index | yes | Savings Products,View |  |  |
| POST | /api/savings-products | SavingsProductController@store | yes | Savings Products,Create |  |  |
| PUT | /api/savings-products/{savingsProduct} | SavingsProductController@update | yes | Savings Products,Edit |  |  |
| DELETE | /api/savings-products/{savingsProduct} | SavingsProductController@destroy | yes | Savings Products,Delete |  |  |
| GET | /api/savings-reports/{type} | SavingsReportController@show | yes | Savings Reports,View |  |  |
| GET | /api/savings-reports/{type}/pdf | SavingsReportController@exportPdf | yes | Savings Reports,View |  |  |
| POST | /api/savings-transactions/{savingsTransaction}/void | SavingsAccountController@voidTransaction | yes | Savings Accounts,Delete |  | yes |
| PUT | /api/speed-draft-collateral/{collateral} | SpeedDraftCollateralController@update | yes | Speed Drafts,Edit |  |  |
| DELETE | /api/speed-draft-collateral/{collateral} | SpeedDraftCollateralController@destroy | yes | Speed Drafts,Edit |  |  |
| POST | /api/speed-draft-collateral/{collateral}/documents | SpeedDraftCollateralDocumentController@store | yes | Speed Drafts,Edit |  |  |
| DELETE | /api/speed-draft-collateral/{collateral}/documents/{document} | SpeedDraftCollateralDocumentController@destroy | yes | Speed Drafts,Edit |  |  |
| GET | /api/speed-draft-collateral/{collateral}/documents/{document}/file | SpeedDraftCollateralDocumentController@show | yes | Speed Drafts,View |  |  |
| DELETE | /api/speed-draft-payments/{payment} | SpeedDraftPaymentController@destroy | yes | Speed Draft Payments,Delete |  | yes |
| GET | /api/speed-draft-types | SpeedDraftTypeController@index | yes | Speed Draft Types,View |  |  |
| POST | /api/speed-draft-types | SpeedDraftTypeController@store | yes | Speed Draft Types,Create |  |  |
| PUT | /api/speed-draft-types/{speedDraftType} | SpeedDraftTypeController@update | yes | Speed Draft Types,Edit |  |  |
| DELETE | /api/speed-draft-types/{speedDraftType} | SpeedDraftTypeController@destroy | yes | Speed Draft Types,Delete |  |  |
| GET | /api/speed-drafts | SpeedDraftController@index | yes | Speed Drafts,View |  |  |
| POST | /api/speed-drafts | SpeedDraftController@store | yes | Speed Drafts,Create |  |  |
| GET | /api/speed-drafts-overdue | SpeedDraftController@overdue | yes | Speed Drafts,View |  |  |
| GET | /api/speed-drafts-status-counts | SpeedDraftController@statusCounts | yes | Speed Drafts,View |  |  |
| GET | /api/speed-drafts/{speedDraft} | SpeedDraftController@show | yes | Speed Drafts,View |  |  |
| PUT | /api/speed-drafts/{speedDraft} | SpeedDraftController@update | yes | — | yes |  |
| DELETE | /api/speed-drafts/{speedDraft} | SpeedDraftController@destroy | yes | Speed Drafts,Delete |  |  |
| POST | /api/speed-drafts/{speedDraft}/approve | SpeedDraftController@approve | yes | **none** |  |  |
| GET | /api/speed-drafts/{speedDraft}/collateral | SpeedDraftCollateralController@index | yes | Speed Drafts,View |  |  |
| POST | /api/speed-drafts/{speedDraft}/collateral | SpeedDraftCollateralController@store | yes | Speed Drafts,Edit |  |  |
| POST | /api/speed-drafts/{speedDraft}/disburse | SpeedDraftController@disburse | yes | Speed Drafts,Approve |  | yes |
| GET | /api/speed-drafts/{speedDraft}/next-receipt-no | SpeedDraftPaymentController@nextReceiptNo | yes | Speed Draft Payments,Create |  |  |
| GET | /api/speed-drafts/{speedDraft}/payments | SpeedDraftPaymentController@index | yes | Speed Draft Payments,View |  |  |
| POST | /api/speed-drafts/{speedDraft}/payments | SpeedDraftPaymentController@store | yes | Speed Draft Payments,Create |  | yes |
| POST | /api/speed-drafts/{speedDraft}/reject | SpeedDraftController@reject | yes | **none** |  |  |
| POST | /api/speed-drafts/{speedDraft}/renew | SpeedDraftController@renew | yes | Speed Draft Payments,Create |  | yes |
| POST | /api/speed-drafts/{speedDraft}/request-disbursement | SpeedDraftController@requestDisbursement | yes | Speed Drafts,Create |  | yes |
| GET | /api/staff | StaffController@index | yes | Staff,View |  |  |
| POST | /api/staff | StaffController@store | yes | Staff,Create |  |  |
| PUT | /api/staff/{staff} | StaffController@update | yes | Staff,Edit |  |  |
| DELETE | /api/staff/{staff} | StaffController@destroy | yes | Staff,Delete |  |  |
| POST | /api/staff/{staff}/create-login | StaffController@createLogin | yes | Staff,Edit |  |  |
| GET | /api/staff/options | StaffController@options | yes | **none** |  |  |
| GET | /api/subscriptions | SubscriptionController@index | yes | Subscriptions,View |  |  |
| POST | /api/subscriptions | SubscriptionController@store | yes | Subscriptions,Create |  |  |
| PUT | /api/subscriptions/{subscription} | SubscriptionController@update | yes | Subscriptions,Edit |  |  |
| DELETE | /api/subscriptions/{subscription} | SubscriptionController@destroy | yes | Subscriptions,Delete |  |  |
| GET | /api/subscriptions/due-soon | SubscriptionController@dueSoon | yes | Subscriptions,View |  |  |
| POST | /api/system/clean-transactions | SystemMaintenanceController@cleanTransactions | yes | — | yes |  |
| GET | /api/undeposited-funds | UndepositedFundsController@show | yes | Bank Transactions,View |  |  |
| POST | /api/undeposited-funds/deposit | UndepositedFundsController@deposit | yes | Bank Transactions,Create |  | yes |
| GET | /api/users | UserController@index | yes | User Management,View |  |  |
| POST | /api/users | UserController@store | yes | User Management,Create |  |  |
| PUT | /api/users/{user} | UserController@update | yes | User Management,Edit |  |  |
| DELETE | /api/users/{user} | UserController@destroy | yes | User Management,Delete |  |  |
| GET | /api/users/active | UserController@active | yes | **none** |  |  |
| GET | /api/users/marketing-executives | UserController@marketingExecutives | yes | **none** |  |  |
| GET | /api/vendors | VendorController@index | yes | Vendors,View |  |  |
| POST | /api/vendors | VendorController@store | yes | Vendors,Create |  |  |
| PUT | /api/vendors/{vendor} | VendorController@update | yes | Vendors,Edit |  |  |
| DELETE | /api/vendors/{vendor} | VendorController@destroy | yes | Vendors,Delete |  |  |
