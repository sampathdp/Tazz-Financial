<?php

use App\Http\Controllers\Api\AccountingReportController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\ApprovalTypeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BankController;
use App\Http\Controllers\Api\BankReconciliationController;
use App\Http\Controllers\Api\BankTransactionController;
use App\Http\Controllers\Api\UndepositedFundsController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CashAccountController;
use App\Http\Controllers\Api\CashBankReportController;
use App\Http\Controllers\Api\CashbookEntryController;
use App\Http\Controllers\Api\CashReconciliationController;
use App\Http\Controllers\Api\CompanyProfileController;
use App\Http\Controllers\Api\ChartOfAccountController;
use App\Http\Controllers\Api\CollectionReportController;
use App\Http\Controllers\Api\BlacklistController;
use App\Http\Controllers\Api\CustomerBankDetailController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerCribReportController;
use App\Http\Controllers\Api\CustomerDocumentController;
use App\Http\Controllers\Api\CustomerPhotoController;
use App\Http\Controllers\Api\CustomerReportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\PaymentVoucherController;
use App\Http\Controllers\Api\PettyCashFundController;
use App\Http\Controllers\Api\PettyCashReplenishmentController;
use App\Http\Controllers\Api\PettyCashVoucherController;
use App\Http\Controllers\Api\ExpenseReportController;
use App\Http\Controllers\Api\CashFloatController;
use App\Http\Controllers\Api\FinanceAuditController;
use App\Http\Controllers\Api\FinanceCalculatorController;
use App\Http\Controllers\Api\FinanceSettingController;
use App\Http\Controllers\Api\FinanceDashboardController;
use App\Http\Controllers\Api\FinanceReportController;
use App\Http\Controllers\Api\FinanceRequestController;
use App\Http\Controllers\Api\FinanceTransactionController;
use App\Http\Controllers\Api\JournalEntryController;
use App\Http\Controllers\Api\ManualJournalEntryController;
use App\Http\Controllers\Api\BorrowingController;
use App\Http\Controllers\Api\FdDepositController;
use App\Http\Controllers\Api\AssetCategoryController;
use App\Http\Controllers\Api\FdCommissionController;
use App\Http\Controllers\Api\FixedAssetController;
use App\Http\Controllers\Api\FdInterestPaymentController;
use App\Http\Controllers\Api\FdLoanController;
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
use App\Http\Controllers\Api\LoanProvisionController;
use App\Http\Controllers\Api\LoanReportController;
use App\Http\Controllers\Api\LoanTypeController;
use App\Http\Controllers\Api\LoanWaiverController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalesExecutiveCommissionController;
use App\Http\Controllers\Api\SavingsAccountController;
use App\Http\Controllers\Api\SavingsCollectionController;
use App\Http\Controllers\Api\SavingsInterestController;
use App\Http\Controllers\Api\SavingsProductController;
use App\Http\Controllers\Api\SavingsReportController;
use App\Http\Controllers\Api\PawningArticleTypeController;
use App\Http\Controllers\Api\PawningAuctionController;
use App\Http\Controllers\Api\PawningController;
use App\Http\Controllers\Api\PawningGoldRateController;
use App\Http\Controllers\Api\PawningTypeController;
use App\Http\Controllers\Api\SpeedDraftCollateralController;
use App\Http\Controllers\Api\SpeedDraftCollateralDocumentController;
use App\Http\Controllers\Api\SpeedDraftController;
use App\Http\Controllers\Api\SpeedDraftPaymentController;
use App\Http\Controllers\Api\SpeedDraftTypeController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SystemMaintenanceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\UserAchievementController;
use App\Http\Controllers\Api\ProfileController;
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

// --- Connectivity probe (client offline banner polls this; no auth, no DB) ---
Route::get('ping', fn () => response()->json(['ok' => true]));

// --- Public branding (pre-auth: Login screen, page <title>, <img> can't carry a bearer token) ---
Route::get('company-profile/branding', [CompanyProfileController::class, 'branding']);
Route::get('company-profile/logo', [CompanyProfileController::class, 'logo']);

