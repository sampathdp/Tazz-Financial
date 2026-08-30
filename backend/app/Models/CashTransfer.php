<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashTransfer extends Model
{
    protected $fillable = [
        'from_cash_account_id', 'to_cash_account_id', 'transfer_date', 'amount', 'description',
        'status', 'requested_by', 'approved_by', 'approved_at',
        'void_reason', 'voided_by', 'voided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'transfer_date' => 'date',
        'approved_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function fromAccount()
    {
        return $this->belongsTo(CashAccount::class, 'from_cash_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(CashAccount::class, 'to_cash_account_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Shared approval_requests table, scoped to this transfer's 'cash_transfer'
     *  rows via subject_id — see ApprovalRequest's docblock. */
    public function approvalRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'cash_transfer'))
            ->orderByDesc('requested_at');
    }

    /** The one transfer-approval request currently awaiting approval, if any. */
    public function pendingApprovalRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'cash_transfer'))
            ->where('status', 'PendingApproval');
    }
}
