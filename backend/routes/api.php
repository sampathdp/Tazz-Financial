<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDocumentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LoanChargeController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\LoanDiaryNoteController;
use App\Http\Controllers\Api\LoanDocumentController;
use App\Http\Controllers\Api\LoanPaymentController;
use App\Http\Controllers\Api\LoanPostDatedChequeController;
use App\Http\Controllers\Api\LoanTypeController;
use App\Http\Controllers\Api\LoanWaiverController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ABSL Financial API routes
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

    // --- Loan Management: Loan Applications ---
    Route::get('loans', [LoanController::class, 'index'])->middleware('perm:Loan Applications,View');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->middleware('perm:Loan Applications,View');
    Route::post('loans', [LoanController::class, 'store'])->middleware('perm:Loan Applications,Create');
    Route::put('loans/{loan}', [LoanController::class, 'update'])->middleware('perm:Loan Applications,Edit');
    Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->middleware('perm:Loan Applications,Delete');
    Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->middleware('perm:Loan Applications,Approve');
    Route::post('loans/{loan}/reject', [LoanController::class, 'reject'])->middleware('perm:Loan Applications,Approve');

    // --- Loan Management: Active Loans ---
    Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse'])->middleware('perm:Active Loans,Approve');
    Route::patch('loans/{loan}/status-flags', [LoanController::class, 'updateStatusFlags'])->middleware('perm:Active Loans,Edit');

    // --- Loan Management: Loan Payments ---
    Route::get('loans/{loan}/payments', [LoanPaymentController::class, 'index'])->middleware('perm:Loan Payments,View');
    Route::post('loans/{loan}/payments', [LoanPaymentController::class, 'store'])->middleware('perm:Loan Payments,Create');
    Route::delete('loan-payments/{payment}', [LoanPaymentController::class, 'destroy'])->middleware('perm:Loan Payments,Delete');

    // --- Loan Management: Charges (Document/Legal/Valuation/Other due-type breakdown) ---
    Route::get('loans/{loan}/charges', [LoanChargeController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/charges', [LoanChargeController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::put('loan-charges/{charge}', [LoanChargeController::class, 'update'])->middleware('perm:Active Loans,Edit');
    Route::delete('loan-charges/{charge}', [LoanChargeController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Post Dated Cheques ---
    Route::get('loans/{loan}/post-dated-cheques', [LoanPostDatedChequeController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/post-dated-cheques', [LoanPostDatedChequeController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::patch('loan-post-dated-cheques/{cheque}', [LoanPostDatedChequeController::class, 'updateStatus'])->middleware('perm:Active Loans,Edit');
    Route::delete('loan-post-dated-cheques/{cheque}', [LoanPostDatedChequeController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Contract Images / Documents ---
    Route::get('loans/{loan}/documents/{document}/file', [LoanDocumentController::class, 'show'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/documents', [LoanDocumentController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::delete('loans/{loan}/documents/{document}', [LoanDocumentController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Diarizing (follow-up notes) ---
    Route::get('loans/{loan}/diary-notes', [LoanDiaryNoteController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/diary-notes', [LoanDiaryNoteController::class, 'store'])->middleware('perm:Active Loans,Create');
    Route::delete('loan-diary-notes/{note}', [LoanDiaryNoteController::class, 'destroy'])->middleware('perm:Active Loans,Delete');

    // --- Loan Management: Waivers (W-Off Set Plan / Waved Off Details) ---
    Route::get('loans/{loan}/waivers', [LoanWaiverController::class, 'index'])->middleware('perm:Active Loans,View');
    Route::post('loans/{loan}/waivers', [LoanWaiverController::class, 'store'])->middleware('perm:Active Loans,Approve');
    Route::delete('loan-waivers/{waiver}', [LoanWaiverController::class, 'destroy'])->middleware('perm:Active Loans,Delete');
});
