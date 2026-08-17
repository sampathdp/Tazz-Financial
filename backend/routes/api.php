<?php

use App\Http\Controllers\Api\AccountingReportController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\ApprovalTypeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BankTransactionController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CashBankReportController;
use App\Http\Controllers\Api\CashbookEntryController;
use App\Http\Controllers\Api\CashReconciliationController;
use App\Http\Controllers\Api\CashTransferController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\CollectionReportController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDocumentController;
use App\Http\Controllers\Api\CustomerReportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PaymentVoucherController;
use App\Http\Controllers\Api\PettyCashFundController;
use App\Http\Controllers\Api\PettyCashReplenishmentController;
use App\Http\Controllers\Api\PettyCashVoucherController;
use App\Http\Controllers\Api\ExpenseReportController;
use App\Http\Controllers\Api\FinanceCalculatorController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\FdDepositController;
use App\Http\Controllers\Api\FdInterestPaymentController;
use App\Http\Controllers\Api\FixedDepositController;
use App\Http\Controllers\Api\FundingDashboardController;
use App\Http\Controllers\Api\FundingProductController;
use App\Http\Controllers\Api\FundingReportController;
use App\Http\Controllers\Api\GeneratedNumberController;
use App\Http\Controllers\Api\InvestorReportController;
use App\Http\Controllers\Api\LoanChargeController;
use App\Http\Controllers\Api\LoanCollateralDocumentController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LoanDiaryNoteController;
use App\Http\Controllers\Api\LoanDocumentController;
use App\Http\Controllers\Api\LoanPaymentController;
use App\Http\Controllers\Api\LoanPostDatedChequeController;
use App\Http\Controllers\Api\LoanReportController;
use App\Http\Controllers\Api\LoanTypeController;
use App\Http\Controllers\Api\LoanWaiverController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalesExecutiveCommissionController;
use App\Http\Controllers\Api\SpeedDraftCollateralController;
use App\Http\Controllers\Api\SpeedDraftCollateralDocumentController;
use App\Http\Controllers\Api\SpeedDraftController;
use App\Http\Controllers\Api\SpeedDraftPaymentController;
use App\Http\Controllers\Api\SpeedDraftTypeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VendorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tazz Investment & Credit API routes
|--------------------------------------------------------------------------
| Public: login only (with strict throttle).
| Everything else requires a valid Sanctum bearer token, plus a
| perm:{Module},{Action} gate on every write/read that isn't universally
| available to all authenticated users.
*/

