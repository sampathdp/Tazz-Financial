<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BankTransactionController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CashbookEntryController;
use App\Http\Controllers\Api\CashReconciliationController;
use App\Http\Controllers\Api\CashTransferController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FdDepositController;
use App\Http\Controllers\Api\FdInterestPaymentController;
use App\Http\Controllers\Api\FixedDepositController;
use App\Http\Controllers\Api\FundingDashboardController;
use App\Http\Controllers\Api\FundingProductController;
use App\Http\Controllers\Api\FundingReportController;
use App\Http\Controllers\Api\GeneratedNumberController;
use App\Http\Controllers\Api\LoanChargeController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LoanDiaryNoteController;
use App\Http\Controllers\Api\LoanDocumentController;
use App\Http\Controllers\Api\LoanPaymentController;
use App\Http\Controllers\Api\LoanPostDatedChequeController;
use App\Http\Controllers\Api\LoanTypeController;
use App\Http\Controllers\Api\LoanWaiverController;
use App\Http\Controllers\Api\MasterCategoryController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
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

    Route::get('dashboard', [DashboardController::class, 'index']);

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

    // --- Loan Management: Loan Applications ---
    Route::get('loans', [LoanController::class, 'index'])->middleware('perm:Loan Applications,View');
    // Flat path (not loans/overdue) so it can never collide with the loans/{loan} route below.
    Route::get('loans-overdue', [LoanController::class, 'overdue'])->middleware('perm:Overdue Loans,View');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->middleware('perm:Loan Applications,View');
    Route::post('loans', [LoanController::class, 'store'])->middleware('perm:Loan Applications,Create');
    Route::put('loans/{loan}', [LoanController::class, 'update'])->middleware('perm:Loan Applications,Edit');
    Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->middleware('perm:Loan Applications,Delete');
    Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->middleware('perm:Loan Applications,Approve');
    Route::post('loans/{loan}/reject', [LoanController::class, 'reject'])->middleware('perm:Loan Applications,Approve');

    // --- Loan Management: Active Loans ---
    Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse'])->middleware('perm:Active Loans,Approve');
    Route::patch('loans/{loan}/status-flags', [LoanController::class, 'updateStatusFlags'])->middleware('perm:Active Loans,Edit');
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
    Route::delete('loan-payments/{payment}', [LoanPaymentController::class, 'destroy'])->middleware('perm:Loan Payments,Delete');
    Route::post('loan-payments/{payment}/approve-settlement', [LoanPaymentController::class, 'approveSettlement'])->middleware('perm:Loan Payments,Approve');
    Route::post('loan-payments/{payment}/reject-settlement', [LoanPaymentController::class, 'rejectSettlement'])->middleware('perm:Loan Payments,Approve');
    // Admin-only — realizing/bouncing a cheque is a Loan Adjustments action, not a regular Loan Payments one.
    Route::patch('loan-payments/{payment}/clear-cheque', [LoanPaymentController::class, 'clearCheque'])->middleware('admin');

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

    // --- Masters: shared lookup/category lists (Expense Categories, ...) ---
    Route::get('master-categories', [MasterCategoryController::class, 'index'])->middleware('perm:Masters,View');
    Route::post('master-categories', [MasterCategoryController::class, 'store'])->middleware('perm:Masters,Create');
    Route::put('master-categories/{masterCategory}', [MasterCategoryController::class, 'update'])->middleware('perm:Masters,Edit');
    Route::delete('master-categories/{masterCategory}', [MasterCategoryController::class, 'destroy'])->middleware('perm:Masters,Delete');

    // --- Masters: Banks — a dedicated table (not master_categories), used
    // everywhere a "Bank Name" field is picked (Bank Accounts, Loan Payments/
    // Cheques, Fixed Deposits) — see frontend's BankSelect component. ---
    Route::get('banks', [BankController::class, 'index'])->middleware('perm:Masters,View');
    Route::post('banks', [BankController::class, 'store'])->middleware('perm:Masters,Create');
    Route::put('banks/{bank}', [BankController::class, 'update'])->middleware('perm:Masters,Edit');
    Route::delete('banks/{bank}', [BankController::class, 'destroy'])->middleware('perm:Masters,Delete');

    // --- Funding Management: Funding Products ---
    Route::get('funding-products', [FundingProductController::class, 'index'])->middleware('perm:Funding Products,View');
    Route::post('funding-products', [FundingProductController::class, 'store'])->middleware('perm:Funding Products,Create');
    Route::put('funding-products/{fundingProduct}', [FundingProductController::class, 'update'])->middleware('perm:Funding Products,Edit');
    Route::delete('funding-products/{fundingProduct}', [FundingProductController::class, 'destroy'])->middleware('perm:Funding Products,Delete');

    // --- Funding Management: Deposits (cash-in, before an FD exists) ---
    Route::get('fd-deposits', [FdDepositController::class, 'index'])->middleware('perm:Deposits,View');
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
    Route::get('funding-dashboard', [FundingDashboardController::class, 'index'])->middleware('perm:Funding Reports,View');
});