// --- Protected ---
Route::middleware(['auth:sanctum', 'throttle:' . config('ratelimit.api') . ',1'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // --- My profile: own achievements (open to every signed-in user, scoped to the caller) ---
    Route::get('profile/staff', [ProfileController::class, 'staff']);
    Route::get('profile/photo', [ProfileController::class, 'photo']);
    Route::post('profile/photo', [ProfileController::class, 'storePhoto']);
    Route::delete('profile/photo', [ProfileController::class, 'destroyPhoto']);
    Route::get('profile/achievements',[UserAchievementController::class, 'index']);
    Route::post('profile/achievements', [UserAchievementController::class, 'store']);
    Route::put('profile/achievements/{achievement}', [UserAchievementController::class, 'update']);
    Route::delete('profile/achievements/{achievement}', [UserAchievementController::class, 'destroy']);

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
    // --- Staff register (picklist is open to any signed-in user, like users/active) ---
    Route::get('staff/options', [StaffController::class, 'options']);
    Route::get('staff', [StaffController::class, 'index'])->middleware('perm:Staff,View');
    Route::post('staff', [StaffController::class, 'store'])->middleware('perm:Staff,Create');
    Route::put('staff/{staff}', [StaffController::class, 'update'])->middleware('perm:Staff,Edit');
    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->middleware('perm:Staff,Delete');
    Route::post('staff/{staff}/create-login', [StaffController::class, 'createLogin'])->middleware('perm:Staff,Edit');
    Route::get('users', [UserController::class, 'index'])->middleware('perm:User Management,View');
    Route::post('users', [UserController::class, 'store'])->middleware('perm:User Management,Create');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('perm:User Management,Edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('perm:User Management,Delete');

    // --- User Permission (per-user overrides) ---
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('perm:User Permission,View');
    Route::put('permissions/{user}', [PermissionController::class, 'updateUser'])->middleware('perm:User Permission,Edit');

    // --- Company Profile (branding/identity — see the public branding/logo routes above for the pre-auth half) ---
    Route::get('company-profile', [CompanyProfileController::class, 'show'])->middleware('perm:Company Profile,View');
    Route::put('company-profile', [CompanyProfileController::class, 'update'])->middleware('perm:Company Profile,Edit');
    Route::post('company-profile/logo', [CompanyProfileController::class, 'uploadLogo'])->middleware('perm:Company Profile,Edit');

    // --- Customer Management ---
    Route::get('customers', [CustomerController::class, 'index'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}/loans', [CustomerController::class, 'loans'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}/funding', [CustomerController::class, 'funding'])->middleware('perm:Customer Management,View');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('perm:Customer Management,Create');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('perm:Customer Management,Edit');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('perm:Customer Management,Delete');

    // --- Sanction / Blacklist (admin only; bars the customer from every new facility in every branch) ---
    Route::post('customers/{customer}/blacklist', [BlacklistController::class, 'store'])->middleware('admin');
    Route::delete('customers/{customer}/blacklist', [BlacklistController::class, 'destroy'])->middleware('admin');

    // --- Customer Credit Report (internal CRIB-style report, per customer) ---
    Route::get('customers/{customer}/crib-report', [CustomerCribReportController::class, 'show'])->middleware('perm:Customer Management,View');
    Route::get('customers/{customer}/crib-report/pdf', [CustomerCribReportController::class, 'exportPdf'])->middleware('perm:Customer Management,View');

    // --- Customer Photo (profile image on customers.photo_path) ---
    Route::get('customers/{customer}/photo', [CustomerPhotoController::class, 'show'])->middleware('perm:Customer Management,View');
    Route::post('customers/{customer}/photo', [CustomerPhotoController::class, 'store'])->middleware('perm:Customer Management,Edit');
    Route::delete('customers/{customer}/photo', [CustomerPhotoController::class, 'destroy'])->middleware('perm:Customer Management,Edit');

    // --- Customer Documents ---
    Route::get('customers/{customer}/bank-details', [CustomerBankDetailController::class, 'index'])->middleware('perm:Customer Management,View');
    Route::post('customers/{customer}/bank-details', [CustomerBankDetailController::class, 'store'])->middleware('perm:Customer Management,Create');
    Route::delete('customers/{customer}/bank-details/{bankDetail}', [CustomerBankDetailController::class, 'destroy'])->middleware('perm:Customer Management,Delete');
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
    Route::get('generated-numbers-years', [GeneratedNumberController::class, 'years'])->middleware('perm:Document Tracking,View');

    // --- Leasing / Loan Calculator: standalone quote, never persists ---
    Route::post('finance-calculator/calculate', [FinanceCalculatorController::class, 'calculate'])->middleware('perm:Loan Calculator,View');

    // --- Loan Management: Loan Applications ---
    Route::get('loans', [LoanController::class, 'index'])->middleware('perm:Loan Applications,View');
    // Flat path (not loans/overdue) so it can never collide with the loans/{loan} route below.
    Route::get('loans-overdue', [LoanController::class, 'overdue'])->middleware('perm:Overdue Loans,View');
    Route::get('loans-status-counts', [LoanController::class, 'statusCounts'])->middleware('perm:Active Loans,View');
    Route::get('loan-reports/{type}', [LoanReportController::class, 'show'])->middleware('perm:Loan Reports,View');
    Route::get('loan-reports/{type}/pdf', [LoanReportController::class, 'exportPdf'])->middleware('perm:Loan Reports,View');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->middleware('perm:Loan Applications,View');
    Route::post('loans', [LoanController::class, 'store'])->middleware('perm:Loan Applications,Create');
    Route::put('loans/{loan}', [LoanController::class, 'update'])->middleware('admin');
    Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->middleware('perm:Loan Applications,Delete');
    // approve/reject gated inline by ApprovalType::isAuthorized('loan_application', ...) — see LoanController —
    // not the module permission grid, so who may approve an application is admin-manageable data, same as loan_cancel below.
    Route::post('loans/{loan}/approve', [LoanController::class, 'approve']);
    Route::post('loans/{loan}/reject', [LoanController::class, 'reject']);
    Route::post('loans/{loan}/cancel-request', [LoanController::class, 'cancelRequest'])->middleware(['perm:Loan Applications,Delete', 'idempotency']);
    // approve/reject gated inline by ApprovalType::isAuthorized('loan_cancel', ...) — see LoanController — not
    // the module permission grid, so who may approve is admin-manageable data, not a hardcoded permission module.
    Route::post('loan-cancel-requests/{cancelRequest}/approve', [LoanController::class, 'approveCancelRequest'])->middleware('idempotency');
    Route::post('loan-cancel-requests/{cancelRequest}/reject', [LoanController::class, 'rejectCancelRequest'])->middleware('idempotency');

    // --- Loan Management: Active Loans ---
    // Direct disburse (Finance / admin): finishes the loan and releases the money in one call.
    Route::get('loans-disbursement', [LoanController::class, 'awaitingDisbursement'])->middleware('perm:Loan Disbursement,View');
    Route::get('loans-disbursement/{loan}', [LoanController::class, 'show'])->middleware('perm:Loan Disbursement,View');
    Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse'])->middleware(['perm:Loan Disbursement,Approve', 'idempotency']);
    // Request disburse (loan officer): raises a LoanDisbursement finance request; Finance
    // approves + executes it, then a listener finishes the loan (see the Finance module plan, D2).
    Route::post('loans/{loan}/request-disbursement', [LoanController::class, 'requestDisbursement'])->middleware(['perm:Loan Disbursement,Create', 'idempotency']);
    // Write-off is maker-checker (shared approval_requests table, code 'loan_write_off'),
    // same pattern as loan_cancel below. Submitting needs the module permission; who may
    // approve/reject is admin-manageable data via ApprovalType::isAuthorized('loan_write_off').
    Route::post('loans/{loan}/write-off-request', [LoanController::class, 'writeOffRequest'])->middleware(['perm:Active Loans,Delete', 'idempotency']);
    Route::post('loan-write-off-requests/{writeOffRequest}/approve', [LoanController::class, 'approveWriteOffRequest'])->middleware('idempotency');
    Route::post('loan-write-off-requests/{writeOffRequest}/reject', [LoanController::class, 'rejectWriteOffRequest'])->middleware('idempotency');
    // Loan reschedule (restructure) — closes the loan as 'Restructured' and creates a
    // new linked loan. Admin-only, no approval workflow, same as the other Loan Adjustments actions.
    Route::post('loans/{loan}/reschedule', [LoanController::class, 'reschedule'])->middleware('admin');
    // TEMPORARY (admin-only) — hard-purges a loan and its whole restructure chain
    // from the database: schedule, payments, charges, collateral, documents, GL
    // journal entries + lines and cashbook receipts (the cash balance is
    // corrected). One-off live-data cleanup for test / mis-keyed loans; remove
    // this route + LoanController::purge() once the cleanup is done.
    Route::delete('loans/{loan}/purge', [LoanController::class, 'purge'])->middleware('admin');

    // --- Loan Management: Impairment / NPL Provisioning ---
    // Flat path (not loans/provisions) so it can never collide with loans/{loan} below.
    Route::get('loans-provisions', [LoanProvisionController::class, 'index'])->middleware('perm:Loan Provisioning,View');
    Route::post('loans/{loan}/post-provision', [LoanProvisionController::class, 'post'])->middleware('perm:Loan Provisioning,Edit');
    Route::get('loans/{loan}/provisions', [LoanProvisionController::class, 'history'])->middleware('perm:Loan Provisioning,View');
    Route::post('loans/{loan}/close', [LoanController::class, 'close'])->middleware('perm:Active Loans,Edit');
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
    Route::get('loans/{loan}/outstanding-by-bucket', [LoanPaymentController::class, 'outstandingByBucket'])->middleware('perm:Loan Payments,Create');
    Route::post('loans/{loan}/payments', [LoanPaymentController::class, 'store'])->middleware(['perm:Loan Payments,Create', 'idempotency']);
    Route::post('loan-payments/{payment}/void-request', [LoanPaymentController::class, 'voidRequest'])->middleware(['perm:Loan Payments,Delete', 'idempotency']);
    // approve/reject gated inline by ApprovalType::isAuthorized('payment_void', ...) — see LoanPaymentController.
    Route::post('loan-payment-void-requests/{voidRequest}/approve', [LoanPaymentController::class, 'approveVoidRequest'])->middleware('idempotency');
    Route::post('loan-payment-void-requests/{voidRequest}/reject', [LoanPaymentController::class, 'rejectVoidRequest']);
    // approve/reject gated inline by ApprovalType::isAuthorized('payment_manual_allocation', ...) — see LoanPaymentController.
    Route::post('loan-payment-allocation-requests/{allocationRequest}/approve', [LoanPaymentController::class, 'approveAllocationRequest'])->middleware('idempotency');
    Route::post('loan-payment-allocation-requests/{allocationRequest}/reject', [LoanPaymentController::class, 'rejectAllocationRequest']);
    // approve/reject gated inline by ApprovalType::isAuthorized('payment_settlement', ...) — see LoanPaymentController —
    // not the module permission grid, same maker-checker pattern as payment_void above.
    Route::post('loan-payments/{payment}/approve-settlement', [LoanPaymentController::class, 'approveSettlement'])->middleware('idempotency');
    Route::post('loan-payments/{payment}/reject-settlement', [LoanPaymentController::class, 'rejectSettlement'])->middleware('idempotency');
    // Admin-only — realizing/bouncing a cheque is a Loan Adjustments action, not a regular Loan Payments one.
    Route::patch('loan-payments/{payment}/clear-cheque', [LoanPaymentController::class, 'clearCheque'])->middleware('admin');

    Route::get('collection-reports/{type}', [CollectionReportController::class, 'show'])->middleware('perm:Collection Reports,View');
    Route::get('collection-reports/{type}/pdf', [CollectionReportController::class, 'exportPdf'])->middleware('perm:Collection Reports,View');

    // --- Loan Management: Sales Executive Commissions ---
    Route::get('sales-executive-commissions', [SalesExecutiveCommissionController::class, 'index'])->middleware('perm:Sales Executive Commissions,View');
    // Admin-only, like other one-shot financial-payout actions (clear-cheque, waivers) — bypasses the permission grid entirely.
    Route::post('sales-executive-commissions/{commission}/pay', [SalesExecutiveCommissionController::class, 'pay'])->middleware(['admin', 'idempotency']);
    Route::post('sales-executive-commissions/{commission}/void', [SalesExecutiveCommissionController::class, 'void'])->middleware('admin');

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
    Route::post('loans/{loan}/waivers', [LoanWaiverController::class, 'store'])->middleware(['admin', 'idempotency']);
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
    Route::get('speed-drafts-status-counts', [SpeedDraftController::class, 'statusCounts'])->middleware('perm:Speed Drafts,View');
    Route::get('speed-drafts/{speedDraft}', [SpeedDraftController::class, 'show'])->middleware('perm:Speed Drafts,View');
    Route::post('speed-drafts', [SpeedDraftController::class, 'store'])->middleware('perm:Speed Drafts,Create');
    Route::put('speed-drafts/{speedDraft}', [SpeedDraftController::class, 'update'])->middleware('admin');
    Route::delete('speed-drafts/{speedDraft}', [SpeedDraftController::class, 'destroy'])->middleware('perm:Speed Drafts,Delete');
    // approve/reject gated inline by ApprovalType::isAuthorized('speed_draft_application', ...) — see SpeedDraftController —
    // not the module permission grid, so who may approve is admin-manageable data, same as loan_application above.
    Route::post('speed-drafts/{speedDraft}/approve', [SpeedDraftController::class, 'approve']);
    Route::post('speed-drafts/{speedDraft}/reject', [SpeedDraftController::class, 'reject']);
    Route::post('speed-drafts/{speedDraft}/disburse', [SpeedDraftController::class, 'disburse'])->middleware(['perm:Speed Drafts,Approve', 'idempotency']);
    Route::post('speed-drafts/{speedDraft}/request-disbursement', [SpeedDraftController::class, 'requestDisbursement'])->middleware(['perm:Speed Drafts,Create', 'idempotency']);
    Route::post('speed-drafts/{speedDraft}/renew', [SpeedDraftController::class, 'renew'])->middleware(['perm:Speed Draft Payments,Create', 'idempotency']);

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
    Route::post('speed-drafts/{speedDraft}/payments', [SpeedDraftPaymentController::class, 'store'])->middleware(['perm:Speed Draft Payments,Create', 'idempotency']);
    Route::delete('speed-draft-payments/{payment}', [SpeedDraftPaymentController::class, 'destroy'])->middleware(['perm:Speed Draft Payments,Delete', 'idempotency']);

    // --- Pawning: Types (schemes — rates, LTV, grace/auction-notice days) ---
    Route::get('pawning-types', [PawningTypeController::class, 'index'])->middleware('perm:Pawning Types,View');
    Route::post('pawning-types', [PawningTypeController::class, 'store'])->middleware('perm:Pawning Types,Create');
    Route::put('pawning-types/{pawningType}', [PawningTypeController::class, 'update'])->middleware('perm:Pawning Types,Edit');
    Route::delete('pawning-types/{pawningType}', [PawningTypeController::class, 'destroy'])->middleware('perm:Pawning Types,Delete');

    // --- Pawning: Article Types (Chain, Ring, Bangle, ...) ---
    Route::get('pawning-article-types', [PawningArticleTypeController::class, 'index'])->middleware('perm:Pawning Types,View');
    Route::post('pawning-article-types', [PawningArticleTypeController::class, 'store'])->middleware('perm:Pawning Types,Create');
    Route::put('pawning-article-types/{pawningArticleType}', [PawningArticleTypeController::class, 'update'])->middleware('perm:Pawning Types,Edit');
    Route::delete('pawning-article-types/{pawningArticleType}', [PawningArticleTypeController::class, 'destroy'])->middleware('perm:Pawning Types,Delete');

    // --- Pawning: Gold Rates (valuation rate history by karat/branch) ---
    Route::get('pawning-gold-rates', [PawningGoldRateController::class, 'index'])->middleware('perm:Pawning Types,View');
    Route::get('pawning-gold-rates/latest', [PawningGoldRateController::class, 'latest'])->middleware('perm:Pawnings,Create');
    Route::post('pawning-gold-rates', [PawningGoldRateController::class, 'store'])->middleware('perm:Pawning Types,Create');
    Route::delete('pawning-gold-rates/{pawningGoldRate}', [PawningGoldRateController::class, 'destroy'])->middleware('perm:Pawning Types,Delete');

    // --- Pawning: Tickets (issued+disbursed in one step — appraisal IS the approval) ---
    Route::get('pawnings', [PawningController::class, 'index'])->middleware('perm:Pawnings,View');
    // Flat path (not pawnings/overdue) so it can never collide with the pawnings/{pawning} route below.
    Route::get('pawnings-overdue', [PawningController::class, 'overdue'])->middleware('perm:Pawnings,View');
    Route::get('pawnings/{pawning}', [PawningController::class, 'show'])->middleware('perm:Pawnings,View');
    Route::put('pawnings/{pawning}', [PawningController::class, 'adminUpdate'])->middleware('admin');
    Route::post('pawnings', [PawningController::class, 'store'])->middleware('perm:Pawnings,Create');
    Route::post('pawnings/{pawning}/renew', [PawningController::class, 'renew'])->middleware(['perm:Pawning Payments,Create', 'idempotency']);
    Route::post('pawnings/{pawning}/redeem', [PawningController::class, 'redeem'])->middleware(['perm:Pawning Payments,Create', 'idempotency']);
    Route::post('pawning-payments/{payment}/void', [PawningController::class, 'voidPayment'])->middleware(['perm:Pawning Payments,Delete', 'idempotency']);

    // --- Pawning: Auctions (default handling) ---
    Route::get('pawning-auctions', [PawningAuctionController::class, 'index'])->middleware('perm:Pawning Auctions,View');
    Route::get('pawning-auctions-eligible', [PawningAuctionController::class, 'eligible'])->middleware('perm:Pawning Auctions,View');
    Route::get('pawning-auctions/{pawningAuction}', [PawningAuctionController::class, 'show'])->middleware('perm:Pawning Auctions,View');
    Route::post('pawning-auctions', [PawningAuctionController::class, 'store'])->middleware('perm:Pawning Auctions,Create');
    Route::post('pawning-auctions/{pawningAuction}/items', [PawningAuctionController::class, 'addItems'])->middleware('perm:Pawning Auctions,Edit');
    Route::post('pawning-auctions/{pawningAuction}/complete', [PawningAuctionController::class, 'complete'])->middleware('perm:Pawning Auctions,Edit');
    Route::post('pawning-auction-items/{pawningAuctionItem}/sale', [PawningAuctionController::class, 'recordSale'])->middleware(['perm:Pawning Auctions,Edit', 'idempotency']);
    Route::post('pawning-auction-items/{pawningAuctionItem}/unsold', [PawningAuctionController::class, 'markUnsold'])->middleware(['perm:Pawning Auctions,Edit', 'idempotency']);
    Route::post('pawning-auction-items/{pawningAuctionItem}/settle-surplus', [PawningAuctionController::class, 'settleSurplus'])->middleware(['perm:Pawning Auctions,Edit', 'idempotency']);
    Route::post('pawning-auction-items/{pawningAuctionItem}/forfeit-surplus', [PawningAuctionController::class, 'forfeitSurplus'])->middleware(['perm:Pawning Auctions,Edit', 'idempotency']);

    // --- Bank Management: Bank Accounts ---
    Route::get('bank-accounts', [BankAccountController::class, 'index'])->middleware('perm:Bank Accounts,View');
    Route::post('bank-accounts', [BankAccountController::class, 'store'])->middleware('perm:Bank Accounts,Create');
    Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->middleware('perm:Bank Accounts,Edit');
    Route::delete('bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->middleware('perm:Bank Accounts,Delete');

    // --- Bank Management: Bank Transactions (immutable ledger entries — void by deleting, never edited) ---
    Route::get('bank-transactions', [BankTransactionController::class, 'index'])->middleware('perm:Bank Transactions,View');
    Route::post('bank-transactions', [BankTransactionController::class, 'store'])->middleware(['perm:Bank Transactions,Create', 'idempotency']);
    Route::get('undeposited-funds', [UndepositedFundsController::class, 'show'])->middleware('perm:Bank Transactions,View');
    Route::post('undeposited-funds/deposit', [UndepositedFundsController::class, 'deposit'])->middleware(['perm:Bank Transactions,Create', 'idempotency']);
    Route::post('bank-transactions/{bankTransaction}/clear', [BankTransactionController::class, 'clear'])->middleware(['perm:Bank Transactions,Edit', 'idempotency']);
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
    Route::post('cashbook-entries', [CashbookEntryController::class, 'store'])->middleware(['perm:Cashbook,Create', 'idempotency']);
    Route::delete('cashbook-entries/{cashbookEntry}', [CashbookEntryController::class, 'destroy'])->middleware('perm:Cashbook,Delete');

    // Cash Transfers retired in Phase 9 — superseded by Finance > Transfers
    // (finance_requests + FinancePostingService). The cash_transfers table and
    // its history stay; there is no create/approve path any more.

    // --- Cashbook Management: Cash Reconciliation (branch-level daily cash-in-hand entry) ---
    Route::get('cash-reconciliations', [CashReconciliationController::class, 'index'])->middleware('perm:Cash Reconciliation,View');
    Route::get('cash-reconciliations/summary', [CashReconciliationController::class, 'summary'])->middleware('perm:Cash Reconciliation,View');
    Route::post('cash-reconciliations', [CashReconciliationController::class, 'store'])->middleware('perm:Cash Reconciliation,Create');
    Route::post('cash-reconciliations/{cashReconciliation}/verify', [CashReconciliationController::class, 'verify'])->middleware('perm:Cash Reconciliation,Edit');
    Route::delete('cash-reconciliations/{cashReconciliation}', [CashReconciliationController::class, 'destroy'])->middleware('perm:Cash Reconciliation,Delete');

    // --- Finance: Dashboard (cash control at a glance) ---
    Route::get('finance-dashboard', [FinanceDashboardController::class, 'index'])->middleware('perm:Finance Dashboard,View');

    // --- Finance: Transaction ledger + reversals ---
    Route::get('finance-transactions', [FinanceTransactionController::class, 'index'])->middleware('perm:Cash Transactions,View');
    Route::get('finance-transactions/{financeTransaction}', [FinanceTransactionController::class, 'show'])->middleware('perm:Cash Transactions,View');
    // Reverse — direct if the reversal_requires_approval setting is off, else raises a finance_reversal approval.
    Route::post('finance-transactions/{financeTransaction}/reverse-request', [FinanceTransactionController::class, 'reverseRequest'])->middleware(['perm:Cash Transactions,Delete', 'idempotency']);
    Route::post('finance-transaction-reverse-requests/{approvalRequest}/approve', [FinanceTransactionController::class, 'approveReverse']);
    Route::post('finance-transaction-reverse-requests/{approvalRequest}/reject', [FinanceTransactionController::class, 'rejectReverse']);

    // --- Finance: Audit Log ---
    Route::get('finance-audit', [FinanceAuditController::class, 'index'])->middleware('perm:Finance Audit,View');

    // --- Finance: Reports (daily-cash, cash-position, cash-flow, transfers, floats,
    //     requests-pending, requests-unpaid, reversals, variance, audit) ---
    Route::get('finance-reports/{type}', [FinanceReportController::class, 'show'])->middleware('perm:Finance Reports,View');
    Route::get('finance-reports/{type}/pdf', [FinanceReportController::class, 'exportPdf'])->middleware('perm:Finance Reports,View');


    // --- Finance: Settings ---
    Route::get('finance-settings', [FinanceSettingController::class, 'index'])->middleware('perm:Finance Settings,View');
    Route::put('finance-settings', [FinanceSettingController::class, 'update'])->middleware('perm:Finance Settings,Edit');

    // --- Finance: Cash Floats (a float is a cash_accounts row, type CashierFloat) ---
    Route::get('cash-floats', [CashFloatController::class, 'index'])->middleware('perm:Cash Floats,View');
    Route::get('cash-float-settlements', [CashFloatController::class, 'settlements'])->middleware('perm:Cash Floats,View');
    Route::post('cash-floats/issue', [CashFloatController::class, 'issue'])->middleware('perm:Cash Floats,Create');
    Route::post('cash-floats/{cashAccount}/request-settlement', [CashFloatController::class, 'requestSettlement'])->middleware('perm:Cash Floats,Edit');
    // settle gated inline by ApprovalType::isAuthorized('finance_float_settlement') — same maker-checker pattern.
    Route::post('cash-float-settlements/{cashFloatSettlement}/settle', [CashFloatController::class, 'settle'])->middleware(['perm:Cash Floats,Edit', 'idempotency']);

    // --- Finance: Requests (the single outbound-payment / transfer queue) ---
    // Create / submit / cancel are requester actions; approve / reject are
    // gated inline by ApprovalType::isAuthorized() on the request's approval
    // code (finance_request_payment / finance_transfer / finance_float_issue),
    // same maker-checker pattern as expense_approval; execute releases money.
    Route::get('finance-requests', [FinanceRequestController::class, 'index'])->middleware('perm:Finance Requests,View');
    Route::get('finance-requests/{financeRequest}', [FinanceRequestController::class, 'show'])->middleware('perm:Finance Requests,View');
    Route::post('finance-requests', [FinanceRequestController::class, 'store'])->middleware('perm:Finance Requests,Create');
    Route::post('finance-requests/{financeRequest}/submit', [FinanceRequestController::class, 'submit'])->middleware('perm:Finance Requests,Create');
    Route::post('finance-requests/{financeRequest}/cancel', [FinanceRequestController::class, 'cancel'])->middleware('perm:Finance Requests,Create');
    Route::post('finance-requests/{financeRequest}/approve', [FinanceRequestController::class, 'approve']);
    Route::post('finance-requests/{financeRequest}/reject', [FinanceRequestController::class, 'reject']);
    Route::post('finance-requests/{financeRequest}/execute', [FinanceRequestController::class, 'execute'])->middleware('perm:Finance Requests,Edit');
    // Cross-branch transfer: release (send) -> receive (confirm) -> or recall.
    Route::post('finance-requests/{financeRequest}/release', [FinanceRequestController::class, 'release'])->middleware(['perm:Finance Transfers,Edit', 'idempotency']);
    Route::post('finance-requests/{financeRequest}/receive', [FinanceRequestController::class, 'receive'])->middleware(['perm:Finance Transfers,Edit', 'idempotency']);
    Route::post('finance-requests/{financeRequest}/recall', [FinanceRequestController::class, 'recall'])->middleware('perm:Finance Transfers,Edit');

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
    Route::post('fd-deposits', [FdDepositController::class, 'store'])->middleware(['perm:Deposits,Create', 'idempotency']);
    Route::delete('fd-deposits/{fdDeposit}', [FdDepositController::class, 'destroy'])->middleware('perm:Deposits,Delete');

    // --- Funding Management: Fixed Deposits ---
    // --- Loan against FD ---
    // Flat paths so they never collide with fixed-deposits/{fixedDeposit} or loans/{loan}.
    Route::get('fd-loans/eligible-fds', [FdLoanController::class, 'eligibleFds'])->middleware('perm:Loan Applications,Create');
    Route::get('fd-loans/quote/{fixedDeposit}', [FdLoanController::class, 'quote'])->middleware('perm:Loan Applications,Create');
    Route::post('fd-loans', [FdLoanController::class, 'store'])->middleware('perm:Loan Applications,Create');
    Route::post('loans/{loan}/settle-from-fd', [FdLoanController::class, 'settle'])->middleware(['perm:Active Loans,Approve', 'idempotency']);
    Route::get('fd-loan-policies', [FdLoanController::class, 'policies'])->middleware('perm:Funding Products,View');
    Route::put('fd-loan-policies/{fundingProduct}', [FdLoanController::class, 'savePolicy'])->middleware('perm:Funding Products,Edit');

    Route::get('fixed-deposits', [FixedDepositController::class, 'index'])->middleware('perm:Fixed Deposits,View');
    Route::get('fixed-deposits/{fixedDeposit}', [FixedDepositController::class, 'show'])->middleware('perm:Fixed Deposits,View');
    Route::put('fixed-deposits/{fixedDeposit}', [FixedDepositController::class, 'adminUpdate'])->middleware('admin');
    Route::post('fixed-deposits', [FixedDepositController::class, 'store'])->middleware('perm:Fixed Deposits,Create');
    Route::post('fixed-deposits/{fixedDeposit}/renew', [FixedDepositController::class, 'renew'])->middleware(['perm:Fixed Deposits,Edit', 'idempotency']);
    Route::post('fixed-deposits/{fixedDeposit}/withdraw', [FixedDepositController::class, 'withdraw'])->middleware(['perm:Fixed Deposits,Edit', 'idempotency']);
    Route::get('fixed-deposits/{fixedDeposit}/withdrawal-preview', [FixedDepositController::class, 'withdrawalPreview'])->middleware('perm:Fixed Deposits,View');
    Route::put('fixed-deposits/{fixedDeposit}/payout-account', [FixedDepositController::class, 'setPayoutAccount'])->middleware('perm:Fixed Deposits,Edit');
    Route::get('fd-commissions', [FdCommissionController::class, 'index'])->middleware('perm:Fixed Deposits,View');
    Route::post('fixed-deposits/{fixedDeposit}/commissions', [FdCommissionController::class, 'store'])->middleware('perm:Fixed Deposits,Edit');
    Route::put('fd-commissions/{commission}', [FdCommissionController::class, 'update'])->middleware('perm:Fixed Deposits,Edit');
    Route::delete('fd-commissions/{commission}', [FdCommissionController::class, 'destroy'])->middleware('perm:Fixed Deposits,Edit');
    Route::post('fd-commissions/{commission}/pay', [FdCommissionController::class, 'pay'])->middleware(['perm:Fixed Deposits,Edit', 'idempotency']);
    Route::post('fd-commissions/{commission}/void', [FdCommissionController::class, 'void'])->middleware('admin');
    Route::put('fixed-deposits/{fixedDeposit}/auto-renew', [FixedDepositController::class, 'setAutoRenew'])->middleware('perm:Fixed Deposits,Edit');
    Route::post('fixed-deposits/{fixedDeposit}/void-withdrawal', [FixedDepositController::class, 'voidWithdrawal'])->middleware(['perm:Fixed Deposits,Delete', 'idempotency']);
    Route::get('fixed-deposits/{fixedDeposit}/premature-preview', [FixedDepositController::class, 'prematurePreview'])->middleware('perm:Fixed Deposits,View');
    Route::post('fixed-deposits/{fixedDeposit}/premature-withdraw', [FixedDepositController::class, 'prematureWithdraw'])->middleware(['perm:Fixed Deposits,Edit', 'idempotency']);
    Route::post('fixed-deposits/{fixedDeposit}/part-payment', [FixedDepositController::class, 'partPayment'])->middleware(['perm:Fixed Deposits,Edit', 'idempotency']);
    Route::post('fd-part-payments/{fdPartPayment}/void', [FixedDepositController::class, 'voidPartPayment'])->middleware(['perm:Fixed Deposits,Delete', 'idempotency']);

    // --- Funding Management: Monthly Interest Payments ---
    Route::get('fd-interest-payments/due', [FdInterestPaymentController::class, 'due'])->middleware('perm:Fixed Deposits,View');
    Route::get('fixed-deposits/{fixedDeposit}/interest-payments', [FdInterestPaymentController::class, 'index'])->middleware('perm:Fixed Deposits,View');
    Route::post('fd-interest-payments/{fdInterestPayment}/pay', [FdInterestPaymentController::class, 'pay'])->middleware(['perm:Fixed Deposits,Edit', 'idempotency']);
    Route::get('fd-interest-payments/{fdInterestPayment}/voucher', [FdInterestPaymentController::class, 'voucher'])->middleware('perm:Fixed Deposits,View');
    Route::post('fd-interest-payments/{fdInterestPayment}/void', [FdInterestPaymentController::class, 'void'])->middleware('perm:Fixed Deposits,Delete');

    // --- Funding Management: Borrowings (money the company itself borrows) ---
    Route::get('borrowings', [BorrowingController::class, 'index'])->middleware('perm:Borrowings,View');
    Route::get('borrowings/next-number', [BorrowingController::class, 'nextNumber'])->middleware('perm:Borrowings,Create');
    Route::get('borrowings/lookups', [BorrowingController::class, 'lookups'])->middleware('perm:Borrowings,View');
    Route::get('borrowings/{borrowing}', [BorrowingController::class, 'show'])->middleware('perm:Borrowings,View');
    Route::put('borrowings/{borrowing}', [BorrowingController::class, 'adminUpdate'])->middleware('admin');
    Route::post('borrowings', [BorrowingController::class, 'store'])->middleware(['perm:Borrowings,Create', 'idempotency']);
    Route::post('borrowings/{borrowing}/renew', [BorrowingController::class, 'renew'])->middleware('perm:Borrowings,Edit');
    Route::post('borrowings/{borrowing}/transactions', [BorrowingController::class, 'addTransaction'])->middleware(['perm:Borrowings,Edit', 'idempotency']);
    Route::post('borrowing-transactions/{borrowingTransaction}/void', [BorrowingController::class, 'voidTransaction'])->middleware(['perm:Borrowings,Delete', 'idempotency']);
    Route::delete('borrowings/{borrowing}', [BorrowingController::class, 'destroy'])->middleware('perm:Borrowings,Delete');

    // Branch-to-branch funding through Head Office
    Route::get('branch-funding', [\App\Http\Controllers\Api\BranchFundingController::class, 'index'])->middleware('perm:Branch Funding,View');
    Route::get('branch-funding/lookups', [\App\Http\Controllers\Api\BranchFundingController::class, 'lookups'])->middleware('perm:Branch Funding,View');
    Route::get('branch-funding/{id}', [\App\Http\Controllers\Api\BranchFundingController::class, 'show'])->middleware('perm:Branch Funding,View');
    Route::put('branch-funding/{id}', [\App\Http\Controllers\Api\BranchFundingController::class, 'adminUpdate'])->middleware('admin');
    Route::post('branch-funding', [\App\Http\Controllers\Api\BranchFundingController::class, 'store'])->middleware('perm:Branch Funding,Create');
    Route::post('branch-funding/{id}/approve', [\App\Http\Controllers\Api\BranchFundingController::class, 'approve'])->middleware('perm:Branch Funding,Approve');
    Route::post('branch-funding/{id}/reject', [\App\Http\Controllers\Api\BranchFundingController::class, 'reject'])->middleware('perm:Branch Funding,Approve');
    Route::post('branch-funding/{id}/accrue', [\App\Http\Controllers\Api\BranchFundingController::class, 'accrue'])->middleware('perm:Branch Funding,Edit');
    Route::post('branch-funding/{id}/repay', [\App\Http\Controllers\Api\BranchFundingController::class, 'repay'])->middleware(['perm:Branch Funding,Edit', 'idempotency']);

    // --- Daily Savings: Products ---
    Route::get('savings-products', [SavingsProductController::class, 'index'])->middleware('perm:Savings Products,View');
    Route::post('savings-products', [SavingsProductController::class, 'store'])->middleware('perm:Savings Products,Create');
    Route::put('savings-products/{savingsProduct}', [SavingsProductController::class, 'update'])->middleware('perm:Savings Products,Edit');
    Route::delete('savings-products/{savingsProduct}', [SavingsProductController::class, 'destroy'])->middleware('perm:Savings Products,Delete');

    // --- Daily Savings: Accounts (open / deposit / withdraw / close / freeze) ---
    Route::get('savings-accounts', [SavingsAccountController::class, 'index'])->middleware('perm:Savings Accounts,View');
    Route::get('savings-accounts/next-number', [SavingsAccountController::class, 'nextNumber'])->middleware('perm:Savings Accounts,Create');
    Route::get('savings-accounts/{savingsAccount}', [SavingsAccountController::class, 'show'])->middleware('perm:Savings Accounts,View');
    Route::put('savings-accounts/{savingsAccount}', [SavingsAccountController::class, 'adminUpdate'])->middleware('admin');
    Route::get('savings-accounts/{savingsAccount}/statement', [SavingsAccountController::class, 'statement'])->middleware('perm:Savings Accounts,View');
    Route::post('savings-accounts', [SavingsAccountController::class, 'store'])->middleware(['perm:Savings Accounts,Create', 'idempotency']);
    Route::post('savings-accounts/{savingsAccount}/deposit', [SavingsAccountController::class, 'deposit'])->middleware(['perm:Savings Accounts,Edit', 'idempotency']);
    Route::post('savings-accounts/{savingsAccount}/withdraw', [SavingsAccountController::class, 'withdraw'])->middleware(['perm:Savings Accounts,Edit', 'idempotency']);
    Route::post('savings-accounts/{savingsAccount}/close', [SavingsAccountController::class, 'close'])->middleware(['perm:Savings Accounts,Edit', 'idempotency']);
    Route::patch('savings-accounts/{savingsAccount}/status', [SavingsAccountController::class, 'setStatus'])->middleware('perm:Savings Accounts,Edit');
    // Void the latest posted transaction — gated inline by ApprovalType::isAuthorized('savings_txn_void').
    Route::post('savings-transactions/{savingsTransaction}/void', [SavingsAccountController::class, 'voidTransaction'])->middleware(['perm:Savings Accounts,Delete', 'idempotency']);

    // --- Daily Savings: Field Collection Sheets ---
    Route::get('savings-collection-sheets', [SavingsCollectionController::class, 'index'])->middleware('perm:Savings Collection,View');
    Route::get('savings-collection-sheets/{savingsCollectionSheet}', [SavingsCollectionController::class, 'show'])->middleware('perm:Savings Collection,View');
    Route::post('savings-collection-sheets', [SavingsCollectionController::class, 'store'])->middleware('perm:Savings Collection,Create');
    Route::put('savings-collection-sheets/{savingsCollectionSheet}', [SavingsCollectionController::class, 'update'])->middleware('perm:Savings Collection,Edit');
    Route::post('savings-collection-sheets/{savingsCollectionSheet}/post', [SavingsCollectionController::class, 'post'])->middleware(['perm:Savings Collection,Edit', 'idempotency']);
    Route::post('savings-collection-sheets/{savingsCollectionSheet}/cancel', [SavingsCollectionController::class, 'cancel'])->middleware('perm:Savings Collection,Delete');

    // --- Daily Savings: Batch Interest Runs ---
    Route::get('savings-interest-runs', [SavingsInterestController::class, 'index'])->middleware('perm:Savings Interest,View');
    Route::get('savings-interest-preview', [SavingsInterestController::class, 'preview'])->middleware('perm:Savings Interest,View');
    Route::get('savings-interest-runs/{savingsInterestRun}', [SavingsInterestController::class, 'show'])->middleware('perm:Savings Interest,View');
    Route::post('savings-interest-runs', [SavingsInterestController::class, 'store'])->middleware('perm:Savings Interest,Create');
    Route::delete('savings-interest-runs/{savingsInterestRun}', [SavingsInterestController::class, 'destroy'])->middleware('perm:Savings Interest,Create');
    // post / void gated inline by ApprovalType::isAuthorized('savings_interest_run').
    Route::post('savings-interest-runs/{savingsInterestRun}/post', [SavingsInterestController::class, 'post'])->middleware(['perm:Savings Interest,Edit', 'idempotency']);
    Route::post('savings-interest-runs/{savingsInterestRun}/void', [SavingsInterestController::class, 'voidRun'])->middleware(['perm:Savings Interest,Delete', 'idempotency']);

    // --- Daily Savings: Reports ---
    Route::get('savings-reports/{type}', [SavingsReportController::class, 'show'])->middleware('perm:Savings Reports,View');
    Route::get('savings-reports/{type}/pdf', [SavingsReportController::class, 'exportPdf'])->middleware('perm:Savings Reports,View');

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
    Route::get('accounting-reports/profit-loss/monthly', [AccountingReportController::class, 'profitAndLossMonthly'])->middleware('perm:Accounting Reports,View');
    Route::get('accounting-reports/{type}', [AccountingReportController::class, 'show'])->middleware('perm:Accounting Reports,View');
    Route::get('accounting-reports/{type}/pdf', [AccountingReportController::class, 'exportPdf'])->middleware('perm:Accounting Reports,View');
    Route::get('journal-entries', [JournalEntryController::class, 'index'])->middleware('perm:Accounting Reports,View');

    // --- Accounting: Manual Journal Entries (maker-checker, posts via LedgerPoster on approval) ---
    Route::get('manual-journal-entries', [ManualJournalEntryController::class, 'index'])->middleware('perm:Manual Journal Entries,View');
    Route::get('manual-journal-entries/account-options', [ManualJournalEntryController::class, 'accountOptions'])->middleware('perm:Manual Journal Entries,View');
    Route::post('manual-journal-entries', [ManualJournalEntryController::class, 'store'])->middleware(['perm:Manual Journal Entries,Create', 'idempotency']);
    // approve/reject gated inline by ApprovalType::isAuthorized('manual_journal_entry') — same pattern as expense_approval.
    Route::put('manual-journal-entries/{manualJournalEntry}/approve', [ManualJournalEntryController::class, 'approve'])->middleware('idempotency');
    Route::put('manual-journal-entries/{manualJournalEntry}/reject', [ManualJournalEntryController::class, 'reject'])->middleware('idempotency');

    // --- Expense Management: Vendors ---
    Route::get('vendors', [VendorController::class, 'index'])->middleware('perm:Vendors,View');
    Route::post('vendors', [VendorController::class, 'store'])->middleware('perm:Vendors,Create');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('perm:Vendors,Edit');
    Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])->middleware('perm:Vendors,Delete');

    // --- Expense Management: Expenses (request -> approve/reject -> pay) ---
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('perm:Expenses,View');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware(['perm:Expenses,Create', 'idempotency']);
    // approve/reject gated inline by ApprovalType::isAuthorized('expense_approval', ...) — see ExpenseController —
    // not the module permission grid, same maker-checker pattern as loan_cancel.
    Route::put('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
    Route::put('expenses/{expense}/reject', [ExpenseController::class, 'reject']);
    // Direct pay (Finance / admin): moves the money + records the voucher.
    Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->middleware(['perm:Expenses,Edit', 'idempotency']);
    // Request pay (requester / manager): raises an ExpensePayment finance request; Finance executes it.
    Route::post('expenses/{expense}/request-payment', [ExpenseController::class, 'requestPayment'])->middleware(['perm:Expenses,Create', 'idempotency']);

    // --- Expense Management: Payment Vouchers (auto-created by ExpenseController::pay — view/void only, see PaymentVoucherController) ---
    Route::get('payment-vouchers', [PaymentVoucherController::class, 'index'])->middleware('perm:Payment Vouchers,View');
    Route::delete('payment-vouchers/{paymentVoucher}', [PaymentVoucherController::class, 'destroy'])->middleware('perm:Payment Vouchers,Delete');

    // --- Petty Cash: Funds (imprest float per branch, custodian, ceiling) ---
    // Fixed Assets
    Route::get('asset-categories', [AssetCategoryController::class, 'index'])->middleware('perm:Fixed Assets,View');
    Route::post('asset-categories', [AssetCategoryController::class, 'store'])->middleware('perm:Fixed Assets,Create');
    Route::put('asset-categories/{assetCategory}', [AssetCategoryController::class, 'update'])->middleware('perm:Fixed Assets,Edit');
    Route::get('fixed-assets', [FixedAssetController::class, 'index'])->middleware('perm:Fixed Assets,View');
    Route::get('fixed-assets/depreciations', [FixedAssetController::class, 'depreciations'])->middleware('perm:Fixed Assets,View');
    Route::post('fixed-assets/depreciation/preview', [FixedAssetController::class, 'depreciationPreview'])->middleware('perm:Fixed Assets,View');
    Route::post('fixed-assets/depreciation/run', [FixedAssetController::class, 'depreciationRun'])->middleware(['perm:Fixed Assets,Edit', 'idempotency']);
    Route::post('fixed-assets', [FixedAssetController::class, 'store'])->middleware(['perm:Fixed Assets,Create', 'idempotency']);
    Route::get('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])->middleware('perm:Fixed Assets,View');
    Route::put('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'update'])->middleware('perm:Fixed Assets,Edit');
    Route::post('fixed-assets/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])->middleware(['perm:Fixed Assets,Edit', 'idempotency']);
    Route::delete('fixed-assets/{fixedAsset}', [FixedAssetController::class, 'destroy'])->middleware('admin');

    Route::get('petty-cash-funds', [PettyCashFundController::class, 'index'])->middleware('perm:Petty Cash,View');
    Route::post('petty-cash-funds', [PettyCashFundController::class, 'store'])->middleware('perm:Petty Cash,Create');
    Route::put('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'update'])->middleware('perm:Petty Cash,Edit');
    Route::delete('petty-cash-funds/{pettyCashFund}', [PettyCashFundController::class, 'destroy'])->middleware('perm:Petty Cash,Delete');

    // --- Petty Cash: Replenishments (top the float back up from a cash/bank account) ---
    Route::get('petty-cash-replenishments', [PettyCashReplenishmentController::class, 'index'])->middleware('perm:Petty Cash,View');
    Route::post('petty-cash-replenishments', [PettyCashReplenishmentController::class, 'store'])->middleware(['perm:Petty Cash,Create', 'idempotency']);
    Route::delete('petty-cash-replenishments/{pettyCashReplenishment}', [PettyCashReplenishmentController::class, 'destroy'])->middleware(['perm:Petty Cash,Delete', 'idempotency']);

    // --- Petty Cash: Vouchers (small receipt-level spend against the float) ---
    Route::get('petty-cash-vouchers', [PettyCashVoucherController::class, 'index'])->middleware('perm:Petty Cash,View');
    Route::post('petty-cash-vouchers', [PettyCashVoucherController::class, 'store'])->middleware(['perm:Petty Cash,Create', 'idempotency']);
    Route::delete('petty-cash-vouchers/{pettyCashVoucher}', [PettyCashVoucherController::class, 'destroy'])->middleware(['perm:Petty Cash,Delete', 'idempotency']);

    // --- Subscriptions & Document Expiry: domains, hosting, licenses, legal
    // documents with an expiry date + reminder lead time. due-soon feeds the
    // NotificationBell (see Notify.tsx's loadSystemAlerts()). ---
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->middleware('perm:Subscriptions,View');
    Route::get('subscriptions/due-soon', [SubscriptionController::class, 'dueSoon'])->middleware('perm:Subscriptions,View');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->middleware('perm:Subscriptions,Create');
    Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->middleware('perm:Subscriptions,Edit');
    Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->middleware('perm:Subscriptions,Delete');

    // --- System Maintenance: "start fresh" — wipe all transactional data + its
    // uploaded files, keep every master/config table + customers. Admin-only,
    // password + typed-phrase gated, blocked in production unless DB_CLEAN_ENABLED. ---
    Route::post('system/clean-transactions', [SystemMaintenanceController::class, 'cleanTransactions'])->middleware('admin');
});