// --- Public auth ---
Route::middleware('throttle:' . config('ratelimit.login') . ',1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// --- Protected ---
Route::middleware(['auth:sanctum', 'throttle:' . config('ratelimit.api') . ',1'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('perm:Dashboard,View');

    // --- Approvals inbox (admin-only, see ApprovalController) ---
    Route::get('approvals', [ApprovalController::class, 'index'])->middleware('admin');

    // --- Approval Types registry (admin-only, see ApprovalTypeController) ---
    Route::get('approval-types', [ApprovalTypeController::class, 'index'])->middleware('admin');
    Route::post('approval-types', [ApprovalTypeController::class, 'store'])->middleware('admin');
    Route::put('approval-types/{approvalType}', [ApprovalTypeController::class, 'update'])->middleware('admin');
    Route::delete('approval-types/{approvalType}', [ApprovalTypeController::class, 'destroy'])->middleware('admin');
    Route::put('approval-types/{approvalType}/approvers', [ApprovalTypeController::class, 'updateApprovers'])->middleware('admin');

    // --- Branch Management ---
    Route::get('branches', [BranchController::class, 'index'])->middleware('perm:Branch Management,View');
    Route::post('branches', [BranchController::class, 'store'])->middleware('perm:Branch Management,Create');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('perm:Branch Management,Edit');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('perm:Branch Management,Delete');

    // --- Role Management (structural/security-sensitive: admin-only) ---
    Route::middleware('admin')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);
    });
    Route::get('roles/{role}/permissions', [PermissionController::class, 'role'])->middleware('perm:Role Management,View');
    Route::put('roles/{role}/permissions', [PermissionController::class, 'updateRole'])->middleware('perm:Role Management,Edit');

    // --- User Management ---
    // Sales-executive picklist for Loan/Pawning "assign officer" dropdowns —
    // open to any authenticated user (see UserController::marketingExecutives()).
    Route::get('users/marketing-executives', [UserController::class, 'marketingExecutives']);
    Route::get('users/active', [UserController::class, 'active']);
    Route::get('users', [UserController::class, 'index'])->middleware('perm:User Management,View');
    Route::post('users', [UserController::class, 'store'])->middleware('perm:User Management,Create');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('perm:User Management,Edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('perm:User Management,Delete');

    // --- User Permission (per-user overrides) ---
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('perm:User Permission,View');
    Route::put('permissions/{user}', [PermissionController::class, 'updateUser'])->middleware('perm:User Permission,Edit');

    // --- Customer Management ---
    Route::get('customers', [CustomerController::class, 'index'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}/loans', [CustomerController::class, 'loans'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}/funding', [CustomerController::class, 'funding'])->middleware('perm:Customer Management,View');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('perm:Customer Management,Create');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('perm:Customer Management,Edit');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('perm:Customer Management,Delete');

    // --- Customer Documents ---
    Route::get('customers/{customer}/documents/{document}/file', [CustomerDocumentController::class, 'show'])->middleware('perm:Customer Management,View');
    Route::post('customers/{customer}/documents', [CustomerDocumentController::class, 'store'])->middleware('perm:Customer Management,Create');
    Route::delete('customers/{customer}/documents/{document}', [CustomerDocumentController::class, 'destroy'])->middleware('perm:Customer Management,Delete');

    // --- Loan Management: Loan Types ---
    Route::get('loan-types', [LoanTypeController::class, 'index'])->middleware('perm:Loan Types,View');
    Route::post('loan-types', [LoanTypeController::class, 'store'])->middleware('perm:Loan Types,Create');
    Route::put('loan-types/{loanType}', [LoanTypeController::class, 'update'])->middleware('perm:Loan Types,Edit');
    Route::delete('loan-types/{loanType}', [LoanTypeController::class, 'destroy'])->middleware('perm:Loan Types,Delete');

    // --- Document Tracking: generated reference numbers ---
    Route::get('generated-numbers', [GeneratedNumberController::class, 'index'])->middleware('perm:Document Tracking,View');

    // --- Leasing / Loan Calculator: standalone quote, never persists ---
    Route::post('finance-calculator/calculate', [FinanceCalculatorController::class, 'calculate'])->middleware('perm:Loan Calculator,View');

    // --- Loan Management: Loan Applications ---
    Route::get('loans', [LoanController::class, 'index'])->middleware('perm:Loan Applications,View');
    // Flat path (not loans/overdue) so it can never collide with the loans/{loan} route below.
    Route::get('loans-overdue', [LoanController::class, 'overdue'])->middleware('perm:Overdue Loans,View');
    Route::get('loan-reports/{type}', [LoanReportController::class, 'show'])->middleware('perm:Loan Reports,View');
    Route::get('loan-reports/{type}/pdf', [LoanReportController::class, 'exportPdf'])->middleware('perm:Loan Reports,View');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->middleware('perm:Loan Applications,View');
    Route::post('loans', [LoanController::class, 'store'])->middleware('perm:Loan Applications,Create');
    Route::put('loans/{loan}', [LoanController::class, 'update'])->middleware('perm:Loan Applications,Edit');
    Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->middleware('perm:Loan Applications,Delete');
    Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->middleware('perm:Loan Applications,Approve');
    Route::post('loans/{loan}/reject', [LoanController::class, 'reject'])->middleware('perm:Loan Applications,Approve');
    Route::post('loans/{loan}/cancel-request', [LoanController::class, 'cancelRequest'])->middleware('perm:Loan Applications,Delete');
    // approve/reject gated inline by ApprovalType::isAuthorized('loan_cancel', ...) — see LoanController — not
    // the module permission grid, so who may approve is admin-manageable data, not a hardcoded permission module.
    Route::post('loan-cancel-requests/{cancelRequest}/approve', [LoanController::class, 'approveCancelRequest']);
    Route::post('loan-cancel-requests/{cancelRequest}/reject', [LoanController::class, 'rejectCancelRequest']);

    // --- Loan Management: Active Loans ---
    Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse'])->middleware('perm:Active Loans,Approve');
    Route::patch('loans/{loan}/status-flags', [LoanController::class, 'updateStatusFlags'])->middleware('perm:Active Loans,Edit');
    Route::patch('loans/{loan}/officers', [LoanController::class, 'updateOfficers'])->middleware('perm:Active Loans,Edit');
    // Admin-only — see the frontend's "Loan Adjustments" screen (installment skip / cheque clearance / waivers).
    Route::post('loans/{loan}/schedules/{schedule}/skip', [LoanController::class, 'skipInstallment'])->middleware('admin');
    // Bulk installment skip, by loan type — skips the matching due-date installment
    // for every active loan of that type in one go (e.g. a public holiday).
    Route::get('loan-types/{loanType}/skip-dates', [LoanController::class, 'skipDates'])->middleware('admin');
    Route::post('loan-types/{loanType}/schedules/bulk-skip', [LoanController::class, 'bulkSkipInstallments'])->middleware('admin');

    // --- Loan Management: Loan Payments ---
    Route::get('loans/{loan}/payments', [LoanPaymentController::class, 'index'])->middleware('perm:Loan Payments,View');
    Route::get('loans/{loan}/next-receipt-no', [LoanPaymentController::class, 'nextReceiptNo'])->middleware('perm:Loan Payments,Create');
    Route::post('loans/{loan}/payments', [LoanPaymentController::class, 'store'])->middleware('perm:Loan Payments,Create');
    Route::post('loan-payments/{payment}/void-request', [LoanPaymentController::class, 'voidRequest'])->middleware('perm:Loan Payments,Delete');
    // approve/reject gated inline by ApprovalType::isAuthorized('payment_void', ...) — see LoanPaymentController.
    Route::post('loan-payment-void-requests/{voidRequest}/approve', [LoanPaymentController::class, 'approveVoidRequest']);
    Route::post('loan-payment-void-requests/{voidRequest}/reject', [LoanPaymentController::class, 'rejectVoidRequest']);
    Route::post('loan-payments/{payment}/approve-settlement', [LoanPaymentController::class, 'approveSettlement'])->middleware('perm:Loan Payments,Approve');
    Route::post('loan-payments/{payment}/reject-settlement', [LoanPaymentController::class, 'rejectSettlement'])->middleware('perm:Loan Payments,Approve');
    // Admin-only — realizing/bouncing a cheque is a Loan Adjustments action, not a regular Loan Payments one.
    Route::patch('loan-payments/{payment}/clear-cheque', [LoanPaymentController::class, 'clearCheque'])->middleware('admin');

    Route::get('collection-reports/{type}', [CollectionReportController::class, 'show'])->middleware('perm:Collection Reports,View');
    Route::get('collection-reports/{type}/pdf', [CollectionReportController::class, 'exportPdf'])->middleware('perm:Collection Reports,View');

    // --- Loan Management: Sales Executive Commissions ---
    Route::get('sales-executive-commissions', [SalesExecutiveCommissionController::class, 'index'])->middleware('perm:Sales Executive Commissions,View');
    // Admin-only, like other one-shot financial-payout actions (clear-cheque, waivers) — bypasses the permission grid entirely.
    Route::post('sales-executive-commissions/{commission}/pay', [SalesExecutiveCommissionController::class, 'pay'])->middleware('admin');

    // --- Loan Management: Charges (Document/Legal/Valuation/Other due-type breakdown) ---
    Route::get('loans/{loan}/charges', [LoanChargeController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/charges', [LoanChargeController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::put('loan-charges/{charge}', [LoanChargeController::class, 'update'])->middleware('perm:Active Loans,Edit');
    Route::delete('loan-charges/{charge}', [LoanChargeController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Post Dated Cheques ---
    // Recorded from the Payment Receipt screen (Loan Payments module), not Active Loans —
    // a cheque is a record only and never touches the loan balance until realized.
    // The cross-loan list and the Realize/Bounce action are Loan Adjustments (admin-only);
    // the per-loan read-only list and recording a new cheque stay permission-based.
    Route::get('post-dated-cheques', [LoanPostDatedChequeController::class, 'all'])->middleware('admin');
    Route::get('loans/{loan}/post-dated-cheques', [LoanPostDatedChequeController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/post-dated-cheques', [LoanPostDatedChequeController::class, 'store'])->middleware('perm:Loan Payments,Create');
    Route::patch('loan-post-dated-cheques/{cheque}', [LoanPostDatedChequeController::class, 'updateStatus'])->middleware('admin');
    Route::delete('loan-post-dated-cheques/{cheque}', [LoanPostDatedChequeController::class, 'destroy'])->middleware('perm:Loan Payments,Delete');

    // --- Loan Management: Contract Images / Documents ---
    Route::get('loans/{loan}/documents/{document}/file', [LoanDocumentController::class, 'show'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/documents', [LoanDocumentController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::delete('loans/{loan}/documents/{document}', [LoanDocumentController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Collateral Documents (e.g. vehicle photos, RC book copy) ---
    Route::get('loans/{loan}/collateral/{collateral}/documents/{document}/file', [LoanCollateralDocumentController::class, 'show'])->middleware('perm:Loan Applications,View');
    Route::post('loans/{loan}/collateral/{collateral}/documents', [LoanCollateralDocumentController::class, 'store'])->middleware('perm:Loan Applications,Create');
    Route::delete('loans/{loan}/collateral/{collateral}/documents/{document}', [LoanCollateralDocumentController::class, 'destroy'])->middleware('perm:Loan Applications,Delete');

    // --- Loan Management: Diarizing (follow-up notes) ---
    Route::get('loans/{loan}/diary-notes', [LoanDiaryNoteController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/diary-notes', [LoanDiaryNoteController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::delete('loan-diary-notes/{note}', [LoanDiaryNoteController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Waivers (W-Off Set Plan / Waved Off Details) ---
    // Viewing stays permission-based (the loan detail page shows them read-only);
    // recording/removing a waiver is a Loan Adjustments action (admin-only).
    Route::get('loans/{loan}/waivers', [LoanWaiverController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/waivers', [LoanWaiverController::class, 'store'])->middleware('admin');
    Route::delete('loan-waivers/{waiver}', [LoanWaiverController::class, 'destroy'])->middleware('admin');

    // --- Speed Drafts: Types ---
    Route::get('speed-draft-types', [SpeedDraftTypeController::class, 'index'])->middleware('perm:Speed Draft Types,View');
    Route::post('speed-draft-types', [SpeedDraftTypeController::class, 'store'])->middleware('perm:Speed Draft Types,Create');
    Route::put('speed-draft-types/{speedDraftType}', [SpeedDraftTypeController::class, 'update'])->middleware('perm:Speed Draft Types,Edit');
    Route::delete('speed-draft-types/{speedDraftType}', [SpeedDraftTypeController::class, 'destroy'])->middleware('perm:Speed Draft Types,Delete');

    // --- Speed Drafts: Drafts (Loans / Mortgages / Gold Pawning / Leases, interest-only repayment) ---
    Route::get('speed-drafts', [SpeedDraftController::class, 'index'])->middleware('perm:Speed Drafts,View');
    // Flat path (not speed-drafts/overdue) so it can never collide with the speed-drafts/{speedDraft} route below.
    Route::get('speed-drafts-overdue', [SpeedDraftController::class, 'overdue'])->middleware('perm:Speed Drafts,View');
    Route::get('speed-drafts/{speedDraft}', [SpeedDraftController::class, 'show'])->middleware('perm:Speed Drafts,View');
    Route::post('speed-drafts', [SpeedDraftController::class, 'store'])->middleware('perm:Speed Drafts,Create');
    Route::put('speed-drafts/{speedDraft}', [SpeedDraftController::class, 'update'])->middleware('perm:Speed Drafts,Edit');
    Route::delete('speed-drafts/{speedDraft}', [SpeedDraftController::class, 'destroy'])->middleware('perm:Speed Drafts,Delete');
    Route::post('speed-drafts/{speedDraft}/approve', [SpeedDraftController::class, 'approve'])->middleware('perm:Speed Drafts,Approve');
    Route::post('speed-drafts/{speedDraft}/reject', [SpeedDraftController::class, 'reject'])->middleware('perm:Speed Drafts,Approve');
    Route::post('speed-drafts/{speedDraft}/disburse', [SpeedDraftController::class, 'disburse'])->middleware('perm:Speed Drafts,Approve');

    // --- Speed Drafts: Collateral (Gold Pawning / Lease asset records) ---
    Route::get('speed-drafts/{speedDraft}/collateral', [SpeedDraftCollateralController::class, 'index'])->middleware('perm:Speed Drafts,View');
    Route::post('speed-drafts/{speedDraft}/collateral', [SpeedDraftCollateralController::class, 'store'])->middleware('perm:Speed Drafts,Edit');
    Route::put('speed-draft-collateral/{collateral}', [SpeedDraftCollateralController::class, 'update'])->middleware('perm:Speed Drafts,Edit');
    Route::delete('speed-draft-collateral/{collateral}', [SpeedDraftCollateralController::class, 'destroy'])->middleware('perm:Speed Drafts,Edit');

    // --- Speed Drafts: Collateral Documents (e.g. gold item photos) ---
    Route::get('speed-draft-collateral/{collateral}/documents/{document}/file', [SpeedDraftCollateralDocumentController::class, 'show'])->middleware('perm:Speed Drafts,View');
    Route::post('speed-draft-collateral/{collateral}/documents', [SpeedDraftCollateralDocumentController::class, 'store'])->middleware('perm:Speed Drafts,Edit');
    Route::delete('speed-draft-collateral/{collateral}/documents/{document}', [SpeedDraftCollateralDocumentController::class, 'destroy'])->middleware('perm:Speed Drafts,Edit');

    // --- Speed Drafts: Payments (interest / capital-reduction / full settlement) ---
    Route::get('speed-drafts/{speedDraft}/payments', [SpeedDraftPaymentController::class, 'index'])->middleware('perm:Speed Draft Payments,View');
    Route::get('speed-drafts/{speedDraft}/next-receipt-no', [SpeedDraftPaymentController::class, 'nextReceiptNo'])->middleware('perm:Speed Draft Payments,Create');
    Route::post('speed-drafts/{speedDraft}/payments', [SpeedDraftPaymentController::class, 'store'])->middleware('perm:Speed Draft Payments,Create');
    Route::delete('speed-draft-payments/{payment}', [SpeedDraftPaymentController::class, 'destroy'])->middleware('perm:Speed Draft Payments,Delete');

    // --- Bank Management: Bank Accounts ---
    Route::get('bank-accounts', [BankAccountController::class, 'index'])->middleware('perm:Bank Accounts,View');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->middleware('perm:Bank Accounts,Create');
    Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->middleware('perm:Bank Accounts,Edit');
    Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->middleware('perm:Bank Accounts,Delete');

    // --- Bank Management: Bank Transactions (immutable ledger entries — void by deleting, never edited) ---
    Route::get('bank-transactions', [BankTransactionController::class, 'index'])->middleware('perm:Bank Transactions,View');
    Route::post('bank-transactions', [BankTransactionController::class, 'store'])->middleware('perm:Bank Transactions,Create');
    Route::delete('bank-transactions/{bankTransaction}', [BankTransactionController::class, 'destroy'])->middleware('perm:Bank Transactions,Delete');

    // --- Bank Management: Bank Reconciliation ---
    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index'])->middleware('perm:Bank Reconciliation,View');
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store'])->middleware('perm:Bank Reconciliation,Create');
    Route::put('bank-reconciliations/{bankReconciliation}', [BankReconciliationController::class, 'update'])->middleware('perm:Bank Reconciliation,Edit');
    Route::delete('bank-reconciliations/{bankReconciliation}', [BankReconciliationController::class, 'destroy'])->middleware('perm:Bank Reconciliation,Delete');

    // --- Cashbook Management: Cash Accounts + Cashbook Entries (single "Cashbook" screen/permission) ---
    Route::get('cash-accounts', [CashAccountController::class, 'index'])->middleware('perm:Cashbook,View');
    Route::post('cash-accounts', [CashAccountController::class, 'store'])->middleware('perm:Cashbook,Create');
    Route::put('cash-accounts/{cashAccount}', [CashAccountController::class, 'update'])->middleware('perm:Cashbook,Edit');
    Route::delete('cash-accounts/{cashAccount}', [CashAccountController::class, 'destroy'])->middleware('perm:Cashbook,Delete');

    Route::get('cashbook-entries', [CashbookEntryController::class, 'index'])->middleware('perm:Cashbook,View');
    Route::post('cashbook-entries', [CashbookEntryController::class, 'store'])->middleware('perm:Cashbook,Create');
    Route::delete('cashbook-entries/{cashbookEntry}', [CashbookEntryController::class, 'destroy'])->middleware('perm:Cashbook,Delete');

    // --- Cashbook Management: Cash Transfers (between cash accounts, requires approval) ---
    Route::get('cash-transfers', [CashTransferController::class, 'index'])->middleware('perm:Cash Transfers,View');
    Route::post('cash-transfers', [CashTransferController::class, 'store'])->middleware('perm:Cash Transfers,Create');
    Route::put('cash-transfers/{cashTransfer}/approve', [CashTransferController::class, 'approve'])->middleware('perm:Cash Transfers,Approve');
    Route::put('cash-transfers/{cashTransfer}/reject', [CashTransferController::class, 'reject'])->middleware('perm:Cash Transfers,Approve');
    Route::delete('cash-transfers/{cashTransfer}', [CashTransferController::class, 'destroy'])->middleware('perm:Cash Transfers,Delete');

    // --- Cashbook Management: Cash Reconciliation (branch-level daily cash-in-hand entry) ---
    Route::get('cash-reconciliations', [CashReconciliationController::class, 'index'])->middleware('perm:Cash Reconciliation,View');
    Route::get('cash-reconciliations/summary', [CashReconciliationController::class, 'summary'])->middleware('perm:Cash Reconciliation,View');
    Route::post('cash-reconciliations', [CashReconciliationController::class, 'store'])->middleware('perm:Cash Reconciliation,Create');
    Route::delete('cash-reconciliations/{cashReconciliation}', [CashReconciliationController::class, 'destroy'])->middleware('perm:Cash Reconciliation,Delete');

    // --- Masters: Expense Categories — a dedicated table, used by the
    // Expense Management module's category picker. ---
    Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('perm:Masters,View');
    Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('perm:Masters,Create');
    Route::put('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'update'])->middleware('perm:Masters,Edit');
    Route::delete('expense-categories/{expenseCategory}', [ExpenseCategoryController::class, 'destroy'])->middleware('perm:Masters,Delete');

    // --- Masters: Banks — a dedicated table, used
    // everywhere a "Bank Name" field is picked (Bank Accounts, Loan Payments/
    // Cheques, Fixed Deposits) — see frontend's BankSelect component. ---
    Route::get('banks', [BankController::class, 'index'])->middleware('perm:Masters,View');
    Route::post('banks', [BankController::class, 'store'])->middleware('perm:Masters,Create');
    Route::put('banks/{bank}', [BankController::class, 'update'])->middleware('perm:Masters,Edit');
    Route::delete('banks/{bank}', [BankController::class, 'destroy'])->middleware('perm:Masters,Delete');

    // --- Masters: Document Categories — used by Loan Contract Images ---
    Route::get('document-categories', [\App\Http\Controllers\Api\DocumentCategoryController::class, 'index'])->middleware('perm:Masters,View');
    Route::post('document-categories', [\App\Http\Controllers\Api\DocumentCategoryController::class, 'store'])->middleware('perm:Masters,Create');
    Route::put('document-categories/{documentCategory}', [\App\Http\Controllers\Api\DocumentCategoryController::class, 'update'])->middleware('perm:Masters,Edit');
    Route::delete('document-categories/{documentCategory}', [\App\Http\Controllers\Api\DocumentCategoryController::class, 'destroy'])->middleware('perm:Masters,Delete');

    Route::get('charge-categories', [\App\Http\Controllers\Api\ChargeCategoryController::class, 'index'])->middleware('perm:Masters,View');
    Route::post('charge-categories', [\App\Http\Controllers\Api\ChargeCategoryController::class, 'store'])->middleware('perm:Masters,Create');
    Route::put('charge-categories/{chargeCategory}', [\App\Http\Controllers\Api\ChargeCategoryController::class, 'update'])->middleware('perm:Masters,Edit');
    Route::delete('charge-categories/{chargeCategory}', [\App\Http\Controllers\Api\ChargeCategoryController::class, 'destroy'])->middleware('perm:Masters,Delete');

    // --- Funding Management: Funding Products ---
    Route::get('funding-products', [FundingProductController::class, 'index'])->middleware('perm:Funding Products,View');
    Route::post('funding-products', [FundingProductController::class, 'store'])->middleware('perm:Funding Products,Create');
    Route::put('funding-products/{fundingProduct}', [FundingProductController::class, 'update'])->middleware('perm:Funding Products,Edit');
    Route::delete('funding-products/{fundingProduct}', [FundingProductController::class, 'destroy'])->middleware('perm:Funding Products,Delete');

    // --- Funding Management: Deposits (cash-in, before an FD exists) ---
    Route::get('fd-deposits', [FdDepositController::class, 'index'])->middleware('perm:Deposits,View');
    Route::get('fd-deposits/next-number', [FdDepositController::class, 'nextDepositNo'])->middleware('perm:Deposits,Create');
    Route::get('fd-deposits/{fdDeposit}', [FdDepositController::class, 'show'])->middleware('perm:Deposits,View');
    Route::post('fd-deposits', [FdDepositController::class, 'store'])->middleware('perm:Deposits,Create');
    Route::delete('fd-deposits/{fdDeposit}', [FdDepositController::class, 'destroy'])->middleware('perm:Deposits,Delete');

    // --- Funding Management: Fixed Deposits ---
    Route::get('fixed-deposits', [FixedDepositController::class, 'index'])->middleware('perm:Fixed Deposits,View');
    Route::get('fixed-deposits/{fixedDeposit}', [FixedDepositController::class, 'show'])->middleware('perm:Fixed Deposits,View');
    Route::post('fixed-deposits', [FixedDepositController::class, 'store'])->middleware('perm:Fixed Deposits,Create');
    Route::post('fixed-deposits/{fixedDeposit}/renew', [FixedDepositController::class, 'renew'])->middleware('perm:Fixed Deposits,Edit');
    Route::post('fixed-deposits/{fixedDeposit}/withdraw', [FixedDepositController::class, 'withdraw'])->middleware('perm:Fixed Deposits,Edit');

    // --- Funding Management: Monthly Interest Payments ---
    Route::get('fixed-deposits/{fixedDeposit}/interest-payments', [FdInterestPaymentController::class, 'index'])->middleware('perm:Fixed Deposits,View');
    Route::post('fd-interest-payments/{fdInterestPayment}/pay', [FdInterestPaymentController::class, 'pay'])->middleware('perm:Fixed Deposits,Edit');

    // --- Funding Management: Reports & Dashboard ---
    Route::get('funding-reports/{type}', [FundingReportController::class, 'show'])->middleware('perm:Funding Reports,View');
    Route::get('funding-reports/{type}/pdf', [FundingReportController::class, 'exportPdf'])->middleware('perm:Funding Reports,View');
    Route::get('funding-dashboard', [FundingDashboardController::class, 'index'])->middleware('perm:Funding Reports,View');

    // --- Reports: Customer / Investor / Cash & Bank / Expense ---
    Route::get('customer-reports/{type}', [CustomerReportController::class, 'show'])->middleware('perm:Customer Reports,View');
    Route::get('customer-reports/{type}/pdf', [CustomerReportController::class, 'exportPdf'])->middleware('perm:Customer Reports,View');
    Route::get('investor-reports/{type}', [InvestorReportController::class, 'show'])->middleware('perm:Investor Reports,View');
    Route::get('investor-reports/{type}/pdf', [InvestorReportController::class, 'exportPdf'])->middleware('perm:Investor Reports,View');
    Route::get('cash-bank-reports/{type}', [CashBankReportController::class, 'show'])->middleware('perm:Cash & Bank Reports,View');
    Route::get('cash-bank-reports/{type}/pdf', [CashBankReportController::class, 'exportPdf'])->middleware('perm:Cash & Bank Reports,View');
    Route::get('expense-reports/{type}', [ExpenseReportController::class, 'show'])->middleware('perm:Expense Reports,View');
    Route::get('expense-reports/{type}/pdf', [ExpenseReportController::class, 'exportPdf'])->middleware('perm:Expense Reports,View');

    // --- Accounting: Chart of Accounts + Trial Balance / General Ledger ---
    Route::get('chart-of-accounts', [ChartOfAccountController::class, 'index'])->middleware('perm:Chart of Accounts,View');
    Route::post('chart-of-accounts', [ChartOfAccountController::class, 'store'])->middleware('perm:Chart of Accounts,Create');
    Route::put('chart-of-accounts/{chartOfAccount}', [ChartOfAccountController::class, 'update'])->middleware('perm:Chart of Accounts,Edit');
    Route::get('accounting-reports/{type}', [AccountingReportController::class, 'show'])->middleware('perm:Accounting Reports,View');
    Route::get('accounting-reports/{type}/pdf', [AccountingReportController::class, 'exportPdf'])->middleware('perm:Accounting Reports,View');
    Route::get('journal-entries', [JournalEntryController::class, 'index'])->middleware('perm:Accounting Reports,View');

    // --- Expense Management: Vendors ---
    Route::get('vendors', [VendorController::class, 'index'])->middleware('perm:Vendors,View');
    Route::post('vendors', [VendorController::class, 'store'])->middleware('perm:Vendors,Create');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('perm:Vendors,Edit');
    Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->middleware('perm:Vendors,Delete');

    // --- Expense Management: Expenses (request -> approve/reject -> pay) ---
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('perm:Expenses,View');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('perm:Expenses,Create');
    Route::put('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('perm:Expenses,Approve');
    Route::put('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('perm:Expenses,Approve');
    Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->middleware('perm:Expenses,Edit');

    // --- Expense Management: Payment Vouchers (auto-created by ExpenseController::pay — view/void only, see PaymentVoucherController) ---
    Route::get('payment-vouchers', [PaymentVoucherController::class, 'index'])->middleware('perm:Payment Vouchers,View');
    Route::delete('payment-vouchers/{paymentVoucher}', [PaymentVoucherController::class, 'destroy'])->middleware('perm:Payment Vouchers,Delete');

    // --- Petty Cash: Funds (imprest float per branch, custodian, ceiling) ---
    Route::get('petty-cash-funds', [PettyCashFundController::class, 'index'])->middleware('perm:Petty Cash,View');
    Route::post('petty-cash-funds', [PettyCashFundController::class, 'store'])->middleware('perm:Petty Cash,Create');
    Route::put('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'update'])->middleware('perm:Petty Cash,Edit');
    Route::delete('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'destroy'])->middleware('perm:Petty Cash,Delete');

    // --- Petty Cash: Replenishments (top the float back up from a cash/bank account) ---
    Route::get('petty-cash-replenishments', [PettyCashReplenishmentController::class, 'index'])->middleware('perm:Petty Cash,View');
    Route::post('petty-cash-replenishments', [PettyCashReplenishmentController::class, 'store'])->middleware('perm:Petty Cash,Create');
    Route::delete('petty-cash-replenishments/{pettyCashReplenishment}', [PettyCashReplenishmentController::class, 'destroy'])->middleware('perm:Petty Cash,Delete');

    // --- Petty Cash: Vouchers (small receipt-level spend against the float) ---
    Route::get('petty-cash-vouchers', [PettyCashVoucherController::class, 'index'])->middleware('perm:Petty Cash,View');
    Route::post('petty-cash-vouchers', [PettyCashVoucherController::class, 'store'])->middleware('perm:Petty Cash,Create');
    Route::delete('petty-cash-vouchers/{pettyCashVoucher}', [PettyCashVoucherController::class, 'destroy'])->middleware('perm:Petty Cash,Delete');
});
