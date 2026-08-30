<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'code', 'branch_id', 'expense_category_id', 'vendor_id', 'amount', 'expense_date',
        'description', 'status', 'requested_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'approved_at' => 'datetime',
    ];

    public const STATUSES = ['Pending', 'Approved', 'Rejected', 'Paid'];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function voucher()
    {
        return $this->hasOne(PaymentVoucher::class);
    }

    /** Shared approval_requests table, scoped to this expense's
     *  'expense_approval' rows via subject_id — see ApprovalRequest's docblock. */
    public function approvalRequests()
    {
        return $this->hasMany(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'expense_approval'))
            ->orderByDesc('requested_at');
    }

    /** The one expense-approval request currently awaiting approval, if any. */
    public function pendingApprovalRequest()
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->whereHas('approvalType', fn ($q) => $q->where('code', 'expense_approval'))
            ->where('status', 'PendingApproval');
    }
}
