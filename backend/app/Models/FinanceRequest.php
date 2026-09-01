<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

/**
 * The request queue — see the create_finance_requests_table migration.
 * Managed by App\Support\FinanceRequestService. A request records intent;
 * money moves only when its finance_transaction is executed.
 */
class FinanceRequest extends Model
{
    use BelongsToBranch;

    public const REQUEST_TYPES = [
        'LoanDisbursement', 'ExpensePayment', 'SupplierPayment', 'Refund', 'Advance',
        'CashFloat', 'BranchFunding', 'CommissionPayout', 'FdPayout', 'CashTransfer', 'Other',
    ];

    /** request_type => the approval_types code that gates it. */
    public const APPROVAL_CODES = [
        'LoanDisbursement' => 'finance_request_payment',
        'ExpensePayment' => 'finance_request_payment',
        'SupplierPayment' => 'finance_request_payment',
        'Refund' => 'finance_request_payment',
        'Advance' => 'finance_request_payment',
        'BranchFunding' => 'finance_request_payment',
        'CommissionPayout' => 'finance_request_payment',
        'FdPayout' => 'finance_request_payment',
        'CashFloat' => 'finance_float_issue',
        'CashTransfer' => 'finance_transfer',
        'Other' => 'finance_request_payment',
    ];

    /** request_type => the finance_transactions txn_type it executes as. */
    public const TXN_TYPES = [
        'LoanDisbursement' => 'DISBURSEMENT',
        'ExpensePayment' => 'EXPENSE_PAYMENT',
        'SupplierPayment' => 'CASH_OUT',
        'Refund' => 'CASH_OUT',
        'Advance' => 'CASH_OUT',
        'BranchFunding' => 'TRANSFER',
        'CommissionPayout' => 'CASH_OUT',
        'FdPayout' => 'CASH_OUT',
        'CashFloat' => 'FLOAT_ISSUE',
        'CashTransfer' => 'TRANSFER',
        'Other' => 'CASH_OUT',
    ];

    /** Statuses from which a request can still be cancelled by its requester. */
    public const CANCELLABLE = ['Draft', 'Submitted', 'PendingApproval'];

    protected $fillable = [
        'code', 'request_type', 'source_module', 'source_id', 'branch_id', 'amount', 'currency',
        'payee_type', 'payee_id', 'payee_name', 'preferred_method', 'purpose', 'notes', 'priority',
        'status', 'approval_request_id', 'source_cash_account_id', 'source_bank_account_id',
        'finance_transaction_id', 'idempotency_key',
        'requested_by', 'approved_by', 'executed_by', 'requested_at', 'approved_at', 'executed_at',
        'closed_at', 'reject_reason', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function approvalCode(): string
    {
        return self::APPROVAL_CODES[$this->request_type] ?? 'finance_request_payment';
    }

    public function txnType(): string
    {
        return self::TXN_TYPES[$this->request_type] ?? 'CASH_OUT';
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function approvalRequest()
    {
        return $this->belongsTo(ApprovalRequest::class);
    }

    public function financeTransaction()
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function sourceCashAccount()
    {
        return $this->belongsTo(CashAccount::class, 'source_cash_account_id');
    }

    public function sourceBankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function executor()
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
